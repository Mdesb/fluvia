<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La permission de corriger la ventilation d'un règlement (D45, `VTE-4`).
 *
 * **Pourquoi elle est distincte de `caisse.gerer`, et ce n'est pas une précaution de forme.**
 * Déplacer 50 € d'« espèces » vers « carte » sur une vente scellée fait disparaître un manquant de
 * caisse **sans qu'un billet ne bouge**. C'est exactement le geste qu'un caissier indélicat voudrait
 * pouvoir faire, et c'est pourquoi il ne doit appartenir ni au caissier, ni au responsable de caisse,
 * mais à quelqu'un qui n'a pas compté le tiroir.
 *
 * On ne rattache donc cette permission à **aucun rôle d'exploitation** : seul l'administrateur du
 * groupe la détient par le joker, et un exploitant qui veut la déléguer doit le faire **explicitement**
 * — ce qui laisse une trace de sa décision.
 *
 * **Semée par migration et non par fixture.** Les fixtures ne s'exécutent qu'en test et en
 * démonstration ; le seul chemin qui atteint la base d'un client est la migration. Constat du 24/08 :
 * les rôles du socle étaient vides chez un client parce que tout avait été posé en fixture.
 *
 * Écrite à la main, horodatée en heure locale (D32).
 */
final class Version20260825103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permission `vente.corriger_reglement` — distincte de la gestion de caisse (D45).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO sec_permission (id, module, action)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), 'vente', 'corriger_reglement' FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM sec_permission WHERE module = 'vente' AND action = 'corriger_reglement'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // On retire d'abord les rattachements : supprimer une permission encore reliee a un role
        // laisserait une ligne orpheline dans la table de liaison.
        $this->addSql(<<<'SQL'
            DELETE rp FROM sec_role_permission rp
              JOIN sec_permission p ON p.id = rp.permission_id
             WHERE p.module = 'vente' AND p.action = 'corriger_reglement'
            SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM sec_permission WHERE module = 'vente' AND action = 'corriger_reglement'
            SQL);
    }
}
