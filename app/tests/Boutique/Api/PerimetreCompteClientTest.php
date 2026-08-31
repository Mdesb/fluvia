<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Enum\StatutPanier;
use App\Crm\Entity\Client;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * UN EXPLOITANT VOIT LES CLIENTS QUI ONT **ACHETÉ** CHEZ LUI, PAS CEUX QUI S'Y SONT INSCRITS.
 *
 * **La règle d'avant était fausse dans les deux sens, et c'est ce qui la rendait invisible.**
 * `CompteClient` était cloisonné par sa boutique d'inscription. Un exploitant ne voyait donc pas un
 * client venu d'ailleurs qui lui achetait tous les mois, **et** voyait un client inscrit chez lui qui
 * n'avait jamais rien pris. Aucune des deux erreurs ne se remarque : la liste a une taille plausible,
 * et personne ne compte les clients d'un CRM.
 *
 * > **Un compte global est une identité. Ce qui appartient à un exploitant, ce sont les transactions
 * > faites chez lui.**
 *
 * Les deux tests ci-dessous sont **le même défaut vu des deux côtés**. Écrire l'un sans l'autre
 * laisserait passer une règle qui rendrait tout, ou une qui ne rendrait rien.
 */
final class PerimetreCompteClientTest extends BoutiqueApiTestCase
{
    /**
     * **Le client venu d'ailleurs qui achète chez moi : je dois le voir.**
     *
     * Compte né sur la vitrine B, commande passée sur l'établissement A. L'ancienne règle le cachait
     * à A — c'est-à-dire cachait un client à celui qui l'encaisse.
     */
    public function testUnCompteNeAilleursMaisAyantAcheteIciEstVisible(): void
    {
        $compte = $this->compte('venu.dailleurs@test.local', $this->vitrine(SocleFixtures::ETAB_B_NOM));
        $this->commander($compte, $this->etablissement(SocleFixtures::ETAB_A_NOM));

        self::assertContains(
            (string) $compte->getId(),
            $this->comptesVusDepuisA(),
            'Un client qui a commande sur A doit apparaitre dans le CRM de A, quel que soit son lieu d inscription.',
        );
    }

    /**
     * **Le garde contre le vide.**
     *
     * Les deux tests negatifs passeraient sur une liste toujours vide -- c'est exactement ce qui est
     * arrive a la premiere version de l'extracteur. Celui-ci verifie que la collection rend QUELQUE
     * CHOSE, sans quoi les autres ne prouvent rien.
     */
    public function testLaCollectionNEstPasVideParConstruction(): void
    {
        $compte = $this->compte('temoin@test.local', $this->vitrine(SocleFixtures::ETAB_A_NOM));
        $this->commander($compte, $this->etablissement(SocleFixtures::ETAB_A_NOM));

        self::assertNotSame([], $this->comptesVusDepuisA(), 'La collection ne doit pas etre vide par construction.');
    }

    /**
     * **Le client inscrit chez moi qui n'a jamais rien pris : je ne dois pas le voir.**
     *
     * C'est le versant qui empêche la règle de tout rendre. Sans lui, une extension qui aurait
     * simplement cessé de filtrer passerait le premier test.
     */
    public function testUnCompteInscritIciMaisSansAchatNEstPasVisible(): void
    {
        $compte = $this->compte('inscrit.sans.achat@test.local', $this->vitrine(SocleFixtures::ETAB_A_NOM));

        self::assertNotContains(
            (string) $compte->getId(),
            $this->comptesVusDepuisA(),
            'S inscrire n est pas acheter : un compte sans commande n appartient a personne.',
        );
    }

    /**
     * **Un panier ABANDONNÉ ne fait pas un client.**
     *
     * `StatutPanier::Ouvert` dit qu'on a hésité. Le retenir gonflerait le CRM de chaque exploitant
     * avec des gens qui ne lui ont jamais rien acheté — et le rendrait inutilisable pour la seule
     * chose qu'on en attend : savoir à qui l'on vend.
     */
    public function testUnPanierAbandonneNeRendPasLeCompteVisible(): void
    {
        $compte = $this->compte('panier.abandonne@test.local', $this->vitrine(SocleFixtures::ETAB_B_NOM));
        $this->commander($compte, $this->etablissement(SocleFixtures::ETAB_A_NOM), StatutPanier::Ouvert);

        self::assertNotContains((string) $compte->getId(), $this->comptesVusDepuisA());
    }

