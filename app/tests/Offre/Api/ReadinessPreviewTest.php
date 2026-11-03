<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Fonctionnalite\Enum\CapaciteCode;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Categorie;
use App\Offre\Entity\Produit;
use App\Offre\Enum\AxeCategorie;
use App\Offre\Entity\TypeProduit;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /produits/readiness-preview : la garde de publication, jugée AVANT la création (refonte de la
 * création, lot 1). La modale en déduit son bouton : « Créer et mettre en vente » si rien ne
 * manquerait, sinon « Créer le produit » et la phrase de ce qui restera à faire.
 *
 * Chaque manque a son témoin : le même corps, complété, n'en a plus. Et rien n'est écrit.
 */
final class ReadinessPreviewTest extends OffreApiTestCase
{
    public function testACompleteEntryLacksNothingOnceItsPriceIsAnnounced(): void
    {
        [$client, $header] = $this->admin();
        $this->accessControl(false);

        self::assertSame([], $this->preview($client, $header, ['prixPrevu' => '6.50']));
        // Témoin : sans prix annoncé, le prix manque — la garde est bien celle de la publication.
        self::assertSame(['prix'], $this->preview($client, $header));
    }

    public function testAnEntryLacksItsZoneWhereTheSiteControlsAccess(): void
    {
        [$client, $header] = $this->admin();
        $this->accessControl(true);

        $body = $client->request('POST', '/api/produits/readiness-preview', $header + ['json' => $this->body() + ['prixPrevu' => '6.50']])->toArray();
        self::assertSame(['zone_acces'], array_column($body['missing'], 'code'));
        self::assertStringContainsString('choisissez les zones', $body['missing'][0]['message']);
    }

    public function testACardWithoutItsCardIsMissingIt(): void
    {
        [$client, $header] = $this->admin();
        $this->accessControl(false);

        self::assertContains('carte', $this->preview($client, $header, ['prixPrevu' => '55'], OffreFixtures::TYPE_CARTE));
        self::assertNotContains('carte', $this->preview($client, $header, [
            'prixPrevu' => '55', 'carte' => ['nbPaye' => 10, 'nbCredite' => 11],
        ], OffreFixtures::TYPE_CARTE));
    }

    public function testNothingIsWritten(): void
    {
        [$client, $header] = $this->admin();
        $avant = $this->em()->getRepository(Produit::class)->count([]);

        $this->preview($client, $header, ['prixPrevu' => '6.50', 'carte' => ['nbPaye' => 10, 'nbCredite' => 10]], OffreFixtures::TYPE_CARTE);
        $this->preview($client, $header, ['prixPrevu' => '6.50']);

        $this->em()->clear();
        self::assertSame($avant, $this->em()->getRepository(Produit::class)->count([]), "l'aperçu ne doit créer aucun produit");
        self::assertNull($this->em()->getRepository(Produit::class)->findOneBy(['libelleRecherche' => 'Aperçu modale']));
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    private function admin(): array
    {
        [$client, $token, $idA] = $this->adminSurA();

        return [$client, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]];
    }

    /** @return array<string, mixed> */
    private function body(string $type = OffreFixtures::TYPE_ENTREE): array
    {
        $site = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        return [
            'libelle' => ['fr' => 'Aperçu modale'],
            'type' => '/api/type_produits/'.$this->entite(TypeProduit::class, ['code' => $type])->getId(),
            'canaux' => ['guichet', 'en_ligne'],
            'etablissements' => ['/api/etablissements/'.$site->getId()],
            'categories' => ['/api/categories/'.$this->entite(Categorie::class, ['axe' => AxeCategorie::Comptable])->getId()],
        ];
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $extra
     *
     * @return list<string>
     */
    private function preview(Client $client, array $header, array $extra = [], string $type = OffreFixtures::TYPE_ENTREE): array
    {
        $body = $client->request('POST', '/api/produits/readiness-preview', $header + ['json' => $this->body($type) + $extra])->toArray();
        self::assertResponseIsSuccessful();

        return array_column($body['missing'], 'code');
    }

    private function accessControl(bool $on): void
    {
        /** @var Fonctionnalites $features */
        $features = static::getContainer()->get(Fonctionnalites::class);
        $features->definir(
            $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]),
            CapaciteCode::ControleAcces->value,
            $on,
            null,
        );
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
