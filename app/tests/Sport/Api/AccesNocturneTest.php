<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Sport\Entity\ConfigAccesNocturne;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Accès nocturne autonome sécurisé (US-SPORT-09, décision actée, CA-11) : vidéo réputée active,
 * bouton SOS crée un `EvenementSOS` horodaté, détection de présence isolée signale toute occurrence
 * d'un seul adhérent présent, occupation au-delà de `limiteOccupationNocturne` bloque toute nouvelle
 * entrée (réutilise `ValidationPassageHandler`/`ModeSeuil::Blocage` de L3, aucun développement L3 requis).
 */
final class AccesNocturneTest extends SportApiTestCase
{
    public function testCa11ConfigNocturneExposeVideoEtLimiteOccupation(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/config_acces_nocturnes', $entete);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $config = ($liste['member'] ?? $liste['hydra:member'])[0];
        self::assertTrue($config['videoActive']);
        self::assertTrue($config['boutonSosActif']);
        self::assertTrue($config['detectionPresenceIsoleeActive']);
        self::assertSame(3, $config['limiteOccupationNocturne']);
    }

    public function testCa11BoutonSosCreeUnEvenementHorodateEtNotifiable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $espaceId = $this->idEspaceAcces();

        $client->request('POST', '/api/sport/espaces/' . $espaceId . '/sos', []);
        self::assertResponseIsSuccessful();
        $evenement = $client->getResponse()->toArray();
        self::assertSame('ouverte', $evenement['statut']);
        self::assertNotEmpty($evenement['horodatage']);

        // Clôture tracée par un superviseur habilité.
        $client->request('POST', '/api/sport/sos/' . $evenement['id'] . '/traiter', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('traitee', $client->getResponse()->toArray()['statut']);
    }

    public function testCa11DetectionPresenceIsoleeSignaleUnSeulAdherent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $espaceId = $this->idEspaceAcces();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $espace = $em->getRepository(EspaceAcces::class)->find($espaceId);
        $jauge = $em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
        $jauge->setValeurCourante(1);
        $em->flush();

        $client->request('POST', '/api/sport/espaces/' . $espaceId . '/detecter-presence-isolee', $entete);
        self::assertResponseIsSuccessful();
        $alerte = $client->getResponse()->toArray();
        self::assertSame(1, $alerte['nbPersonnesDetectees']);
    }

    public function testCa11DetectionPresenceIsoleeSansDeclenchementSiPasSeul(): void
    {
        [$client, $entete] = $this->adminSurA();
        $espaceId = $this->idEspaceAcces();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $espace = $em->getRepository(EspaceAcces::class)->find($espaceId);
        $jauge = $em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
        $jauge->setValeurCourante(2);
        $em->flush();

        $client->request('POST', '/api/sport/espaces/' . $espaceId . '/detecter-presence-isolee', $entete);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCa11LimiteOccupationNocturneBloqueAuDelaDuSeuil(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Configure un espace de test dont le seuil FMI L3 est aligné sur `limiteOccupationNocturne`
        // (§1.7 du plan : le seuil FMI diurne peut différer de la limite nocturne ; l'établissement
        // paramètre l'un ou l'autre selon le créneau — ici on démontre la réutilisation du mécanisme
        // générique L3, aucun code Sport supplémentaire n'intervient dans la validation du passage).
        [$equipementEntree, $supportA, $supportB] = $this->creerEspaceNocturneASeuilUn();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportA],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportB],
        ]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('seuil_fmi', $reponse['codeMotif']);
    }

    /** @return array{0: string, 1: string, 2: string} idEquipement, identifiantSupportA, identifiantSupportB */
    private function creerEspaceNocturneASeuilUn(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $espaceSocle = (new Espace())->setNom('Espace nocturne test')->setEtablissement($etab)->setType('salle');
        $em->persist($espaceSocle);

        $espace = (new EspaceAcces())->setLibelle('Espace nocturne test')->setEspaceSocle($espaceSocle)->setSeuilFmi(1)->setModeSeuil(ModeSeuil::Blocage);
        $em->persist($espace);

        $config = (new ConfigAccesNocturne())->setEspaceAcces($espace)->setLimiteOccupationNocturne(1);
        $em->persist($config);

        $controleur = (new Controleur())->setLibelle('Contrôleur nocturne test')->setEspace($espace)->setItboxRef('ITBOX-NUIT-TEST');
        $em->persist($controleur);

        $equipement = (new Equipement())->setLibelle('Entrée nocturne test')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($equipement);

        $identifiantA = 'NUIT-A-' . substr((string) Uuid::v4(), 0, 8);
        $identifiantB = 'NUIT-B-' . substr((string) Uuid::v4(), 0, 8);
        foreach ([$identifiantA, $identifiantB] as $identifiant) {
            // D87 : sans zone déclarée, ce droit n'ouvrirait aucune porte. Ce test ne porte pas sur
            // les zones — il lui faut un droit qui ouvre l'espace créé juste au-dessus.
            $droit = (new DroitAcces())->setSourceType(TypeDroitAcces::Billet)->setStatutProjection(StatutProjectionDroit::Valide)->setEtablissement($etab);
            $droit->addAuthorisedSpace($espace);
            $em->persist($droit);
            $support = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
            $em->persist($support);
            $em->persist((new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));
        }

        $em->flush();

        return [(string) $equipement->getId(), $identifiantA, $identifiantB];
    }
}