    // --- outillage ------------------------------------------------------------------------------

    /**
     * @return list<string> identifiants des comptes visibles depuis l'établissement A
     *
     * ⚠ **On compare des identifiants, pas des adresses, et ce n'est pas un detail.**
     *
     * `Client.email` n'appartient pas au groupe `compte:read` : le client imbrique sort en IRI, pas en
     * objet. Ma premiere version extrayait `client.email`, obtenait des chaines vides, les filtrait —
     * et rendait donc TOUJOURS une liste vide. Les deux tests negatifs passaient a vide, et seul le
     * test positif a revele le probleme.
     *
     * > **Deux tests qui passent parce qu'ils ne mesurent rien sont pires qu'un test absent : ils
     * > occupent la place.**
     */
    private function comptesVusDepuisA(): array
    {
        // ⚠ `adminSurA()` rend [client, ENTETE] dans la base Boutique, la ou la base Offre rend
        // [client, jeton, idEtablissement]. Deux bases de test, deux conventions -- et l'erreur ne se
        // voit qu'a l'execution, sous la forme << auth_bearer doit etre une chaine >>.
        [$client, $entete] = $this->adminSurA();

        // ⚠ `/api/boutique/comptes` est en POST SEUL (creation depuis la vitrine). La collection
        // back-office est `/api/compte_clients` -- troisieme chemin suppose faux de la journee,
        // et toujours la meme lecon : on releve dans `debug:router`, on ne deduit pas.
        $reponse = $client->request('GET', '/api/compte_clients', $entete + [
            'query' => ['itemsPerPage' => 200],
        ]);
        self::assertResponseIsSuccessful();

        $tableau = $reponse->toArray();
        $membres = $tableau['member'] ?? $tableau['hydra:member'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $c): string => (string) ($c['id'] ?? ''),
            $membres,
        )));
    }

    private function compte(string $email, Vitrine $vitrine): CompteClient
    {
        $em = $this->em();

        $fiche = (new Client())
            ->setType(TypeClient::Physique)
            ->setNom('Test')
            ->setPrenom('Perimetre')
            ->setEmail($email)
            ->setStatut(StatutClient::Actif)
            // `groupe_id` est NOT NULL : un client appartient toujours a un groupe, qu'il tient de la
            // region de son etablissement. Meme chemin que `CreationCompteHandler` -- on ne choisit
            // pas un groupe au hasard pour faire passer le test, on prend celui que la production
            // prendrait.
            ->setGroupe($vitrine->getEtablissement()?->getRegion()?->getGroupe())
            ->setEtablissementCreation($vitrine->getEtablissement());
        $em->persist($fiche);

        $utilisateur = (new Utilisateur())
            ->setEmail($email)
            ->setNom('Test Perimetre')
            ->setStatut(StatutUtilisateur::Actif)
            ->setRolesSecurite(['ROLE_USER']);
        // Le mot de passe n'est jamais utilise ici : aucun de ces tests ne s'authentifie avec ce
        // compte. On pose une valeur non vide parce que la colonne l'exige.
        $utilisateur->setMotDePasse('$2y$13$' . str_repeat('a', 53));
        $em->persist($utilisateur);

        $compte = (new CompteClient())
            ->setClient($fiche)
            ->setUtilisateur($utilisateur)
            ->setVitrineCreation($vitrine)
            ->setEtablissement($vitrine->getEtablissement());
        $em->persist($compte);
        $em->flush();

        return $compte;
    }

    private function commander(
        CompteClient $compte,
        Etablissement $etablissement,
        StatutPanier $statut = StatutPanier::TransformeEnCommande,
    ): void {
        $em = $this->em();
        $em->persist(
            (new PanierEnLigne())
                ->setCompteClient($compte)
                ->setEtablissement($etablissement)
                ->setVitrine($this->vitrine($etablissement->getNom()))
                ->setStatut($statut)
        );
        $em->flush();
    }

    private function vitrine(string $nomEtablissement): Vitrine
    {
        $vitrine = $this->em()->getRepository(Vitrine::class)
            ->findOneBy(['etablissement' => $this->etablissement($nomEtablissement)]);
        self::assertInstanceOf(Vitrine::class, $vitrine, sprintf('Vitrine de « %s » introuvable.', $nomEtablissement));

        return $vitrine;
    }

    private function etablissement(string $nom): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        return $etablissement;
    }
}
