<?php

declare(strict_types=1);

namespace App\SmartFlow\Command;

use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\SmartFlow\Service\SlotWaitlistPromotionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `smart-flow:waitlist:expirer` (RG-SF-07, plan-smart-flow.md T9, patron
 * `App\Reservation\Command\BasculerNoShowCommand`) : expire toute `RescheduleProposal` issue d'une
 * promotion de liste d'attente Smart Flow (`sourceWaitlistEntryRef` renseigné) restée `proposed`
 * au-delà de `expiresAt` sans confirmation, marque la `SlotWaitlistEntry` correspondante `expired`, puis
 * tente l'inscription suivante sur la même ressource (RG-SF-07 : « sans confirmation avant expiration,
 * l'inscription suivante est tentée »).
 *
 * Portée volontairement limitée à I2 (`sourceWaitlistEntryRef IS NOT NULL`) : l'expiration générale
 * d'une `RescheduleProposal` I1 (report de no-show, fenêtre globale 30 jours, `originReservationRef`
 * renseigné, RG-SF-11) n'a pas de tâche planifiée dédiée à ce jour — hors périmètre de ce lot (T9),
 * signalé pour arbitrage (le client peut toujours consulter le statut via l'API de lecture existante,
 * seule l'expiration automatique est absente).
 */
#[AsCommand(
    name: 'smart-flow:waitlist:expirer',
    description: 'Expire les promotions de liste d\'attente Smart Flow non confirmées et tente l\'inscription suivante (RG-SF-07).',
)]
final class ExpireSlotWaitlistPromotionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SlotWaitlistPromotionService $promotionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $traites = $this->expirer(new \DateTimeImmutable());
        $io->success(sprintf('%d promotion(s) de liste d\'attente expirée(s).', $traites));

        return Command::SUCCESS;
    }

    public function expirer(\DateTimeImmutable $maintenant): int
    {
        /** @var list<RescheduleProposal> $propositions */
        $propositions = $this->em->getRepository(RescheduleProposal::class)->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->andWhere('p.sourceWaitlistEntryRef IS NOT NULL')
            ->andWhere('p.expiresAt <= :maintenant')
            ->setParameter('status', RescheduleProposalStatus::Proposed->value)
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->getQuery()
            ->getResult();

        $traites = 0;
        foreach ($propositions as $proposition) {
            $proposition->setStatus(RescheduleProposalStatus::Expired);

            $entryRef = $proposition->getSourceWaitlistEntryRef();
            $entry = $entryRef !== null
                ? $this->em->getRepository(SlotWaitlistEntry::class)->find($entryRef)
                : null;

            if ($entry instanceof SlotWaitlistEntry) {
                $entry->setStatus(SlotWaitlistEntryStatus::Expired);
                $establissement = $entry->getEstablishment();

                // Flush avant de tenter la promotion suivante : `SlotWaitlistPromotionService` relit
                // les inscriptions `waiting` par une requête DQL fraîche — sans ce flush, l'entrée que
                // l'on vient d'expirer resterait `waiting` en base et pourrait être repromue elle-même.
                $this->em->flush();

                if ($establissement !== null) {
                    $this->promotionService->promoteNext($establissement, $entry->getResourceId(), $proposition->getOriginSlotId());
                }
            }

            ++$traites;
        }

        $this->em->flush();

        return $traites;
    }
}
