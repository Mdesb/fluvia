<?php

declare(strict_types=1);

namespace App\Subscription\Command;

use App\Securite\Entity\Utilisateur;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\InvoicingRefusedException;
use App\Subscription\Exception\UnknownCustomerException;
use App\Subscription\Security\BillingServiceAccount;
use App\Subscription\Service\SubscriptionInvoicer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `subscription:facturer-le-mois` — facture tous les abonnements d'un mois en une passe (ED-7).
 *
 * **Rejouable sans dommage.** Chaque abonnement passe par le registre `SubscriptionInvoice`, dont
 * l'unicité `(abonnement, mois)` interdit la seconde facture. Relancer la commande après un incident,
 * ou la lancer deux fois par distraction, ne produit aucun doublon — elle compte les abonnements déjà
 * facturés et passe.
 *
 * **Un échec n'arrête pas la passe.** Un client sans fiche, une période comptable close : le cas est
 * consigné et la commande continue. Interrompre priverait de facture tous les clients suivants pour
 * le problème d'un seul, et c'est exactement ce qu'on ne veut pas d'un traitement de masse.
 *
 * ---
 *
 * **QUI SIGNE LES FACTURES QU'ELLE EMET.**
 *
 * Une facture NF525 porte le nom de qui l'a emise, et une tache periodique n'a personne derriere
 * elle. Maxime a arbitre : les emissions automatiques portent un compte de service nomme
 * « Facturation automatique », auquel personne ne peut se connecter — voir
 * {@see BillingServiceAccount} pour les trois conditions posees.
 *
 * **`--auteur` reste disponible, et ce n'est pas une politesse.** L'automatisation ne remplace pas le
 * geste humain, elle le rend inutile en temps normal. Le jour d'un incident, on veut pouvoir
 * rattraper un mois au nom de quelqu'un — et que la facture le dise.
 *
 */
#[AsCommand(
    name: 'subscription:facturer-le-mois',
    description: 'Facture tous les abonnements actifs pour un mois donné (ED-7). Rejouable sans doublon.',
)]
final class FacturerAbonnementsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionInvoicer $facturier,
        private readonly BillingServiceAccount $compteDeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('auteur', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail de la personne au nom de qui les factures sont emises. Sans elle : le compte de service.')
            ->addOption('mois', null, InputOption::VALUE_REQUIRED, 'Mois a facturer, au format AAAA-MM. Par defaut : le mois courant.')
            ->addOption('a-blanc', null, InputOption::VALUE_NONE, 'Montre ce qui serait facture sans rien emettre.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mois = $this->mois($input->getOption('mois'));
        if (null === $mois) {
            $io->error('Le mois s ecrit AAAA-MM.');

            return Command::INVALID;
        }

        $aBlanc = (bool) $input->getOption('a-blanc');

        $auteur = null;

        if (!$aBlanc) {
            $demande = $input->getOption('auteur');

            if (\is_string($demande) && '' !== $demande) {
                // Passage a la main : les factures portent le nom de la personne indiquee. C'est ce
                // qu'on veut le jour d'un rattrapage — savoir qui a lance quoi.
                $auteur = $this->auteur($demande);

                if (null === $auteur) {
                    $io->error(sprintf('Aucun compte ne porte l adresse « %s ».', $demande));

                    return Command::INVALID;
                }
            } else {
                // Passage automatique : le compte de service, nomme pour etre lu par un client.
                $auteur = $this->compteDeService->compte();
            }
        }

        $abonnements = $this->abonnementsFacturables();
        $io->title(sprintf('Facturation des abonnements — %s', $mois->format('m/Y')));

        $emises = 0;
        $deja = 0;
        $refus = [];

        foreach ($abonnements as $abonnement) {
            if ($aBlanc) {
                $io->writeln(sprintf(
                    '  %s — %s',
                    $abonnement->getId()->toRfc4122(),
                    number_format($this->facturier->montantDuMois($abonnement, $mois) / 100, 2, ',', ' ').' EUR',
                ));
                continue;
            }

            try {
                $avant = $this->em->getRepository(\App\Subscription\Entity\SubscriptionInvoice::class)->count([
                    'subscription' => $abonnement,
                    'periodStart' => \App\Subscription\Entity\SubscriptionInvoice::debutDeMois($mois),
                ]);

                $this->facturier->facturerLeMois($abonnement, $mois, $auteur);

                if (0 === $avant) {
                    ++$emises;
                } else {
                    ++$deja;
                }
            } catch (InvoicingRefusedException|UnknownCustomerException $echec) {
                // On consigne et on continue : interrompre priverait de facture tous les clients
                // suivants pour le probleme d un seul.
                $refus[] = sprintf('%s : %s', $abonnement->getId()->toRfc4122(), $echec->getMessage());
            }
        }

        if ($aBlanc) {
            $io->success(sprintf('%d abonnement(s) seraient factures. Rien n a ete emis.', \count($abonnements)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d facture(s) emise(s), %d deja facturee(s).', $emises, $deja));

        if ([] !== $refus) {
            // Un echec n est pas un detail : il signifie un client qui ne sera pas preleve ce mois-ci.
            $io->warning(sprintf('%d abonnement(s) n ont pas pu etre factures :', \count($refus)));
            $io->listing($refus);
        }

        return Command::SUCCESS;
    }

    /** @return list<Subscription> */
    private function abonnementsFacturables(): array
    {
        /** @var list<Subscription> $abonnements */
        $abonnements = $this->em->getRepository(Subscription::class)->findBy(
            ['status' => [SubscriptionStatus::Active, SubscriptionStatus::Suspended]],
        );

        return $abonnements;
    }

    private function mois(mixed $brut): ?\DateTimeImmutable
    {
        if (!\is_string($brut) || '' === $brut) {
            return new \DateTimeImmutable('first day of this month');
        }

        if (1 !== preg_match('/^\d{4}-\d{2}$/', $brut)) {
            return null;
        }

        $mois = \DateTimeImmutable::createFromFormat('!Y-m-d', $brut.'-01');

        return false === $mois ? null : $mois;
    }

    private function auteur(mixed $email): ?Utilisateur
    {
        if (!\is_string($email) || '' === $email) {
            return null;
        }

        $auteur = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);

        return $auteur instanceof Utilisateur ? $auteur : null;
    }
}
