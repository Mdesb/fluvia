<?php

declare(strict_types=1);

namespace App\PublicApi\Read;

use App\Acces\Entity\Passage;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\PublicApi\Security\PartnerUser;
use App\PublicApi\Service\SupportReferenceSigner;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /v1/access-events` — les passages enregistrés des établissements consentants.
 *
 * ⚠ **DONNÉE PERSONNELLE PSEUDONYME** (traces de lieu horodatées) : la fenêtre servie est bornée à
 * 90 jours sur l'instant du passage, quelle que soit la requête. Aucun nom, aucun code de support : la
 * référence opaque propre à l'application, la zone, le point d'accès, le résultat et son motif codé.
 *
 * `updatedSince` porte sur l'instant d'ENREGISTREMENT, pas sur celui du passage : un passage remonté
 * hors ligne une heure plus tard doit apparaître à la synchronisation suivante. L'identifiant d'un
 * passage est un UUID v7 posé à l'enregistrement : son ordre est celui de l'enregistrement, et sert à la
 * fois au curseur et à ce filtre.
 */
final class AccessEventProvider
{
    public const WINDOW_DAYS = 90;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupportReferenceSigner $signer,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, nextCursor: ?string} */
    public function page(PartnerReadQuery $query, PartnerUser $partner): array
    {
        if ([] === $query->establishments) {
            return ['data' => [], 'nextCursor' => null];
        }

        $qb = $this->em->createQueryBuilder()->select('p')->from(Passage::class, 'p')
            ->andWhere('IDENTITY(p.etablissement) IN (:establishments)')
            ->setParameter('establishments', array_map(static fn ($id) => $id->toBinary(), $query->establishments), ArrayParameterType::BINARY)
            ->andWhere('p.horodatage >= :windowStart')
            ->setParameter('windowStart', new \DateTimeImmutable(sprintf('-%d days', self::WINDOW_DAYS)), 'datetime_immutable')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($query->limit + 1);
        if (null !== $query->cursor) {
            $qb->andWhere('p.id > :cursor')->setParameter('cursor', $query->cursor->toBinary(), ParameterType::BINARY);
        }
        if (null !== $query->updatedSince) {
            $qb->andWhere('p.id >= :since')->setParameter('since', self::lowestV7($query->updatedSince)->toBinary(), ParameterType::BINARY);
        }

        /** @var list<Passage> $passages */
        $passages = $qb->getQuery()->getResult();
        $next = \count($passages) > $query->limit;
        if ($next) {
            array_pop($passages);
        }

        $application = $partner->application->getId();
        $data = array_map(fn (Passage $p): array => [
            'id' => (string) $p->getId(),
            'establishment' => (string) $p->getEtablissement()?->getId(),
            'occurredAt' => $p->getHorodatage()->format(\DATE_ATOM),
            'supportReference' => null === $p->getSupport() ? null : $this->signer->reference($application, $p->getSupport()->getId()),
            'zone' => null === $p->getEspace() ? null : ['id' => (string) $p->getEspace()->getId(), 'name' => $p->getEspace()->getLibelle()],
            'accessPoint' => null === $p->getEquipement() ? null : (string) $p->getEquipement()->getId(),
            'direction' => SensPassage::Sortie === $p->getSens() ? 'out' : 'in',
            'result' => match ($p->getResultat()) {
                ResultatPassage::Valide => 'granted',
                ResultatPassage::Refuse => 'denied',
                ResultatPassage::Compte => 'counted',
            },
            'reason' => $p->getCodeMotif()?->value,
        ], $passages);

        return ['data' => $data, 'nextCursor' => $next ? PartnerReadQuery::encodeCursor(end($passages)->getId()) : null];
    }

    /** Le plus petit UUID v7 de cette milliseconde : tout passage enregistré depuis lui est plus grand. */
    private static function lowestV7(\DateTimeImmutable $at): Uuid
    {
        $hex = str_pad(dechex((int) $at->format('Uv')), 12, '0', \STR_PAD_LEFT);

        return Uuid::fromString(substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-7000-8000-000000000000');
    }
}
