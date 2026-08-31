<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UNE COLONNE `NOT NULL` SANS DÉFAUT EST UNE MINE POUR LE CODE QUI TOURNE ENCORE.
 *
 * ── CE QUI EST ARRIVÉ, LE SOIR MÊME ──────────────────────────────────────────────────────────────
 *
 * `Version20260831180000` a ajouté `adresse JSON NOT NULL` — sans défaut. La migration a été
 * appliquée, le déploiement ne l'a pas suivie tout de suite, et pendant deux heures :
 *
 *     base          adresse NOT NULL, aucun défaut
 *     PHP servi     une classe `ProfilExploitant` qui ne connaît pas `adresse` (21 commits de retard)
 *
 * Un `POST /api/profil_exploitants` — exposé, gardé par `compta.gerer` — aurait construit un objet
 * sans ce champ, et l'INSERT aurait omis une colonne obligatoire sans défaut : **erreur SQL, 500**.
 * Pas un risque théorique de désynchronisation : une écriture qui échoue, sur un chemin exposé.
 * Relevé par `b8`.
 *
 * ── ET LE DÉPLOIEMENT OUVRE CETTE FENÊTRE À CHAQUE FOIS ──────────────────────────────────────────
 *
 * ⚠ `deploy-preprod.sh` applique les migrations (ligne 140) **avant** de redémarrer FPM (ligne 193).
 * Entre les deux, le schéma est neuf et le code est ancien. La fenêtre dure quelques secondes en
 * temps normal — elle a duré deux heures ici parce que j'ai migré sans déployer.
 *
 * Inverser l'ordre ne résout rien : du code neuf sur un schéma ancien casse tout autant.
 *
 * **La seule forme qui tient est un changement de schéma compatible avec les DEUX versions du
 * code.** Un `DEFAULT` suffit ici : l'ancien code n'écrit pas la colonne, la base la remplit ;
 * le nouveau l'écrit, et le défaut ne sert plus.
 *
 * ⚠ Le défaut est aussi déclaré au mapping (`options: ['default' => '[]']`) — sinon le garde-fou
 * D32 refuserait un DEFAULT que la base porte et que le mapping ignore. Les deux doivent dire la
 * même chose, et c'est précisément ce que ce garde-fou existe pour tenir.
 */
final class Version20260831210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'L’adresse du siège a un défaut : le schéma reste compatible avec le code d’avant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE compta_profil_exploitant MODIFY adresse JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant MODIFY adresse JSON NOT NULL');
    }
}
