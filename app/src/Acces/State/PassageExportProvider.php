<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\Entity\Passage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Export filtré du journal des passages (US-L3-11, écran A-05, CA-12). Filtrable par période
 * (`depuis`/`jusqu'a`), espace, équipement, type (`resultat`) via les paramètres de requête ; lecture
 * seule (aucune mutation), même source que le journal standard.
 *
 * @implements ProviderInterface<list<Passage>>
 */
final class PassageExportProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $qb = $this->em->getRepository(Passage::class)->createQueryBuilder('p')->orderBy('p.horodatage', 'ASC');

        if ($request !== null) {
            if (($depuis = $request->query->get('depuis')) !== null) {
                $qb->andWhere('p.horodatage >= :depuis')->setParameter('depuis', new \DateTimeImmutable((string) $depuis));
            }
            if (($jusqua = $request->query->get('jusqua')) !== null) {
                $qb->andWhere('p.horodatage <= :jusqua')->setParameter('jusqua', new \DateTimeImmutable((string) $jusqua));
            }
            if (($espace = $request->query->get('espace')) !== null && Uuid::isValid((string) $espace)) {
                $qb->andWhere('IDENTITY(p.espace) = :espace')->setParameter('espace', $espace, 'uuid');
            }
            if (($equipement = $request->query->get('equipement')) !== null && Uuid::isValid((string) $equipement)) {
                $qb->andWhere('IDENTITY(p.equipement) = :equipement')->setParameter('equipement', $equipement, 'uuid');
            }
            if (($resultat = $request->query->get('resultat')) !== null) {
                $qb->andWhere('p.resultat = :resultat')->setParameter('resultat', (string) $resultat);
            }
        }

        return $qb->getQuery()->getResult();
    }
}
