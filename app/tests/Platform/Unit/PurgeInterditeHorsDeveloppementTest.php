<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\DataFixtures\PurgeurInterditHorsDeveloppement;
use App\Platform\DataFixtures\PurgeurRefusant;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `doctrine:fixtures:load` ne peut pas vider une base hors `dev` et `test` (T6).
 *
 * **Ce que ce test protège, et pourquoi il ne peut pas être un test d'API.** Charger la démonstration
 * en préproduction demande que `DoctrineFixturesBundle` y soit actif — donc que sa commande, qui
 * **purge par défaut**, y soit disponible. C'est la commande qui a mis les trente-quatre rôles de la
 * préproduction à zéro droit le 24/08. Le garde retire cette possibilité ; ce test empêche qu'on la
 * rende sans le vouloir.
 *
 * On teste la fabrique directement plutôt que par la commande : en environnement de test, le garde
 * rend — volontairement — le vrai purgeur, puisque le harnais en dépend. Lui passer « prod » en
 * argument est le seul moyen d'observer le comportement de production depuis la suite.
 *
 * **Trois pièges rencontrés en écrivant ce garde, chacun couvert par une assertion ci-dessous :**
 *
 * 1. le refus doit venir de `purge()`, **pas** de la fabrique : la commande construit un purgeur même
 *    en `--append`, et refuser trop tôt casserait le chargement additif — le mode dont la
 *    préproduction a besoin ;
 * 2. le purgeur doit implémenter `ORMPurgerInterface` : `ORMExecutor` type son argument dessus, et un
 *    simple `PurgerInterface` fait échouer la commande sur une erreur de type illisible ;
 * 3. `dev` et `test` doivent continuer à recevoir le vrai purgeur, sans quoi la suite entière tombe.
 */
final class PurgeInterditeHorsDeveloppementTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function environnementsDeDeveloppement(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }

    /** @return iterable<string, array{string}> */
    public static function environnementsProteges(): iterable
    {
        yield 'prod' => ['prod'];
        yield 'preprod' => ['preprod'];
    }

    #[DataProvider('environnementsDeDeveloppement')]
    public function testLeVraiPurgeurEstRenduEnDeveloppement(string $environnement): void
    {
        $purgeur = (new PurgeurInterditHorsDeveloppement($environnement))
            ->createForEntityManager(null, $this->createStub(EntityManagerInterface::class));

        self::assertInstanceOf(ORMPurger::class, $purgeur, 'Le harnais de test recrée le schéma à chaque classe et dépend de ce purgeur.');
    }

    #[DataProvider('environnementsProteges')]
    public function testAilleursLaFabriqueRendUnPurgeurQuiRefuse(string $environnement): void
    {
        $purgeur = (new PurgeurInterditHorsDeveloppement($environnement))
            ->createForEntityManager(null, $this->createStub(EntityManagerInterface::class));

        // Piège n°1 : la fabrique elle-même ne doit pas lever. La commande la sollicite aussi en
        // `--append`, où aucune purge n'aura lieu.
        self::assertInstanceOf(PurgeurRefusant::class, $purgeur);
    }

    public function testLaPurgeLeveEtDitQuoiFaireALaPlace(): void
    {
        $purgeur = (new PurgeurInterditHorsDeveloppement('prod'))
            ->createForEntityManager(null, $this->createStub(EntityManagerInterface::class));

        try {
            $purgeur->purge();
            self::fail('La purge aurait dû être refusée en environnement « prod ».');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('prod', $e->getMessage());
            self::assertStringContainsString('24/08', $e->getMessage(), 'Le message doit rappeler ce que la purge a déjà coûté.');
            self::assertStringContainsString('app:demo:charger', $e->getMessage(), 'Un refus qui ne dit pas quoi faire à la place fait chercher le contournement.');
        }
    }
}
