<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\Treasury\Entity\BankAccount;
use App\Finance\Treasury\Entity\BankStatementImport;
use App\Finance\Treasury\Dto\ParsedStatementLine;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementImportFormat;
use App\Finance\Treasury\Enum\BankStatementImportStatus;
use App\Finance\Treasury\Port\BankStatementParserInterface;
use App\Securite\Entity\Utilisateur;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST `/api/bank_statement_imports` (§0.4/§0.5 du plan, D8 explicite) : corps
 * `{ bankAccount: iri, format, content?: base64, fileName?, fileMimeType? }`.
 *
 * Idempotence à deux niveaux (§0.5) :
 * 1. **Fichier** — `contentHash` (SHA-256 du contenu brut) sous `UNIQUE(bank_account_id, content_hash)` :
 *    un ré-import du même fichier sur le même compte échoue tôt en 409, sans même parser (CA-2).
 * 2. **Ligne** — avant de persister une `BankStatementLine` issue du parsing, vérification applicative
 *    (pas de contrainte `UNIQUE`) du triplet `(operationDate, amount, reference)` sur le même compte et
 *    le même format : déjà connue -> comptée dans `linesSkipped`, non recréée.
 *
 * `format: manual` (§0.4) : aucun fichier, conteneur pour `AddManualStatementLineProcessor`.
 * `format: ofx`/`camt053` : 422 explicite (« format non encore supporté »), jamais 500 (§0.4).
 *
 * @implements ProcessorInterface<BankStatementImport, BankStatementImport>
 */
final class ImportBankStatementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementVerificateur $perimetre,
        private readonly BankStatementParserInterface $parser,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BankStatementImport
    {
        \assert($data instanceof BankStatementImport);

        $bankAccount = $data->getBankAccount();
        if ($bankAccount === null) {
            throw new UnprocessableEntityHttpException('bankAccount est obligatoire.');
        }
        // D8 — revérification explicite, ne se fie jamais au filtrage implicite de la résolution d'IRI.
        if (!$this->perimetre->estDansLePerimetre($bankAccount->getEstablishment())) {
            throw new NotFoundHttpException('Compte bancaire introuvable.');
        }

        $format = $data->getFormat();
        if ($format === null) {
            throw new UnprocessableEntityHttpException('format est obligatoire.');
        }

        $acteur = $this->security->getUser();
        if ($acteur instanceof Utilisateur) {
            $data->setCreatedBy($acteur);
        }
        $data->setImportedAt(new \DateTimeImmutable());

        if ($format === BankStatementImportFormat::Manual) {
            $data->setFileName(null);
            $data->setFileMimeType(null);
            $data->setFileSize(null);
            $data->setContentHash(null);
            $data->setContent(null);
            $data->setStatus(BankStatementImportStatus::Imported);
            $data->setLinesCreated(0);
            $data->setLinesSkipped(0);

            $this->em->persist($data);
            $this->em->flush();

            return $data;
        }

        $contenuBase64 = $data->getContent();
        if (!\is_string($contenuBase64) || $contenuBase64 === '') {
            throw new UnprocessableEntityHttpException('content (base64) est obligatoire pour ce format.');
        }
        $binaire = base64_decode($contenuBase64, true);
        if ($binaire === false) {
            throw new UnprocessableEntityHttpException('content n\'est pas du base64 valide.');
        }

        $contentHash = hash('sha256', $binaire);

        // Niveau 1 (fichier) — CA-2 : même compte + même empreinte -> 409 avant tout parsing.
        $existant = $this->em->getRepository(BankStatementImport::class)->findOneBy([
            'bankAccount' => $bankAccount->getId(),
            'contentHash' => $contentHash,
        ]);
        if ($existant !== null) {
            throw new ConflictHttpException('Ce fichier a déjà été importé sur ce compte bancaire (CA-2).');
        }

        if (!$this->parser->supports($format)) {
            throw new UnprocessableEntityHttpException(sprintf('Format « %s » non encore supporté (§0.4).', $format->value));
        }

        $lot = $this->parser->parse($binaire, $bankAccount);

        $data->setFileSize(\strlen($binaire));
        $data->setContentHash($contentHash);
        $data->setContent(null);

        $linesCreated = 0;
        $linesSkipped = \count($lot->skippedReasons);

        $this->em->wrapInTransaction(function () use ($data, $lot, $bankAccount, $format, &$linesCreated, &$linesSkipped): void {
            $this->em->persist($data);

            foreach ($lot->lines as $ligneParsee) {
                if ($this->ligneDejaImportee($bankAccount, $format, $ligneParsee)) {
                    ++$linesSkipped;

                    continue;
                }

                $ligne = new BankStatementLine();
                $ligne->setStatementImport($data);
                $ligne->setOperationDate($ligneParsee->operationDate);
                $ligne->setLabel($ligneParsee->label);
                $ligne->setAmount($ligneParsee->amount);
                $ligne->setReference($ligneParsee->reference);
                $this->em->persist($ligne);
                ++$linesCreated;
            }

            $data->setLinesCreated($linesCreated);
            $data->setLinesSkipped($linesSkipped);
            $data->setErrorMessage($lot->skippedReasons === [] ? null : implode(' | ', $lot->skippedReasons));
            $data->setStatus(BankStatementImportStatus::Imported);

            $this->em->flush();
        });

        return $data;
    }

    private function ligneDejaImportee(BankAccount $compte, BankStatementImportFormat $format, ParsedStatementLine $ligne): bool
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(BankStatementLine::class, 'l')
            ->innerJoin('l.statementImport', 'si')
            ->andWhere('si.bankAccount = :bankAccount')
            ->andWhere('si.format = :format')
            ->andWhere('l.operationDate = :date')
            ->andWhere('l.amount = :amount')
            ->setParameter('bankAccount', $compte->getId(), 'uuid')
            ->setParameter('format', $format)
            ->setParameter('date', $ligne->operationDate, 'date_immutable')
            ->setParameter('amount', $ligne->amount);

        if ($ligne->reference === null) {
            $qb->andWhere('l.reference IS NULL');
        } else {
            $qb->andWhere('l.reference = :reference')->setParameter('reference', $ligne->reference);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
