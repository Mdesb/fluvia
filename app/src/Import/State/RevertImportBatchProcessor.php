<?php

declare(strict_types=1);

namespace App\Import\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportBatchStatus;
use App\Import\Service\RowImporterRegistry;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST `/imports/{id}/annuler` (plan-import-i1.md §0.6/§0.8, SPEC-REPRISE-INITIALE.md §5) — supprime
 * **exactement** ce que ce lot a créé. Refuse (409) si le statut n'est pas `applied`, ou si
 * `RowImporter::isReferenced()` détecte qu'une ligne créée par ce lot a servi depuis (§0.8) : « on ne
 * défait pas ce qui a déjà servi, on corrige par un second import ».
 *
 * Même revérification D8 explicite qu'`ApplyImportBatchProcessor` (§3 point 2 du plan) : 404 si
 * l'établissement actif ne correspond pas à celui du lot.
 *
 * @implements ProcessorInterface<ImportBatch, ImportBatch>
 */
final class RevertImportBatchProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly RowImporterRegistry $registry,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ImportBatch
    {
        // Échec fermé D8 (même piège que `ApplyImportBatchProcessor`) : lot hors périmètre filtré → `null`
        // → 404, jamais un 500 d'assertion.
        if (!$data instanceof ImportBatch) {
            throw new NotFoundHttpException('Lot d\'import introuvable.');
        }

        $actif = $this->contexte->idActif();
        if ($actif === null || $data->getEstablishment() === null || !$data->getEstablishment()->getId()->equals($actif)) {
            throw new NotFoundHttpException('Lot d\'import introuvable.');
        }

        if ($data->getStatus() !== ImportBatchStatus::Applied) {
            throw new ConflictHttpException(sprintf('Le lot doit être au statut « applied » pour être annulé (statut actuel : « %s »).', $data->getStatus()->value));
        }

        $type = $data->getType();
        if ($type === null) {
            throw new UnprocessableEntityHttpException('type est obligatoire.');
        }
        $rowImporter = $this->registry->forType($type);

        if ($rowImporter->isReferenced($data->getId())) {
            throw new ConflictHttpException('Des lignes de ce lot ont été utilisées depuis (spec §5) : annulation refusée.');
        }

        $this->em->wrapInTransaction(function () use ($rowImporter, $data): void {
            $rowImporter->revert($data->getId());

            $data->setStatus(ImportBatchStatus::Reverted);

            $this->em->flush();
        });

        return $data;
    }
}
