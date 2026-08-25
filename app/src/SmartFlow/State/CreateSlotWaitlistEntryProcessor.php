<?php

declare(strict_types=1);

namespace App\SmartFlow\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\SmartFlow\Service\ReservationSlotReader;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /smart-flow/waitlist-entries` (RG-SF-05, plan-smart-flow.md §0.10/§2/§3 point 4, T10).
 *
 * `establishment` résolu **serveur** via `ContexteEtablissement::etablissementActif()`, jamais du corps
 * de la requête (RG-SF-15, même patron que `App\Dms\Processor\UploadDocumentProcessor`). `resourceId`
 * fourni par le client revérifié dans le périmètre actif via `ReservationSlotReader::resourceExists()`
 * avant persistance — sinon 422 (IDOR) : cette route est `read: false` (création par corps brut), hors
 * du filet de `App\SmartFlow\Doctrine\SmartFlowScopeExtension`, d'où la revérification explicite ici.
 *
 * `rank` = `max(rank) + 1` par `(establishment, resourceId)` (FIFO, RG-SF-06 côté promotion) — le
 * scope établissement (revue de cohérence, ce lot) est une défense en profondeur : `resourceId` est une
 * colonne UUID opaque (RG-SF-17), rien ne garantit son unicité inter-établissements au niveau base.
 *
 * @implements ProcessorInterface<mixed, SlotWaitlistEntry>
 */
final class CreateSlotWaitlistEntryProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly ReservationSlotReader $slotReader,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SlotWaitlistEntry
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('smart_flow.error.establishment_missing');
        }

        $resourceId = $this->uuid($corps['resourceId'] ?? null);
        if ($resourceId === null) {
            throw new UnprocessableEntityHttpException('smart_flow.error.resource_id_required');
        }

        if (!$this->slotReader->resourceExists($resourceId, $etablissement->getId())) {
            throw new UnprocessableEntityHttpException(
                'smart_flow.error.resource_out_of_scope : la ressource référencée n\'appartient pas à l\'établissement actif (RG-SF-16).',
            );
        }

        $beneficiaryId = $this->uuid($corps['beneficiaryId'] ?? null);
        if ($beneficiaryId === null) {
            throw new UnprocessableEntityHttpException('smart_flow.error.beneficiary_id_required');
        }

        $searchWindowStart = $this->datetime($corps['searchWindowStart'] ?? null);
        $searchWindowEnd = $this->datetime($corps['searchWindowEnd'] ?? null);
        if ($searchWindowStart === null || $searchWindowEnd === null || $searchWindowEnd <= $searchWindowStart) {
            throw new UnprocessableEntityHttpException('smart_flow.error.search_window_invalid');
        }

        // Corrigé (revue de cohérence, ce lot) : `rank` scopé établissement en plus de la ressource —
        // `resourceId` seul (colonne UUID opaque, aucune relation Doctrine, RG-SF-17) ne suffit pas à
        // garantir l'unicité inter-établissements si un même UUID de ressource existait par accident
        // dans deux périmètres distincts (défense en profondeur, même discipline que RG-SF-16 partout
        // ailleurs dans ce module).
        $rangMax = (int) $this->em->getRepository(SlotWaitlistEntry::class)->createQueryBuilder('e')
            ->select('COALESCE(MAX(e.rank), 0)')
            ->andWhere('e.resourceId = :resourceId')
            ->andWhere('e.establishment = :establishment')
            ->setParameter('resourceId', $resourceId, 'uuid')
            ->setParameter('establishment', $etablissement)
            ->getQuery()
            ->getSingleScalarResult();

        $entry = new SlotWaitlistEntry();
        $entry->setEstablishment($etablissement)
            ->setResourceId($resourceId)
            ->setBeneficiaryId($beneficiaryId)
            ->setSearchWindowStart($searchWindowStart)
            ->setSearchWindowEnd($searchWindowEnd)
            ->setRank($rangMax + 1)
            ->setStatus(SlotWaitlistEntryStatus::Waiting);

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function uuid(mixed $value): ?Uuid
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $segment = str_contains($value, '/') ? basename($value) : $value;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }

    private function datetime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
