<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Enum\TypeDroitAcces;
use App\Organisation\Entity\Espace;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;

/**
 * Une porte retirée de la topologie doit disparaître des bornes synchronisées en delta.
 *
 * `portesEligibles` ne dépend pas que du droit : il dépend aussi des espaces que dessert le
 * contrôleur de chaque porte (`Controleur::espacesOuverts()`), du contrôleur de l'équipement et du
 * sous-réseau de l'espace — tous modifiables par l'API d'administration. Sans avancer de version, un
 * contrôleur qui ne dessert plus une zone continuait d'y laisser entrer, hors ligne, les droits de
 * cette zone.
 *
 * Le témoin négatif compte autant : le battement de cœur d'un contrôleur s'écrit en permanence. S'il
 * faisait avancer les supports, chaque delta redeviendrait un snapshot complet.
 */
final class SnapshotTopologyTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    public function testRetirerUnEspaceDesserviRetireLaPorteAuDelta(): void
    {
        $zone = $this->creerZone();
        $em = $this->snapshotEm();
        $this->controleurFixture()->addServedSpace($em->getRepository(EspaceAcces::class)->find($zone->getId()));
        $em->flush();
        [, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Abonnement, 1, null, [$this->snapshotEm()->getRepository(EspaceAcces::class)->find($zone->getId())]);
        $porte = $this->idEquipement();
        self::assertContains($porte, $this->fullSnapshotEntry($identifiant)['portesEligibles'], 'témoin : la zone desservie ouvre la porte');

        $curseur = $this->snapshotCursor();
        $em = $this->snapshotEm();
        $this->controleurFixture()->removeServedSpace($em->getRepository(EspaceAcces::class)->find($zone->getId()));
        $em->flush();

        self::assertNotContains($porte, $this->assertInDelta($curseur, $identifiant, 'Controleur::removeServedSpace')['portesEligibles']);
    }

    public function testUnBattementDeCoeurNeFaitPasAvancerLesSupports(): void
    {
        [, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Abonnement);
        $curseur = $this->snapshotCursor();

        $em = $this->snapshotEm();
        $this->controleurFixture()->setDernierHeartbeat(new \DateTimeImmutable());
        $em->flush();

        self::assertNull($this->deltaEntry($curseur, $identifiant), 'Un battement de cœur ne change aucune porte.');
    }

    private function controleurFixture(): Controleur
    {
        $controleur = $this->snapshotEm()->getRepository(Controleur::class)->findOneBy(['libelle' => AccesFixtures::CONTROLEUR_LIBELLE]);
        self::assertInstanceOf(Controleur::class, $controleur);

        return $controleur;
    }

    private function creerZone(): EspaceAcces
    {
        $em = $this->snapshotEm();
        $socle = (new Espace())->setNom('Salle topologie')->setEtablissement($this->snapshotEtablissementA())->setType('salle');
        $em->persist($socle);
        $zone = (new EspaceAcces())->setLibelle('Zone topologie ' . uniqid())->setEspaceSocle($socle);
        $em->persist($zone);
        $em->flush();

        return $zone;
    }
}
