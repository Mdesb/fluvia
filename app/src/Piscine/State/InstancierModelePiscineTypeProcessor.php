<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Piscine\ApiResource\ModelePiscineType;
use App\Piscine\Service\ModelePiscineTypeGenerator;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /piscine/modeles/piscine-type/instancier (US-L6-01, CA-1).
 *
 * @implements ProcessorInterface<mixed, ModelePiscineType>
 */
final class InstancierModelePiscineTypeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ModelePiscineTypeGenerator $generateur,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ModelePiscineType
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $produits = $this->generateur->instancier($etablissement);

        $vue = new ModelePiscineType();
        $vue->produitsCrees = array_map(static fn ($produit) => '/api/produits/' . $produit->getId(), $produits);

        return $vue;
    }
}
