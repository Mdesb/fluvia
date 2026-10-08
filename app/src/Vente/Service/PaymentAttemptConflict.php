<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\Vente;
use App\Vente\Enum\PaymentAttemptStatus;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Une autre tentative tient la vente : rien n'a été encaissé par cette demande (G-3, G-6).
 *
 * Rendue en 409 avec un code lisible par une machine — le message d'une exception ne traverse pas
 * toujours la production, et l'écran doit distinguer les cas :
 * - `payment_in_progress` : l'effet est en cours ; rejouer plus tard, avec la même clé ;
 * - `payment_outcome_unknown` : le terminal a pu débiter ; rien ne repart avant une déclaration (Q-A1) ;
 * - `payment_outcome_known` : la tentative à déclarer a trouvé son issue sans déclaration (réponse
 *   tardive du terminal, autre poste) ; relire la vente.
 *
 * La réponse nomme la tentative quand elle est connue (`tentative`) : l'écran en a besoin pour la
 * déclarer, y compris après un rechargement ou depuis un autre poste.
 *
 * @phpstan-import-type Attempt from PaymentAttemptStore
 */
final class PaymentAttemptConflict extends \RuntimeException
{
    public const IN_PROGRESS = 'payment_in_progress';
    public const OUTCOME_UNKNOWN = 'payment_outcome_unknown';
    public const OUTCOME_KNOWN = 'payment_outcome_known';

    /** @param Attempt|null $attempt */
    private function __construct(public readonly string $reason, string $message, public readonly ?array $attempt)
    {
        parent::__construct($message);
    }

    /** @param Attempt|null $attempt */
    public static function inProgress(?array $attempt = null): self
    {
        return new self(self::IN_PROGRESS, 'Un règlement est déjà en cours sur cette vente ; cette demande n\'a rien encaissé. '
            . 'Attendez son issue, puis relisez le reste dû avant d\'encaisser de nouveau.', $attempt);
    }

    /** @param Attempt|null $attempt */
    public static function outcomeUnknown(?array $attempt = null): self
    {
        return new self(self::OUTCOME_UNKNOWN, 'Le terminal n\'a pas rendu d\'issue pour un règlement de cette vente : la carte a peut-être été débitée. '
            . 'Rien ne s\'encaisse sur cette vente tant que ce qu\'affiche le terminal n\'a pas été déclaré.', $attempt);
    }

    /** @param Attempt $attempt */
    public static function outcomeKnown(array $attempt): self
    {
        return new self(self::OUTCOME_KNOWN, sprintf(
            'Ce règlement a déjà son issue (%s) : il n\'y a rien à déclarer. Relisez la vente avant d\'encaisser de nouveau.',
            $attempt['status']->label(),
        ), $attempt);
    }

    /**
     * Le refus à rendre quand une tentative tient la vente : « issue inconnue » pour un terminal muet, sinon « en cours ».
     *
     * @param Attempt $attempt
     */
    public static function holding(array $attempt): self
    {
        return $attempt['status'] === PaymentAttemptStatus::Unresolved ? self::outcomeUnknown($attempt) : self::inProgress($attempt);
    }

    public function toResponse(Vente $vente): JsonResponse
    {
        return new JsonResponse([
            'code' => $this->reason,
            'message' => $this->getMessage(),
            'vente' => (string) $vente->getId(),
            'reglementEnregistre' => false,
            'tentative' => $this->attempt !== null ? PaymentAttemptStore::summary($this->attempt) : null,
        ], JsonResponse::HTTP_CONFLICT);
    }
}
