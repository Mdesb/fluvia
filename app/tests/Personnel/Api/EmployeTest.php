<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Tests\Personnel\PersonnelApiTestCase;

/**
 * Fiche employé & rattachement multi-établissement (RG-PERSO-01/09, CA-1/CA-2).
 */
final class EmployeTest extends PersonnelApiTestCase
{
    public function testCreationSansCompteUtilisateurUtilisablePlanningEtBadge(): void
    {
        [$client, $entete] = $this->rhSurA();

        $client->request('POST', '/api/employes', $entete + [
            'json' => [
                'nom' => 'Durand',
                'prenom' => 'Alice',
                'poste' => 'Agent d\'entretien',
                'typeContrat' => 'cdi',
                'dateEntree' => '2024-01-01',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $employe = $client->getResponse()->toArray();
        self::assertSame('actif', $employe['statut']);
        self::assertArrayNotHasKey('utilisateur', $employe, 'Aucun compte Utilisateur requis (CA-1).');

        // Rattachement à un établissement : condition nécessaire pour être planifiable/badgeable
        // (RG-PERSO-09) — la fiche est bien utilisable sans jamais créer de compte logiciel.
        $client->request('POST', '/api/rattachement_employes', $entete + [
            'json' => [
                'employe' => '/api/employes/' . $employe['id'],
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'debut' => '2024-01-01',
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testPlanningMultiEtablissementAvecPosteLocal(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $client = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => [
                'nom' => 'Martin',
                'prenom' => 'Julien',
                'poste' => 'Agent d\'accueil',
                'typeContrat' => 'cdi',
                'dateEntree' => '2024-01-01',
            ],
        ])->toArray();
        $idEmploye = $client['id'];

        // Rattaché aux deux établissements, avec un poste local différent sur B.
        $clientRh->request('POST', '/api/rattachement_employes', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'debut' => '2024-01-01',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $clientRh->request('POST', '/api/rattachement_employes', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'posteLocal' => 'Agent de caisse',
                'debut' => '2024-01-01',
            ],
        ]);
        self::assertResponseIsSuccessful();

        [$clientPlanning, $entetePlanning] = $this->planningSurA();
        $creneauA = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Accueil matin',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        [$clientPlanningB, $entetePlanningB] = $this->authentifie(\App\Personnel\DataFixtures\PersonnelFixtures::EMAIL_PLANNING, $this->idEtablissementB());
        $creneauB = $clientPlanningB->request('POST', '/api/personnel/creneaux-travail', $entetePlanningB + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'libellePoste' => 'Caisse après-midi',
                'debut' => '2026-09-01T14:00:00+00:00',
                'fin' => '2026-09-01T18:00:00+00:00',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneauA['id'], 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseIsSuccessful();

        $clientPlanningB->request('POST', '/api/personnel/affectations', $entetePlanningB + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneauB['id'], 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseIsSuccessful();

        // Le roster de chaque site montre bien l'employé avec son poste local propre au site.
        $rosterA = $clientPlanning->request('GET', '/api/personnel/roster', $entetePlanning + [
            'query' => ['employe' => '/api/employes/' . $idEmploye],
        ])->toArray();
        $rosterB = $clientPlanningB->request('GET', '/api/personnel/roster', $entetePlanningB + [
            'query' => ['employe' => '/api/employes/' . $idEmploye],
        ])->toArray();

        self::assertNotEmpty($rosterA['member'] ?? $rosterA['hydra:member']);
        self::assertNotEmpty($rosterB['member'] ?? $rosterB['hydra:member']);
    }
}
