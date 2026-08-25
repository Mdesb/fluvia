<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ReglementFacture;
use App\Organisation\Entity\Etablissement;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-8 — ce qui reste dû à l'éditeur.
 *
 * **Une facture émise n'est pas une facture payée**, et c'est tout l'objet de cet écran. Sans lui,
 * automatiser l'émission produirait un flot de factures dont personne ne sait lesquelles sont
 * honorées : de l'automatisation à l'aveugle.
 */
final class EditorReceivablesApiTest extends FacturationApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /** Une facture émise et non réglée apparaît, avec son reste à encaisser. */
    public function testUneFactureNonRegleeApparaitAvecSonResteAEncaisser(): void
    {
        $this->sauterSiRouteAbsente();
        $facture = $this->factureEmise('Camping des Pins');

        $client = static::createClient();
        $lignes = $this->membres($client->request('GET', '/api/editor/receivables', $this->commeEditeur($client))->toArray());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $lignes);
        self::assertSame('Camping des Pins', $lignes[0]['customerName']);
        self::assertSame(0, $lignes[0]['paidCents']);
        self::assertSame($this->cents($facture->getTotalTTC()), $lignes[0]['remainingCents']);
    }

    /**
     * **Une facture soldée sort de la liste.**
     *
     * Elle n'est ni grisée ni repliée : elle disparaît. Une liste de créances où figurent les factures
     * payées oblige à lire pour savoir quoi faire — exactement l'effort qu'on veut supprimer.
     */
    public function testUneFactureSoldeeSortDeLaListe(): void
    {
        $this->sauterSiRouteAbsente();
        $facture = $this->factureEmise('Camping des Pins');
        $this->regler($facture, $facture->getTotalTTC());

        $client = static::createClient();
        $lignes = $this->membres($client->request('GET', '/api/editor/receivables', $this->commeEditeur($client))->toArray());

        self::assertSame([], $lignes);
    }

    /** Un règlement partiel laisse la facture dans la liste, avec le solde exact. */
    public function testUnReglementPartielLaisseLeSoldeExact(): void
    {
        $this->sauterSiRouteAbsente();
        $facture = $this->factureEmise('Camping des Pins');
        $total = $this->cents($facture->getTotalTTC());

        $this->regler($facture, '10.00');

        $client = static::createClient();
        $lignes = $this->membres($client->request('GET', '/api/editor/receivables', $this->commeEditeur($client))->toArray());

        self::assertCount(1, $lignes);
        self::assertSame(1000, $lignes[0]['paidCents']);
        self::assertSame($total - 1000, $lignes[0]['remainingCents']);
    }

    /**
     * **Le plus en retard passe devant.**
     *
     * Trier par date d'émission mettrait en tête ce qu'on vient de facturer, c'est-à-dire ce dont
     * personne ne se soucie encore. Ce qu'on cherche, c'est la facture que plus personne ne regarde.
     */
    public function testLePlusEnRetardPasseDevant(): void
    {
        $this->sauterSiRouteAbsente();

        // On fabrique le retard en facturant de VIEUX MOIS, pas en modifiant une facture emise :
        // le scellement NF525 l'interdit, et il a raison — une facture emise est inalterable.
        $this->factureEmise('Récente', (new \DateTimeImmutable('-2 months'))->format('Y-m'));
        $this->factureEmise('Ancienne', (new \DateTimeImmutable('-10 months'))->format('Y-m'));

        $client = static::createClient();
        $lignes = $this->membres($client->request('GET', '/api/editor/receivables', $this->commeEditeur($client))->toArray());

        self::assertCount(2, $lignes);
        self::assertSame('Ancienne', $lignes[0]['customerName']);
        self::assertGreaterThan($lignes[1]['daysLate'], $lignes[0]['daysLate']);
        self::assertGreaterThanOrEqual(89, $lignes[0]['daysLate']);
    }

    /** Une facture dont l'echeance n'est pas passee n'est pas en retard. */
    public function testUneFactureNonEchueNestPasEnRetard(): void
    {
        $this->sauterSiRouteAbsente();
        // Facture du mois courant : echeance a trente jours, donc pas encore due.
        $this->factureEmise('Camping des Pins');

        $client = static::createClient();
        $lignes = $this->membres($client->request('GET', '/api/editor/receivables', $this->commeEditeur($client))->toArray());

        self::assertSame(0, $lignes[0]['daysLate']);
    }

    /** Un autre établissement ne voit rien. */
    public function testUnAutreEtablissementNeVoitRien(): void
    {
        $this->sauterSiRouteAbsente();
        $this->factureEmise('Camping des Pins');

        $client = static::createClient();
        $reponse = $client->request('GET', '/api/editor/receivables', [
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
            if (str_ends_with($route->getPath(), '/editor/receivables')) {
                return;
            }
        }

        self::markTestSkipped('`src/Subscription/ApiResource` absent de `mapping.paths` (C9).');
    }

    /** Une vraie facture, émise par la vraie chaîne : c'est ce qu'on veut voir apparaître. */
    private function factureEmise(string $raisonSociale, ?string $mois = null): Facture
    {
        $client = static::createClient();
        $reponse = $client->request('POST', '/api/editor/billing', $this->commeEditeur($client) + [
            'json' => [
                'subscriptionId' => $this->abonnementActif($raisonSociale)->getId()->toRfc4122(),
                'tauxTvaId' => $this->idTauxTva('Taux normal 20 %'),
                'month' => $mois,
            ],
        ])->toArray();

        $facture = $this->em()->getRepository(Facture::class)->findOneBy(['numero' => $reponse['invoiceNumber']]);
        \assert($facture instanceof Facture);

        return $facture;
    }

    private function regler(Facture $facture, string $montant): void
    {
        $reglement = new ReglementFacture();
        $reglement->setFacture($facture)
            ->setMontant($montant)
            ->setMoyen('virement')
            ->setDateReglement(new \DateTimeImmutable());

        $this->em()->persist($reglement);
        $facture->addReglement($reglement);
        $this->em()->flush();
    }

    private function abonnementActif(string $raisonSociale): Subscription
    {
        $editeur = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($editeur instanceof Etablissement);

        $client = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale($raisonSociale)
            ->setEmail(md5($raisonSociale).'@exemple.test')
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

        $hier = new \DateTimeImmutable('-1 day');

        $abonnement = (new Subscription())
            ->setCustomerReference($client->getId()->toRfc4122())
            ->setPlan($plan);
        $abonnement->transitionTo(SubscriptionStatus::Active, $hier);

        $this->em()->persist($abonnement);
        $this->em()->flush();

        return $abonnement;
    }

    private function cents(string $montant): int
    {
        return (int) round(((float) $montant) * 100);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
