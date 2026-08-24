<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Publication sociale : installation des permissions du module en base réelle.
 *
 * **Le défaut que cette migration corrige est le mien.** SOC-1 déclarait ses quatre permissions dans
 * le manifeste du module et les semait dans ses fixtures. Or les fixtures ne tournent qu'en test et
 * en démonstration : en préproduction comme chez un client, elles n'existaient nulle part. Le module
 * était donc complet, testé, et **inaccessible à tout le monde** — exactement le défaut que j'avais
 * signalé pour `support.*` sans voir que je venais de le commettre.
 *
 * Déclarer une permission dans un manifeste ne l'installe pas ; la semer en fixtures ne l'installe
 * que pour les tests. Sur ce dépôt, le seul chemin qui atteint une base réelle est la migration.
 *
 * **Créer la permission ne suffit pas non plus : il faut qu'un rôle la détienne.** Une permission que
 * personne ne porte se comporte exactement comme une permission absente — l'écran disparaît, l'API
 * refuse, et rien n'indique laquelle des deux causes est en jeu. Le rattachement à
 * « Administrateur groupe » se fait par une jointure sur le nom du rôle plutôt que par un
 * identifiant en dur : un identifiant écrit ici serait juste sur la base où on l'a lu et faux
 * partout ailleurs.
 *
 * `INSERT IGNORE` partout : la migration doit pouvoir s'appliquer sur une base où les permissions
 * auraient déjà été créées par un autre chemin — c'est précisément le cas de la préproduction, qui
 * porte des permissions créées par un chemin qui n'existe plus.
 */
final class Version20260824224000 extends AbstractMigration
{
    private const MODULE = 'social';

    /** @var list<string> */
    private const ACTIONS = ['read_account', 'manage_account', 'read_post', 'publish'];

    public function getDescription(): string
    {
        return 'Publication sociale : permissions social.* creees et accordees a l administrateur.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), self::MODULE, $action],
            );
        }

        // Rattachement par NOM de rôle, et à deux noms plutôt qu'un.
        //
        // Une base construite uniquement par les migrations ne contient pas « Administrateur groupe »
        // — ce rôle vient des fixtures, donc de la démonstration. Elle contient « Responsable de
        // site », qui est le rôle modèle installé par le socle et le destinataire naturel de la
        // publication sociale : c'est le responsable de l'établissement qui parle au nom de
        // l'établissement.
        //
        // On vise les deux : la base de démonstration a le premier, une installation neuve a le
        // second, et aucune n'a besoin qu'on sache laquelle on est en train de migrer. Un
        // identifiant de rôle en dur serait juste sur la base où on l'a lu et faux partout ailleurs.
        $this->addSql(
            'INSERT IGNORE INTO sec_role_permission (role_id, permission_id) '
            . 'SELECT r.id, p.id FROM sec_role r, sec_permission p '
            . 'WHERE r.nom IN (?, ?) AND p.module = ?',
            ['Administrateur groupe', 'Responsable de site', self::MODULE],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rp FROM sec_role_permission rp '
            . 'INNER JOIN sec_permission p ON p.id = rp.permission_id '
            . 'WHERE p.module = ?',
            [self::MODULE],
        );
        $this->addSql('DELETE FROM sec_permission WHERE module = ?', [self::MODULE]);
    }
}
