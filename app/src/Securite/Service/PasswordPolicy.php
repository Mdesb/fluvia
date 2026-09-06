<?php

declare(strict_types=1);

namespace App\Securite\Service;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * LA RÈGLE DU MOT DE PASSE, ÉCRITE UNE FOIS — audit du 06/09, constat 7.
 *
 * Deux contrôleurs (activation, réinitialisation) portaient chacun leur `TAILLE_MIN_MOT_DE_PASSE = 12`.
 * Deux autres entrées n'en portaient aucune : la création de compte boutique acceptait « aaa » (vérifié
 * en préproduction, HTTP 201), et un compte exploitant créé par un administrateur avec `motDePasseClair`
 * acceptait « a ». Quatre portes, deux règles, dont une absente — la règle vit désormais ici, et les
 * quatre l'appellent.
 *
 * ⚠ ELLE NE S'APPLIQUE QU'AU MOMENT OÙ LE MOT DE PASSE EST POSÉ. Les comptes existants gardent le leur —
 * un hachage ne se relit pas — et se connectent comme avant. La préproduction porte des « aaa »
 * volontaires (consigne de Maxime) : ils continuent d'ouvrir ; seuls les NOUVEAUX mots de passe passent
 * par ici.
 *
 * Douze caractères, et pas l'adresse e-mail. Pas de classes de caractères imposées : la longueur pèse
 * plus que la ponctuation, et une règle qu'on n'arrive pas à satisfaire finit sur un post-it.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** Le motif du refus, ou `null` si le mot de passe est acceptable. */
    public function violation(string $plain, ?string $email = null): ?string
    {
        if (mb_strlen($plain) < self::MIN_LENGTH) {
            return sprintf('Mot de passe trop court : %d caractères au minimum.', self::MIN_LENGTH);
        }

        if ($email !== null && strcasecmp(trim($plain), trim($email)) === 0) {
            return 'Le mot de passe ne peut pas être l’adresse e-mail du compte.';
        }

        return null;
    }

    /**
     * @throws UnprocessableEntityHttpException avec le motif, pour les entrées qui lèvent plutôt qu'elles ne répondent
     */
    public function assertAcceptable(string $plain, ?string $email = null): void
    {
        $violation = $this->violation($plain, $email);
        if ($violation !== null) {
            throw new UnprocessableEntityHttpException($violation);
        }
    }
}
