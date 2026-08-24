<?php

declare(strict_types=1);

namespace App\Subscription\Enum;

/**
 * État d'une demande de provisioning (ED-3, RG-ED-05).
 *
 * **Trois états, et le troisième n'est pas une erreur technique.** `Failed` signifie qu'un
 * provisioning a été tenté et qu'il ne doit pas être rejoué à l'identique : l'adresse de
 * l'administrateur est déjà prise, le rôle modèle est absent. Rejouer ne changerait rien tant que
 * la cause n'est pas levée — d'où un état distinct de `Pending`, qui lui attend encore son tour.
 *
 * **Il n'y a pas d'état « en cours ».** La demande est créée en `Pending` et validée en base avant
 * que le moindre établissement ne soit créé : c'est cette écriture qui sert de verrou (voir
 * {@see \App\Subscription\Entity\ProvisioningRequest}), pas un drapeau applicatif qu'un processus
 * interrompu laisserait allumé pour toujours.
 */
enum ProvisioningStatus: string
{
    /** Demande enregistrée, établissement pas encore créé. */
    case Pending = 'pending';

    /** Établissement, administrateur et modules en place. État final du chemin nominal. */
    case Completed = 'completed';

    /** Tentative refusée pour une cause qu'un rejeu ne lèverait pas. Voir `failureReason`. */
    case Failed = 'failed';
}
