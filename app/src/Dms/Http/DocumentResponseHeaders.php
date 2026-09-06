<?php

declare(strict_types=1);

namespace App\Dms\Http;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les en-têtes d'un document servi — décidés à UN endroit, pour les deux contrôleurs.
 *
 * ⚠ LE LIEN PUBLIC SERVAIT N'IMPORTE QUEL TYPE EN `inline`, SUR L'ORIGINE DE L'APPLICATION, AVEC LE
 * MIME DÉCLARÉ PAR CELUI QUI AVAIT TÉLÉVERSÉ. Un `text/html` partagé s'ouvrait donc comme une page du
 * site : son JavaScript tournait sur notre origine et lisait le JWT posé en `localStorage`. Audit du
 * 06/09, constat 6. Trois mesures ferment le trou, et aucune ne suffit seule :
 *
 *  - `inline` seulement pour ce qu'un navigateur AFFICHE sans rien exécuter (PDF, images). Tout le
 *    reste part en pièce jointe — un type inconnu compris, et le SVG compris : il porte du script.
 *  - `X-Content-Type-Options: nosniff` sur toute réponse. Sans lui, un fichier déclaré PDF mais rempli
 *    de HTML est « deviné » HTML par le navigateur, et la liste blanche ne protège plus de rien. Les
 *    versions téléversées avant le 06/09 portent encore le type déclaré par le client (le serveur ne
 *    le devinait pas) : c'est cet en-tête qui les rend inoffensives sans les retoucher.
 *  - une CSP `sandbox` sur les pièces jointes : si un navigateur les rendait malgré la disposition,
 *    elles tourneraient sans origine, donc sans accès au stockage de l'application. Pas de CSP sur ce
 *    qui s'affiche en ligne — un PDF ou une image n'exécute rien, et une CSP posée sur un PDF a déjà
 *    empêché des lecteurs intégrés de l'ouvrir.
 *
 * Le nom de fichier passe par `HeaderUtils::makeDisposition()`, qui encode les caractères hors ASCII
 * (RFC 6266) au lieu de les hacher à la main.
 */
final class DocumentResponseHeaders
{
    /**
     * Ce qu'un navigateur peut afficher sans exécuter quoi que ce soit. Ni SVG (il porte du script),
     * ni HTML, ni XML : ceux-là se téléchargent.
     */
    private const INLINE_SAFE_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public static function apply(Response $response, string $mimeType, string $originalFilename, bool $inlineAllowed): void
    {
        $type = strtolower(trim(explode(';', $mimeType, 2)[0]));
        if ($type === '' || preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $type) !== 1) {
            $type = 'application/octet-stream';
        }
        $inline = $inlineAllowed && \in_array($type, self::INLINE_SAFE_TYPES, true);

        $name = trim(str_replace(['"', "\r", "\n", '/', '\\', '%'], '', $originalFilename));
        if ($name === '') {
            $name = 'document';
        }
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $name);
        if (!\is_string($fallback) || trim($fallback) === '') {
            $fallback = 'document';
        }

        $response->headers->set('Content-Type', $type);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            $fallback,
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (!$inline) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        }
    }

    /** @return list<string> */
    public static function inlineSafeTypes(): array
    {
        return self::INLINE_SAFE_TYPES;
    }
}
