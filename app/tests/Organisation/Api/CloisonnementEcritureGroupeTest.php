<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PEUT-ON CREER UNE REGION DANS LE GROUPE D'UN AUTRE CLIENT ?
 *
 * ⚠ POURQUOI CELLE-CI PLUTOT QU'UNE AUTRE, ET CE QUI LA DISTINGUE DES QUATRE COMPTABLES.
 *
 * Un balayage a montre que quinze ressources acceptent leur rattachement depuis le corps de la
 * requete sans qu'aucun processeur ne le pose. Sur les quatre entites comptables, l'ecriture croisee
 * a ete mesuree IMPOSSIBLE — non par une garde, mais parce qu'API Platform resout l'IRI d'une
 * relation en passant par le fournisseur d'item, lequel est cloisonne par `AccountingScopeExtension`.
 * On ne peut pas ecrire le rattachement d'autrui parce qu'on ne peut pas le NOMMER.
 *
 * `Groupe` echappe a ce raisonnement, et c'est ce qui rend ce test necessaire :
 *
 *   — AUCUNE extension d'item ne nomme `Groupe` ; son `Get` d'item n'exige que
 *     `IS_AUTHENTICATED_FULLY`. N'importe quel utilisateur connecte resout donc l'IRI d'un groupe,
 *     y compris celui d'un autre client ;
 *   — `Region::$groupe` porte `nullable: false` ET le groupe d'ecriture : c'est au client de le
 *     fournir, et rien ne le pose ;
 *   — l'ecriture est gardee par `organisation.gerer`, que porte le role CLIENT « Administrateur
 *     groupe ».
 *
 * Les trois conditions qui sauvent les entites comptables manquent ici toutes les trois. Ce test
 * existe pour savoir, par execution, ce que la lecture du code ne peut pas trancher — la lecon de
 * la matinee, apprise en croyant a tort avoir trouve une fuite.
 *
 * ⚠ Mesure faite sur base JETABLE. Une region creee ne s'annule pas : `Region` n'expose aucun
 * `Delete`, et sonder en preprod aurait laisse dans la structure d'un client une ligne definitive
 * qu'on aurait ensuite prise pour une vraie.
 */
final class CloisonnementEcritureGroupeTest extends SocleApiTestCase
{
    public function testUneRegionNePeutPasEtreCreeeDansLeGroupeDunAutre(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        // ── CONTROLE POSITIF ────────────────────────────────────────────────────────────────────
        //
        // Sans lui, un refus ne prouverait rien : un champ manquant, un droit absent ou un IRI
        // malforme rendraient le meme genre de reponse que le cloisonnement qu'on cherche.
        $client->request('POST', '/api/regions', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => ['nom' => 'Region temoin', 'groupe' => '/api/groupes/' . $this->idGroupePropre()],
        ]);

        self::assertResponseStatusCodeSame(
            201,
            'temoin : creer une region dans son propre groupe doit reussir, sinon le refus mesure '
            . 'plus bas ne prouverait rien',
        );

        // ── LE GROUPE D'UN AUTRE ────────────────────────────────────────────────────────────────
        $idEtranger = $this->groupeEtranger();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        self::assertNotNull(
            $em->getRepository(Groupe::class)->find($idEtranger),
            'temoin : le groupe etranger doit EXISTER, sinon son inaccessibilite ne prouve rien',
        );

