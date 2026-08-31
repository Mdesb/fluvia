<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Facturation\DataFixtures\FacturationFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API Facturation : schéma recréé et fixtures socle + offre + vente + accès + compta
 * + facturation rechargées avant chaque test (même patron que `ComptaApiTestCase`).
 */
abstract class FacturationApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, AccesFixtures::class, ComptaFixtures::class, FacturationFixtures::class] as $classe) {
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

    protected function idTauxTva(string $libelle): string
    {
        return (string) $this->entite(TauxTva::class, ['libelle' => $libelle])->getId();
    }

    protected function idAdmin(): string
    {
        return (string) $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId();
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

    protected function idPointDeVente(): string
    {
        return (string) $this->entite(PointDeVente::class, ['libelle' => VenteFixtures::PDV_LIBELLE])->getId();
    }

    protected function idCaisse(): string
    {
        return (string) $this->entite(Caisse::class, ['libelle' => VenteFixtures::CAISSE_LIBELLE])->getId();
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
     * Déroule un cycle de vente complet (session -> panier -> paiement -> validation) et renvoie la
     * vente validée (JSON) — même patron que `ComptaApiTestCase::creerVenteValidee`.
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
     * Corps par défaut d'une facture directe brouillon (destinataire personne morale + une ligne au
     * taux normal 20 %) — utilisable tel quel par `POST /api/factures`.
     *
     * @return array<string, mixed>
     */
    protected function corpsFactureDirecte(float $prixUnitaireHT = 100.0, int $quantite = 1): array
    {
        return [
            'destinataire' => [
                'type' => 'personne_morale',
                'raisonSociale' => 'Collectivité Test',
                'siret' => '12345678900011',
                'adresse' => ['rue' => '1 rue de Test', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
            ],
            'lignes' => [
                [
                    'designation' => 'Prestation de test',
                    'quantite' => $quantite,
                    'prixUnitaireHT' => number_format($prixUnitaireHT, 2, '.', ''),
                    'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva('Taux normal 20 %'),
                ],
            ],
            'dateEcheance' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
        ];
    }
}
