<?php

declare(strict_types=1);

namespace App\Vente\Port;

use Symfony\Component\Uid\Uuid;

/**
 * Frontière M4 (CRM, hors périmètre L2) : recherche / création / rattachement d'un client
 * (US-L2-05). En L2, un stub renvoie un identifiant logique déterministe ; le câblage réel au
 * fichier client M4 intervient à l'intégration (L5).
 */
interface ClientM4Interface
{
    /**
     * Recherche un client par nom, e-mail ou n° de compte. Renvoie son identifiant logique ou null.
     */
    public function rechercher(string $critere): ?Uuid;

    /**
     * Crée rapidement un client à partir de données minimales et renvoie son identifiant logique.
     *
     * @param array<string, mixed> $donnees
     */
    public function creerRapide(array $donnees): Uuid;
}
