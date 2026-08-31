<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réapplique, en test, le DDL que le mapping Doctrine ne sait pas exprimer.
 *
 * **Le problème.** Chaque classe de base de test reconstruit la base avec `SchemaTool` à partir des
 * métadonnées ORM, avant chaque test. Or certaines structures n'existent que dans les migrations
 * parce que Doctrine n'a pas d'attribut pour elles — au premier rang, l'index `FULLTEXT` du module
 * Support (migration `Version20260817205431`). Résultat : la fonctionnalité marche en production et
 * échoue en test, avec un message (« Can't find FULLTEXT index matching the column list ») qui
 * ressemble à une régression du code alors que c'est le harnais qui ment. C'est l'écart le plus
 * coûteux qui soit, puisqu'il rend un module intestable sans rien signaler.
 *
 * **L'usage.** Appeler {@see self::appliquer()} juste après `createSchema()`, dans toute classe de
 * base qui reconstruit le schéma. Le bloc de reconstruction est aujourd'hui recopié dans huit classes
 * de base ; ce point d'entrée unique évite au moins que le rattrapage le soit aussi.
 *
 * **La règle.** Toute DDL ajoutée ici doit être le miroir strict d'une migration. Ce n'est pas
 * l'endroit où la base de test diverge de la production — c'est celui où on l'en empêche.
 */
final class DdlHorsMapping
{
    /**
     * @var array<string, array{index: string, ddl: string}> table concernée => index posé et son DDL
     */
    private const DDL = [
        // Recherche plein-texte du module Support (US-SUP-01/07, RG-SUP-06) : InnoDB gère FULLTEXT
        // nativement, mais l'ORM ne peut pas le déclarer.
        'support_article_aide' => [
            'index' => 'support_ft_article_recherche',
            'ddl' => 'ALTER TABLE support_article_aide ADD FULLTEXT INDEX support_ft_article_recherche (recherche_texte)',
        ],
    ];

    public static function appliquer(EntityManagerInterface $em): void
    {
        $connexion = $em->getConnection();
        $gestionnaire = $connexion->createSchemaManager();

        foreach (self::DDL as $table => $objet) {
            // Une table peut être absente d'un périmètre réduit : on passe, plutôt que d'échouer.
            // En revanche, si la table existe, une erreur de DDL doit remonter — pas d'échec masqué.
            if (!$gestionnaire->tablesExist([$table])) {
                continue;
            }

            // ⚠ ON NE REPOSE PAS UN INDEX DÉJÀ LÀ.
            //
            // Ce rattrapage a longtemps pu être aveugle : le schéma était détruit et reconstruit
            // avant CHAQUE test, donc l'index n'existait jamais au moment de l'appel. Le jour où le
            // harnais a cessé de tout reconstruire — 27/08, pour ramener la suite de six heures à
            // une heure et demie — les vingt-sept tests du module Support sont tombés d'un coup sur
            // « Duplicate key name ».
            //
            // Le DDL n'était pas faux : il DÉPENDAIT d'un effet de bord du harnais. C'est la même
            // faute que les fixtures qui créaient sans chercher, et elle se corrige pareil.
            //
            // > Tout ce qui se rejoue doit être idempotent, y compris ce qui n'a jamais été rejoué.
            if (self::indexExiste($connexion, $table, $objet['index'])) {
                continue;
            }

            $connexion->executeStatement($objet['ddl']);
        }
    }

    private static function indexExiste(Connection $connexion, string $table, string $index): bool
    {
        return (bool) $connexion->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index],
        );
    }
}
