<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\PanierTarificationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/paniers/{id} — comble des manques boutique : expose le **prix par ligne + le total**
 * du panier (`App\Boutique\Service\PanierTarificationHandler`, moteur M1/M2 réutilisé). Vérifie
 * impérativement la propriété du panier via `PanierProprietaireGuard` (jeton `X-Panier-Token` ou
 * titulaire de compte authentifié) — l'opération native `Get` sans provider ne l'appelait pas
 * jusqu'ici (aucune fuite exploitée en pratique : identifiant panier = UUID non énumérable, mais le
 * contrôle impératif est désormais aligné avec toutes les autres actions `/boutique/paniers/{id}/*`).
 *
 * @implements ProviderInterface<PanierEnLigne>
 */
final class PanierAvecTotalProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierProprietaireGuard $guard,
        private readonly PanierTarificationHandler $tarification,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        $id = PanierProprietaireGuard::estUuid($uriVariables['id'] ?? null);
        $panier = $id !== null ? $this->em->getRepository(PanierEnLigne::class)->find($id) : null;
        if (!$panier instanceof PanierEnLigne) {
            throw new NotFoundHttpException('Panier introuvable.');
        }

        $this->guard->verifier($panier);
        $this->tarification->calculer($panier);

        return $panier;
    }
}
