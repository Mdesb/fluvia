<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Personnel\Entity\BadgeStaff;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Passage badge staff au tourniquet (RG-PERSO-07, CA-9) : validé par le **même moteur** que pour un
 * client (`ValidationPassageHandler`, réutilisé tel quel), réutilise `SimulateurAccesAdapter`.
 */
final class BadgeStaffPassageTest extends PersonnelApiTestCase
{
    public function testPassageEmployeValideParMemeMoteurQueClient(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Passage', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
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

        $maintenant = new \DateTimeImmutable();
        $creneau = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Accueil',
                'debut' => $maintenant->modify('-1 hour')->format(DATE_ATOM),
                'fin' => $maintenant->modify('+1 hour')->format(DATE_ATOM),
            ],
        ])->toArray()['id'];

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneau, 'employe' => '/api/employes/' . $idEmploye],
        ]);
        self::assertResponseIsSuccessful();

        $identifiant = $this->identifiantSupport($badge['id']);

        $clientRh->request('POST', '/api/acces/passages', $enteteRh + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => $identifiant,
                'horodatage' => $maintenant->format(DATE_ATOM),
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('valide', $clientRh->getResponse()->toArray()['resultat'], 'CA-9 : le badge staff est validé par le même moteur que pour un client.');
    }

    public function testPassageRefuseHorsFenetreHoraire(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'HorsFenetre', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $this->rattacher($clientRh, $enteteRh, $idEmploye, $this->idEtablissementA());

        // Aucun shift : la fenêtre est mise dans le passé (décision n°3) — le badge existe mais ne
        // peut jamais valider un passage tant qu'aucun shift n'est planifié.
        $badge = $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'shifts_uniquement',
                'margeAvantApres' => 15,
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();

        $identifiant = $this->identifiantSupport($badge['id']);

        $clientRh->request('POST', '/api/acces/passages', $enteteRh + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => $identifiant,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('refuse', $clientRh->getResponse()->toArray()['resultat'], 'CA-9 : hors fenêtre horaire, le passage est refusé.');
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

    private function identifiantSupport(string $idBadge): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $badge = $em->getRepository(BadgeStaff::class)->find($idBadge);
        self::assertNotNull($badge);
        $support = $badge->getSupport();
        self::assertNotNull($support);

        return $support->getIdentifiant();
    }
}
