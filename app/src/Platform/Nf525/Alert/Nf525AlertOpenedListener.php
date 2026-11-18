<?php

declare(strict_types=1);

namespace App\Platform\Nf525\Alert;

use App\Platform\Nf525\SealChainRef;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Appelé par `Nf525AlertStore::open` DANS la transaction de l'alerte, uniquement quand l'alerte vient de
 * s'ouvrir (jamais pour un événement compté sur une alerte déjà ouverte). Écrire sur `$alertConnection`
 * seulement : une exception annule l'alerte entière (cloche ⇔ alerte).
 */
interface Nf525AlertOpenedListener
{
    /** @param list<Uuid> $establishmentIds */
    public function onOpened(Connection $alertConnection, OpenedAlert $alert, SealChainRef $chain, array $establishmentIds): void;
}
