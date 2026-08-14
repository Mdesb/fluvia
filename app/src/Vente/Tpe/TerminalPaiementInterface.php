<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Caisse\Entity\PointDeVente;

/**
 * Connecteur TPE (Ingenico / Nayax / PAX selon le point de vente, US-L2-07). Le montant est envoyé
 * automatiquement au terminal ; le résultat (accepté/refusé/annulé/timeout) revient dans la caisse.
 * En L2, une implémentation mock permet de simuler les issues.
 */
interface TerminalPaiementInterface
{
    public function demander(PointDeVente $pointDeVente, string $montant): ResultatTpe;
}
