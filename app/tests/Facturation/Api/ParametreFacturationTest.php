<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE PARAMETRAGE DE FACTURATION — une ressource complete que RIEN n'appelait, et qui ne POUVAIT pas
 * l'etre.
 *
 * ⚠ DEUX MANQUES SIGNALES AILLEURS VIENNENT DE LA :
 *
 *   — `tauxPenaliteRetard` est nullable et personne ne pouvait le renseigner. Le taux de penalites
 *     « absent des factures » n'etait pas absent du modele : il etait inatteignable ;
 *   — `ResolveurComptesFacturation` dit, quand il echoue : « renseignez une categorie comptable
 *     mappee, OU un compte de produit par defaut dans le parametrage de facturation ». La seconde
 *     voie n'existait pas — un repli qu'on ne pouvait pas armer.
 *
 * ⚠ ET LA RESSOURCE ETAIT INUTILISABLE, CE QUI EXPLIQUE L'ABSENCE D'ECRAN. `profilExploitant`
 * portait `nullable: false` ET le groupe d'ecriture : c'etait au client de le fournir, et rien ne le
 * posait. Un POST sans lui rendait un 500 « Column profil_exploitant_id cannot be null ». Mesure
 * faite sur une base JETABLE — surtout pas en preprod, ou cette ressource n'expose aucun `Delete` et
 * ou chaque essai aurait laisse une ligne definitive.
 */
final class ParametreFacturationTest extends FacturationApiTestCase
{
    /**
     * ⚠ UN PARAMETRAGE PAR PROFIL, ET C'EST UNE CONTRAINTE DE BASE.
     *
     * `uniq_facturation_parametre_profil` interdit le second. L'ecran lit donc ce qui existe et le
     * corrige ; il ne cree que s'il n'y a rien. Ce test cloue ce comportement, parce qu'un ecran qui
     * creerait a chaque enregistrement rendrait un 500 sur le deuxieme clic — et l'exploitant
     * conclurait que son reglage n'a pas ete pris.
     */
    public function testUnTauxDePenalitesPeutEnfinEtreRenseigneEtRelu(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/parametres-facturation', $entete);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'] ?? [];

        self::assertNotEmpty($membres, 'temoin : un parametrage existe pour ce profil, la suite le corrige');
        $id = $membres[0]['id'];

        $client->request('PATCH', '/api/parametres-facturation/' . $id, [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['tauxPenaliteRetard' => '10.00', 'delaiPaiementDefautJours' => 45],
        ]);

        self::assertResponseIsSuccessful();
        $rendu = $client->getResponse()->toArray();

        // ⚠ ON RELIT CE QUE LE SERVEUR A RETENU, PAS CE QU'ON LUI A ENVOYE. Une requete acceptee
        // n'est pas un champ enregistre : un groupe d'ecriture absent le fait ignorer en silence, et
        // la reponse rend alors l'ancienne valeur sans se plaindre. Ce depot a deja paye trois fois
        // cette confusion.
        self::assertSame('10.00', $rendu['tauxPenaliteRetard'], 'le taux doit etre retenu, pas seulement accepte');
        self::assertSame(45, $rendu['delaiPaiementDefautJours']);

