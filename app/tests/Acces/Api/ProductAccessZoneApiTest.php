<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\ProductAccessZone;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * DÉCLARER « CE PRODUIT OUVRE CETTE ZONE » PAR L'API.
 *
 * `ProductAccessZoneProjectionTest` prouve que la déclaration descend sur le droit. Celui-ci prouve
 * qu'on peut la POSER — et surtout qu'on ne peut pas la poser n'importe où.
 *
 * ⚠ LE DEUXIÈME TEST EST LE PLUS IMPORTANT, ET IL NE PARLE PAS DE ZONES.
 *
 * Le champ `space` est dans le groupe d'écriture : son IRI vient donc du client. L'extension de
 * périmètre ne couvre que les collections servies par le fournisseur standard ; la désérialisation
 * d'une relation passe par un `find()` que rien ne borne. Sans le contrôle du processeur, on
 * estampillerait la déclaration à SON établissement en pointant la zone d'un AUTRE — une ligne qui
 * traverse la frontière sans qu'aucune requête ne la montre.
 *
 * Et la réponse attendue est 404, pas 403 : un 403 confirmerait que cette zone existe ailleurs, ce
 * qui fait de la route un oracle d'énumération (D6).
 */
final class ProductAccessZoneApiTest extends AccesApiTestCase
{
    /**
     * POSER UNE DÉCLARATION, ET LA RELIRE PAR LE FILTRE.
     *
     * La relecture n'est pas décorative : `productRef` est une référence libre, et un `SearchFilter`
     * posé dessus rendrait une liste VIDE sans rien signaler (D58). Le test échouerait ici, et c'est
     * exactement la raison d'être de `ProductRefFilter`.
     */
    public function testDeclarerUneZonePuisLaRelireParProduit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $produit = (string) Uuid::v4();

        $client->request('POST', '/api/product_access_zones', $entete + [
            'json' => [
                'productRef' => $produit,
                'space' => '/api/espace_acces/' . $this->idEspaceAcces(),
            ],
        ]);

        self::assertResponseStatusCodeSame(201);

        $client->request('GET', '/api/product_access_zones?productRef=' . $produit, $entete);

        self::assertResponseIsSuccessful();
        $membres = $client->getResponse()->toArray()['member'] ?? [];
        self::assertCount(1, $membres, 'le filtre doit retrouver la déclaration qu’on vient de poser');
        self::assertSame($produit, $membres[0]['productRef'] ?? null);
    }

    /**
     * UNE ZONE DU VOISIN EST INDISCERNABLE D'UNE ZONE QUI N'EXISTE PAS.
     *
     * ⚠ CE TEST N'ASSERTE PAS UN NUMÉRO, ET C'EST DÉLIBÉRÉ.
     *
     * J'attendais 404 ; la réponse observée est 400. `AbstractItemNormalizer::getResourceFromIri()`
     * attrape l'`ItemNotFoundException` levée en résolvant l'IRI et la ré-emballe en erreur de
     * désérialisation. Vouloir forcer le 404 supposerait de déclarer `exception_to_status` — ce qui,
     * essayé la veille sur un autre cas, remplace toute la table par défaut d'API Platform et fait
     * basculer treize réponses en 500. Le remède est pire que le mal.
     *
     * Et surtout : **le numéro n'est pas la propriété de sécurité.** Ce qu'on protège, c'est qu'un
     * appelant ne puisse pas distinguer « cette zone n'existe pas » de « cette zone existe, mais chez
     * quelqu'un d'autre ». Sinon la route devient un oracle d'énumération des zones du voisin (D6).
     *
     * On compare donc les deux réponses l'une à l'autre, plutôt que chacune à une constante. C'est
     * exactement l'énoncé qu'on veut tenir, et il reste vrai si API Platform change de code demain.
     */
    public function testUneZoneDuVoisinEstIndiscernableDUneZoneInexistante(): void
    {
        [$client, $entete] = $this->adminSurA();

        $voisine = $this->reponsePourEspace($client, $entete, $this->creerEspaceChezLeVoisin());
        $inexistante = $this->reponsePourEspace($client, $entete, (string) Uuid::v4());

        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        // Deux échecs identiques ne prouvent rien s'ils sont identiques parce que TOUT échoue. On
        // vérifie donc d'abord qu'une zone légitime, elle, passe.
        $legitime = $this->reponsePourEspace($client, $entete, $this->idEspaceAcces());
        self::assertSame(201, $legitime['statut'], 'témoin : une zone du bon établissement doit être acceptée');

        self::assertSame($inexistante['statut'], $voisine['statut'], 'le statut ne doit pas trahir l’existence de la zone voisine');
        self::assertSame($inexistante['titre'], $voisine['titre'], 'le libellé de l’erreur ne doit pas trahir davantage');
        self::assertNotSame(201, $voisine['statut'], 'et la déclaration ne doit surtout pas être acceptée');
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array{statut: int, titre: string}
     */
    private function reponsePourEspace(object $client, array $entete, string $idEspace): array
    {
        $client->request('POST', '/api/product_access_zones', $entete + [
            'json' => [
                'productRef' => (string) Uuid::v4(),
                'space' => '/api/espace_acces/' . $idEspace,
            ],
        ]);

        $reponse = $client->getResponse();
        $corps = 201 === $reponse->getStatusCode() ? [] : $reponse->toArray(false);

        return [
            'statut' => $reponse->getStatusCode(),
            // Le titre, pas le détail : le détail contient l'IRI demandée, donc forcément
            // l'identifiant que l'appelant vient d'écrire lui-même. Il ne lui apprend rien.
            'titre' => (string) ($corps['title'] ?? ''),
        ];
    }

    /**
     * RETIRER UNE DÉCLARATION.
     *
     * ⚠ La liste est vérifiée NON VIDE avant la suppression. Sans ce témoin, une route de création
     * défaillante rendrait ce test vert : on constaterait l'absence de ce qui n'avait jamais existé.
     */
    public function testRetirerUneDeclaration(): void
    {
        [$client, $entete] = $this->adminSurA();
        $produit = (string) Uuid::v4();

        $client->request('POST', '/api/product_access_zones', $entete + [
            'json' => [
                'productRef' => $produit,
                'space' => '/api/espace_acces/' . $this->idEspaceAcces(),
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $iri = $client->getResponse()->toArray()['@id'] ?? null;
        self::assertIsString($iri, 'témoin : la déclaration a bien été créée avant qu’on la retire');

        $client->request('DELETE', $iri, $entete);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/product_access_zones?productRef=' . $produit, $entete);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getResponse()->toArray()['member'] ?? []);
    }

    /**
     * Un espace rattaché à l'établissement B. On repart du socle de l'espace de A puis on force
     * l'établissement : `setEspaceSocle()` déduit l'établissement du socle, donc l'ordre compte.
     */
    private function creerEspaceChezLeVoisin(): string
    {
        $em = $this->em();

        $modele = $em->getRepository(EspaceAcces::class)->find($this->idEspaceAcces());
        self::assertInstanceOf(EspaceAcces::class, $modele);

        $etabB = $em->getRepository(Etablissement::class)->find($this->idEtablissement(SocleFixtures::ETAB_B_NOM));
        self::assertInstanceOf(Etablissement::class, $etabB);

        $espace = (new EspaceAcces())
            ->setLibelle('Zone du voisin')
            ->setEspaceSocle($modele->getEspaceSocle())
            ->setEtablissement($etabB);

        $em->persist($espace);
        $em->flush();

        return (string) $espace->getId();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
