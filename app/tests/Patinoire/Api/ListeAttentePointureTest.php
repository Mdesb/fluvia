<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Tests\Patinoire\PatinoireApiTestCase;

/** Pointure en rupture : pointure voisine + liste d'attente (US-PATIN-05, CA-5, décision actée). */
final class ListeAttentePointureTest extends PatinoireApiTestCase
{
    public function testPointureVoisineProposeeAvantListeAttente(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idParc42 = $this->idParcPatins(42); // fixture : quantiteTotale=5, quantiteSortie=1 (démo).
        $idParc41 = $this->idParcPatins(41); // fixture : quantiteTotale=5, quantiteEnAffutage=1 → dispo 4.

        // Vide entièrement la pointure 42 (encore 4 disponibles).
        for ($i = 0; $i < 4; ++$i) {
            $client->request('POST', '/api/patinoire/locations', $entete + [
                'json' => [
                    'parcPatins' => '/api/patinoire_parc_patins/' . $idParc42,
                    'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                ],
            ]);
            self::assertResponseIsSuccessful();
        }

        // Tentative de sortie pointure 42 (dispo nulle) → 409, pointure voisine (41, dispo 4) suggérée.
        $client->request('POST', '/api/patinoire/locations', $entete + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc42,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiaireEnfant(),
            ],
        ]);
        self::assertResponseStatusCodeSame(409);
        $detail = $client->getResponse()->toArray(false)['detail'] ?? '';
        self::assertStringContainsString('41', $detail, 'CA-5 : pointure voisine (41) proposée dans le message.');

        // Inscription en liste d'attente pour la pointure 42.
        $client->request('POST', '/api/patinoire/liste-attente', $entete + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc42,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiaireEnfant(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $inscription = $client->getResponse()->toArray();
        self::assertSame('en_attente', $inscription['statut']);
        self::assertSame(41, $inscription['pointureVoisineProposee'], 'CA-5 : pointure voisine mémorisée à l\'inscription.');
        self::assertSame(1, $inscription['rang']);
    }

    public function testNotificationDesInscritsAuRetourDUneUnite(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idParc = $this->idParcPatins(42);

        // Vide la pointure 42 (4 unités restantes après la location démo, +1 sortie ici).
        $idLocations = [];
        for ($i = 0; $i < 4; ++$i) {
            $client->request('POST', '/api/patinoire/locations', $entete + [
                'json' => [
                    'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                    'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                ],
            ]);
            self::assertResponseIsSuccessful();
            $idLocations[] = $client->getResponse()->toArray()['id'];
        }

        // Inscription en liste d'attente (rupture confirmée).
        $client->request('POST', '/api/patinoire/liste-attente', $entete + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiaireEnfant(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idInscription = $client->getResponse()->toArray()['id'];

        // Retour « bon » d'une des locations → une unité redevient disponible → promotion (§4.5).
        $client->request('POST', '/api/patinoire/locations/' . $idLocations[0] . '/retour', $entete + [
            'json' => ['etatRetour' => 'bon'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/patinoire_liste_attente_pointures/' . $idInscription, $entete);
        self::assertResponseIsSuccessful();
        $inscription = $client->getResponse()->toArray();
        self::assertSame('proposee', $inscription['statut'], 'CA-5 : promotion automatique à la libération d\'une unité.');
        self::assertNotNull($inscription['dateExpirationProposition']);
    }

    public function testAnnulationListeAttente(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idParc = $this->idParcPatins(42);
        $client->request('POST', '/api/patinoire/liste-attente', $entete + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiaireConjoint(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idInscription = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/patinoire/liste-attente/' . $idInscription . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('expiree', $client->getResponse()->toArray()['statut']);
    }
}
