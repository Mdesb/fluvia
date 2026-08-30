<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrait de `off_produit_associe` — la table que l'écran remplissait et que rien ne lisait.
 *
 * ── CE QU'ELLE ÉTAIT ────────────────────────────────────────────────────────────────────────────
 *
 * Un `ManyToMany` produit → produit, exposé en lecture **et en écriture**, avec un écran complet dans
 * la fiche produit dont le texte d'aide disait : « le cadenas avec l'entrée piscine, l'audioguide
 * avec la visite ».
 *
 * Mesuré le 30/08 : six occurrences PHP, **les six dans `Produit.php`** — déclaration, `JoinTable`,
 * constructeur, getter, `add`. Aucun appelant, nulle part. La caisse ne l'a jamais lue.
 *
 * Et un `add` **sans `remove`** : l'exploitant cochait, l'écran enregistrait, décocher ne retirait
 * rien. C'est le premier des trois patrons relevés dans « les 200 menteurs ».
 *
 * ── ⚠ POURQUOI CE `DROP` NE PERD RIEN, ET COMMENT ON LE SAIT ───────────────────────────────────
 *
 * `SELECT COUNT(*) FROM off_produit_associe` rendait **0** au moment de la suppression, et
 * `off_complementary_product` reprend le même lien depuis le 30/08. Une table vide dont le
 * remplaçant est en place : le `DROP` ne détruit aucune information, il retire une promesse que
 * personne ne tenait.
 *
 * ⚠ Le témoin importe autant que le compte : la même requête sur `off_complementary_product` rend un
 * nombre non nul. Un zéro seul se soupçonne — celui-ci est encadré.
 *
 * ── ⚠ L'ORDRE ÉTAIT ASYMÉTRIQUE, ET LA FAUTE SILENCIEUSE ÉTAIT DU CÔTÉ RAPIDE ──────────────────
 *
 * Ce retrait attendait que la fiche produit cesse d'écrire le champ — livré par `allaccess-c2`, qui
 * pose, lit et retire désormais des `ComplementaryProduct`.
 *
 * L'ordre inverse n'aurait **rien cassé bruyamment** : un champ inconnu est ignoré à la
 * désérialisation, l'écran aurait continué d'envoyer sa liste, et l'exploitant aurait continué de
 * cocher dans le vide en croyant paramétrer. C'est précisément pour cela que l'ordre comptait.
 *
 * ── CE QUI REMPLACE, ET CE QUE LE REMPLAÇANT AJOUTE ────────────────────────────────────────────
 *
 * `off_complementary_product` porte ce qu'un `ManyToMany` nu ne pouvait pas porter : le **mode**
 * (facultatif / suggéré / obligatoire) — qui est toute la raison d'être de l'objet —, la quantité
 * proposée par défaut, l'unicité de la paire, le `ON DELETE CASCADE` des deux côtés, un retrait
 * possible, et une garde de vente qui **le lit**.
 *
 * DDL relevé sur le mapping (D32).
 */
final class Version20260830190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retrait de off_produit_associe : table vide, remplacée par off_complementary_product.';
    }

    /**
     * @drop-voulu : la table etait VIDE (0 ligne, mesure avant le DROP) et rien ne la lisait —
     * six occurrences PHP, les six dans Produit.php, aucun appelant. Remplacee depuis le 30/08
     * par `off_complementary_product`, qui porte le mode, la quantite par defaut, l'unicite
     * de la paire et un retrait possible. L'ecran qui l'ecrivait a ete repointe par allaccess-c2
     * AVANT ce retrait : l'ordre importait, un champ inconnu etant ignore en silence a la
     * deserialisation.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE off_produit_associe');
    }

    /**
     * ⚠ `down()` recrée la table VIDE, et c'est tout ce qu'il peut honnêtement faire.
     *
     * Elle l'était au moment du `DROP` — mesuré, pas supposé. Une migration descendante qui
     * prétendrait restaurer des lignes mentirait : il n'y en avait aucune.
     *
     * ⚠ ET CETTE DDL EST RELEVÉE SUR LA BASE, PAS ÉCRITE DE MÉMOIRE. Une première version inventait
     * des colonnes `INT` nommées `produit_source`/`produit_cible`. Le réel est `BINARY(16)`, la
     * seconde colonne s'appelle `produit_target`, et les index portent les noms générés par
     * Doctrine. Un `down()` approximatif ne se remarque qu'au moment où quelqu'un s'en sert — c'est
     *-à-dire au pire moment.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE off_produit_associe (
                produit_source BINARY(16) NOT NULL,
                produit_target BINARY(16) NOT NULL,
                INDEX IDX_BBBCDCC4A8D8B449 (produit_source),
                INDEX IDX_BBBCDCC4B13DE4C6 (produit_target),
                PRIMARY KEY (produit_source, produit_target)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }
}
