<?php

declare(strict_types=1);

namespace App\Facturation\Command;

use App\Facturation\Entity\InstallmentInvoice;
use App\Facturation\Service\InstallmentInvoicer;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Port\EcheanceSepaSource;
use App\Sepa\Service\CompositeEcheanceSepaSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Émet la facture de chaque échéance d'abonnement arrivée à terme, **avant tout prélèvement**.
 *
 * ── ⚠ LA COMMANDE LA PLUS DANGEREUSE DU LOT, ET IL FAUT LE DIRE EN HAUT ─────────────────────────
 *
 * Elle produit des documents **scellés au sens NF525**. Une facture émise ne s'annule pas : elle
 * s'avoire. Il n'existe donc aucun « annuler » après coup, et un passage de trop laisse une trace
 * comptable définitive qu'il faut corriger pièce par pièce.
 *
 * Deux gardes, et elles ne font pas la même chose :
 *
 *  1. **Un plancher de date, à AUJOURD'HUI par défaut.** La source d'échéances n'a pas de borne basse
 *     (`dateProgrammee <= :date`) — c'est juste pour elle, une échéance en retard doit rester
 *     collectable. Mais ici, sans plancher, le PREMIER passage verrait tout l'arriéré et émettrait
 *     une facture scellée par échéance passée. Le même piège a déjà été rencontré sur
 *     `sepa:preavis:annoncer`, dont le commentaire raconte « un premier passage aurait versé onze mois
 *     d'arriéré dans la remise suivante ». On ne rattrape donc l'arriéré que par `--depuis`, c'est-à-dire
 *     par une décision explicite, jamais par défaut.
 *
 *  2. **`--dry-run`**, qui compte et nomme sans rien écrire. ⚠ Il sort **après** les mêmes gardes que
 *     le mode réel, jamais avant : un mode à blanc qui court-circuite ce qu'il simule annonce des
 *     résultats que le mode réel ne produira pas.
 *
 * ⚠ `safeOnFirstRun: false` au catalogue : l'ordonnanceur ne lancera JAMAIS cette commande de
 * lui-même tant qu'un humain ne l'a pas exécutée une première fois. C'est voulu, et c'est la
 * troisième garde.
 *
 * ── CE QU'ELLE N'EST PAS ────────────────────────────────────────────────────────────────────────
 *
 * Elle n'encaisse rien et ne parle à aucune banque. La facture naît ici ; la remise SEPA n'est ensuite
 * qu'une tentative de paiement contre un document qui existe déjà. C'est le découplage décrit dans
 * {@see InstallmentInvoicer}.
 *
 * ── POURQUOI UN ÉCHEC N'ARRÊTE PAS LA BOUCLE ────────────────────────────────────────────────────
 *
 * Un adhérent dont le produit n'a pas de taux de TVA fait refuser SA facture — pas celles des trois
 * cents autres. Les refus sont comptés et **nommés** en fin de passage : un compteur sans les noms
 * obligerait à relire les journaux d'un conteneur pour savoir qui corriger.
 */
