<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

/**
 * Connecteur d'un terminal de paiement (US-L2-07 ; spec TPE lot 1). ASYNCHRONE : un paiement avec
 * code PIN dure plus que la coupure de nginx (60 s), et un worker PHP-FPM ne doit pas l'attendre.
 *
 * - `demarrer()` envoie la demande et rend la main tout de suite. Le coordinateur fait ensuite passer
 *   la tentative `pending` → `sent`.
 * - L'issue arrive PLUS TARD, et par un seul chemin : {@see TerminalOutcomeSink::record()} — celui
 *   d'un pont local, d'un webhook, d'un worker ou du caissier qui lit le terminal. Ce chemin ne fait
 *   passer la tentative que depuis un statut sans issue : une réponse tardive et une déclaration se la
 *   disputent sans double règlement.
 * - `annuler()` demande au terminal d'abandonner ; la tentative passe `cancelled` sans attendre. Une
 *   acceptation qui arrive ensuite gagne : le client a été débité, le règlement s'écrit.
 *
 * ⚠ CONTRAT DES ERREURS DE `demarrer()` (ticket opposable, lot 2) : une exception HTTP 4xx dit que
 * RIEN n'est parti au terminal (aucun terminal configuré, demande refusée avant l'envoi) ; la vente
 * reste libre. Toute autre exception laisse l'issue inconnue : la tentative devient `unresolved` et
 * la vente reste tenue jusqu'à ce que le caissier déclare ce qu'affiche le terminal.
 */
interface TerminalPaiementInterface
{
    /** Le nom du connecteur, écrit sur la tentative (`PointDeVente.tpe.connecteur`). */
    public function connector(): string;

    /**
     * Au-delà, une tentative envoyée sans issue est jugée `unresolved` : le terminal a pu débiter, et
     * seule une déclaration la sort. Propre au connecteur — un pont qui rend toujours une issue n'a
     * pas le délai d'un terminal que le caissier lit.
     */
    public function staleAfterSeconds(): int;

    /** Envoie la demande au terminal et rend la main. Voir le contrat des erreurs. */
    public function demarrer(TerminalAttempt $tentative): void;

    /** Demande au terminal d'abandonner ; sans effet si l'issue est déjà rendue. Une erreur n'empêche pas l'annulation côté caisse. */
    public function annuler(TerminalAttempt $tentative): void;
}
