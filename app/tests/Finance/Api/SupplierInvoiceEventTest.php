<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Finance\DataFixtures\FinanceFixtures;
use App\Platform\Event\DomainEvent;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Finance\FinanceApiTestCase;
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

        self::assertCount(1, $captures);
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        // Le tenant est A (établissement RÉEL de la facture), pas B (contexte HTTP actif, D6).
        self::assertSame($idEtablissementA, $evenement->tenant->establishmentId->toRfc4122());
        self::assertNotSame($idEtablissementB, $evenement->tenant->establishmentId->toRfc4122());
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
