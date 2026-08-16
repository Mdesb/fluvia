<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Patinoire\Entity\Affutage;
use App\Patinoire\Enum\StatutAffutage;
use App\Patinoire\Enum\TypeAffutage;
use App\Patinoire\Service\PromotionListeAttenteHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Termine un affûtage (POST /patinoire/affutages/{id}/terminer, US-PATIN-07, RG-PAT-06, CA-7). Pour
 * `maintenance_parc` : l'article repasse « bon » et redevient disponible
 * (`quantiteEnAffutage--`), sans ligne de vente associée (opération interne), et promeut la liste
 * d'attente pointure (§4.5) si une unité redevient disponible.
 *
 * @implements ProcessorInterface<Affutage, Affutage>
 */
final class TerminerAffutageProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PromotionListeAttenteHandler $promotion,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Affutage
    {
        \assert($data instanceof Affutage);

        if ($data->getStatut() === StatutAffutage::Termine) {
            throw new ConflictHttpException('Cet affûtage est déjà terminé.');
        }

        $data->setStatut(StatutAffutage::Termine)->setDateSortieAtelier(new \DateTimeImmutable());

        $parcPatins = $data->getParcPatins();
        if ($data->getType() === TypeAffutage::MaintenanceParc && $parcPatins !== null) {
            $parcPatins->incrementerEnAffutage(-1);
        }

        $this->em->flush();

        if ($data->getType() === TypeAffutage::MaintenanceParc && $parcPatins !== null) {
            $this->promotion->promouvoir($parcPatins);
        }

        return $data;
    }
}
