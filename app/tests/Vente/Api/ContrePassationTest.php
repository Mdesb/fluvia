<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Autorisation\AutorisationApiTestCase;
use App\Tests\Vente\Support\RowHolder;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Remboursement / avoir / annulation par contre-passation (CA-13 / RG-M2-07) : droit requis,
 * traçabilité, avoir généré, support invalidé après impression, aucune ligne supprimée.
 */
final class ContrePassationTest extends AutorisationApiTestCase
{
    use RowHolder;

    /** CA-13 — Annulation par un habilité : avoir + support invalidé (après impression) ; lignes conservées. */
    public function testCa13AnnulationGenereAvoirEtInvalideSupport(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']); // 45,00, imprimée (>seuil)

        $avoir = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + [
            'json' => ['motif' => 'Erreur de saisie'],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('annulee', $avoir['statutVente']);
        self::assertSame('annulation', $avoir['nature']);
        self::assertTrue($avoir['supportInvalide'], 'Annulation après impression → support invalidé (Accès).');
        self::assertSame('45.00', $avoir['montant']);

        // Aucune ligne d'origine supprimée (contre-passation).
        $vente = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();
        self::assertNotEmpty($vente['lignes']);
    }

    /** CA-13 — Remboursement partiel tracé : avoir « remboursement », vente en statut avoir_emis. */
    public function testCa13RemboursementPartiel(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']);

        $avoir = $client->request('POST', '/api/ventes/' . $venteId . '/rembourser', $entete + [
            'json' => ['motif' => 'Geste commercial', 'montant' => '15.00'],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('remboursement', $avoir['nature']);
        self::assertSame('15.00', $avoir['montant']);
        self::assertSame('avoir_emis', $avoir['statutVente']);
    }

    /** CA-13 — Un opérateur non habilité (lecture seule) ne peut pas annuler (403). */
    public function testCa13NonHabiliteRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']);

        // Lecteur (permission *.lire uniquement) sur l'établissement A.
        $lecteurClient = static::createClient();
        $token = $this->jeton($lecteurClient, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $enteteLecteur = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];

        $lecteurClient->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteLecteur + [
            'json' => ['motif' => 'Test'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /** CA-2 — Plafond 0,00 avec escalade : toute annulation part en escalade, la vente reste validée. */
    public function testPlafondZeroEscaladeToujours(): void
    {
        $this->configurerLimiteAnnuler();
        [$client, $entete] = $this->connecte('caissier-plafond0@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']);

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Client parti']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('escalade_requise', $reponse->toArray(false)['decision']);
        self::assertArrayHasKey('demandeEscalade', $reponse->toArray(false));
        self::assertSame('validee', $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray()['statut']);
    }

    /** CA-4 — Une vente déjà annulée ne s'annule pas deux fois : 409. */
    public function testAnnulerDeuxFoisRend409(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']);
        $corps = $entete + ['json' => ['motif' => 'Doublon']];

        $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $corps);
        self::assertResponseStatusCodeSame(201);
        $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $corps);
        self::assertResponseStatusCodeSame(409);
    }

    /** CA-5 — Motif absent ou hors liste fermée : 422, et le message nomme les motifs admis. */
    public function testMotifAbsentOuHorsListeRend422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']);

        $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(422);
        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Bidon']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Erreur de saisie', $reponse->toArray(false)['detail']);
    }

