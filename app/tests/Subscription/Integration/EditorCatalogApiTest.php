<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\DataFixtures\SocleFixtures;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-6 — l'administration du catalogue d'offres, et ce qui la distingue de la vitrine.
 *
 * La vitrine **cache** ce qu'elle ne sait pas vendre : formule retirée, formule promettant une
 * capacité inexistante. Cet écran-ci les **montre**, parce que c'est le seul endroit où on peut les
 * corriger. Deux lectures du même catalogue, deux règles opposées, et c'est voulu — un test par
 * ressource, sinon quelqu'un alignera l'une sur l'autre en croyant supprimer une incohérence.
 */
final class EditorCatalogApiTest extends SocleApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /**
     * **Le cas qui distingue cet écran de la vitrine** : l'éditeur voit aussi ce qui n'est plus vendu.
     *
     * Une formule retirée de la vente disparaît de la page publique — un prospect ne doit pas
     * composer autour d'une offre qu'on ne vend plus. Mais si elle disparaissait aussi de
     * l'administration, plus personne ne pourrait la remettre en vente ni la supprimer.
     */
    public function testLediteurVoitAussiLesFormulesRetireesDeLaVente(): void
    {
        $this->sauterSiRouteAbsente('/editor/catalog/plans');

        $this->plan('essentiel', 'Essentiel', 4900, true);
        $this->plan('retire', 'Ancienne formule', 9900, false);

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/catalog/plans', $this->commeEditeur($client))->toArray();

        self::assertResponseIsSuccessful();

        $codes = array_column($reponse['member'] ?? $reponse['hydra:member'] ?? [], 'code');
        self::assertContains('essentiel', $codes);
        self::assertContains('retire', $codes, 'l administration doit voir ce que la vitrine cache');
    }

    /** Un autre établissement n'a rien à faire dans le catalogue commercial de l'éditeur. */
    public function testUnAutreEtablissementNaccedePasAuCatalogue(): void
    {
        $this->sauterSiRouteAbsente('/editor/catalog/plans');
        $this->plan('essentiel', 'Essentiel', 4900, true);

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/catalog/plans', [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /** L'éditeur met une option en vente depuis son administration. */
    public function testLediteurMetUneOptionEnVente(): void
    {
        $this->sauterSiRouteAbsente('/editor/catalog/options');

        $client = static::createClient();
        $client->request('POST', '/api/editor/catalog/options', $this->commeEditeur($client) + [
            'json' => ['capability' => 'reservation', 'label' => 'Réservation', 'monthlyPriceCents' => 1500],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->em()->getRepository(PlanOption::class)->findAll());
    }

    /** Et un exploitant ne peut pas en créer, même authentifié : l'écriture suit la même règle. */
    public function testUnAutreEtablissementNeCreePasDoption(): void
    {
        $this->sauterSiRouteAbsente('/editor/catalog/options');

        $client = static::createClient();
        $reponse = $client->request('POST', '/api/editor/catalog/options', [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
            'json' => ['capability' => 'reservation', 'label' => 'Intrusion', 'monthlyPriceCents' => 1],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertCount(0, $this->em()->getRepository(PlanOption::class)->findAll());
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

    private function sauterSiRouteAbsente(string $suffixe): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        foreach ($routeur->getRouteCollection() as $route) {
            if (str_ends_with($route->getPath(), $suffixe)) {
                return;
            }
        }

        self::markTestSkipped('`src/Subscription/Entity` absent de `mapping.paths` (C9).');
    }

    private function plan(string $code, string $label, int $prixCents, bool $actif): Plan
    {
        $plan = (new Plan())
            ->setCode($code)
            ->setLabel($label)
            ->setMonthlyPriceCents($prixCents)
            ->setIncludedCapabilities(['controle_acces'])
            ->setActive($actif);

        $this->em()->persist($plan);
        $this->em()->flush();

        return $plan;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
