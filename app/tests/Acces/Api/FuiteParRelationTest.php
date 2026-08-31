<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\SousReseau;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNE RESSOURCE NON CLOISONNEE PEUT-ELLE PUBLIER LES IDENTIFIANTS D'ENTITES QUI LE SONT ?
 *
 * ⚠ CETTE DIMENSION N'ETAIT VUE PAR AUCUN DE NOS DEUX CRITERES.
 *
 * Les quatre fuites mesurees le 31/08 passaient toutes par une collection RACINE non cloisonnee :
 * `Region`, `Groupe`, `Utilisateur`, `OperationScellee`. Les criteres qui les ont trouvees cherchent
 * le rattachement de l'entite racine.
 *
 * Aucun ne regarde ce que cette entite REND. Or une relation de collection se serialise en liste
 * d'IRI, et cette serialisation ne passe pas par le fournisseur d'item : le cloisonnement du type
 * VISE ne s'y applique donc pas. Une ressource ouverte peut ainsi publier les identifiants
 * d'entites parfaitement cloisonnees.
 *
 * Balayage de allaccess-b8 sur cette dimension : une seule ressource dans tout le depot remplit le
 * critere — `SousReseau.espaces -> EspaceAcces`. Ce test la mesure, et referme la dimension entiere.
 *
 * ── ⚠ POURQUOI L'ENJEU N'EST PAS LA FUITE DE LIBELLES ───────────────────────────────────────────
 *
 * Un UUID d'espace ne dit rien par lui-meme. Mais `POST /sport/espaces/{id}/sos` est declare
 * `PUBLIC_ACCESS` — declenchement physique d'une alerte, sans authentification. Son en-tete le
 * reconnait : « Risque n°7 du plan, ⚠ jeton d'appareil non specifie ». La seule protection est donc
 * que l'identifiant ne soit pas devinable.
 *
 * Si une collection le publie, cette protection tombe. C'est pour ca que le second test existe :
 * mesurer la FUITE ne suffit pas, il faut mesurer ce qu'elle PERMET.
 */
final class FuiteParRelationTest extends AccesApiTestCase
{
    private const LIBELLE_ETRANGER = 'Espace du voisin';

    /**
     * ⚠ LE LECTEUR PLUTOT QUE L'ADMINISTRATEUR, ET C'EST LA CLE DE LA MESURE.
     *
     * L'administrateur du socle est affecte a `Piscine A`, `Patinoire B` ET a l'etablissement de
     * l'editeur : rien n'est « chez un autre » pour lui, et le test n'aurait rien mesure. Le compte
     * « Lecture seule » n'est affecte qu'a `Piscine A`, et son role porte `*.lire` — donc
     * `acces.lire`, ce qui ouvre la collection sans ouvrir le perimetre.
     */
    public function testUneRessourceOuverteNePubliePasLesIdentifiantsDEspacesEtrangers(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        $idEtranger = $this->espaceAccesChezLeVoisin();
        $idSien = $this->idEspaceAcces();

        // ⚠ TEMOIN : L'ESPACE ETRANGER N'EST PAS LISIBLE DIRECTEMENT.
        //
        // Sans lui, ce test ne prouverait rien : si la ressource `EspaceAcces` etait ouverte a tous,
        // la publication de son identifiant par une autre collection ne serait pas une fuite, juste
        // une redondance. C'est parce que cette porte-la est FERMEE que la publication compte.
        // ⚠ D'ABORD LE SIEN, PAR LA MEME ROUTE. Sans ca, le 404 mesure plus bas ne distingue pas
        // « refuse par le perimetre » de « cette route n'existe pas » — et je viens de me faire
        // prendre exactement ainsi sur `/api/sous_reseaux`, qui n'existe pas (c'est `sous_reseaus`).
        $client->request('GET', '/api/espace_acces/' . $idSien, $entete);
        self::assertResponseStatusCodeSame(
            200,
            'temoin de route : le lecteur doit pouvoir lire SON espace a cette adresse, sinon le '
            . '404 suivant ne prouve rien',
        );

        $client->request('GET', '/api/espace_acces/' . $idEtranger, $entete);
        self::assertResponseStatusCodeSame(
            404,
            'temoin : l\'espace du voisin ne doit pas etre lisible directement, sinon sa publication '
            . 'ailleurs ne serait pas une fuite',
        );

        $client->request('GET', '/api/sous_reseaus', $entete + ['query' => ['itemsPerPage' => 100]]);
        self::assertResponseIsSuccessful();

        $corps = $client->getResponse()->getContent(false);

        // Temoin positif : le sien doit y figurer. Une reponse vide passerait l'assertion suivante
        // en prouvant le contraire de ce qu'on veut.
        self::assertStringContainsString(
            $idSien,
            $corps,
            'temoin : l\'espace de mon propre etablissement doit apparaitre, sinon la collection ne '
            . 'rend rien et la mesure suivante est vide de sens',
        );

        self::assertStringNotContainsString(
            $idEtranger,
            $corps,
            'une ressource non cloisonnee ne doit pas publier les identifiants d\'espaces d\'un '
            . 'autre etablissement : `POST /sport/espaces/{id}/sos` est PUBLIC_ACCESS et n\'exige '
            . 'que cet identifiant',
        );
    }

