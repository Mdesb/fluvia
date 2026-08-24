<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-6 — l'écran de pilotage de l'éditeur, et qui a le droit de le lire.
 *
 * **C'est l'inverse exact de la vitrine.** Cette ressource porte des noms de clients, des montants et
 * l'état de leur plateforme. Un client qui la lirait verrait la liste des autres clients de
 * l'éditeur, leurs formules et ce qu'ils paient — la fuite commerciale la plus complète possible.
 *
 * Le contrôle n'est pas une permission mais une **identité de tenant** : une permission se délègue,
 * s'hérite et se recopie dans un rôle modèle ; l'appartenance au tenant éditeur, non.
 */
final class EditorSubscriptionsApiTest extends SocleApiTestCase
{
    private const CLIENT_NOM = 'Camping des Pins';

    protected function setUp(): void
    {
        parent::setUp();

        // L'éditeur est désigné par l'environnement, sans repli (D36). Ici : l'établissement A.
        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /** L'éditeur voit ses abonnements, avec le nom du client et l'état de sa plateforme. */
    public function testLediteurVoitSesAbonnements(): void
    {
        $this->sauterSiRouteAbsente();
        $this->abonnement();

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/subscriptions', $this->commeEditeur($client))->toArray();

        self::assertResponseIsSuccessful();

        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(1, $membres);
        self::assertSame(self::CLIENT_NOM, $membres[0]['customerName']);
        self::assertSame('draft', $membres[0]['status']);
        // Aucun provisionnement demandé pour un abonnement encore au panier.
        self::assertNull($membres[0]['provisioningStatus']);
    }

    /**
     * Un exploitant authentifié, sur son propre établissement, ne voit rien — et reçoit 404.
     *
     * **404 et non 403**, délibérément : un 403 lui confirmerait que cet écran existe et qu'il
     * concerne l'éditeur. Un 404 ne lui apprend rien. C'est la discipline du reste du cloisonnement
     * du dépôt (D3).
     */
    public function testUnAutreEtablissementNeVoitRienEtRecoit404(): void
    {
        $this->sauterSiRouteAbsente();
        $this->abonnement();

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/subscriptions', [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertStringNotContainsString(self::CLIENT_NOM, (string) $reponse->getContent(false), 'aucun nom de client ne doit fuir dans le refus');
    }

    /** Sans jeton, rien : cet écran n'est pas public, contrairement au catalogue d'offres. */
    public function testSansJetonLaccesEstRefuse(): void
    {
        $this->sauterSiRouteAbsente();
        $this->abonnement();

        $reponse = static::createClient()->request('GET', '/api/editor/subscriptions');

        self::assertSame(401, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    // ---------------------------------------------------------------- montage

    /** @return array<string, mixed> */
    private function commeEditeur(object $client): array
    {
        /** @var \ApiPlatform\Symfony\Bundle\Test\Client $client */
        return [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];
    }

    /** Voir `PublicCatalogApiTest` : même garde, même raison (C9). */
    private function sauterSiRouteAbsente(): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        foreach ($routeur->getRouteCollection() as $route) {
            if (str_ends_with($route->getPath(), '/editor/subscriptions')) {
                return;
            }
        }

        self::markTestSkipped('`src/Subscription/ApiResource` absent de `mapping.paths` (C9).');
    }

    private function abonnement(): Subscription
    {
        $editeur = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($editeur instanceof Etablissement);

        $prospect = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale(self::CLIENT_NOM)
            ->setEmail('contact@campingdespins.test')
            ->setGroupe($editeur->getRegion()?->getGroupe())
            ->setEtablissementCreation($editeur);
        $this->em()->persist($prospect);
        $this->em()->flush();

        $plan = (new Plan())
            ->setCode('essentiel')
            ->setLabel('Essentiel')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities(['controle_acces'])
            ->setActive(true);
        $this->em()->persist($plan);

        $abonnement = (new Subscription())
            ->setCustomerReference($prospect->getId()->toRfc4122())
            ->setPlan($plan);
        $this->em()->persist($abonnement);
        $this->em()->flush();

        return $abonnement;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
