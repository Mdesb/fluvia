<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-5 — l'entrée du tunnel depuis le site vitrine, ouverte sans compte.
 *
 * **C'est le seul point non authentifié qui écrit dans le CRM de l'éditeur.** Ces tests portent donc
 * moins sur le chemin nominal que sur ce qui l'entoure : ce qu'on refuse, comment on le refuse, et ce
 * qu'on ne laisse jamais fuir dans un message d'erreur.
 */
final class PublicCartApiTest extends SocleApiTestCase
{
    private const COMPRISE = 'controle_acces';
    private const OPTION = 'reservation';
    private const NON_VENDABLE = 'casiers';
    private const PLAN = 'essentiel';

    protected function setUp(): void
    {
        parent::setUp();

        // La désignation de l'éditeur vient de l'environnement et n'a pas de repli (D36). On la pose
        // avant que le noyau ne démarre, sur l'établissement des fixtures : sans elle, le tunnel
        // refuse de s'exécuter — ce qui est le comportement voulu, mais pas ce qu'on teste ici.
        $id = $this->editeurId();
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;

        $this->remettreLeCompteurAZero();
    }

    /**
     * Le limiteur de débit compte dans le cache, qui survit d'un test à l'autre.
     *
     * Découvert en le subissant : cinq tests qui ouvrent chacun un panier depuis la même adresse
     * épuisent la part horaire, et les suivants reçoivent 429 sans rapport avec ce qu'ils testent.
     * C'est la preuve que le limiteur fonctionne, et la preuve qu'un test qui partage un compteur
     * avec ses voisins n'est pas isolé. On repart donc d'un compteur vide, comme on repart d'une base
     * vide.
     */
    private function remettreLeCompteurAZero(): void
    {
        static::bootKernel();

        /** @var \Symfony\Contracts\Cache\CacheInterface&\Psr\Cache\CacheItemPoolInterface $cache */
        $cache = static::getContainer()->get('cache.app');
        $cache->clear();

        self::ensureKernelShutdown();
    }

