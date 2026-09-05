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
 * `smart-flow:waitlist:expirer` (RG-SF-07/RG-SF-11, CA-5, plan-smart-flow.md T9, patron
 * `App\Reservation\Command\BasculerNoShowCommand`) : expire toute `RescheduleProposal` en statut
 * `searching` ou `proposed` dont `expiresAt` est dépassé — désormais **sans distinction** d'origine
 * (revue de cohérence, ce lot) :
 *
 * - une proposition I2 (`sourceWaitlistEntryRef` renseigné, promotion de liste d'attente) marque en
 *   plus la `SlotWaitlistEntry` correspondante `expired`, puis tente l'inscription suivante sur la même
 *   ressource (RG-SF-07 : « sans confirmation avant expiration, l'inscription suivante est tentée ») ;
 * - une proposition I1 (report de no-show, fenêtre globale 30 jours, `originReservationRef` renseigné,
 *   `sourceWaitlistEntryRef` NULL) est simplement basculée `expired` (RG-SF-11/12, CA-5) — aucune liste
 *   d'attente associée, rien d'autre à faire.
 *
 * Corrigé (revue de cohérence, ce lot) : jusqu'ici cette commande ne traitait que les propositions
 * `sourceWaitlistEntryRef IS NOT NULL` (I2) — les propositions I1 `searching`/`proposed` restées échues
 * au-delà de `expiresAt` n'étaient jamais expirées automatiquement (CA-5 non couvert). C'est maintenant
 * couvert par le même passage de commande.
 */
#[AsCommand(
    name: 'smart-flow:waitlist:expirer',
    description: 'Expire toute RescheduleProposal (I1 report de no-show + I2 liste d\'attente) échue sans confirmation (RG-SF-07/RG-SF-11).',
)]
final class ExpireSlotWaitlistPromotionsCommand extends Command
{
    /** @var list<RescheduleProposalStatus> statuts non terminaux, expirables (RG-SF-11/12). */
    private const EXPIRABLE_STATUSES = [RescheduleProposalStatus::Searching, RescheduleProposalStatus::Proposed];

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
        $io->success(sprintf('%d proposition(s) de report expirée(s).', $traites));

        return Command::SUCCESS;
    }

    public function expirer(\DateTimeImmutable $maintenant): int
    {
        /** @var list<RescheduleProposal> $propositions */
        $propositions = $this->em->getRepository(RescheduleProposal::class)->createQueryBuilder('p')
            ->andWhere('p.status IN (:statuts)')
            ->andWhere('p.expiresAt <= :maintenant')
            ->setParameter('statuts', array_map(static fn (RescheduleProposalStatus $s): string => $s->value, self::EXPIRABLE_STATUSES))
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->getQuery()
            ->getResult();

        $traites = 0;
        foreach ($propositions as $proposition) {
            // La séquence complète — clore, consommer l'inscription, servir le suivant — vit
            // désormais dans `SlotWaitlistPromotionService` : `POST .../decline` en avait besoin
            // elle aussi, et deux copies auraient divergé sur le `flush` intercalé.
            //
            // D37 : l'expiration constatée à `$maintenant` déclenche elle-même la promotion suivante
            // (RG-SF-07) — il n'existe pas d'instant métier antérieur plus légitime.
            $this->promotionService->closeAndPromoteNext(
                $proposition,
                SlotWaitlistEntryStatus::Expired,
                $maintenant,
            );

            ++$traites;
        }

        $this->em->flush();

        return $traites;
    }
}
