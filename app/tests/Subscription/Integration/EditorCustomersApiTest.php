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
 * ED-6 — la fiche client de l'éditeur, et la frontière qu'elle ne doit jamais franchir.
 *
 * **Le risque n'est pas théorique.** Le CRM de l'éditeur et celui de chaque exploitant sont la même
 * table. Sans filtre, cet écran listerait les nageurs d'une piscine et les visiteurs d'un musée à
 * côté des prospects de l'éditeur — une fuite de données personnelles de clients finaux vers un
 * tiers, à travers un écran d'administration qui n'a aucune raison de les voir.
 */
final class EditorCustomersApiTest extends SocleApiTestCase
{
    private const PROSPECT = 'Camping des Pins';
    private const CLIENT_FINAL = 'Nageur Dupont';

    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /**
     * **Le test qui compte** : seuls les clients du CRM de l'éditeur sont listés.
     *
     * Le client final créé sur l'établissement B est un nageur ; il n'a rien à faire dans le commerce
     * de l'éditeur, et sa présence ici serait une fuite pure et simple.
     */
    public function testSeulsLesClientsDuCrmDeLediteurSontListes(): void
    {
        $this->sauterSiRouteAbsente('/editor/customers');

        $this->client(SocleFixtures::ETAB_A_NOM, self::PROSPECT, 'contact@campingdespins.test');
        $this->client(SocleFixtures::ETAB_B_NOM, self::CLIENT_FINAL, 'nageur@exemple.test');

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/customers', $this->commeEditeur($client))->toArray();

        self::assertResponseIsSuccessful();

        $noms = array_column($reponse['member'] ?? $reponse['hydra:member'] ?? [], 'name');
        self::assertContains(self::PROSPECT, $noms);
        self::assertNotContains(self::CLIENT_FINAL, $noms, 'un client final d exploitant ne doit jamais apparaitre ici');
    }

    /** Et demandé nommément par son identifiant, il reste introuvable. */
    public function testUnClientDexploitantResteIntrouvableParSonIdentifiant(): void
    {
        $this->sauterSiRouteAbsente('/editor/customers');

        $nageur = $this->client(SocleFixtures::ETAB_B_NOM, self::CLIENT_FINAL, 'nageur@exemple.test');

        $client = static::createClient();
        $reponse = $client->request(
            'GET',
            '/api/editor/customers/'.$nageur->getId()->toRfc4122(),
            $this->commeEditeur($client),
        );

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertStringNotContainsString(self::CLIENT_FINAL, (string) $reponse->getContent(false));
    }

    /** La fiche assemble en une lecture ce qu'on veut savoir quand le client appelle. */
    public function testLaFicheAssembleLabonnementEnUneLecture(): void
    {
        $this->sauterSiRouteAbsente('/editor/customers');

        $prospect = $this->client(SocleFixtures::ETAB_A_NOM, self::PROSPECT, 'contact@campingdespins.test');
        $this->abonnement($prospect);

        $client = static::createClient();
        $fiche = $client->request(
            'GET',
            '/api/editor/customers/'.$prospect->getId()->toRfc4122(),
            $this->commeEditeur($client),
        )->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(self::PROSPECT, $fiche['name']);
        self::assertCount(1, $fiche['subscriptions']);
        self::assertSame('Essentiel', $fiche['subscriptions'][0]['planLabel']);
        self::assertSame(4900, $fiche['subscriptions'][0]['monthlyPriceCents']);
        // Pas de mandat signé, pas de provisionnement demandé : la fiche le dit par des nuls, pas par
        // des sections absentes que l'écran devrait deviner.
        self::assertNull($fiche['mandate']);
        self::assertNull($fiche['subscriptions'][0]['provisioningStatus']);
        self::assertSame([], $fiche['supportAccesses']);
    }

    // ---------------------------------------------------------------- montage

    /** @return array<string, mixed> */
    /**
     * ⚠ « L'ARGENT EST UN RÔLE À PART » — et ce test dit ce que ça veut dire concrètement.
     *
     * Une permission sur la ressource n'y suffisait pas : la fiche PORTE les montants. Autoriser
     * l'assistance à la lire lui aurait donné le chiffre d'affaires de chaque client. C'est donc la
     * RÉPONSE qui se tait, pas l'accès qui se ferme — l'agent garde ce dont il a besoin, le libellé
     * du plan, et perd ce qui ne le regarde pas.
     *
     * ⚠ LES DEUX FACES SONT VÉRIFIÉES, ET LA SECONDE EST CELLE QUI COMPTE. Sans elle,
     * `monthlyPriceCents === null` serait vert même si le champ était toujours nul — donc même si
     * rien ne marchait. Une assertion vraie pour une raison qui n'est pas la sienne.
     */
    public function testLassistanceLitLaFicheMaisPasCeQueLeClientPaie(): void
    {
        $this->sauterSiRouteAbsente('/editor/customers');

        $prospect = $this->client(SocleFixtures::ETAB_A_NOM, self::PROSPECT, 'contact@campingdespins.test');
        $this->abonnement($prospect);
        $url = '/api/editor/customers/'.$prospect->getId()->toRfc4122();

        // 1. L'assistance : la fiche, sans l'argent.
        $client = static::createClient();
        $fiche = $client->request('GET', $url, $this->commeAssistance($client))->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame(self::PROSPECT, $fiche['name'], 'L’agent doit savoir de quel client on parle.');
        self::assertSame('Essentiel', $fiche['subscriptions'][0]['planLabel'], 'Le plan reste : un agent doit savoir sur quelle offre est son interlocuteur.');
        self::assertNull($fiche['subscriptions'][0]['monthlyPriceCents'], 'Le prix ne le regarde pas.');
        self::assertNull($fiche['mandate'], 'Les coordonnées bancaires non plus.');

        // 2. L'écran de facturation lui est refusé — 403 et non 404 : il est chez lui, lui cacher
        //    l'existence de l'écran ne protégerait rien et l'empêcherait de comprendre.
        $client->request('GET', '/api/editor/billing', $this->commeAssistance($client));
        self::assertResponseStatusCodeSame(403);

        // 3. ⚠ LA MÊME FICHE, VUE PAR QUI A LE DROIT. Sans cette lecture, tout ce qui précède
        //    serait vrai d'un champ mort.
        $ficheDirection = $client->request('GET', $url, $this->commeEditeur($client))->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(4900, $ficheDirection['subscriptions'][0]['monthlyPriceCents'], 'Le champ existe et se remplit : le nul précédent est bien un silence, pas un vide.');
    }

    /** Un compte de l'éditeur qui porte l'assistance et rien d'autre. */
    private function commeAssistance(object $client): array
    {
        /** @var \ApiPlatform\Symfony\Bundle\Test\Client $client */
        return [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ASSISTANCE_EDITEUR_EMAIL, SocleFixtures::ASSISTANCE_EDITEUR_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];
    }

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

        self::markTestSkipped('`src/Subscription/ApiResource` absent de `mapping.paths` (C9).');
    }

    private function client(string $nomEtablissement, string $raisonSociale, string $email): Client
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        \assert($etablissement instanceof Etablissement);

        $client = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale($raisonSociale)
            ->setEmail($email)
            ->setGroupe($etablissement->getRegion()?->getGroupe())
            ->setEtablissementCreation($etablissement);

        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    private function abonnement(Client $prospect): Subscription
    {
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
