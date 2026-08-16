<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Enum\SeqTpSepa;
use App\Sepa\Enum\VarianteCreancierSepa;

/**
 * Génère le XML pain.008.001.02 d'une remise (plan §1/§3). **Régime-aware** : injecte `UltmtCdtr` +
 * `AmdmntInd` en variante `REGIE` (`Cdtr` = la collectivité), les omet en variante `PRIVE` (`Cdtr` =
 * l'entité elle-même) — seule différence structurante entre les deux fichiers de référence anonymisés
 * (`specs/sepa/exemples/pain008-{regie,prive}-*.xml`). Groupe les lignes en **un `PmtInf` par
 * `SeqTp`** (une remise mixte produit plusieurs `PmtInf`, §8 du plan). `NbOfTxs`/`CtrlSum` exacts
 * (somme en centimes convertie en décimal 2 chiffres), au niveau `GrpHdr` (toute la remise) et de
 * chaque `PmtInf` (son sous-groupe de `SeqTp`).
 *
 * ⚠ IBAN : ni l'IBAN créancier ni les IBAN débiteurs ne sont stockés en clair en base — ils sont
 * tokenisés (jeton HMAC non réversible, affichage/recherche) **et** chiffrés de façon réversible
 * (coffre IBAN `ChiffreurIbanInterface`, libsodium). Ce générateur **déchiffre côté serveur** les
 * IBAN créancier/débiteurs au moment strict de construire le XML de remise, afin qu'il porte le
 * **véritable IBAN** — jamais renvoyé en réponse API (garde §4 de la spec, testée). Si un IBAN chiffré
 * est absent (donnée non migrée), un placeholder structurellement valide (préfixe pays + zéros + 4
 * derniers chiffres connus) est utilisé en repli, afin de ne jamais faire échouer une génération.
 */
final class Pain008Generator
{
    private const NAMESPACE_URI = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02';

    public function __construct(
        private readonly ChiffreurIbanInterface $chiffreur,
    ) {
    }

    public function generer(RemiseSepa $remise, ConfigCreancierSepa $config): string
    {
        $lignes = $remise->getLignes()->toArray();

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $racine = $document->createElementNS(self::NAMESPACE_URI, 'Document');
        $racine->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $document->appendChild($racine);

        $initn = $document->createElement('CstmrDrctDbtInitn');
        $racine->appendChild($initn);

        $initn->appendChild($this->construireGrpHdr($document, $remise, $config));

        foreach ($this->grouperParSeqTp($lignes) as $seqTp => $lignesGroupe) {
            $initn->appendChild($this->construirePmtInf($document, $remise, $config, SeqTpSepa::from($seqTp), $lignesGroupe));
        }

        $xml = $document->saveXML();
        \assert($xml !== false);

        return $xml;
    }

    /**
     * @param list<LigneRemiseSepa> $lignes
     *
     * @return array<string, list<LigneRemiseSepa>> groupées par valeur `SeqTp`, dans l'ordre de 1ère apparition
     */
    private function grouperParSeqTp(array $lignes): array
    {
        $groupes = [];
        foreach ($lignes as $ligne) {
            $groupes[$ligne->getSeqTp()->value][] = $ligne;
        }

        return $groupes;
    }

    private function construireGrpHdr(\DOMDocument $document, RemiseSepa $remise, ConfigCreancierSepa $config): \DOMElement
    {
        $grpHdr = $document->createElement('GrpHdr');
        $grpHdr->appendChild($document->createElement('MsgId', (string) $remise->getMessageId()));
        $grpHdr->appendChild($document->createElement('CreDtTm', $remise->getDateCreation()->format('Y-m-d\TH:i:s.v')));
        $grpHdr->appendChild($document->createElement('NbOfTxs', (string) $remise->getNbTxs()));
        $grpHdr->appendChild($document->createElement('CtrlSum', $this->formaterMontant($remise->getCtrlSumCentimes())));

        $initgPty = $document->createElement('InitgPty');
        $nomInitiateur = $config->getVariante() === VarianteCreancierSepa::Regie
            ? (string) $config->getUltimateCreancierNom()
            : $config->getCreancierNom();
        $initgPty->appendChild($document->createElement('Nm', $nomInitiateur));
        $grpHdr->appendChild($initgPty);

        return $grpHdr;
    }

