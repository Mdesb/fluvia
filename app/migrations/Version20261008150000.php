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
 * ── DEUX PROFILS D'UN MEME SIREN : DEUX SERIES DISTINCTES (decision de Maxime du 08/10) ─────────
 *
 * L'index par exploitant laisserait deux profils d'un meme SIREN rendre chacun `FA-2026-00001` au
 * meme vendeur. `facturation_serie_numerotation.code` porte donc le code court qui separe leurs
 * series (`FA-<code>-2026-00001`), pose par `GenerateurNumeroFacture` a la premiere facture du profil.
 * Toutes les series existantes restent sans code : leur numerotation continue a l'identique.
 *
 * ── SANS PERTE ──────────────────────────────────────────────────────────────────────────────────
 *
 * Aucune ligne n'est touchee : un index change, une colonne nullable s'ajoute. Le nouvel index est
 * plus large que l'ancien : toute table que l'ancien acceptait, le nouveau l'accepte. Mesure en preprod avant d'ecrire (lecture) :
 * 7 factures, un seul exploitant, aucun doublon ni sur `(profil, numero)` ni sur le numero seul.
 * Le nouvel index est pose AVANT que l'ancien tombe : la table n'est jamais sans unicite.
 *
 * @drop-voulu : `uniq_facture_numero` disparait parce que c'est LUI le defaut — unique sur le numero
 * seul, il interdit a un second exploitant sa propre serie. `uniq_facture_profil_numero` le
 * remplace et le contient. Le `down()` le repose, et refuse en le disant si deux exploitants portent
 * deja le meme numero, ou si une serie porte deja un code : on ne renumerote pas une facture emise.
 */
final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le numero de facture est unique par exploitant (sa serie), et deux profils d un meme SIREN ont des series distinctes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_facture_profil_numero ON facturation_facture (profil_exploitant_id, numero)');
        $this->addSql('DROP INDEX uniq_facture_numero ON facturation_facture');
        $this->addSql('ALTER TABLE facturation_serie_numerotation ADD code VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Lu avant toute ecriture : on ne renumerote pas une facture emise, et une serie codee ne
        // retombe pas dans celle d'un autre profil.
        $doublons = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT numero FROM facturation_facture WHERE numero IS NOT NULL GROUP BY numero HAVING COUNT(*) > 1) d',
        );
        $this->abortIf(
            $doublons > 0,
            sprintf('%d numero(s) de facture portes par plusieurs exploitants : l’index unique sur le numero seul ne peut pas revenir sans renumeroter des factures emises.', $doublons),
        );
        $codees = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM facturation_serie_numerotation WHERE code IS NOT NULL');
        $this->abortIf(
            $codees > 0,
            sprintf('%d serie(s) portent un code de profil : sans lui, ces profils reprendraient la serie sans code d’un autre profil du meme SIREN.', $codees),
        );

        $this->addSql('ALTER TABLE facturation_serie_numerotation DROP code');
        $this->addSql('CREATE UNIQUE INDEX uniq_facture_numero ON facturation_facture (numero)');
        $this->addSql('DROP INDEX uniq_facture_profil_numero ON facturation_facture');
    }
}
