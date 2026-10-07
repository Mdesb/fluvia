<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Securite\Entity\Utilisateur;

/**
 * Le SEUL endroit qui définit « membre de l'équipe plateforme » — un compte qui voit et administre
 * TOUS les établissements, quel que soit son rattachement.
 *
 * ── POURQUOI CETTE EXCEPTION EXISTE, ET POURQUOI ELLE EST SÛRE ICI ─────────────────────────────
 *
 * RG-ED-07 pose qu'aucun rôle ne voit tous les établissements : c'est la règle d'un SaaS vendu à des
 * exploitants TIERS, où l'éditeur ne doit pas lire les données de ses clients. Dans ce déploiement,
 * tous les sites appartiennent au MÊME propriétaire ; il n'y a pas de tiers à cloisonner, et « mon
 * équipe voit tout » est un droit de propriété, pas une brèche d'isolation. On assume donc
 * l'exception — bornée à ce seuil unique, pour qu'elle se lise, se teste et se retire d'un endroit.
 *
 * ⚠ Le jour où Fluvia est vendu à des tiers, cette classe est le point à durcir (borner le périmètre
 * global au groupe du propriétaire), pas trente extensions.
 */
final class PlatformScope
{
    public function isGlobal(Utilisateur $user): bool
    {
        return $user->isPlateforme();
    }
}
