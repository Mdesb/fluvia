<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Api;

use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Entity\PolitiqueRecouvrement;
use App\Recouvrement\Entity\RepresentationSepa;
use App\Recouvrement\Enum\MomentRefusAcces;
use App\Tests\Recouvrement\RecouvrementApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur de recouvrement partagé (`App\Recouvrement`, refactor extraction depuis `App\Sport`) : rejet
 * → incident → (selon politique) accès bloqué → régularisation → ré-accès. Chemin d'entrée du rejet ici
 * : `POST /sport/echeances/{id}/simuler-rejet` (point d'entrée propre à Sport, qui route vers le moteur
 * générique) — toute la suite (représentation, résolution, tableau de bord, politique) passe par les
 * routes génériques `/recouvrement/*`.
 */
final class MoteurRecouvrementTest extends RecouvrementApiTestCase
{
    public function testRejetCreeUnIncidentEtProgrammeUneRepresentationSansBloquerLaccesParDefaut(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->premiereEcheanceContratDemo();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04', 'libelleRetour' => 'Fonds insuffisants'],
        ]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('representation', $incident['statut']);
        self::assertFalse($incident['accesBloque'], 'Politique par défaut = apres_representation_echouee : accès non bloqué avant échec.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $representations = $em->getRepository(RepresentationSepa::class)->findBy(['incident' => $incident['id']]);
        self::assertCount(1, $representations, 'Une représentation est programmée automatiquement selon le calendrier.');
    }

    public function testEchecDeRepresentationBloqueLaccesEtBasculeEnRecouvrement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $representation = $this->representationDe($incidentId);
        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $incident = $em->getRepository(IncidentImpaye::class)->find($incidentId);
        self::assertSame('recouvrement', $incident->getStatut()->value);
        self::assertTrue($incident->isAccesBloque());
    }

    public function testRefusDesLe1erEchecSiPolitiqueLeParametre(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $politique = $em->getRepository(PolitiqueRecouvrement::class)->findOneBy([]);
        $politique->setMomentRefusAcces(MomentRefusAcces::Apres1erEchec);
        $em->flush();

        $echeance = $this->premiereEcheanceContratDemo();
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertTrue($incident['accesBloque'], 'Accès bloqué immédiatement (apres_1er_echec).');

        $em->clear();
        $representations = $em->getRepository(RepresentationSepa::class)->findBy(['incident' => $incident['id']]);
        self::assertCount(1, $representations, 'La représentation reste programmée même en refus immédiat.');
    }

    public function testResolutionUnClicRegulariseEtRestaureLaccesGeneriquement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);
        $representation = $this->representationDe($incidentId);
        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('resolu', $incident['statut']);
        self::assertSame('app_1_clic', $incident['canalResolution']);
        self::assertFalse($incident['accesBloque']);
    }

    public function testResolutionRefuseeSiIncidentDejaResolu(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete);
        self::assertResponseStatusCodeSame(422);
    }

    public function testForcerReouvertureExigeUnMotif(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/forcer-reouverture', $entete + ['json' => ['motif' => '']]);
        self::assertResponseStatusCodeSame(422, 'RG-SOCLE-07 : le motif est requis pour une réouverture forcée journalisée.');

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/forcer-reouverture', $entete + ['json' => ['motif' => 'Geste commercial exceptionnel']]);
        self::assertResponseIsSuccessful();
    }

    private function creerIncident(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): string
    {
        $echeance = $this->premiereEcheanceContratDemo();
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    private function representationDe(string $incidentId): RepresentationSepa
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $representation = $em->getRepository(RepresentationSepa::class)->findOneBy(['incident' => $incidentId]);
        self::assertNotNull($representation);

        return $representation;
    }
}
