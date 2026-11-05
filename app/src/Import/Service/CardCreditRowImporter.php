<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\TypeDroitAcces;
use App\Import\Dto\ParsedImportRow;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportType;
use App\Offre\Entity\Produit;
use Symfony\Component\Uid\Uuid;

/**
 * Reprise des SOLDES DE CARTES (`card_credits`, #348) — le seul type qui porte de l'argent déjà payé.
 *
 * Colonnes : `externalRef` ; `supportIdentifiant` ; `produit` (code d'un produit « carte à
 * entrées ») ; `creditRestant` (entier > 0) ; `dateFin` (facultative) ; `typeSupport` (facultatif).
 *
 * ── LE TOTAL ANNONCÉ N'EST PAS UNE OPTION (SPEC §4) ────────────────────────────────────────────
 *
 * Un export tronqué ressemble à un export court. Le client ANNONCE le nombre total d'entrées restantes
 * (`expectedTotal` du lot), et le moindre écart refuse le lot entier. On ne devine pas une dette.
 *
 * ── UN CRÉDIT ENTAMÉ NE S'ANNULE PLUS ──────────────────────────────────────────────────────────
 *
 * En plus des passages (vus par le scan du mapping), l'annulation compare le crédit de chaque droit à
 * celui du fichier d'origine : un crédit qui a bougé — par un passage, une correction en SQL direct,
 * une recharge — a servi, et l'annulation est refusée.
 */
final class CardCreditRowImporter extends AccessRightRowImporter
{
    public function type(): ImportType
    {
        return ImportType::CardCredits;
    }

    protected function rightType(): TypeDroitAcces
    {
        return TypeDroitAcces::CarteQuota;
    }

    protected function validateFile(array $rows, ?ImportBatch $batch): array
    {
        $annonce = $batch?->getExpectedTotal();
        if ($annonce === null || $annonce === '') {
            return [0 => 'Total annoncé obligatoire : le nombre total d\'entrées restantes que le client annonce. On ne devine pas une dette.'];
        }
        if (!preg_match('/^\d+(\.0+)?$/', $annonce)) {
            return [0 => sprintf('Total annoncé « %s » : un nombre entier d\'entrées.', $annonce)];
        }

        $somme = 0;
        foreach ($rows as $row) {
            $credit = $this->col($row, 'creditrestant');
            if (ctype_digit($credit)) {
                $somme += (int) $credit;
            }
        }
        $attendu = (int) $annonce;
        if ($somme !== $attendu) {
            return [0 => sprintf(
                'Le fichier totalise %d entrée(s) restante(s), le client en annonce %d (écart de %d). Le lot est refusé : '
                . 'l\'export est incomplet, ou le total annoncé est faux.',
                $somme,
                $attendu,
                abs($somme - $attendu),
            )];
        }

        return [];
    }

    protected function validateSpecific(ParsedImportRow $row, Produit $produit, \DateTimeImmutable $today): ?string
    {
        if ($produit->getCarte() === null) {
            return sprintf('Le produit « %s » n\'est pas une carte à entrées.', $produit->getCode());
        }
        $credit = $this->col($row, 'creditrestant');
        $fin = $this->date($row, 'datefin');

        return match (true) {
            !ctype_digit($credit) => sprintf('creditRestant illisible : « %s » (attendu un nombre entier d\'entrées).', $credit),
            (int) $credit <= 0 => 'creditRestant nul : une carte épuisée ne se reprend pas.',
            $fin === false => sprintf('dateFin illisible : « %s » (attendu AAAA-MM-JJ ou JJ/MM/AAAA).', $this->col($row, 'datefin')),
            $fin instanceof \DateTimeImmutable && $fin < $today => sprintf('Carte échue le %s : son solde n\'est plus utilisable, il ne se reprend pas en l\'état.', $fin->format('d/m/Y')),
            default => null,
        };
    }

    protected function hydrateRight(DroitAcces $droit, ParsedImportRow $row, \DateTimeImmutable $today): void
    {
        $fin = $this->date($row, 'datefin');
        $droit->setCreditRestant((int) $this->col($row, 'creditrestant'));
        $droit->setFenetreFin($fin instanceof \DateTimeImmutable ? $fin->setTime(23, 59, 59) : null);
    }

    protected function usedSinceImport(Uuid $batchId): bool
    {
        $batch = $this->em->getRepository(ImportBatch::class)->find($batchId);
        if (!$batch instanceof ImportBatch) {
            return true; // Lot illisible : refuser l'annulation est le sens sûr.
        }
        $binaire = base64_decode($batch->getContent(), true);
        if ($binaire === false) {
            return true;
        }
        $repris = [];
        foreach ($this->parser->parse($binaire)->rows as $row) {
            $repris[$this->col($row, 'externalref')] = (int) $this->col($row, 'creditrestant');
        }
        foreach ($this->rightsOf($batchId) as $droit) {
            if ($droit->getCreditRestant() !== ($repris[(string) $droit->getExternalRef()] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
