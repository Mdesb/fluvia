<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use Symfony\Component\HttpFoundation\Request;

/**
 * A provider that calls us back server to server (#339) — PayFiP's URLNOTIF, Stripe's webhook.
 *
 * A notification is an INVITATION TO ASK, never an answer: PayFiP's guide says so ("elle représente
 * une invitation pour le SI partenaire à effectuer l'appel Web service permettant la récupération du
 * résultat", Régie Web service V18.0, §3.5.2.5). The adapter only extracts the reference from it; the
 * outcome always comes from `verifierRetour()`, i.e. from a call WE make to the provider. A forged
 * notification can therefore at worst make us ask a question whose answer we trust.
 */
interface NotifiedOnlinePaymentInterface
{
    /** The path segment of the notification URL: `/api/boutique/paiement-en-ligne/notification/{provider}`. */
    public function provider(): string;

    /** The transaction reference the notification is about, or `null` if it carries none. */
    public function referenceFromNotification(Request $request): ?string;
}
