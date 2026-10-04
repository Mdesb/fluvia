<?php

declare(strict_types=1);

namespace App\PublicApi\Read;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutSupport;
use App\Acces\Enum\TypeDroitAcces;
use App\PublicApi\Security\PartnerUser;
use App\PublicApi\Service\SupportReferenceSigner;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `GET /v1/access-rights` — un élément par SUPPORT des établissements consentants : son droit tel que
 * le terminal l'appliquerait (même lecture que `SnapshotTerminalProvider`), sans rien de nominatif.
 *
 * Statut :
 *  - `revoked`   : support bloqué (perte, vol), ou aucun droit appairé ;
 *  - `suspended` : droit dévalidé (impayé, réversible) ;
 *  - `expired`   : fin de validité dépassée ;
 *  - `active`    : sinon.
 *
 * Zones (D87, `DroitAcces::ouvre()`) : les zones déclarées ; aucune zone = AUCUNE porte, sauf pour les
 * types exemptés (personnel, réservation), qui ouvrent tout — `allZones: true`.
 *
 * ⚠ **LE DROIT ET SES ZONES SONT BORNÉS À L'ÉTABLISSEMENT DU SUPPORT.** `AppairageHandler` retrouve un
 * support par identifiant sur toute la base : un support de A peut être réappairé à un droit de B. Le
 * partenaire de A ne doit voir ni ce droit ni les zones de B — le support y est servi `revoked`.
 *
 * ⚠ **LE PERSONNEL N'EST PAS SERVI** (choix prudent, réversible, spec §3.2) : un support appairé à un
 * droit `Personnel` n'apparaît pas — un tiers ne trace pas les horaires des employés sans décision
 * explicite de l'établissement.
 *
 * ⚠ `updatedSince` N'EST PAS SERVI ICI (400) : aucune date de modification fiable n'existe sur les droits
 * (voir le rapport de la PR) ; un filtre qui manquerait des changements serait pire qu'un refus.
 */
final class AccessRightProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupportReferenceSigner $signer,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, nextCursor: ?string} */
    public function page(PartnerReadQuery $query, PartnerUser $partner): array
    {
        if (null !== $query->updatedSince) {
            throw new BadRequestHttpException('`updatedSince` n’est pas encore servi sur les droits : parcourez la liste complète.');
        }
        if ([] === $query->establishments) {
            return ['data' => [], 'nextCursor' => null];
        }

        $qb = $this->em->createQueryBuilder()->select('s')->from(Support::class, 's')
            ->andWhere('IDENTITY(s.etablissement) IN (:establishments)')
            ->setParameter('establishments', array_map(static fn ($id) => $id->toBinary(), $query->establishments), ArrayParameterType::BINARY)
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s ps JOIN ps.droit dp WHERE ps.support = s AND ps.actif = true AND dp.sourceType = :staff)',
                Appairage::class,
            ))
            ->setParameter('staff', TypeDroitAcces::Personnel->value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults($query->limit + 1);
        if (null !== $query->cursor) {
            $qb->andWhere('s.id > :cursor')->setParameter('cursor', $query->cursor->toBinary(), ParameterType::BINARY);
        }

        /** @var list<Support> $supports */
        $supports = $qb->getQuery()->getResult();
        $next = \count($supports) > $query->limit;
        if ($next) {
            array_pop($supports);
        }

        $rights = $this->activeRights($supports);
        $now = new \DateTimeImmutable();
        $data = array_map(fn (Support $s): array => $this->view($s, $rights[(string) $s->getId()] ?? null, $partner, $now), $supports);

        return ['data' => $data, 'nextCursor' => $next ? PartnerReadQuery::encodeCursor(end($supports)->getId()) : null];
    }

    /**
     * @param list<Support> $supports
     *
     * @return array<string, DroitAcces> le droit de l'appairage ACTIF, par support
     */
    private function activeRights(array $supports): array
    {
        if ([] === $supports) {
            return [];
        }

        /** @var list<Appairage> $pairings */
        $pairings = $this->em->createQueryBuilder()->select('a', 'd')->from(Appairage::class, 'a')
            ->join('a.droit', 'd')->join('a.support', 'sa')
            ->andWhere('IDENTITY(a.support) IN (:supports)')->andWhere('a.actif = true')
            ->andWhere('IDENTITY(d.etablissement) = IDENTITY(sa.etablissement)')
            ->setParameter('supports', array_map(static fn (Support $s) => $s->getId()->toBinary(), $supports), ArrayParameterType::BINARY)
            ->getQuery()->getResult();

        $rights = [];
        foreach ($pairings as $pairing) {
            $rights[(string) $pairing->getSupport()?->getId()] = $pairing->getDroit();
        }

        return array_filter($rights);
    }

    /** @return array<string, mixed> */
    private function view(Support $support, ?DroitAcces $right, PartnerUser $partner, \DateTimeImmutable $now): array
    {
        $until = $right?->getFenetreFin();
        $status = match (true) {
            StatutSupport::Bloque === $support->getStatut(), null === $right => 'revoked',
            StatutProjectionDroit::Valide !== $right->getStatutProjection() => 'suspended',
            null !== $until && $until < $now => 'expired',
            default => 'active',
        };
        // Les zones d'un AUTRE établissement ne sont jamais servies, même si elles figurent sur le droit.
        $establishment = (string) $support->getEtablissement()?->getId();
        $zones = null === $right ? [] : array_values(array_map(
            static fn (EspaceAcces $z): array => ['id' => (string) $z->getId(), 'name' => $z->getLibelle()],
            array_filter(
                $right->getAuthorisedSpaces()->toArray(),
                static fn (EspaceAcces $z): bool => (string) $z->getEtablissement()?->getId() === $establishment,
            ),
        ));

        return [
            'reference' => $this->signer->reference($partner->application->getId(), $support->getId()),
            'establishment' => (string) $support->getEtablissement()?->getId(),
            'status' => $status,
            'validFrom' => $right?->getFenetreDebut()?->format(\DATE_ATOM),
            'validUntil' => $until?->format(\DATE_ATOM),
            'zones' => $zones,
            'allZones' => null !== $right && [] === $zones && \in_array($right->getSourceType(), DroitAcces::TYPES_EXEMPTES_DE_ZONE, true),
        ];
    }
}