    /**
     * LE COMPTE ANNONCE CORRESPOND AUX LIGNES RENDUES, MALGRE UNE JOINTURE ManyToMany.
     *
     * ⚠ J'AI ESSAYE DE FAIRE ECHOUER CE TEST ET JE N'AI PAS PU. C'est ecrit ici parce que la
     * version precedente de ce commentaire affirmait le contraire.
     *
     * Le cloisonnement de `SousReseau` joint `espaces`, un ManyToMany : la jointure multiplie la
     * ligne racine par le nombre d'espaces du site actif. J'avais donc annonce que ce test gardait
     * le `->distinct()` de `restreindre()`. J'ai retire ce `distinct()` pour voir le filet
     * attraper : le test est reste VERT.
     *
     * Deux mecanismes tiennent l'invariant, et aucun n'est celui que j'avais nomme :
     *
     *   — l'hydrateur d'objets de Doctrine rend des entites racines UNIQUES, les lignes dupliquees
     *     se rabattant sur le meme objet via la carte d'identite ;
     *   — le paginateur d'API Platform compte en `COUNT(DISTINCT id)`.
     *
     * Le `distinct()` reste dans l'extension : il est defensif et gratuit. Mais il n'est pas ce qui
     * tient l'invariant, et l'ecrire aurait envoye chercher au mauvais endroit le jour ou des
     * doublons apparaitraient.
     *
     * Ce que ce test garde est donc l'invariant OBSERVABLE, pas une ligne : il virerait au rouge si
     * quelqu'un remplacait le paginateur, ou changeait la jointure d'une facon qui casse le
     * comptage. C'est moins que ce que j'avais annonce ; c'est ce qu'il fait.
     */
    public function testLeCompteAnnonceCorrespondAuxLignesRendues(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabA);

        $reseau = (new SousReseau())->setLibelle('Reseau a trois espaces')->setActif(true);

        // Trois espaces DU MEME site : c'est la multiplication qu'on veut voir ecrasee.
        foreach (['Bassin nord', 'Bassin sud', 'Pediatrique'] as $nom) {
            $socle = (new Espace())->setNom($nom)->setEtablissement($etabA)->setType('bassin');
            $em->persist($socle);

            $espace = (new EspaceAcces())->setLibelle($nom)->setEspaceSocle($socle);
            $espace->setEtablissement($etabA);
            $em->persist($espace);

            $reseau->addEspace($espace);
        }

        $em->persist($reseau);
        $em->flush();

        $client->request('GET', '/api/sous_reseaus', $entete + ['query' => ['itemsPerPage' => 100]]);
        self::assertResponseIsSuccessful();

        $rendu = $client->getResponse()->toArray();
        $membres = $rendu['member'] ?? $rendu['hydra:member'] ?? [];

        // ⚠ L'ASSERTION PORTE SUR `totalItems`, ET C'EST LE SEUL ENDROIT OU LA DUPLICATION SE VOIT.
        //
        // J'avais d'abord compte les occurrences dans `member`. Ca ne mesurait rien : l'hydrateur
        // d'objets de Doctrine rend des entites racines UNIQUES -- les lignes dupliquees par la
        // jointure se rabattent sur le meme objet via la carte d'identite. Le test restait vert
        // avec ou sans `distinct()`, et je ne l'ai su qu'en retirant celui-ci pour voir.
        //
        // `totalItems` vient d'un COUNT sur la requete jointe, qui ne dedoublonne pas. Sans
        // `distinct()`, un sous-reseau de trois espaces compte pour trois : la pagination annonce
        // des pages vides, et l'ecran affiche un nombre de sous-reseaux qui n'existe pas.
        self::assertSame(
            \count($membres),
            $rendu['totalItems'] ?? -1,
            'le compte annonce doit egaler le nombre de lignes rendues : la jointure de '
            . 'cloisonnement porte sur un ManyToMany, et sans le `distinct()` de `restreindre()` '
            . 'le COUNT multiplie chaque sous-reseau par son nombre d\'espaces',
        );

