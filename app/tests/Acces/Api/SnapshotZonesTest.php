<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Enum\TypeDroitAcces;
use App\Organisation\Entity\Espace;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;

/**
 * Hors ligne, un droit n'ouvre que les portes de ses zones (D87), comme en ligne.
 *
 * Le contrôle en ligne (`ValidationPassageHandler`) applique `DroitAcces::ouvre()` aux espaces
 * desservis par le contrôleur ; le snapshot ne filtrait que par sous-réseau, si bien qu'un droit
 * limité à une zone ouvrait hors ligne toutes les portes de la borne.
 */
final class SnapshotZonesTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    // --- Zones autorisées (D87) -------------------------------------------------------------------

    /**
     * Hors ligne, un droit limité à une zone n'ouvre pas les portes d'une autre.
     *
     * Le contrôle en ligne (`ValidationPassageHandler`) applique `DroitAcces::ouvre()` aux espaces
     * desservis par le contrôleur ; le snapshot ne filtrait que par sous-réseau. Trois témoins
     * encadrent le cas : la zone de la borne (porte servie), un droit Personnel sans zone (exempté,
     * porte servie) et un droit Abonnement sans zone (D87 : aucune porte).
     */
    public function testSnapshotRespecteLesZonesAutorisees(): void
    {
        $porte = $this->idEquipement();

        [, [$zoneAutre]] = $this->createPairedRight(TypeDroitAcces::Abonnement, 1, null, [$this->creerAutreZone()]);
        [, [$zoneBorne]] = $this->createPairedRight(TypeDroitAcces::Abonnement);
        [, [$personnel]] = $this->createPairedRight(TypeDroitAcces::Personnel, 1, null, []);
        [, [$sansZone]] = $this->createPairedRight(TypeDroitAcces::Abonnement, 1, null, []);

        self::assertContains($porte, $this->fullSnapshotEntry($zoneBorne)['portesEligibles'], 'témoin positif : la zone de la borne ouvre sa porte');
        self::assertContains($porte, $this->fullSnapshotEntry($personnel)['portesEligibles'], 'témoin : un badge Personnel sans zone reste exempté (D87/D90)');
        self::assertNotContains($porte, $this->fullSnapshotEntry($zoneAutre)['portesEligibles'], 'Un droit limité à la zone Z ne doit pas ouvrir la porte d\'une borne de la zone Y.');
        self::assertNotContains($porte, $this->fullSnapshotEntry($sansZone)['portesEligibles'], 'D87 : zone vide = aucune porte.');
    }

    // --- Échafaudages ---------------------------------------------------------------------------

    private function creerAutreZone(): EspaceAcces
    {
        $em = $this->snapshotEm();
        $socle = (new Espace())->setNom('Salle fitness delta')->setEtablissement($this->snapshotEtablissementA())->setType('salle');
        $em->persist($socle);
        $zone = (new EspaceAcces())->setLibelle('Zone fitness delta ' . uniqid())->setEspaceSocle($socle);
        $em->persist($zone);
        $em->flush();

        return $zone;
    }

    /** Un `BilletSupport` de vente de l'établissement A portant l'identifiant du support à recharger. */
}
