<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST `/finance/treasury/statement-lines/{id}/ignore` (§7 cas limite spec) : corps `{ reason }`,
 * 422 si vide. Marque une ligne sans correspondance possible (frais bancaires, virement non identifié).
 *
 * @implements ProcessorInterface<BankStatementLine, BankStatementLine>
 */
final class IgnoreStatementLineProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BankStatementLine
    {
        \assert($data instanceof BankStatementLine);

        if ($data->getStatus() === BankStatementLineStatus::Reconciled) {
            throw new ConflictHttpException('Une ligne déjà rapprochée ne peut pas être ignorée.');
        }

        $corps = $this->lecteur->corps();
        $motif = $corps['reason'] ?? null;
        if (!\is_string($motif) || trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Le motif d\'ignorance est obligatoire.');
        }

        $data->setStatus(BankStatementLineStatus::Ignored);
        $data->setIgnoredReason($motif);

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
