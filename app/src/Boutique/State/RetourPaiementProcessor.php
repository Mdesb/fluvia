<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Paiement\OnlinePaymentSettlement;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /boutique/paniers/{id}/retour-paiement — retour PayFiP/PSP (US-L8-07/08, RG-M3-04/11, CA-9 à
 * CA-12). Un échec/timeout n'enregistre **aucun** paiement : la vente reste `en_cours`, le panier
 * reste valide jusqu'à expiration (CA-10, retentative possible). Un succès déclenche
 * `ConfirmerCommandeHandler::confirmerApresPaiementReussi()` (billets immédiats, e-mail, Reservation
 * confirmée par ligne timed-entry).
 *
 * ⚠ AUDIT DU 06/09, CONSTAT 1. Le corps portait `statut` et `montantCentimes`, et l'adaptateur les
 * rendait tels quels : `{"statut":"accepte"}` confirmait la commande. Le corps porte désormais la
 * référence et ce qui l'atteste (`recu` pour un bouchon ; rien pour un vrai prestataire, que
 * l'adaptateur interroge lui-même) ; le statut et le montant sont ceux que l'adaptateur a VÉRIFIÉS.
 *
 * #339 — le règlement lui-même vit dans `OnlinePaymentSettlement`, partagé avec la notification du
 * prestataire : les deux portes font la même chose, et une seule encaisse. Réponse `en_attente` quand
 * le prestataire ne connaît pas encore l'issue : l'acheteur ne doit pas être invité à repayer.
 *
 * @implements ProcessorInterface<PanierEnLigne, JsonResponse>
 */
final class RetourPaiementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierProprietaireGuard $guard,
        private readonly LecteurCorps $lecteur,
        private readonly OnlinePaymentSettlement $settlement,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $suivi = $this->em->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $data]);
        if (!$suivi instanceof SuiviCommandeEnLigne || $suivi->getVente() === null) {
            throw new ConflictHttpException('Aucun paiement en cours pour ce panier (RG-M3-11).');
        }

        $corps = $this->lecteur->corps();
        $reference = \is_string($corps['referenceTransaction'] ?? null) ? $corps['referenceTransaction'] : '';
        $issue = $this->settlement->settle($suivi, $reference, $corps);

        if ($issue->vente === null) {
            return new JsonResponse(['statut' => $issue->status, 'peutReessayer' => $issue->canRetry(), 'panier' => (string) $data->getId()]);
        }

        return new JsonResponse([
            'statut' => $issue->status,
            'vente' => (string) $issue->vente->getId(),
            'panier' => (string) $data->getId(),
        ]);
    }
}
