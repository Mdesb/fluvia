<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * REMETTRE LA BASE À PLAT ENTRE DEUX TESTS — sans reconstruire le schéma à chaque fois.
 *
 * ── CE QUE ÇA CORRIGE, ET CE QUE ÇA COÛTAIT ─────────────────────────────────────────────────────
 *
 * Soixante et une classes de base faisaient, chacune, `dropSchema()` puis `createSchema()` dans leur
 * `setUp()`. Or **`setUp()` s'exécute avant CHAQUE test**, pas avant chaque classe : le schéma de
 * près de trois cents tables était donc détruit et reconstruit **1 718 fois**.
 *
 * Mesuré le 27/08 : une classe à 1 test prenait 19 s, la même à 6 tests en prenait 65 — soit ~10 s
 * par test, dont l'écrasante majorité en DDL. C'est là que passaient les six heures neuf de la suite
 * complète.
 *
 * > **Une suite qu'on ne peut pas lancer est une suite dont on ne connaît pas le résultat.**
 *
 * Le 27/08 en a donné la preuve la plus chère : un test de non-régression du cloisonnement
 * machine/humain n'avait plus compilé depuis un mois, et personne ne pouvait le savoir.
 *
 * ── CE QUE FAIT CETTE CLASSE ────────────────────────────────────────────────────────────────────
 *
 * Le schéma est construit **une fois par processus**, puis chaque test repart d'une base vidée par
 * `TRUNCATE`. L'état de départ est identique — des tables vides, aux mêmes colonnes — mais on ne
 * repaie pas la construction.
 *
 * **Pourquoi c'est équivalent et pas seulement plus rapide.** Ce qu'un test attend de `setUp()`,
 * c'est de ne trouver que ce que ses fixtures ont posé. `DROP`+`CREATE` et `TRUNCATE` donnent le même
 * résultat sur ce point. La seule différence serait un test qui modifierait la STRUCTURE de la base —
 * il n'y en a pas, et s'il en apparaissait un, il devrait le déclarer plutôt que de compter sur un
 * effet de bord du harnais.
 *
 * ── DEUX PRÉCAUTIONS, TOUTES DEUX APPRISES À LEURS DÉPENS ───────────────────────────────────────
 *
 * **Les contraintes de clé étrangère sont coupées le temps de l'opération.** Sans ça, ni le `DROP`
 * ni le `TRUNCATE` ne trouvent d'ordre valide : `reservation_ressource` se référence elle-même
 * (`ressource_mere_id`), et aucun ordonnancement ne résout ce cas.
 *
 * **Les séquences natives ne se tronquent pas.** `acces_snapshot_seq` est créée par une migration,
 * hors mapping Doctrine : elle survit au `dropSchema` (qui ne connaît que les tables du mapping) et
 * un `TRUNCATE` dessus échouerait. Elle est donc écartée — comme l'est déjà l'historique des
 * migrations, qui n'est pas une donnée de test.
 */
final class SchemaDuHarnais
{
    /**
     * Le schéma a-t-il déjà été construit dans CE processus ?
     *
     * PHPUnit exécute toute la suite dans un seul processus, sauf isolation explicite : un booléen
     * statique suffit, et il se réinitialise naturellement d'une exécution à l'autre.
     */
    private static bool $construit = false;

    /** Tables à ne jamais vider : ce ne sont pas des données de test. */
    private const HORS_DONNEES = ['doctrine_migration_versions'];

    public static function reinitialiser(EntityManagerInterface $em): void
    {
        $connexion = $em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        try {
            if (!self::$construit) {
                $outil = new SchemaTool($em);
                $metadonnees = $em->getMetadataFactory()->getAllMetadata();
                $outil->dropSchema($metadonnees);
                $outil->createSchema($metadonnees);
                self::$construit = true;

                return;
            }

            foreach ($connexion->createSchemaManager()->listTableNames() as $table) {
                if (\in_array($table, self::HORS_DONNEES, true) || str_ends_with($table, '_seq')) {
                    continue;
                }

                $connexion->executeStatement('TRUNCATE TABLE ' . $connexion->quoteIdentifier($table));
            }
        } finally {
            // `finally` et pas la ligne suivante : si le vidage échoue, laisser les contrôles coupés
            // ferait passer les tests suivants sur une base sans intégrité référentielle — et un test
            // de cloisonnement qui passe sur une base sans clés étrangères ne prouve rien.
            $connexion->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Oublier que le schéma existe — pour un test qui aurait besoin de le reconstruire vraiment.
     *
     * Aucun n'en a besoin aujourd'hui. La méthode existe pour que le jour où l'un en aura besoin, la
     * réponse ne soit pas « on remet `dropSchema` dans un `setUp` » — c'est-à-dire pour que le coût
     * ne revienne pas par la porte de derrière.
     */
    public static function oublier(): void
    {
        self::$construit = false;
    }
}
