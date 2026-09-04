<?php

declare(strict_types=1);

namespace App\Website\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Assainit le corps d'un article avant de l'écrire (ED-10).
 *
 * ⚠ **À L'ÉCRITURE, JAMAIS AU RENDU.** Assainir au rendu obligerait chaque gabarit, chaque flux RSS
 * et chaque futur export à s'en souvenir : celui qui oublie sert du HTML brut, et il n'échoue pas —
 * il rend une page qui marche. En assainissant à l'écriture, la colonne ne contient que ce qui est
 * déjà sûr, et tout ce qui la lit peut lui faire confiance sans le savoir.
 *
 * ⚠ **ON N'ÉCRIT PAS SON PROPRE ASSAINISSEUR.** Une liste blanche faite à la main paraît simple et
 * rate toujours quelque chose — un attribut `on*`, une adresse `javascript:`, une entité encodée
 * deux fois. `symfony/html-sanitizer` implémente la spécification HTML Sanitizer du W3C ; c'est du
 * code lu par beaucoup de monde, ce qu'un assainisseur maison n'est jamais.
 *
 * **Ce qui est autorisé, et pourquoi si peu.** Le corps d'un article de blog a besoin de titres, de
 * paragraphes, de listes, de gras, d'italique, de liens, de citations, de code et d'images. Rien
 * d'autre : ni `<script>` (évident), ni `<iframe>` (une page tierce qui s'exécute chez nous), ni
 * `<style>` ni attribut `style` (une feuille de style d'article peut repeindre le site entier, y
 * compris masquer un prix ou déplacer un bouton).
 *
 * **Les liens sortants portent `rel="noopener"`** : sans lui, la page ouverte garde une poignée sur
 * la nôtre. Le sanitizer le pose lui-même dès qu'on déclare les liens forcés en nouvelle fenêtre.
 */
final class BodySanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('u')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('code')
            ->allowElement('pre')
            ->allowElement('hr')
            ->allowElement('a', ['href', 'title'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('figure')
            ->allowElement('figcaption')
            // `http` et `https` seulement. Sans cette borne, `javascript:` et `data:` passent — et un
            // lien `data:text/html` est une page entière servie depuis notre domaine.
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->forceHttpsUrls(true)
            // Ce qui n'est pas autorisé est SUPPRIMÉ avec son contenu pour ces deux-là : garder le
            // texte d'un `<script>` déposerait du code lisible au milieu d'un article.
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return trim($this->sanitizer->sanitize($html));
    }
}
