<?php

declare(strict_types=1);

namespace App\Tests\Acces;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
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
 * Base des tests d'API L3 : schéma recréé et fixtures socle + offre + vente + accès rechargées avant
 * chaque test. Fournit des raccourcis pour authentifier l'admin sur l'établissement A et pour
 * résoudre les objets de la topologie de démonstration (espace/contrôleur/équipement/support/droit).
 */
abstract class AccesApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, AccesFixtures::class] as $classe) {
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

    protected function idEspaceAcces(): string
    {
        return (string) $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE])->getId();
    }

    protected function idControleur(): string
    {
        return (string) $this->entite(Controleur::class, ['libelle' => AccesFixtures::CONTROLEUR_LIBELLE])->getId();
    }

    protected function idEquipement(): string
    {
        return (string) $this->entite(Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE])->getId();
    }

    protected function idSupport(): string
    {
        return (string) $this->entite(Support::class, ['identifiant' => AccesFixtures::SUPPORT_IDENTIFIANT])->getId();
    }

    protected function idDroit(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $droit = $em->getRepository(DroitAcces::class)->findOneBy([]);
        self::assertNotNull($droit);

        return (string) $droit->getId();
    }

    protected function idAdmin(): string
    {
        return (string) $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId();
    }

    /** En-tête d'authentification du `Terminal` de démonstration (plan-acces-terminal.md §7 Lot E T19). */
    protected function terminalEntete(?string $secret = null): array
    {
        return ['headers' => ['Authorization' => 'Bearer ' . ($secret ?? AccesFixtures::TERMINAL_SECRET)]];
    }

    protected function idTerminal(): string
    {
        return (string) $this->entite(\App\Acces\Entity\Terminal::class, ['nom' => AccesFixtures::TERMINAL_NOM])->getId();
    }

    // --- Raccourcis M2 (Vente & Caisse) — même patron que `App\Tests\Vente\VenteApiTestCase`, dupliqué
    // ici (comme `App\Tests\Crm\CrmApiTestCase`) pour composer un scénario « vente → appairage →
    // recharge » (CQ-1) sans faire dépendre les tests L3 de la base de tests L2. ---

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
