<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * ACT-4 — les permissions `dining.*`, créées **et accordées** (RG-SOCLE-02/03).
 *
 * **Posée dès le premier lot, avant qu'un endpoint n'existe** — même choix que pour `lodging`, et pour
 * la même raison apprise sur `stay` : les permissions n'y avaient vécu qu'en fixtures pendant plusieurs
 * lots, donc nulle part chez un client. Le module s'annonçait protégé et ne l'était pas, ce qui ne s'est
 * vu que le jour où quelqu'un a construit un menu et a dû retirer l'entrée, faute de pouvoir l'accorder
 * à qui que ce soit. Le coût de l'écrire maintenant est nul ; celui de l'oublier se paie en aval.
 *
 * `dining.fire` et `dining.void` sont séparées de `dining.write` volontairement : envoyer en cuisine
 * et annuler après envoi sont les deux gestes irréversibles du module. Les confondre avec la saisie
 * ordinaire donnerait à tout serveur le droit d'annuler un plat déjà sorti — or c'est une perte, et
 * une perte se valide.
 *
 * Rattachement par **nom de rôle, à deux noms** (patron de `Version20260824224000`) : une base
 * construite uniquement par les migrations n'a pas forcément les mêmes rôles qu'une base de
 * démonstration, et un identifiant en dur serait juste là où on l'a lu, faux partout ailleurs.
 *
 * ⚠ Écrite à la main, horodatée en **heure locale** (D32). Aucun `DROP`, aucune table touchée hors
 * `sec_permission`/`sec_role_permission`.
 */
final class Version20260901021200 extends AbstractMigration
{
    private const MODULE = 'dining';

    /** @var list<string> */
    private const ACTIONS = ['read', 'write', 'fire', 'void'];

    /** @var list<string> */
    private const ROLES = ['Administrateur groupe', 'Responsable de site'];

    public function getDescription(): string
    {
        return 'Restauration : permissions dining.* creees et accordees aux roles administrateur et responsable de site.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), self::MODULE, $action],
            );
        }

        $this->addSql(
            'INSERT IGNORE INTO sec_role_permission (role_id, permission_id) '
            . 'SELECT r.id, p.id FROM sec_role r, sec_permission p '
            . 'WHERE r.nom IN (?, ?) AND p.module = ?',
            [self::ROLES[0], self::ROLES[1], self::MODULE],
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
