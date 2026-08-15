<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /beneficiaires/{id}/retirer (US-L5-03) : retrait tracé (dateRetrait horodaté), jamais de
 * suppression physique — réversible par un nouvel ajout.
 *
 * @implements ProcessorInterface<Beneficiaire, Beneficiaire>
 */
final class RetirerBeneficiaireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Beneficiaire
    {
        \assert($data instanceof Beneficiaire);

        if ($data->getDateRetrait() === null) {
            $data->setDateRetrait(new \DateTimeImmutable());
            $this->em->flush();
        }

        return $data;
    }
}
