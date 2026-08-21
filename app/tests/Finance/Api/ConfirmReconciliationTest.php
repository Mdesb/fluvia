<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\TauxTva;
use App\Compta\Service\DirectLedgerEntryBuilder;
use App\Compta\Service\PeriodeComptableResolver;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * §0.2 point 3 du plan — `ledgerLineIds` fourni dans le corps de `POST …/reconcile` est un identifiant
 * client non protégé par l'extension Doctrine (D8) : chaque `LigneEcriture` référencée doit appartenir
 * au **même** `CompteComptable` que `bankStatementLine.statementImport.bankAccount.ledgerAccount` —
 * 404 sinon, même si la ligne appartient au même établissement (défense en profondeur inter-comptes,
 * pas seulement inter-établissements).
 */
final class ConfirmReconciliationTest extends TreasuryApiTestCase
{
    public function testLedgerLineIdDunAutreCompteRefuse404(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);

        // Ligne d'écriture scellée, montant identique, MAIS sur le compte 706100 (produit), pas 512000.
        $ligneAutreCompte = $this->creerLigneNonBancaireScellee('500.00', new \DateTimeImmutable('2026-08-14'));

        $import = $this->creerImportManuel($client, $entete, $compte['id']);
        $ligneReleve = $this->creerLigneManuelle($client, $entete, $import['id'], '2026-08-14', 'Encaissement', '500.00');

        $reponse = $client->request('POST', '/api/finance/treasury/statement-lines/' . $ligneReleve['id'] . '/reconcile', $entete + [
            'json' => ['ledgerLineIds' => [(string) $ligneAutreCompte->getId()]],
        ]);

        self::assertSame(404, $reponse->getStatusCode());
    }

    private function creerLigneNonBancaireScellee(string $montantDecimal, \DateTimeImmutable $date): \App\Compta\Entity\LigneEcriture
    {
        $em = $this->em();
        $profil = $this->profilExploitant();
        /** @var Journal $journal */
        $journal = $this->entite(Journal::class, ['profilExploitant' => $profil->getId(), 'code' => 'BNQ']);
        /** @var CompteComptable $compteProduit */
        $compteProduit = $this->entite(CompteComptable::class, ['profilExploitant' => $profil->getId(), 'numero' => '706100']);
        /** @var CompteComptable $compteRedevable */
        $compteRedevable = $this->entite(CompteComptable::class, ['profilExploitant' => $profil->getId(), 'numero' => '411000']);
        /** @var TauxTva $taux */
        $taux = $this->entite(TauxTva::class, ['profilExploitant' => $profil->getId(), 'taux' => '20.00']);

        $periodeResolver = static::getContainer()->get(PeriodeComptableResolver::class);
        \assert($periodeResolver instanceof PeriodeComptableResolver);
        $periode = $periodeResolver->resoudreOuCreer($profil, $date);

        $montantCentimes = (int) round(((float) $montantDecimal) * 100);

        $builder = static::getContainer()->get(DirectLedgerEntryBuilder::class);
        \assert($builder instanceof DirectLedgerEntryBuilder);
        $ecriture = $builder->construire($profil, $journal, $periode, $date, 'Écriture non bancaire', [
            new DirectLedgerEntryLine($compteRedevable, $montantCentimes, 0, $taux),
            new DirectLedgerEntryLine($compteProduit, 0, $montantCentimes, $taux),
        ]);
        $em->flush();

        foreach ($ecriture->getLignes() as $ligne) {
            if ($ligne->getCompte()?->getId()->equals($compteProduit->getId())) {
                return $ligne;
            }
        }

        throw new \LogicException('Ligne 706100 introuvable.');
    }
}
