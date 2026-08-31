<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Import\Dto\ParsedImportBatch;
use App\Import\Dto\ParsedImportRow;
use App\Import\Port\ImportFileParserInterface;

/**
 * Parseur CSV générique (plan-import-i1.md §0.4, seul adaptateur construit par ce lot) : lit une ligne
 * d'en-tête et restitue chaque ligne suivante en tableau associatif **par nom de colonne** (clés
 * normalisées : minuscules, espaces retirés), délimiteur `;`. Numérotation des lignes en base 1,
 * en-tête = ligne 1 — cohérent avec les messages d'erreur qui doivent nommer la ligne du fichier tel
 * que l'utilisateur le voit dans son tableur.
 *
 * Une colonne « establishment »/« etablissement » présente dans le fichier est restituée comme
 * n'importe quelle autre colonne : c'est au `RowImporter` de ne jamais la lire (§0.7, D41), pas au
 * parseur de la censurer — un parseur générique ne connaît pas la sémantique des colonnes.
 */
final class CsvImportParser implements ImportFileParserInterface
{
    public function supports(string $mimeType, string $fileName): bool
    {
        $mime = strtolower(trim($mimeType));
        $name = strtolower(trim($fileName));

        if ($name !== '' && !str_ends_with($name, '.csv')) {
            return false;
        }

        if ($mime !== '' && !str_contains($mime, 'csv') && !str_contains($mime, 'text/plain') && !str_contains($mime, 'octet-stream')) {
            return false;
        }

        return true;
    }

    public function parse(string $rawContent): ParsedImportBatch
    {
        $lignesBrutes = preg_split('/\r\n|\r|\n/', rtrim($rawContent, "\r\n"));
        if ($lignesBrutes === false || $lignesBrutes === [] || trim($rawContent) === '') {
            return new ParsedImportBatch([], ['Fichier vide ou illisible.']);
        }

        $enteteBrut = array_shift($lignesBrutes);
        $entetes = array_map(
            static fn (string $colonne): string => str_replace(' ', '', strtolower(trim($colonne))),
            // `$escape` explicite (PHP 8.4+, RFC deprecation) : `""` neutralise l'échappement legacy,
            // même geste que `CsvBankStatementParser`.
            str_getcsv((string) $enteteBrut, ';', '"', ''),
        );

        $rows = [];
        foreach ($lignesBrutes as $index => $ligneBrute) {
            if (trim((string) $ligneBrute) === '') {
                continue;
            }

            $valeurs = str_getcsv((string) $ligneBrute, ';', '"', '');
            $colonnes = [];
            foreach ($entetes as $position => $nom) {
                if ($nom === '') {
                    continue;
                }
                $colonnes[$nom] = trim((string) ($valeurs[$position] ?? ''));
            }

            // En-tête = ligne 1 (§0.4) ; $index démarre à 0 sur la première ligne de données -> ligne 2.
            $rows[] = new ParsedImportRow($index + 2, $colonnes);
        }

        return new ParsedImportBatch($rows);
    }
}
