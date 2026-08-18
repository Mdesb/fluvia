<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\BilletsCommandeHandler;
use App\Vente\Enum\StatutVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/paniers/{id}/billets — comble des manques boutique : permet à un acheteur **invité**
 * (sans compte) de récupérer ses billets à QR une fois la commande payée, en prouvant la possession
 * du panier via le seul jeton `X-Panier-Token` (`PanierProprietaireGuard`, même contrôle que les
 * autres actions `/boutique/paniers/{id}/*`) — aucune énumération possible : jeton non devinable,
 * 403 sur jeton absent/invalide/étranger, 404 si aucune commande payée n'est encore rattachée à ce
 * panier (pas de fuite d'état interne). Un titulaire de compte connecté peut aussi passer par ici,
 * mais `GET /boutique/comptes/me/billets` reste l'accès de référence pour lui (inchangé).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PanierBilletsProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierProprietaireGuard $guard,
        private readonly BilletsCommandeHandler $billetsHandler,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = PanierProprietaireGuard::estUuid($uriVariables['id'] ?? null);
        $panier = $id !== null ? $this->em->getRepository(PanierEnLigne::class)->find($id) : null;
        if (!$panier instanceof PanierEnLigne) {
            throw new NotFoundHttpException('Panier introuvable.');
        }

        $this->guard->verifier($panier);

        $suivi = $this->em->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panier]);
        $vente = $suivi?->getVente();
        if ($vente === null || $vente->getStatut() !== StatutVente::Validee) {
            throw new NotFoundHttpException('Aucune commande payée n\'est rattachée à ce panier.');
        }

        return new JsonResponse(['vente' => (string) $vente->getId(), 'billets' => $this->billetsHandler->listerPourVente($vente)]);
    }
}