    /** CA-3 — Un caissier d'un autre établissement ne voit pas la vente : 404. */
    public function testAutreEtablissementRend404(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $venteId = $this->venteCarteValidee($client, $entete, $session['id']);

        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $this->creerUtilisateur('caissier-b@test.itcotation.com', $this->roleCaissier(), etablissement: $etabB);
        $clientB = static::createClient();
        $enteteB = ['auth_bearer' => $this->jeton($clientB, 'caissier-b@test.itcotation.com', 'aaa'), 'headers' => [ContexteEtablissement::HEADER => (string) $etabB->getId()]];
        $reponse = $clientB->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteB + ['json' => ['motif' => 'Doublon']]);
        self::assertSame(404, $reponse->getStatusCode());
    }

    /**
     * É11 du ticket opposable — JAMAIS PLUS REMBOURSÉ QUE CE QUI RESTE. Chaque remboursement était borné
     * au total de la vente, pas à ce qui en restait : deux remboursements de 30,00 sur 45,00 rendaient 60,00.
     */
    public function testLesRemboursementsCumulesNeDepassentJamaisLeTotal(): void
    {
        [$client, $entete] = $this->adminSurA();
        $venteId = $this->venteCarteValidee($client, $entete, $this->ouvrirSession($client, $entete)['id']); // 45,00

        self::assertSame(201, $this->rembourser($client, $entete, $venteId, '30.00')->getStatusCode());
        $refus = $this->rembourser($client, $entete, $venteId, '30.00');
        self::assertSame(422, $refus->getStatusCode());
        self::assertStringContainsString('15.00', $refus->toArray(false)['detail'], 'Le refus dit ce qui reste remboursable.');
        self::assertSame(201, $this->rembourser($client, $entete, $venteId, '15.00')->getStatusCode(), 'Le reste exact passe (témoin).');
        self::assertSame(422, $this->rembourser($client, $entete, $venteId, '0.01')->getStatusCode());
        self::assertSame('45.00', $this->totalAvoirs($venteId));
    }

    /** Sans montant, un remboursement rend ce qui reste, plus le total. */
    public function testUnRemboursementSansMontantRendLeReste(): void
    {
        [$client, $entete] = $this->adminSurA();
        $venteId = $this->venteCarteValidee($client, $entete, $this->ouvrirSession($client, $entete)['id']);
        $this->rembourser($client, $entete, $venteId, '10.00');

        $avoir = $this->rembourser($client, $entete, $venteId, null);
        self::assertSame(201, $avoir->getStatusCode());
        self::assertSame('35.00', $avoir->toArray()['montant']);
        self::assertSame('45.00', $this->totalAvoirs($venteId));
    }

    /** Une annulation après un remboursement partiel n'émet que le reste ; après un remboursement total, un avoir nul. */
    public function testUneAnnulationApresDesRemboursementsNEmetQueLeReste(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete)['id'];
        $partielle = $this->venteCarteValidee($client, $entete, $session);
        $totale = $this->venteCarteValidee($client, $entete, $session);
        $this->rembourser($client, $entete, $partielle, '10.00');
        $this->rembourser($client, $entete, $totale, null);

        foreach ([$partielle => '35.00', $totale => '0.00'] as $venteId => $attendu) {
            $avoir = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Erreur de saisie']]);
            self::assertSame(201, $avoir->getStatusCode());
            self::assertSame([$attendu, 'annulee'], [$avoir->toArray()['montant'], $avoir->toArray()['statutVente']]);
            self::assertSame('45.00', $this->totalAvoirs($venteId));
        }
    }

    /**
     * Un remboursement concurrent tient la vente et a émis 30,00 sans avoir validé : le second attend
     * son issue, puis ne passe plus. Lu avant le verrou, le déjà-remboursé aurait été 0,00.
     */
    public function testUnRemboursementConcurrentEstAttenduPuisPlafonne(): void
    {
        [$client, $entete] = $this->adminSurA();
        $venteId = $this->venteCarteValidee($client, $entete, $this->ouvrirSession($client, $entete)['id']);
        $hex = str_replace('-', '', $venteId);
        $auteur = str_replace('-', '', (string) $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId());

        $autre = $this->holdRow([
            'SELECT id FROM vente_vente WHERE id = UNHEX(?) FOR UPDATE',
            'INSERT INTO vente_avoir (id, numero, montant, motif, date_heure, support_invalide, nature, vente_origine_id, auteur_id, etablissement_id) '
            . "SELECT UNHEX(REPLACE(UUID(), '-', '')), 'AV-CONCURRENT', '30.00', 'Concurrent', UTC_TIMESTAMP(), 0, 'remboursement', id, UNHEX(?), etablissement_id FROM vente_vente WHERE id = UNHEX(?)",
        ], [[$hex], [$auteur, $hex]]);
        $debut = microtime(true);
        $statut = $this->rembourser($client, $entete, $venteId, '30.00')->getStatusCode();
        $attente = microtime(true) - $debut;
        $this->releaseRow($autre);

        self::assertSame(422, $statut);
        self::assertGreaterThanOrEqual(1.5, $attente, 'Le remboursement doit attendre la contre-passation en cours sur la même vente.');
        self::assertSame('30.00', $this->totalAvoirs($venteId));
    }

    /** @param array<string, mixed> $entete */
    private function rembourser(object $client, array $entete, string $venteId, ?string $montant): ResponseInterface
    {
        return $client->request('POST', '/api/ventes/' . $venteId . '/rembourser', $entete + [
            'json' => ['motif' => 'Geste commercial'] + ($montant === null ? [] : ['montant' => $montant]),
        ]);
    }

    private function totalAvoirs(string $venteId): string
    {
        return (string) $this->em()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(montant), 0) FROM vente_avoir WHERE vente_origine_id = UNHEX(:v)',
            ['v' => str_replace('-', '', $venteId)],
        );
    }

    private function configurerLimiteAnnuler(): void
    {
        $em = $this->em();
        $em->persist((new LimiteAutorisation())
            ->setOperation($em->getRepository(OperationSensible::class)->find('vente.annuler'))
            ->setRole($this->roleCaissier())
            ->setEtablissement($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]))
            ->setPlafondMontant('0.00')
            ->setPerimetre(PerimetreAutorisation::Global)
            ->setEscaladeAuDela(true)
            ->setAuteur($this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])));
        $em->flush();
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function venteCarteValidee(object $client, array $entete, string $sessionId): string
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $vente['id'];
    }
}
