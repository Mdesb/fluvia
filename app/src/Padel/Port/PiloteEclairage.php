<?php

declare(strict_types=1);

namespace App\Padel\Port;

use App\Padel\Dto\ResultatCommandeEclairage;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Padel\Enum\ActionEclairage;
use App\Padel\Enum\StatutRelaisEclairage;

/**
 * Port principal d'intégration matériel du relais d'éclairage (§2.1 du plan) : contrat métier
 * agnostique du transport (contact sec, domotique, IoT — protocole réel non spécifié, Risque n°4).
 * Même patron que `App\Acces\Port\PiloteAcces`/`SimulateurAccesAdapter`.
 */
interface PiloteEclairage
{
    /** Commande l'allumage/extinction du relais, retourne succès/échec. */
    public function commander(RelaisEclairageTerrain $relais, ActionEclairage $action): ResultatCommandeEclairage;

    /** Vivacité du relais (opérationnel/en défaut), alimente le repli manuel. */
    public function heartbeat(RelaisEclairageTerrain $relais): StatutRelaisEclairage;
}
