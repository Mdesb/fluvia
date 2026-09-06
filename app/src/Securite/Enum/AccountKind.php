<?php

declare(strict_types=1);

namespace App\Securite\Enum;

/**
 * LA NATURE D'UN COMPTE : EXPLOITANT, OU CLIENT FINAL — audit du 06/09, constat 4.
 *
 * `POST /boutique/comptes` est public et crée un `Utilisateur` actif dans la même table que les
 * exploitants. N'importe qui obtenait donc, par `/auth`, un jeton qui franchissait toutes les portes
 * « connecté, et rien de plus » du back-office (vérifié en préproduction : 201, puis 200 + JWT).
 *
 * ⚠ UNE NATURE, PAS UN RÔLE. Un rôle se délègue, se recopie dans un rôle modèle, s'ajoute par une
 * affectation ; la nature d'un compte se pose à sa création et ne bouge plus. C'est ce qui permet à
 * `CustomerAccountPathListener` de refuser un client final hors de l'espace client sans qu'une
 * permission mal recopiée puisse l'y faire entrer. Elle se traduit en rôle Symfony (`role()`) pour
 * que `security.yaml` et les expressions `is_granted` puissent la lire.
 */
enum AccountKind: string
{
    case Operator = 'operator';
    case Customer = 'customer';

    public function role(): string
    {
        return match ($this) {
            self::Operator => 'ROLE_OPERATOR',
            self::Customer => 'ROLE_CUSTOMER',
        };
    }
}
