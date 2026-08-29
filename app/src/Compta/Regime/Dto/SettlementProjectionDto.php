<?php

declare(strict_types=1);

namespace App\Compta\Regime\Dto;

/**
 * Un règlement d'une vente, vu par le moteur comptable : par quel moyen, et pour quel montant NET.
 *
 * ── POURQUOI « NET » ────────────────────────────────────────────────────────────────────────────
 *
 * `Paiement` porte `montant` et `rendu`. En espèces, le client tend un billet de 50 pour 42,30 € :
 * le montant est 50, le rendu 7,70, et ce qui reste en caisse est 42,30. C'est ce net qui doit
 * peser dans la ventilation — sinon les espèces paraîtraient plus lourdes qu'elles ne le sont,
 * au prorata du hasard des billets tendus.
 *
 * ── LE MOYEN EST DÉSIGNÉ PAR SON CODE ───────────────────────────────────────────────────────────
 *
 * C'est ce que `Paiement` enregistre, et c'est donc la seule clé disponible sans faire dépendre
 * `App\Vente` de `App\Compta`. La correspondance vers le compte se fait côté comptable, sur le code.
 */
final class SettlementProjectionDto
{
    public function __construct(
        public readonly string $paymentMethodCode,
        /** Montant réellement encaissé, rendu de monnaie déduit. */
        public readonly int $netAmountCents,
    ) {
    }
}
