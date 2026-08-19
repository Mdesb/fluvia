<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;

/**
 * CRUD `ExpenseAccountMapping` (US-L4-12, RG-M6-12) : contrainte unique `(businessProfile,
 * expenseNatureCode)`, filtre `active`, cloisonnement à l'écriture (§3 spec, point 3).
 */
final class ExpenseAccountMappingApiTest extends ComptaApiTestCase
{
    public function testCreationEtLectureDunMapping(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/expense_account_mappings', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'expenseNatureCode' => 'travel',
                'expenseAccount' => '/api/compte_comptables/' . $this->idCompte('627000'),
                'deductibleVatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('travel', $reponse['expenseNatureCode']);
        self::assertTrue($reponse['active']);

        $client->request('GET', '/api/expense_account_mappings/' . $reponse['id'], $entete);
        self::assertResponseIsSuccessful();
    }

    public function testContrainteUniqueProfilNature(): void
    {
        [$client, $entete] = $this->adminSurA();

        $corps = [
            'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
            'expenseNatureCode' => 'lodging',
            'expenseAccount' => '/api/compte_comptables/' . $this->idCompte('627000'),
            'deductibleVatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
        ];
        $client->request('POST', '/api/expense_account_mappings', $entete + ['json' => $corps]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/expense_account_mappings', $entete + ['json' => $corps]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testFiltreActif(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/expense_account_mappings', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'expenseNatureCode' => 'meals',
                'expenseAccount' => '/api/compte_comptables/' . $this->idCompte('627000'),
                'deductibleVatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
                'active' => false,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $reponse = $client->request('GET', '/api/expense_account_mappings?active=false', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertNotEmpty($membres);
        foreach ($membres as $item) {
            self::assertFalse($item['active']);
        }
    }

    public function testCreationPourProfilHorsPerimetreRefusee(): void
    {
        // Admin actif sur l'établissement B (aucun profil exploitant Compta n'y existe) tente de créer
        // un mapping pour le profil de l'établissement A -> refusé (§3 spec, point 3).
        [$client, $entete] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $idB]];

        $client->request('POST', '/api/expense_account_mappings', $enteteB + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'expenseNatureCode' => 'supplies',
                'expenseAccount' => '/api/compte_comptables/' . $this->idCompte('627000'),
                'deductibleVatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
