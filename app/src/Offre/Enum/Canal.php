<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Canal de vente/visibilité (RG-M1-07 / RG-M1-09).
 *
 * **Un canal ne peut pas exister sans déclarer son attente de paiement et son débiteur** (D46-bis).
 * Les deux `match` ci-dessous sont volontairement **sans `default`** : ajouter un cas sans le décrire
 * ici lève `UnhandledMatchError` au premier appel, et `CanalContratTest` le fait échouer avant qu'il
 * n'arrive en production.
 *
 * **Ce que PHP ne permet pas, et ce qui le remplace.** L'idéal serait que la donnée soit exigée à la
 * construction — une énumération PHP n'a pas de constructeur, donc c'est impossible. Le couple
 * « `match` exhaustif + test qui parcourt `cases()` » en est l'équivalent le plus proche : l'omission
 * n'est pas rattrapée par la relecture, elle est rattrapée par la suite, systématiquement.
 *
 * **Pourquoi cette précaution ici plutôt qu'ailleurs.** Maxime a trouvé le trou en trois secondes
 * parce qu'il connaît son métier ; le prochain canal sera ajouté par quelqu'un qui ne le connaîtra
 * pas — et cette énumération doit grandir de trois valeurs cette année.
 */
enum Canal: string
{
    case Guichet = 'guichet';
    case EnLigne = 'en_ligne';
    case Borne = 'borne';
    /** D38 — application mobile : même attente qu'en ligne. */
    case Appli = 'appli';
    /** D45-bis — vente de gestion : facturée à terme convenu. */
    case Gestion = 'gestion';
    /** Agence de voyage / revendeur : le règlement vient du partenaire, groupé et différé. */
    case Ota = 'ota';

    /**
     * Ce que ce canal attend comme règlement — donc ce qui fait d'une vente non soldée une anomalie,
     * ou non.
     */
    public function attentePaiement(): PaymentExpectation
    {
        return match ($this) {
            self::Guichet, self::EnLigne, self::Borne, self::Appli => PaymentExpectation::Immediate,
            self::Gestion => PaymentExpectation::AgreedTerm,
            self::Ota => PaymentExpectation::DeferredPooled,
        };
    }

    /**
     * Qui doit l'argent. Savoir qu'un règlement est attendu ne dit pas **à qui le réclamer** : c'est
     * la moitié qui manquait, et c'est elle qui évite de relancer un visiteur ayant payé son agence.
     */
    public function debiteur(): Debtor
    {
        return match ($this) {
            self::Guichet, self::EnLigne, self::Borne, self::Appli, self::Gestion => Debtor::Customer,
            self::Ota => Debtor::Partner,
        };
    }

    // Volontairement PAS de raccourci du genre `nonSoldeeEstCreanceClient(): bool`. Je l'avais écrit,
    // puis retiré : il rendait `true` pour `gestion`, alors qu'une vente à terme convenu non soldée
    // **avant** l'échéance est parfaitement normale — elle ne devient une créance qu'après. Un
    // booléen sans la date se serait donc trompé sur un canal sur six, et il aurait eu l'air de
    // dispenser l'appelant de réfléchir. C'est exactement le raccourci que D46-bis interdit, à un
    // niveau d'abstraction de plus. Les deux propriétés suffisent ; qui a besoin de l'échéance doit
    // aller la chercher.
}
