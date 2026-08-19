<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Personnel\DataFixtures\PersonnelFixtures;
use App\Personnel\Entity\Employe;
use App\Personnel\Enum\TypeContrat;
use App\Securite\Entity\Utilisateur;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Correctif — `lire_soi` manquant sur `Qualification`/`CreneauTravail`/`AffectationTravail` (revue
 * de sécurité, item 4) : un employé authentifié détenant uniquement `personnel.lire_soi` (fixture
 * « Employé Soi-même ») doit pouvoir lire ses propres enregistrements — et uniquement les siens.
 */
final class LireSoiPersonnelTest extends PersonnelApiTestCase
{
    public function testEmployeVoitSesPropresQualificationsEtPasCellesDesAutres(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        $idEmployeSoi = (string) $this->creerEmployeLieAUtilisateurSoi()->getId();
        $idAutreEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Autre', 'prenom' => 'Employe', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        $qualifSoi = $clientRh->request('POST', '/api/qualifications', $enteteRh + [
            'json' => ['employe' => '/api/employes/' . $idEmployeSoi, 'type' => 'MNS', 'dateValidite' => '2030-01-01'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $qualifAutre = $clientRh->request('POST', '/api/qualifications', $enteteRh + [
            'json' => ['employe' => '/api/employes/' . $idAutreEmploye, 'type' => 'BNSSA', 'dateValidite' => '2030-01-01'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        [$clientSoi, $enteteSoi] = $this->employeSoi();
        $reponse = $clientSoi->request('GET', '/api/qualifications', $enteteSoi)->toArray();
        self::assertResponseIsSuccessful();
        $membres = $reponse['member'] ?? $reponse['hydra:member'];
        $ids = array_map(static fn (array $q): string => $q['id'], $membres);

        self::assertContains($qualifSoi['id'], $ids);
        self::assertNotContains($qualifAutre['id'], $ids, 'personnel.lire_soi : un employé ne voit que ses propres qualifications.');
    }

    public function testEmployeVoitSesPropresCreneauxEtPasCeuxDesAutres(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        [$clientPlanning, $entetePlanning] = $this->planningSurA();

        $idEmployeSoi = (string) $this->creerEmployeLieAUtilisateurSoi()->getId();
        $idAutreEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Autre', 'prenom' => 'Employe', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        $creneauSoi = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Poste Soi',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
            ],
        ])->toArray()['id'];
        $creneauAutre = $clientPlanning->request('POST', '/api/personnel/creneaux-travail', $entetePlanning + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'libellePoste' => 'Poste Autre',
                'debut' => '2026-09-01T14:00:00+00:00',
                'fin' => '2026-09-01T18:00:00+00:00',
            ],
        ])->toArray()['id'];

        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneauSoi, 'employe' => '/api/employes/' . $idEmployeSoi],
        ]);
        self::assertResponseIsSuccessful();
        $clientPlanning->request('POST', '/api/personnel/affectations', $entetePlanning + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneauAutre, 'employe' => '/api/employes/' . $idAutreEmploye],
        ]);
        self::assertResponseIsSuccessful();

        [$clientSoi, $enteteSoi] = $this->employeSoi();

        $reponseCreneaux = $clientSoi->request('GET', '/api/creneau_travails', $enteteSoi)->toArray();
        self::assertResponseIsSuccessful();
        $membresCreneaux = $reponseCreneaux['member'] ?? $reponseCreneaux['hydra:member'];
        $idsCreneaux = array_map(static fn (array $c): string => $c['id'], $membresCreneaux);

        self::assertContains($creneauSoi, $idsCreneaux);
        self::assertNotContains($creneauAutre, $idsCreneaux, 'personnel.lire_soi : un employé ne voit que ses propres créneaux.');

        $reponseAffectations = $clientSoi->request('GET', '/api/affectation_travails', $enteteSoi)->toArray();
        self::assertResponseIsSuccessful();
        $membresAffectations = $reponseAffectations['member'] ?? $reponseAffectations['hydra:member'];

        self::assertCount(1, $membresAffectations, 'personnel.lire_soi : un employé ne voit que ses propres affectations (pas celle de l\'autre employé).');
    }

    private function creerEmployeLieAUtilisateurSoi(): Employe
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $utilisateur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => PersonnelFixtures::EMAIL_EMPLOYE_SOI]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        $employe = (new Employe())->setUtilisateur($utilisateur)
            ->setNom('Soi')->setPrenom('Employe')->setPoste('Agent')
            ->setTypeContrat(TypeContrat::Cdi)->setDateEntree(new \DateTimeImmutable('2024-01-01'));
        $em->persist($employe);
        $em->flush();

        return $employe;
    }
}
