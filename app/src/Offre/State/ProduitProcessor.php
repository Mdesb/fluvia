<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Offre\Service\GenerateurCodeProduit;
use App\Offre\Service\ResolveurFacettes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Traitement d'écriture d'un Produit (POST/PATCH) : génère un code unique si absent, met à jour
 * la projection de recherche (CA-1), purge les saisies orphelines selon le type (CA-3, RG-M1-02)
 * et rafraîchit la date de modification, puis délègue au persist processor Doctrine standard.
 *
 * @implements ProcessorInterface<Produit, Produit>
 */
final class ProduitProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Produit, Produit> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly ResolveurFacettes $facettes,
        private readonly GenerateurCodeProduit $generateurCode,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Produit) {
            if ($data->getCode() === '') {
                $data->setCode($this->generateurCode->generer());
            }
            $data->setLibelleRecherche($this->projeterLibelle($data));
            $this->facettes->purgerOrphelins($data);
            $data->toucherModifieLe();
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function projeterLibelle(Produit $produit): ?string
    {
        $valeurs = array_values($produit->getLibelle());
        if ($valeurs === []) {
            return null;
        }

        return mb_substr(implode(' ', array_map('strval', $valeurs)), 0, 512);
    }
}
