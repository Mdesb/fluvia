<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Tests\Crm\CrmApiTestCase;

/**
 * US-L5-03, RG-M4-02 : familles, payeur ≠ bénéficiaire.
 */
final class FamilleTest extends CrmApiTestCase
{
    /** CA-5, RG-M4-02 — L'achat réglé par le payeur apparaît sur la fiche de l'enfant bénéficiaire. */
    public function testCa5AbonnementApparaitSurFicheBeneficiaireJamaisPayeur(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();
        $enfantId = $this->idEnfant();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['session' => '/api/session_caisses/' . $session['id']]])->toArray();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/client', $entete + ['json' => ['client' => $payeurId]]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(\App\Offre\DataFixtures\OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(\App\Offre\DataFixtures\OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
                'beneficiaire' => $enfantId,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete);
        self::assertResponseIsSuccessful();

        $ficheEnfant = $client->request('GET', '/api/clients/' . $enfantId . '/fiche-360', $entete)->toArray();
        $rolesEnfant = array_merge(...array_column($ficheEnfant['historique'], 'roles'));
        self::assertContains('beneficiaire', $rolesEnfant, 'CA-5 : la ligne apparaît sur la fiche du bénéficiaire.');

        $fichePayeur = $client->request('GET', '/api/clients/' . $payeurId . '/fiche-360', $entete)->toArray();
        $entreesBeneficiairePourPayeur = array_filter(
            $fichePayeur['historique'],
            static fn (array $h): bool => $h['client'] === $payeurId && \in_array('beneficiaire', $h['roles'], true),
        );
        self::assertSame([], $entreesBeneficiairePourPayeur, 'CA-5 : jamais comme bénéficiaire sur la fiche du payeur.');
    }

    /** CA-6 — Rattacher un client déjà dans une famille active à une seconde famille active déclenche une alerte, reste tracé/réversible. */
    public function testCa6AlerteSecondeFamilleActiveEtRetraitTraceReversible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $enfantId = $this->idEnfant();

        // Nouvelle famille, tentative de rattacher l'enfant déjà actif dans la famille Dupont.
        $nouvellefamille = $client->request('POST', '/api/familles', $entete + [
            'json' => ['libelle' => 'Famille Secondaire', 'payeurPrincipal' => '/api/clients/' . $this->idConjoint()],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $ajout = $client->request('POST', '/api/familles/' . $nouvellefamille['id'] . '/beneficiaires', $entete + [
            'json' => ['client' => '/api/clients/' . $enfantId, 'role' => 'beneficiaire'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertTrue($ajout['alerte'], 'CA-6 : alerte affichée, ajout à une 2ᵉ famille active.');

        // Retrait tracé et réversible.
        $beneficiaireId = $ajout['beneficiaire'];
        $retrait = $client->request('POST', '/api/beneficiaires/' . $beneficiaireId . '/retirer', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotNull($retrait['dateRetrait']);
    }
}
