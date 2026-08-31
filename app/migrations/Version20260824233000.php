<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le rôle modèle « Administrateur d'établissement », dont dépend le provisionnement (ED-3, RG-ED-07).
 *
 * **Sans lui, une souscription payée échoue.** `ProvisioningService` duplique ce modèle pour habiliter
 * l'administrateur du client ; il refuse explicitement d'inventer une politique d'habilitation à sa
 * place. Le modèle n'existait que dans des fixtures — qui ne s'exécutent qu'en test et en
 * démonstration (constat de `claude-H`, repris par `claude-A` dans `Version20260824231500`). Le seul
 * chemin qui atteint la base d'un client est la migration ; c'est donc ici que le rôle doit naître.
 *
 * **Le joker `*.*` porte sur les ACTIONS, pas sur les DONNÉES.** Le cloisonnement par établissement
 * est assuré séparément, par l'extension Doctrine : un administrateur d'établissement peut tout faire
 * — dans son établissement, et nulle part ailleurs. Le joker plutôt qu'une liste de modules pour la
 * même raison que `claude-A` : une liste devrait être complétée à chaque module neuf, personne ne le
 * ferait, et c'est exactement le motif qui a produit le défaut d'origine.
 *
 * ---
 *
 * **`securite.gerer` en plus du joker, et ce n'est pas une redondance décorative.**
 *
 * `RoleAPrivileges::estAPrivileges()` décide si le MFA est obligatoire (RG-M8-06, CA-4). Il le décide
 * en cherchant une permission dont le **module** vaut `securite`. Or le joker a pour module `*` : un
 * rôle qui ne porterait que lui serait tout-puissant **et dispensé de MFA**, sans que rien ne le
 * signale. Un administrateur d'établissement gère les comptes de son établissement ; c'est
 * précisément le rôle pour lequel le second facteur existe.
 *
 * On rattache donc aussi `securite.gerer`, qui ne change rien aux droits — le joker les couvre déjà —
 * mais rend le rôle reconnaissable par le contrôle du MFA.
 *
 * **Le vrai défaut est ailleurs et il est signalé** : `RoleAPrivileges` ignore le joker. Tout rôle
 * `*.*` échappe aujourd'hui à l'obligation de MFA, y compris « Administrateur groupe ». La correction
 * appartient à `Securite` ; ce contournement-ci est local, explicite, et sans effet le jour où le
 * service saura lire le joker.
 */
final class Version20260824233000 extends AbstractMigration
{
    private const ROLE = "Administrateur d'établissement";

    public function getDescription(): string
    {
        return "Cree le role modele « Administrateur d'etablissement », requis par le provisionnement ED-3.";
    }

    public function up(Schema $schema): void
    {
        // Idempotente de bout en bout : la démonstration charge des fixtures qui peuvent créer les
        // mêmes lignes, et `sec_permission` porte une unicité sur (module, action).

        $this->addSql(<<<'SQL'
            INSERT INTO sec_permission (id, module, action)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), '*', '*' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM sec_permission WHERE module = '*' AND action = '*')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO sec_permission (id, module, action)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), 'securite', 'gerer' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM sec_permission WHERE module = 'securite' AND action = 'gerer')
            SQL);

        $this->addSql(<<<SQL
            INSERT INTO sec_role (id, nom, est_modele, role_modele_origine_id)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), '{$this->escaped()}', 1, NULL FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM sec_role WHERE nom = '{$this->escaped()}')
            SQL);

        foreach ([['*', '*'], ['securite', 'gerer']] as [$module, $action]) {
            $this->addSql(<<<SQL
                INSERT INTO sec_role_permission (role_id, permission_id)
                SELECT r.id, p.id
                  FROM sec_role r
                  JOIN sec_permission p ON p.module = '{$module}' AND p.action = '{$action}'
                 WHERE r.nom = '{$this->escaped()}'
                   AND NOT EXISTS (
                       SELECT 1 FROM sec_role_permission rp
                        WHERE rp.role_id = r.id AND rp.permission_id = p.id
                   )
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        // On retire les rattachements, pas le rôle ni les permissions : une base réelle a pu attacher
        // des utilisateurs à ce rôle entre-temps, et le supprimer les priverait de tout accès sans le
        // dire. Les permissions, elles, sont partagées avec d'autres rôles.
        $this->addSql(<<<SQL
            DELETE rp FROM sec_role_permission rp
              JOIN sec_role r ON r.id = rp.role_id
             WHERE r.nom = '{$this->escaped()}'
            SQL);
    }

    private function escaped(): string
    {
        return str_replace("'", "''", self::ROLE);
    }
}
