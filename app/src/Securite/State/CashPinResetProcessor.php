<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /utilisateurs/{id}/cash-pin/reset` (admin, `securite.gerer`, #346) — efface le code agent de
 * caisse d'un compte : code oublié, ou bloqué après trop d'erreurs. Le titulaire en redéfinit un à sa
 * prochaine ouverture de caisse. Tracé avant/après, comme la réinitialisation du MFA.
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class CashPinResetProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Utilisateur
    {
        \assert($data instanceof Utilisateur);

        $avant = ['defini' => $data->isCashPinDefined(), 'echecsConsecutifs' => $data->getCashPinFailures()];

        $data->setCashPinHash(null);
        $data->setCashPinFailures(0);

        $entree = $this->journal->enregistrer('cash_pin.reset', Utilisateur::class, (string) $data->getId(), null);
        $entree->setValeurAvant($avant);
        $entree->setValeurApres(['defini' => false, 'echecsConsecutifs' => 0]);

        $this->em->flush();

        return $data;
    }
}