#[AsCommand(
    name: 'sepa:echeances:facturer',
    description: 'Émet la facture des échéances d\'abonnement arrivées à terme (avant prélèvement).',
)]
final class InvoiceDueInstallmentsCommand extends Command
{
    private const EMAIL_SYSTEME = 'systeme+facturation-echeances@fluvia.local';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstallmentInvoicer $invoicer,
        // Même raisonnement que `PreNotifyUpcomingDebitsCommand` : l'agrégat, pas une verticale, pour
        // que ce qu'on facture soit exactement ce que la collecte relira.
        #[Autowire(service: CompositeEcheanceSepaSource::class)]
        private readonly EcheanceSepaSource $source,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Montre ce qui serait facturé sans émettre aucun document.',
        );
        $this->addOption(
            'depuis',
            null,
            InputOption::VALUE_REQUIRED,
            'Abaisse le plancher de date (AAAA-MM-JJ) pour rattraper un arriéré. ⚠ Chaque échéance '
            . 'rattrapée produit une facture scellée : à n\'utiliser qu\'après un --dry-run lu.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');
        $maintenant = new \DateTimeImmutable();

        $plancher = $this->plancher($input, $io);
        if ($plancher === null) {
            return Command::INVALID;
        }

        $configs = $this->em->getRepository(ConfigCreancierSepa::class)->findAll();
        if ([] === $configs) {
            $io->warning('Aucune configuration créancier SEPA : aucune échéance à facturer.');

            return Command::SUCCESS;
        }

        $emises = 0;
        $deja = 0;
        $anterieures = 0;
        $refus = [];

        foreach ($configs as $config) {
            $etablissement = $config->getEtablissement();
            if ($etablissement === null) {
                continue;
            }

            foreach ($this->source->echeancesDues($etablissement, $maintenant) as $due) {
                // Garde n°1 — voir le commentaire de classe. Elle s'applique AUSSI en simulation :
                // un dry-run qui montrerait l'arriéré alors que le mode réel l'écarte mentirait.
                if ($due->dateEcheance < $plancher) {
                    ++$anterieures;
                    continue;
                }

                if ($simulation) {
                    $io->text(sprintf(
                        'à facturer : %s — %s € pour le %s',
                        $due->libelle,
                        number_format($due->montantCentimes / 100, 2, ',', ' '),
                        $due->dateEcheance->format('d/m/Y'),
                    ));
                    ++$emises;
                    continue;
                }

                // ⚠ ON DEMANDE AVANT, PAS APRÈS. `facturer()` rend la même réservation qu'elle vienne
                //    d'être créée ou qu'elle existât déjà — c'est ce qui la rend idempotente, et c'est
                //    aussi ce qui rend les deux cas indiscernables après coup. Ma première version
                //    tranchait sur `issuedAt < aujourd'hui`, ce qui recomptait comme « émise » une
                //    échéance déjà facturée LE JOUR MÊME. Sur une tâche d'argent, un compteur faux est
                //    pire que pas de compteur : il rassure.
                $dejaConnue = $this->em->getRepository(InstallmentInvoice::class)
                    ->findOneBy(['originReference' => $due->referenceOrigine]) !== null;

                try {
                    $reservation = $this->invoicer->facturer($due, $etablissement, $this->auteurSysteme());
                    if ($reservation->getInvoiceId() === null) {
                        // Réservation posée sans facture : l'émission a échoué ailleurs et l'a laissée
                        // en place. On le dit plutôt que de la compter comme émise.
                        $refus[] = sprintf('%s : réservation sans facture', $due->referenceOrigine);
                        continue;
                    }
                    $dejaConnue ? ++$deja : ++$emises;
                } catch (\Throwable $echec) {
                    $refus[] = sprintf('%s : %s', $due->referenceOrigine, $echec->getMessage());
                }
            }
        }

        $io->writeln('');
        $io->writeln(sprintf(
            '%s : %d, déjà facturée(s) : %d, antérieure(s) au plancher %s : %d, refus : %d',
            $simulation ? 'à facturer' : 'facture(s) émise(s)',
            $emises,
            $deja,
            $plancher->format('d/m/Y'),
            $anterieures,
            \count($refus),
        ));

        if ($refus !== []) {
            // ⚠ NOMMER, PAS COMPTER. « 12 refus » n'a jamais permis de corriger quoi que ce soit.
            $io->warning('Échéances non facturées :');
            $io->listing(array_slice($refus, 0, 50));
            if (\count($refus) > 50) {
                $io->text(sprintf('… et %d autre(s).', \count($refus) - 50));
            }
        }

        if ($anterieures > 0 && $plancher > new \DateTimeImmutable('today')) {
            $io->note(sprintf(
                '%d échéance(s) antérieure(s) au plancher n\'ont pas été facturées. Pour les rattraper : --depuis=AAAA-MM-JJ, après un --dry-run.',
                $anterieures,
            ));
        }

        return $refus === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** Le plancher de date, à aujourd'hui sauf décision explicite. */
    private function plancher(InputInterface $input, SymfonyStyle $io): ?\DateTimeImmutable
    {
        $depuis = $input->getOption('depuis');
        if (!\is_string($depuis) || $depuis === '') {
            return new \DateTimeImmutable('today');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $depuis);
        if ($date === false) {
            $io->error(sprintf('« %s » n\'est pas une date AAAA-MM-JJ.', $depuis));

            return null;
        }

        return $date;
    }

    /**
     * L'auteur porté par les factures émises sans acteur HTTP.
     *
     * Même patron que `SessionSystemeBoutiqueResolver` : un compte dédié, créé paresseusement, avec un
     * mot de passe aléatoire qu'aucun humain ne connaît. Une adresse distincte par mécanisme, pour que
     * la trace dise QUI a émis — « système » tout court obligerait à deviner lequel.
     */
    private function auteurSysteme(): Utilisateur
    {
        $existant = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL_SYSTEME]);
        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setEmail(self::EMAIL_SYSTEME)
            ->setNom('Système (facturation des échéances)')
            ->setStatut(StatutUtilisateur::Actif)
            ->setMotDePasse(bin2hex(random_bytes(32)))
            ->setRolesSecurite(['ROLE_SYSTEME']);
        $this->em->persist($utilisateur);
        $this->em->flush();

        return $utilisateur;
    }
}
