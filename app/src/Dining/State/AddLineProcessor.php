<?php

declare(strict_types=1);

namespace App\Dining\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dining\Domain\CourseRef;
use App\Dining\Entity\DiningOrder;
use App\Dining\Entity\DiningOrderLine;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Saisie d une ligne a table (ACT-4). Corps attendu :
 *   { "courseCode": "plat", "courseRank": 2, "label": "Entrecote", "quantity": 2, "unitAmount": "24.50" }
 *
 * La ligne nait **au brouillon** : elle n existe qu a l ecran du serveur tant qu elle n est pas
 * envoyee en cuisine. C est ce qui permet de la corriger sans consequence.
 *
 * @implements ProcessorInterface<mixed, DiningOrder>
 */
final class AddLineProcessor implements ProcessorInterface
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

        if (!$addition->acceptsLines()) {
            throw new ConflictHttpException('dining.error.order_closed');
        }

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

        $libelle = $corps['label'] ?? null;
        if (!is_string($libelle) || '' === trim($libelle)) {
            throw new UnprocessableEntityHttpException('dining.error.label_required');
        }

        $quantite = $corps['quantity'] ?? 1;
        if (!is_int($quantite) || $quantite < 1) {
            throw new UnprocessableEntityHttpException('dining.error.quantity_invalid');
        }

        $montant = $corps['unitAmount'] ?? null;
        if (!is_string($montant) || 1 !== preg_match('/^\d+(\.\d{1,2})?$/', $montant)) {
            // Refus d un flottant JSON : `24.5` deserialise en float perdrait le centime avant meme
            // d arriver ici. Le montant se transmet en chaine, comme il est stocke.
            throw new UnprocessableEntityHttpException('dining.error.amount_invalid');
        }

        $ligne = new DiningOrderLine($addition, $service, trim($libelle), $quantite, number_format((float) $montant, 2, '.', ''));
        $this->em->persist($ligne);
        $this->em->flush();

        return $addition;
    }
}
