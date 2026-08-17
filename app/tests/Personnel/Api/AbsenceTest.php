<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Tests\Personnel\PersonnelApiTestCase;

/**
 * Absences légères (RG-PERSO-05/10, CA-7).
 */
final class AbsenceTest extends PersonnelApiTestCase
{
    public function testAffectationRefuseeSurAbsenceValidee(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Absent', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        $absence = $clientPlanning->request('POST', '/api/personnel/absences', $entetePlanning + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'debut' => '2026-09-01T00:00:00+00:00',
                'fin' => '2026-09-08T00:00:00+00:00',
                'type' => 'conge',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('declaree', $absence['statut']);

        $clientPlanning->request('POST', '/api/personnel/absences/' . $absence['id'] . '/valider', $entetePlanning);
        self::assertResponseIsSuccessful();
        self::assertSame('validee', $clientPlanning->getResponse()->toArray()['statut']);

        $creneau = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Accueil',
                'debut' => '2026-09-02T08:00:00+00:00',
                'fin' => '2026-09-02T12:00:00+00:00',
            ],
        ])->toArray()['id'];

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau, 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseStatusCodeSame(409, 'CA-7 : affectation refusée sur une période d\'absence validée.');
    }

    public function testAlerteCouvertureSiChevaucheAffectationConfirmee(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Confirme', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        $creneau = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Accueil',
                'debut' => '2026-09-10T08:00:00+00:00',
                'fin' => '2026-09-10T12:00:00+00:00',
            ],
        ])->toArray()['id'];

        $affectation = $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau, 'employe' => '/api/employes/' . $idEmploye],
        ])->toArray();
        self::assertResponseIsSuccessful();

        // Confirme l'affectation directement en base (pas d'endpoint dédié dans ce lot) pour simuler
        // une affectation déjà confirmée au moment de la validation de l'absence.
        $this->confirmerAffectation($affectation['id']);

        $absence = $clientPlanning->request('POST', '/api/personnel/absences', $entetePlanning + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'debut' => '2026-09-10T00:00:00+00:00',
                'fin' => '2026-09-11T00:00:00+00:00',
                'type' => 'maladie',
            ],
        ])->toArray();

        $clientPlanning->request('POST', '/api/personnel/absences/' . $absence['id'] . '/valider', $entetePlanning);
        self::assertResponseIsSuccessful();
        $resultat = $clientPlanning->getResponse()->toArray();
        self::assertSame('validee', $resultat['statut']);
        self::assertTrue($resultat['alerteCouverture'], 'CA-7 : alerte de couverture levée (pas d\'annulation automatique).');
    }

    private function confirmerAffectation(string $id): void
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $affectation = $em->getRepository(\App\Personnel\Entity\AffectationTravail::class)->find($id);
        self::assertNotNull($affectation);
        $affectation->setStatut(\App\Personnel\Enum\StatutAffectationTravail::Confirmee);
        $em->flush();
    }
}
