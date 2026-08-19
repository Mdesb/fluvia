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
use Symfony\Component\Uid\Uuid;

/**
 * CA-4/CA-7 (RG-M6-13, non-régression FEC) : les colonnes `CompAuxNum`/`CompAuxLib` (indices 6/7,
 * toujours vides avant ce lot) sont désormais peuplées **uniquement** quand la ligne porte un tiers ;
 * une ligne sans tiers — y compris une écriture historique déjà scellée avant ce lot, rejouée ici en
 * fixture — produit exactement `''`/`''`, **octet à octet identique** à la sortie d'avant ce lot :
 * toujours 18 colonnes, même séparateur tabulation, mêmes en-têtes `ExportFecAdapter::CHAMPS`.
 */
final class ExportFecCompAuxNonRegressionTest extends TestCase
{
    public function testLigneSansTiersProduitDesColonnesAuxiliairesVidesInchangees(): void
    {
        $ecriture = $this->ecritureAvecUneLigne(counterpartyId: null, counterpartyLabel: null);

        $contenu = (new ExportFecAdapter())->generer($this->export($ecriture), [$ecriture]);
        $lignes = explode("\n", $contenu);

        self::assertCount(2, $lignes, 'Entête + 1 ligne de donnée.');
        $entete = explode("\t", $lignes[0]);
        self::assertCount(18, $entete);
        self::assertSame(ExportFecAdapter::CHAMPS, $entete, 'Mêmes 18 en-têtes, même ordre : structure FEC inchangée.');

        $colonnes = explode("\t", $lignes[1]);
        self::assertCount(18, $colonnes);
        self::assertSame('', $colonnes[6], 'CompAuxNum doit rester vide pour une ligne sans tiers (non-régression).');
        self::assertSame('', $colonnes[7], 'CompAuxLib doit rester vide pour une ligne sans tiers (non-régression).');
    }

    public function testLigneAvecTiersPeupleLesColonnesAuxiliaires(): void
    {
        $tiersId = Uuid::v4();
        $ecriture = $this->ecritureAvecUneLigne(counterpartyId: $tiersId, counterpartyLabel: 'Fournisseur Piscine SARL');

        $contenu = (new ExportFecAdapter())->generer($this->export($ecriture), [$ecriture]);
        $lignes = explode("\n", $contenu);

        $colonnes = explode("\t", $lignes[1]);
        self::assertSame((string) $tiersId, $colonnes[6], 'CA-4 : CompAuxNum peuplé quand le tiers est renseigné.');
        self::assertSame('Fournisseur Piscine SARL', $colonnes[7], 'CA-4 : CompAuxLib peuplé quand le tiers est renseigné.');
    }

    private function ecritureAvecUneLigne(?Uuid $counterpartyId, ?string $counterpartyLabel): EcritureComptable
    {
        $profil = new ProfilExploitant();
        $journal = (new Journal())->setProfilExploitant($profil)->setCode('OD')->setLibelle('Opérations diverses');
        $compte = (new CompteComptable())->setProfilExploitant($profil)->setNumero('401000')->setLibelle('Fournisseurs')->setSens(SensCompte::Credit);
        $taux = (new TauxTva())->setProfilExploitant($profil)->setTaux('20.00')->setLibelle('Taux normal 20 %');

        $ecriture = new EcritureComptable();
        $ecriture->setProfilExploitant($profil);
        $ecriture->setJournal($journal);
        $ecriture->setDateEcriture(new \DateTimeImmutable('2026-08-19'));
        $ecriture->setLibelle('OD test non-régression');
        $ecriture->setNumeroSequence(1);
        $ecriture->setEmpreinte('empreinte-test');
        $ecriture->setSignature('signature-test');

        $ligne = (new LigneEcriture())->setCompte($compte)->setCreditCentimes(1200)->setTauxTva($taux);
        if ($counterpartyId !== null) {
            $ligne->setCounterpartyType('stock_fournisseur');
            $ligne->setCounterpartyId($counterpartyId);
        }
        if ($counterpartyLabel !== null) {
            $ligne->setCounterpartyLabel($counterpartyLabel);
        }
        $ecriture->addLigne($ligne);

        return $ecriture;
    }

    private function export(EcritureComptable $ecriture): ExportComptable
    {
        $export = new ExportComptable();
        $export->setProfilExploitant($ecriture->getProfilExploitant());
        $export->setFormat(FormatExport::Fec);

        return $export;
    }
}
