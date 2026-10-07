<?php

declare(strict_types=1);

namespace App\Tests\Membership\Api;

use ApiPlatform\Symfony\Bundle\Test\Client as ApiClient;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\Support;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Enum\StatutEcheanceSepa;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Recouvrement\DataFixtures\RecouvrementFixtures;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Sport\DataFixtures\SportFixtures;
use App\Tests\Acces\AccesApiTestCase;
use App\Vente\Entity\BilletSupport;
use App\Vente\Nf525\Entity\OperationScellee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SOUSCRIRE AU COMPTOIR PUIS ENCAISSER LE PREMIER MOIS : UN SEUL ABONNEMENT.
 *
 * `SouscriptionAbonnement.jsx` souscrit (`/sport/abonnements/souscrire`), puis encaisse la première
 * échéance dans une vente qui porte le MÊME produit-formule. À la validation, la caisse crée
 * l'abonnement de chaque ligne-formule (G-1) : sans reconnaître celui que l'écran vient de
 * souscrire, elle en créait un second (mesuré le 07/10, PR #276 : 2 → 3 abonnements actifs).
 *
 * Chaque test rejoue l'écran corps pour corps (identifiants nus), pas une sonde plus permissive que
 * lui. Jusqu'au 07/10, la ligne de l'écran ne nommait pas le bénéficiaire : une formule nominative
 * (facette accès) était refusée (422) avant tout encaissement, et seule une formule NON nominative
 * allait jusqu'à la validation. L'écran nomme désormais le bénéficiaire : les deux y vont.
 */
final class CounterSubscriptionCashFirstMonthTest extends AccesApiTestCase
{
    private const IBAN = 'FR7630006000011234567890189';

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        // Sport : les droits `sport.*` de l'admin, et un abonnement Gold du payeur souscrit il y a un
        // mois — celui-là ne doit jamais être pris pour l'abonnement du jour.
        foreach ([ComptaFixtures::class, CrmFixtures::class, SepaFixtures::class, RecouvrementFixtures::class, SportFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        self::ensureKernelShutdown();
    }

    public function testCashFirstMonthKeepsTheSubscriptionJustTaken(): void
    {
        [$client, $entete] = $this->adminSurA();
        $produit = $this->nonNominativeGold();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $session = $this->ouvrirSession($client, $entete);

        $parcours = $this->subscribeThenPayCash($client, $entete, $session['id'], $payeur, $produit);
        self::assertLessThan(300, $parcours['valider'], $parcours['corps']);

        $abonnements = $this->subscriptionsOf($payeur, $produit);
        self::assertCount(1, $abonnements, 'Un parcours de souscription crée UN abonnement, encaissé ou non.');
        self::assertSame($parcours['abonnement'], (string) $abonnements[0]->getId(), 'C\'est celui que l\'écran a souscrit.');
        self::assertSame($parcours['ligne'], (string) $abonnements[0]->getSourceSaleLineId(), 'Il est relié à la ligne qui a payé son premier mois.');
        self::assertSame(1, $this->echeancesAnnulees($parcours['abonnement']), 'La première échéance, encaissée au comptoir, n\'est plus prélevée.');
    }

    public function testCashFirstMonthForAFamilyMemberKeepsOneSubscription(): void
    {
        [$client, $entete] = $this->adminSurA();
        $produit = $this->nonNominativeGold();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $enfant = $this->entite(Client::class, ['prenom' => CrmFixtures::ENFANT_PRENOM]);
        $session = $this->ouvrirSession($client, $entete);

        // La ligne nomme l'enfant, comme la souscription.
        $parcours = $this->subscribeThenPayCash($client, $entete, $session['id'], $payeur, $produit, $enfant);
        self::assertLessThan(300, $parcours['valider'], $parcours['corps']);

        $abonnements = $this->subscriptionsOf($payeur, $produit);
        self::assertCount(1, $abonnements, 'L\'abonnement de l\'enfant ne se double pas d\'un abonnement au nom du payeur.');
        self::assertSame((string) $enfant->getId(), (string) $abonnements[0]->getAdherent()?->getClient()?->getId());
    }

    /**
     * FORMULE NOMINATIVE : UN ABONNEMENT, ET UN SEUL ACCÈS. La ligne nomme le bénéficiaire (ce que
     * l'écran fait depuis le 07/10). La vente émet alors son propre billet, avec un droit d'accès sans
     * fin de validité (mesuré le 07/10) : il doublait le QR de l'abonnement et ouvrait encore après
     * une résiliation. Seul le QR de l'abonnement doit rester.
     */
    public function testANominativeFormulaGivesOneSubscriptionAndOneAccess(): void
    {
        [$client, $entete] = $this->adminSurA();
        $produit = $this->entite(Produit::class, ['code' => 'PRD-GOLD01']);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $session = $this->ouvrirSession($client, $entete);

        $parcours = $this->subscribeThenPayCash($client, $entete, $session['id'], $payeur, $produit);
        self::assertLessThan(300, $parcours['valider'], $parcours['corps']);

        $abonnements = $this->subscriptionsOf($payeur, $produit);
        self::assertCount(1, $abonnements);
        $em = $this->em();
        $billet = $em->getRepository(BilletSupport::class)->findOneBy(['ligne' => Uuid::fromString($parcours['ligne'])]);
        self::assertInstanceOf(BilletSupport::class, $billet, 'Témoin : la ligne nominative émet bien un billet.');
        self::assertFalse($this->opens((string) $billet->getIdentifiantSupport()), 'Le billet de la vente n\'ouvre plus rien.');
        $statut = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnements[0]]);
        self::assertTrue($this->opens((string) $statut?->getSupportIdentifiant()), 'Le QR de l\'abonnement reste l\'accès.');
    }

    /**
     * UNE VENTE D'ABONNEMENT ANONYME EST REFUSÉE AVANT D'ÊTRE SCELLÉE (décision de Maxime du 07/10,
     * qui revoit G-5). Jusque-là, elle était scellée PUIS refusée : l'argent entrait, sans abonnement
     * possible ni reprise. Elle reste maintenant ouverte, et on peut lui rattacher le client.
     */
    public function testAnAnonymousSubscriptionSaleIsRefusedBeforeSealing(): void
    {
        [$client, $entete] = $this->adminSurA();
        $produit = $this->nonNominativeGold();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $session = $this->ouvrirSession($client, $entete);

        $parcours = $this->subscribeThenPayCash($client, $entete, $session['id'], $payeur, $produit, null, false);
        self::assertSame(422, $parcours['valider'], $parcours['corps']);

        $relue = $client->request('GET', '/api/ventes/' . $parcours['vente'], $entete)->toArray();
        self::assertSame('en_cours', $relue['statut']);
        self::assertSame(0, (int) $this->em()->getRepository(OperationScellee::class)->count([]));

        // Et la vente se reprend : client rattaché, elle se valide et relie l'abonnement du jour.
        $client->request('POST', '/api/ventes/' . $parcours['vente'] . '/client', $entete + ['json' => ['client' => (string) $payeur->getId()]]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $parcours['vente'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->subscriptionsOf($payeur, $produit));
    }

    // ── Outillage ─────────────────────────────────────────────────────────────────────────────

    /**
     * Rejoue `SouscriptionAbonnement.jsx` : `soumettre()` puis `encaisserComptant()`, dans l'ordre et
     * avec ses corps : la ligne nomme l'adhérent, à défaut le payeur. `$rattacher = false` rejoue
     * le rattachement client qui échoue.
     *
     * @param array<string, mixed> $entete
     *
     * @return array{abonnement: string, vente: string, ligne: string, valider: int, corps: string}
     */
    private function subscribeThenPayCash(
        ApiClient $client,
        array $entete,
        string $sessionId,
        Client $payeur,
        Produit $produit,
        ?Client $adherent = null,
        bool $rattacher = true,
    ): array {
        $payeurId = (string) $payeur->getId();
        $souscription = [
            'payeur' => $payeurId,
            'formule' => (string) $produit->getFormule()?->getId(),
            'iban' => self::IBAN,
            'titulaireMandat' => 'Jean Dupont',
            'dureeEngagementMois' => 12,
        ];
        if ($adherent instanceof Client) {
            $souscription['adherent'] = (string) $adherent->getId();
        }
        $abonnement = $client->request('POST', '/api/sport/abonnements/souscrire', $entete + ['json' => $souscription])->toArray();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['session' => $sessionId]])->toArray();
        if ($rattacher) {
            $client->request('POST', '/api/ventes/' . $vente['id'] . '/client', $entete + ['json' => ['client' => $payeurId]]);
            self::assertResponseIsSuccessful();
        }
        $ligne = [
            'produit' => (string) $produit->getId(),
            'typeTarif' => $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => 1,
            'beneficiaire' => (string) ($adherent ?? $payeur)->getId(),
        ];
        $apres = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + ['json' => $ligne])->toArray();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '39.90'],
        ]);
        self::assertResponseIsSuccessful();
        $valider = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        $statut = $valider->getStatusCode();

        if ($statut < 300) {
            // Puis l'écran annule la première échéance « à venir » de CET abonnement.
            $echeances = $client->request('GET', '/api/echeance_sepas', $entete + ['query' => [
                'itemsPerPage' => 200, 'abonnement' => $abonnement['id'], 'statut' => 'a_venir', 'order' => 'asc',
            ]])->toArray();
            $premiere = ($echeances['member'] ?? $echeances['hydra:member'] ?? [])[0] ?? null;
            self::assertIsArray($premiere, 'L\'écran doit retrouver la première échéance.');
            $client->request('POST', '/api/sport/echeances/' . $premiere['id'] . '/annuler', $entete + [
                'json' => ['motif' => 'Première échéance encaissée au comptoir.'],
            ]);
            self::assertResponseIsSuccessful();
        }

        return [
            'abonnement' => (string) $abonnement['id'],
            'vente' => (string) $vente['id'],
            'ligne' => (string) $apres['lignes'][0]['id'],
            'valider' => $statut,
            'corps' => (string) $valider->getContent(false),
        ];
    }

    /** Le Gold des fixtures, sous un type sans facette accès : une formule non nominative. */
    private function nonNominativeGold(): Produit
    {
        $em = $this->em();
        $type = (new TypeProduit())
            ->setCode('abonnement_libre')
            ->setLibelle('Abonnement sans accès')
            ->setFacettes([TypeProduit::FACETTE_FORMULE]);
        $em->persist($type);
        $produit = $em->getRepository(Produit::class)->findOneBy(['code' => 'PRD-GOLD01']);
        self::assertInstanceOf(Produit::class, $produit);
        $produit->setType($type);
        $em->flush();

        return $produit;
    }

    /** Les abonnements du payeur à cette formule souscrits aujourd'hui (celui des fixtures date d'un mois). @return list<Membership> */
    private function subscriptionsOf(Client $payeur, Produit $produit): array
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Membership::class)->findBy([
            'payeur' => $payeur->getId(),
            'formule' => $produit->getFormule()?->getId(),
            'dateSouscription' => new \DateTimeImmutable('today'),
        ]);
    }

    /** Le support porte-t-il un appairage actif côté accès ? (Révoqué, il n'ouvre plus rien.) */
    private function opens(string $code): bool
    {
        $em = $this->em();
        $support = $em->getRepository(Support::class)->findOneBy(['identifiant' => $code]);

        return $support instanceof Support
            && $em->getRepository(Appairage::class)->findOneBy(['support' => $support, 'actif' => true]) instanceof Appairage;
    }

    private function echeancesAnnulees(string $abonnementId): int
    {
        return $this->em()->getRepository(EcheanceSepa::class)->count([
            'abonnement' => Uuid::fromString($abonnementId),
            'statut' => StatutEcheanceSepa::Annulee,
        ]);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
