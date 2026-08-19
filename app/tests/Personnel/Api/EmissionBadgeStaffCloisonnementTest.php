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
 * Correctif — Émission de badge staff cross-établissement (revue de sécurité, item 1) :
 * l'établissement du corps de requête n'était jamais recoupé contre l'établissement actif ni contre
 * un rattachement actif de l'employé (RG-PERSO-09).
 */
final class EmissionBadgeStaffCloisonnementTest extends PersonnelApiTestCase
{
    public function testEmissionRefuseeSiEtablissementDuCorpsHorsPerimetreDeLAgent(): void
    {
        [$clientRhFixture, $enteteRhFixture] = $this->rhSurA();
        $idEmploye = $clientRhFixture->request('POST', '/api/employes', $enteteRhFixture + [
            'json' => ['nom' => 'CrossEtab', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        // Agent RH restreint à l'établissement A uniquement (contrairement au RH de fixture, affecté
        // sur A **et** B) — reproduit un agent n'ayant aucun droit sur l'établissement ciblé. Créé en
        // dernier : les assertions `self::assertResponse*()` d'API Platform portent sur le **dernier**
        // client créé via `createClient()` (cf. `ApiTestCase::createClient()`), pas sur le dernier
        // ayant émis une requête.
        [$emailRhA, $motDePasse] = $this->creerRhRestreintA();
        [$clientRhA, $enteteRhA] = $this->authentifieAvecMotDePasse($emailRhA, $this->idEtablissementA(), $motDePasse);

        // X-Etablissement: A, corps.etablissement: B, agent sans droit sur B → refusé.
        $clientRhA->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRhA + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ]);

        self::assertResponseStatusCodeSame(403, 'RG-SOCLE-05 : établissement du corps hors périmètre de l\'agent, malgré un en-tête X-Etablissement valide.');
    }

    public function testEmissionRefuseeSansRattachementActifSurLEtablissementCible(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => ['nom' => 'SansRattachement', 'prenom' => 'Test', 'poste' => 'Agent', 'typeContrat' => 'cdi', 'dateEntree' => '2024-01-01'],
        ])->toArray()['id'];

        // Aucun RattachementEmploye créé sur A : RG-PERSO-09.
        $clientRh->request('POST', '/api/personnel/employes/' . $idEmploye . '/badges', $enteteRh + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'modeHoraire' => 'permanent',
                'espacesAutorises' => ['/api/espace_acces/' . $this->idEspaceAcces()],
            ],
        ]);

        self::assertResponseStatusCodeSame(422, 'RG-PERSO-09 : aucun badge sans rattachement actif de l\'employé sur l\'établissement ciblé.');
    }

    /** @return array{0: string, 1: string} email, mot de passe */
    private function creerRhRestreintA(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $roleRh = $em->getRepository(Role::class)->findOneBy(['nom' => 'Personnel Administrateur RH']);
        self::assertNotNull($roleRh);
        $etabA = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => PersonnelFixtures::ETAB_A_NOM]);

        $email = 'personnel.rh.restreint.a@itcotation.com';
        $motDePasse = 'RhRestreintA#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('RH restreint A')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);
        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($roleRh)->setEtablissement($etabA));
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