    /** Le chemin nominal : un prospect compose son panier depuis la vitrine, sans compte. */
    public function testUnProspectOuvreSonPanierSansCompte(): void
    {
        $this->sauterSiRouteAbsente();
        $this->offre();

        $reponse = static::createClient()->request('POST', '/api/editor/carts', [
            'json' => [
                'companyName' => 'Camping des Pins',
                'email' => 'contact@campingdespins.test',
                'planCode' => self::PLAN,
                'capabilities' => [self::COMPRISE, self::OPTION],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();

        // Le prix vient du serveur, pas de ce que la page avait additionné : 4900 (formule) + 1500.
        self::assertSame(6400, $reponse['monthlyPriceCents']);
        self::assertSame(self::PLAN, $reponse['planCode']);

        $abonnement = $this->em()->getRepository(Subscription::class)->find($reponse['id']);
        self::assertInstanceOf(Subscription::class, $abonnement);
        self::assertSame(SubscriptionStatus::Draft, $abonnement->getStatus(), 'rien n\'est vendu à cette étape');
    }

    /**
     * Composer un panier ne crée aucun établissement.
     *
     * C'est la frontière entre « un prospect s'intéresse » et « un client a payé ». La franchir ici
     * ouvrirait un service que rien ne paie, à quiconque poste un formulaire.
     */
    public function testComposerUnPanierNouvreAucunService(): void
    {
        $this->sauterSiRouteAbsente();
        $this->offre();
        $avant = \count($this->em()->getRepository(Etablissement::class)->findAll());

        static::createClient()->request('POST', '/api/editor/carts', [
            'json' => [
                'companyName' => 'Piscine du Lac',
                'email' => 'contact@piscinedulac.test',
                'planCode' => self::PLAN,
                'capabilities' => [self::COMPRISE],
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($avant, \count($this->em()->getRepository(Etablissement::class)->findAll()));
    }

    /** Une adresse mal formée est refusée ici, pas au provisionnement — après paiement. */
    public function testUneAdresseMalFormeeEstRefuseeToutDeSuite(): void
    {
        $this->sauterSiRouteAbsente();
        $this->offre();

        static::createClient()->request('POST', '/api/editor/carts', [
            'json' => [
                'companyName' => 'Camping des Pins',
                'email' => 'pas-une-adresse',
                'planCode' => self::PLAN,
                'capabilities' => [self::COMPRISE],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Une capacité non vendable est refusée en 422 avec un message lisible, jamais en 500.
     *
     * La vitrine est vue par des gens qui ne sont pas clients : une trace de pile ou un nom de classe
     * y coûte cent fois plus cher qu'en interne.
     *
     * **Ce test est né d'un défaut réel.** On relayait le message d'`InvalidOfferException`, en le
     * croyant destiné à un humain. Il l'était — à l'éditeur : « Ajoute-la au catalogue d'options avant
     * de la proposer », au tutoiement, une consigne d'administration servie à un prospect. Le test
     * vérifie donc à la fois le code de statut **et** que le message reste celui du visiteur.
     */
    public function testUneCapaciteNonVendableEstRefuseeSansFuiteDeMessageInterne(): void
    {
        $this->sauterSiRouteAbsente();
        $this->offre();

        $reponse = static::createClient()->request('POST', '/api/editor/carts', [
            'json' => [
                'companyName' => 'Camping des Pins',
                'email' => 'contact@campingdespins.test',
                'planCode' => self::PLAN,
                'capabilities' => [self::NON_VENDABLE],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);

        // Le champ `detail` est ce que voit le visiteur ; la trace n'existe qu'en mode débogage et
        // n'est pas servie en production. On assert donc sur `detail`, pas sur le corps entier.
        $message = $this->detail($reponse->toArray(false));

        self::assertStringNotContainsString('Ajoute-la', $message, 'consigne d\'administration servie au visiteur');
        self::assertStringNotContainsString('catalogue d\'options', $message, 'vocabulaire interne');
        self::assertStringNotContainsString(self::NON_VENDABLE, $message, 'code technique à l\'écran');
        self::assertStringContainsString('Rechargez', $message, 'le visiteur doit savoir quoi faire');
    }

    /** Sans les champs indispensables, on refuse avant d'écrire quoi que ce soit. */
    public function testLesChampsIndispensablesSontExiges(): void
    {
        $this->sauterSiRouteAbsente();
        $this->offre();

        static::createClient()->request('POST', '/api/editor/carts', [
            'json' => ['companyName' => 'Sans e-mail', 'planCode' => self::PLAN, 'capabilities' => []],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Au-delà de la part horaire, l'adresse est refusée — c'est le garde-fou du seul point d'entrée
     * non authentifié qui écrit.
     *
     * Sans lui, une boucle triviale verse des milliers de prospects fantômes dans le CRM de
     * l'éditeur, et le jour où cela arrive on ne sait plus distinguer l'abus d'une campagne qui
     * marche. On vérifie aussi que le refus reste poli et utile : la vitrine est vue par des gens
     * qui ne sont pas clients, y compris quand on leur dit non.
     */
    public function testAuDelaDeLaPartHoraireLadresseEstRefusee(): void
    {
        $this->sauterSiRouteAbsente();
        $this->offre();

        $client = static::createClient();
        $corps = [
            'json' => [
                'companyName' => 'Insistant',
                'email' => 'contact@insistant.test',
                'planCode' => self::PLAN,
                'capabilities' => [self::COMPRISE],
            ],
        ];

        for ($essai = 1; $essai <= 5; ++$essai) {
            $client->request('POST', '/api/editor/carts', $corps);
            self::assertResponseIsSuccessful(sprintf('le panier n°%d doit passer', $essai));
        }

        $refuse = $client->request('POST', '/api/editor/carts', $corps);

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Réessayez', $this->detail($refuse->toArray(false)));
    }

    // ---------------------------------------------------------------- montage

    /** Voir `PublicCatalogApiTest` : même garde, même raison (C9). Comparaison par suffixe. */
    private function sauterSiRouteAbsente(): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        foreach ($routeur->getRouteCollection() as $route) {
            if (str_ends_with($route->getPath(), '/editor/carts')) {
                return;
            }
        }

        self::markTestSkipped('`src/Subscription/ApiResource` absent de `mapping.paths` (C9).');
    }

    /**
     * Le message effectivement montré au visiteur.
     *
     * @param array<string, mixed> $reponse
     */
    private function detail(array $reponse): string
    {
        return (string) ($reponse['detail'] ?? $reponse['hydra:description'] ?? '');
    }

    private function editeurId(): string
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($etablissement instanceof Etablissement);

        return $etablissement->getId()->toRfc4122();
    }

    private function offre(): void
    {
        $plan = (new Plan())
            ->setCode(self::PLAN)
            ->setLabel('Essentiel')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities([self::COMPRISE])
            ->setActive(true);
        $this->em()->persist($plan);

        $option = (new PlanOption())
            ->setCapability(self::OPTION)
            ->setLabel('Réservation')
            ->setMonthlyPriceCents(1500);
        $this->em()->persist($option);

        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
