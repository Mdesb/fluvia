<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le rôle « Administrateur groupe » n'existait dans AUCUNE base réelle.
 *
 * Trouvé par `claude-H` le 24/08 en construisant une base uniquement par les migrations, pour vérifier
 * autre chose. Constat vérifié ensuite : trois migrations touchent `sec_role_permission`, et les seuls
 * rattachements qu'elles écrivent concernent « Client final ». Les rôles d'exploitation — Caissier,
 * Responsable de site, Comptable, Contrôleur — existent et **ne portent aucun droit**. Et
 * « Administrateur groupe » n'existe pas du tout : il est créé par les fixtures.
 *
 * **La cause est plus large que le défaut** : les fixtures ne s'exécutent qu'en test et en
 * démonstration. Le seul chemin qui atteint la base d'un client est la migration. Tout ce que ce dépôt
 * a posé en fixtures et qui n'est pas une donnée de démonstration — permissions, rôles, rattachements —
 * n'existe donc pas chez un client. Vingt-six fichiers de fixtures accordent des droits à
 * « Administrateur groupe » ; aucun ne s'exécutera jamais chez lui.
 *
 * **Conséquence concrète, le premier jour** : on crée un compte, on lui donne « Caissier », il ne peut
 * rien faire. Et comme aucun rôle d'administration n'existe, personne ne peut corriger la situation
 * depuis l'application. Le logiciel est inutilisable et ne dit pas pourquoi.
 *
 * **Ce que cette migration fait, et ce qu'elle ne fait pas.** Elle crée le rôle d'administration et lui
 * donne le joker `*.*`, reconnu par `CalculateurDroits`. Elle ne remplit PAS Caissier, Comptable et les
 * autres : décider quelles actions revient à un caissier est un choix métier, pas une réparation, et je
 * ne l'invente pas à leur place. Avec un administrateur qui existe, un exploitant peut composer ces
 * rôles depuis l'application — ce qu'il ne pouvait pas faire du tout jusqu'ici.
 *
 * **Pourquoi le joker plutôt que la liste des modules.** Une liste devrait être complétée à chaque
 * module neuf, et personne ne le ferait — c'est exactement le motif qui a produit ce défaut. Le joker
 * n'a pas besoin d'être entretenu. Et il porte sur les **actions**, pas sur les **données** : le
 * cloisonnement par établissement reste assuré par l'extension Doctrine, indépendamment des
 * permissions. Un administrateur de groupe peut tout faire — dans son groupe, et nulle part ailleurs.
 */
final class Version20260824231500 extends AbstractMigration
{
    private const ROLE = 'Administrateur groupe';

    public function getDescription(): string
    {
        return "Crée le rôle « Administrateur groupe » et son droit d'administration. "
            . 'Sans lui, une installation neuve n\'a aucun rôle capable de quoi que ce soit.';
    }

    public function up(Schema $schema): void
    {
        // Idempotente de bout en bout : la démonstration charge des fixtures qui créent le même rôle,
        // et `sec_permission` porte une unicité sur (module, action). Une écriture aveugle ferait
        // échouer l'une ou l'autre.

        $this->addSql(<<<'SQL'
            INSERT INTO sec_permission (id, module, action)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), '*', '*' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM sec_permission WHERE module = '*' AND action = '*')
            SQL);

        $this->addSql(<<<SQL
            INSERT INTO sec_role (id, nom, est_modele, role_modele_origine_id)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), '{$this->escaped()}', 1, NULL FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM sec_role WHERE nom = '{$this->escaped()}')
            SQL);

        $this->addSql(<<<SQL
            INSERT INTO sec_role_permission (role_id, permission_id)
            SELECT r.id, p.id
              FROM sec_role r
              JOIN sec_permission p ON p.module = '*' AND p.action = '*'
             WHERE r.nom = '{$this->escaped()}'
               AND NOT EXISTS (
                   SELECT 1 FROM sec_role_permission rp
                    WHERE rp.role_id = r.id AND rp.permission_id = p.id
               )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // On retire le rattachement, pas le rôle : une base réelle a pu y attacher des utilisateurs
        // entre-temps, et supprimer le rôle les priverait de tout accès sans le dire.
        $this->addSql(<<<SQL
            DELETE rp FROM sec_role_permission rp
              JOIN sec_role r ON r.id = rp.role_id
              JOIN sec_permission p ON p.id = rp.permission_id
             WHERE r.nom = '{$this->escaped()}' AND p.module = '*' AND p.action = '*'
            SQL);
    }

    private function escaped(): string
    {
        return str_replace("'", "''", self::ROLE);
    }
}
