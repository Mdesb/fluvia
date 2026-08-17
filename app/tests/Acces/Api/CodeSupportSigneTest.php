<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport as AccesTypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use App\Vente\Enum\TypeSupport as VenteTypeSupport;
use App\Vente\Service\GenerateurCodeSupport;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exploitation, côté contrôle d'accès, du code de support unique et signé émis par
 * `App\Vente\Service\GenerateurCodeSupport` (CA-12) : un code appairé et correctement signé est
 * accepté ; un code forgé/altéré (même appairé — cas d'école) ou de signature invalide est refusé
 * explicitement, sans jamais divulguer de secret.
 */
final class CodeSupportSigneTest extends AccesApiTestCase
{
    public function testPassageAccepteUnCodeDeSupportSigneEtAppaire(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$equipement, $identifiant] = $this->creerSupportAppaireAvecCodeSigne();

        $reponse = $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $equipement->getId(),
                'identifiantSupport' => $identifiant,
            ],
        ]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));
        self::assertSame('valide', $reponse->toArray()['resultat']);
    }

    public function testPassageRefuseUnCodeForgeAvecSignatureInvalide(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$equipement, $identifiant] = $this->creerSupportAppaireAvecCodeSigne();

        // Altère le dernier caractère de la signature : format toujours reconnu comme « signé », mais
        // la signature ne correspond plus (code forgé/altéré).
        $dernier = substr($identifiant, -1);
        $identifiantForge = substr($identifiant, 0, -1) . ($dernier === 'A' ? 'B' : 'A');

        $reponse = $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $equipement->getId(),
                'identifiantSupport' => $identifiantForge,
            ],
        ]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));
        $corps = $reponse->toArray();
        self::assertSame('refuse', $corps['resultat']);
        self::assertSame('signature_invalide', $corps['codeMotif']);
    }

    public function testIdentifiantLegacyNonSigneRestePleinementFonctionnel(): void
    {
        // Non-régression explicite : les identifiants historiques/manuels (hors format signé) restent
        // acceptés normalement (fixtures L3, RFID) — cf. testCa3... dans ValidationPassageTest.
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => \App\Acces\DataFixtures\AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('valide', $reponse->toArray()['resultat']);
    }

    /** @return array{0: Equipement, 1: string} équipement de démonstration, identifiant du support signé créé */
    private function creerSupportAppaireAvecCodeSigne(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var GenerateurCodeSupport $generateur */
        $generateur = static::getContainer()->get(GenerateurCodeSupport::class);

        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $equipement = $this->entite(Equipement::class, ['libelle' => \App\Acces\DataFixtures\AccesFixtures::EQUIPEMENT_LIBELLE]);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::Billet)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($etab);
        $em->persist($droit);

        $identifiant = $generateur->genererPourType(VenteTypeSupport::Qr);

        $support = new Support();
        $support->setIdentifiant($identifiant)->setType(AccesTypeSupport::Qr)->setEtablissement($etab);
        $em->persist($support);

        $appairage = new Appairage();
        $appairage->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab);
        $em->persist($appairage);

        $em->flush();

        return [$equipement, $identifiant];
    }
}