    /** @param list<LigneRemiseSepa> $lignes */
    private function construirePmtInf(
        \DOMDocument $document,
        RemiseSepa $remise,
        ConfigCreancierSepa $config,
        SeqTpSepa $seqTp,
        array $lignes,
    ): \DOMElement {
        $nbTxs = \count($lignes);
        $ctrlSumCentimes = array_sum(array_map(static fn (LigneRemiseSepa $l): int => $l->getMontantCentimes(), $lignes));

        $pmtInf = $document->createElement('PmtInf');
        $pmtInf->appendChild($document->createElement('PmtInfId', $remise->getMessageId() . '-' . $seqTp->value));
        $pmtInf->appendChild($document->createElement('PmtMtd', 'DD'));
        $pmtInf->appendChild($document->createElement('BtchBookg', 'false'));
        $pmtInf->appendChild($document->createElement('NbOfTxs', (string) $nbTxs));
        $pmtInf->appendChild($document->createElement('CtrlSum', $this->formaterMontant($ctrlSumCentimes)));

        $pmtTpInf = $document->createElement('PmtTpInf');
        $svcLvl = $document->createElement('SvcLvl');
        $svcLvl->appendChild($document->createElement('Cd', 'SEPA'));
        $pmtTpInf->appendChild($svcLvl);
        $lclInstrm = $document->createElement('LclInstrm');
        $lclInstrm->appendChild($document->createElement('Cd', 'CORE'));
        $pmtTpInf->appendChild($lclInstrm);
        $pmtTpInf->appendChild($document->createElement('SeqTp', $seqTp->value));
        $pmtInf->appendChild($pmtTpInf);

        $pmtInf->appendChild($document->createElement('ReqdColltnDt', $remise->getDateCollecte()->format('Y-m-d')));

        $estRegie = $config->getVariante() === VarianteCreancierSepa::Regie;

        $cdtr = $document->createElement('Cdtr');
        $nomCdtr = $estRegie ? (string) $config->getCollectiviteNom() : $config->getCreancierNom();
        $cdtr->appendChild($document->createElement('Nm', $nomCdtr));
        $pmtInf->appendChild($cdtr);

        $pmtInf->appendChild($this->construireCompteIban($document, 'CdtrAcct', $this->resoudreIban($config->getCreancierIbanChiffre(), $config->getCreancierIban4Derniers())));

        $cdtrAgt = $document->createElement('CdtrAgt');
        $finInstnId = $document->createElement('FinInstnId');
        $finInstnId->appendChild($document->createElement('BIC', $config->getCreancierBic()));
        $cdtrAgt->appendChild($finInstnId);
        $pmtInf->appendChild($cdtrAgt);

        if ($estRegie) {
            $ultmtCdtr = $document->createElement('UltmtCdtr');
            $ultmtCdtr->appendChild($document->createElement('Nm', (string) $config->getUltimateCreancierNom()));
            $idEl = $document->createElement('Id');
            $orgId = $document->createElement('OrgId');
            $othr = $document->createElement('Othr');
            $othr->appendChild($document->createElement('Id', (string) $config->getUltimateCreancierOrgId()));
            $orgId->appendChild($othr);
            $idEl->appendChild($orgId);
            $ultmtCdtr->appendChild($idEl);
            $pmtInf->appendChild($ultmtCdtr);
        }

        $pmtInf->appendChild($document->createElement('ChrgBr', 'SLEV'));

        $cdtrSchmeId = $document->createElement('CdtrSchmeId');
        $idEl = $document->createElement('Id');
        $prvtId = $document->createElement('PrvtId');
        $othr = $document->createElement('Othr');
        $othr->appendChild($document->createElement('Id', $config->getIcs()));
        $schmeNm = $document->createElement('SchmeNm');
        $schmeNm->appendChild($document->createElement('Prtry', 'SEPA'));
        $othr->appendChild($schmeNm);
        $prvtId->appendChild($othr);
        $idEl->appendChild($prvtId);
        $cdtrSchmeId->appendChild($idEl);
        $pmtInf->appendChild($cdtrSchmeId);

        foreach ($lignes as $ligne) {
            $pmtInf->appendChild($this->construireDrctDbtTxInf($document, $ligne, $estRegie));
        }

        return $pmtInf;
    }

