<?php

declare(strict_types=1);

namespace App\Platform\Event;

use App\Platform\Event\Exception\InvalidDomainEventException;

/**
 * L'entité concernée par le fait : `{ type: "Payment", id: "…" }` (enveloppe du catalogue).
 *
 * `type` est un **nom court anglais** en `PascalCase` (D5), pas un FQCN : l'abonné ne doit pas pouvoir
 * déduire la classe PHP de l'émetteur, sinon on réintroduit par la bande le couplage que D2 interdit.
 * `id` reste une chaîne — tous les sujets ne sont pas identifiés par un UUID (numéro de pièce, code
 * externe d'un système tiers), et le bus n'a pas à en juger.
 */
final class EventSubject
{
    private const TYPE_PATTERN = '/^[A-Z][A-Za-z0-9]*$/';

    public readonly string $type;
    public readonly string $id;

    public function __construct(string $type, string $id)
    {
        if (1 !== preg_match(self::TYPE_PATTERN, $type)) {
            throw new InvalidDomainEventException(sprintf(
                'Type de sujet invalide : "%s". Attendu un nom court anglais en PascalCase (D5) — '
                .'par exemple « SupplierInvoice », et non un nom de classe complet.',
                $type,
            ));
        }

        if ('' === trim($id)) {
            throw new InvalidDomainEventException(
                'L\'identifiant du sujet est obligatoire : un événement porte des références, un '
                .'sujet sans id ne réfère à rien (RG-PLAT-01).',
            );
        }

        $this->type = $type;
        $this->id = $id;
    }
}
