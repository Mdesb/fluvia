<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Compta\Entity\EcritureComptable;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Entity\SupplierPayment;
use App\Platform\Event\DomainEvent;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Finance\FinanceApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * CA-9 (US-SINV-09) + D6/D7 — les 4 événements `supplier_invoice.*` sont publiés **synchrones, dans la
 * transaction** de l'action qui les déclenche, avec un `tenant.establishmentId` dérivé de
 * `SupplierInvoice.establishment`, **jamais** de `ContexteEtablissement`.
 */
final class SupplierInvoiceEventTest extends FinanceApiTestCase
{
    public function testRecordedEmisAvecPayloadAttendu(): void
    {
        [$client, $entete] = $this->adminSurA();
        // Le client Symfony **rebootent le kernel à chaque requête par défaut** (nouveau conteneur, donc
        // un nouvel `EventDispatcher` à chaque appel) : sans ce garde-fou, un listener enregistré via
        // `static::getContainer()` ne verrait jamais les événements publiés par la requête suivante.
        $client->disableReboot();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);

        $captures = $this->capturerEvenements(['supplier_invoice.recorded']);

        $facture = $client->request('POST', '/api/supplier_invoices', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseur,
                'supplierInvoiceNumber' => 'FACT-EVT-001',
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ])->toArray();

        self::assertCount(1, $captures);
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame('supplier_invoice.recorded', $evenement->name->value);
        self::assertSame($facture['id'], $evenement->subject->id);
        self::assertSame('SupplierInvoice', $evenement->subject->type);
        self::assertSame($idFournisseur, $evenement->payload['supplierId']);
        self::assertSame('manual', $evenement->payload['source']);
        self::assertArrayHasKey('amountInclTaxCents', $evenement->payload);
    }

    /**
     * D6 — un appelant dont l'établissement actif (en-tête `X-Etablissement`) diffère de
     * l'établissement réel de la facture (utilisateur multi-établissement, cas de l'admin socle affecté
     * sur A **et** B) déclenche un événement dont `tenant.establishmentId` correspond **strictement** à
     * `SupplierInvoice.establishment`, jamais à `ContexteEtablissement::idActif()`.
     */
    public function testTenantDeriveDeLEtablissementFactureJamaisDuContexte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $idEtablissementA = $this->idEtablissement('Piscine A');
        $idEtablissementB = $this->idEtablissement('Patinoire B');

        // L'admin est affecté sur A ET B (SocleFixtures) : il envoie `X-Etablissement: B` (contexte
        // actif) tout en créant une facture dont le corps porte `establishment: A` — un scénario
        // plausible pour un utilisateur multi-établissement qui bascule d'onglet sans changer son
        // sélecteur d'établissement actif.
        $enteteContexteB = $entete;
        $enteteContexteB['headers'][ContexteEtablissement::HEADER] = $idEtablissementB;

        $captures = $this->capturerEvenements(['supplier_invoice.recorded']);

        $client->request('POST', '/api/supplier_invoices', $enteteContexteB + [
            'json' => [
                'establishment' => '/api/etablissements/' . $idEtablissementA,
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseur,
                'supplierInvoiceNumber' => 'FACT-EVT-002',
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ]);

        // CE QUE LA BASCULE D'AXE A CHANGE. Ce scénario — corps portant l'établissement A, en-tête
        // portant B — vérifiait que le tenant suivait l'ENTITÉ et non l'en-tête. C'était une menace
        // réelle tant que le cloisonnement portait sur le PÉRIMÈTRE : le fournisseur de A était
        // résolvable en regardant B, la facture se créait, et seul le calcul du tenant empêchait la
        // fuite.
        //
        // Depuis que le filtre porte sur l'établissement ACTIF, le fournisseur de A n'est plus
        // résolvable depuis B : la création est refusée avant tout événement. La divergence n'est
        // plus surveillée, elle est devenue impossible par ce chemin.
        //
        // Les deux moitiés de l'assertion comptent. Le refus, évidemment ; mais surtout l'ABSENCE
        // d'événement — un refus qui aurait tout de même publié porterait un tenant arbitraire dans
        // tout le système aval, sans qu'aucune réponse HTTP ne le signale.
        self::assertGreaterThanOrEqual(
            400,
            $client->getResponse()->getStatusCode(),
            'Créer une facture citant une ressource hors de l\'établissement actif doit être refusé.',
        );
        self::assertCount(0, $captures, 'Une opération refusée ne doit publier aucun événement.');
    }

    public function testApprovedPaidDisputedEmisAuxTransitionsAttendues(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-EVT-003');

        $capturesApprove = $this->capturerEvenements(['supplier_invoice.approved']);
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);
        self::assertCount(1, $capturesApprove, 'supplier_invoice.approved doit être émis exactement une fois à la validation.');

        $capturesDisputed = $this->capturerEvenements(['supplier_invoice.disputed']);
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/dispute', $entete + ['json' => ['reason' => 'Litige test']]);
        self::assertCount(1, $capturesDisputed);

        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/resolve-dispute', $entete + ['json' => ['resolutionReason' => 'Résolu']]);

        $capturesPaid = $this->capturerEvenements(['supplier_invoice.paid']);
        $client->request('POST', '/api/supplier_payments', $entete + [
            'json' => [
                'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                'date' => '2026-09-01',
                'amount' => $facture['amountInclTax'],
                'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
            ],
        ]);
        self::assertCount(1, $capturesPaid, 'supplier_invoice.paid ne doit être émis qu\'au règlement qui solde la facture.');
    }

    /**
     * Correctif revue de cohérence (défaut 4) : `recorded`/`disputed`/`paid` réunissent désormais
     * `flush()` et `publish()` dans un seul `wrapInTransaction()` (déjà le cas pour `approved`) — un
     * abonné qui lève doit annuler l'écriture en base, pas laisser une facture orpheline (D7).
     */
    public function testRecordedRollbackSiUnAbonneLeveUneException(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $listener = static function (): void {
            throw new \RuntimeException('Abonné en échec (recorded).');
        };
        $dispatcher->addListener('supplier_invoice.recorded', $listener);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = (int) $em->getRepository(SupplierInvoice::class)->count([]);

        try {
            $reponse = $client->request('POST', '/api/supplier_invoices', $entete + [
                'json' => [
                    'establishment' => '/api/etablissements/' . $this->idEtablissement('Piscine A'),
                    'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                    'supplier' => '/api/stock_fournisseurs/' . $idFournisseur,
                    'supplierInvoiceNumber' => 'FACT-EVT-ROLLBACK-001',
                    'invoiceDate' => '2026-08-01',
                    'dueDate' => '2026-09-01',
                ],
            ]);

            self::assertSame(500, $reponse->getStatusCode());
            self::assertSame($avant, (int) $em->getRepository(SupplierInvoice::class)->count([]), 'Aucune facture orpheline si l\'abonné `recorded` échoue.');
        } finally {
            $dispatcher->removeListener('supplier_invoice.recorded', $listener);
        }
    }

    public function testDisputedRollbackSiUnAbonneLeveUneException(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-EVT-ROLLBACK-002');
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $listener = static function (): void {
            throw new \RuntimeException('Abonné en échec (disputed).');
        };
        $dispatcher->addListener('supplier_invoice.disputed', $listener);

        try {
            $reponse = $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/dispute', $entete + ['json' => ['reason' => 'Litige test rollback']]);
            self::assertSame(500, $reponse->getStatusCode());
        } finally {
            $dispatcher->removeListener('supplier_invoice.disputed', $listener);
        }

        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('to_pay', $rechargee['status'], 'Le statut ne doit pas basculer à `disputed` si l\'abonné a fait échouer la transaction.');
    }

    public function testPaidRollbackSiUnAbonneLeveUneException(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [], 'FACT-EVT-ROLLBACK-003');
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);
        $facture = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avantEcritures = (int) $em->getRepository(EcritureComptable::class)->count([]);
        $avantReglements = (int) $em->getRepository(SupplierPayment::class)->count([]);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $listener = static function (): void {
            throw new \RuntimeException('Abonné en échec (paid).');
        };
        $dispatcher->addListener('supplier_invoice.paid', $listener);

        try {
            $reponse = $client->request('POST', '/api/supplier_payments', $entete + [
                'json' => [
                    'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
                    'date' => '2026-09-01',
                    'amount' => $facture['amountInclTax'],
                    'paymentMethod' => '/api/moyen_paiements/' . $this->idMoyenPaiement('virement'),
                ],
            ]);
            self::assertSame(500, $reponse->getStatusCode());
        } finally {
            $dispatcher->removeListener('supplier_invoice.paid', $listener);
        }

        self::assertSame($avantEcritures, (int) $em->getRepository(EcritureComptable::class)->count([]), 'Aucune écriture de règlement orpheline si l\'abonné `paid` échoue.');
        self::assertSame($avantReglements, (int) $em->getRepository(SupplierPayment::class)->count([]), 'Aucun `SupplierPayment` orphelin.');

        $rechargee = $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
        self::assertSame('to_pay', $rechargee['status'], 'Le statut ne doit pas basculer à `paid` si l\'abonné a fait échouer la transaction.');
    }

    /**
     * Abonne un capteur sur les noms d'événements donnés et retourne un conteneur (référence stable,
     * contrairement à un `array` renvoyé par valeur) qui accumule les `DomainEvent` publiés ensuite.
     *
     * @param list<string> $noms
     */
    private function capturerEvenements(array $noms): \ArrayObject
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = new \ArrayObject();
        foreach ($noms as $nom) {
            $dispatcher->addListener($nom, static function (DomainEvent $event) use ($captures): void {
                $captures[] = $event;
            });
        }

        return $captures;
    }
}
