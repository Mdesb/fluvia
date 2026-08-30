<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'exemption durable de blocage d'accès pour impayé — D84.
 *
 * Demandée par Maxime le 30/08 : la collectivité qui produit un impayé par mois et qu'on ne veut
 * jamais bloquer. Jusqu'ici chaque impayé se forçait à la main, un par un, indéfiniment.
 *
 * ── ELLE PORTE SUR LE REDEVABLE, PAS SUR LE DOSSIER ────────────────────────────────────────────
 *
 * `accesBloque` se pose par dossier ; la porte se ferme par redevable. Une exemption par dossier
 * serait un forçage renommé : le mois suivant, un nouvel incident referme la porte. D'où le couple
 * `debtor_type`/`debtor_ref`, qui reprend l'identification opaque d'`recouvrement_incident_impaye`.
 *
 * ── ⚠ AUCUN INDEX UNIQUE, ET CE N'EST PAS UN OUBLI ────────────────────────────────────────────
 *
 * « Une seule exemption ACTIVE par redevable » est un index PARTIEL (sur `revoked_at IS NULL`), que
 * MariaDB ne sait pas exprimer. Un unique sur le seul couple interdirait de ré-exempter un client
 * dont on a retiré l'exemption — donc de changer d'avis. La règle est tenue par
 * `BlockingExemptionRegistry::accorder()`, en un seul point, qui refuse en 409.
 *
 * ── ⚠ `reason` EST NON NULLE PARCE QU'ELLE EST LA SEULE GARDE ─────────────────────────────────
 *
 * Maxime a tranché que le droit exigé serait `recouvrement.forcer_acces`, le même que le forçage,
 * plutôt qu'un droit dédié. Contrepartie énoncée avant la décision : un agent de caisse peut
 * exempter un client pour toujours. Il ne reste donc que le motif écrit et la trace de l'agent pour
 * qu'on sache, dans six mois, pourquoi ce client ne bloque jamais.
 *
 * ── PAS DE DATE DE FIN, ET C'EST UN CHOIX ─────────────────────────────────────────────────────
 *
 * Une expiration obligatoire a été proposée et écartée : une exemption qui expire un lundi matin
 * bloque un client à la porte sans que personne n'ait rien décidé ce jour-là. Le retrait est un
 * geste, comme la pose — d'où `revoked_at`/`revoked_by` plutôt qu'une échéance.
 *
 * ⚠ `revoked_at` bascule au lieu d'effacer : supprimer la ligne perdrait la réponse à « qui avait
 * exempté ce client, et pourquoi, avant qu'on ne le rebloque ».
 */
final class Version20260830160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D84 — exemption durable de blocage d\'accès pour impayé (par redevable, sans date de fin, motif obligatoire).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recovery_blocking_exemption (
                id BINARY(16) NOT NULL,
                debtor_type VARCHAR(32) NOT NULL,
                debtor_ref VARCHAR(64) NOT NULL,
                reason LONGTEXT NOT NULL,
                granted_at DATETIME NOT NULL,
                revoked_at DATETIME DEFAULT NULL,
                etablissement_id BINARY(16) NOT NULL,
                granted_by_id BINARY(16) DEFAULT NULL,
                revoked_by_id BINARY(16) DEFAULT NULL,
                INDEX idx_blocking_exemption_debtor (debtor_type, debtor_ref),
                INDEX IDX_BLKEXM_ETAB (etablissement_id),
                INDEX IDX_BLKEXM_GRANTED_BY (granted_by_id),
                INDEX IDX_BLKEXM_REVOKED_BY (revoked_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE recovery_blocking_exemption ADD CONSTRAINT FK_BLKEXM_ETAB FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        // ⚠ SET NULL et non CASCADE : supprimer un agent ne doit pas effacer l'exemption qu'il a
        // posée. Le client resterait exempté sans qu'aucune ligne ne l'explique — c'est-à-dire
        // exactement l'inverse de ce que le motif obligatoire cherche à garantir.
        $this->addSql('ALTER TABLE recovery_blocking_exemption ADD CONSTRAINT FK_BLKEXM_GRANTED_BY FOREIGN KEY (granted_by_id) REFERENCES sec_utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE recovery_blocking_exemption ADD CONSTRAINT FK_BLKEXM_REVOKED_BY FOREIGN KEY (revoked_by_id) REFERENCES sec_utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recovery_blocking_exemption');
    }
}
