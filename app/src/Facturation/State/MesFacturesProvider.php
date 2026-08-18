<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /mes-factures (US-FACT-07, CA-10) : espace client M3 — l'utilisateur connecté voit toutes ses
 * factures (justificatives et directes), jamais celles d'un autre client. Filtre par
 * `DestinataireFacturation::$clientRef = Utilisateur::$clientLie`, même patron que
 * `Facture::estLieA()`.
 *
 * @implements ProviderInterface<list<Facture>>
 */
final class MesFacturesProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** @return list<Facture> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur || $utilisateur->getClientLie() === null) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('f')
            ->from(Facture::class, 'f')
            ->innerJoin(DestinataireFacturation::class, 'd', 'WITH', 'd = f.destinataire')
            ->andWhere('d.clientRef = :clientRef')
            ->setParameter('clientRef', $utilisateur->getClientLie(), 'uuid')
            ->orderBy('f.creeLe', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
