<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Vente\ApiResource\Synchronisation;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fournit l'indicateur d'état de synchronisation (US-L2-12) : en ligne / dégradé / synchro en cours.
 * Côté serveur l'état nominal est « en_ligne » ; le compteur des ventes d'origine hors-ligne aide au
 * suivi de la remontée. La bascule réseau et le stockage local relèvent du poste (hors périmètre API).
 *
 * @implements ProviderInterface<Synchronisation>
 */
final class EtatSynchroProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Synchronisation
    {
        $etat = new Synchronisation();
        $etat->etat = 'en_ligne';
        $etat->ventesHorsLigne = (int) $this->em->getRepository(Vente::class)
            ->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.origineHorsLigne = true')
            ->getQuery()
            ->getSingleScalarResult();

        return $etat;
    }
}
