<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\ProductAccessZone;
use App\DataFixtures\SocleFixtures;
use App\Fonctionnalite\Enum\CapaciteCode;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * UN PRODUIT PUBLIÉ DOIT SE VENDRE TEL QUE SON TYPE LE PROMET (lot des garde-fous, 08/10).
 *
 * Mesuré sur `main` (d2d27d1d) : une carte sans `CarteMultiEntrees` se publiait et devenait à la
 * borne un billet sans crédit, donc illimité ; un abonnement sans formule se vendait comme un
 * article simple, sans mandat ni échéancier ; une entrée sans zone se publiait sur un site au
 * contrôle d'accès actif et n'ouvrait aucune porte (D87). La garde ne regardait que le libellé, le
 * site, le canal, le prix et la catégorie comptable.
 *
 * Chaque refus a son témoin : le même produit, complété, passe. Une garde qui refuserait tout
 * serait verte ici sans rien prouver.
 */
final class PublicationByTypeTest extends OffreApiTestCase
{
    public function testACardWithoutItsCardIsRefusedAndCreatesItInTheSameCall(): void
    {
        [$client, $header] = $this->admin();

        $bare = $this->create($client, $header, OffreFixtures::TYPE_CARTE, 'Carte sans carte');
        self::assertContains('carte', $this->missingCodes($client, $header, $bare['id']));
        $client->request('POST', '/api/produits/'.$bare['id'].'/publier', $header);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Créez sa carte', $this->detail($client));

        $full = $this->create($client, $header, OffreFixtures::TYPE_CARTE, 'Carte 10=12', [
            'carte' => ['nbPaye' => 10, 'nbCredite' => 12],
        ]);
        self::assertSame(12, $full['carte']['nbCredite'] ?? null, 'la carte doit naître avec le produit, dans le même appel');
        self::assertNotContains('carte', $this->missingCodes($client, $header, $full['id']));
    }

    public function testASubscriptionWithoutFormulaIsRefusedAndCreatesItInTheSameCall(): void
    {
        [$client, $header] = $this->admin();

        $bare = $this->create($client, $header, OffreFixtures::TYPE_ABONNEMENT, 'Abonnement sans formule');
        self::assertContains('formule', $this->missingCodes($client, $header, $bare['id']));
        $client->request('POST', '/api/produits/'.$bare['id'].'/publier', $header);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Créez sa formule', $this->detail($client));

        $full = $this->create($client, $header, OffreFixtures::TYPE_ABONNEMENT, 'Abonnement mensuel', [
            'formule' => ['periodicite' => 'mensuel', 'sepaActif' => true, 'jourPrelevement' => 5],
        ]);
        self::assertSame('mensuel', $full['formule']['periodicite'] ?? null, 'la formule doit naître avec le produit');
        self::assertTrue($full['formule']['sepaActif'] ?? false);
        self::assertNotContains('formule', $this->missingCodes($client, $header, $full['id']));
    }

    public function testAnEntryWithoutZoneIsRefusedWhereAccessControlIsOn(): void
    {
        [$client, $header] = $this->admin();
        $this->accessControl(true);
        $this->remettreEnBrouillon(OffreFixtures::PRODUIT_ENTREE);
        $id = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        $client->request('POST', '/api/produits/'.$id.'/publier', $header);
        self::assertResponseStatusCodeSame(422);
        $detail = $this->detail($client);
        self::assertStringContainsString('aucune porte', $detail);
        self::assertStringContainsString(SocleFixtures::ETAB_A_NOM, $detail, 'le message doit nommer le site concerné');

        $this->declareZone($id);
        self::assertSame([], $this->missingCodes($client, $header, $id));
        $client->request('POST', '/api/produits/'.$id.'/publier', $header);
        self::assertResponseIsSuccessful();
    }

    /** Le témoin : sans contrôle d'accès sur le site, aucune zone n'est exigée. */
    public function testNoZoneIsRequiredWhereAccessControlIsOff(): void
    {
        [$client, $header] = $this->admin();
        $this->accessControl(false);
        $this->remettreEnBrouillon(OffreFixtures::PRODUIT_ENTREE);

        $client->request('POST', '/api/produits/'.$this->idProduit(OffreFixtures::PRODUIT_ENTREE).'/publier', $header);
        self::assertResponseIsSuccessful();
    }

