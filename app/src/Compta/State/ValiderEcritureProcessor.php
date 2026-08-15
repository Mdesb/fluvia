<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Enum\StatutEcriture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /compta/ecritures/{id}/valider : transition provisoire/contrôlée → validée (cycle de vie
 * §4.10 spec, permission `compta.valider`).
 *
 * @implements ProcessorInterface<EcritureComptable, EcritureComptable>
 */
final class ValiderEcritureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EcritureComptable
    {
        \assert($data instanceof EcritureComptable);

        if ($data->getStatut() === StatutEcriture::Exportee) {
            throw new ConflictHttpException('Écriture déjà exportée : validation impossible.');
        }
        if (!$data->estEquilibree()) {
            throw new ConflictHttpException('Écriture déséquilibrée : validation impossible.');
        }

        $data->setStatut(StatutEcriture::Validee);
        $this->em->flush();

        return $data;
    }
}
