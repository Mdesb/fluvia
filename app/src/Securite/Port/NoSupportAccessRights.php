<?php

declare(strict_types=1);

namespace App\Securite\Port;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;

/**
 * L'implémentation par défaut : personne n'a d'accès d'assistance.
 *
 * **Elle existe pour que le socle tourne sans le module d'abonnement**, et pour que le défaut soit le
 * refus. Si `Subscription` n'est pas installé — ou si son adaptateur n'est pas déclaré —, le calcul des
 * droits continue de fonctionner et n'accorde rien.
 *
 * **Le repli sûr d'un mécanisme d'accès est de ne pas ouvrir.** C'est la même règle que partout
 * ailleurs : une donnée absente doit faire refuser, jamais autoriser. `Stock` a montré aujourd'hui ce
 * que coûte l'inverse — trois lectures d'une configuration manquante, dont deux qui laissaient passer.
 *
 * **Elle ne trace rien, et c'est correct** : ne rien accorder n'est pas un refus d'accès d'assistance,
 * c'est l'absence de toute demande. Le refus tracé, lui, appartient à `SupportAccessGuard`, qui sait
 * distinguer « pas d'accès » de « accès révoqué ».
 */
final class NoSupportAccessRights implements SupportAccessRightsInterface
{
    public function grantedCodes(
        Utilisateur $agent,
        Etablissement $etablissement,
        \DateTimeImmutable $a,
    ): array {
        return [];
    }
}