        // Et relu depuis la BASE, pas depuis la reponse : celle-ci peut refleter l'objet en memoire
        // sans que la ligne ait ete ecrite.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        $relu = $em->getRepository(ParametreFacturationEtablissement::class)->find($id);
        self::assertInstanceOf(ParametreFacturationEtablissement::class, $relu);
        self::assertSame('10.00', $relu->getTauxPenaliteRetard());
    }

    /**
     * LA CREATION — LE CHEMIN QUE L'ECRAN EMPRUNTE QUAND RIEN N'EXISTE ENCORE.
     *
     * ⚠ CE TEST COMBLE UN TROU DANS MA PROPRE COUVERTURE. Les autres font des PATCH sur la ligne
     * des fixtures. Mais `ParametresFacturation.jsx` appelle `creerParametreFacturation` des que
     * `parametre?.id` est absent — c'est-a-dire chez tout exploitant qui n'a jamais ouvert cette
     * page. Le POST etait donc le cas COURANT, et le seul que je ne mesurais pas.
     *
     * C'est aussi la seule raison d'etre de `BillingSettingsStampProcessor` : avant lui, un POST
     * sans `profilExploitant` rendait un 500 « Column profil_exploitant_id cannot be null », et le
     * champ n'etant plus accepte du client, personne ne pouvait le fournir. Sans ce test, le
     * processeur pourrait cesser de poser le profil sans que rien ne vire au rouge.
     *
     * ⚠ ON RETIRE D'ABORD LA LIGNE DES FIXTURES. `uniq_facturation_parametre_profil` interdit le
     * second parametrage : sans ce nettoyage, le POST echouerait sur la contrainte d'unicite et le
     * test rendrait rouge pour une raison qui n'a rien a voir avec ce qu'il mesure.
     */
    public function testLaChargeExacteDeLEcranCreeUnParametrage(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        foreach ($em->getRepository(ParametreFacturationEtablissement::class)->findAll() as $existant) {
            $em->remove($existant);
        }
        $em->flush();
        $em->clear();

        // La charge EXACTE de l'ecran : aucun `profilExploitant`. Le composer a la main avec un
        // profil en plus prouverait que l'API marche, pas que l'ecran marche.
        $client->request('POST', '/api/parametres-facturation', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => [
                'conditionsReglementDefaut' => 'Paiement a 30 jours date de facture.',
                'delaiPaiementDefautJours' => 30,
                'tauxPenaliteRetard' => '10.00',
                'indemniteForfaitaireRecouvrement' => '40.00',
                'chorusProActif' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(
            201,
            'l\'ecran cree un parametrage sans fournir de profil : si cette charge est refusee, '
            . 'personne ne peut en poser un depuis l\'interface',
        );

        $rendu = $client->getResponse()->toArray();

        // ⚠ ET LE PROFIL DOIT AVOIR ETE POSE. Un 201 dit qu'on a accepte ; il ne dit pas que le
        // rattachement est le bon. Une ligne posee sur un autre profil serait invisible en lecture
        // — l'extension de perimetre la filtrerait — et l'exploitant croirait son reglage perdu.
        $em->clear();
        $creee = $em->getRepository(ParametreFacturationEtablissement::class)->find($rendu['id']);
        self::assertInstanceOf(ParametreFacturationEtablissement::class, $creee);
        self::assertNotNull(
            $creee->getProfilExploitant(),
            'le processeur doit poser le profil comptable depuis l\'etablissement actif',
        );

        // Et la ligne doit se relire par l'API, pas seulement exister en base : c'est la lecture
        // cloisonnee qui dit si le rattachement est le bon.
        $client->request('GET', '/api/parametres-facturation', $entete);
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'] ?? [];
        self::assertCount(
            1,
            $membres,
            'le parametrage cree doit etre relu par son auteur : sinon il a ete rattache ailleurs',
        );
    }

    /**
     * ⚠ LE PROFIL N'EST PLUS ACCEPTE DU CLIENT, ET CE TEST GARDE CETTE PORTE FERMEE.
     *
     * Il portait le groupe d'ecriture : un profil etranger dans le corps aurait ecrit le parametrage
     * de facturation du voisin — ses conditions de reglement, son taux de penalites. Le symptome
     * aurait ete un reglage qui change tout seul chez quelqu'un d'autre, ce qu'on n'impute jamais a
     * une requete etrangere.
     */
    public function testLeProfilComptableNEstPasAcceptableDepuisLeCorps(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/parametres-facturation', $entete);
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'] ?? [];
        self::assertNotEmpty($membres);

        $avant = $membres[0]['profilExploitant'] ?? null;
        self::assertNotNull($avant, 'temoin : le profil est bien rendu en lecture');

        $client->request('PATCH', '/api/parametres-facturation/' . $membres[0]['id'], [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['profilExploitant' => '/api/profil_exploitants/00000000-0000-0000-0000-000000000000'],
        ]);

        // Le champ n'est plus denormalise : il est ignore. Ce qui compte est qu'il ne CHANGE pas.
        $client->request('GET', '/api/parametres-facturation', $entete);
        $apres = ($client->getResponse()->toArray()['member']
            ?? $client->getResponse()->toArray()['hydra:member'])[0]['profilExploitant'] ?? null;

        self::assertSame($avant, $apres, 'le profil ne doit pas pouvoir etre change depuis le corps');
    }

    /**
     * ⚠ LE TAUX PART EN CHAINE, ET UN NOMBRE EST REFUSE.
     *
     * `tauxPenaliteRetard` est declare `?string` : un `Number()` cote ecran est refuse. C'est le
     * defaut exact qui a rendu la creation d'un taux de TVA impossible pendant des jours, et il ne
     * se voit qu'a l'execution.
     *
     * ⚠ 400 ET NON 422, ET J'AVAIS PREDIT 422. La distinction n'est pas cosmetique : une erreur de
     * TYPE est un corps malforme, refuse par le deserialiseur avant toute validation metier — d'ou
     * `Bad Request`. Un 422 signalerait une valeur bien formee mais invalide, et l'ecran qui
     * chercherait a afficher des erreurs de champ ne trouverait rien a afficher.
     *
     * Ce que ce test protege est donc le REFUS, pas le code : ce qui compte est qu'un nombre ne
     * passe pas en silence. Le code est constate, pas exige — je l'avais suppose et je m'etais
     * trompe.
     */
    public function testUnTauxEnvoyeCommeNombreEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/parametres-facturation', $entete);
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'] ?? [];
        self::assertNotEmpty($membres);

        $client->request('PATCH', '/api/parametres-facturation/' . $membres[0]['id'], [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['tauxPenaliteRetard' => 10],
        ]);

        self::assertResponseStatusCodeSame(400, 'un nombre doit etre refuse : la valeur est declaree ?string');
    }
}
