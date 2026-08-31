<?php

declare(strict_types=1);

namespace App\Offre\Enum;

/**
 * Qui doit l'argent d'une vente, selon son canal (D46-bis).
 *
 * **La propriété que personne n'avait vue**, et qui manquait pour que l'attente de paiement soit
 * exploitable : savoir qu'un règlement est attendu ne dit pas **à qui le réclamer**.
 */
enum Debtor: string
{
    /** Le client de la vente — le cas de cinq canaux sur six. */
    case Customer = 'customer';

    /**
     * Le partenaire, pas le client (canal `ota`). Le visiteur a payé son agence ; c'est l'agence qui
     * reverse. Relancer le client reviendrait à réclamer de l'argent à quelqu'un qui a déjà payé —
     * le pire résultat possible pour une fonction censée en récupérer.
     */
    case Partner = 'partner';
}
