<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ExportComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\FormatExport;
use App\Compta\Enum\SensCompte;
use App\Compta\Export\ExportFecAdapter;
use PHPUnit\Framework\TestCase;

/**
 * US-L4-07/CA-11 : export FEC légal 18 champs — implémentation réelle, testable de bout en bout
 * (fixture -> fichier -> assertions colonnes), sans dépendance au kernel.
 */
final class ExportFecAdapterTest extends TestCase
{
    public function testGenereUnFichierAvecLes18ChampsEtLesMontantsCorrects(): void
    {
        $profil = new ProfilExploitant();
        $journal = (new Journal())->setProfilExploitant($profil)->setCode('VTE')->setLibelle('Journal des ventes');
        $compteProduit = (new CompteComptable())->setProfilExploitant($profil)->setNumero('706100')->setLibelle('Billetterie')->setSens(SensCompte::Credit);
        $compteEncaissement = (new CompteComptable())->setProfilExploitant($profil)->setNumero('511000')->setLibelle('Encaissements')->setSens(SensCompte::Debit);
        $taux = (new TauxTva())->setProfilExploitant($profil)->setTaux('20.00')->setLibelle('Taux normal 20 %');

        $ecriture = new EcritureComptable();
        $ecriture->setProfilExploitant($profil);
        $ecriture->setJournal($journal);
        $ecriture->setDateEcriture(new \DateTimeImmutable('2026-03-15'));
        $ecriture->setLibelle('Vente VTE-0001');
        $ecriture->setNumeroSequence(1);
        $ecriture->setEmpreinte('empreinte-test');
        $ecriture->setSignature('signature-test');

        $ligneDebit = (new LigneEcriture())->setCompte($compteEncaissement)->setDebitCentimes(550)->setCreditCentimes(0)->setTauxTva($taux);
        $ligneCredit = (new LigneEcriture())->setCompte($compteProduit)->setDebitCentimes(0)->setCreditCentimes(550)->setTauxTva($taux);
        $ecriture->addLigne($ligneDebit);
        $ecriture->addLigne($ligneCredit);

        $export = new ExportComptable();
        $export->setProfilExploitant($profil);
        $export->setFormat(FormatExport::Fec);

        $adaptateur = new ExportFecAdapter();
        self::assertSame(FormatExport::Fec, $adaptateur->format());

        $contenu = $adaptateur->generer($export, [$ecriture]);
        $lignes = explode("\n", $contenu);

        $entete = explode("\t", $lignes[0]);
        self::assertCount(18, $entete, 'Le FEC doit comporter exactement 18 champs.');
        self::assertSame(ExportFecAdapter::CHAMPS, $entete);

        // 2 lignes d'écriture -> 2 lignes de données après l'entête.
        self::assertCount(3, $lignes);

        $colonnesDebit = explode("\t", $lignes[1]);
        self::assertSame('VTE', $colonnesDebit[0]);
        self::assertSame('511000', $colonnesDebit[4]);
        self::assertSame('5,50', $colonnesDebit[11]); // Debit
        self::assertSame('0,00', $colonnesDebit[12]); // Credit

        $colonnesCredit = explode("\t", $lignes[2]);
        self::assertSame('706100', $colonnesCredit[4]);
        self::assertSame('0,00', $colonnesCredit[11]);
        self::assertSame('5,50', $colonnesCredit[12]);
    }
}
