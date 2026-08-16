<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Reporting\Entity\Indicateur;
use App\Reporting\Entity\Mesure;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Enum\StatutCompletude;
use App\Tests\Reporting\ReportingApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `reporting:agreger` — RG-M7-02/03 (source unique, agrégation ascendante), RG-M7-04/CA-6 (FMI ≠
 * fréquentation cumulée), RG-REPORT-09/CA-9 (consolidation multi-régime), RG-REPORT-11/CA-10
 * (complétude), idempotence de `cleAgregation`.
 */
final class AgregationTest extends ReportingApiTestCase
{
    public function testCaSiteEstCalculeDepuisLesVentesEncaissees(): void
    {
        [$client] = $this->authAdmin();
        static::getContainer();
        $this->agreger();

        $mesure = $this->mesureEtablissement('CA', L11Fixtures::SITE_A1_NOM);
        self::assertSame(L11Fixtures::CA_A1, $mesure->getValeur());
    }

    public function testAgregationIdempotenteNeDupliquePasLaMesure(): void
    {
        $this->agreger();
        $premiere = $this->mesureEtablissement('CA', L11Fixtures::SITE_A1_NOM);
        $premiereId = (string) $premiere->getId();

        $this->agreger();
        $seconde = $this->mesureEtablissement('CA', L11Fixtures::SITE_A1_NOM);

        self::assertSame($premiereId, (string) $seconde->getId(), 'Le upsert doit mettre à jour la même ligne, jamais dupliquer (cleAgregation unique).');
        self::assertSame(L11Fixtures::CA_A1, $seconde->getValeur());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nombre = (int) $em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Mesure::class, 'm')
            ->where('m.indicateur = :ind')
            ->setParameter('ind', $this->indicateur('CA')->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
        // 1 ligne par établissement (A1,A2,B1) + 1 région A + 1 région B + 1 groupe = 6.
        self::assertSame(6, $nombre);
    }

    public function testAgregationAscendanteRegionEstLaSommeDesSites(): void
    {
        $this->agreger();

        $mesureRegionA = $this->mesureRegion('CA', L11Fixtures::REGION_A_NOM);
        self::assertSame('200.00', $mesureRegionA->getValeur(), 'CA région A = CA A1 (120) + CA A2 (80).');

        $mesureGroupe = $this->mesureGroupe('CA');
        self::assertSame('240.00', $mesureGroupe->getValeur(), 'CA groupe = 120 + 80 + 40.');
    }

    public function testFmiMaxEtFrequentationCumuleeSontDeuxIndicateursDistinctsEtJamaisFusionnes(): void
    {
        $this->agreger();

        $fmi = $this->mesureEtablissement('FMI_MAX', L11Fixtures::SITE_A1_NOM);
        $frequentation = $this->mesureEtablissement('FREQUENTATION_CUMULEE', L11Fixtures::SITE_A1_NOM);

        self::assertNotSame($fmi->getIndicateur()?->getCode(), $frequentation->getIndicateur()?->getCode());
        self::assertSame('10.00', $fmi->getValeur(), 'FMI = jauge courante (10), pas le nombre de passages.');
        self::assertSame('3.00', $frequentation->getValeur(), 'Fréquentation cumulée = COUNT(Passage entrée validé) = 3.');
    }

    public function testFmiAgregeeAuDelaDuSiteExposeDeuxIndicateursDistinctsAvecLibellesExplicites(): void
    {
        $this->agreger();

        $somme = $this->mesureRegion('FMI_MAX_SOMME_SITES', L11Fixtures::REGION_A_NOM);
        $critique = $this->mesureRegion('FMI_MAX_SITE_CRITIQUE', L11Fixtures::REGION_A_NOM);

        self::assertSame('35.00', $somme->getValeur(), 'Somme des FMI max des sites A1(10) + A2(25).');
        self::assertSame('25.00', $critique->getValeur(), 'FMI max — site le plus critique = max(10,25) = 25 (A2).');
        self::assertStringContainsStringIgnoringCase('somme des fmi max', (string) $somme->getIndicateur()?->getLibelle());
        self::assertStringContainsStringIgnoringCase('site le plus critique', (string) $critique->getIndicateur()?->getLibelle());
        // Aucun indicateur nommé simplement « FMI » agrégé au-delà du site (CA-6).
        self::assertNotSame('FMI_MAX', $somme->getIndicateur()?->getCode());
        self::assertNotSame('FMI_MAX', $critique->getIndicateur()?->getCode());
    }

