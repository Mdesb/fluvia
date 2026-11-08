<?php

declare(strict_types=1);

namespace App\Boutique\Paiement\Stripe;

use App\Boutique\Paiement\ConfigurableOnlinePaymentInterface;
use App\Boutique\Paiement\InitiationPaiementEnLigne;
use App\Boutique\Paiement\NotifiedOnlinePaymentInterface;
use App\Boutique\Paiement\OnlinePaymentContext;
use App\Boutique\Paiement\PaiementEnLigneInterface;
use App\Boutique\Paiement\ResultatRetourPaiementEnLigne;
use App\Compta\Enum\TypeExploitant;
use App\Vente\Enum\StatutTPE;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Uid\Uuid;

/**
 * PSP CB DES EXPLOITANTS PRIVÉS — Stripe Checkout, page de paiement hébergée (#339).
 *
 * ⚠ LE CHOIX DU PRESTATAIRE N'EST PAS ACTÉ : il est soumis à Maxime dans la PR (QCM). Cet adaptateur
 * est celui du prestataire jugé le plus probable ; le contrat (`PaiementEnLigneInterface`) ne lui doit
 * rien, et un autre prestataire se branche à côté sans toucher au tunnel.
 *
 * Protocole (documentation Stripe, API « Checkout Sessions », lue le 05/11/2026) :
 *  - `POST /v1/checkout/sessions` (mode `payment`, montant en centimes, `success_url` portant
 *    `{CHECKOUT_SESSION_ID}`) → `id` (référence) et `url` (page hébergée : aucune donnée de carte ne
 *    transite chez nous) ;
 *  - `GET /v1/checkout/sessions/{id}` → `payment_status` (`paid` | `unpaid` | `no_payment_required`),
 *    `status` (`open` | `complete` | `expired`), `amount_total`, `currency`.
 *
 * Vérification : comme pour PayFiP, l'issue vient d'un appel que NOUS faisons avec la clé secrète, pas
 * de ce que le navigateur ou le webhook transportent. Le webhook n'est qu'une invitation à demander.
 *
 * Configuration : STRIPE_SECRET_KEY (clé `sk_test_…` en préproduction), STRIPE_API_BASE facultatif.
 */
#[AutoconfigureTag('boutique.paiement_en_ligne')]
final class StripeCheckoutAdapter implements PaiementEnLigneInterface, ConfigurableOnlinePaymentInterface, NotifiedOnlinePaymentInterface
{
    private const DEFAULT_API_BASE = 'https://api.stripe.com';

    private readonly string $secretKey;
    private readonly string $apiBase;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::STRIPE_SECRET_KEY')] ?string $secretKey = null,
        #[Autowire(env: 'default::STRIPE_API_BASE')] ?string $apiBase = null,
    ) {
        $this->secretKey = trim((string) $secretKey);
        $this->apiBase = rtrim(trim((string) $apiBase) ?: self::DEFAULT_API_BASE, '/');
    }

    public function cle(): TypeExploitant
    {
        return TypeExploitant::GroupePrive;
    }

    public function isConfigured(): bool
    {
        return preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $this->secretKey) === 1;
    }

    public function provider(): string
    {
        return 'stripe';
    }

    /** Événement `checkout.session.*` : la référence est `data.object.id`. Le reste du corps n'est pas lu. */
    public function referenceFromNotification(Request $request): ?string
    {
        try {
            $event = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $type = \is_array($event) ? ($event['type'] ?? null) : null;
        $id = \is_array($event) ? ($event['data']['object']['id'] ?? null) : null;
        if (!\is_string($type) || !str_starts_with($type, 'checkout.session.') || !\is_string($id)) {
            return null;
        }

        return preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $id) === 1 ? $id : null;
    }

    public function initierPaiement(Uuid $venteId, int $montantCentimes, string $urlRetour, ?OnlinePaymentContext $context = null): InitiationPaiementEnLigne
    {
        if ($urlRetour === '') {
            throw new HttpException(500, 'Paiement en ligne : BOUTIQUE_BASE_URL n\'est pas renseignée, le retour de paiement n\'a pas d\'adresse.');
        }
        $libelle = 'Commande ' . ($context?->orderNumber ?: (string) $venteId);
        $parametres = [
            'mode' => 'payment',
            'locale' => 'fr',
            'client_reference_id' => (string) $venteId,
            'metadata[vente]' => (string) $venteId,
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => 'eur',
            'line_items[0][price_data][unit_amount]' => (string) $montantCentimes,
            'line_items[0][price_data][product_data][name]' => mb_substr($libelle, 0, 200),
            'success_url' => $urlRetour . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $urlRetour . '?annule=1',
        ];
        if ($context !== null && filter_var($context->payerEmail, \FILTER_VALIDATE_EMAIL) !== false) {
            $parametres['customer_email'] = $context->payerEmail;
        }

        $session = $this->appeler('POST', '/v1/checkout/sessions', $parametres);
        if ($session === null || !\is_string($session['id'] ?? null) || !\is_string($session['url'] ?? null)) {
            throw new HttpException(502, 'Le prestataire de paiement n\'a pas pu ouvrir la transaction. Réessayez dans quelques minutes.');
        }

        return new InitiationPaiementEnLigne($session['id'], $session['url']);
    }

    public function verifierRetour(string $referenceTransaction, int $montantAttenduCentimes, array $donneesRetour): ?ResultatRetourPaiementEnLigne
    {
        if (preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $referenceTransaction) !== 1) {
            return null;
        }

        try {
            $session = $this->appeler('GET', '/v1/checkout/sessions/' . $referenceTransaction);
        } catch (HttpException) {
            // Stripe injoignable : l'issue est inconnue, pas négative.
            return new ResultatRetourPaiementEnLigne($referenceTransaction, StatutTPE::Timeout, $montantAttenduCentimes);
        }
        if ($session === null
            || ($session['id'] ?? null) !== $referenceTransaction
            || ($session['currency'] ?? null) !== 'eur'
            || !\is_int($session['amount_total'] ?? null)) {
            return null;
        }

        $statut = match (true) {
            ($session['payment_status'] ?? null) === 'paid' => StatutTPE::Accepte,
            ($session['status'] ?? null) === 'expired' => StatutTPE::Annule,
            default => StatutTPE::Timeout,
        };

        return new ResultatRetourPaiementEnLigne($referenceTransaction, $statut, $session['amount_total']);
    }

    /**
     * @param array<string, string> $parametres
     *
     * @return array<string, mixed>|null le corps décodé ; `null` si Stripe répond 404 (objet inconnu de NOTRE compte)
     */
    private function appeler(string $methode, string $chemin, array $parametres = []): ?array
    {
        try {
            $options = ['auth_bearer' => $this->secretKey, 'timeout' => 20];
            if ($methode === 'POST') {
                $options['body'] = $parametres;
            }
            $reponse = $this->httpClient->request($methode, $this->apiBase . $chemin, $options);
            $statut = $reponse->getStatusCode();
            if ($statut === 404) {
                return null;
            }
            $corps = $reponse->toArray(false);
        } catch (HttpClientException $e) {
            $this->logger->error('Stripe injoignable', ['chemin' => $chemin, 'erreur' => $e->getMessage()]);

            throw new HttpException(502, 'Le prestataire de paiement est injoignable.', $e);
        }
        if ($statut >= 400) {
            $this->logger->error('Stripe a refusé l\'appel', ['chemin' => $chemin, 'statut' => $statut, 'erreur' => $corps['error']['message'] ?? null]);

            throw new HttpException(502, 'Le prestataire de paiement a refusé la transaction.');
        }

        return $corps;
    }
}
