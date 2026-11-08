<?php

declare(strict_types=1);

namespace App\Boutique\Controller;

use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Paiement\OnlinePaymentSettlement;
use App\Boutique\Paiement\SelecteurPaiementEnLigne;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * NOTIFICATION SERVEUR À SERVEUR D'UN PRESTATAIRE DE PAIEMENT (#339).
 *
 * PayFiP appelle l'URLNOTIF « complétée du paramètre IdOp » quand l'acheteur revient, ou dans les deux
 * heures s'il a fermé son navigateur, ou la nuit par rattrapage (guide Régie Web service V18.0,
 * §3.5.2.5) ; Stripe envoie un événement `checkout.session.*`. C'est ce qui confirme une commande
 * dont l'acheteur n'est jamais revenu sur la boutique.
 *
 * PUBLIQUE PAR NÉCESSITÉ, ET SANS POUVOIR : aucun prestataire ne porte de jeton Fluvia. La route ne lit
 * dans la requête qu'une RÉFÉRENCE ; elle la confronte à celle qu'on a initiée, puis l'issue est
 * demandée au prestataire par `OnlinePaymentSettlement` (la même voie que le retour de l'acheteur).
 * Une notification forgée ne peut donc que nous faire poser une question — au plus une toutes les
 * cinq secondes par commande.
 */
final class OnlinePaymentNotificationController
{
    public function __construct(
        private readonly SelecteurPaiementEnLigne $selecteur,
        private readonly OnlinePaymentSettlement $settlement,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/boutique/paiement-en-ligne/notification/{provider}', name: 'boutique_paiement_notification', requirements: ['provider' => '[a-z]{2,20}'], methods: ['GET', 'POST'])]
    public function __invoke(string $provider, Request $request): JsonResponse
    {
        $adaptateur = $this->selecteur->pourNotification($provider);
        if ($adaptateur === null) {
            return new JsonResponse(['statut' => 'inconnu'], 404);
        }

        $reference = $adaptateur->referenceFromNotification($request);
        $suivi = $reference === null ? null : $this->em->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['paymentReference' => $reference]);
        if ($reference === null || !$suivi instanceof SuiviCommandeEnLigne) {
            $this->logger->notice('Notification de paiement sans transaction connue', ['prestataire' => $provider, 'reference' => $reference]);

            return new JsonResponse(['statut' => 'inconnu'], 404);
        }

        try {
            $issue = $this->settlement->settle($suivi, $reference, [], throttle: true);
        } catch (HttpExceptionInterface $e) {
            // Retour non vérifiable, ou commande déjà close : rien à faire, et on le dit sans détail.
            $this->logger->warning('Notification de paiement refusée', ['prestataire' => $provider, 'reference' => $reference, 'motif' => $e->getMessage()]);

            return new JsonResponse(['statut' => 'refuse'], $e->getStatusCode());
        }

        return new JsonResponse(['statut' => $issue->status]);
    }
}
