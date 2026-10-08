<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE NUMERO DE FACTURE EST UNIQUE DANS SA SERIE, C'EST-A-DIRE PAR EXPLOITANT.
 *
 * ── LE DEFAUT, MESURE LE 08/10/2026 ─────────────────────────────────────────────────────────────
 *
 * La serie est tenue par exploitant (`facturation_serie_numerotation`, unique sur `(profil,
 * exercice, prefixe)`), mais `uniq_facture_numero` portait sur le numero SEUL. Le deuxieme
 * exploitant qui facture dans l'annee ouvre sa serie a 1, compose `FA-2026-00001`, et l'index le
 * rejette : 500, aucune facture emettable. Meme chose pour la serie `AVF`. Le numero porte deja son
 * prefixe et son annee : `(profil_exploitant_id, numero)` est donc exactement l'unicite de la serie.
 *
 * ── SANS PERTE ──────────────────────────────────────────────────────────────────────────────────
 *
 * Aucune ligne n'est touchee, seul l'index change. Le nouveau est plus large que l'ancien : toute
 * table que l'ancien acceptait, le nouveau l'accepte. Mesure en preprod avant d'ecrire (lecture) :
 * 7 factures, un seul exploitant, aucun doublon ni sur `(profil, numero)` ni sur le numero seul.
 * Le nouvel index est pose AVANT que l'ancien tombe : la table n'est jamais sans unicite.
 *
 * @drop-voulu : `uniq_facture_numero` disparait parce que c'est LUI le defaut — unique sur le numero
 * seul, il interdit a un second exploitant sa propre serie. `uniq_facture_profil_numero` le
 * remplace et le contient. Le `down()` le repose, et echoue si deux exploitants portent deja le meme
 * numero : c'est voulu, on ne renumerote pas une facture emise.
 */
final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le numero de facture est unique par exploitant (sa serie), plus sur toute la table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_facture_profil_numero ON facturation_facture (profil_exploitant_id, numero)');
        $this->addSql('DROP INDEX uniq_facture_numero ON facturation_facture');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_facture_numero ON facturation_facture (numero)');
        $this->addSql('DROP INDEX uniq_facture_profil_numero ON facturation_facture');
    }
}
