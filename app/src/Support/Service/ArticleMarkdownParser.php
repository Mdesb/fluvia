<?php

declare(strict_types=1);

namespace App\Support\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parse un fichier de doc vivante `docs/aide/<module>/<slug>.md` (§5.1 plan-support.md) : front
 * matter YAML délimité par `---` en tête de fichier + corps Markdown.
 */
final class ArticleMarkdownParser
{
    /** @return array{frontMatter: array<string, mixed>, corps: string} */
    public function parser(string $contenuFichier): array
    {
        $contenu = ltrim(str_replace("\r\n", "\n", $contenuFichier));

        if (!str_starts_with($contenu, '---')) {
            throw new \InvalidArgumentException('Front matter YAML manquant (délimiteur "---" attendu en tête de fichier).');
        }

        $parties = preg_split('/^---\s*$/m', $contenu, 3);
        if ($parties === false || \count($parties) < 3) {
            throw new \InvalidArgumentException('Front matter YAML mal formé (second délimiteur "---" manquant).');
        }

        $frontMatterBrut = $parties[1];
        $corps = ltrim($parties[2] ?? '', "\n");

        try {
            $frontMatter = Yaml::parse($frontMatterBrut);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException('Front matter YAML invalide : ' . $e->getMessage(), 0, $e);
        }

        if (!\is_array($frontMatter)) {
            throw new \InvalidArgumentException('Front matter YAML invalide : doit être un mapping clé/valeur.');
        }

        return ['frontMatter' => $frontMatter, 'corps' => $corps];
    }
}
