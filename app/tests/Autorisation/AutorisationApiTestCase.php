<?php

declare(strict_types=1);

namespace App\Tests\Autorisation;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Autorisation\Entity\OperationSensible;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests du module `App\Autorisation`. Réutilise le socle M2 (schéma + fixtures
 * Socle/Offre/Vente) via `VenteApiTestCase`, et ajoute par-dessus (sans toucher aux fixtures
 * globales, hors périmètre de ce lot) : les 3 permissions `autorisation.{gerer,approuver,lire}`
 * (octroyées à l'administrateur groupe), le catalogue `OperationSensible` (`vente.annuler`/
 * `vente.rembourser`), et des helpers pour construire des caissiers/superviseurs de test et des
 * ventes validées à montant exact (prix forcé, RG-AUTZ tests).
 */
abstract class AutorisationApiTestCase extends VenteApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $roleAdmin = $em->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);

        // Octroi direct sur les objets en mémoire (même patron que VenteFixtures/OffreFixtures) —
        // ne PAS re-requêter les Permission juste persistées avant flush (ambiguïté de timing).
        foreach (['gerer', 'approuver', 'lire'] as $action) {
            $permission = (new Permission())->setModule('autorisation')->setAction($action);
            $em->persist($permission);
            if ($roleAdmin instanceof Role) {
                $roleAdmin->addPermission($permission);
            }
        }

        $em->persist((new OperationSensible())->setCode('vente.annuler')->setLibelle('Vente — Annulation')->setModuleAction('vente.annuler')->setActive(true));
        $em->persist((new OperationSensible())->setCode('vente.rembourser')->setLibelle('Vente — Remboursement')->setModuleAction('vente.rembourser')->setActive(true));

        $em->flush();

        self::ensureKernelShutdown();
    }

    /**
     * Crée un rôle « Caissier Test » (vente.* / caisse.* — réutilise les `Permission` déjà créées par
     * `VenteFixtures`) et un utilisateur affecté sur l'établissement donné.
     */
    protected function creerCaissier(string $email, ?Role $role = null): Utilisateur
    {
        return $this->creerUtilisateur($email, $role ?? $this->roleCaissier());
    }

    protected function creerSuperviseur(string $email): Utilisateur
    {
        return $this->creerUtilisateur($email, $this->roleSuperviseur());
    }

    protected function roleCaissier(): Role
    {
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Caissier Test']);
        if ($role instanceof Role) {
            return $role;
        }

        $permVente = $em->getRepository(Permission::class)->findOneBy(['module' => 'vente', 'action' => '*']);
        $permCaisse = $em->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => '*']);

        $role = (new Role())->setNom('Caissier Test');
        if ($permVente instanceof Permission) {
            $role->addPermission($permVente);
        }
        if ($permCaisse instanceof Permission) {
            $role->addPermission($permCaisse);
        }
        $em->persist($role);
        $em->flush();

        return $role;
    }

    protected function roleSuperviseur(): Role
    {
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Superviseur Test']);
        if ($role instanceof Role) {
            return $role;
        }

        $permApprouver = $em->getRepository(Permission::class)->findOneBy(['module' => 'autorisation', 'action' => 'approuver']);
        $permVenteLire = $em->getRepository(Permission::class)->findOneBy(['module' => 'vente', 'action' => 'lire']);

        $role = (new Role())->setNom('Superviseur Test');
        if ($permApprouver instanceof Permission) {
            $role->addPermission($permApprouver);
        }
        if ($permVenteLire instanceof Permission) {
            $role->addPermission($permVenteLire);
        }
        $em->persist($role);
        $em->flush();

        return $role;
    }

    protected function creerUtilisateur(string $email, Role $role, string $motDePasse = 'aaa', ?Etablissement $etablissement = null): Utilisateur
    {
        $em = $this->em();
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $etab = $etablissement ?? $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($email)->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);
        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etab));
        $em->flush();

        return $utilisateur;
    }

    /**
     * Authentifie un utilisateur de test (créé à la volée avec le rôle « Caissier Test » s'il
     * n'existe pas encore) sur l'établissement A.
     *
     * @return array{0: Client, 1: array<string, mixed>} client authentifié, entête auth+étab A
     */
    protected function connecte(string $email, string $motDePasse = 'aaa', ?Role $role = null): array
    {
        $existant = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if (!$existant instanceof Utilisateur) {
            $this->creerUtilisateur($email, $role ?? $this->roleCaissier(), $motDePasse);
        }

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $entete = ['auth_bearer' => $token, 'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];

        return [$client, $entete];
    }

    /**
     * Ouvre une session de caisse dont l'opérateur est l'auteur authentifié de `$entete` (régisseur =
     * admin, code fixe) : réutilisé pour matérialiser le périmètre `propre_session` (RG-AUTZ-05).
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function ouvrirSessionCaissier(Client $client, array $entete, string $fond = '50.00'): array
    {
        return $this->ouvrirSession($client, $entete, $fond);
    }

    /**
     * Construit et valide une vente au montant exact demandé (prix forcé sur `PRODUIT_CARTE`, sans
     * promotion automatique applicable, pour ne pas fausser le total attendu par les scénarios
     * RG-AUTZ). Payée intégralement en espèces.
     *
     * @param array<string, mixed> $entete
     */
    protected function venteValideeMontant(Client $client, array $entete, string $sessionId, string $montant): string
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
                'prixForce' => true,
                'prixUnitaire' => $montant,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => $montant]]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $vente['id'];
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
