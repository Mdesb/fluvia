<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Terminal;
use App\Tests\Acces\AccesApiTestCase;

/**
 * Enrôlement/rotation/révocation d'un `Terminal` (US-TERM-01/09, CA-1, CA-11).
 *
 * Note technique : les assertions ci-dessous vérifient directement l'objet `ResponseInterface` retourné
 * par chaque appel (`getStatusCode()`/`toArray()`) plutôt que les helpers globaux
 * `assertResponseIsSuccessful()`/`assertResponseStatusCodeSame()` — ces derniers s'appuient sur « la
 * dernière réponse suivie » par `ApiTestAssertionsTrait`, un état partagé qui devient ambigu dès qu'on
 * entrelace, dans un même test, des appels sur le client admin (`adminSurA()`) et sur un second client
 * terminal (`static::createClient()` retourne en réalité le même service `test.api_platform.client`).
 */
final class TerminalEnrolementTest extends AccesApiTestCase
{
    public function testCa1EnrolementCreeUnTerminalActifEtAfficheLeSecretUneSeuleFois(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/acces/terminaux', $entete + [
            'json' => ['nom' => 'ITBOX Test Entrée B', 'itboxRef' => 'ITBOX-TEST-B'],
        ]);
        self::assertSame(201, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        $corps = $reponse->toArray();
        self::assertSame('actif', $corps['statut']);
        self::assertNotEmpty($corps['secret']);

        // Le secret n'est plus jamais restitué en lecture (RG-SOCLE-06).
        $lecture = $client->request('GET', '/api/acces/terminaux/' . $corps['id'], $entete)->toArray();
        self::assertArrayNotHasKey('secret', $lecture);
        self::assertArrayNotHasKey('secretHash', $lecture);

        // Le jeton fraîchement émis authentifie bien un appel terminal.
        $snapshot = $client->request('GET', '/api/terminal/snapshot', $this->terminalEntete($corps['secret']));
        self::assertSame(200, $snapshot->getStatusCode(), (string) $snapshot->getContent(false));
    }

    public function testCa1JetonAbsentOuInconnuRefuse401SurLes3Endpoints(): void
    {
        $client = static::createClient();

        $reponseAbsente = $client->request('GET', '/api/terminal/snapshot');
        self::assertSame(401, $reponseAbsente->getStatusCode(), (string) $reponseAbsente->getContent(false));

        $reponseInconnue = $client->request('POST', '/api/terminal/passages', $this->terminalEntete('un-secret-totalement-inconnu'));
        self::assertSame(401, $reponseInconnue->getStatusCode(), (string) $reponseInconnue->getContent(false));

        $reponseLot = $client->request('POST', '/api/terminal/passages/lot', $this->terminalEntete('un-secret-totalement-inconnu') + ['json' => ['lot' => []]]);
        self::assertSame(401, $reponseLot->getStatusCode(), (string) $reponseLot->getContent(false));
    }

    public function testCa11RevocationImmediateRefuse401SurLes3EndpointsSansDonneeTransmise(): void
    {
        [$client, $entete] = $this->adminSurA();

        $idTerminal = $this->idTerminal();
        $revocation = $client->request('POST', '/api/acces/terminaux/' . $idTerminal . '/revoquer', $entete);
        self::assertContains($revocation->getStatusCode(), [200, 201], (string) $revocation->getContent(false));
        self::assertSame('revoque', $revocation->toArray()['statut']);

        $terminalEntete = $this->terminalEntete();

        $reponsePassage = $client->request('POST', '/api/terminal/passages', $terminalEntete + [
            'json' => ['equipementId' => $this->idEquipement(), 'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT],
        ]);
        self::assertSame(401, $reponsePassage->getStatusCode(), (string) $reponsePassage->getContent(false));

        $reponseSnapshot = $client->request('GET', '/api/terminal/snapshot', $terminalEntete);
        self::assertSame(401, $reponseSnapshot->getStatusCode(), (string) $reponseSnapshot->getContent(false));

        $reponseLot = $client->request('POST', '/api/terminal/passages/lot', $terminalEntete + ['json' => ['lot' => []]]);
        self::assertSame(401, $reponseLot->getStatusCode(), (string) $reponseLot->getContent(false));

        // Idempotent : une seconde révocation reste un succès (200) sans erreur.
        $secondeRevocation = $client->request('POST', '/api/acces/terminaux/' . $idTerminal . '/revoquer', $entete);
        self::assertContains($secondeRevocation->getStatusCode(), [200, 201], (string) $secondeRevocation->getContent(false));
    }

    public function testRotationJetonRevoqueLAncienEtEmetUnNouveauSecret(): void
    {
        [$client, $entete] = $this->adminSurA();

        $idTerminal = $this->idTerminal();

        $ancienSecretFonctionneAvantRotation = $client->request('GET', '/api/terminal/snapshot', $this->terminalEntete());
        self::assertSame(200, $ancienSecretFonctionneAvantRotation->getStatusCode(), (string) $ancienSecretFonctionneAvantRotation->getContent(false));

        $rotation = $client->request('POST', '/api/acces/terminaux/' . $idTerminal . '/jetons', $entete);
        self::assertSame(201, $rotation->getStatusCode(), (string) $rotation->getContent(false));
        $nouveauSecret = $rotation->toArray()['secret'];
        self::assertNotEmpty($nouveauSecret);
        self::assertNotSame(AccesFixtures::TERMINAL_SECRET, $nouveauSecret);

        // L'ancien jeton est révoqué immédiatement (pas de période de grâce, Risque R-2 du plan).
        $ancienSecretRefuseApresRotation = $client->request('GET', '/api/terminal/snapshot', $this->terminalEntete());
        self::assertSame(401, $ancienSecretRefuseApresRotation->getStatusCode(), (string) $ancienSecretRefuseApresRotation->getContent(false));

        $nouveauSecretFonctionne = $client->request('GET', '/api/terminal/snapshot', $this->terminalEntete($nouveauSecret));
        self::assertSame(200, $nouveauSecretFonctionne->getStatusCode(), (string) $nouveauSecretFonctionne->getContent(false));

        $terminal = $this->entite(Terminal::class, ['nom' => AccesFixtures::TERMINAL_NOM]);
        self::assertSame('actif', $terminal->getStatut()->value);
    }

    /**
     * Durcissement revue sécurité (double-enrôlement non révocable) : deux `Terminal` actifs sur le
     * même `itboxRef`/établissement produiraient deux `JetonTerminal` valides pour le même matériel —
     * révoquer l'un ne coupant pas l'autre. Le second enrôlement doit être refusé (409).
     */
    public function testDoubleEnrolementMemeItboxRefMemeEtablissementRefuse409(): void
    {
        [$client, $entete] = $this->adminSurA();

        $premier = $client->request('POST', '/api/acces/terminaux', $entete + [
            'json' => ['nom' => 'ITBOX Doublon 1', 'itboxRef' => 'ITBOX-DOUBLON-01'],
        ]);
        self::assertSame(201, $premier->getStatusCode(), (string) $premier->getContent(false));

        $second = $client->request('POST', '/api/acces/terminaux', $entete + [
            'json' => ['nom' => 'ITBOX Doublon 2', 'itboxRef' => 'ITBOX-DOUBLON-01'],
        ]);
        self::assertSame(409, $second->getStatusCode(), (string) $second->getContent(false));
    }
}
