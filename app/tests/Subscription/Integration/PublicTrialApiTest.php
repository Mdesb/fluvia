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
 * ED-5 — les deux routes publiques de l'essai gratuit, vues du dehors.
 *
 * ⚠ **Ce que les tests d'integration ne peuvent pas prouver, et que ceux-ci prouvent.**
 * `TrialFunnelTest` appelle le service directement : il resterait vert si les ressources n'etaient
 * pas exposees, si `PUBLIC_ACCESS` disparaissait, ou si le processeur n'etait pas cable. Ici on passe
 * par HTTP, sans jeton — donc exactement comme le prospect.
 *
 * **Le refus teste ici est celui qui garde la route.** Tenir un identifiant de panier ne suffit pas a
 * declencher un courriel : il faut redonner l'adresse saisie. Sans cette regle, l'identifiant seul
 * permettrait d'envoyer autant de messages que la borne de debit l'autorise, a une adresse qu'on ne
 * connait pas — et d'en lire le domaine dans la reponse.
 */
final class PublicTrialApiTest extends SocleApiTestCase
{
    private const COMPRISE = 'controle_acces';
    private const PLAN = 'essentiel';
    private const EMAIL = 'contact@piscinedulac.test';

    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->editeurId();
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;

        $this->remettreLeCompteurAZero();
    }

    /** La demande d'essai part sans compte, et ne rend jamais le jeton. */
    public function testLaDemandeDessaiPartSansCompteEtNeRendJamaisLeJeton(): void
    {
        $this->sauterSiRouteAbsente();
        $this->offre();

        $panier = static::createClient()->request('POST', '/api/editor/carts', [
            'json' => [
                'companyName' => 'Piscine du Lac',
                'email' => self::EMAIL,
                'planCode' => self::PLAN,
                'capabilities' => [self::COMPRISE],
            ],
        ])->toArray();

        $reponse = static::createClient()->request('POST', '/api/editor/trial-requests', [
            'json' => ['cartId' => $panier['id'], 'email' => self::EMAIL],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('c•••@piscinedulac.test', $reponse['maskedEmail'], 'le domaine reste lisible : c est lui qui fait voir une faute de frappe');
        self::assertSame(72, $reponse['confirmationHours']);

        // Etablir d'abord qu'il y a quelque chose a regarder : contre une reponse vide, les deux
        // assertions ci-dessous passeraient sans avoir rien lu, et le test dirait << aucun jeton ne
        // fuit >> alors qu'il n'aurait mesure aucune reponse.
        $brut = json_encode($reponse, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($reponse, 'sinon les deux refus qui suivent ne prouveraient rien');
        self::assertStringContainsString('maskedEmail', $brut, 'temoin positif : la reponse lue est bien celle de cette route');

        self::assertStringNotContainsString('token', $brut, 'un jeton rendu par l API permettrait de confirmer une adresse qu on ne lit pas');
        self::assertStringNotContainsString('jeton', $brut);

        $abonnement = $this->em()->getRepository(Subscription::class)->find($panier['id']);
        \assert($abonnement instanceof Subscription);
        self::assertSame(SubscriptionStatus::Draft, $abonnement->getStatus(), 'la demande ne vend rien');
        self::assertNotNull($abonnement->getEmailConfirmationTokenHash(), 'le courriel n a pas ete compose');
    }

    /**
     * L'identifiant de panier SEUL ne declenche rien.
     *
     * C'est la preuve d'appartenance de cette route : sans elle, tenir un identifiant suffirait a
     * faire partir des courriels chez quelqu'un d'autre.
     */
    public function testUnIdentifiantDePanierSeulNeDeclencheAucunCourriel(): void
    {
        $this->sauterSiRouteAbsente();
        $this->offre();

        $panier = static::createClient()->request('POST', '/api/editor/carts', [
            'json' => [
                'companyName' => 'Piscine du Lac',
                'email' => self::EMAIL,
                'planCode' => self::PLAN,
                'capabilities' => [self::COMPRISE],
            ],
        ])->toArray();

        static::createClient()->request('POST', '/api/editor/trial-requests', [
            'json' => ['cartId' => $panier['id'], 'email' => 'quelqu-un-dautre@exemple.test'],
        ]);

        self::assertResponseStatusCodeSame(422);

        $abonnement = $this->em()->getRepository(Subscription::class)->find($panier['id']);
        \assert($abonnement instanceof Subscription);
        self::assertNull($abonnement->getEmailConfirmationTokenHash(), 'aucun courriel ne doit avoir ete compose');
    }

    /** Un jeton invente n'ouvre aucune plateforme, et le message ne dit pas si le jeton a existe. */
    public function testUnJetonInventeNouvreAucunePlateforme(): void
    {
        $this->sauterSiRouteAbsente();
        $avant = \count($this->em()->getRepository(Etablissement::class)->findAll());

        static::createClient()->request('POST', '/api/editor/trial-confirmations', [
            'json' => ['token' => bin2hex(random_bytes(32))],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($avant, \count($this->em()->getRepository(Etablissement::class)->findAll()));
    }

    // ---------------------------------------------------------------- montage

    private function remettreLeCompteurAZero(): void
    {
        static::bootKernel();

        /** @var \Symfony\Contracts\Cache\CacheInterface&\Psr\Cache\CacheItemPoolInterface $cache */
        $cache = static::getContainer()->get('cache.app');
        $cache->clear();

        self::ensureKernelShutdown();
    }

    private function sauterSiRouteAbsente(): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        foreach ($routeur->getRouteCollection() as $route) {
            if (str_ends_with($route->getPath(), '/editor/trial-requests')) {
                return;
            }
        }

        self::markTestSkipped('`src/Subscription/ApiResource` absent de `mapping.paths` (C9).');
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
            ->setCapability('reservation')
            ->setLabel('Reservation')
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
