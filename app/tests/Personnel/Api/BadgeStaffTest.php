<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Personnel\Entity\BadgeStaff;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Badge staff = Support + DroitAcces (RG-PERSO-06/07, CA-8) : mode `shifts_uniquement` (fenêtre
 * bornée au shift ± marge) vs `permanent` (aucune fenêtre).
 */
final class BadgeStaffTest extends PersonnelApiTestCase
{
    public function testModeShiftsUniquementValideSeulementPendantCreneau(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Shift', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $this->rattacher($clientRh, $enteteRh, $idEmploye, $this->idEtablissementA());

        $badge = $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'shifts_uniquement',
                'margeAvantApres' => 15,
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('actif', $badge['statut']);

        // Sans aucun shift, la fenêtre est dans le passé (refus par ResolveurMarges, décision n°3).
        $droit = $this->droitDuBadge($badge['id']);
        self::assertNotNull($droit->getFenetreDebut());
        self::assertLessThan(new \DateTimeImmutable(), $droit->getFenetreFin());

        // Un shift confirmé pousse la fenêtre sur sa propre plage (± marge appliquée par ResolveurMarges).
        $creneau = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Accueil',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
            ],
        ])->toArray()['id'];

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau, 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseIsSuccessful();

        $droitApres = $this->droitDuBadge($badge['id']);
        self::assertEquals(new \DateTimeImmutable('2026-09-01T08:00:00+00:00'), $droitApres->getFenetreDebut());
        self::assertEquals(new \DateTimeImmutable('2026-09-01T12:00:00+00:00'), $droitApres->getFenetreFin());
        self::assertSame(15, $droitApres->getMargeAvanceDefaut());
        self::assertSame(15, $droitApres->getMargeRetardDefaut());
    }

    public function testModePermanentValideEnContinu(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Permanent', 'prenom' => 'Test', 'poste' => 'Responsable', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $this->rattacher($clientRh, $enteteRh, $idEmploye, $this->idEtablissementA());

        $badge = $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $droit = $this->droitDuBadge($badge['id']);
        self::assertNull($droit->getFenetreDebut(), 'Mode permanent : aucune fenêtre, aucune contrainte (§4.2 plan L3).');
        self::assertNull($droit->getFenetreFin());
    }

    public function testUnSeulBadgeActifParCoupleEmployeEtablissement(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Unique', 'prenom' => 'Badge', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $this->rattacher($clientRh, $enteteRh, $idEmploye, $this->idEtablissementA());

        $corps = [
            'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
            'modeHoraire' => 'permanent',
            'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
        ];

        $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + ['json' => $corps]);
        self::assertResponseIsSuccessful();

        $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + ['json' => $corps]);
        self::assertResponseStatusCodeSame(409, 'décision n°2 du plan : 1 badge actif par couple (Employé, Établissement).');
    }

    /**
     * RG-PERSO-09 : un badge ne peut être émis que pour un employé ayant un rattachement actif sur
     * l'établissement ciblé (correctif cloisonnement, cf. rapport de revue).
     *
     * @param array<string, mixed> $entete
     */
    private function rattacher(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $idEmploye, string $idEtablissement): void
    {
        $client->request('POST', '/api/rattachement_employes', $entete + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'etablissement' => '/api/etablissements/' . $idEtablissement,
                'debut' => '2024-01-01',
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    private function droitDuBadge(string $idBadge): \App\Acces\Entity\DroitAcces
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $badge = $em->getRepository(BadgeStaff::class)->find($idBadge);
        self::assertNotNull($badge);
        $droit = $badge->getDroitAcces();
        self::assertNotNull($droit);

        return $droit;
    }
}