        $libelles = array_map(static fn (array $r): string => (string) ($r['libelle'] ?? ''), $membres);
        self::assertContains(
            'Reseau a trois espaces',
            $libelles,
            'temoin : le sous-reseau fabrique doit etre rendu, sinon l\'egalite ci-dessus est vraie '
            . 'sur une liste vide',
        );
    }

    /**
     * CE QUE L'IDENTIFIANT PERMET, ET C'EST LA VRAIE QUESTION.
     *
     * ⚠ CE TEST NE MESURE PAS UNE FUITE, IL MESURE UNE CONSEQUENCE. Il etablit que le declenchement
     * du SOS n'exige RIEN d'autre que l'identifiant de l'espace — ni jeton, ni compte, ni
     * appartenance. C'est ce qui transforme la publication d'un UUID en capacite d'agir sur le site
     * d'un autre client.
     *
     * Il reste vert que la fuite soit fermee ou non : il decrit le point d'entree, pas le
     * cloisonnement. S'il devenait rouge, ce serait que quelqu'un a ferme le SOS — et alors la
     * publication d'identifiants redeviendrait un probleme mineur.
     */
    public function testLeDeclenchementDuSosNExigeQueLIdentifiantDeLEspace(): void
    {
        $client = static::createClient();
        $id = $this->idEspaceAcces();

        // Aucun jeton, aucun en-tete d'etablissement : c'est tout l'objet de la mesure.
        $client->request('POST', '/api/sport/espaces/' . $id . '/sos', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => [],
        ]);

        $code = $client->getResponse()->getStatusCode();

        self::assertNotSame(
            401,
            $code,
            'le declenchement SOS est declare PUBLIC_ACCESS : s\'il exigeait une authentification, '
            . 'la publication d\'identifiants d\'espaces perdrait sa consequence la plus grave',
        );
        self::assertNotSame(403, $code, 'idem : aucune permission n\'est exigee');
    }

    /**
     * Un espace d'acces sur `Patinoire B`, hors du perimetre du lecteur, range dans un sous-reseau.
     *
     * Les fixtures n'en posent qu'un, sur `Piscine A`, et aucun sous-reseau. On fabrique donc le
     * voisin — c'est ce qui manque pour qu'il y ait quelque chose a cloisonner.
     */
    private function espaceAccesChezLeVoisin(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB, 'temoin : l\'etablissement B doit exister');

        $socle = (new Espace())->setNom('Piste du voisin')->setEtablissement($etabB)->setType('piste');
        $em->persist($socle);

        $espace = (new EspaceAcces())
            ->setLibelle(self::LIBELLE_ETRANGER)
            ->setEspaceSocle($socle);
        $espace->setEtablissement($etabB);
        $em->persist($espace);

        // ⚠ DEUX SOUS-RESEAUX MONO-CLIENT, ET C'EST LE CAS REALISTE.
        //
        // Un sous-reseau MIXTE demontrerait aussi le mecanisme, mais l'interface ne peut pas en
        // produire : `TopologieAcces.jsx` ne propose que les espaces rendus par `/api/espace_acces`,
        // qui est cloisonne. Mesurer un cas que le produit ne fabrique pas conduirait a un correctif
        // taille pour lui — filtrer la collection serialisee espace par espace — la ou le defaut
        // reel se ferme en cloisonnant la racine.
        //
        // Le defaut ordinaire suffit : la collection rend les sous-reseaux de TOUS les clients,
        // chacun publiant les identifiants des espaces de son proprietaire.
        $sien = $em->getRepository(EspaceAcces::class)->find($this->idEspaceAcces());
        self::assertInstanceOf(EspaceAcces::class, $sien);

        $reseauSien = (new SousReseau())->setLibelle('Reseau Piscine A')->setActif(true);
        $reseauSien->addEspace($sien);
        $em->persist($reseauSien);

        $reseauEtranger = (new SousReseau())->setLibelle('Reseau du voisin')->setActif(true);
        $reseauEtranger->addEspace($espace);
        $em->persist($reseauEtranger);

        $em->flush();

        return (string) $espace->getId();
    }
}
