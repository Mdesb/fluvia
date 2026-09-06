<?php

declare(strict_types=1);

namespace App\PublicApi\Enum;

/**
 * Les portees qu'un etablissement peut accorder a une application tierce.
 *
 * ⚠ **CETTE LISTE EST COURTE, ET C'EST LE POINT.** Arbitrage de Maxime du 06/09 : la surface
 * publique est *restreinte et versionnee*, pas l'API interne rendue publique. Les 922 routes
 * internes restent internes et libres de bouger ; ce qui figure ici devient un contrat qu'on ne
 * peut plus casser sans casser un partenaire.
 *
 * ⚠ **ON N'AJOUTE PAS UNE PORTEE PARCE QU'ELLE EXISTERAIT « AU CAS OU ».** Une portee sans
 * ressource derriere est une promesse qu'un integrateur lit, demande, et n'obtient pas.
 */
enum ApiScope: string
{
    case BookingsRead = 'bookings:read';
    case BookingsWrite = 'bookings:write';
    case SalesRead = 'sales:read';
    case CustomersRead = 'customers:read';
    case AccessRead = 'access:read';

    /** L'abonnement aux evenements sortants : elle ne lit rien, elle fait recevoir. */
    case EventsSubscribe = 'events:subscribe';

    /** Le libelle montre a l'exploitant au moment ou il accorde l'acces. */
    public function label(): string
    {
        return match ($this) {
            self::BookingsRead => 'Lire les réservations',
            self::BookingsWrite => 'Créer et annuler des réservations',
            self::SalesRead => 'Lire les ventes',
            self::CustomersRead => 'Lire la fiche des clients',
            self::AccessRead => 'Lire les passages de contrôle d\'accès',
            self::EventsSubscribe => 'Recevoir les événements en temps réel',
        };
    }
}
