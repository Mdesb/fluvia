<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Unit;

use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Enum\SeqTpSepa;
use App\Sepa\Enum\VarianteCreancierSepa;
use App\Sepa\Service\ChiffreurIban;
use App\Sepa\Service\Pain008Generator;
use PHPUnit\Framework\TestCase;

/**
 * `Pain008Generator` (plan §1/§3/§8) : conformité structurelle aux échantillons de référence anonymisés
 * (`specs/sepa/exemples/pain008-{regie,prive}-*.xml`) — seule différence structurante = le bloc
 * créancier bi-régime (`UltmtCdtr`/`AmdmntInd`). `NbOfTxs`/`CtrlSum` exacts, un `PmtInf` par `SeqTp`.
 *
 * Coffre IBAN réversible (`ChiffreurIbanInterface`) : le générateur déchiffre côté serveur pour porter
 * le **véritable IBAN** (fictif) dans le XML — repli sur un placeholder si l'IBAN chiffré est absent.
 */
final class Pain008GeneratorTest extends TestCase
{
    private const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02';

    private ChiffreurIban $chiffreur;

    protected function setUp(): void
    {
        $this->chiffreur = new ChiffreurIban('cle-test-pain008-chiffrement-iban');
    }

    private function generator(): Pain008Generator
    {
        return new Pain008Generator($this->chiffreur);
    }

    public function testVarianteRegieContientUltmtCdtrEtAmdmntIndCdtrEstLaCollectivite(): void
    {
        $config = $this->configRegie();
        $remise = $this->remise([
            $this->ligne($this->mandat('0189'), SeqTpSepa::Frst, 6300, 'EX00000000000001'),
            $this->ligne($this->mandat('0201'), SeqTpSepa::Frst, 6300, 'EX00000000000002'),
        ], 'EXEMPLE-REGIE-SDD-0001');

        $xml = $this->generator()->generer($remise, $config);
        $xpath = $this->xpath($xml);

        self::assertSame('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02', $this->racine($xml)->namespaceURI);
        self::assertSame('2', $this->valeur($xpath, '//p:GrpHdr/p:NbOfTxs'));
        self::assertSame('126.00', $this->valeur($xpath, '//p:GrpHdr/p:CtrlSum'));
        self::assertSame(1, $xpath->query('//p:PmtInf')->length, 'Une seule SeqTp (FRST) : un seul PmtInf.');
        self::assertSame('FRST', $this->valeur($xpath, '//p:PmtInf/p:PmtTpInf/p:SeqTp'));
        self::assertSame('COLLECTIVITE EXEMPLE / VILLE-MODELE', $this->valeur($xpath, '//p:PmtInf/p:Cdtr/p:Nm'), 'Régie : Cdtr = la collectivité.');
        self::assertSame(1, $xpath->query('//p:PmtInf/p:UltmtCdtr')->length, 'Régie : UltmtCdtr présent.');
        self::assertSame('REGIE EXEMPLE CENTRE NAUTIQUE', $this->valeur($xpath, '//p:PmtInf/p:UltmtCdtr/p:Nm'));
        self::assertSame('00000000000000', $this->valeur($xpath, '//p:PmtInf/p:UltmtCdtr/p:Id/p:OrgId/p:Othr/p:Id'));
        self::assertGreaterThan(0, $xpath->query('//p:DrctDbtTx/p:MndtRltdInf/p:AmdmntInd')->length, 'Régie : AmdmntInd présent.');
    }

