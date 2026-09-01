<?php

declare(strict_types=1);

namespace App\Sepa\Command;

use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Exception\PreNotificationRefusedException;
use App\Sepa\Port\EcheanceSepaSource;
use App\Sepa\Service\CompositeEcheanceSepaSource;
use App\Sepa\Service\DebitPreNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Annonce aux clients les prélèvements à venir, assez tôt pour qu'ils soient licites.
 *
 * **Sans cette commande, PAY-2 est inerte — et invisiblement.** `GenerationRemiseHandler` écarte toute
 * échéance non couverte par un préavis émis au moins `delaiPrenotification` jours plus tôt. Poser le
 * préavis au moment de prélever ne peut pas marcher : `sentAt` vaudrait aujourd'hui, le délai ne
 * serait jamais tenu, et **annoncer au moment de prélever n'est de toute façon pas prévenir** — c'est
 * exactement ce que la règle interdit. Il fallait donc quelqu'un qui parle **avant**, et personne ne
 * le faisait : `App\Sepa` ne déclarait aucune commande.
 *
 * En production, cent pour cent des échéances auraient été écartées, indéfiniment, et la
 * fonctionnalité entière serait restée sans effet. C'est le motif de ce dépôt à son sommet : le
 * mécanisme existe, l'appel manque.
 *
 * **Ce qu'elle annonce.** Pour chaque établissement disposant d'une configuration créancier, elle
 * demande aux verticales branchées (`CompositeEcheanceSepaSource`) les échéances qui seront dues à la
 * date d'exécution correspondant au délai de ce créancier, et annonce chacune d'elles. La source, la
 * référence d'origine et le montant sont **les mêmes** que ceux que la collecte relira : c'est ce qui
 * fait que `covers()` reconnaîtra l'annonce plutôt qu'une annonce qui lui ressemble.
 *
 * **Le piège qu'elle évite, et il aurait tout annulé.** `announce()` remet `sentAt` à l'instant courant
 * — c'est voulu, un montant qui change doit rendre au client la totalité du délai. Mais une commande
 * qui réannoncerait tout à chaque passage repousserait `sentAt` chaque jour, et **aucune échéance ne
 * serait jamais couverte**. Elle ne réannonce donc que ce qui a changé de montant. Le mécanisme se
 * serait auto-neutralisé en tournant, ce qui est la panne la plus difficile à voir : tout s'exécute,
 * rien n'aboutit.
 */
#[AsCommand(
    name: 'sepa:preavis:annoncer',
    description: 'Annonce aux débiteurs les prélèvements SEPA à venir (préavis réglementaire).',
)]
final class PreNotifyUpcomingDebitsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DebitPreNotifier $preNotifier,
        // L interface n est aliasee nulle part — trois verticales l implementent, et
        // GenerationRemiseHandler la recoit en parametre plutot qu en dependance. On designe donc
        // l agregat explicitement : c est lui qui interroge toutes les verticales branchees, et c est
        // la MEME source que la collecte relira. Une source differente annoncerait des echeances qui
        // ne sont pas celles qu on prelevera.
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
            'Montre ce qui serait annoncé sans rien envoyer ni consigner.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');
        $maintenant = new \DateTimeImmutable();

        $configs = $this->em->getRepository(ConfigCreancierSepa::class)->findAll();
        if ([] === $configs) {
            $io->warning('Aucune configuration créancier SEPA : aucun prélèvement possible, donc rien à annoncer.');

            return Command::SUCCESS;
        }

        $annonces = 0;
        $deja = 0;
        $refus = [];
        $passees = 0;

        foreach ($configs as $config) {
            $etablissement = $config->getEtablissement();
            if (null === $etablissement) {
                continue;
            }

            $delai = $config->getPreNotificationDelayDays();
            $dateExecution = $maintenant->modify(sprintf('+%d days', $delai));

            foreach ($this->source->echeancesDues($etablissement, $dateExecution) as $due) {
                // ⚠ ON N'ANNONCE PAS UNE ECHEANCE DEJA PASSEE.
                //
                // La source n'a pas de borne basse — `dateProgrammee <= :date` — et c'est juste pour
                // elle : `GenerationRemiseHandler` l'appelle aussi, et une echeance en retard doit
                // rester COLLECTABLE. Le plancher appartient ici, ou le mot « preavis » a un sens :
                // prevenir apres le prelevement n'est pas prevenir.
                //
                // Mesure du 01/09, avant ce filtre : 26 preavis a envoyer, dont des echeances du
                // 03/10/2025. Et annoncer une echeance la rend collectable dans une remise — un
                // premier passage aurait donc verse onze mois d'arriere dans la remise suivante.
                if ($due->dateEcheance < $maintenant->setTime(0, 0)) {
                    ++$passees;
                    continue;
                }

                $mandat = $this->em->getRepository(MandatSepa::class)->find($due->mandatId);
                if (!$mandat instanceof MandatSepa) {
                    continue;
                }

                // Voir le commentaire de classe : réannoncer à l'identique repousserait `sentAt` et
                // rendrait le préavis perpétuellement trop récent.
                if ($this->preNotifier->alreadyAnnounced($mandat, $due->referenceOrigine, $due->montantCentimes)) {
                    ++$deja;
                    continue;
                }

                if ($simulation) {
                    $io->text(sprintf(
                        'à annoncer : %s — %s, %s € pour le %s',
                        $mandat->getRum(),
                        $due->libelle,
                        number_format($due->montantCentimes / 100, 2, ',', ' '),
                        $due->dateEcheance->format('d/m/Y'),
                    ));
                    ++$annonces;
                    continue;
                }

                try {
                    $this->preNotifier->announce(
                        $mandat,
                        $due->referenceOrigine,
                        $due->montantCentimes,
                        $due->dateEcheance,
                        PreNotificationReason::Schedule,
                        $maintenant,
                    );
                    ++$annonces;
                } catch (PreNotificationRefusedException $refus_) {
                    // Un préavis refusé n'interrompt pas les autres : un mandat sans client ne doit pas
                    // empêcher de prévenir tous les autres débiteurs de l'établissement.
                    $refus[] = $refus_->getMessage();
                }
            }
        }

        // ⚠ CE QUI EST ECARTE SE DIT. Une echeance passee encore au statut « a venir » est un etat
        // COINCE : jamais annoncee, donc jamais collectee, et rien ne l'aurait signale. Le filtre
        // ci-dessus evite d'annoncer n'importe quoi ; il ne resout pas ce que ces echeances font la.
        if ($passees > 0) {
            $io->warning(sprintf(
                "%d echeance(s) sont encore « a venir » alors que leur date est PASSEE : elles ne "
                . "seront ni annoncees ni collectees, et rien d'autre ne le signale. Ce filtre les "
                . "ecarte, il ne les explique pas — il faut decider ce qu'elles deviennent.",
                $passees,
            ));
        }

        $io->success(sprintf(
            '%d préavis %s, %d déjà annoncés et inchangés.',
            $annonces,
            $simulation ? 'à envoyer (simulation)' : 'envoyés',
            $deja,
        ));

        if ([] !== $refus) {
            // Affiché après le compte, mais c'est ce qu'il faut lire : chaque refus est un client
            // qu'on ne pourra pas prélever.
            $io->warning(sprintf('%d préavis n\'ont pas pu partir :', \count($refus)));
            $io->listing(array_slice($refus, 0, 20));
        }

        return Command::SUCCESS;
    }
}
