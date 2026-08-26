<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Organisation\Entity\Etablissement;
use App\Personnel\DataFixtures\PersonnelFixtures;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Non-régression du cloisonnement (D3/D8) de `POST /personnel/declarations-incident/{id}/annuler`
 * (`AnnulerDeclarationIncidentBadgeProcessor`). L'opération est `read: false` : la déclaration est
 * résolue depuis l'identifiant de l'URI par un `find()` direct, hors des extensions. Annuler une
 * déclaration réactive le badge (RG-ACC-07) — un agent habilité sur B ne doit pas pouvoir réactiver un
 * badge de A en visant sa déclaration → 404.
 */
final class AnnulerDeclarationIncidentCloisonnementTest extends PersonnelApiTestCase
{
    public function testAnnulerLaDeclarationDunBadgeDunAutreEtablissementRenvoie404(): void
    {
        [$clientRhA, $enteteRhA] = $this->rhSurA();
        $idDeclaration = $this->declarerIncidentSurBadgeA($clientRhA, $enteteRhA);

        // RH habilité (personnel.gerer_badge) mais affecté à B seulement.
        [$emailRhB, $motDePasse] = $this->creerRhSurBSeul();
        $clientRhB = static::createClient();
        $enteteRhB = [
            'auth_bearer' => $this->jeton($clientRhB, $emailRhB, $motDePasse),
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissementB()],
        ];

        $reponse = $clientRhB->request('POST', '/api/personnel/declarations-incident/' . $idDeclaration . '/annuler', $enteteRhB + ['json' => []]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /** Contrôle positif — le RH de A annule bien la déclaration du badge de A (pas de 404). */
    public function testAnnulerSaPropreDeclarationNestPasRefusee(): void
    {
        [$clientRhA, $enteteRhA] = $this->rhSurA();
        $idDeclaration = $this->declarerIncidentSurBadgeA($clientRhA, $enteteRhA);

        $reponse = $clientRhA->request('POST', '/api/personnel/declarations-incident/' . $idDeclaration . '/annuler', $enteteRhA + ['json' => []]);

        self::assertNotSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /**
     * Crée un employé + badge sur A, déclare l'incident, et renvoie l'id de la déclaration.
     *
     * @param array<string, mixed> $entete
     */
    private function declarerIncidentSurBadgeA(Client $client, array $entete): string
    {
        $idEmploye = $client->request('POST', '/api/employes', $entete + [
            'json' => ['nom' => 'Incident', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        $client->request('POST', '/api/rattachement_employes', $entete + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'debut' => '2024-01-01',
            ],
        ]);

        $badge = $client->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $declaration = $client->request('POST', '/api/personnel/badges/' . $badge['id'] . '/declarer-incident', $entete + [
            'json' => ['motif' => 'Perte lors du service.'],
        ])->toArray();
        self::assertNotNull($declaration['id'] ?? null, 'La déclaration doit exposer son id.');

        return (string) $declaration['id'];
    }

    /** @return array{0: string, 1: string} email, mot de passe d'un RH (gerer_badge) affecté à B seul. */
    private function creerRhSurBSeul(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $roleRh = $em->getRepository(Role::class)->findOneBy(['nom' => 'Personnel Administrateur RH']);
        self::assertInstanceOf(Role::class, $roleRh);
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => PersonnelFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);

        $email = 'personnel.rh.b.' . uniqid() . '@itcotation.com';
        $motDePasse = 'RhB#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('RH B')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);
        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($roleRh)->setEtablissement($etabB));
        $em->flush();

        return [$email, $motDePasse];
    }
}
