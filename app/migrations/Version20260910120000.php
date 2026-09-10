<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Socle du module `App\Membership` (lot 0) : la table `membership`. Aucune donnee migree, aucune
 * table existante touchee.
 *
 * ── ⚠ ECRITE A LA MAIN, MAIS PAS DE MEMOIRE ────────────────────────────────────────────────────
 *
 * Le CLAUDE.md interdit un `migrations:diff` brut, qui ratisserait la derive des autres sessions --
 * et il y en a une en ce moment sur `finance_treasury_cash_alert`, qui n'a rien a faire ici. La
 * methode prescrite est inversee par rapport a l'intuition : on DEMANDE d'abord le SQL a Doctrine
 * (`doctrine:schema:update --dump-sql`), on retient la seule partie qui nous concerne, et on
 * verifie l'absence de derive apres.
 *
 * Ce SQL est donc exactement celui que Doctrine a rendu le 10/09 pour cette entite, noms d'index et
 * de contraintes compris. Les renommer en minuscules aurait ete plus joli et aurait fabrique une
 * derive permanente : Doctrine aurait propose de les recreer a chaque comparaison.
 *
 * ── ⚠ LA TABLE DU MANDAT S'APPELLE `sepa_mandat` ───────────────────────────────────────────────
 *
 * Pas `sport_mandat_sepa_fitness`, qui est le nom que porte encore la migration de creation de
 * `sport_abonnement_fitness` (`Version20260815114107`). Le mandat a demenage depuis. C'est
 * precisement le genre de detail qu'on recopie sans verifier, et une cle etrangere vers une table
 * qui n'existe plus echoue en cours de deploiement -- comme le 07/09, ou une migration a bloque
 * TOUS les deploiements sur un errno 150.
 *
 * ── ⚠ `BINARY(16)`, JAMAIS `CHAR(36)` ──────────────────────────────────────────────────────────
 *
 * C'est la convention du depot, et s'en ecarter a deja coute : la migration du 07/09 avait declare
 * ses identifiants en `CHAR(36)`, MariaDB a refuse la cle etrangere entre deux types differents, et
 * le message parlait de « can't create table » alors que c'est la CONTRAINTE qui echouait.
 *
 * ── AUCUN `DROP` DANS `up()` ───────────────────────────────────────────────────────────────────
 *
 * Il n'y a rien a supprimer : la table est neuve. Le garde-fou n°13 n'a donc aucune annotation
 * `@drop-voulu` a reclamer, et c'est l'etat normal d'une migration de creation.
 */
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Socle du module Membership (lot 0) : table membership, aucune donnee migree.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE membership (
                id BINARY(16) NOT NULL,
                periodicity VARCHAR(12) NOT NULL,
                status VARCHAR(12) DEFAULT 'active' NOT NULL,
                subscribed_on DATE NOT NULL,
                commitment_starts_on DATE NOT NULL,
                commitment_ends_on DATE NOT NULL,
                notice_period_days SMALLINT DEFAULT 30 NOT NULL,
                amount_cents INT DEFAULT 0 NOT NULL,
                member_id BINARY(16) NOT NULL,
                payer_id BINARY(16) NOT NULL,
                formula_id BINARY(16) NOT NULL,
                sepa_mandate_id BINARY(16) NOT NULL,
                etablissement_id BINARY(16) NOT NULL,
                INDEX IDX_86FFD2857597D3FE (member_id),
                INDEX IDX_86FFD285C17AD9A9 (payer_id),
                INDEX IDX_86FFD285A50A6386 (formula_id),
                INDEX IDX_86FFD285A55266CC (sepa_mandate_id),
                INDEX IDX_86FFD285FF631228 (etablissement_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ⚠ AUCUN INDEX UNIQUE SUR `sepa_mandate_id`, ET C'EST UNE ERREUR DEJA COMMISE A COTE.
        //
        // La creation de `sport_abonnement_fitness` avait pose un `UNIQUE INDEX` sur son
        // `mandat_sepa_id`. Il a fallu le retirer le 01/09 : un meme mandat porte PLUSIEURS
        // abonnements -- un parent qui paie pour deux enfants signe un mandat, pas deux. La table
        // neuve ne rejoue pas ce defaut.
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD2857597D3FE FOREIGN KEY (member_id) REFERENCES crm_beneficiaire (id)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285C17AD9A9 FOREIGN KEY (payer_id) REFERENCES crm_client (id)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285A50A6386 FOREIGN KEY (formula_id) REFERENCES off_formule (id)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285A55266CC FOREIGN KEY (sepa_mandate_id) REFERENCES sepa_mandat (id)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // Ordre inverse : les contraintes d'abord, la table ensuite. MariaDB refuse de supprimer une
        // table encore referencee.
        $this->addSql('ALTER TABLE membership DROP FOREIGN KEY FK_86FFD2857597D3FE');
        $this->addSql('ALTER TABLE membership DROP FOREIGN KEY FK_86FFD285C17AD9A9');
        $this->addSql('ALTER TABLE membership DROP FOREIGN KEY FK_86FFD285A50A6386');
        $this->addSql('ALTER TABLE membership DROP FOREIGN KEY FK_86FFD285A55266CC');
        $this->addSql('ALTER TABLE membership DROP FOREIGN KEY FK_86FFD285FF631228');
        $this->addSql('DROP TABLE membership');
    }
}
