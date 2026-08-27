<?php

declare(strict_types=1);

namespace App\Tests\Piscine;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\EspaceAcces;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Piscine\DataFixtures\PiscineFixtures;
use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\Poss;
use App\Vente\DataFixtures\VenteFixtures;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API L6 : schéma recréé et fixtures socle + offre + vente + accès + piscine
 * rechargées avant chaque test. Fournit des raccourcis pour authentifier l'admin sur l'établissement
 * A et résoudre les objets du jeu de démonstration (Poss, Bassin, Casier).
 */
abstract class PiscineApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, AccesFixtures::class, PiscineFixtures::class] as $classe) {
            $fixture = $container->get($classe);
            $fixture->load($em);
        }

        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement A */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /**
     * Entête PATCH (Content-Type merge-patch fusionné dans le tableau `headers` existant — un simple
     * `$entete + ['headers' => [...]]` n'aurait aucun effet, l'opérateur `+` conservant la clé
     * `headers` déjà présente dans `$entete`).
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function entetePatch(array $entete): array
    {
        $entete['headers'] = ($entete['headers'] ?? []) + ['Content-Type' => 'application/merge-patch+json'];

        return $entete;
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idEspaceAcces(): string
    {
        return (string) $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE])->getId();
    }

    protected function idPoss(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $espace = $em->getRepository(EspaceAcces::class)->findOneBy(['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        $poss = $em->getRepository(Poss::class)->findOneBy(['espaceAcces' => $espace]);
        self::assertNotNull($poss, 'Poss de démonstration introuvable.');

        return (string) $poss->getId();
    }

    protected function idBassin(): string
    {
        return (string) $this->entite(Bassin::class, ['libelle' => PiscineFixtures::BASSIN_LIBELLE])->getId();
    }

    protected function idCasier(): string
    {
        return (string) $this->entite(Casier::class, ['numero' => PiscineFixtures::CASIER_NUMERO, 'zone' => PiscineFixtures::CASIER_ZONE])->getId();
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
