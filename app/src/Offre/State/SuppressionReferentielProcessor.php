<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TrancheQuotientFamilial;
use App\Offre\Entity\TypeTarif;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Garde de suppression des référentiels (CA-9 / US-L1-07) : un type de tarif ou une saison
 * utilisé par une grille (donc par un produit) ne peut être SUPPRIMÉ ; la suppression est
 * refusée (409) au profit de la désactivation (actif=false). Sinon, délègue au remove processor.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class SuppressionReferentielProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof TypeTarif && $this->typeTarifUtilise($data)) {
            throw new ConflictHttpException(
                'Ce type de tarif est utilisé par une grille : suppression interdite, désactivez-le (actif=false).'
            );
        }
        if ($data instanceof Saison && $this->saisonUtilisee($data)) {
            throw new ConflictHttpException(
                'Cette saison est utilisée par une grille : suppression interdite, désactivez-la (actif=false).'
            );
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function typeTarifUtilise(TypeTarif $typeTarif): bool
    {
        $parGrille = $this->em->getRepository(GrilleTarifaire::class)->count(['typeTarif' => $typeTarif]);
        $parTranche = $this->em->getRepository(TrancheQuotientFamilial::class)->count(['typeTarif' => $typeTarif]);

        return $parGrille > 0 || $parTranche > 0;
    }

    private function saisonUtilisee(Saison $saison): bool
    {
        return $this->em->getRepository(GrilleTarifaire::class)->count(['saison' => $saison]) > 0;
    }
}
