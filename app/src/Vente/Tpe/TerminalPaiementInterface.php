<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Caisse\Entity\PointDeVente;

/**
 * Connecteur TPE (Ingenico / Nayax / PAX selon le point de vente, US-L2-07). Le montant est envoyé
 * automatiquement au terminal ; le résultat (accepté/refusé/annulé/timeout) revient dans la caisse.
 * En L2, une implémentation mock permet de simuler les issues.
 *
 * ⚠ CONTRAT DES ERREURS (ticket opposable, lot 2) : une exception HTTP 4xx dit que le terminal n'a RIEN
 * débité (aucun terminal configuré, demande refusée avant l'envoi) ; la vente reste libre. Toute autre
 * exception laisse l'issue inconnue : la tentative devient `unresolved` et la vente reste bloquée
 * jusqu'à ce que le caissier déclare ce qu'affiche le terminal. Un adaptateur réel doit s'y tenir.
 */
interface TerminalPaiementInterface
{
    public function demander(PointDeVente $pointDeVente, string $montant): ResultatTpe;
}
