<?php

declare(strict_types=1);

namespace App\Securite\Port;

use App\Securite\Entity\Utilisateur;

/**
 * Le repli : sans module d'abonnement, aucun établissement n'est atteint par l'assistance.
 *
 * Le socle doit tourner seul, et le repli d'un mécanisme d'accès est de ne pas ouvrir. Le
 * cloisonnement ordinaire s'applique alors inchangé — un utilisateur ne voit que les établissements
 * où il a une affectation, exactement comme avant l'existence de ce port.
 *
 * ⚠ CETTE CLASSE EST LE DÉFAUT PAR DÉFAUT, ET C'EST VOULU — mais c'est aussi la forme exacte du
 * piège qu'on vient de désamorcer ailleurs : `SupportAccessRightsInterface` est resté câblé sur son
 * équivalent inerte, et toute la chaîne d'accès d'assistance n'accordait rien sans qu'aucune erreur
 * ne le dise. Le câblage réel vit dans `services.yaml` ; s'il disparaît, tout continue de
 * fonctionner et rien ne fonctionne.
 */
final class NoSupportAccessScope implements SupportAccessScopeInterface
{
    public function reachableEstablishmentIds(Utilisateur $agent, \DateTimeImmutable $at): array
    {
        return [];
    }
}
