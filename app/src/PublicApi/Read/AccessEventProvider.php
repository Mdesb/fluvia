<?php

declare(strict_types=1);

namespace App\PublicApi\Read;

use App\Acces\Entity\Passage;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\Enum\TypeDroitAcces;
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
 * `updatedSince` porte sur la CRÉATION du passage côté serveur, pas sur l'instant du passage : un passage
 * remonté hors ligne une heure plus tard apparaît à la synchronisation suivante. L'identifiant est un
 * UUID v7 posé à la CONSTRUCTION de l'objet, pas au commit : un passage construit juste avant `since` et
 * commité juste après aurait un identifiant plus petit que la borne. D'où un RECOUVREMENT de 5 minutes :
 * on sert à partir de `since − 5 min`, et le partenaire déduplique par `id` (documenté). Le curseur, lui,
 * reste exact à l'intérieur d'un même parcours.
 *
 * ⚠ Les passages du PERSONNEL ne sont pas servis (spec §3.2), et une zone d'un autre établissement que
 * celui du passage n'est jamais nommée.
 */
final class AccessEventProvider
{
    public const WINDOW_DAYS = 90;

    public const SINCE_OVERLAP_MINUTES = 5;

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

        $windowStart = new \DateTimeImmutable(sprintf('-%d days', self::WINDOW_DAYS));
        $qb = $this->em->createQueryBuilder()->select('p')->from(Passage::class, 'p')
            ->andWhere('IDENTITY(p.etablissement) IN (:establishments)')
            ->setParameter('establishments', array_map(static fn ($id) => $id->toBinary(), $query->establishments), ArrayParameterType::BINARY)
            ->andWhere('p.horodatage >= :windowStart')
            ->setParameter('windowStart', $windowStart, 'datetime_immutable')
            // ⚠ PLANCHER D'IDENTIFIANT, ET C'EST LUI QUI REND LA REQUÊTE BORNÉE. Un passage est toujours
            // créé après son instant (une remontée hors ligne arrive plus tard, jamais plus tôt) : son
            // UUID v7 est donc ≥ celui du début de la fenêtre. Sans ce plancher, l'index
            // (etablissement_id, id implicite) est lu depuis le premier passage de l'établissement et
            // l'horodatage filtré après coup — mesuré le 04/10 : 20 101 lignes lues pour en servir 101 sur
            // un historique de 400 jours ; 101 avec le plancher.
            ->andWhere('p.id >= :windowFloor')
            ->setParameter('windowFloor', self::lowestV7($windowStart)->toBinary(), ParameterType::BINARY)
            ->leftJoin('p.droit', 'pd')
            ->andWhere('pd.id IS NULL OR pd.sourceType <> :staff')
            ->setParameter('staff', TypeDroitAcces::Personnel->value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($query->limit + 1);
        if (null !== $query->cursor) {
            $qb->andWhere('p.id > :cursor')->setParameter('cursor', $query->cursor->toBinary(), ParameterType::BINARY);
        }
        if (null !== $query->updatedSince) {
            $qb->andWhere('p.id >= :since')->setParameter('since', self::lowestV7($query->updatedSince->modify(sprintf('-%d minutes', self::SINCE_OVERLAP_MINUTES)))->toBinary(), ParameterType::BINARY);
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
            'zone' => null === $p->getEspace() || (string) $p->getEspace()->getEtablissement()?->getId() !== (string) $p->getEtablissement()?->getId()
                ? null
                : ['id' => (string) $p->getEspace()->getId(), 'name' => $p->getEspace()->getLibelle()],
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
