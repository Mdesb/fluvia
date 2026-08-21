<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Compta\Entity\LettrageEcriture;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * §0.6 du plan — décision d'architecture centrale de FIN-4 (⚠ validation A en cours) : le cas courant
 * (1 seule ligne 512 candidate) appelle `LettrageHandler::lettrer()` (mono-ligne) + pose explicitement
 * `reconciliationCode`, jamais `lettrerGroupe()` (réservé au cas rare, ≥2 lignes équilibrées).
 */
final class BankReconciliationHandlerTest extends TreasuryApiTestCase
{
    /** CA-3 : ligne 500 €/écriture scellée 500 € -> `reconciled`, `reconciliationCode` identique sur la ligne de relevé et la `LettrageEcriture`. */
    public function testConfirmationUneSeuleLigneCreeLettrageAvecReconciliationCode(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $ligneEcriture = $this->creerLigneEcritureBancaireScellee('500.00', 'debit', new \DateTimeImmutable('2026-08-10'));

        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligneReleve = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-10', 'Encaissement', '500.00');

        $reponse = $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligneReleve['id'] . '/reconcile', $entete + [
            'json' => ['ledgerLineIds' => [(string) $ligneEcriture->getId()]],
        ]);

        self::assertSame(201, $reponse->getStatusCode());
        $resultat = $reponse->toArray();
        self::assertSame('reconciled', $resultat['status']);
        self::assertNotNull($resultat['reconciliationCode']);

        $lettrage = $this->em()->getRepository(LettrageEcriture::class)->findOneBy(['ligne' => $ligneEcriture->getId()]);
        self::assertInstanceOf(LettrageEcriture::class, $lettrage);
        self::assertSame($resultat['reconciliationCode'], $lettrage->getReconciliationCode());
    }

    public function testConfirmationDeuxiemeFoisSurMemeLigneRefuse409(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $ligneEcriture = $this->creerLigneEcritureBancaireScellee('200.00', 'debit', new \DateTimeImmutable('2026-08-11'));

        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligneReleve1 = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-11', 'Encaissement 1', '200.00', 'R1');
        $ligneReleve2 = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-11', 'Encaissement 2', '200.00', 'R2');

        $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligneReleve1['id'] . '/reconcile', $entete + [
            'json' => ['ledgerLineIds' => [(string) $ligneEcriture->getId()]],
        ]);

        $reponse = $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligneReleve2['id'] . '/reconcile', $entete + [
            'json' => ['ledgerLineIds' => [(string) $ligneEcriture->getId()]],
        ]);

        self::assertSame(409, $reponse->getStatusCode());
    }

    public function testConfirmationGroupeeLettrerGroupeSiEquilibree(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $ligneDebit = $this->creerLigneEcritureBancaireScellee('500.00', 'debit', new \DateTimeImmutable('2026-08-12'), 'Écriture débit');
        $ligneCredit = $this->creerLigneEcritureBancaireScellee('500.00', 'credit', new \DateTimeImmutable('2026-08-12'), 'Écriture crédit');

        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligneReleve = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-12', 'Remise groupée', '0.00');

        $reponse = $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligneReleve['id'] . '/reconcile', $entete + [
            'json' => ['ledgerLineIds' => [(string) $ligneDebit->getId(), (string) $ligneCredit->getId()]],
        ]);

        self::assertSame(201, $reponse->getStatusCode());
        $resultat = $reponse->toArray();
        self::assertSame('reconciled', $resultat['status']);

        $lettrageDebit = $this->em()->getRepository(LettrageEcriture::class)->findOneBy(['ligne' => $ligneDebit->getId()]);
        $lettrageCredit = $this->em()->getRepository(LettrageEcriture::class)->findOneBy(['ligne' => $ligneCredit->getId()]);
        self::assertInstanceOf(LettrageEcriture::class, $lettrageDebit);
        self::assertInstanceOf(LettrageEcriture::class, $lettrageCredit);
        self::assertSame($lettrageDebit->getReconciliationCode(), $lettrageCredit->getReconciliationCode());
    }

    public function testConfirmationGroupeeDesequilibreeRefuse422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);
        $ligne1 = $this->creerLigneEcritureBancaireScellee('500.00', 'debit', new \DateTimeImmutable('2026-08-13'), 'Écriture 1');
        $ligne2 = $this->creerLigneEcritureBancaireScellee('300.00', 'debit', new \DateTimeImmutable('2026-08-13'), 'Écriture 2');

        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligneReleve = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-13', 'Remise déséquilibrée', '0.00');

        $reponse = $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligneReleve['id'] . '/reconcile', $entete + [
            'json' => ['ledgerLineIds' => [(string) $ligne1->getId(), (string) $ligne2->getId()]],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
    }
}
