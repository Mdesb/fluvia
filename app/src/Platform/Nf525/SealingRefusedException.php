<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Scellement refusé parce que le dernier maillon de la chaîne ne se vérifie plus avec le trousseau
 * (#361). 409 : l'état de la chaîne interdit l'opération, la requête elle-même n'est pas fautive.
 *
 * Le MESSAGE (`getMessage()`, ce que l'API rend au caissier et que la quarantaine hors ligne recopie) est
 * une phrase FIXE, sans détail technique (G-8). Le détail (identifiants de clé nettoyés, nom de variable,
 * procédure) n'est lisible que par `technicalDetail()` : journal et alerte NF525, jamais l'API.
 */
final class SealingRefusedException extends ConflictHttpException
{
    private const GENERIC_MESSAGE = 'Scellement refusé : la chaîne fiscale est bloquée. L\'administrateur et l\'éditeur sont prévenus.';

    /** Format de la référence d'alerte : `NF525-` + 8 hexadécimaux majuscules (jamais une clé, un UUID, une variable). */
    private const REFERENCE_FORMAT = '/^NF525-[0-9A-F]{8}$/D';

    public function __construct(
        private readonly SealRefusalCause $cause,
        private readonly string $technicalDetail,
        private readonly ?string $reference = null,
    ) {
        if ($reference !== null && preg_match(self::REFERENCE_FORMAT, $reference) !== 1) {
            throw new \InvalidArgumentException('Référence d\'alerte NF525 invalide.');
        }
        parent::__construct(
            $reference === null
                ? self::GENERIC_MESSAGE
                : sprintf('%s Référence de l\'alerte : %s.', self::GENERIC_MESSAGE, $reference),
        );
    }

    public function cause(): SealRefusalCause
    {
        return $this->cause;
    }

    /** Détail réservé au journal et à l'alerte (éditeur) : ne jamais le rendre par l'API. */
    public function technicalDetail(): string
    {
        return $this->technicalDetail;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    /** Copie portant la référence de l'alerte ; l'original n'est pas modifié. */
    public function withReference(string $reference): self
    {
        return new self($this->cause, $this->technicalDetail, $reference);
    }
}
