<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\DataFixtures\SocleFixtures;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;
use App\Platform\Module\ModuleAccess;
use App\Platform\Module\ModuleManifest;
use App\Platform\Module\ModuleRegistry;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CA-5 / RG-PLAT-08 — activation à deux niveaux, câblée sur l'activation existante.
 *
 * Test d'intégration et non unitaire : l'intérêt de `ModuleAccess` est précisément qu'il ne recrée pas
 * de stockage et délègue à `Fonctionnalites`. Le vérifier contre un double ne prouverait que la
 * délégation, pas qu'elle répond juste sur la vraie table.
 *
 * Les codes utilisés (`controle_acces`, `acces_nocturne`) sont deux capacités réelles du catalogue :
 * `Fonctionnalites::definir()` refuse tout code inconnu, et un test qui contournerait cette validation
 * testerait un chemin que la production n'emprunte jamais.
 */
final class ModuleAccessTest extends SocleApiTestCase
{
    private const CAPACITE_MODULE = 'controle_acces';
    private const FEATURE = 'acces_nocturne';

    public function testCa5CapaciteInactiveRepondFauxSansErreur(): void
    {
        $acces = $this->moduleAccess();

        self::assertFalse($acces->hasModule($this->etablissementA(), self::CAPACITE_MODULE));
    }

    public function testModuleActifQuandLaCapaciteEstSouscrite(): void
    {
        $etablissement = $this->etablissementA();
        $this->activer($etablissement, self::CAPACITE_MODULE, true);

        self::assertTrue($this->moduleAccess()->hasModule($etablissement, self::CAPACITE_MODULE));
    }

    /** Le cœur de RG-PLAT-08 : module actif n'implique pas toutes ses features actives. */
    public function testFeatureIndependanteDuModule(): void
    {
        $etablissement = $this->etablissementA();
        $this->activer($etablissement, self::CAPACITE_MODULE, true);
        $this->activer($etablissement, self::FEATURE, false);

        $acces = $this->moduleAccess();

        self::assertTrue($acces->hasModule($etablissement, self::CAPACITE_MODULE));
        self::assertFalse($acces->hasFeature($etablissement, self::FEATURE));

        $this->activer($etablissement, self::FEATURE, true);
        self::assertTrue($this->moduleAccess()->hasFeature($etablissement, self::FEATURE));
    }

    /** Le second niveau ne peut pas contourner le premier : feature ouverte, module éteint ⇒ non. */
    public function testFeatureRefuseeQuandLeModuleEstEteint(): void
    {
        $etablissement = $this->etablissementA();
        $this->activer($etablissement, self::CAPACITE_MODULE, false);
        $this->activer($etablissement, self::FEATURE, true);

        self::assertFalse($this->moduleAccess()->hasFeature($etablissement, self::FEATURE));
    }

    /** Échec fermé : une feature que personne ne déclare n'ouvre rien, même activée. */
    public function testFeatureInconnueDuRegistreRefusee(): void
    {
        $etablissement = $this->etablissementA();
        $this->activer($etablissement, self::CAPACITE_MODULE, true);
        $this->activer($etablissement, self::FEATURE, true);

        $registreVide = new ModuleRegistry([]);
        $acces = new ModuleAccess($registreVide, $this->fonctionnalites());

        self::assertFalse($acces->hasFeature($etablissement, self::FEATURE));
    }

    private function moduleAccess(): ModuleAccess
    {
        return new ModuleAccess(new ModuleRegistry([$this->manifesteDeTest()]), $this->fonctionnalites());
    }

    /** Manifeste minimal : le registre n'a pas besoin d'un vrai module pour répondre sur les features. */
    private function manifesteDeTest(): ModuleManifest
    {
        return new class implements ModuleManifest {
            public function id(): string
            {
                return 'access-control';
            }

            public function version(): string
            {
                return '0.1.0';
            }

            public function capability(): string
            {
                return ModuleAccessTest::capaciteModule();
            }

            /** @return list<string> */
            public function dependencies(): array
            {
                return [];
            }

            /** @return list<string> */
            public function permissions(): array
            {
                return [];
            }

            /** @return list<string> */
            public function eventsEmitted(): array
            {
                return [];
            }

            /** @return list<string> */
            public function eventsConsumed(): array
            {
                return [];
            }

            /** @return list<string> */
            public function features(): array
            {
                return [ModuleAccessTest::feature()];
            }

            /** @return list<string> */
            public function routes(): array
            {
                return [];
            }

            /** @return array<string, mixed> */
            public function settingsSchema(): array
            {
                return [];
            }
        };
    }

    public static function capaciteModule(): string
    {
        return self::CAPACITE_MODULE;
    }

    public static function feature(): string
    {
        return self::FEATURE;
    }

    private function activer(Etablissement $etablissement, string $code, bool $actif): void
    {
        $this->fonctionnalites()->definir($etablissement, $code, $actif, null);
    }

    private function fonctionnalites(): Fonctionnalites
    {
        $service = static::getContainer()->get(Fonctionnalites::class);
        self::assertInstanceOf(Fonctionnalites::class, $service);

        return $service;
    }

    private function etablissementA(): Etablissement
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        return $etablissement;
    }
}
