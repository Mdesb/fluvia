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
 *
 * ⚠ **REGLE : UNE PORTEE D'ECRITURE N'ENTRE ICI QU'AVEC LA RESSOURCE QUI LA PORTE.** `bookings:write`
 * a ete retiree le 04/10 (spec API partenaire v1, §3.2) : depuis que les exploitants accordent eux-memes
 * les portees, la proposer revenait a leur faire consentir a une ecriture que rien n'execute.
 */
enum ApiScope: string
{
    case BookingsRead = 'bookings:read';
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
            self::SalesRead => 'Lire les ventes',
            self::CustomersRead => 'Lire la fiche des clients',
            self::AccessRead => 'Lire les passages de contrôle d\'accès',
            self::EventsSubscribe => 'Recevoir les événements en temps réel',
        };
    }
}
