<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Tests\Personnel\PersonnelApiTestCase;

/**
 * Cloisonnement multi-entités (RG-SOCLE-05) : un utilisateur ne voit que les employés rattachés à un
 * établissement où il possède une affectation.
 */
final class CloisonnementPersonnelTest extends PersonnelApiTestCase
{
    public function testUtilisateurNeVoitQueSesEtablissementsAffectes(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientRhB, $enteteRhB] = $this->rhSurB();

        $idEmployeA = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'SiteA', 'prenom' => 'Employe', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $clientRh->request('POST', '/api/rattachement_employes', $enteteRh + [
            'json' => ['employe' => '/api/employes/' . $idEmployeA, 'etablissement' => '/api/etablissements/' . $this->idEtablissementA(), 'debut' => '2024-01-01'],
        ]);
        self::assertResponseIsSuccessful();

        $idEmployeB = $clientRhB->request('POST', '/api/employes', $enteteRhB + [
            'json' => ['nom' => 'SiteB', 'prenom' => 'Employe', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $clientRhB->request('POST', '/api/rattachement_employes', $enteteRhB + [
            'json' => ['employe' => '/api/employes/' . $idEmployeB, 'etablissement' => '/api/etablissements/' . $this->idEtablissementB(), 'debut' => '2024-01-01'],
        ]);
        self::assertResponseIsSuccessful();

        // Le lecteur n'a d'affectation socle que sur l'établissement A.
        [$clientLecture, $enteteLecture] = $this->lectureSurA();

        $reponse = $clientLecture->request('GET', '/api/employes', $enteteLecture)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'];
        $ids = array_map(static fn (array $e): string => $e['id'], $membres);

        self::assertContains($idEmployeA, $ids);
        self::assertNotContains($idEmployeB, $ids, 'RG-SOCLE-05 : l\'employé du site B, sans affectation socle du lecteur, n\'est pas visible.');

        $reponseRattachement = $clientLecture->request('GET', '/api/rattachement_employes', $enteteLecture)->toArray();
        $membresRattachement = $reponseRattachement['member'] ?? $reponseRattachement['hydra:member'];
        self::assertCount(1, $membresRattachement);
    }

    public function testEmployeSansRattachementVisiblePourSonCreateurGestionnaire(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Orphelin', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        // Aucun RattachementEmploye créé : la fiche reste consultable par un détenteur de
        // `personnel.gerer_employe` (état transitoire, cf. CA-1 — chicken-and-egg à la création).
        $clientRh->request('GET', '/api/employes/' . $idEmploye, $enteteRh);
        self::assertResponseIsSuccessful();
    }

    /**
     * Correctif — Employés orphelins visibles partout (revue de sécurité, item 5) : un employé sans
     * `RattachementEmploye` n'était visible sous **aucune** condition d'établissement, à quiconque
     * détenait `personnel.lire` — y compris un lecteur totalement étranger à l'établissement du
     * créateur. Le fallback « orphelin » est désormais réservé aux détenteurs de
     * `personnel.gerer_employe`.
     */
    public function testEmployeSansRattachementInvisiblePourUnSimpleLecteur(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientLecture, $enteteLecture] = $this->lectureSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'OrphelinConfidentiel', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        // Le lecteur (permission `personnel.lire` seule, pas `gerer_employe`) ne voit pas la fiche
        // orpheline, même affecté au même établissement que le créateur.
        $clientLecture->request('GET', '/api/employes/' . $idEmploye, $enteteLecture);
        self::assertResponseStatusCodeSame(404, 'RG-SOCLE-05 : un employé orphelin n\'est visible que par un détenteur de personnel.gerer_employe.');

        $reponse = $clientLecture->request('GET', '/api/employes', $enteteLecture)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'];
        $ids = array_map(static fn (array $e): string => $e['id'], $membres);
        self::assertNotContains($idEmploye, $ids, 'RG-SOCLE-05 : l\'employé orphelin n\'apparaît pas non plus dans la collection pour un simple lecteur.');
    }
}
