<?php

declare(strict_types=1);

namespace App\Boutique\Paiement\Stripe;

use App\Boutique\Paiement\ConfigurableOnlinePaymentInterface;
use App\Boutique\Paiement\InitiationPaiementEnLigne;
use App\Boutique\Paiement\NotifiedOnlinePaymentInterface;
use App\Boutique\Paiement\OnlinePaymentContext;
use App\Boutique\Paiement\PaiementEnLigneInterface;
use App\Boutique\Paiement\ResultatRetourPaiementEnLigne;
use App\Boutique\Paiement\SignedNotificationInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Vente\Enum\StatutTPE;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
 * Notification : le webhook est signé (`Stripe-Signature`), vérifié par `verifyNotification()` AVANT toute
 * lecture du corps ; sans secret de webhook pour le compte visé, toute notification est refusée (la carte
 * reste proposée).
 *
 * **SANS ÉTAT, ET UN COMPTE PAR EXPLOITANT (D3-B de #365).** L'adaptateur ne garde aucune clé : à chaque appel,
 * `StripeAccountResolver` donne le compte du profil de la commande (`OnlinePaymentContext::$profile`) — la clé
 * saisie par l'exploitant, sinon la clé globale `STRIPE_SECRET_KEY` (repli). Payer et vérifier passent par le
 * même profil, donc par le même compte. Contrat conservé : une 404 de Stripe = objet inconnu de NOTRE compte.
 */
#[AutoconfigureTag('boutique.paiement_en_ligne')]
final class StripeCheckoutAdapter implements PaiementEnLigneInterface, ConfigurableOnlinePaymentInterface, NotifiedOnlinePaymentInterface, SignedNotificationInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly StripeAccountResolver $accounts,
    ) {
    }

    public function cle(): TypeExploitant
    {
        return TypeExploitant::GroupePrive;
    }

    /** La carte est proposée si l'exploitant a sa clé, ou à défaut la clé globale. Une clé illisible lève un 503 (jamais de repli). */
    public function isConfiguredFor(ProfilExploitant $profile): bool
    {
        return $this->accounts->forProfile($profile) !== null;
    }

    /** Toujours : la signature est la porte, et elle échoue fermée (secret absent = refus). */
    public function acceptsNotifications(): bool
    {
        return true;
    }

    public function provider(): string
    {
        return 'stripe';
    }

    public function verifyNotification(Request $request, ?string $profileId = null): void
    {
        (new StripeSignatureChecker())->verify(
            (string) $request->headers->get('Stripe-Signature', ''),
            $request->getContent(),
            $this->accounts->webhookSecretFor($profileId),
            \time(),
        );
    }

    public function ownsOrder(?string $notifiedProfileId, ProfilExploitant $orderProfile): bool
    {
        $account = $this->accounts->forProfile($orderProfile);
        if ($account === null) {
            return false;
        }
        if ($notifiedProfileId === null) {
            return $account->source === StripeAccountSource::Global;
        }

        return $account->source === StripeAccountSource::Profile && $account->profileId === strtolower($notifiedProfileId);
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
        if (filter_var($context?->payerEmail, \FILTER_VALIDATE_EMAIL) !== false) {
            $parametres['customer_email'] = $context->payerEmail;
        }

        $session = $this->appeler($this->accountFor($context), 'POST', '/v1/checkout/sessions', $parametres);
        if ($session === null || !\is_string($session['id'] ?? null) || !\is_string($session['url'] ?? null)) {
            throw new HttpException(502, 'Le prestataire de paiement n\'a pas pu ouvrir la transaction. Réessayez dans quelques minutes.');
        }

        return new InitiationPaiementEnLigne($session['id'], $session['url']);
    }

    public function verifierRetour(string $referenceTransaction, int $montantAttenduCentimes, array $donneesRetour, ?OnlinePaymentContext $context = null): ?ResultatRetourPaiementEnLigne
    {
        // Échec fermé : sans le profil de la commande on ne sait pas dans quel compte chercher (D3-B de #365).
        if ($context === null || preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $referenceTransaction) !== 1) {
            return null;
        }

        // Le compte du profil DE LA COMMANDE, jamais un autre : payer et vérifier passent par la même clé. Aucun
        // compte (clé retirée depuis) : on ne peut rien attester. Une clé illisible lève un 503, jamais un repli.
        $account = $this->accounts->forProfile($context->profile);
        if ($account === null) {
            return null;
        }

        try {
            $session = $this->appeler($account, 'GET', '/v1/checkout/sessions/' . $referenceTransaction);
        } catch (HttpException) {
            // Stripe injoignable : l'issue est inconnue, pas négative.
            return new ResultatRetourPaiementEnLigne($referenceTransaction, StatutTPE::Timeout, $montantAttenduCentimes);
        }
        if ($session === null) {
            // Session inconnue de CE compte : clé remplacée par celle d'un autre compte depuis l'initiation, ou
            // référence forgée. Rien n'est confirmé ; on le dit à l'exploitant par le journal, jamais la clé.
            $this->logger->warning('Session Stripe inconnue du compte courant : vérification refusée', [
                'profil' => $account->profileId ?? (string) $context->profile->getId(),
                'source' => $account->source->value,
                'reference' => $referenceTransaction,
            ]);

            return null;
        }
        if (($session['id'] ?? null) !== $referenceTransaction
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

    /** Le compte de l'exploitant de la commande ; un 503 si aucun (la carte n'aurait pas dû être proposée). */
    private function accountFor(?OnlinePaymentContext $context): StripeAccount
    {
        if ($context === null) {
            throw new \LogicException('Stripe : le profil de l\'exploitant est requis pour choisir le compte.');
        }

        return $this->accounts->forProfile($context->profile)
            ?? throw new HttpException(503, 'Le paiement par carte n\'est pas disponible pour cet exploitant.');
    }

    /**
     * @param array<string, string> $parametres
     *
     * @return array<string, mixed>|null le corps décodé ; `null` si Stripe répond 404 (objet inconnu de NOTRE compte)
     */
    private function appeler(StripeAccount $account, string $methode, string $chemin, array $parametres = []): ?array
    {
        try {
            $options = ['auth_bearer' => $account->secretKey(), 'timeout' => 20];
            if ($methode === 'POST') {
                $options['body'] = $parametres;
            }
            $reponse = $this->httpClient->request($methode, $account->apiBase() . $chemin, $options);
            $statut = $reponse->getStatusCode();
            if ($statut === 404) {
                return null;
            }
            $corps = $reponse->toArray(false);
        } catch (HttpClientException $e) {
            // Jamais le message : il peut citer l'adresse ou un fragment de la clé. La classe suffit.
            $this->logger->error('Stripe injoignable', ['chemin' => $chemin, 'erreur' => $e::class, 'source' => $account->source->value]);

            throw new HttpException(502, 'Le prestataire de paiement est injoignable.', $e);
        }
        if ($statut >= 400) {
            // Jamais `error.message` : Stripe y cite un fragment de la clé (« Invalid API Key provided: sk_test_…1234 »).
            // Le type et le code, énumérés par Stripe, disent la cause sans la valeur.
            $this->logger->error('Stripe a refusé l\'appel', [
                'chemin' => $chemin,
                'statut' => $statut,
                'type' => $this->enumerated($corps['error']['type'] ?? null),
                'code' => $this->enumerated($corps['error']['code'] ?? null),
                'source' => $account->source->value,
                'profil' => $account->profileId,
            ]);

            throw new HttpException(502, 'Le prestataire de paiement a refusé la transaction.');
        }

        return $corps;
    }

    /** Un identifiant énuméré de Stripe (`invalid_request_error`, `api_key_expired`…) ; tout autre contenu est écarté. */
    private function enumerated(mixed $value): ?string
    {
        return \is_string($value) && preg_match('/^[a-z0-9_]{1,64}$/', $value) === 1 ? $value : null;
    }
}
