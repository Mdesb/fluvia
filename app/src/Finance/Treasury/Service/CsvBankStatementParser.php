<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Service;

use App\Finance\Treasury\Dto\ParsedStatementBatch;
use App\Finance\Treasury\Dto\ParsedStatementLine;
use App\Finance\Treasury\Entity\BankAccount;
use App\Finance\Treasury\Enum\BankStatementImportFormat;
use App\Finance\Treasury\Port\BankStatementParserInterface;

/**
 * Parseur CSV minimal (§0.4 du plan, seul adaptateur construit par ce lot) : colonnes
 * `date;libelle;montant;reference` (délimiteur `;`), une ligne d'en-tête ignorée, montant décimal point
 * ou virgule normalisé. Une ligne fautive (montant illisible, date invalide) est comptée dans le motif
 * de retour, **jamais** une exception qui interromprait l'import du fichier entier.
 */
final class CsvBankStatementParser implements BankStatementParserInterface
{
    public function supports(BankStatementImportFormat $format): bool
    {
        return $format === BankStatementImportFormat::Csv;
    }

    public function parse(string $content, BankAccount $account): ParsedStatementBatch
    {
        $lignesBrutes = preg_split('/\r\n|\r|\n/', trim($content));
        if ($lignesBrutes === false) {
            return new ParsedStatementBatch([], ['Fichier illisible.']);
        }

        $lignes = [];
        $motifsIgnores = [];

        foreach ($lignesBrutes as $numero => $ligneBrute) {
            $ligneBrute = trim($ligneBrute);
            if ($ligneBrute === '') {
                continue;
            }
            if ($numero === 0) {
                // Ligne d'en-tête toujours ignorée (§0.4), qu'elle ressemble ou non à un intitulé de
                // colonnes : le format minimal l'impose systématiquement.
                continue;
            }

            // `$escape` explicite (PHP 8.4+, RFC deprecation) : `""` neutralise l'échappement legacy
            // (absent du format minimal §0.4), pas de comportement fonctionnel changé.
            $colonnes = str_getcsv($ligneBrute, ';', '"', '');
            if (\count($colonnes) < 3) {
                $motifsIgnores[] = sprintf('Ligne %d : nombre de colonnes insuffisant (« %s »).', $numero + 1, $ligneBrute);

                continue;
            }

            [$dateBrute, $libelle, $montantBrut] = [$colonnes[0] ?? '', $colonnes[1] ?? '', $colonnes[2] ?? ''];
            $reference = isset($colonnes[3]) && $colonnes[3] !== '' ? trim((string) $colonnes[3]) : null;

            $date = $this->parseDate(trim((string) $dateBrute));
            if ($date === null) {
                $motifsIgnores[] = sprintf('Ligne %d : date invalide (« %s »).', $numero + 1, $dateBrute);

                continue;
            }

            $montant = $this->parseMontant(trim((string) $montantBrut));
            if ($montant === null) {
                $motifsIgnores[] = sprintf('Ligne %d : montant illisible (« %s »).', $numero + 1, $montantBrut);

                continue;
            }

            $lignes[] = new ParsedStatementLine($date, trim((string) $libelle), $montant, $reference);
        }

        return new ParsedStatementBatch($lignes, $motifsIgnores);
    }

    private function parseDate(string $valeur): ?\DateTimeImmutable
    {
        if ($valeur === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $valeur);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }

    private function parseMontant(string $valeur): ?string
    {
        if ($valeur === '') {
            return null;
        }
        $normalise = str_replace(',', '.', str_replace(' ', '', $valeur));
        if (1 !== preg_match('/^-?\d+(\.\d+)?$/', $normalise)) {
            return null;
        }

        return number_format((float) $normalise, 2, '.', '');
    }
}
