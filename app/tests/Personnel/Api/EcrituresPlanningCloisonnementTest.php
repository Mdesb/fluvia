<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Personnel\DataFixtures\PersonnelFixtures;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Correctif — Écritures planning par UUID brut sans contrôle établissement (revue de sécurité,
 * item 3) : `AffecterEmployeProcessor`, `CreerCreneauTravailProcessor` et `DeclarerAbsenceProcessor`
 * résolvent des entités par UUID/IRI brut du corps de requête sans jamais recouper l'établissement
 * effectivement ciblé contre le périmètre réel de l'agent.
 *
 * Utilise un agent planning restreint à l'établissement A uniquement (contrairement au planning de
 * fixture, affecté sur A **et** B) pour reproduire un agent n'ayant aucun droit sur B.
 */
final class EcrituresPlanningCloisonnementTest extends PersonnelApiTestCase
{
    public function testAffecterEmployeRefuseSiCreneauDunAutreEtablissement(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();
        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'Cible', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        [$clientPlanningB, $entetePlanningB] = $this->authentifie(PersonnelFixtures::EMAIL_PLANNING, $this->idEtablissementB());
        $creneauB = $clientPlanningB->request('POST', '/api/personnel/creneaux-travail', $entetePlanningB + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'libellePoste' => 'Poste B',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
            ],
        ])->toArray()['id'];

        // Agent restreint à A, créé en dernier : les assertions `self::assertResponse*()` d'API
        // Platform portent sur le **dernier** client créé via `createClient()`, pas sur le dernier
        // ayant émis une requête (cf. `ApiTestCase::createClient()` → `self::getClient(...)`).
        [$emailPlanningA, $motDePasse] = $this->creerPlanningRestreintA();
        [$clientPlanningA, $entetePlanningA] = $this->authentifieAvecMotDePasse($emailPlanningA, $this->idEtablissementA(), $motDePasse);

        // Agent restreint à A (X-Etablissement: A) tente d'affecter un employé à un créneau de B.
        $clientPlanningA->request('POST', '/api/personnel/affectations', $entetePlanningA + [
            'json' => ['creneauTravail' => '/api/creneau_travails/' . $creneauB, 'employe' => '/api/employes/' . $idEmploye],
        ]);

        self::assertResponseStatusCodeSame(403, 'RG-SOCLE-05 : le créneau appartient à un établissement hors du périmètre de l\'agent.');
    }

    public function testCreationCreneauRefuseeSiEtablissementDuCorpsHorsPerimetre(): void
    {
        [$emailPlanningA, $motDePasse] = $this->creerPlanningRestreintA();
        [$clientPlanningA, $entetePlanningA] = $this->authentifieAvecMotDePasse($emailPlanningA, $this->idEtablissementA(), $motDePasse);

        // X-Etablissement: A, corps.etablissement: B, agent sans droit sur B → refusé.
        $clientPlanningA->request('POST', '/api/personnel/creneaux-travail', $entetePlanningA + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'libellePoste' => 'Poste B',
                'debut' => '2026-09-01T08:00:00+00:00',
                'fin' => '2026-09-01T12:00:00+00:00',
            ],
        ]);

        self::assertResponseStatusCodeSame(403, 'RG-SOCLE-05 : établissement du corps hors périmètre de l\'agent, malgré un en-tête X-Etablissement valide.');
    }

    public function testDeclarationAbsenceRefuseeSiEmployeRattacheAUnAutreEtablissement(): void
    {
        [$clientRhB, $enteteRhB] = $this->rhSurB();

        $idEmploye = $clientRhB->request('POST', '/api/employes', $enteteRhB + [
            'json' => ['nom' => 'RattacheB', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];
        $clientRhB->request('POST', '/api/rattachement_employes', $enteteRhB + [
            'json' => ['employe' => '/api/employes/' . $idEmploye, 'etablissement' => '/api/etablissements/' . $this->idEtablissementB(), 'debut' => '2024-01-01'],
        ]);
        self::assertResponseIsSuccessful();

        // Agent restreint à A, créé en dernier (cf. commentaire du test précédent).
        [$emailPlanningA, $motDePasse] = $this->creerPlanningRestreintA();
        [$clientPlanningA, $entetePlanningA] = $this->authentifieAvecMotDePasse($emailPlanningA, $this->idEtablissementA(), $motDePasse);

        // Agent planning restreint à A tente de déclarer une absence pour un employé rattaché
        // exclusivement à B.
        $clientPlanningA->request('POST', '/api/personnel/absences', $entetePlanningA + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'debut' => '2026-09-01T00:00:00+00:00',
                'fin' => '2026-09-08T00:00:00+00:00',
                'type' => 'conge',
            ],
        ]);

        self::assertResponseStatusCodeSame(403, 'RG-SOCLE-05 : employé rattaché à un établissement hors du périmètre de l\'agent.');
    }

    /** @return array{0: string, 1: string} email, mot de passe */
    private function creerPlanningRestreintA(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $rolePlanning = $em->getRepository(Role::class)->findOneBy(['nom' => 'Personnel Responsable Planning']);
        self::assertNotNull($rolePlanning);
        $etabA = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => PersonnelFixtures::ETAB_A_NOM]);

        $email = 'personnel.planning.restreint.a@itcotation.com';
        $motDePasse = 'PlanningRestreintA#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Planning restreint A')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);
        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($rolePlanning)->setEtablissement($etabA));
        $em->flush();

        return [$email, $motDePasse];
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>} */
    private function authentifieAvecMotDePasse(string $email, ?string $idEtablissement, string $motDePasse): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $entete = ['auth_bearer' => $token];
        if ($idEtablissement !== null) {
            $entete['headers'] = [ContexteEtablissement::HEADER => $idEtablissement];
        }

        return [$client, $entete];
    }
}