    public function testVariantePriveNaAucunUltmtCdtrCdtrEstLentiteEllememe(): void
    {
        $config = $this->configPrive();
        $remise = $this->remise([
            $this->ligne($this->mandat('0502'), SeqTpSepa::Rcur, 4030, '1000001'),
            $this->ligne($this->mandat('0603'), SeqTpSepa::Rcur, 4710, '1000002'),
        ], 'EXEMPLE-PRIVE-SDD-0001');

        $xml = $this->generator()->generer($remise, $config);
        $xpath = $this->xpath($xml);

        self::assertSame('87.40', $this->valeur($xpath, '//p:GrpHdr/p:CtrlSum'));
        self::assertSame('RCUR', $this->valeur($xpath, '//p:PmtInf/p:PmtTpInf/p:SeqTp'));
        self::assertSame('CLUB EXEMPLE FITNESS', $this->valeur($xpath, '//p:PmtInf/p:Cdtr/p:Nm'), 'Privé : Cdtr = l\'entité elle-même.');
        self::assertSame(0, $xpath->query('//p:UltmtCdtr')->length, 'Privé : jamais de UltmtCdtr.');
        self::assertSame(0, $xpath->query('//p:AmdmntInd')->length, 'Privé : jamais de AmdmntInd.');
    }

    public function testRemiseMixteProduitUnPmtInfParSeqTp(): void
    {
        $config = $this->configPrive();
        $remise = $this->remise([
            $this->ligne($this->mandat('0111'), SeqTpSepa::Frst, 1000, 'E1'),
            $this->ligne($this->mandat('0222'), SeqTpSepa::Rcur, 2000, 'E2'),
            $this->ligne($this->mandat('0333'), SeqTpSepa::Rcur, 3000, 'E3'),
        ], 'MIXTE-0001');

        $xml = $this->generator()->generer($remise, $config);
        $xpath = $this->xpath($xml);

        self::assertSame('3', $this->valeur($xpath, '//p:GrpHdr/p:NbOfTxs'));
        self::assertSame('60.00', $this->valeur($xpath, '//p:GrpHdr/p:CtrlSum'));
        self::assertSame(2, $xpath->query('//p:PmtInf')->length, 'FRST + RCUR : deux PmtInf.');

        $seqTps = [];
        foreach ($xpath->query('//p:PmtInf') as $pmtInf) {
            $seqTps[] = $xpath->evaluate('string(.//p:PmtTpInf/p:SeqTp)', $pmtInf);
        }
        sort($seqTps);
        self::assertSame(['FRST', 'RCUR'], $seqTps);

        // PmtInf FRST : 1 transaction (10.00€) ; PmtInf RCUR : 2 transactions (50.00€).
        $nbOfTxsParPmtInf = [];
        foreach ($xpath->query('//p:PmtInf') as $pmtInf) {
            $nbOfTxsParPmtInf[] = $xpath->evaluate('string(./p:NbOfTxs)', $pmtInf);
        }
        sort($nbOfTxsParPmtInf);
        self::assertSame(['1', '2'], $nbOfTxsParPmtInf);
    }

    public function testIbanReelDechiffreEstPorteParLeXmlQuandUnIbanChiffreEstDisponible(): void
    {
        $ibanDebiteur = 'FR7630006000011234567890189';
        $ibanCreancier = 'FR7630004000031234567890143';

        $config = $this->configPrive();
        $config->setCreancierIbanChiffre($this->chiffreur->chiffrer($ibanCreancier));
        $mandat = $this->mandat('0189');
        $mandat->setIbanChiffre($this->chiffreur->chiffrer($ibanDebiteur));
        $remise = $this->remise([$this->ligne($mandat, SeqTpSepa::Frst, 100, 'E1')], 'IBAN-TEST');

        $xml = $this->generator()->generer($remise, $config);
        $xpath = $this->xpath($xml);

        self::assertStringContainsString($ibanDebiteur, $xml, 'IBAN débiteur réel (fictif) porté par le XML, pas un placeholder.');
        self::assertStringContainsString($ibanCreancier, $xml, 'IBAN créancier réel (fictif) porté par le XML, pas un placeholder.');
        self::assertSame($ibanCreancier, $this->valeur($xpath, '//p:PmtInf/p:CdtrAcct/p:Id/p:IBAN'));
        self::assertSame($ibanDebiteur, $this->valeur($xpath, '//p:DrctDbtTxInf/p:DbtrAcct/p:Id/p:IBAN'));
    }

