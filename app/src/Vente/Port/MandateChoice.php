<?php

declare(strict_types=1);

namespace App\Vente\Port;

/**
 * Choix de mandat SEPA fait au comptoir a la vente d'un abonnement. VO immuable, defini cote
 * App\Vente pour que le module Vente ne depende d'aucune classe App\Sepa. Trois modes :
 *  - existing : reutiliser un mandat existant du client ($mandateId optionnel ; a defaut, le mandat
 *               actif du client sur l'etablissement vendeur).
 *  - counter  : signer un nouveau mandat au comptoir a partir de l'IBAN saisi ($iban + $titulaire).
 *  - pending  : creer un mandat en attente (IBAN capture plus tard via /sepa/mandats/{id}/complete).
 *
 * Le mode par defaut est `counter` (saisie au comptoir), le cas le plus courant.
 */
final class MandateChoice
{
    public const MODE_EXISTING = 'existing';
    public const MODE_COUNTER = 'counter';
    public const MODE_PENDING = 'pending';

    public function __construct(
        public readonly string $mode,
        public readonly ?string $mandateId = null,
        public readonly ?string $iban = null,
        public readonly ?string $titulaire = null,
        public readonly ?string $bic = null,
    ) {
    }

    /**
     * Construit le choix depuis la cle `abonnement` du corps de POST /ventes/{id}/valider.
     * Tolerant : un corps absent ou invalide retombe sur le mode `counter`.
     */
    public static function fromBody(mixed $data): self
    {
        $data = \is_array($data) ? $data : [];
        $mode = \in_array($data['mode'] ?? null, [self::MODE_EXISTING, self::MODE_COUNTER, self::MODE_PENDING], true)
            ? (string) $data['mode']
            : self::MODE_COUNTER;
        $texte = static fn (mixed $v): ?string => \is_string($v) && '' !== $v ? $v : null;

        return new self(
            mode: $mode,
            mandateId: $texte($data['mandateId'] ?? null),
            iban: $texte($data['iban'] ?? null),
            titulaire: $texte($data['titulaire'] ?? null),
            bic: $texte($data['bic'] ?? null),
        );
    }
}
