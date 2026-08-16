<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Musee\Entity\PolitiqueDelestage;
use App\Musee\Entity\Salle;
use App\Musee\Entity\SousQuotaSalle;
use App\Musee\Enum\ModeDelestage;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Tests\Musee\MuseeApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sous-quota de salle & délestage (US-MUSEE-02, décision actée « Expo à forte affluence », CA-2) —
 * spécialisation musée de la jauge FMI L3 (`EspaceAcces`/`JaugeFmi`, **réutilisés**, aucun code L3
 * modifié). Le comptage réutilise `POST /acces/passages` (L3) tel quel.
 */
final class SousQuotaSalleTest extends MuseeApiTestCase
{
    public function testCa2ModeBlocageRefuseAuSeuilEtSortieLibereUnePlace(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        [$idSalle, $idEntree, $idSortie, $supportA, $supportB] = $this->creerSalleAvecSousQuota(ModeSeuil::Blocage, 1);

        $client->request('GET', '/api/musee/salles/' . $idSalle . '/etat', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $client->getResponse()->toArray()['presents']);

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $idEntree, 'identifiantSupport' => $supportA],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        // Seuil du sous-quota atteint (blocage strict) : la deuxième entrée est refusée (CA-2).
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $idEntree, 'identifiantSupport' => $supportB],
        ]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);

        $client->request('GET', '/api/musee/salles/' . $idSalle . '/etat', $entete);
        $etat = $client->getResponse()->toArray();
        self::assertSame(1, $etat['presents']);
        self::assertTrue($etat['seuilAtteint']);

        // Une sortie scannée libère immédiatement une place, indépendamment de la jauge du créneau
        // d'entrée (déjà validée en amont, CA-2).
        $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $idSortie, 'sens' => 'sortie', 'motif' => 'sortie test'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $idEntree, 'identifiantSupport' => $supportB],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat'], 'CA-2 : nouvel entrant admissible après la sortie.');
    }

    public function testCa2ModeAlerteEntreeAccepteeAuDelaDuSeuilEtPolitiqueDelestageExposee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        [$idSalle, $idEntree, , $supportA, $supportB] = $this->creerSalleAvecSousQuota(ModeSeuil::Alerte, 1);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $salle = $em->getRepository(Salle::class)->find($idSalle);
        self::assertInstanceOf(Salle::class, $salle);
        $sousQuota = $em->getRepository(SousQuotaSalle::class)->findOneBy(['salle' => $salle]);
        self::assertInstanceOf(SousQuotaSalle::class, $sousQuota);
        $politique = (new PolitiqueDelestage())->setSousQuotaSalle($sousQuota)->setMode(ModeDelestage::FileAttenteSurPlace)
            ->setMessageAgent('File d\'attente à l\'entrée de la salle.')->setEtablissement($salle->getEtablissement());
        $em->persist($politique);
        $em->flush();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $idEntree, 'identifiantSupport' => $supportA],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        // Mode alerte : l'entrée passe même au-delà du seuil (pas de blocage physique, §4.2).
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $idEntree, 'identifiantSupport' => $supportB],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat'], 'CA-2 : mode alerte, entrée acceptée au-delà du seuil.');

        $client->request('GET', '/api/musee/salles/' . $idSalle . '/etat', $entete);
        $etat = $client->getResponse()->toArray();
        self::assertSame(2, $etat['presents']);
        self::assertTrue($etat['seuilAtteint'], 'CA-2 : la saturation doit être signalée à l\'agent mobile.');
        self::assertSame('file_attente_sur_place', $etat['politiqueDelestageMode']);
        self::assertNotEmpty($etat['messageAgent']);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} idSalle, idEquipementEntree,
     *   idEquipementSortie, identifiantSupportA, identifiantSupportB
     */
    private function creerSalleAvecSousQuota(ModeSeuil $mode, int $seuil): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $espaceSocle = (new Espace())->setNom('Salle sous-quota test')->setEtablissement($etab)->setType('salle_exposition');
        $em->persist($espaceSocle);

        $espaceAcces = (new EspaceAcces())->setLibelle('Salle sous-quota test')->setEspaceSocle($espaceSocle)->setSeuilFmi($seuil)->setModeSeuil($mode);
        $em->persist($espaceAcces);

        $salle = (new Salle())->setNom('Salle sous-quota test')->setEspace($espaceSocle)->setEspaceAcces($espaceAcces);
        $em->persist($salle);

        $sousQuota = (new SousQuotaSalle())->setSalle($salle)->setActif(true);
        $em->persist($sousQuota);

        $controleur = (new Controleur())->setLibelle('Contrôleur salle test')->setEspace($espaceAcces)->setItboxRef('ITBOX-MUSEE-SALLE-TEST');
        $em->persist($controleur);

        $entree = (new Equipement())->setLibelle('Entrée salle test')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($entree);
        $sortie = (new Equipement())->setLibelle('Sortie salle test')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Sortie);
        $em->persist($sortie);

        $identifiantA = 'SALLE-A-' . substr((string) Uuid::v4(), 0, 8);
        $identifiantB = 'SALLE-B-' . substr((string) Uuid::v4(), 0, 8);
        foreach ([$identifiantA, $identifiantB] as $identifiant) {
            $droit = (new DroitAcces())->setSourceType(TypeDroitAcces::Billet)->setStatutProjection(StatutProjectionDroit::Valide)->setEtablissement($etab);
            $em->persist($droit);
            $support = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
            $em->persist($support);
            $em->persist((new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));
        }

        $em->flush();

        return [(string) $salle->getId(), (string) $entree->getId(), (string) $sortie->getId(), $identifiantA, $identifiantB];
    }
}
