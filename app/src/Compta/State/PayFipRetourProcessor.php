<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Enum\StatutPayFiP;
use App\Compta\Service\TraiterRetourPayFipHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/payfip/retour (webhook, RG-PAYFIP-03, CA-6). Corps :
 *   { "venteOrigine": uuid, "referenceTransaction": string, "statut": "ok|echec|annule" }
 * Journalise pour contrôle et rejeu (retour manquant → rejouable, cf. `PayFipRejouerProcessor`).
 *
 * @implements ProcessorInterface<mixed, BordereauPayFiP>
 */
final class PayFipRetourProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly TraiterRetourPayFipHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BordereauPayFiP
    {
        $corps = $this->lecteur->corps();
        $reference = \is_string($corps['referenceTransaction'] ?? null) ? $corps['referenceTransaction'] : '';
        $statut = StatutPayFiP::tryFrom((string) ($corps['statut'] ?? 'en_attente')) ?? StatutPayFiP::EnAttente;

        $bordereau = $this->em->getRepository(BordereauPayFiP::class)->findOneBy(['referenceTransaction' => $reference]);
        if ($bordereau === null) {
            $venteId = \is_string($corps['venteOrigine'] ?? null) ? $corps['venteOrigine'] : null;
            if ($venteId === null || !Uuid::isValid($venteId)) {
                throw new UnprocessableEntityHttpException('Retour PayFiP : venteOrigine et referenceTransaction requis pour un premier retour.');
            }
            $bordereau = $this->handler->initier(Uuid::fromString($venteId), $reference);
        }

        return $this->handler->traiterRetour($bordereau, $statut);
    }
}