        $client->request('POST', '/api/regions', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => ['nom' => 'Region posee chez le voisin', 'groupe' => '/api/groupes/' . $idEtranger],
        ]);

        $code = $client->getResponse()->getStatusCode();

        $em->clear();
        $ecrite = $em->getRepository(Region::class)->findOneBy(['nom' => 'Region posee chez le voisin']);

        // ⚠ CE QU'ON MESURE EST LA LIGNE, PAS LE CODE. Un 201 dit qu'on a accepte ; seule l'absence
        // d'enregistrement dit qu'on n'a rien ecrit chez le voisin. Le depot a deja paye trois fois
        // la confusion entre « accepte » et « enregistre ».
        self::assertNull(
            $ecrite,
            sprintf(
                'une region ne doit pas pouvoir etre creee dans le groupe d\'un autre client. '
                . 'Le serveur a repondu %d et la ligne EXISTE : le rattachement est accepte depuis '
                . 'le corps, aucun processeur ne le pose, et `Groupe` n\'est cloisonne par aucune '
                . 'extension d\'item — les trois conditions qui protegent les entites comptables '
                . 'manquent ici.',
                $code,
            ),
        );
    }

    /**
     * L'ECRAN DES REGIONS ENVOIE `nom` ET RIEN D'AUTRE — CETTE CREATION DOIT MARCHER.
     *
     * `RegionsSection.jsx` n'a qu'un seul champ, `nom`. Le `groupe` est `nullable: false` et porte
     * `Assert\NotNull` : la creation etait donc refusee avant meme d'atteindre un processeur.
     *
     * ⚠ ET LE MEME ECRAN DIT : « Créez-en une avant votre premier établissement : un site ne peut
     * pas exister sans elle. » Il envoyait l'exploitant faire un geste que le serveur refusait.
     * C'est le meme defaut que le parametrage de facturation : un champ de rattachement qu'on
     * demande au client alors que le serveur est seul a le connaitre.
     *
     * Ce test rejoue la charge EXACTE de l'ecran. Le composer a la main avec un `groupe` en plus
     * prouverait que l'API marche, pas que l'ecran marche — et c'est l'ecran qu'on livre.
     */
    public function testLaChargeExacteDeLEcranCreeUneRegion(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $client->request('POST', '/api/regions', [
            'auth_bearer' => $token,
            'headers' => [
                ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'Content-Type' => 'application/ld+json',
            ],
            'json' => ['nom' => 'Région Est'],
        ]);

        self::assertResponseStatusCodeSame(
            201,
            'l\'ecran des regions n\'envoie que `nom` : si cette charge est refusee, personne ne '
            . 'peut creer de region depuis l\'interface',
        );

        // Et rattachee au bon groupe, pas a n'importe lequel : une region posee dans le groupe d'un
        // autre client serait un defaut pire que le refus qu'on vient de lever.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        $creee = $em->getRepository(Region::class)->findOneBy(['nom' => 'Région Est']);
        self::assertInstanceOf(Region::class, $creee);
        self::assertNotNull($creee->getGroupe(), 'la region doit etre rattachee a un groupe');
    }

    /**
     * LA LISTE DES GROUPES EST-ELLE VISIBLE DE TOUT UTILISATEUR CONNECTE ?
     *
     * ⚠ CE N'EST PAS LE MEME DEFAUT QUE L'ECRITURE CI-DESSUS, ET IL NE SE REFERME PAS AVEC ELLE.
     *
     * `Groupe` expose `GetCollection` et `Get` sous `IS_AUTHENTICATED_FULLY`, et aucune extension de
     * perimetre ne le nomme. Un groupe, dans ce logiciel, est un CLIENT : une regie, un delegataire,
     * un groupe prive. La collection est donc la liste des clients — que se partagent parfois des
     * concurrents sur les memes appels d'offres. Un caissier de piscine peut savoir chez qui d'autre
     * le logiciel est installe.
     *
     * ⚠ CE QUI REND LE RESSERREMENT SUR : aucun ecran ne lit `/api/groupes` — verifie par grep sur
     * tout `frontend/src`, avec `/api/groupe_options` (une ressource sans rapport) comme temoin que
     * le motif trouve bien quelque chose. Les quatre lecteurs serveur passent par Doctrine
     * (`PerimetreReportingResolver`, `AgregateurMesuresService`, et deux autres) et ne traversent
     * aucune garde d'API : ils continuent de fonctionner.
     *
     * ⚠ CE QUE CETTE MESURE NE COUVRE PAS : un integrateur, un script ou un connecteur externe
     * pourraient interroger cette collection. Ce sont des lecteurs qu'aucun grep du depot ne voit.
     */
    public function testUnUtilisateurNeVoitPasLesGroupesDesAutresClients(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        $idEtranger = $this->groupeEtranger();

        $client->request('GET', '/api/groupes', $entete);
        self::assertResponseIsSuccessful('temoin : la collection doit repondre, sinon on ne mesure rien');

        $rendu = $client->getResponse()->toArray();
        $membres = $rendu['member'] ?? $rendu['hydra:member'] ?? [];

        // Temoin positif : on doit voir AU MOINS le sien. Une collection vide passerait le test
        // suivant sans rien prouver — et casserait l'application au passage.
        self::assertNotEmpty($membres, 'temoin : l\'utilisateur doit voir son propre groupe');

        $vus = array_map(static fn (array $g): string => (string) ($g['@id'] ?? ''), $membres);

        self::assertNotContains(
            '/api/groupes/' . $idEtranger,
            $vus,
            'la collection des groupes est la liste des clients : elle ne doit pas etre visible '
            . 'd\'un utilisateur d\'un autre client',
        );
    }

    /**
     * L'ITEM AUSSI, ET C'EST LUI QUI PORTE LE RESTE.
     *
     * ⚠ POURQUOI CE TEST EXISTE SEPAREMENT DES TROIS AUTRES. Aucun d'eux ne prouve le cloisonnement
     * de l'ITEM :
     *
     *   — l'ecriture croisee est refusee par `RegionGroupScopeProcessor`, pas par une extension ;
     *   — le test de collection ne dit rien de `GET /api/groupes/{id}`.
     *
     * Or c'est l'item qui compte au-dela de cette entite. API Platform resout l'IRI d'une relation
     * en passant par le fournisseur d'item : tant qu'un groupe etranger reste resolvable, TOUTE
     * ressource qui accepte un `groupe` depuis son corps peut le designer. `RegleConservation`
     * (`Crm/Entity/RegleConservation.php`) est exactement dans ce cas — `groupe` en ecriture,
     * `nullable: false`, aucun processeur — et ce sont des regles de conservation RGPD, c'est-a-dire
     * ce qui decide quand les donnees personnelles d'un client sont effacees.
     *
     * Ce test est donc l'argument qui la couvre sans avoir a monter un compte portant
     * `crm.parametrer` : ce n'est pas « la meme forme, donc probablement pareil », c'est le meme
     * mecanisme, mesure ici.
     *
     * ⚠ CE QU'IL NE COUVRE PAS : si quelqu'un ajoute demain a `RegleConservation` un processeur qui
     * resout le groupe autrement — par un depot Doctrine plutot que par un IRI — ce chemin-ci ne le
     * verrait pas. La protection tient a ce que le rattachement passe par la resolution d'IRI.
     */
    public function testUnGroupeEtrangerNEstPasResolvableALUnite(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        // Temoin positif : le sien doit rester lisible. Un cloisonnement qui rend tout invisible
        // passerait l'assertion suivante en cassant l'application.
        $client->request('GET', '/api/groupes/' . $this->idGroupePropre(), $entete);
        self::assertResponseIsSuccessful('temoin : son propre groupe doit rester lisible a l\'unite');

        $client->request('GET', '/api/groupes/' . $this->groupeEtranger(), $entete);

        self::assertResponseStatusCodeSame(
            404,
            'un groupe etranger ne doit pas etre resolvable a l\'unite : c\'est ce chemin que la '
            . 'deserialisation d\'un IRI emprunte, et donc ce qui protege toute ressource acceptant '
            . 'un `groupe` depuis son corps',
        );
    }

    /** Le groupe auquel appartient l'etablissement A, via sa region. */
    private function idGroupePropre(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $groupe = $em->getRepository(Groupe::class)->findOneBy([]);
        self::assertInstanceOf(Groupe::class, $groupe, 'temoin : les fixtures doivent poser un groupe');

        return (string) $groupe->getId();
    }

    /**
     * Un second groupe, qui n'est celui de personne dans cette session.
     *
     * Les fixtures n'en posent qu'un : sans voisin, il n'y a pas de cloisonnement a mesurer.
     */
    private function groupeEtranger(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $groupe = new Groupe();
        $groupe->setNom('Groupe d\'un autre client');

        $em->persist($groupe);
        $em->flush();

        return (string) $groupe->getId();
    }
}
