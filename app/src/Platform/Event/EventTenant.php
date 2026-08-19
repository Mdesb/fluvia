<?php

declare(strict_types=1);

namespace App\Platform\Event;

use App\Platform\Event\Exception\InvalidDomainEventException;
use Symfony\Component\Uid\Uuid;

/**
 * Le périmètre de l'événement — l'établissement concerné (RG-PLAT-03, D3, D6).
 *
 * **Point de sécurité du lot.** Cet identifiant se dérive de l'**entité sujet** (l'établissement de la
 * facture, de la réservation…), **jamais** de `App\Securite\Service\ContexteEtablissement` : ce dernier
 * lit l'en-tête HTTP `X-Etablissement`, qui est un sélecteur envoyé par le client. Le prendre comme
 * source du tenant reviendrait à laisser l'appelant choisir le périmètre de l'événement — exactement
 * la classe de faille corrigée le 19/08 sur cinq endpoints.
 *
 * Le type est distinct de `ContexteEtablissement` précisément pour que la confusion ne compile pas.
 */
final class EventTenant
{
    public readonly Uuid $establishmentId;

    public function __construct(Uuid $establishmentId)
    {
        // L'UUID nil est la forme sournoise du « vide » : bien typé, syntaxiquement valide, et
        // pourtant il ne désigne aucun établissement. Échec fermé (D3) plutôt que fuite de périmètre.
        if ('00000000-0000-0000-0000-000000000000' === $establishmentId->toRfc4122()) {
            throw new InvalidDomainEventException(
                'Le tenant d\'un événement ne peut pas être l\'UUID nil : un événement doit porter un '
                .'établissement réel, dérivé de l\'entité sujet (RG-PLAT-03).',
            );
        }

        $this->establishmentId = $establishmentId;
    }

    public function equals(self $other): bool
    {
        return $this->establishmentId->equals($other->establishmentId);
    }
}
