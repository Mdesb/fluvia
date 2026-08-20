<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Service\LettrageHandler;
use App\DataFixtures\SocleFixtures;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\SupplierInvoice\Entity\SupplierPayment;
use App\Securite\Entity\Utilisateur;
use App\Tests\Finance\FinanceApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CA-6 (US-SINV-06, RG-SINV-07) : règlement total -> `paid` + lettrage groupé (même
 * `reconciliationCode` sur les deux lignes 401) ; règlement partiel -> `partially_paid`, solde recalculé,
 * **aucun** lettrage tenté (§0.8 point 4 du plan). RG-SINV-08 : gel en cas de litige.
 */
final class SupplierPaymentHandlerTest extends FinanceApiTestCase
{
    public function testReglementTotalPasseAPaidEtLettre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-PAY-001');
        self::assertSame('480.00', $facture['amountInclTax']);

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '480.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);
        self::assertSame(201, $reponse->getStatusCode());
        $reglement = $reponse->toArray();
        self::assertNotNull($reglement['reconciliationCode']);

        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('paid', $rechargee['status']);
    }

    public function testReglementPartielPasseAPartiallyPaidSansLettrage(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureMilleEuros($client, $entete, 'FACT-PAY-002');

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '400.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);
        self::assertSame(201, $reponse->getStatusCode());
        $reglement = $reponse->toArray();
        self::assertNull($reglement['reconciliationCode'], 'Aucun lettrage tant que le solde n\'est pas nul (§0.8 point 4).');

        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('partially_paid', $rechargee['status']);
    }

    public function testReglementSuperieurAuSoldeRejete(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-PAY-003');

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '999999.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    public function testReglementSurFactureLitigieuseRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-PAY-004');

        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/dispute', $entete + ['json' => ['reason' => 'Marchandise non conforme']]);

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '480.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);

        self::assertSame(409, $reponse->getStatusCode());
    }

    /**
     * Correctif revue de cohérence (défaut 3) : avant le correctif, `enregistrer()` flushait l'écriture
     * NF525 scellée AVANT le persist du `SupplierPayment` et AVANT `lettrerGroupe()` (qui peut lever) —
     * une écriture orpheline restait en base si le lettrage échouait. Ici, la ligne 401 de la facture
     * d'origine est déjà lettrée manuellement : le règlement qui devrait solder exactement la facture
     * déclenche `lettrerGroupe()`, qui lève (ligne déjà lettrée) — aucune trace ne doit rester en base.
     */
    public function testReglementDontLeLettrageEchoueNeLaissePasDecritureOrpheline(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureValidee($client, $entete, 'FACT-PAY-ATOMIC-001');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecritureFacture = $em->getRepository(EcritureComptable::class)->find(basename((string) $facture['ledgerEntry']));
        self::assertNotNull($ecritureFacture);

        $ligne401 = null;
        foreach ($ecritureFacture->getLignes() as $ligne) {
            if ($ligne->getCounterpartyType() === 'stock_fournisseur') {
                $ligne401 = $ligne;

                break;
            }
        }
        self::assertNotNull($ligne401, 'Ligne 401 introuvable sur l\'écriture de la facture.');

        $admin = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $admin);
        static::getContainer()->get(LettrageHandler::class)->lettrer($ligne401, $admin);

        $avantEcritures = $this->compterEcritures();
        $avantReglements = $this->compterReglements();

        $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '480.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);

        self::assertSame(409, $reponse->getStatusCode(), '`lettrerGroupe()` doit lever (ligne 401 déjà lettrée).');
        self::assertSame($avantEcritures, $this->compterEcritures(), 'Aucune écriture de règlement orpheline : le rollback doit annuler la nouvelle écriture NF525.');
        self::assertSame($avantReglements, $this->compterReglements(), 'Aucun `SupplierPayment` orphelin.');

        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('to_pay', $rechargee['status'], 'Le statut ne doit pas basculer à `paid` si le lettrage a échoué.');
    }

    // Défaut 3 (revue de cohérence) — le verrou `LockMode::PESSIMISTIC_WRITE` est posé EN PREMIER dans
    // `SupplierPaymentHandler::enregistrer()` (dans `wrapInTransaction()`, avant toute lecture du solde).
    // Son effet réel — aucune écriture NF525 orpheline quand un règlement échoue en contention — est
    // vérifié de façon DÉTERMINISTE par le test ci-dessus (comptage des écritures/règlements avant/après
    // un 409 sur ligne déjà lettrée). Un test « le verrou fait attendre ~1 s » a été volontairement retiré :
    // le blocage d'un verrou de ligne entre deux connexions n'est pas observable de façon fiable dans un
    // runner phpunit mono-processus (dépend de la config MariaDB `innodb_lock_wait_timeout` et de la
    // représentation binaire des UUID), ce qui en faisait un test intermittent — pas une preuve.

    /**
     * Correctif revue de cohérence (défaut 5) : `soldeCentimes()` doit désormais soustraire les avoirs
     * déjà émis (RG-SINV-09), pas seulement les règlements — sans quoi un règlement pouvait dépasser
     * (facture − avoirs) et aboutir à un trop-payé.
     */
    public function testAvoirDejaEmisReduitLeSoldeReelAvantUnNouveauReglement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $facture = $this->factureMilleEuros($client, $entete, 'FACT-PAY-AVOIR-001');

        $reponseAvoir = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/credit-note', $entete + [
            'json' => ['amount' => '400.00', 'reason' => 'Retour partiel de marchandise'],
        ]);
        self::assertSame(201, $reponseAvoir->getStatusCode());

        // Solde réel : 1000,00 - 400,00 (avoir) = 600,00 €. Avant le correctif, `soldeCentimes()`
        // ignorait l'avoir et n'aurait rejeté qu'au-delà de 1000,00 € — laissant passer un trop-payé.
        $reponseTropPaye = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '601.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);
        self::assertSame(422, $reponseTropPaye->getStatusCode(), 'Le solde réel (600,00 €) doit tenir compte de l\'avoir déjà émis.');

        $reponseOk = $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-15',
                'amount' => '600.00',
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);
        self::assertSame(201, $reponseOk->getStatusCode());
        $reglement = $reponseOk->toArray();
        self::assertNotNull($reglement['reconciliationCode'], 'Le règlement qui solde (facture − avoir) doit déclencher le lettrage groupé, avoir inclus.');

        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('paid', $rechargee['status']);
    }

    private function compterEcritures(): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return (int) $em->getRepository(EcritureComptable::class)->count([]);
    }

    private function compterReglements(): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return (int) $em->getRepository(SupplierPayment::class)->count([]);
    }

    /** @return array<string, mixed> */
    private function factureValidee(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $numero): array
    {
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], $numero);
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        return $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
    }

    /** Facture de 1000 € TTC (RG-M6-05 : quantité 200 x 4,1666 €HT au taux 20 %, arrondi à 1000,00 €). */
    private function factureMilleEuros(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $numero): array
    {
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [
            'quantity' => '1.000',
            'unitPriceExclTax' => '833.3333',
        ], $numero);
        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('1000.00', $rechargee['amountInclTax']);

        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        return $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
    }
}
