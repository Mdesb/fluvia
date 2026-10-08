<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use Doctrine\DBAL\Connection;

/**
 * UNE FACTURATION D'ABSENCE NE SE TRAITE QU'UNE FOIS (G-5 du ticket opposable, lot 4).
 *
 * Le débit du porte-monnaie, la vente d'un agent et l'exonération partent tous de « à facturer », lu
 * sur une entité chargée AVANT : deux gestes simultanés passaient tous deux, et le second écrasait le
 * premier — un client débité deux fois, ou « exonéré » et débité. Chacun prend donc la ligne de la
 * facturation dans sa transaction et relit son statut SOUS ce verrou ; le second attend le premier,
 * puis voit son issue.
 *
 * Ordre des verrous : la facturation d'abord, puis la vente (celui de `ValiderVenteService`).
 */
final class NoShowBillingLock
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** Le statut en base, la ligne tenue jusqu'à la fin de la transaction en cours ; `null` si elle n'existe pas. */
    public function lock(FacturationNoShow $facturation): ?StatutFacturationNoShow
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Une facturation d\'absence se verrouille dans une transaction : hors d\'elle, le verrou tombe aussitôt.');
        }
        $statut = $this->connection->fetchOne(
            'SELECT statut FROM reservation_facturation_no_show WHERE id = UNHEX(:id) FOR UPDATE',
            ['id' => bin2hex($facturation->getId()->toBinary())],
        );

        return \is_string($statut) ? StatutFacturationNoShow::tryFrom($statut) : null;
    }
}
