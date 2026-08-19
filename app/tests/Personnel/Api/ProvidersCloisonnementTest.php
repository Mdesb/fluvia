<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Personnel\Entity\BadgeStaff;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Correctif — Providers custom non cloisonnés (revue de sécurité, item 2) : `RosterProvider` et
 * `DeclarationIncidentBadgeProvider` exécutaient une requête EM brute où le filtre `etablissement`
 * était un simple paramètre client optionnel, sans jamais recouper le périmètre réel de
 * l'utilisateur (`Affectation`).
 */
final class ProvidersCloisonnementTest extends PersonnelApiTestCase
{
    public function testRosterSansParametreNeRenvoieQueLetablissementDeLutilisateur(): void
    {
        [$clientPlanningA, $entetePlanningA] = $this->planningSurA();
        [$clientPlanningB, $entetePlanningB] = $this->authentifie(\App\Personnel\DataFixtures\PersonnelFixtures::EMAIL_PLANNING, $this->idEtablissementB());

        $clientPlanningA->request('POST', '/api/personnel/creneaux-travail', $entetePlanningA + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Accueil A',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $clientPlanningB->request('POST', '/api/personnel/creneaux-travail', $entetePlanningB + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'libellePoste' => 'Accueil B',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Le lecteur n'est affecté (Affectation socle) que sur A — sans le moindre paramètre
        // `etablissement`, seuls les créneaux de A doivent apparaître.
        [$clientLecture, $enteteLecture] = $this->lectureSurA();
        $roster = $clientLecture->request('GET', '/api/personnel/roster', $enteteLecture)->toArray();
        $membres = $roster['member'] ?? $roster['hydra:member'];
        $postes = array_map(static fn (array $c): string => $c['poste'], $membres);

        self::assertContains('Accueil A', $postes);
        self::assertNotContains('Accueil B', $postes, 'RG-SOCLE-05 : le roster de B ne doit jamais apparaître pour un utilisateur affecté uniquement sur A.');
    }

    public function testRosterAvecParametreEtablissementHorsPerimetreNeRenvoieRien(): void
    {
        [$clientPlanningB, $entetePlanningB] = $this->authentifie(\App\Personnel\DataFixtures\PersonnelFixtures::EMAIL_PLANNING, $this->idEtablissementB());

        $clientPlanningB->request('POST', '/api/personnel/creneaux-travail', $entetePlanningB + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'libellePoste' => 'Accueil B bis',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Le lecteur (affecté sur A uniquement) force le paramètre `etablissement` vers B : le
        // paramètre client ne doit jamais élargir le périmètre réel.
        [$clientLecture, $enteteLecture] = $this->lectureSurA();
        $roster = $clientLecture->request('GET', '/api/personnel/roster', $enteteLecture + [
            'query' => ['etablissement' => '/api/etablissements/' . $this->idEtablissementB()],
        ])->toArray();
        $membres = $roster['member'] ?? $roster['hydra:member'];

        self::assertSame([], $membres, 'RG-SOCLE-05 : un établissement hors périmètre demandé en paramètre ne renvoie jamais de résultat.');
    }

    public function testDeclarationsIncidentDunAutreEtablissementNonVisibles(): void
    {
        [$clientRhA, $enteteRhA] = $this->rhSurA();
        [$clientRhB, $enteteRhB] = $this->rhSurB();
        [$clientAccueil, $enteteAccueil] = $this->accueilSurA();

        $idEmployeA = $clientRhA->request('POST', '/api/employes', $enteteRhA + [
            'json' => ['nom' => 'InciA', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $clientRhA->request('POST', '/api/rattachement_employes', $enteteRhA + [
            'json' => ['employe' => '/api/employes/' . $idEmployeA, 'etablissement' => '/api/etablissements/' . $this->idEtablissementA(), 'debut' => '2024-01-01'],
        ]);
        self::assertResponseIsSuccessful();
        $badgeA = $clientRhA->request('POST', '/api/personnel/employes/' . $idEmployeA . '/badges', $enteteRhA + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();

        $idEmployeB = $clientRhB->request('POST', '/api/employes', $enteteRhB + [
            'json' => ['nom' => 'InciB', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $clientRhB->request('POST', '/api/rattachement_employes', $enteteRhB + [
            'json' => ['employe' => '/api/employes/' . $idEmployeB, 'etablissement' => '/api/etablissements/' . $this->idEtablissementB(), 'debut' => '2024-01-01'],
        ]);
        self::assertResponseIsSuccessful();
        $badgeB = $clientRhB->request('POST', '/api/personnel/employes/' . $idEmployeB . '/badges', $enteteRhB + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();

        $clientAccueil->request('POST', '/api/personnel/badges/' . $badgeA['id'] . '/declarer-incident', $enteteAccueil + [
            'json' => ['motif' => 'Perdu (A)'],
        ]);
        self::assertResponseIsSuccessful();

        $clientRhB->request('POST', '/api/personnel/badges/' . $badgeB['id'] . '/declarer-incident', $enteteRhB + [
            'json' => ['motif' => 'Perdu (B)'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $declarationA = $em->getRepository(\App\Acces\Entity\DeclarationPerteVol::class)
            ->findOneBy(['support' => $em->getRepository(BadgeStaff::class)->find($badgeA['id'])->getSupport()]);
        $declarationB = $em->getRepository(\App\Acces\Entity\DeclarationPerteVol::class)
            ->findOneBy(['support' => $em->getRepository(BadgeStaff::class)->find($badgeB['id'])->getSupport()]);
        self::assertNotNull($declarationA);
        self::assertNotNull($declarationB);

        // Le lecteur (affecté sur A uniquement) ne voit que la déclaration de A.
        [$clientLecture, $enteteLecture] = $this->lectureSurA();
        $reponse = $clientLecture->request('GET', '/api/personnel/declarations-incident', $enteteLecture)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'];
        $ids = array_map(static fn (array $d): string => $d['id'], $membres);

        self::assertContains((string) $declarationA->getId(), $ids);
        self::assertNotContains((string) $declarationB->getId(), $ids, 'RG-SOCLE-05 : la déclaration d\'incident d\'un autre établissement n\'est jamais visible.');

        // Idem en lecture directe par id.
        $clientLecture->request('GET', '/api/personnel/declarations-incident/' . $declarationB->getId(), $enteteLecture);
        self::assertResponseStatusCodeSame(404, 'RG-SOCLE-05 : lecture directe d\'une déclaration hors périmètre refusée.');
    }
}
