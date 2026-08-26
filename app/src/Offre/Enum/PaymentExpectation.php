<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Ce qu'un canal attend comme paiement (D46-bis). Porté par `Canal`, jamais deviné au cas par cas.
 *
 * **À quoi ça sert concrètement** : c'est ce qui dit si une vente non soldée est une **anomalie**, et
 * laquelle. Sans cette distinction, on ne peut que traiter tous les canaux pareil — et c'est ainsi
 * qu'une vente OTA en attente de reversement se retrouve relancée comme un impayé.
 */
enum PaymentExpectation: string
{
    /** Guichet, en ligne, borne, appli : la vente n'existe pas sans son règlement. */
    case Immediate = 'immediate';

    /**
     * Canal `gestion` : terme convenu (30 j, 60 j…). Non soldée **avant** l'échéance, c'est normal ;
     * après, c'est une créance.
     */
    case AgreedTerm = 'agreed_term';

    /**
     * Canal `ota` : le règlement arrive **groupé et plus tard**, du partenaire. Une vente isolée non
     * soldée n'y est pas une anomalie ; l'anomalie est un écart au rapprochement.
     */
    case DeferredPooled = 'deferred_pooled';
}
