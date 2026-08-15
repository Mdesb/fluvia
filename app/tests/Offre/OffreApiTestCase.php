<?php

declare(strict_types=1);

namespace App\Tests\Offre;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Base des tests d'API M1 : schéma recréé et fixtures socle + offre rechargées avant chaque test.
 */
abstract class OffreApiTestCase extends ApiTestCase
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
        // FK_CHECKS désactivé le temps du drop/create (nombreuses tables inter-référencées) : évite les
        // échecs d'ordonnancement DROP/CREATE observés après l'introduction du schéma recouvrement_*.
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        /** @var SocleFixtures $socle */
        $socle = $container->get(SocleFixtures::class);
        $socle->load($em);

        /** @var OffreFixtures $offre */
        $offre = $container->get(OffreFixtures::class);
        $offre->load($em);

        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        $reponse = $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ]);

        return $reponse->toArray()['token'];
    }

    /** @return array{0: Client, 1: string, 2: string} client, token admin, id établissement A */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        return [$client, $token, $this->idEtablissement(SocleFixtures::ETAB_A_NOM)];
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idProduit(string $libelleRecherche): string
    {
        return (string) $this->entite(Produit::class, ['libelleRecherche' => $libelleRecherche])->getId();
    }

    protected function idType(string $code): string
    {
        return (string) $this->entite(TypeProduit::class, ['code' => $code])->getId();
    }

    protected function idTarif(string $nom): string
    {
        return (string) $this->entite(TypeTarif::class, ['nom' => $nom])->getId();
    }

    protected function idSaison(string $nom): string
    {
        return (string) $this->entite(Saison::class, ['nom' => $nom])->getId();
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
