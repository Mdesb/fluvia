<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Subscription\Entity\SubscriptionInvoice;
use App\Subscription\Enum\SubscriptionStatus;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-7 — l'écran de facturation, et ce qu'il doit montrer en premier.
 *
 * **Ce qui n'a pas eu lieu passe devant ce qui a réussi.** Un abonnement actif qu'on a oublié de
 * facturer ne produit aucun signal : pas d'erreur, pas d'alerte, juste de l'argent jamais prélevé,
 * qu'on découvre en rapprochant les comptes trois mois plus tard. Le seul endroit où il peut se voir,
 * c'est cet écran, et seulement s'il est en tête.
 */
final class EditorBillingApiTest extends FacturationApiTestCase
{
    private const A_FACTURER = 'Camping des Pins';
    private const DEJA_FACTURE = 'Piscine du Lac';

    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /** Un abonnement actif non facturé apparaît, avec ce qu'il devrait coûter. */
    public function testUnAbonnementNonFactureApparaitAvecSonMontantAttendu(): void
    {
        $this->sauterSiRouteAbsente();
        $this->abonnementActif(self::A_FACTURER);

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/billing', $this->commeEditeur($client))->toArray();

        self::assertResponseIsSuccessful();

        $lignes = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(1, $lignes);
        self::assertSame(self::A_FACTURER, $lignes[0]['customerName']);
        self::assertFalse($lignes[0]['invoiced']);
        self::assertSame(6400, $lignes[0]['expectedCents'], 'formule 4900 + option 1500');
        self::assertNull($lignes[0]['invoiceNumber']);
    }

    /**
     * **Une option achetee en cours de mois est facturee au prorata, pas oubliee.**
     *
     * Ce test est ne d'un defaut reel : la premiere version ne retenait que les options actives au
     * PREMIER du mois. Une option achetee le 20 n'apparaissait sur aucune facture — le client s'en
     * servait sans jamais la payer, et rien ne le signalait. `ProrationCalculator` existait deja pour
     * ca (CA-4) ; il n'etait simplement pas appele.
     */
    public function testUneOptionAjouteeEnCoursDeMoisEstFactureeAuProrata(): void
    {
        $this->sauterSiRouteAbsente();

        $abonnement = $this->abonnementActif(self::A_FACTURER);
        $mois = SubscriptionInvoice::debutDeMois(new \DateTimeImmutable());

        // Une seconde option, achetee a la moitie du mois.
        $abonnement->addOption('no_show', 3100, $mois->modify('+15 days'));
        $this->em()->flush();

        $client = static::createClient();
        $lignes = $this->membres($client->request('GET', '/api/editor/billing', $this->commeEditeur($client))->toArray());

        $jours = (int) $mois->modify('+1 month')->diff($mois)->days;
        $duSurLaSeconde = (int) round(3100 * (($jours - 15) / $jours));

        self::assertGreaterThan(0, $duSurLaSeconde, 'une option de milieu de mois doit couter quelque chose');
        self::assertLessThan(3100, $duSurLaSeconde, 'et moins que le mois entier');
        self::assertSame(4900 + 1500 + $duSurLaSeconde, $lignes[0]['expectedCents']);
    }

    /** **Le manque passe devant.** */
    public function testLesAbonnementsNonFacturesRemontentEnTete(): void
    {
        $this->sauterSiRouteAbsente();

        // « Piscine » vient avant « Camping » par le nom : si l'ordre était alphabétique, elle serait
        // première. Facturée, elle doit passer derrière.
        $facture = $this->abonnementActif(self::DEJA_FACTURE);
        $this->abonnementActif(self::A_FACTURER);
        $this->marquerFacture($facture);

        $client = static::createClient();
        $lignes = $this->membres($client->request('GET', '/api/editor/billing', $this->commeEditeur($client))->toArray());

        self::assertCount(2, $lignes);
        self::assertSame(self::A_FACTURER, $lignes[0]['customerName'], 'ce qui manque doit venir en premier');
        self::assertFalse($lignes[0]['invoiced']);
        self::assertTrue($lignes[1]['invoiced']);
    }

