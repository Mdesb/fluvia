<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Api;

use App\Caisse\Entity\PointDeVente;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Marketing\Entity\LoyaltyRule;
use App\Marketing\Entity\ReferralProgram;
use App\Marketing\Entity\LoyaltyTier;
use App\Tests\Marketing\MarketingApiTestCase;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;

/**
 * LA FIDÉLITÉ — ce que ces tests protègent, c'est qu'un solde reste JUSTE.
 *
 * Un compteur de points est facile à écrire et impossible à réparer une fois faux : le client
 * connaît son solde, il l'a vu au comptoir, et personne ne peut lui expliquer pourquoi il a changé.
 * D'où le choix de tout recalculer depuis les ventes — et d'où ces tests, qui vérifient chacune des
 * conséquences de ce choix.
 */
final class LoyaltyTest extends MarketingApiTestCase
{
    /**
     * Les fixtures posent un barème, des paliers et un programme de DÉMONSTRATION.
     *
     * Un test qui s'appuie dessus ne mesure pas ce qu'il croit : il mesure la démo, et il tombera le
     * jour où quelqu'un changera un seuil pour faire une capture d'écran. On repart donc d'un
     * établissement sans programme, et chaque test pose exactement ce dont il a besoin.
     *
     * Les contraintes d'unicité rendaient déjà le mélange impossible — c'était leur travail, et
     * c'est ainsi qu'on l'a su.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        foreach ([LoyaltyRule::class, LoyaltyTier::class, ReferralProgram::class] as $classe) {
            foreach ($em->getRepository($classe)->findBy(['establishment' => $this->etablissementA()]) as $demo) {
                $em->remove($demo);
            }
        }
        $em->flush();
    }

    /**
     * **Le barème est daté : changer le tarif ne réécrit pas le passé.**
     *
     * C'est le test central. Sans dates, passer de 1 à 2 points par euro doublerait du jour au
     * lendemain tous les soldes de tous les clients — y compris ceux acquis sous un barème que
     * personne n'avait promis.
     */
    public function testUnNouveauBaremeNeReecritPasLesPointsDejaAcquis(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $client = $this->clientDuGroupeA();

        $this->bareme(1, '-2 years');
        $this->bareme(10, '-1 day');

        $this->vente($client, '-6 months', '100.00');   // 100 points au barème d'alors
        $this->vente($client, '-1 hour', '10.00');      // 100 points au nouveau

        $etat = $appelant->request('GET', $this->url($client), $entete)->toArray();

        self::assertSame(200, $etat['gagnes'], 'Chaque vente compte au barème de SON jour.');
    }

    /**
     * **Une vente annulée retire ses points d'elle-même.**
     *
     * Aucun code ne la rattrape : les points n'ont jamais été stockés, donc il n'y a rien à défaire.
     * C'est le bénéfice principal du calcul, et il se perdrait au premier compteur.
     */
    public function testUneVenteAnnuleeNeRapporteAucunPoint(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $client = $this->clientDuGroupeA();
        $this->bareme(1, '-1 year');

        $this->vente($client, '-1 month', '50.00');
        $this->vente($client, '-1 month', '50.00', StatutVente::Annulee);
        $this->vente($client, '-1 month', '50.00', StatutVente::AvoirEmis);

        $etat = $appelant->request('GET', $this->url($client), $entete)->toArray();

        self::assertSame(50, $etat['gagnes'], 'Seule la vente encaissée rapporte.');
    }

    /**
     * **Sans barème, aucun point** — et surtout pas « un par euro par défaut ».
     *
     * Un défaut implicite inventerait une promesse que l'exploitant n'a jamais faite, et le premier
     * client à réclamer ses points aurait raison.
     */
    public function testSansBaremeAucunPointNEstAccorde(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $client = $this->clientDuGroupeA();
        $this->vente($client, '-1 month', '500.00');

        $etat = $appelant->request('GET', $this->url($client), $entete)->toArray();

        self::assertSame(0, $etat['gagnes']);
        self::assertNull($etat['baremeCourant']);
    }