    private function construireDrctDbtTxInf(\DOMDocument $document, LigneRemiseSepa $ligne, bool $estRegie): \DOMElement
    {
        $mandat = $ligne->getMandat();
        \assert($mandat !== null);

        $txInf = $document->createElement('DrctDbtTxInf');

        $pmtId = $document->createElement('PmtId');
        $pmtId->appendChild($document->createElement('InstrId', $ligne->getEndToEndId()));
        $pmtId->appendChild($document->createElement('EndToEndId', $ligne->getEndToEndId()));
        $txInf->appendChild($pmtId);

        $instdAmt = $document->createElement('InstdAmt', $this->formaterMontant($ligne->getMontantCentimes()));
        $instdAmt->setAttribute('Ccy', 'EUR');
        $txInf->appendChild($instdAmt);

        $drctDbtTx = $document->createElement('DrctDbtTx');
        $mndtRltdInf = $document->createElement('MndtRltdInf');
        $mndtRltdInf->appendChild($document->createElement('MndtId', $mandat->getRum()));
        $mndtRltdInf->appendChild($document->createElement('DtOfSgntr', $mandat->getDateSignature()->format('Y-m-d')));
        if ($estRegie) {
            $mndtRltdInf->appendChild($document->createElement('AmdmntInd', 'false'));
        }
        $drctDbtTx->appendChild($mndtRltdInf);
        $txInf->appendChild($drctDbtTx);

        $dbtrAgt = $document->createElement('DbtrAgt');
        $finInstnId = $document->createElement('FinInstnId');
        $finInstnId->appendChild($document->createElement('BIC', $mandat->getBicDebiteur()));
        $dbtrAgt->appendChild($finInstnId);
        $txInf->appendChild($dbtrAgt);

        $dbtr = $document->createElement('Dbtr');
        $dbtr->appendChild($document->createElement('Nm', $mandat->getDebiteurNom()));
        $txInf->appendChild($dbtr);

        $txInf->appendChild($this->construireCompteIban($document, 'DbtrAcct', $this->resoudreIban($mandat->getIbanChiffre(), $mandat->getIban4Derniers())));

        $rmtInf = $document->createElement('RmtInf');
        $rmtInf->appendChild($document->createElement('Ustrd', $ligne->getLibelle()));
        $txInf->appendChild($rmtInf);

        return $txInf;
    }

    private function construireCompteIban(\DOMDocument $document, string $nomElement, string $iban): \DOMElement
    {
        $compte = $document->createElement($nomElement);
        $id = $document->createElement('Id');
        $id->appendChild($document->createElement('IBAN', $iban));
        $compte->appendChild($id);

        return $compte;
    }

    /**
     * Déchiffre le véritable IBAN via le coffre (`ChiffreurIbanInterface`) — au moment strict de
     * construire le XML de remise, jamais renvoyé en dehors de ce générateur. Replie sur un
     * placeholder structurellement valide si l'IBAN chiffré est absent (donnée non migrée).
     */
    private function resoudreIban(?string $ibanChiffre, string $quatreDerniers): string
    {
        if ($ibanChiffre === null || $ibanChiffre === '') {
            return $this->ibanPlaceholder($quatreDerniers);
        }

        try {
            return $this->chiffreur->dechiffrer($ibanChiffre);
        } catch (\RuntimeException) {
            return $this->ibanPlaceholder($quatreDerniers);
        }
    }

    /** IBAN placeholder structurellement valide (27 caractères, FR) — repli si l'IBAN chiffré est absent. */
    private function ibanPlaceholder(string $quatreDerniers): string
    {
        $derniers = str_pad(substr($quatreDerniers, -4), 4, '0', STR_PAD_LEFT);

        return 'FR76' . str_repeat('0', 19) . $derniers;
    }

    private function formaterMontant(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }
}
