<?php

declare(strict_types=1);

namespace App\I18n;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Une erreur d'API que le frontal sait traduire : un CODE stable et ses PARAMÈTRES, à côté du message
 * français.
 *
 * Le serveur ne traduit pas. Il envoie `code` + `params` ; le frontal cherche `error.<code>` dans le
 * catalogue de la langue qu'il affiche et rend `detail` (le français, langue source) si la clé lui
 * est inconnue. Un seul jeu de catalogues, au seul endroit qui affiche : pourquoi, voir
 * `COORDINATION/CONTRACT/i18n-traduction.md`.
 *
 * Deux usages : un processeur la LÈVE (`CodedHttpExceptionListener` la met en forme), un contrôleur
 * qui rend déjà une `JsonResponse` appelle `toResponse()`. Le corps est le même. Pour un refus (4xx) :
 * levée en 5xx, elle suit le chemin ordinaire des pannes.
 */
final class CodedHttpException extends HttpException
{
    /**
     * @param string                          $errorCode  ex. `auth.mfa_invalid_code` ; la clé frontale est `error.<code>`
     * @param array<string, string|int|float> $parameters remplacent les `{nom}` du texte traduit
     */
    public function __construct(
        int $statusCode,
        public readonly string $errorCode,
        string $message,
        public readonly array $parameters = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $message, $previous);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'status' => $this->getStatusCode(),
            'detail' => $this->getMessage(),
            // `message` en plus de `detail` : c'est le champ que le frontal et les contrôleurs
            // existants lisent en premier. Le retirer changerait ce qu'affiche un vieil écran.
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'params' => (object) $this->parameters,
        ], $this->getStatusCode(), ['Content-Type' => 'application/problem+json']);
    }
}
