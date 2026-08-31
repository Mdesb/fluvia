<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Platform\Event\DomainEvent;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * D6 — le tenant de `treasury.reconciliation_completed` est dérivé de `BankAccount.establishment`,
 * **jamais** de `ContexteEtablissement` (en-tête `X-Etablissement`, sélecteur fourni par le client).
 * Assertion explicite demandée par la mission, même patron que FIN-2/FIN-3.
 */
final class EventTenantTreasuryTest extends TreasuryApiTestCase
{
    public function testTenantDeriveDuCompteBancaireJamaisDuContexte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $compte = $this->creerCompteBancaire($client, $entete);
        $ligneEcriture = $this->creerLigneEcritureBancaireScellee('150.00', 'debit', new \DateTimeImmutable('2026-08-16'));
        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligneReleve = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-16', 'Encaissement', '150.00');

        $idEtablissementA = $this->idEtablissement('Piscine A');
        $idEtablissementB = $this->idEtablissement('Patinoire B');

        // L'admin est affecté sur A ET B (SocleFixtures) : il envoie `X-Etablissement: B` (contexte
        // actif) tout en confirmant le rapprochement d'une ligne dont le compte bancaire réel est A.
        $enteteContexteB = $entete;
        $enteteContexteB['headers'][ContexteEtablissement::HEADER] = $idEtablissementB;

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = new \ArrayObject();
        $listener = static function (DomainEvent $event) use ($captures): void {
            $captures[] = $event;
        };
        $dispatcher->addListener('treasury.reconciliation_completed', $listener);

        try {
            $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligneReleve['id'] . '/reconcile', $enteteContexteB + [
                'json' => ['ledgerLineIds' => [(string) $ligneEcriture->getId()]],
            ]);
        } finally {
            $dispatcher->removeListener('treasury.reconciliation_completed', $listener);
        }

        // Même histoire que `SupplierInvoiceEventTest`. Ce test vérifiait que le tenant de
        // l'événement suivait la ligne de relevé (établissement A) et non l'en-tête (B). Depuis la
        // bascule sur l'établissement ACTIF, la ligne de A n'est plus résolvable depuis B : le
        // rapprochement est refusé avant tout événement.
        //
        // La surveillance devient une garantie, et c'est elle qu'on affirme ici — refus, ET aucun
        // événement publié. Un événement émis malgré le refus porterait un tenant arbitraire dans
        // tout l'aval comptable, sans trace côté HTTP.
        self::assertGreaterThanOrEqual(
            400,
            $client->getResponse()->getStatusCode(),
            'Rapprocher une ligne hors de l\'établissement actif doit être refusé.',
        );
        self::assertCount(0, $captures, 'Une opération refusée ne doit publier aucun événement.');
    }
}
