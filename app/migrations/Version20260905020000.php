<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE PRIX PAR PRATICIEN — un supplément sur la ressource, pas un second prix.
 *
 * ── L'ARBITRAGE ─────────────────────────────────────────────────────────────────────────────────
 *
 * Jusqu'ici le tarif vivait uniquement sur la prestation (`Activite::tarifReferenceMontant`) et
 * `Ressource` ne portait aucun montant. « Junior 25 €, senior 40 € », ou « avec Sophie c'est 15 €
 * de plus », était inexprimable : il fallait dupliquer la prestation par niveau — ce qui duplique
 * aussi ses créneaux, ses règles d'annulation et son délai de rappel.
 *
 * ⚠ **UN SUPPLÉMENT, ET NON UN PRIX ABSOLU.** Un prix absolu sur la ressource créerait une SECONDE
 * source pour le même chiffre : le jour où l'exploitant change le tarif de la prestation, les
 * praticiens qui portent un prix absolu ne bougeraient pas, sans que rien ne le signale. Avec un
 * supplément, il n'y a jamais qu'une grille — et « junior » est simplement le supplément à zéro.
 *
 * ── ⚠ CE QUE ÇA CHANGE, ET QUI DÉPASSE LA VENTE ─────────────────────────────────────────────────
 *
 * `Creneau::tarifReference()` a **deux** lecteurs, et le supplément coule dans les deux :
 *
 *   - `ReserverProcessor:192` — le prix payé. C'est l'effet attendu.
 *   - `DeclencherFacturationNoShowHandler:54` — le montant d'une non-présentation, calculé à partir
 *     du tarif de référence. Une absence sur un rendez-vous à 40 € se facture donc sur 40 €, pas
 *     sur 25 €.
 *
 * Ce second effet est VOULU et se dit : une heure de senior perdue coûte ce qu'elle vaut. Mais il
 * fallait le nommer, parce qu'il n'est visible nulle part depuis l'écran où l'on saisit le
 * supplément.
 *
 * ⚠ **DÉFAUT À ZÉRO** : les ressources existantes — lignes d'eau, terrains, bassins — n'ont aucun
 * supplément, et rien ne change pour elles. Une valeur non nulle par défaut renchérirait en silence
 * toutes les réservations de tous les établissements.
 *
 * ⚠ **DÉCIMAL, PAS FLOTTANT**, et de la même forme que `tarifReferenceMontant` : même précision,
 * même échelle. Deux montants qui s'additionnent et qui ne se stockent pas pareil finissent par
 * diverger à l'arrondi.
 */
final class Version20260905020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supplément tarifaire par ressource (défaut 0,00) : « avec Sophie, +15 € ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE reservation_ressource
             ADD supplement_tarif_montant NUMERIC(10, 2) DEFAULT '0.00' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        // ⚠ LA DESCENTE REND TOUS LES PRATICIENS AU MÊME PRIX, sans prévenir personne. Les
        // réservations déjà vendues gardent leur montant — il est figé sur la vente — mais les
        // suivantes repartiront au tarif de base.
        $this->addSql('ALTER TABLE reservation_ressource DROP supplement_tarif_montant');
    }
}
