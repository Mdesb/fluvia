<?php

declare(strict_types=1);

namespace App\Tests\Membership\Unit;

use App\Membership\Doctrine\MembershipScopeExtension;
use App\Membership\Entity\Membership;
use App\Membership\Enum\MembershipPeriodicity;
use App\Membership\Enum\MembershipStatus;
use App\Membership\MembershipModule;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;

/**
 * Le socle du module `Membership` (lot 0) — ce qu'un lot **sans comportement** peut honnêtement
 * affirmer.
 *
 * ⚠ CE FICHIER EXISTE SURTOUT POUR `testLExtensionDePerimetreNommeLEntite`. Tous les garde-fous qui
 * surveillent le cloisonnement — n°5 (couverture de périmètre), n°35 (entité rattachable hors
 * liste) — ne mordent que sur les entités portant `#[ApiResource]`. `Membership` n'est pas exposée
 * au lot 0 : **aucun garde-fou ne vérifie donc aujourd'hui qu'elle est cloisonnable**. Ce test est
 * le seul filet jusqu'au lot 3, et il tombera si quelqu'un retire l'entrée de l'extension.
 *
 * Découverte par réflexion, sans démarrer le noyau : le test reste unitaire, rapide, et exécutable
 * sans base de données — même choix que `ManifestCatalogueTest`.
 */
final class MembershipSocleTest extends TestCase
{
    public function testLeManifesteEstConstructibleSansArgument(): void
    {
        $constructeur = (new \ReflectionClass(MembershipModule::class))->getConstructor();

        self::assertSame(0, $constructeur?->getNumberOfRequiredParameters() ?? 0);
        self::assertSame('membership', (new MembershipModule())->id());
    }

    /**
     * ⚠ `null` N'EST PAS UN OUBLI, C'EST LA DÉCISION D-2 DU PLAN.
     *
     * Il désigne un **service transverse** : piscine, padel, patinoire, musée et sport vendent tous
     * des abonnements, en faire une capacité à cocher créerait une porte fermée là où il n'en faut
     * pas. Même raisonnement que `GroupModule`.
     *
     * Ce test échouera le jour où quelqu'un posera une capacité — ce qui est exactement le rappel
     * voulu : ce jour-là, il faudra aussi un `case` dans `CapaciteCode` et un descripteur dans
     * `CatalogueCapacites`, sinon le module devient définitivement inaccessible sans aucune erreur.
     */
    public function testLeModuleEstTransverseEtNePorteDoncAucuneCapacite(): void
    {
        self::assertNull((new MembershipModule())->capability());
    }

    public function testLesPermissionsRespectentLeFormatModuleAction(): void
    {
        $permissions = (new MembershipModule())->permissions();

        // Une quantité AVANT la boucle : sans elle, ce test passerait au vert sur une liste vide,
        // en n'ayant rien vérifié tout en l'affirmant.
        self::assertCount(2, $permissions, 'Le manifeste doit déclarer ses deux permissions.');

        foreach ($permissions as $permission) {
            self::assertMatchesRegularExpression(
                '/^membership\.[a-z_]+$/',
                $permission,
                sprintf('Permission « %s » hors format module.action, ou hors du module (D5).', $permission),
            );
        }
    }

    public function testAucuneDependanceNiEvenementTantQueLeSocleNeFaitRien(): void
    {
        $manifeste = new MembershipModule();

        // RG-PLAT-07 : le registre refuse de démarrer si une dépendance nomme un module inconnu.
        // `App\Crm`, `App\Offre` et `App\Sepa` n'ont pas de manifeste — le lien vit dans le typage.
        self::assertSame([], $manifeste->dependencies());
        // RG-PLAT-06 : catalogue d'abord, déclaration ensuite.
        self::assertSame([], $manifeste->eventsEmitted());
        self::assertSame([], $manifeste->eventsConsumed());
    }

    public function testLEntiteEstMappeeSurLaTableMembership(): void
    {
        $table = (new \ReflectionClass(Membership::class))->getAttributes(ORM\Table::class);

        self::assertCount(1, $table, 'L\'entité doit déclarer sa table explicitement.');
        self::assertSame('membership', $table[0]->newInstance()->name);
    }

    /**
     * ⚠ LE TEST QUI JUSTIFIE CE FICHIER. Sans exposition d'API, aucun garde-fou ne vérifie que
     * l'entité est couverte par une extension de cloisonnement. Une entité hors du filtre ne
     * produit AUCUNE erreur Doctrine : elle rend simplement les lignes de tous les établissements.
     */
    public function testLExtensionDePerimetreNommeLEntite(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(MembershipScopeExtension::class))->getFileName(),
        );
        self::assertIsString($source, 'Source de l\'extension de périmètre illisible.');

        self::assertStringContainsString(
            'Membership::class => []',
            $source,
            'L\'entité doit figurer dans la table de cloisonnement, sinon elle sort du filtre en silence.',
        );
    }

    /**
     * ⚠ LA PROPRIÉTÉ DE CLOISONNEMENT DOIT S'APPELER `etablissement`, EN FRANÇAIS.
     *
     * Seule entorse à D5 du module, et elle est imposée : les extensions écrivent
     * `IDENTITY(%s.etablissement)` EN DUR. La renommer `establishment` ferait sortir l'entité du
     * filtre sans aucune erreur, jusqu'au jour où un exploitant lit les données d'un autre. Le
     * garde-fou n°28 refuse cette combinaison ; ce test la refuse aussi, en amont.
     */
    public function testLaRelationDeCloisonnementSAppelleEtablissement(): void
    {
        $classe = new \ReflectionClass(Membership::class);

        self::assertTrue(
            $classe->hasProperty('etablissement'),
            'La relation de cloisonnement doit se nommer « etablissement » : les extensions filtrent ce nom en dur.',
        );

        $relation = $classe->getProperty('etablissement')->getAttributes(ORM\ManyToOne::class);
        self::assertCount(1, $relation);
        self::assertSame(Etablissement::class, $relation[0]->newInstance()->targetEntity);
    }

    /**
     * Les valeurs persistées sont en anglais (D5), à la différence de celles de Sport. La table de
     * correspondance vit dans les docblocks des deux énumérations ; elle sera appliquée par la
     * migration de données du lot 1. Ce test fige les valeurs pour qu'elles ne dérivent pas d'ici là.
     */
    public function testLesValeursPersisteesSontEnAnglais(): void
    {
        self::assertSame(
            ['monthly', 'weekly', 'yearly'],
            array_column(MembershipPeriodicity::cases(), 'value'),
        );
        self::assertSame(
            ['active', 'paused', 'unpaid', 'terminated', 'expired'],
            array_column(MembershipStatus::cases(), 'value'),
        );
    }
}
