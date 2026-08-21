<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementImportFormat;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST `/api/bank_statement_lines` (§0.4 mode manuel du plan, D8 explicite) : corps
 * `{ statementImport: iri, operationDate, label, amount, reference? }`. Refuse 409 si
 * `statementImport.format != manual` — les imports fichier ne reçoivent jamais de ligne ajoutée à la
 * main (seul `ImportBankStatementProcessor` en crée).
 *
 * @implements ProcessorInterface<BankStatementLine, BankStatementLine>
 */
final class AddManualStatementLineProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementVerificateur $perimetre,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BankStatementLine
    {
        \assert($data instanceof BankStatementLine);

        $import = $data->getStatementImport();
        if ($import === null) {
            throw new UnprocessableEntityHttpException('statementImport est obligatoire.');
        }

        $compte = $import->getBankAccount();
        // D8 — revérification explicite, ne se fie jamais au filtrage implicite de la résolution d'IRI.
        if ($compte === null || !$this->perimetre->estDansLePerimetre($compte->getEstablishment())) {
            throw new NotFoundHttpException('Import de relevé introuvable.');
        }

        if ($import->getFormat() !== BankStatementImportFormat::Manual) {
            throw new ConflictHttpException('Impossible d\'ajouter une ligne à la main sur un import fichier (§0.4).');
        }

        $data->setStatus(BankStatementLineStatus::Unmatched);

        $this->em->persist($data);
        $import->setLinesCreated($import->getLinesCreated() + 1);
        $this->em->flush();

        return $data;
    }
}
