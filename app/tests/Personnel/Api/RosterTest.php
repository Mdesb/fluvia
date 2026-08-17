<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Tests\Personnel\PersonnelApiTestCase;

/**
 * Roster hebdomadaire (§4.5 spec, US-PERSO-04, CA-6) : statut de couverture par créneau
 * (complet/sous-couvert/conflit) et signalement d'une qualification manquante/expirée.
 */
final class RosterTest extends PersonnelApiTestCase
{
    public function testCouvertureCompletSousCouvertConflitEtQualifManquante(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        // Créneau 1 : effectif requis 1, non affecté -> sous-couvert.
        $creneauSousCouvert = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Accueil (sous-couvert)',
                'debut' => '2026-09-07T08:00:00+00:00',
                'fin' => '2026-09-07T12:00:00+00:00',
            ],
        ])->toArray()['id'];

        // Créneau 2 : qualification exigée MNS, affecté à un employé sans qualification valide -> conflit.
        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Sans', 'prenom' => 'Qualif', 'poste' => 'MNS', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        $creneauConflit = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Surveillance bassin (conflit)',
                'debut' => '2026-09-07T14:00:00+00:00',
                'fin' => '2026-09-07T18:00:00+00:00',
                'qualificationRequise' => 'MNS',
            ],
        ])->toArray()['id'];

        // Créneau 3 : complet (effectif 1, un employé affecté, sans qualification exigée).
        $idEmployeComplet = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Complet', 'prenom' => 'Poste', 'poste' => 'Accueil', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $creneauComplet = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Accueil (complet)',
                'debut' => '2026-09-07T20:00:00+00:00',
                'fin' => '2026-09-07T22:00:00+00:00',
            ],
        ])->toArray()['id'];
        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneauComplet, 'employe' => '/api/employes/' . $idEmployeComplet],
        ]);
        self::assertResponseIsSuccessful();

        $roster = $clientPlanning->request('GET', '/api/personnel/roster', $entetePlanning + [
            'query' => ['etablissement' => '/api/etablissements/' . $this->idEtablissementA()],
        ])->toArray();
        $membres = $roster['member'] ?? $roster['hydra:member'];

        $parId = [];
        foreach ($membres as $entree) {
            $parId[$entree['id']] = $entree;
        }

        self::assertSame('sous_couvert', $parId[$creneauSousCouvert]['statutCouverture']);
        self::assertSame('complet', $parId[$creneauComplet]['statutCouverture']);
        self::assertSame('conflit', $parId[$creneauConflit]['statutCouverture']);
        self::assertTrue($parId[$creneauConflit]['qualificationManquanteOuExpiree']);
    }
}