    public function testIbanPlaceholderStructurellementValideEnReplisQuandAucunIbanChiffre(): void
    {
        // Aucun `ibanChiffre` fourni (donnée non migrée) : repli sur un placeholder structurellement
        // valide portant les 4 derniers chiffres connus, jamais un IBAN réel inventé.
        $config = $this->configPrive();
        $remise = $this->remise([$this->ligne($this->mandat('0502'), SeqTpSepa::Frst, 100, 'E1')], 'IBAN-TEST');

        $xml = $this->generator()->generer($remise, $config);

        self::assertStringNotContainsString('FR7630006000011234567890189', $xml);
        self::assertStringContainsString('0502', $xml, 'Les 4 derniers chiffres connus restent dans le placeholder IBAN.');
        self::assertMatchesRegularExpression('/<IBAN>FR760+0502<\/IBAN>/', $xml);
    }

    private function configRegie(): ConfigCreancierSepa
    {
        $config = new ConfigCreancierSepa();
        $config->setVariante(VarianteCreancierSepa::Regie)
            ->setIcs('FR00ZZZ000000')
            ->setCreancierNom('REGIE EXEMPLE CENTRE NAUTIQUE')
            ->setCreancierBic('BDFEFRPPCCT')
            ->setCreancierIban4Derniers('0097')
            ->setCollectiviteNom('COLLECTIVITE EXEMPLE / VILLE-MODELE')
            ->setUltimateCreancierNom('REGIE EXEMPLE CENTRE NAUTIQUE')
            ->setUltimateCreancierOrgId('00000000000000');

        return $config;
    }

    private function configPrive(): ConfigCreancierSepa
    {
        $config = new ConfigCreancierSepa();
        $config->setVariante(VarianteCreancierSepa::Prive)
            ->setIcs('FR00ZZZ111111')
            ->setCreancierNom('CLUB EXEMPLE FITNESS')
            ->setCreancierBic('CMCIFRPPXXX')
            ->setCreancierIban4Derniers('0399');

        return $config;
    }

    private function mandat(string $quatreDerniers): MandatSepa
    {
        $mandat = new MandatSepa();
        $mandat->setRum('RUM-TEST-' . $quatreDerniers)
            ->setBicDebiteur('AGRIFRPPXXX')
            ->setDebiteurNom('Prenom-Exemple')
            ->setIban4Derniers($quatreDerniers)
            ->setDateSignature(new \DateTimeImmutable('2025-12-01'));

        return $mandat;
    }

    private function ligne(MandatSepa $mandat, SeqTpSepa $seqTp, int $montantCentimes, string $endToEndId): LigneRemiseSepa
    {
        $ligne = new LigneRemiseSepa();
        $ligne->setMandat($mandat)
            ->setSeqTp($seqTp)
            ->setMontantCentimes($montantCentimes)
            ->setEndToEndId($endToEndId)
            ->setLibelle('Facture ' . $endToEndId);

        return $ligne;
    }

    /** @param list<LigneRemiseSepa> $lignes */
    private function remise(array $lignes, string $messageId): RemiseSepa
    {
        $remise = new RemiseSepa();
        $remise->setMessageId($messageId)
            ->setDateCreation(new \DateTimeImmutable('2026-01-05T10:00:00'))
            ->setDateCollecte(new \DateTimeImmutable('2026-01-10'));

        $total = 0;
        foreach ($lignes as $ligne) {
            $remise->addLigne($ligne);
            $total += $ligne->getMontantCentimes();
        }
        $remise->setNbTxs(\count($lignes))->setCtrlSumCentimes($total);

        return $remise;
    }

    private function xpath(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('p', self::NS);

        return $xpath;
    }

    private function racine(string $xml): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $racine = $document->documentElement;
        self::assertNotNull($racine);

        return $racine;
    }

    private function valeur(\DOMXPath $xpath, string $expression): string
    {
        return $xpath->evaluate('string(' . $expression . ')');
    }
}
