<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Charger les données de démonstration **deux fois de suite** ne doit ni échouer, ni rien dupliquer.
 *
 * **Pourquoi ce test existe.** Le 24/08, régénérer les données de démonstration de la préproduction a
 * échoué en cours de route, après avoir tronqué la table des rattachements droits-rôles : trente-quatre
 * rôles se sont retrouvés à zéro droit, et Maxime n'a plus pu tester qu'avec son propre compte. En
 * cherchant la cause, j'ai trouvé que **quatorze fixtures** créaient des rôles sans vérifier s'ils
 * existaient — `Support` en créait huit sans une seule garde.
 *
 * **Pourquoi rien ne l'avait vu, et c'est le cœur du problème.** Le harnais recrée le schéma depuis les
 * entités à chaque classe de test : les fixtures partent donc **toujours d'une base vide**, et elles
 * sont chargées sélectivement, jamais toutes ensemble. Le seul geste qui révèle le défaut — charger
 * deux fois — n'était fait nulle part. Quatorze fixtures ont pu vivre cassées sans qu'une ligne ne
 * rougisse.
 *
 * **Proposé par `claude-D`**, qui l'a d'abord fait à la main pour vérifier sa propre correction plutôt
 * que de se fier à sa suite. Sa remarque vaut plus que le test : *« la suite passait déjà avant ma
 * correction — c'est tout le problème. Ce qui vérifie, c'est le double chargement. »* J'avais écrit
 * dans son ordre que la correction « se vérifie en relançant ta suite ». C'était faux, et elle me l'a dit.
 *
 * **⚠ POURQUOI IL COMPTE LES LIGNES, ET PAS SEULEMENT L'ABSENCE D'ERREUR.** Deuxième objection de
 * `claude-D`, et elle visait juste : *« ne lève pas » n'est pas « idempotent »*. Une fixture qui écrit
 * dans une table **sans contrainte d'unicité** passera la seconde fois — en doublant la donnée,
 * silencieusement. Le test serait vert et les données de démonstration fausses.
 *
 * Ce n'est pas un cas théorique : `claude-G` l'a trouvé sur `Affectation`, qui ne porte aucune unicité
 * en base. Un second chargement n'y échoue pas, il **empile des doublons** — et les droits effectifs
 * d'un utilisateur se calculent en parcourant ses affectations. Le doublon n'est pas cosmétique, c'est
 * un calcul de droits sur des données fausses.
 *
 * **« Le nombre de lignes ne change pas » est la définition de l'idempotence ; « ça ne plante pas »
 * n'en est que le symptôme le plus bruyant.** Le premier test ne voyait que le second, c'est-à-dire
 * uniquement les tables assez bien contraintes pour crier.
 *
 * **Il passe par la commande et non par le chargeur**, parce que c'est le geste réel : un exploitant
 * lance `doctrine:fixtures:load`, il n'appelle pas un exécuteur.
 */
final class FixturesIdempotentesTest extends KernelTestCase
{
    public function testChargerLesFixturesDeuxFoisNeChangeRien(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        // Premier passage : purge puis création. C'est ce que fait le harnais, et c'est le seul qui ait
        // jamais fonctionné.
        $premier = new BufferedOutput();
        $code = $application->run(
            new ArrayInput(['command' => 'doctrine:fixtures:load', '--no-interaction' => true]),
            $premier,
        );
        self::assertSame(0, $code, "Le premier chargement échoue :\n" . $premier->fetch());

        $avant = $this->compterLesLignes($connexion);
        self::assertNotEmpty($avant, 'Aucune table peuplée : le test ne prouverait rien.');

        // Second passage SANS purge — c'est là tout l'intérêt.
        $second = new BufferedOutput();
        $code = $application->run(
            new ArrayInput([
                'command' => 'doctrine:fixtures:load',
                '--no-interaction' => true,
                '--append' => true,
            ]),
            $second,
        );

        self::assertSame(
            0,
            $code,
            "Une fixture n'est pas idempotente : elle crée un objet à contrainte d'unicité sans "
            . "vérifier s'il existe. Cherche avant de créer — voir le patron `roleNomme()` dans "
            . "`SocleFixtures`.\n\n" . $second->fetch(),
        );

        // Le contrôle qui manquait : les tables sans unicité ne crient pas, elles doublent.
        $apres = $this->compterLesLignes($connexion);

        $ecarts = [];
        foreach ($avant as $table => $compte) {
            $nouveau = $apres[$table] ?? 0;

            if ($nouveau !== $compte) {
                $ecarts[] = sprintf('%s : %d → %d (+%d)', $table, $compte, $nouveau, $nouveau - $compte);
            }
        }

        self::assertSame(
            [],
            $ecarts,
            "Une fixture a dupliqué des lignes au second chargement, SANS lever d'erreur : la table "
            . "concernée ne porte pas de contrainte d'unicité, donc rien ne l'a arrêtée.\n\n"
            . "C'est le cas le plus dangereux, parce qu'il est silencieux — `Affectation` en est "
            . "l'exemple : un utilisateur dont les affectations sont doublées voit ses droits "
            . "calculés sur des données fausses.\n\n"
            . "Cherche avant de créer, sur les critères qui font l'identité métier de la ligne "
            . "— voir `affectationUnique()` dans `SocleFixtures`.\n\n"
            . implode("\n", $ecarts),
        );
    }

    /**
     * @return array<string, int> le nombre de lignes par table, tables vides exclues
     */
    private function compterLesLignes(Connection $connexion): array
    {
        $comptes = [];

        foreach ($connexion->createSchemaManager()->listTableNames() as $table) {
            // L'historique des migrations n'est pas une donnée de démonstration : il bouge pour des
            // raisons qui n'ont rien à voir avec l'idempotence des fixtures.
            if ($table === 'doctrine_migration_versions') {
                continue;
            }

            $compte = (int) $connexion->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s', $connexion->quoteIdentifier($table)),
            );

            if ($compte > 0) {
                $comptes[$table] = $compte;
            }
        }

        return $comptes;
    }
}
