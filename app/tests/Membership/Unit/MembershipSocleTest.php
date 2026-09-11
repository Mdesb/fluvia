<?php

declare(strict_types=1);

namespace App\Tests\Membership\Unit;

use ApiPlatform\Metadata\ApiResource;
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

    /**
     * ⚠ LA TABLE N'EST PAS `membership`, ET CE TEST EXISTE POUR QUE PERSONNE NE LA « CORRIGE ».
     *
     * L'entité a été déplacée de `App\Sport` sans que sa table bouge (arbitrage du 10/09,
     * « déplace sans renommer »). **Sept clés étrangères** pointent dessus : un renommage les
     * emmène toutes et impose un déploiement où le code et le schéma basculent au même instant.
     *
     * Ce test fige donc un nom qui a l'air incohérent avec sa classe. Il l'est, et c'est assumé :
     * le jour où l'on renommera vraiment, il faudra le changer ICI en même temps que la migration —
     * ce qui est précisément le rappel voulu.
     */
    public function testLaTableNaPasSuiviLeDeplacementDeLaClasse(): void
    {
        $table = (new \ReflectionClass(Membership::class))->getAttributes(ORM\Table::class);

        self::assertCount(1, $table, 'L\'entité doit déclarer sa table explicitement.');
        self::assertSame('sport_abonnement_fitness', $table[0]->newInstance()->name);
    }

    /**
     * ⚠ MÊME RAISON POUR LA RESSOURCE D'API. `shortName` décide de la route : la renommer ferait
     * répondre 404 à `/api/abonnement_fitnesses`, que le frontal appelle. Le déplacement de classe
     * ne doit RIEN changer de ce que voit un client.
     */
    public function testLaRouteDApiNaPasBouge(): void
    {
        $ressource = (new \ReflectionClass(Membership::class))->getAttributes(ApiResource::class);

        self::assertCount(1, $ressource);
        self::assertSame('AbonnementFitness', $ressource[0]->newInstance()->getShortName());
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
     * ⚠ LES VALEURS RESTENT EN FRANÇAIS, ET C'EST LA CONSÉQUENCE DIRECTE DE NE PAS AVOIR BOUGÉ LA
     * TABLE.
     *
     * Le lot 0 avait posé des valeurs anglaises sur une table neuve et vide. La table retenue est
     * l'ancienne, qui contient des codes français **déjà écrits**. Les traduire demanderait une
     * migration de données et ouvrirait une fenêtre où l'ancien code lirait des valeurs qu'il ne
     * connaît pas.
     *
     * Ce n'est pas une entorse inventée pour l'occasion : `MembershipStatus::Expired` porte déjà ce
     * raisonnement dans son propre docblock, écrit avant ce lot. D5 vise le vocabulaire réellement
     * neuf, pas les codes persistés.
     */
    public function testLesValeursPersisteesRestentCellesDeLaTable(): void
    {
        self::assertSame(
            ['mensuel', 'hebdomadaire', 'annuel'],
            array_column(MembershipPeriodicity::cases(), 'value'),
        );
        self::assertSame(
            ['actif', 'pause', 'impaye', 'resilie', 'echu'],
            array_column(MembershipStatus::cases(), 'value'),
        );
    }
}