    public function testConsolidationMultiRegimePorteLIndicateurDeComparabilite(): void
    {
        $this->agreger();

        $mesureRegionA = $this->mesureRegion('CA', L11Fixtures::REGION_A_NOM);
        self::assertTrue($mesureRegionA->isComparabiliteRegime(), 'Région A mélange régie (A1) et DSP (A2) — RG-REPORT-09.');
        self::assertSame('mixte', $mesureRegionA->getRegimeExploitant()?->value);

        $mesureRegionB = $this->mesureRegion('CA', L11Fixtures::REGION_B_NOM);
        self::assertFalse($mesureRegionB->isComparabiliteRegime(), 'Région B (B1 seul, régie) : pas de mélange de régime.');
    }

    public function testCompletudeMarqueLaMesureRegionalePartielleSansBloquerLaConsolidation(): void
    {
        $this->agreger();

        $mesureA2 = $this->mesureEtablissement('CA', L11Fixtures::SITE_A2_NOM);
        self::assertSame(StatutCompletude::Partiel, $mesureA2->getStatutCompletude(), 'A2 a un contrôleur hors-ligne (RG-REPORT-11).');

        $mesureRegionA = $this->mesureRegion('CA', L11Fixtures::REGION_A_NOM);
        self::assertSame(StatutCompletude::Partiel, $mesureRegionA->getStatutCompletude(), 'Partiel dès qu\'une mesure fille est partielle (RG-M7-08).');
        self::assertNotNull($mesureRegionA->getSitesManquants());
        self::assertContains($this->idEtablissement(L11Fixtures::SITE_A2_NOM), $mesureRegionA->getSitesManquants() ?? []);

        // La consolidation N'EST PAS bloquée : la valeur reste calculée (CA-10).
        self::assertSame('200.00', $mesureRegionA->getValeur());
    }

    public function testFuseauReferenceEtHorodatageUtc(): void
    {
        $avant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->agreger();
        $apres = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $mesure = $this->mesureEtablissement('CA', L11Fixtures::SITE_A1_NOM);

        // RG-REPORT-10 : horodatage toujours en UTC en base, fuseau porté séparément pour l'affichage.
        self::assertSame('Europe/Paris', $mesure->getFuseauReference());
        self::assertGreaterThanOrEqual($avant->getTimestamp(), $mesure->getGenereLe()->getTimestamp());
        self::assertLessThanOrEqual($apres->getTimestamp(), $mesure->getGenereLe()->getTimestamp());
    }

    private function mesureEtablissement(string $code, string $etablissementNom): Mesure
    {
        return $this->mesure($code, NiveauEntite::Etablissement, $this->idEtablissement($etablissementNom));
    }

    private function mesureRegion(string $code, string $regionNom): Mesure
    {
        return $this->mesure($code, NiveauEntite::Region, $this->idRegion($regionNom));
    }

    private function mesureGroupe(string $code): Mesure
    {
        return $this->mesure($code, NiveauEntite::Groupe, $this->idGroupe());
    }

    private function mesure(string $code, NiveauEntite $niveau, string $entiteId): Mesure
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $indicateur = $this->indicateur($code);

        $cle = Mesure::calculerCleAgregation($code, $niveau->value, $entiteId, null, null, null, null, (new \DateTimeImmutable('today'))->format('Y-m-d'), (new \DateTimeImmutable('today'))->format('Y-m-d'), 'jour');

        $mesure = $em->getRepository(Mesure::class)->findOneBy(['cleAgregation' => $cle]);
        self::assertInstanceOf(Mesure::class, $mesure, sprintf('Aucune Mesure(%s, %s, %s).', $code, $niveau->value, $entiteId));
        self::assertSame($indicateur->getId()->toRfc4122(), $mesure->getIndicateur()?->getId()->toRfc4122());

        return $mesure;
    }

    private function indicateur(string $code): Indicateur
    {
        return $this->entite(Indicateur::class, ['code' => $code]);
    }
}
