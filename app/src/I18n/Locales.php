<?php

declare(strict_types=1);

namespace App\I18n;

use App\Organisation\Entity\Etablissement;

/**
 * Les langues que Fluvia parle, et la règle qui donne sa langue à un document.
 *
 * Le français est la SOURCE : tout le reste se traduit depuis lui, et tout ce qui manque retombe sur
 * lui. Le catalan, le basque et le galicien (`ca`, `eu`, `gl`) entrent dans cette liste EN MÊME
 * TEMPS que leur catalogue frontal (`frontend/src/i18n/<langue>.json`), pas avant : sinon un
 * établissement pourrait choisir une langue qu'aucun écran ne parle. Le garde-fou
 * `verifier-chaines-traduites.mjs` refuse les deux listes si elles divergent.
 */
final class Locales
{
    public const SOURCE = 'fr';

    /** @var list<string> */
    public const SUPPORTED = ['fr', 'es'];

    /**
     * La première langue parlée par Fluvia dans une liste `Accept-Language` déjà triée par
     * préférence (`Request::getLanguages()`), ou null si aucune.
     *
     * ⚠ PAS `Request::getPreferredLanguage()` : faute de correspondance, il rend la première langue
     * PROPOSÉE — `fr` — et masquerait ainsi le repli sur la langue de l'établissement.
     *
     * @param list<string> $languages
     */
    public static function fromAcceptLanguage(array $languages): ?string
    {
        foreach ($languages as $language) {
            $primary = strtolower(preg_split('/[_-]/', $language)[0]);
            if (in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }

        return null;
    }

    /**
     * Un document (billet, facture, courriel) parle la langue de l'établissement qui l'émet, pas
     * celle de l'écran de qui le déclenche : une facture espagnole reste espagnole quand un agent
     * francophone la télécharge. Le jour où le client final aura sa langue, c'est ici qu'elle entrera.
     */
    public static function ofEstablishment(?Etablissement $establishment): string
    {
        return $establishment?->getLocale() ?? self::SOURCE;
    }
}
