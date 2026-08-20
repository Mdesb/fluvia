<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\Autorisation\Enum\StatutEscalade;
use App\Tests\Finance\ExpenseReportApiTestCase;

/**
 * CA-2/CA-3 (US-EXP-04, RG-EXP-04) : workflow de validation gradué, entièrement délégué à
 * `App\Autorisation`. RG-EXP-04 littéral : `/submit` renvoie un succès HTTP même en cas d'escalade
 * requise (divergence assumée par rapport au patron M2, §0.3.1) — la note reste `submitted`, ce n'est
 * pas un échec de la requête.
 */
final class SubmitExpenseReportHandlerTest extends ExpenseReportApiTestCase
{
    public function testSousPlafondApprouveEtDeversementDeclenche(): void
    {
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.sous-plafond@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '40.00');

        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        self::assertSame(201, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);
        self::assertSame('approved', $corps['status']);
        self::assertNotNull($corps['approvedAt'] ?? null);
        self::assertNotNull($corps['ledgerEntry'] ?? null, 'Mapping complet (EXPENSE_NATURE_MAPPED) : déversement comptable déclenché synchronement.');
    }

    public function testAuDelaDuPlafondEscaladeCreeeNoteResteSubmitted(): void
    {
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, true);

        [$client, $entete, $idEmploye] = $this->salarie('expense.escalade@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '250.00');

        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        self::assertSame(201, $reponse->getStatusCode(), 'RG-EXP-04 : succès HTTP même en cas d\'escalade requise, pas un 403 (divergence assumée du patron M2).');
        $corps = $reponse->toArray(false);
        self::assertSame('submitted', $corps['status']);
        self::assertNotNull($corps['escalationRequest'] ?? null);

        $demandeId = basename((string) $corps['escalationRequest']);
        $demande = $this->em()->getRepository(DemandeEscalade::class)->find($demandeId);
        self::assertInstanceOf(DemandeEscalade::class, $demande);
        self::assertSame(StatutEscalade::EnAttente, $demande->getStatut());
        self::assertSame('250.00', $demande->getMontant());
    }

    public function testAucuneEscaladePossibleRejetteImmediatement(): void
    {
        $this->configurerLimite('100.00', PerimetreAutorisation::Global, false);

        [$client, $entete, $idEmploye] = $this->salarie('expense.refus@itcotation.com');
        $note = $this->creerNoteAvecLigne($client, $entete, $idEmploye, [], '250.00');

        $reponse = $client->request('POST', '/api/finance/expense-reports/' . $note['id'] . '/submit', $entete + ['json' => []]);

        self::assertSame(201, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);
        self::assertSame('rejected', $corps['status']);
        self::assertNotNull($corps['rejectionReason'] ?? null);
    }
}
