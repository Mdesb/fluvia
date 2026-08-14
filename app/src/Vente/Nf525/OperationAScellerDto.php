<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Enum\TypeOperationScellee;
use Symfony\Component\Uid\Uuid;

/**
 * Données d'entrée du scellement d'une opération (vente/avoir/clôture). Le payload est figé et
 * canonicalisé par le signataire pour produire une empreinte reproductible et vérifiable.
 */
final class OperationAScellerDto
{
    /**
     * @param array<string, mixed> $payload données métier figées (montants, moyens, référence cible…)
     */
    public function __construct(
        public readonly PointDeVente $pointDeVente,
        public readonly TypeOperationScellee $typeOperation,
        public readonly string $cibleType,
        public readonly Uuid $cibleId,
        public readonly array $payload,
    ) {
    }
}
