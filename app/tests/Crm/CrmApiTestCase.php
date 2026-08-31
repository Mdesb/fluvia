<?php

declare(strict_types=1);

namespace App\Tests\Crm;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client as CrmClient;
use App\Crm\Entity\Famille;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API M4 (L5) : schéma recréé et fixtures socle + offre + vente + compta + CRM
 * rechargées avant chaque test. Fournit des raccourcis d'authentification et un cycle de vente
 * complet (pour les tests PMV ↔ paiement M2).
 */
abstract class CrmApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, ComptaFixtures::class, CrmFixtures::class] as $classe) {
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

    /** @return array{0: Client, 1: array<string, mixed>} client, entête auth+étab, agent CRM limité (sans fusionner/rgpd_gerer/parametrer) */
    protected function agentSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, CrmFixtures::AGENT_EMAIL, CrmFixtures::AGENT_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete];
    }

    /** @return array{0: Client, 1: array<string, mixed>} client affecté uniquement au Groupe B (cloisonnement) */
    protected function agentSurGroupeB(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, CrmFixtures::AGENT_B_EMAIL, CrmFixtures::AGENT_B_MDP);
        $idC = $this->idEtablissement(CrmFixtures::ETAB_C_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idC]];

        return [$client, $entete];
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idAdmin(): string
    {
        return (string) $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId();
    }

    protected function idPayeur(): string
    {
        return (string) $this->entite(CrmClient::class, ['email' => CrmFixtures::PAYEUR_EMAIL])->getId();
    }

    protected function idEnfant(): string
    {
        return (string) $this->entite(CrmClient::class, ['prenom' => CrmFixtures::ENFANT_PRENOM])->getId();
    }

    protected function idConjoint(): string
    {
        return (string) $this->entite(CrmClient::class, ['prenom' => CrmFixtures::CONJOINT_PRENOM])->getId();
    }

    protected function idFamille(): string
    {
        return (string) $this->entite(Famille::class, ['libelle' => CrmFixtures::FAMILLE_LIBELLE])->getId();
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
     * Crée une vente avec le produit « Entrée unitaire piscine », rattache un client, mais ne
     * l'encaisse/valide pas — laisse l'appelant choisir le moyen de paiement (PMV, etc.).
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function creerVenteAvecClient(Client $client, array $entete, string $clientId, int $quantite = 1, ?string $sessionId = null): array
    {
        if ($sessionId === null) {
            $session = $this->ouvrirSession($client, $entete);
            $sessionId = $session['id'];
        }
        $vente = $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionId],
        ])->toArray();

        // RattacherClientProcessor (M2) attend un UUID brut (pas une IRI) pour « client ».
        $clientIdBrut = preg_replace('#^.*/#', '', $clientId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/client', $entete + [
            'json' => ['client' => $clientIdBrut],
        ]);

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => $quantite,
            ],
        ]);

        return $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
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
