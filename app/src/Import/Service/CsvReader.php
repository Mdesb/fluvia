<?php

declare(strict_types=1);

namespace App\Import\Service;

/**
 * Lit un CSV de reprise en lignes nommées, sans jamais interrompre la lecture sur une faute.
 *
 * **Pourquoi il ne lève pas d'exception sur une ligne mauvaise.** La décision « tout refuser en
 * nommant les lignes » (SPEC §1) suppose qu'on ait lu le fichier **entier** avant de juger. Un
 * lecteur qui s'arrête à la première anomalie ne peut produire qu'un rapport à une ligne, et
 * condamne l'exploitant à autant d'allers-retours qu'il a de fautes.
 *
 * **Le numéro de ligne rendu est celui du fichier, pas celui du tableau.** L'en-tête est la ligne 1 ;
 * la première donnée est donc la ligne 2. C'est le numéro que l'exploitant voit dans son tableur, et
 * c'est le seul qui lui serve à corriger.
 */
final class CsvReader
{
    /** Séparateurs essayés, dans cet ordre : le point-virgule est ce que produit un tableur français. */
    private const SEPARATEURS = [';', ',', "\t"];

    /**
     * @return array{
     *     header: list<string>,
     *     rows: list<array{line: int, data: array<string, string>}>,
     *     errors: array<int, string>
     * }
     */
    public function read(string $contenu): array
    {
        $erreurs = [];

        // Le BOM d'un tableur passe dans le nom de la première colonne et rend l'en-tête
        // méconnaissable. On le retire silencieusement : c'est un artefact d'export, pas une faute
        // de l'exploitant, et le lui reprocher n'apprend rien à personne.
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu) ?? $contenu;

        $lignes = preg_split('/\r\n|\r|\n/', $contenu) ?: [];
        $lignes = array_values(array_filter($lignes, static fn (string $l): bool => trim($l) !== ''));

        if ($lignes === []) {
            return ['header' => [], 'rows' => [], 'errors' => [0 => 'Le fichier est vide.']];
        }

        $separateur = $this->deviner($lignes[0]);
        $header = array_map('trim', str_getcsv($lignes[0], $separateur, '"', "\\"));

        if (count($header) !== count(array_unique($header))) {
            $erreurs[1] = 'L\'en-tête contient deux fois la même colonne.';
        }

        $rows = [];
        foreach (array_slice($lignes, 1) as $i => $ligne) {
            $numero = $i + 2; // en-tête = 1, première donnée = 2
            $valeurs = str_getcsv($ligne, $separateur, '"', "\\");

            if (count($valeurs) !== count($header)) {
                $erreurs[$numero] = sprintf(
                    'La ligne a %d colonne(s), l\'en-tête en annonce %d.',
                    count($valeurs),
                    count($header),
                );

                continue;
            }

            $data = [];
            foreach ($header as $j => $nom) {
                $data[$nom] = trim((string) ($valeurs[$j] ?? ''));
            }
            $rows[] = ['line' => $numero, 'data' => $data];
        }

        return ['header' => $header, 'rows' => $rows, 'errors' => $erreurs];
    }

    /**
     * Le séparateur est deviné sur l'en-tête, pas déclaré.
     *
     * Demander à l'exploitant quel séparateur son tableur a écrit, c'est lui demander une chose
     * qu'il ne sait pas et qu'on peut lire — et une mauvaise réponse produirait un fichier « à une
     * seule colonne », erreur illisible.
     */
    private function deviner(string $entete): string
    {
        $meilleur = self::SEPARATEURS[0];
        $max = 0;
        foreach (self::SEPARATEURS as $candidat) {
            $n = substr_count($entete, $candidat);
            if ($n > $max) {
                $max = $n;
                $meilleur = $candidat;
            }
        }

        return $meilleur;
    }
}
