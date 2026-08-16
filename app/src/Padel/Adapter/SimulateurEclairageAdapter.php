<?php

declare(strict_types=1);

namespace App\Padel\Adapter;

use App\Padel\Dto\ResultatCommandeEclairage;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Padel\Enum\ActionEclairage;
use App\Padel\Enum\StatutRelaisEclairage;
use App\Padel\Port\PiloteEclairage;

/**
 * Simulateur logiciel (§2.2 du plan) : commande toujours réussie en mémoire, heartbeat toujours
 * « opérationnel » sauf override explicite pour les tests (`forcerDefaut()`). Débloque le
 * développement et les tests sans matériel réel (CA-11), même rôle que
 * `App\Acces\Adapter\SimulateurAccesAdapter`.
 */
final class SimulateurEclairageAdapter implements PiloteEclairage
{
    /** @var list<array{relais: string, action: string}> Journal des commandes, pour les tests. */
    private array $commandes = [];

    /** @var array<string, bool> Relais forcés en défaut (id => true), pour les tests. */
    private array $relaisEnDefaut = [];

    public function commander(RelaisEclairageTerrain $relais, ActionEclairage $action): ResultatCommandeEclairage
    {
        if ($this->relaisEnDefaut[(string) $relais->getId()] ?? false) {
            return ResultatCommandeEclairage::echec('Relais en défaut, aucune commande envoyée (simulée).');
        }

        $this->commandes[] = ['relais' => (string) $relais->getId(), 'action' => $action->value];

        return ResultatCommandeEclairage::ok(sprintf('Commande « %s » simulée sur le relais %s.', $action->value, $relais->getIdentifiantRelais()));
    }

    public function heartbeat(RelaisEclairageTerrain $relais): StatutRelaisEclairage
    {
        return ($this->relaisEnDefaut[(string) $relais->getId()] ?? false)
            ? StatutRelaisEclairage::EnDefaut
            : StatutRelaisEclairage::Operationnel;
    }

    /** Réservé aux tests : force un relais en défaut pour simuler une panne (CA-11 repli manuel). */
    public function forcerDefaut(RelaisEclairageTerrain $relais, bool $enDefaut = true): void
    {
        $this->relaisEnDefaut[(string) $relais->getId()] = $enDefaut;
    }

    /** @return list<array{relais: string, action: string}> */
    public function commandes(): array
    {
        return $this->commandes;
    }
}
