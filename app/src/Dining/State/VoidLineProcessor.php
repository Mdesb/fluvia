<?php

declare(strict_types=1);

namespace App\Dining\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dining\Entity\DiningOrder;
use App\Dining\Entity\DiningOrderLine;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Annulation d une ligne deja envoyee (ACT-4). Corps attendu : { "line": uuid, "reason": "..." }
 *
 * **Exige son propre droit (`dining.void`)**, distinct de la saisie ordinaire : annuler un plat deja
 * sorti de cuisine est une **perte**, et une perte se valide. Donner ce geste a tout serveur reviendrait
 * a ne jamais valider les pertes du service.
 *
 * **La ligne est cherchee dans l addition resolue, jamais par son id seul.** Une resolution directe
 * depuis l entree client devrait etre confrontee au perimetre (D8, garde-fou C19) ; partir de
 * l addition, elle-meme deja verifiee, rend cette confrontation structurelle plutot que repetee.
 *
 * @implements ProcessorInterface<mixed, DiningOrder>
 */
final class VoidLineProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly DiningOrderFromRequest $additions,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DiningOrder
    {
        $corps = $this->lecteur->corps();
        $addition = $this->additions->resolve($uriVariables);

        $idLigne = $corps['line'] ?? null;
        if (!is_string($idLigne) || '' === $idLigne) {
            throw new UnprocessableEntityHttpException('dining.error.line_required');
        }

        $motif = $corps['reason'] ?? null;
        if (!is_string($motif) || '' === trim($motif)) {
            throw new UnprocessableEntityHttpException('dining.error.void_reason_required');
        }

        $cible = null;
        foreach ($addition->getLines() as $ligne) {
            \assert($ligne instanceof DiningOrderLine);
            if ((string) $ligne->getId() === $idLigne) {
                $cible = $ligne;
                break;
            }
        }

        if (!$cible instanceof DiningOrderLine) {
            // 404 et non 422 : une ligne d une autre addition ne doit pas se distinguer d une ligne
            // inexistante.
            throw new NotFoundHttpException('dining.error.line_not_found');
        }

        try {
            $cible->void($motif);
        } catch (\LogicException $e) {
            throw new ConflictHttpException($e->getMessage());
        }

        $this->em->flush();

        return $addition;
    }
}
