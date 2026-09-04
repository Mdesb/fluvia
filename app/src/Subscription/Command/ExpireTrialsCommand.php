<?php

declare(strict_types=1);

namespace App\Subscription\Command;

use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Service\SubscriptionMandates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `subscription:trials:expire` — au quatorzième jour, l'essai bascule ou se ferme (ED-5).
 *
 * **La règle, arbitrée par Maxime le 04/09 :** un essai échu dont le client a signé son mandat SEPA
 * **reste actif** et devient facturable ; un essai échu sans mandat est **suspendu**. Suspendu, pas
 * résilié : l'exposition se coupe, **aucune donnée n'est supprimée** (RG-ED-06). C'est ce qui permet
 * à un client qui revient trois semaines plus tard de retrouver son paramétrage intact.
 *
 * ---
 *
 * **CE QUE CETTE COMMANDE NE FAIT PAS, ET POURQUOI C'EST IMPORTANT.**
 *
 * Elle ne rend **pas** un abonnement facturable : elle ne fait que suspendre ce qui n'a pas abouti.
 * La facturation décide seule, sur un critère qui ne dépend pas d'elle — un essai sans mandat actif
 * n'est jamais facturé, échu ou non ({@see FacturerAbonnementsCommand}).
 *
 * ⚠ **C'est délibéré, et ça évite le pire des scénarios.** Si les deux mécanismes se répondaient —
 * « la commande déclare l'essai terminé, donc la facturation facture » — alors le jour où cette
 * commande ne tourne pas, rien ne se passerait ; mais le jour où elle tourne **à moitié**, ou après
 * une reprise de sauvegarde, on facturerait un client qui n'a jamais signé d'autorisation de
 * prélèvement. Le recouvrement le relancerait ensuite pour un impayé qui n'existe pas. En séparant
 * les deux critères, **la panne de cette commande produit des essais gratuits trop longs — jamais des
 * factures fausses.** Des deux façons de se tromper, on choisit celle qui coûte de l'argent plutôt
 * que celle qui en réclame à tort.
 */
#[AsCommand(
    name: 'subscription:trials:expire',
    description: 'Suspend les essais gratuits échus sans mandat SEPA ; laisse courir ceux qui ont signé (ED-5).',
)]
final class ExpireTrialsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionMandates $mandates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('a-blanc', null, InputOption::VALUE_NONE, 'Montre ce qui serait suspendu sans rien changer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aBlanc = (bool) $input->getOption('a-blanc');
        $maintenant = new \DateTimeImmutable();

        $suspendus = 0;
        $convertis = 0;
        $refus = [];

        foreach ($this->essaisEchus($maintenant) as $abonnement) {
            $reference = $abonnement->getId()->toRfc4122();

            if (null !== $this->mandates->active($abonnement)) {
                // Le client a signé pendant son essai : rien à faire ici. Il reste actif, et il
                // devient facturable du seul fait que son mandat existe.
                ++$convertis;
                continue;
            }

            if ($aBlanc) {
                $io->text(sprintf('  suspendrait %s (essai échu le %s, aucun mandat)', $reference, $abonnement->getTrialEndsAt()?->format('Y-m-d') ?? '?'));
                ++$suspendus;
                continue;
            }

            try {
                $abonnement->transitionTo(SubscriptionStatus::Suspended, $maintenant);
                ++$suspendus;
            } catch (\RuntimeException $erreur) {
                // Un abonnement déjà résilié, par exemple. On consigne et on continue : interrompre
                // priverait tous les suivants du traitement pour le cas d'un seul.
                $refus[] = sprintf('%s : %s', $reference, $erreur->getMessage());
            }
        }

        if (!$aBlanc) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s : %d essai(s) suspendu(s), %d converti(s) — un mandat signé pendant l\'essai.',
            $aBlanc ? 'À blanc' : 'Échéances traitées',
            $suspendus,
            $convertis,
        ));

        if ([] !== $refus) {
            $io->warning(sprintf('%d essai(s) n\'ont pas pu être suspendus :', \count($refus)));
            $io->listing($refus);
        }

        return Command::SUCCESS;
    }

    /**
     * Les essais actifs dont le terme est passé.
     *
     * **Borné sur `trialEndsAt`, jamais sur « les abonnements actifs ».** Un abonnement payant n'a
     * pas de `trialEndsAt` : il ne peut pas entrer dans cette boucle, et donc pas être suspendu par
     * une erreur de cette commande. C'est la colonne, et non un état, qui sépare les deux populations.
     *
     * @return list<Subscription>
     */
    private function essaisEchus(\DateTimeImmutable $maintenant): array
    {
        /** @var list<Subscription> $essais */
        $essais = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Subscription::class, 'a')
            ->where('a.status = :actif')
            ->andWhere('a.trialEndsAt IS NOT NULL')
            ->andWhere('a.trialEndsAt <= :maintenant')
            ->setParameter('actif', SubscriptionStatus::Active)
            ->setParameter('maintenant', $maintenant)
            ->getQuery()
            ->getResult();

        return $essais;
    }
}
