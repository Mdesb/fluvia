<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * Origine du document (RG-FACT-03, « la règle cœur ») : c'est **elle seule**, jamais un choix manuel
 * à l'émission, qui détermine s'il faut comptabiliser.
 *
 * - `ticket_encaisse` : la vente M2 est déjà validée/scellée/payée en caisse, donc **déjà
 *   comptabilisée** (RG-COMPTA-04) — l'émission ne génère AUCUNE écriture, le CA ne bouge pas ;
 * - `vente_a_terme` : aucun passage caisse — l'émission **EST** le fait générateur comptable.
 */
enum OrigineFacture: string
{
    case TicketEncaisse = 'ticket_encaisse';
    case VenteATerme = 'vente_a_terme';
}
