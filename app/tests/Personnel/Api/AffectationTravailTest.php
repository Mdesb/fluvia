<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Personnel\DataFixtures\PersonnelFixtures;
use App\Tests\Personnel\PersonnelApiTestCase;

/**
 * Affectation d'un Employé à un CreneauTravail (RG-PERSO-04, CA-3/CA-4/CA-5).
 */
final class AffectationTravailTest extends PersonnelApiTestCase
{
    public function testConflitChevauchementMemeEtablissementBloque(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmploye = $this->creerEmploye($clientRh, $enteteRh);

        $creneau1 = $this->creerCreneau($clientPlanning, $entetePlanning, $this->idEtablissementA(), '2026-09-01T08:00:00+00:00', '2026-09-01T12:00:00+00:00');
        $creneau2 = $this->creerCreneau($clientPlanning, $entetePlanning, $this->idEtablissementA(), '2026-09-01T10:00:00+00:00', '2026-09-01T14:00:00+00:00');

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau1, 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseIsSuccessful();

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau2, 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testConflitChevauchementEtablissementsDifferentsBloque(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();
        [$clientPlanningB, $entetePlanningB] = $this->authentifie(PersonnelFixtures::EMAIL_PLANNING, $this->idEtablissementB());

        $idEmploye = $this->creerEmploye($clientRh, $enteteRh);

        $creneauA = $this->creerCreneau($clientPlanning, $entetePlanning, $this->idEtablissementA(), '2026-09-01T08:00:00+00:00', '2026-09-01T12:00:00+00:00');
        $creneauB = $this->creerCreneau($clientPlanningB, $entetePlanningB, $this->idEtablissementB(), '2026-09-01T10:00:00+00:00', '2026-09-01T14:00:00+00:00');

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneauA, 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseIsSuccessful();

        $clientPlanningB->request('POST', '/api/personnel/affectations', $entetePlanningB + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneauB, 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseStatusCodeSame(409, 'Le conflit est bloqué même entre établissements différents (RG-PERSO-04, §7 cas limite).');
    }

    public function testAffectationRefuseeSansQualificationValide(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmploye = $this->creerEmploye($clientRh, $enteteRh, 'MNS');

        $creneau = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Surveillance bassin 1',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
                'qualificationRequise' => 'MNS',
            ],
        ])->toArray()['id'];

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau, 'employe' => '/api/employes/' . $idEmploye],
        ]);

        self::assertResponseStatusCodeSame(422, 'CA-5 : aucune qualification valide détenue par l\'employé.');
    }

    public function testEmployeNonProposePourQualificationExpiree(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmploye = $this->creerEmploye($clientRh, $enteteRh, 'MNS');

        // Qualification MNS expirée (CA-3).
        $clientRh->request('POST', '/api/qualifications', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'type' => 'MNS',
                'dateValidite' => '2020-01-01',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $creneau = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Surveillance bassin 1',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
                'qualificationRequise' => 'MNS',
            ],
        ])->toArray()['id'];

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau, 'employe' => '/api/employes/' . $idEmploye],
        ]);

        self::assertResponseStatusCodeSame(422, 'CA-3 : une qualification expirée ne couvre plus le créneau.');
    }

    public function testAffectationValideeAvecQualificationEnCours(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmploye = $this->creerEmploye($clientRh, $enteteRh, 'MNS');

        $clientRh->request('POST', '/api/qualifications', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'type' => 'MNS',
                'dateValidite' => '2030-01-01',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $creneau = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Surveillance bassin 1',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
                'qualificationRequise' => 'MNS',
            ],
        ])->toArray()['id'];

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau, 'employe' => '/api/employes/' . $idEmploye],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('planifiee', $clientPlanning->getResponse()->toArray()['statut']);
    }

    private function creerEmploye(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $poste = 'Agent'): string
    {
        return $client->request('POST', '/api/employes', $entete + [
            'json' => [
                'nom' => 'Test',
                'prenom' => 'Employe',
                'poste' => $poste,
                'typeContrat' => 'cdi',
                'dateEntree' => '2024-01-01',
            ],
        ])->toArray()['id'];
    }

    private function creerCreneau(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $idEtablissement, string $debut, string $fin): string
    {
        return $client->request('POST', '/api/personnel/creneaux-travail', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $idEtablissement,
                'libellePoste' => 'Poste test',
                'debut' => $debut,
                'fin' => $fin,
            ],
        ])->toArray()['id'];
    }
}
