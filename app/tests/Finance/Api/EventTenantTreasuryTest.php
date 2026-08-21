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

        self::assertCount(1, $captures);
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame($idEtablissementA, $evenement->tenant->establishmentId->toRfc4122());
        self::assertNotSame($idEtablissementB, $evenement->tenant->establishmentId->toRfc4122());
        self::assertSame('BankStatementLine', $evenement->subject->type);
        self::assertSame($ligneReleve['id'], $evenement->subject->id);
        self::assertArrayHasKey('reconciliationCode', $evenement->payload);
        self::assertArrayNotHasKey('iban', $evenement->payload);
    }
}
