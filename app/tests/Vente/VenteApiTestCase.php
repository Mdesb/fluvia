<?php

declare(strict_types=1);

namespace App\Tests\Vente;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API M2 : schéma recréé et fixtures socle + offre + vente rechargées avant chaque
 * test. Fournit des raccourcis pour authentifier l'admin sur l'établissement A et pour dérouler le
 * cycle caisse (ouverture de session, composition de panier, encaissement, validation).
 */
abstract class VenteApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class] as $classe) {
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
     * Ouvre une session de caisse et renvoie sa représentation JSON.
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function ouvrirSession(Client $client, array $entete, string $fond = '50.00'): array
    {
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

    /**
     * Ouvre un panier (vente) sur une session.
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function creerVente(Client $client, array $entete, string $sessionId): array
    {
        return $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionId],
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
        return (string) $this->entite(\App\Securite\Entity\Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId();
    }

    protected function idProduit(string $libelleRecherche): string
    {
        return (string) $this->entite(Produit::class, ['libelleRecherche' => $libelleRecherche])->getId();
    }

    protected function idTarif(string $nom): string
    {
        return (string) $this->entite(TypeTarif::class, ['nom' => $nom])->getId();
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
