<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Non-régression module Accès (plan-personnel.md §2/§6) : le cas additif `TypeDroitAcces::Personnel`
 * (extension coordonnée pour `App\Personnel\Entity\BadgeStaff`) ne déclenche **aucun** décompte de
 * crédit (`ValidationPassageHandler` ne teste `sourceType` que pour `CarteQuota`, étape 7) et suit
 * exactement le chemin d'un droit `Billet` au passage — même moteur, aucun code additionnel.
 */
final class TypeDroitAccesPersonnelTest extends AccesApiTestCase
{
    public function testDroitPersonnelNeDeclencheAucunDecompteCreditEtEstValide(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$support] = $this->creerSupportPersonnel();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => $support->getIdentifiant(),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $reponse = $client->getResponse()->toArray();
        self::assertSame('valide', $reponse['resultat'], 'Un droit Personnel suit le chemin générique (comme Billet) — CA-9/RG-PERSO-07.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $droit = $em->getRepository(DroitAcces::class)->findOneBy(['sourceType' => TypeDroitAcces::Personnel]);
        self::assertNotNull($droit);
        self::assertNull($droit->getCreditRestant(), 'Aucun décompte de crédit pour un droit Personnel (non CarteQuota).');
    }

    /** @return array{0: Support, 1: DroitAcces} */
    private function creerSupportPersonnel(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($etab instanceof Etablissement);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::Personnel)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($etab);
        $em->persist($droit);

        $support = (new Support())->setIdentifiant('BADGE-STAFF-' . substr((string) Uuid::v4(), 0, 8))->setType(TypeSupport::Rfid)->setEtablissement($etab);
        $em->persist($support);

        $appairage = (new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab);
        $em->persist($appairage);

        $em->flush();

        return [$support, $droit];
    }
}
