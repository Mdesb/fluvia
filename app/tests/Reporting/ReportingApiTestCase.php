<?php

declare(strict_types=1);

namespace App\Tests\Reporting;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\DataFixtures\L11Fixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API M7 Reporting (L11) : schéma recréé et `L11Fixtures` (autonome) rechargées
 * avant chaque test. Fournit des raccourcis d'authentification par profil (site/région/groupe/
 * admin/non-contigu) et de résolution d'id d'entité (même patron que `ComptaApiTestCase`).
 */
abstract class ReportingApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        $fixture = $container->get(L11Fixtures::class);
        \assert($fixture instanceof L11Fixtures);
        $fixture->load($em);

        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse = L11Fixtures::MDP): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function authentifie(string $email): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, $email);

        return [$client, ['auth_bearer' => $token]];
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function authSite(): array
    {
        return $this->authentifie(L11Fixtures::EMAIL_SITE);
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function authRegion(): array
    {
        return $this->authentifie(L11Fixtures::EMAIL_REGION);
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function authGroupe(): array
    {
        return $this->authentifie(L11Fixtures::EMAIL_GROUPE);
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function authAdmin(): array
    {
        return $this->authentifie(L11Fixtures::EMAIL_ADMIN);
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function authNonContigu(): array
    {
        return $this->authentifie(L11Fixtures::EMAIL_NON_CONTIGU);
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idRegion(string $nom): string
    {
        return (string) $this->entite(Region::class, ['nom' => $nom])->getId();
    }

    protected function idGroupe(): string
    {
        return (string) $this->entite(Groupe::class, ['nom' => L11Fixtures::GROUPE_NOM])->getId();
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

    /** Exécute `reporting:agreger` pour le jour courant (via le service applicatif, pas le process CLI). */
    protected function agreger(): void
    {
        $container = static::getContainer();
        $agregateur = $container->get(\App\Reporting\Service\AgregateurMesuresService::class);
        $agregateur->agregerPeriode(\App\Reporting\ValueObject\Periode::jour());
    }

    /**
     * Crée un `TableauDeBord` (admin, `reporting.configurer`) référençant l'indicateur `CA`,
     * rattaché au niveau/entité donnés. Retourne son IRI.
     */
    protected function creerTableauDeBord(string $niveau, string $entiteIri): string
    {
        [$clientAdmin, $enteteAdmin] = $this->authAdmin();
        $idCa = $this->idIndicateur('CA');

        $payload = ['nom' => 'TDB test ' . uniqid(), $niveau => $entiteIri, 'niveau' => $niveau, 'indicateurs' => ['/api/indicateurs/' . $idCa]];

        $reponse = $clientAdmin->request('POST', '/api/tableau_de_bords', $enteteAdmin + ['json' => $payload]);
        self::assertResponseIsSuccessful();

        return '/api/tableau_de_bords/' . $reponse->toArray()['id'];
    }

    protected function idIndicateur(string $code): string
    {
        return (string) $this->entite(\App\Reporting\Entity\Indicateur::class, ['code' => $code])->getId();
    }
}
