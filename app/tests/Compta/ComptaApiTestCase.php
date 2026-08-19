<?php

declare(strict_types=1);

namespace App\Tests\Compta;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Base des tests d'API M6 (L4) : schéma recréé et fixtures socle + offre + vente + accès + compta
 * rechargées avant chaque test. Fournit des raccourcis pour authentifier l'admin sur l'établissement
 * A et dérouler un cycle de vente complet jusqu'à validation (source des écritures, RG-COMPTA-04).
 */
abstract class ComptaApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, AccesFixtures::class, ComptaFixtures::class] as $classe) {
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

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idProfilExploitant(): string
    {
        return (string) $this->entite(ProfilExploitant::class, ['siren' => ComptaFixtures::PROFIL_SIREN])->getId();
    }

    protected function profilExploitant(): ProfilExploitant
    {
        return $this->entite(ProfilExploitant::class, ['siren' => ComptaFixtures::PROFIL_SIREN]);
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

    protected function idJournal(string $code): string
    {
        return (string) $this->entite(Journal::class, ['profilExploitant' => $this->profilExploitant()->getId(), 'code' => $code])->getId();
    }

    protected function idCompte(string $numero): string
    {
        return (string) $this->entite(CompteComptable::class, ['profilExploitant' => $this->profilExploitant()->getId(), 'numero' => $numero])->getId();
    }

    protected function idTauxTva(string $taux): string
    {
        return (string) $this->entite(TauxTva::class, ['profilExploitant' => $this->profilExploitant()->getId(), 'taux' => $taux])->getId();
    }

    protected function idTauxHorsChamp(): string
    {
        return (string) $this->entite(TauxTva::class, ['profilExploitant' => $this->profilExploitant()->getId(), 'libelle' => TauxTva::LIBELLE_HORS_CHAMP])->getId();
    }

    /**
     * Ouvre une période comptable (US-L4-10) couvrant les dates données — préalable exigé par la
     * saisie manuelle (§0.3 : contrairement au moteur ventes, aucune création silencieuse).
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function ouvrirPeriode(Client $client, array $entete, string $dateDebut, string $dateFin): array
    {
        return $client->request('POST', '/api/periode_comptables', $entete + [
            'json' => [
                'profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'dateDebut' => $dateDebut,
                'dateFin' => $dateFin,
            ],
        ])->toArray();
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
     * Déroule un cycle de vente complet (session -> panier -> paiement -> validation) sur le produit
     * « Entrée unitaire piscine » (M1) et renvoie la vente validée (JSON). Un seul point de vente
     * n'autorise qu'une session active à la fois (RG-M2-01) : passer `$sessionId` pour enchaîner
     * plusieurs ventes sur la même session déjà ouverte (sinon une nouvelle session est ouverte).
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function creerVenteValidee(Client $client, array $entete, int $quantite = 1, string $moyen = 'especes', ?string $sessionId = null): array
    {
        if ($sessionId === null) {
            $session = $this->ouvrirSession($client, $entete);
            $sessionId = $session['id'];
        }
        $vente = $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionId],
        ])->toArray();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => $quantite,
            ],
        ]);

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => $moyen],
        ]);

        return $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete)->toArray();
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
