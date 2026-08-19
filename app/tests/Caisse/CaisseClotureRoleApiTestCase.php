<?php

declare(strict_types=1);

namespace App\Tests\Caisse;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Caisse\DataFixtures\CaisseClotureRoleFixtures;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Base des tests d'API « Clôture de caisse (Z) à rôle gradué » (spec-caisse-cloture-role.md §6.2) :
 * schéma recréé et fixtures socle + offre + vente + `CaisseClotureRoleFixtures` rechargées avant
 * chaque test. Autonome (aucune modification de `App\Tests\Vente\VenteApiTestCase`, partagée par
 * d'autres suites M2 déjà vertes — plan §0 n°8) : fournit ses propres raccourcis d'authentification
 * pour les trois profils à droits différenciés (Caissier / Régisseur A / Régisseur B).
 */
abstract class CaisseClotureRoleApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, CaisseClotureRoleFixtures::class] as $classe) {
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

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} caissier (caisse.cloturer seul), établissement A */
    protected function caissierSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, CaisseClotureRoleFixtures::CAISSIER_EMAIL, CaisseClotureRoleFixtures::CAISSIER_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} régisseur (voir_z + voir_ecart), établissement A */
    protected function regisseurSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, CaisseClotureRoleFixtures::REGISSEUR_EMAIL, CaisseClotureRoleFixtures::REGISSEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} régisseur B (voir_z + voir_ecart), établissement B uniquement */
    protected function regisseurSurB(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, CaisseClotureRoleFixtures::REGISSEUR_B_EMAIL, CaisseClotureRoleFixtures::REGISSEUR_B_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]];

        return [$client, $entete, $idB];
    }

    /**
     * Ouvre une session de caisse (nécessite `caisse.ouvrir`, porté par l'admin) et renvoie sa
     * représentation JSON. Les tests de ce module font ouvrir la session par l'admin puis la
     * clôturer par le profil sous test (caissier/régisseur) : `caisse.cloturer` n'est pas restreint
     * à l'auteur de l'ouverture.
     *
     * @return array<string, mixed>
     */
    protected function ouvrirSession(string $fond = '50.00'): array
    {
        [$client, $entete] = $this->adminSurA();

        return $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
                'caisse' => '/api/caisses/' . $this->idCaisse(),
                'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => $fond,
            ],
        ])->toArray();
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idPointDeVente(): string
    {
        return (string) $this->entite(PointDeVente::class, ['libelle' => VenteFixtures::PDV_LIBELLE])->getId();
    }

    protected function idCaisse(): string
    {
        return (string) $this->entite(Caisse::class, ['libelle' => VenteFixtures::CAISSE_LIBELLE])->getId();
    }

    protected function idAdmin(): string
    {
        return (string) $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId();
    }

    /**
     * Entête PATCH (API Platform n'accepte que `application/merge-patch+json`).
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
