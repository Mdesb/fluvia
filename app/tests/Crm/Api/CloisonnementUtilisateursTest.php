<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Tests\Crm\CrmApiTestCase;

/**
 * L'ADMINISTRATEUR D'UN CLIENT VOIT-IL LES UTILISATEURS DES AUTRES ?
 *
 * ⚠ CE QUE LA COLLECTION REND. `utilisateur:read` expose `email`, `nom`, `statut` et
 * `dernierAcces`. Ce sont des donnees personnelles — et accessoirement la liste nominative de qui
 * travaille chez un concurrent, avec ses heures de connexion.
 *
 * `GET /api/utilisateurs` est garde par `securite.gerer`, que porte le role CLIENT
 * << Administrateur groupe >>. Aucun fournisseur sur mesure, aucune extension de collection ne
 * nomme `Utilisateur`.
 *
 * ── ⚠ POURQUOI CE TEST NE POUVAIT PAS ETRE REMPLACE PAR UNE LECTURE ────────────────────────────
 *
 * Deux fois aujourd'hui, une lecture de code exacte a produit une conclusion fausse : quatre
 * entites comptables paraissaient nues a l'ecriture et ne l'etaient pas, parce qu'API Platform
 * resout l'IRI d'une relation via un fournisseur d'item cloisonne. Le mecanisme etait invisible a
 * toute analyse statique.
 *
 * Ici ce sauvetage-la ne peut structurellement pas jouer — une collection ne resout aucun IRI,
 * elle construit une requete et applique les extensions de collection. Mais << je ne vois pas de
 * mecanisme >> est exactement la phrase qui s'est trompee deux fois. D'ou l'execution.
 *
 * ── LES TROIS MESURES, ET POURQUOI IL EN FAUT TROIS ─────────────────────────────────────────────
 *
 *   1. CONTROLE POSITIF — l'administrateur doit voir SES propres utilisateurs. Sans lui, une
 *      collection vide passerait la mesure suivante en prouvant l'inverse de ce qu'on croit :
 *      un cloisonnement qui rend tout invisible n'est pas un cloisonnement, c'est une panne.
 *
 *   2. LA MESURE — un utilisateur rattache a un AUTRE groupe apparait-il ?
 *
 *   3. TEMOIN NEGATIF — un compte depourvu de `securite.gerer` doit etre refuse. Sinon la mesure 2
 *      pourrait passer parce que la ressource est fermee a tout le monde, ce qui ne dirait rien du
 *      cloisonnement.
 */
final class CloisonnementUtilisateursTest extends CrmApiTestCase
{
    public function testUnAdministrateurNeVoitPasLesUtilisateursDesAutresClients(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/utilisateurs', $entete + ['query' => ['itemsPerPage' => 200]]);
        self::assertResponseIsSuccessful('temoin : l\'administrateur doit pouvoir lister les utilisateurs');

        $rendu = $client->getResponse()->toArray();
        $membres = $rendu['member'] ?? $rendu['hydra:member'] ?? [];

        $courriels = array_map(static fn (array $u): string => (string) ($u['email'] ?? ''), $membres);

        // ── 1. CONTROLE POSITIF ─────────────────────────────────────────────────────────────────
        self::assertContains(
            CrmFixtures::AGENT_EMAIL,
            $courriels,
            'temoin : l\'administrateur doit voir les utilisateurs de SON groupe, sinon une '
            . 'collection vide ferait passer la mesure suivante sans rien prouver',
        );

        // ── 2. LA MESURE ────────────────────────────────────────────────────────────────────────
        self::assertNotContains(
            CrmFixtures::AGENT_B_EMAIL,
            $courriels,
            'la collection des utilisateurs expose nom, courriel et derniere connexion : un '
            . 'administrateur ne doit pas voir ceux d\'un autre client',
        );
    }

    /**
     * UN COMPTE CREE SANS ROLE RESTE VISIBLE — LIMITE ASSUMEE, ET C'EST POURQUOI ELLE EST TESTEE.
     *
     * ⚠ SANS CETTE BRANCHE, LE CLOISONNEMENT CASSERAIT LA CREATION DE COMPTES.
     *
     * L'ecran des comptes cree l'utilisateur PUIS l'affectation, et l'affectation est facultative
     * (`if (payload.roleId && payload.etabId)` dans `Parametres.jsx`). Une regle qui exige un
     * etablissement en commun ferait donc disparaitre le compte a l'instant meme ou on vient de le
     * creer. L'administrateur en conclurait que l'enregistrement a echoue, et le recreerait.
     *
     * Ce que cette branche laisse voir n'est pas une fuite inter-client au sens propre : un compte
     * sans affectation n'appartient au perimetre de personne. Elle reste une porte entrouverte, et
     * la refermer demanderait que la creation pose l'affectation dans le meme geste — un changement
     * d'ecran et de contrat.
     *
     * Ce test est la pour que ce soit une DECISION : quiconque retire la branche fera echouer un
     * test qui dit pourquoi elle existe, au lieu de casser un ecran en silence.
     */
    public function testUnCompteSansAffectationResteVisibleAuCreateur(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/utilisateurs', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => [
                'email' => 'compte.sans.role@itcotation.com',
                'nom' => 'Compte sans role',
                'motDePasseClair' => 'MotDePasseAssezLong#2026',
            ],
        ]);
        self::assertResponseStatusCodeSame(201, 'temoin : la creation d\'un compte doit reussir');

        $client->request('GET', '/api/utilisateurs', $entete + ['query' => ['itemsPerPage' => 200]]);
        $rendu = $client->getResponse()->toArray();
        $membres = $rendu['member'] ?? $rendu['hydra:member'] ?? [];
        $courriels = array_map(static fn (array $u): string => (string) ($u['email'] ?? ''), $membres);

        self::assertContains(
            'compte.sans.role@itcotation.com',
            $courriels,
            'un compte cree sans role doit rester visible : sinon il disparait de l\'ecran a '
            . 'l\'instant ou on vient de le creer',
        );
    }

    /**
     * ⚠ SANS CE TEMOIN, LA MESURE CI-DESSUS POURRAIT PASSER POUR LA MAUVAISE RAISON.
     *
     * Si la ressource devenait fermee a tout le monde, l'assertion << l'agent B n'apparait pas >>
     * serait vraie et ne dirait rien du cloisonnement. Ce test fixe l'autre bord : le droit
     * `securite.gerer` reste ce qui ouvre la porte, et son absence la ferme.
     */
    public function testUnCompteSansLeDroitEstRefuse(): void
    {
        [$client, $entete] = $this->agentSurA();

        $client->request('GET', '/api/utilisateurs', $entete);

        self::assertResponseStatusCodeSame(
            403,
            'un compte depourvu de `securite.gerer` ne doit pas lister les utilisateurs',
        );
    }
}