    /** Émettre depuis l'écran produit une facture numérotée, et la ligne bascule. */
    public function testEmettreDepuisLecranProduitUneFactureNumerotee(): void
    {
        $this->sauterSiRouteAbsente();
        $abonnement = $this->abonnementActif(self::A_FACTURER);

        $client = static::createClient();
        $emise = $client->request('POST', '/api/editor/billing', $this->commeEditeur($client) + [
            'json' => [
                'subscriptionId' => $abonnement->getId()->toRfc4122(),
                'tauxTvaId' => $this->idTauxTva('Taux normal 20 %'),
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertTrue($emise['invoiced']);
        self::assertNotNull($emise['invoiceNumber']);

        $lignes = $this->membres($client->request('GET', '/api/editor/billing', $this->commeEditeur($client))->toArray());
        self::assertTrue($lignes[0]['invoiced']);
        self::assertSame($emise['invoiceNumber'], $lignes[0]['invoiceNumber']);
    }

    /** Deux clics ne font pas deux factures. */
    public function testDeuxClicsNeFontPasDeuxFactures(): void
    {
        $this->sauterSiRouteAbsente();
        $abonnement = $this->abonnementActif(self::A_FACTURER);

        $client = static::createClient();
        $corps = $this->commeEditeur($client) + [
            'json' => [
                'subscriptionId' => $abonnement->getId()->toRfc4122(),
                'tauxTvaId' => $this->idTauxTva('Taux normal 20 %'),
            ],
        ];

        $premier = $client->request('POST', '/api/editor/billing', $corps)->toArray();
        $second = $client->request('POST', '/api/editor/billing', $corps)->toArray();

        self::assertSame($premier['invoiceNumber'], $second['invoiceNumber']);
        self::assertCount(1, $this->em()->getRepository(SubscriptionInvoice::class)->findAll());
    }

    /**
     * Sans taux, le refus explique quoi faire — il n'échoue pas en 500.
     *
     * Le taux se lit dans le parametrage de l'editeur, sans valeur par defaut : tant qu'il n'est pas
     * choisi, la facturation refuse et dit ou le choisir. Le message est le chemin nominal d'une
     * installation neuve, pas un cas limite.
     */
    public function testSansTauxLeRefusExpliqueQuoiFaire(): void
    {
        $this->sauterSiRouteAbsente();
        $abonnement = $this->abonnementActif(self::A_FACTURER);

        $client = static::createClient();
        $reponse = $client->request('POST', '/api/editor/billing', $this->commeEditeur($client) + [
            'json' => ['subscriptionId' => $abonnement->getId()->toRfc4122()],
        ]);

        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertStringContainsString('Aucun taux de TVA', (string) $reponse->getContent(false));
    }

    /** Un autre établissement ne voit rien et ne facture rien. */
    public function testUnAutreEtablissementNeVoitRien(): void
    {
        $this->sauterSiRouteAbsente();
        $this->abonnementActif(self::A_FACTURER);

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/billing', [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    // ---------------------------------------------------------------- montage

    /** @param array<string, mixed> $reponse @return list<array<string, mixed>> */
    private function membres(array $reponse): array
    {
        return $reponse['member'] ?? $reponse['hydra:member'] ?? [];
    }

    /** @return array<string, mixed> */
    private function commeEditeur(object $client): array
    {
        /** @var \ApiPlatform\Symfony\Bundle\Test\Client $client */
        return [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];
    }

    private function sauterSiRouteAbsente(): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        foreach ($routeur->getRouteCollection() as $route) {
            if (str_ends_with($route->getPath(), '/editor/billing')) {
                return;
            }
        }

        self::markTestSkipped('`src/Subscription/ApiResource` absent de `mapping.paths` (C9).');
    }

    /** Marque un abonnement comme facturé sans passer par la chaîne comptable. */
    private function marquerFacture(Subscription $abonnement): void
    {
        $registre = (new SubscriptionInvoice())
            ->setSubscription($abonnement)
            ->setPeriodStart(new \DateTimeImmutable())
            ->setTotalCents(7680);

        $this->em()->persist($registre);
        $this->em()->flush();
    }

    private function abonnementActif(string $raisonSociale): Subscription
    {
        $editeur = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($editeur instanceof Etablissement);

        $client = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale($raisonSociale)
            ->setEmail(strtolower(str_replace(' ', '', $raisonSociale)).'@exemple.test')
            ->setGroupe($editeur->getRegion()?->getGroupe())
            ->setEtablissementCreation($editeur);
        $this->em()->persist($client);

        $plan = $this->em()->getRepository(Plan::class)->findOneBy(['code' => 'essentiel'])
            ?? (new Plan())
                ->setCode('essentiel')
                ->setLabel('Essentiel')
                ->setMonthlyPriceCents(4900)
                ->setIncludedCapabilities(['controle_acces'])
                ->setActive(true);
        $this->em()->persist($plan);
        $this->em()->flush();

        // Option active depuis le debut du mois : prix plein, montant rond. Le cas du milieu de
        // mois a son propre test, ou le prorata est le sujet.
        $debutDuMois = SubscriptionInvoice::debutDeMois(new \DateTimeImmutable());

        $abonnement = (new Subscription())
            ->setCustomerReference($client->getId()->toRfc4122())
            ->setPlan($plan);
        $abonnement->addOption('reservation', 1500, $debutDuMois);
        $abonnement->transitionTo(SubscriptionStatus::Active, $debutDuMois);

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
