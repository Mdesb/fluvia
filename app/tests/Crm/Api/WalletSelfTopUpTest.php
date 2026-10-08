<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use ApiPlatform\Symfony\Bundle\Test\Client as HttpClient;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Service\CreationCompteHandler;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recharge « soi » du porte-monnaie (`POST /clients/{id}/pmv/recharger`, `crm.pmv_recharger_soi`).
 *
 * Le défaut : le processeur créditait le montant envoyé sans aucun paiement. Un porteur de la
 * permission « soi » se créditait 1 000 € et les dépensait à n'importe quelle caisse du groupe.
 * Aucun paiement en ligne n'est branché (bouchons, D112) : la recharge « soi » est refusée.
 *
 * Mesuré le 08/10 : le compte client de la boutique était déjà arrêté avant la route par
 * `CustomerAccountPathListener` (403), mais son rôle portait la permission ; un compte d'exploitant
 * lié à une fiche et porteur de la permission se créditait bel et bien (201, puis 201 à la caisse).
 */
final class WalletSelfTopUpTest extends BoutiqueApiTestCase
{
    /** Le client final de la boutique : ni le rôle ni le processeur ne lui créditent un euro. */
    public function testACustomerCannotTopUpTheirWalletWithoutPaying(): void
    {
        $idClient = (string) $this->entite(Utilisateur::class, ['email' => BoutiqueFixtures::CLIENT_EMAIL])->getClientLie();
        $http = static::createClient();
        $jeton = $this->jeton($http, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);

        $recharge = $this->topUp($http, ['auth_bearer' => $jeton, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]], $idClient);

        self::assertSame([403, 422], [$recharge->getStatusCode(), $this->payWithWallet($idClient)], (string) $recharge->getContent(false));
        self::assertNotContains('crm.pmv_recharger_soi', $this->actionsOfCustomerRole(), 'Le rôle « Client final » ne porte plus la recharge sans paiement.');
    }

    /**
     * Le même geste par un compte d'exploitant lié à une fiche client et porteur de la permission
     * « soi » (un rôle maison la lui donne) : c'est le processeur, pas le rôle, qui refuse ici.
     */
    public function testTheProcessorRefusesASelfTopUpWithoutPayment(): void
    {
        $idClient = (string) $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL])->getId();
        $this->linkedOperatorWithSelfTopUp('lie.soi@essai.test', $idClient);
        $http = static::createClient();
        $jeton = $this->jeton($http, 'lie.soi@essai.test', 'aaa');

        $recharge = $this->topUp($http, ['auth_bearer' => $jeton, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]], $idClient);

        $corps = (string) $recharge->getContent(false);
        // Recharge puis achat de vingt entrées (99 €) payé par le porte-monnaie : seuls 50 € ont été payés.
        self::assertSame([403, 422], [$recharge->getStatusCode(), $this->payWithWallet($idClient, 20)], $corps);
        self::assertStringContainsString('paiement', $corps);
        self::assertSame('50.00', $this->balance($idClient), 'Le solde des fixtures, sans les 1 000 €.');
    }

    /** Témoin : la recharge au guichet par un agent habilité (`crm.pmv_recharger`) fonctionne comme avant. */
    public function testAnAgentAtTheCounterStillTopsUpAWallet(): void
    {
        $idClient = (string) $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL])->getId();
        $http = static::createClient();
        $jeton = $this->jeton($http, CrmFixtures::AGENT_EMAIL, CrmFixtures::AGENT_MDP);

        $recharge = $this->topUp($http, ['auth_bearer' => $jeton, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]], $idClient, '20.00');

        self::assertSame(201, $recharge->getStatusCode(), (string) $recharge->getContent(false));
        self::assertSame('70.00', $recharge->toArray()['soldeApres']);
    }

    /** @param array<string, mixed> $options */
    private function topUp(HttpClient $http, array $options, string $idClient, string $montant = '1000.00'): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $http->request('POST', '/api/clients/' . $idClient . '/pmv/recharger', $options + [
            'json' => ['montant' => $montant, 'canal' => 'en_ligne'],
        ]);
    }

    /** Des entrées vendues au guichet de A au client, réglées par son porte-monnaie : rend le statut du paiement. */
    private function payWithWallet(string $idClient, int $quantite = 1): int
    {
        $http = static::createClient();
        $entete = ['auth_bearer' => $this->jeton($http, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP), 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];
        $session = $http->request('POST', '/api/sessions-caisse/ouvrir', $entete + ['json' => [
            'pointDeVente' => '/api/point_de_ventes/' . $this->entite(PointDeVente::class, ['libelle' => VenteFixtures::PDV_LIBELLE])->getId(),
            'caisse' => '/api/caisses/' . $this->entite(Caisse::class, ['libelle' => VenteFixtures::CAISSE_LIBELLE])->getId(),
            'regisseur' => '/api/utilisateurs/' . $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId(),
            'codeRegisseur' => 'CODE-REGIE-2026',
            'fondDeCaisse' => '50.00',
        ]])->toArray();
        $vente = $http->request('POST', '/api/ventes', $entete + ['json' => ['session' => '/api/session_caisses/' . $session['id']]])->toArray();
        $http->request('POST', '/api/ventes/' . $vente['id'] . '/client', $entete + ['json' => ['client' => $idClient]]);
        $http->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + ['json' => [
            'produit' => '/api/produits/' . $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE])->getId(),
            'typeTarif' => '/api/type_tarifs/' . $this->entite(TypeTarif::class, ['nom' => OffreFixtures::TARIF_PLEIN])->getId(),
            'quantite' => $quantite,
        ]]);
        self::assertResponseIsSuccessful();

        return $http->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'pmv']])->getStatusCode();
    }

    private function linkedOperatorWithSelfTopUp(string $email, string $idClient): void
    {
        $em = $this->em();
        $role = (new Role())->setNom('Rôle maison soi')->setEstModele(false);
        $role->addPermission($this->entite(Permission::class, ['module' => 'crm', 'action' => 'pmv_recharger_soi']));
        $em->persist($role);
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Exploitant lié')->setActif(true);
        $utilisateur->setMotDePasse(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($utilisateur, 'aaa'));
        $utilisateur->setClientLie(\Symfony\Component\Uid\Uuid::fromString($idClient));
        $em->persist($utilisateur);
        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])));
        $em->flush();
    }

    private function balance(string $idClient): string
    {
        $this->em()->clear();
        $pmv = $this->em()->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $this->entite(Client::class, ['id' => $idClient])]);

        return $pmv?->getSolde() ?? '0.00';
    }

    /** @return list<string> */
    private function actionsOfCustomerRole(): array
    {
        $this->em()->clear();
        $role = $this->entite(Role::class, ['nom' => CreationCompteHandler::ROLE_CLIENT_FINAL_NOM]);

        return array_values(array_map(static fn (Permission $p): string => $p->getModule() . '.' . $p->getAction(), $role->getPermissions()->toArray()));
    }
}
