<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Integration;

use App\Boutique\Paiement\OnlinePaymentProfileResolver;
use App\Compta\Entity\ProfilExploitant;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Le profil qui encaisse pour un établissement : une seule requête ciblée (plus de `findAll`), choix
 * déterministe, et un 422 quand rien ne couvre.
 */
final class OnlinePaymentProfileResolverTest extends BoutiqueApiTestCase
{
    private function resolver(): OnlinePaymentProfileResolver
    {
        return new OnlinePaymentProfileResolver($this->em());
    }

    private function fixtureProfile(): ProfilExploitant
    {
        // Le profil de B porte le même SIREN dans les fixtures Boutique : on désigne celui de A par son principal.
        return $this->profileOf($this->site(SocleFixtures::ETAB_A_NOM));
    }

    private function site(string $name): Etablissement
    {
        return $this->entite(Etablissement::class, ['nom' => $name]);
    }

    /** Le profil dont l'établissement donné est le principal (les fixtures Boutique en posent un pour B). */
    private function profileOf(Etablissement $principal): ProfilExploitant
    {
        return $this->entite(ProfilExploitant::class, ['etablissementPrincipal' => $principal]);
    }

    /** Un établissement que AUCUN profil ne couvre (le tenant éditeur des fixtures). */
    private function uncoveredSite(): Etablissement
    {
        $site = $this->site(SocleFixtures::ETAB_EDITEUR_NOM);
        self::assertSame([], $this->em()->getRepository(ProfilExploitant::class)->findBy(['etablissementPrincipal' => $site]));

        return $site;
    }

    public function testThePrincipalEstablishmentIsRecognised(): void
    {
        $siteA = $this->site(SocleFixtures::ETAB_A_NOM);

        $profile = $this->resolver()->forEstablishment($siteA);

        self::assertSame((string) $siteA->getId(), (string) $profile->getEtablissementPrincipal()?->getId());
    }

    public function testTwoEstablishmentsGetTheirOwnProfile(): void
    {
        $siteB = $this->site(SocleFixtures::ETAB_B_NOM);

        $forA = $this->resolver()->forEstablishment($this->site(SocleFixtures::ETAB_A_NOM));
        $forB = $this->resolver()->forEstablishment($siteB);

        self::assertNotSame((string) $forA->getId(), (string) $forB->getId());
        self::assertSame((string) $this->profileOf($siteB)->getId(), (string) $forB->getId());
    }

    public function testAnAttachedEstablishmentIsRecognised(): void
    {
        $site = $this->uncoveredSite();
        $profileA = $this->fixtureProfile();
        $profileA->addEtablissementRattache($site);
        $this->em()->flush();

        self::assertSame((string) $profileA->getId(), (string) $this->resolver()->forEstablishment($site)->getId());
    }

    public function testThePrincipalProfileWinsOverAnAttachedOne(): void
    {
        $siteB = $this->site(SocleFixtures::ETAB_B_NOM);
        $profileB = $this->profileOf($siteB);
        // Le profil de A couvre aussi B (rattaché) : B reste desservi par son propre profil, jamais au hasard.
        $this->fixtureProfile()->addEtablissementRattache($siteB);
        $this->em()->flush();

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame((string) $profileB->getId(), (string) $this->resolver()->forEstablishment($siteB)->getId());
        }
    }

    public function testNoProfileOrNoEstablishmentIsA422(): void
    {
        foreach ([$this->uncoveredSite(), null] as $establishment) {
            try {
                $this->resolver()->forEstablishment($establishment);
                self::fail('Un 422 était attendu.');
            } catch (UnprocessableEntityHttpException $e) {
                self::assertStringContainsString('Aucun profil exploitant', $e->getMessage());
            }
        }

        // Témoin positif : un établissement couvert, lui, se résout.
        self::assertInstanceOf(ProfilExploitant::class, $this->resolver()->forEstablishment($this->site(SocleFixtures::ETAB_A_NOM)));
    }

    public function testOneSingleSelectAndNoFullScan(): void
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $this->em()->clear();
        $siteA = $this->site(SocleFixtures::ETAB_A_NOM);
        $holder->reset();

        $this->resolver()->forEstablishment($siteA);

        $queries = array_merge(...array_values($holder->getData()));
        $selects = array_values(array_filter(array_column($queries, 'sql'), static fn (string $sql): bool => str_starts_with(ltrim($sql), 'SELECT')));
        // Témoin positif : le compteur compte (au moins la requête du résolveur).
        self::assertGreaterThanOrEqual(1, \count($selects));
        $onProfiles = array_values(array_filter($selects, static fn (string $sql): bool => str_contains($sql, 'compta_profil_exploitant')));
        self::assertCount(1, $onProfiles, 'Une seule requête sur les profils, et elle est ciblée.');
        self::assertStringContainsString('LIMIT 1', $onProfiles[0]);
        self::assertStringContainsString('WHERE', $onProfiles[0]);
    }
}
