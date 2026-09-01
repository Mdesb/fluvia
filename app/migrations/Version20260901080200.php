<?php

declare(strict_types=1);

/*
 * ⚠ RENUMEROTEE DE 20260901040200 A 20260901080200 A L'INTEGRATION, LE 01/09.
 *
 * Elle se triait AVANT `Version20260901060000`, qui cree `import_batch` et la colonne
 * `crm_client.import_batch_ref`. Sur un environnement neuf, elle serait donc passee avant ce
 * qu'elle suppose.
 *
 * Les deux migrations de ce lot ont ete decalees du MEME nombre, pour que leur ordre relatif soit
 * preserve par construction — la faute inverse a casse la preproduction quelques heures plus tot.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `App\Crm\Entity\Client.importBatchRef` (plan-import-i1.md §0.6/§1/§4, T5 — coordination requise, §7
 * point 4 du plan) — **seule migration de ce lot touchant une table existante d'un autre module**,
 * additive, colonne nullable : aucune donnée existante affectée.
 *
 * `?Uuid` nu, **pas** de FK vers `import_batch` (D2 littéral de la spec, §5) : posé une seule fois à la
 * création d'un client par reprise, jamais réécrit par une mise à jour ultérieure.
 */
final class Version20260901080200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Import (I1) : crm_client.import_batch_ref (colonne nue, sans FK, D2).';
    }

    public function up(Schema $schema): void
    {
        // ⚠ L'AJOUT DE LA COLONNE A ETE RETIRE A L'INTEGRATION, LE 01/09.
        //
        // `crm_client.import_batch_ref` est deja posee par `Version20260901060000` — l'autre moitie
        // du module Import, ecrite par une seconde session sans que les deux le sachent. Sur un
        // environnement NEUF comme sur la preproduction, `060000` passe avant celle-ci : le doublon
        // est structurel, pas circonstanciel.
        //
        // Ne reste que ce que cette migration apporte reellement : l'index.
        $this->addSql('CREATE INDEX idx_client_import_batch_ref ON crm_client (import_batch_ref)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_client_import_batch_ref ON crm_client');
        // ⚠ ON NE SUPPRIME PAS LA COLONNE : elle ne lui appartient pas. La retirer ici defaireait
        // `Version20260901060000` au passage, et un retour en arriere casserait plus que ce qu'il
        // annule.
    }
}