    /**
     * **Dépenser ses points ne fait pas perdre son palier.**
     *
     * Sinon utiliser sa fidélité coûterait son statut, et le client apprendrait à ne jamais s'en
     * servir — un programme que personne n'utilise coûte autant qu'un programme utilisé, sans le
     * bénéfice.
     */
    public function testDepenserSesPointsNeFaitPasPerdreSonPalier(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $client = $this->clientDuGroupeA();
        $this->bareme(1, '-1 year');
        $this->palier('Argent démonstration', 500);
        $this->vente($client, '-1 month', '600.00');

        $appelant->request('POST', '/api/marketing/fidelite/mouvements', $entete + [
            'json' => [
                'customerRef' => (string) $client->getId(),
                'points' => 400,
                'movement' => 'depense',
                'reason' => 'Entrée offerte',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $etat = $appelant->request('GET', $this->url($client), $entete)->toArray();

        self::assertSame(200, $etat['solde'], 'Le solde, lui, baisse bien.');
        self::assertSame(600, $etat['cumuleDouzeMois']);
        self::assertSame('Argent démonstration', $etat['palier']['libelle'] ?? null);
    }

    /**
     * **Une dépense envoyée en positif reste une dépense.**
     *
     * Sans normalisation du signe, « dépenser 50 points » avec un signe oublié en créditerait
     * cinquante — l'erreur la plus facile à commettre et la plus difficile à voir.
     */
    public function testUneDepenseEnvoyeeEnPositifDebiteQuandMeme(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $client = $this->clientDuGroupeA();
        $this->bareme(1, '-1 year');
        $this->vente($client, '-1 month', '100.00');

        $appelant->request('POST', '/api/marketing/fidelite/mouvements', $entete + [
            'json' => [
                'customerRef' => (string) $client->getId(),
                'points' => 30,
                'movement' => 'depense',
                'reason' => 'Boisson offerte',
            ],
        ]);

        $etat = $appelant->request('GET', $this->url($client), $entete)->toArray();

        self::assertSame(70, $etat['solde']);
    }

    /**
     * **Le solde ne peut pas devenir négatif, et le refus dit le solde réel.**
     *
     * L'agent a le client devant lui : « il ne reste que 100 points » lui permet de répondre,
     * « requête invalide » le laisse sans rien à dire.
     */
    public function testUnSoldeInsuffisantEstRefuseEnDisantLeSolde(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $client = $this->clientDuGroupeA();
        $this->bareme(1, '-1 year');
        $this->vente($client, '-1 month', '100.00');

        $reponse = $appelant->request('POST', '/api/marketing/fidelite/mouvements', $entete + [
            'json' => [
                'customerRef' => (string) $client->getId(),
                'points' => 500,
                'movement' => 'depense',
                'reason' => 'Trop gourmand',
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
        self::assertStringContainsString('100', $reponse->getContent(false));
    }

    /** **Un mouvement sans motif est refusé** : le client demandera, et personne ne s'en souviendra. */
    public function testUnMouvementSansMotifEstRefuse(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $client = $this->clientDuGroupeA();
        $this->bareme(1, '-1 year');
        $this->vente($client, '-1 month', '100.00');

        $appelant->request('POST', '/api/marketing/fidelite/mouvements', $entete + [
            'json' => [
                'customerRef' => (string) $client->getId(),
                'points' => 10,
                'movement' => 'ajustement',
                'reason' => '',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **Le solde d'un client d'un autre groupe est introuvable, pas interdit.**
     *
     * Un 403 confirmerait que cette personne est cliente ailleurs — déjà une information de trop.
     */
    public function testLeSoldeDUnClientHorsPerimetreEstIntrouvable(): void
    {
        $client = $this->clientDuGroupeA();

        [$appelantB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $appelantB->request('GET', $this->url($client), $enteteB);

        self::assertSame(404, $reponse->getStatusCode());
    }

    /**
     * **Les trois collections que l'écran de réglages lit.**
     *
     * Les opérations d'item ont été retirées de ces ressources : rien ne les appelait, et une
     * opération que personne ne peut déclencher coûte quand même un cloisonnement à tenir. Mais
     * API Platform fabrique l'`@id` de chaque ligne à partir d'une opération d'item — les enlever
     * toutes casserait la SÉRIALISATION de la collection, pas la route d'item.
     *
     * Ce test lit donc les trois collections. C'est le chemin exact de l'écran de paramétrage, et
     * c'est le seul endroit où ce défaut se verrait.
     */
    public function testLesTroisCollectionsDeReglageSeLisent(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->bareme(1, '-1 year');
        $this->palier('Palier de lecture', 250);

        $em = $this->em();
        $em->persist(
            (new ReferralProgram())
                ->setEstablishment($this->etablissementA())
                ->setRewardPoints(150)
                ->setMinimumPurchase('15.00')
        );
        $em->flush();

        foreach (['/api/loyalty_rules', '/api/loyalty_tiers', '/api/referral_programs'] as $url) {
            $reponse = $appelant->request('GET', $url, $entete);
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(
                0,
                $reponse->toArray()['totalItems'] ?? 0,
                'Une collection vide passerait ce test sans rien prouver : ' . $url,
            );
        }
    }

    // --- outillage --------------------------------------------------------------------------------

    private function url(Client $client): string
    {
        return '/api/marketing/fidelite/' . $client->getId();
    }

    private function clientDuGroupeA(): Client
    {
        $client = $this->em()->getRepository(Client::class)
            ->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    private function bareme(int $pointsParEuro, string $depuis): void
    {
        $em = $this->em();
        $em->persist(
            (new LoyaltyRule())
                ->setEstablishment($this->etablissementA())
                ->setPointsPerEuro($pointsParEuro)
                ->setValidFrom(new \DateTimeImmutable($depuis))
        );
        $em->flush();
    }

    private function palier(string $libelle, int $seuil): void
    {
        $em = $this->em();
        $em->persist(
            (new LoyaltyTier())
                ->setEstablishment($this->etablissementA())
                ->setLabel($libelle)
                ->setThreshold($seuil)
        );
        $em->flush();
    }

    private function vente(
        Client $client,
        string $quand,
        string $montant,
        StatutVente $statut = StatutVente::Validee,
    ): void {
        $em = $this->em();
        $pdv = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => 'Caisse fidélité'])
            ?? (new PointDeVente())
                ->setLibelle('Caisse fidélité')
                ->setEtablissement($this->etablissementA())
                ->setMoyensAutorises(['especes']);
        $em->persist($pdv);

        $em->persist(
            (new Vente())
                ->setNumero('V-' . bin2hex(random_bytes(6)))
                ->setPointDeVente($pdv)
                ->setEtablissement($this->etablissementA())
                ->setClient($client->getId())
                ->setDate(new \DateTimeImmutable($quand))
                ->setStatut($statut)
                ->setTotal($montant)
        );
        $em->flush();
    }
}
