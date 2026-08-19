<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Event\EventName;
use App\Platform\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * **Le test qui rend « contract-first » (D2) exécutable.**
 *
 * Sans lui, `CONTRACT/catalogue-evenements.md` est un document que chacun est censé lire : les noms
 * dérivent, un module publie un fait que personne n'a inscrit au contrat, et le couplage réapparaît par
 * la bande. Ici, le contrat est **lu par la suite de tests** et confronté aux manifestes réels.
 *
 * Découverte par réflexion plutôt que par le conteneur : un manifeste est une déclaration constructible
 * sans argument (voir `ModuleManifest`), donc inutile de démarrer le noyau Symfony — le test reste
 * unitaire, rapide, et exécutable en CI sans base de données.
 *
 * ⚠ Le catalogue vit dans `COORDINATION/`, **hors** de la racine applicative `app/`. Le
 * `docker-compose.yml` du dépôt ne monte que `./app:/app` : dans ce contexte le fichier est invisible et
 * le test s'annonce comme non exécuté plutôt que de faire échouer la suite des autres instances. Exposer
 * la racine du dépôt au conteneur est un point d'infrastructure suivi en C4.
 */
final class ManifestCatalogueTest extends TestCase
{
    /** Ancres attendues dans le catalogue — si elles disparaissent, c'est le parsing ou le contrat qui a cassé. */
    private const ANCRES = ['payment.failed', 'invoice.overdue', 'supplier_invoice.recorded', 'booking.no_show'];

    private static function cheminCatalogue(): ?string
    {
        $candidats = [
            // exécution avec la racine du dépôt montée (cas CI et `docker run -v <repo>:/repo -w /repo/app`)
            __DIR__ . '/../../../../COORDINATION/CONTRACT/catalogue-evenements.md',
            // surcharge explicite, pour un agencement de répertoires différent
            getenv('CONTRACT_DIR') !== false ? getenv('CONTRACT_DIR') . '/catalogue-evenements.md' : null,
        ];

        foreach ($candidats as $candidat) {
            if (\is_string($candidat) && is_file($candidat)) {
                return $candidat;
            }
        }

        return null;
    }

    /**
     * Extrait les noms d'événements des tableaux Markdown : lignes `| \`domain.fact\` | … |`.
     *
     * @return list<string>
     */
    private static function evenementsDuCatalogue(string $chemin): array
    {
        $contenu = file_get_contents($chemin);
        self::assertIsString($contenu, 'Catalogue d\'événements illisible.');

        preg_match_all('/^\|\s*`([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)`\s*\|/m', $contenu, $correspondances);

        return array_values(array_unique($correspondances[1]));
    }

    /**
     * @return list<class-string<ModuleManifest>>
     */
    private static function classesDeManifeste(): array
    {
        $racine = __DIR__ . '/../../../src';
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        $classes = [];

        /** @var \SplFileInfo $fichier */
        foreach ($iterateur as $fichier) {
            if (!$fichier->isFile() || !str_ends_with($fichier->getFilename(), 'Module.php')) {
                continue;
            }

            $relatif = substr((string) $fichier->getRealPath(), \strlen((string) realpath($racine)) + 1);
            $classe = 'App\\' . str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relatif);

            if (!class_exists($classe)) {
                continue;
            }

            $reflexion = new \ReflectionClass($classe);
            if ($reflexion->isAbstract() || !$reflexion->implementsInterface(ModuleManifest::class)) {
                continue;
            }

            $classes[] = $classe;
        }

        sort($classes);

        return $classes;
    }

    public function testLeCatalogueEstLisibleEtBienForme(): void
    {
        $chemin = self::cheminCatalogue();

        if ($chemin === null) {
            self::markTestSkipped('CONTRACT/catalogue-evenements.md hors de portée : monter la racine du dépôt, ou définir CONTRACT_DIR.');
        }

        $evenements = self::evenementsDuCatalogue($chemin);

        self::assertNotEmpty($evenements, 'Aucun événement extrait : le format du catalogue a changé, le parsing est à revoir.');

        foreach (self::ANCRES as $ancre) {
            self::assertContains($ancre, $evenements, sprintf('Événement « %s » absent du catalogue.', $ancre));
        }

        foreach ($evenements as $evenement) {
            self::assertMatchesRegularExpression(
                EventName::PATTERN,
                $evenement,
                sprintf('Le catalogue déclare « %s », qui ne respecte pas « domain.fact_past_tense » (D5).', $evenement)
            );
        }
    }

    /**
     * RG-PLAT-06 — tout événement déclaré par un manifeste doit exister au contrat. C'est ce qui empêche
     * un module d'inventer un fait dans son coin : l'ajouter au catalogue est un acte visible de tous.
     */
    public function testLesEvenementsDeclaresFigurentAuCatalogue(): void
    {
        $chemin = self::cheminCatalogue();

        if ($chemin === null) {
            self::markTestSkipped('CONTRACT/catalogue-evenements.md hors de portée : monter la racine du dépôt, ou définir CONTRACT_DIR.');
        }

        $catalogue = self::evenementsDuCatalogue($chemin);
        $classes = self::classesDeManifeste();

        if ($classes === []) {
            // Aucun module n'implémente encore de manifeste (C5 pose la brique, les modules suivent).
            // On l'affirme explicitement : le jour où le premier manifeste arrive, ce test devient actif
            // sans qu'on ait à y penser.
            self::assertSame([], $classes);

            return;
        }

        foreach ($classes as $classe) {
            $manifest = new $classe();

            foreach ([...$manifest->eventsEmitted(), ...$manifest->eventsConsumed()] as $evenement) {
                self::assertContains(
                    $evenement,
                    $catalogue,
                    sprintf(
                        'Le module « %s » déclare l\'événement « %s », absent de CONTRACT/catalogue-evenements.md. Ajoutez-le au contrat (et signalez-le dans MESSAGES.md) avant de l\'utiliser.',
                        $manifest->id(),
                        $evenement
                    )
                );
            }
        }
    }

    /**
     * Un manifeste qui dépendrait de la base ou d'une requête ne pourrait être lu ni au démarrage ni en
     * ligne de commande — il cesserait d'être une déclaration pour devenir un service.
     */
    public function testUnManifesteEstConstructibleSansArgument(): void
    {
        foreach (self::classesDeManifeste() as $classe) {
            $constructeur = (new \ReflectionClass($classe))->getConstructor();

            self::assertSame(
                0,
                $constructeur?->getNumberOfRequiredParameters() ?? 0,
                sprintf('Le manifeste « %s » exige des arguments de construction : un manifeste est une déclaration, pas un service.', $classe)
            );
        }

        self::assertTrue(true, 'Aucun manifeste à vérifier pour l\'instant.');
    }

    public function testLesPermissionsDeclareesRespectentLeFormatModuleAction(): void
    {
        foreach (self::classesDeManifeste() as $classe) {
            $manifest = new $classe();

            foreach ($manifest->permissions() as $permission) {
                self::assertMatchesRegularExpression(
                    '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
                    $permission,
                    sprintf('Permission « %s » du module « %s » : attendu « module.action » en anglais (D5).', $permission, $manifest->id())
                );
            }
        }

        self::assertTrue(true, 'Aucun manifeste à vérifier pour l\'instant.');
    }
}