    /** La conversion était l'autre porte : une entrée publiée devenait une carte publiée sans carte. */
    public function testAPublishedProductIsNotConvertedIntoAnUnsellableOne(): void
    {
        [$client, $header] = $this->admin();
        // Un manque ANTÉRIEUR (aucune zone, contrôle d'accès actif) : ce n'est pas la conversion qui
        // le crée, le refus ne doit donc pas l'invoquer.
        $this->accessControl(true);
        $id = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        $client->request('POST', '/api/produits/'.$id.'/convertir', $header + [
            'json' => ['nouveauType' => $this->idType(OffreFixtures::TYPE_CARTE), 'confirmer' => true],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Créez sa carte', $this->detail($client));
        self::assertStringNotContainsString('aucune porte', $this->detail($client));
        $this->em()->clear();
        self::assertSame(OffreFixtures::TYPE_ENTREE, $this->em()->find(Produit::class, Uuid::fromString($id))?->getType()?->getCode(), 'rien ne doit être écrit');
    }

    /** Une formule « personnalisée » se publie, puis échoue à la souscription : la garde la refuse. */
    public function testACustomPeriodicityIsNotPublishable(): void
    {
        [$client, $header] = $this->admin();
        $abo = $this->create($client, $header, OffreFixtures::TYPE_ABONNEMENT, 'Abonnement libre', [
            'formule' => ['periodicite' => 'personnalise'],
        ]);
        self::assertContains('formule', $this->missingCodes($client, $header, $abo['id']));
    }

    /** La route neuve lit le produit par le fournisseur standard : le voisin n'y est pas plus visible. */
    public function testReadinessOfAnotherEstablishmentsProductIsNotFound(): void
    {
        [$client, $header] = $this->admin();
        $em = $this->em();
        $produit = (new Produit())->setLibelle(['fr' => 'Chez B'])->setLibelleRecherche('Chez B')->setCode('PRD-CHEZ-B')
            ->setType($em->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]));
        $produit->addEtablissement($this->site(SocleFixtures::ETAB_B_NOM));
        $em->persist($produit);
        $em->flush();

        $client->request('GET', '/api/produits/'.$produit->getId().'/readiness', $header);
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    private function admin(): array
    {
        [$client, $token, $idA] = $this->adminSurA();

        return [$client, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]];
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function create(Client $client, array $header, string $type, string $label, array $extra = []): array
    {
        $created = $client->request('POST', '/api/produits', $header + ['json' => [
            'libelle' => ['fr' => $label],
            'type' => '/api/type_produits/'.$this->idType($type),
            'canaux' => ['guichet'],
        ] + $extra])->toArray();
        self::assertResponseStatusCodeSame(201);

        return $created;
    }

    /**
     * @param array<string, mixed> $header
     *
     * @return list<string>
     */
    private function missingCodes(Client $client, array $header, string $id): array
    {
        $body = $client->request('GET', '/api/produits/'.$id.'/readiness', $header)->toArray();

        return array_column($body['missing'], 'code');
    }

    private function detail(Client $client): string
    {
        return (string) ($client->getResponse()?->toArray(false)['detail'] ?? '');
    }

    private function accessControl(bool $on): void
    {
        /** @var Fonctionnalites $features */
        $features = static::getContainer()->get(Fonctionnalites::class);
        $features->definir($this->site(SocleFixtures::ETAB_A_NOM), CapaciteCode::ControleAcces->value, $on, null);
    }

    private function declareZone(string $productId): void
    {
        $em = $this->em();
        $site = $this->site(SocleFixtures::ETAB_A_NOM);
        $room = (new Espace())->setNom('Bassin')->setEtablissement($site)->setType('bassin');
        $space = (new EspaceAcces())->setLibelle('Bassin')->setEspaceSocle($room)->setEtablissement($site);
        $em->persist($room);
        $em->persist($space);
        $em->persist((new ProductAccessZone(Uuid::fromString($productId), $space))->setEstablishment($site));
        $em->flush();
    }

    private function site(string $name): Etablissement
    {
        return $this->entite(Etablissement::class, ['nom' => $name]);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
