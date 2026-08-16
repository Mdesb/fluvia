<?php

declare(strict_types=1);

namespace App\Reporting\Service;

use App\Reporting\Entity\Indicateur;
use App\Reporting\Entity\Mesure;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\ValueObject\Periode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Point de lecture UNIQUE des `Mesure` pré-agrégées (RG-M7-02) : dashboards région/groupe et
 * Explorateur passent tous par ce service pour un même triplet indicateur × niveau × entité ×
 * période — garantit CA-5 (cohérence stricte) par construction plutôt que par convention.
 */
final class MesureLookupService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function indicateurParCode(string $code): ?Indicateur
    {
        return $this->em->getRepository(Indicateur::class)->findOneBy(['code' => $code]);
    }

    public function trouver(string $indicateurCode, NiveauEntite $niveau, Uuid $entiteId, Periode $periode): ?Mesure
    {
        $cle = Mesure::calculerCleAgregation(
            $indicateurCode,
            $niveau->value,
            $entiteId->toRfc4122(),
            null,
            null,
            null,
            null,
            $periode->debut->format('Y-m-d'),
            $periode->fin->format('Y-m-d'),
            $periode->granularite->value,
        );

        return $this->em->getRepository(Mesure::class)->findOneBy(['cleAgregation' => $cle]);
    }

    /**
     * Mesures de niveau établissement descendant d'une région/d'un groupe (§1.1 : `region_id`/
     * `groupe_id` dénormalisés), optionnellement filtrées par régime d'exploitant (cas limite §7
     * spec : filtre par régime dans l'Explorateur pour retrouver la comparabilité stricte).
     *
     * @return list<Mesure>
     */
    public function sourcesEtablissement(string $indicateurCode, NiveauEntite $niveau, Uuid $entiteId, Periode $periode, ?string $regimeExploitant = null): array
    {
        $indicateur = $this->indicateurParCode($indicateurCode);
        if ($indicateur === null) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mesure::class, 'm')
            ->where('m.indicateur = :indicateur')
            ->andWhere('m.niveau = :niveauEtab')
            ->andWhere('m.periodeDebut = :debut')
            ->andWhere('m.periodeFin = :fin')
            ->andWhere('m.granularite = :granularite')
            ->setParameter('indicateur', $indicateur->getId(), 'uuid')
            ->setParameter('niveauEtab', NiveauEntite::Etablissement)
            ->setParameter('debut', $periode->debut->format('Y-m-d'))
            ->setParameter('fin', $periode->fin->format('Y-m-d'))
            ->setParameter('granularite', $periode->granularite);

        if ($niveau === NiveauEntite::Region) {
            $qb->andWhere('m.region = :entite');
        } else {
            $qb->andWhere('m.groupe = :entite');
        }
        $qb->setParameter('entite', $entiteId, 'uuid');

        if ($regimeExploitant !== null) {
            $regimeEnum = \App\Reporting\Enum\RegimeExploitantMesure::tryFrom($regimeExploitant);
            if ($regimeEnum === null) {
                return [];
            }
            $qb->andWhere('m.regimeExploitant = :regime')->setParameter('regime', $regimeEnum);
        }

        /** @var list<Mesure> $resultat */
        $resultat = $qb->getQuery()->getResult();

        return $resultat;
    }

    /** Établissement le plus critique (valeur max) parmi les `Mesure` étab. d'un indicateur donné pour une région/un groupe. */
    public function etablissementCritique(string $indicateurCode, NiveauEntite $niveau, Uuid $entiteId, Periode $periode): ?Mesure
    {
        $mesures = $this->sourcesEtablissement($indicateurCode, $niveau, $entiteId, $periode);
        if ($mesures === []) {
            return null;
        }

        usort($mesures, static fn (Mesure $a, Mesure $b): int => (float) $b->getValeur() <=> (float) $a->getValeur());

        return $mesures[0];
    }
}
