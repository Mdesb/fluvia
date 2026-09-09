<?php

declare(strict_types=1);

namespace App\Caisse\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Un guichet n'encaisse que pour une régie de SON établissement (RG-SOCLE-01, RG-REGIE-02).
 *
 * ── POURQUOI UNE CONTRAINTE, ET PAS UNE CONFIANCE DANS L'ÉCRAN ─────────────────────────────────
 *
 * `PointDeVente` est exposé en `Post`/`Patch` sous `caisse.gerer`, et `regie` est dans `pdv:write`.
 * API Platform désérialise l'IRI de la charge **directement dans l'entité** : personne ne vérifie à
 * quel établissement appartient la cible. C'est mot pour mot le défaut que le garde-fou n°8 décrit
 * sur `Acces\Entity\SousReseau`.
 *
 * Le garde-fou n°8, lui, ne se déclenchera pas ici : il ne parle que du cas où le PORTEUR n'est pas
 * cloisonné, et `PointDeVente` l'est. L'absence d'alerte n'est donc pas une preuve d'innocuité —
 * c'est exactement la raison d'écrire la contrainte à la main.
 *
 * ⚠ CE QU'UN RATTACHEMENT CROISÉ PRODUIRAIT. La clôture Z d'un guichet ferait monter l'encaisse
 * d'une régie d'un autre établissement. Le symptôme serait un plafond qui déborde là où personne
 * n'a encaissé, et une clôture comptable refusée à un exploitant qui n'y peut rien — deux
 * établissements de suite avant que quiconque relie les deux.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RevenueOfficeWithinTenant extends Constraint
{
    public string $message = 'Cette régie de recettes n\'appartient pas à l\'établissement de ce point de vente.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
