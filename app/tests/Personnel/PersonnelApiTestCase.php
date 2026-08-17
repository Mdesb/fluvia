<?php

declare(strict_types=1);

namespace App\Tests\Personnel;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Etablissement;
use App\Personnel\DataFixtures\PersonnelFixtures;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Base des tests d'API du module Personnel : schéma recréé et `PersonnelFixtures` (autonome)
 * rechargées avant chaque test. Fournit des raccourcis d'authentification par profil et de
 * résolution d'id d'entité (même patron que `ReportingApiTestCase`/`AccesApiTestCase`).
 */
abstract class PersonnelApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $fixture = $container->get(PersonnelFixtures::class);
        \assert($fixture instanceof PersonnelFixtures);
        $fixture->load($em);

        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse = PersonnelFixtures::MDP): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function authentifie(string $email, ?string $idEtablissement = null): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, $email);
        $entete = ['auth_bearer' => $token];
        if ($idEtablissement !== null) {
            $entete['headers'] = [ContexteEtablissement::HEADER => $idEtablissement];
        }

        return [$client, $entete];
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function rhSurA(): array
    {
        return $this->authentifie(PersonnelFixtures::EMAIL_RH, $this->idEtablissementA());
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function rhSurB(): array
    {
        return $this->authentifie(PersonnelFixtures::EMAIL_RH, $this->idEtablissementB());
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function planningSurA(): array
    {
        return $this->authentifie(PersonnelFixtures::EMAIL_PLANNING, $this->idEtablissementA());
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function lectureSurA(): array
    {
        return $this->authentifie(PersonnelFixtures::EMAIL_LECTURE, $this->idEtablissementA());
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function accueilSurA(): array
    {
        return $this->authentifie(PersonnelFixtures::EMAIL_ACCUEIL, $this->idEtablissementA());
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function employeSoi(): array
    {
        return $this->authentifie(PersonnelFixtures::EMAIL_EMPLOYE_SOI, $this->idEtablissementA());
    }

    protected function idEtablissementA(): string
    {
        return $this->idEtablissement(PersonnelFixtures::ETAB_A_NOM);
    }

    protected function idEtablissementB(): string
    {
        return $this->idEtablissement(PersonnelFixtures::ETAB_B_NOM);
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idEspaceAcces(): string
    {
        return (string) $this->entite(EspaceAcces::class, ['libelle' => PersonnelFixtures::ESPACE_LIBELLE])->getId();
    }

    protected function idControleur(): string
    {
        return (string) $this->entite(Controleur::class, ['libelle' => PersonnelFixtures::CONTROLEUR_LIBELLE])->getId();
    }

    protected function idEquipement(): string
    {
        return (string) $this->entite(Equipement::class, ['libelle' => PersonnelFixtures::EQUIPEMENT_LIBELLE])->getId();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $classe
     * @param array<string, mixed> $criteres
     *
     * @return T
     */
    protected function entite(string $classe, array $criteres): object
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite, sprintf('%s introuvable (%s).', $classe, json_encode($criteres)));

        return $entite;
    }
}
