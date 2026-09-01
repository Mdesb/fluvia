<?php

declare(strict_types=1);

namespace App\Import\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportBatchStatus;
use App\Import\Port\ImportFileParserInterface;
use App\Import\Service\RowImporterRegistry;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST `/imports` (plan-import-i1.md §0.2/§0.7/§0.9, SPEC-REPRISE-INITIALE.md §2/§5) — analyse et
 * VALIDE tout, **n'écrit rien en base métier** (aucun `Client`, D98) : seul `ImportBatch` lui-même est
 * persisté, ce n'est pas de la « base métier » au sens de D98.
 *
 * `establishment` estampillé serveur depuis `ContexteEtablissement::etablissementActif()`, 422 si
 * absent (D41, §0.7) — **hors du filet du décorateur global** `EstablishmentScopeWriteGuard`, ce
 * processor gère lui-même `persist()`/`flush()` sans décorer `persist_processor`.
 *
 * `contentHash` calculé et persisté, **non bloquant** ici (§0.9) : le doublon devient détectable via le
 * filtre `?contentHash=…`, jamais refusé avant application.
 *
 * @implements ProcessorInterface<ImportBatch, ImportBatch>
 */
final class ValidateImportBatchProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly ImportFileParserInterface $parser,
        private readonly RowImporterRegistry $registry,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ImportBatch
    {
        \assert($data instanceof ImportBatch);

        $type = $data->getType();
        if ($type === null) {
            throw new UnprocessableEntityHttpException('type est obligatoire.');
        }

        $establishment = $this->contexte->etablissementActif();
        if (!$establishment instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Aucun établissement actif (en-tête X-Etablissement).');
        }

        $contenuBase64 = $data->getContent();
        if ($contenuBase64 === '') {
            throw new UnprocessableEntityHttpException('content (base64) est obligatoire.');
        }
        $binaire = base64_decode($contenuBase64, true);
        if ($binaire === false) {
            throw new UnprocessableEntityHttpException('content n\'est pas du base64 valide.');
        }

        if (!$this->parser->supports((string) $data->getMimeType(), (string) $data->getFileName())) {
            throw new UnprocessableEntityHttpException('Format de fichier non encore supporté (CSV seul, §0.4).');
        }

        // 422 explicite si le type n'a pas (encore) de RowImporter enregistré (I2+, §0.3).
        $rowImporter = $this->registry->forType($type);

        $lot = $this->parser->parse($binaire);

        $errors = [];
        foreach ($lot->globalErrors as $i => $message) {
            $errors[$i] = $message;
        }
        foreach ($rowImporter->validate($lot->rows, $establishment) as $ligne => $message) {
            $errors[$ligne] = $message;
        }
        ksort($errors);

        $data->setEstablishment($establishment);
        $data->setContentHash(hash('sha256', $binaire));
        $data->setFileSize(\strlen($binaire));
        $data->setRowCount(\count($lot->rows));
        // `createdAt` est posé par le constructeur d'`ImportBatch` (immuable à la construction) — pas de setter.

        $acteur = $this->security->getUser();
        if ($acteur instanceof Utilisateur) {
            $data->setCreatedBy($acteur);
        }

        if ($errors !== []) {
            $data->setErrors($errors);
            $data->setStatus(ImportBatchStatus::Rejected);
        } else {
            $data->setErrors(null);
            $data->setStatus(ImportBatchStatus::Validated);
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
