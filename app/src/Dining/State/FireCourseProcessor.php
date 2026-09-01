<?php

declare(strict_types=1);

namespace App\Dining\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dining\Domain\CourseRef;
use App\Dining\Domain\KitchenDispatch;
use App\Dining\Entity\DiningOrder;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Envoi d un service en cuisine (ACT-4). Corps attendu : { "courseCode": "plat", "courseRank": 2 }
 *
 * **Le seul geste irreversible du module**, et il exige son propre droit (`dining.fire`). Les refus
 * du domaine — service anterieur encore au brouillon, rien a envoyer, ligne deja partie — remontent
 * en 409 : ce ne sont pas des erreurs de saisie, ce sont des conflits avec l etat de la table.
 *
 * @implements ProcessorInterface<mixed, DiningOrder>
 */
final class FireCourseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly DiningOrderFromRequest $additions,
        private readonly KitchenDispatch $cuisine,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DiningOrder
    {
        $corps = $this->lecteur->corps();
        $addition = $this->additions->resolve($uriVariables);

        $code = $corps['courseCode'] ?? null;
        $rang = $corps['courseRank'] ?? null;
        if (!is_string($code) || !is_int($rang)) {
            throw new UnprocessableEntityHttpException('dining.error.course_required');
        }

        try {
            $service = CourseRef::of($code, $rang);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }

        try {
            $this->cuisine->fire($service, $addition->getLines()->toArray(), new \DateTimeImmutable('now'));
        } catch (\LogicException $e) {
            throw new ConflictHttpException($e->getMessage());
        }

        $this->em->flush();

        return $addition;
    }
}
