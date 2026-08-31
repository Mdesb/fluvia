<?php

declare(strict_types=1);

namespace App\Import\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportBatchStatus;
use App\Import\Port\ImportFileParserInterface;
use App\Import\Service\RowImporterRegistry;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST `/imports/{id}/appliquer` (plan-import-i1.md §0.2/§0.5/§0.9) — `read: true`/`input: false`
 * obligatoires (piège API Platform rappelé §2 point 1 du plan : sans `read: true`, API Platform tenterait
 * de désérialiser un corps vide en un nouvel `ImportBatch` au lieu de charger l'existant par `{id}`).
 *
 * Écrit, en **une transaction** (`$em->wrapInTransaction()`, même patron qu'`ImportBankStatementProcessor`) :
 * 409 si le statut n'est pas `validated`, 409 si un **autre** lot du même établissement partage déjà ce
 * `contentHash` et est `applied` (§0.9, seul moment où « déjà importé » bloque).
 *
 * Revérification D8 explicite (§3 point 2 du plan) : l'`ImportBatch` est déjà résolu par le provider
 * d'item standard (filtré par `PerimetreImportExtension`), mais on compare quand même
 * `batch.establishment` à l'établissement actif — 404, jamais 403 (ne pas confirmer l'existence d'un lot
 * hors périmètre).
 *
 * @implements ProcessorInterface<ImportBatch, ImportBatch>
 */
final class ApplyImportBatchProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly ImportFileParserInterface $parser,
        private readonly RowImporterRegistry $registry,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ImportBatch
    {
        // `read: true` + POST : un lot hors périmètre est filtré par `PerimetreImportExtension`, le
        // provider renvoie alors `null` et API Platform appelle quand même ce processor (sémantique POST).
        // Échec fermé D8 : 404, jamais un 500 d'assertion (ni une fuite d'existence hors périmètre).
        if (!$data instanceof ImportBatch) {
            throw new NotFoundHttpException('Lot d\'import introuvable.');
        }

        $actif = $this->contexte->idActif();
        if ($actif === null || $data->getEstablishment() === null || !$data->getEstablishment()->getId()->equals($actif)) {
            throw new NotFoundHttpException('Lot d\'import introuvable.');
        }

        if ($data->getStatus() !== ImportBatchStatus::Validated) {
            throw new ConflictHttpException(sprintf('Le lot doit être au statut « validated » pour être appliqué (statut actuel : « %s »).', $data->getStatus()->value));
        }

        $doublonApplique = $this->em->createQueryBuilder()
            ->select('b')
            ->from(ImportBatch::class, 'b')
            ->andWhere('b.establishment = :establishment')
            ->andWhere('b.contentHash = :hash')
            ->andWhere('b.status = :applied')
            ->andWhere('b.id != :id')
            ->setParameter('establishment', $data->getEstablishment()->getId(), 'uuid')
            ->setParameter('hash', $data->getContentHash())
            ->setParameter('applied', ImportBatchStatus::Applied)
            ->setParameter('id', $data->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
        if ($doublonApplique !== null) {
            throw new ConflictHttpException('Un fichier identique (même contentHash) a déjà été appliqué sur cet établissement (§0.9).');
        }

        $type = $data->getType();
        if ($type === null) {
            throw new UnprocessableEntityHttpException('type est obligatoire.');
        }
        $rowImporter = $this->registry->forType($type);

        $binaire = base64_decode($data->getContent(), true);
        if ($binaire === false) {
            throw new UnprocessableEntityHttpException('content n\'est pas du base64 valide.');
        }
        $lot = $this->parser->parse($binaire);

        $this->em->wrapInTransaction(function () use ($rowImporter, $lot, $data): void {
            $rowImporter->apply($lot->rows, $data);

            $data->setStatus(ImportBatchStatus::Applied);
            $data->setAppliedAt(new \DateTimeImmutable());

            $this->em->flush();
        });

        return $data;
    }
}
