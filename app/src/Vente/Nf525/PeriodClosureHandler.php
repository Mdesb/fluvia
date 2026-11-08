<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Caisse\Entity\PointDeVente;
use App\Caisse\Enum\TypeCloture;
use App\Securite\Entity\Utilisateur;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\Entity\DailyClosure;
use App\Vente\Nf525\Entity\PeriodClosure;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Clôtures mensuelle et annuelle d'un point de vente (#342), scellées dans la chaîne NF525.
 *
 * BOI-TVA-DECLA-30-10-30 § 170 (25/03/2026) : clôtures journalière, mensuelle et annuelle,
 * « cumulatives et impératives », chacune portant le grand total de la période et le total perpétuel.
 *
 * **Le mois additionne des journées, l'année additionne des mois.** Ni l'un ni l'autre ne relit les
 * ventes pour se calculer : ce qui est scellé au niveau du dessous fait foi. Les ventes ne sont relues
 * que comme témoin (`recomputedTotal`), pour qu'un écart se lise au lieu d'être absorbé.
 *
 * **Quatre refus, chacun pour la même raison que ceux de l'arrêté de journée** : une période à venir se
 * remplirait après sa clôture ; une période close deux fois compterait deux fois ; une période sautée
 * sortirait du cumul d'exercice ; et une période dont le niveau du dessous n'est pas complet scellerait
 * un total qui ne couvre pas toutes ses ventes.
 */
final class PeriodClosureHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementHandler $scellement,
        private readonly DailyClosureHandler $daily,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    public function closeMonth(PointDeVente $pdv, int $year, int $month, ?Utilisateur $author): PeriodClosure
    {
        if ($month < 1 || $month > 12) {
            throw new UnprocessableEntityHttpException('Mois invalide.');
        }
        $debut = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $suivant = $debut->modify('first day of next month');
        $fin = $suivant->modify('-1 day');

        $this->refuserSiAVenir($pdv, $fin, sprintf('Le mois %s n\'est pas terminé sur ce point de vente.', $debut->format('m/Y')));
        $this->refuserSiDejaClose($pdv, TypeCloture::Mensuelle, $debut);

        // Mois sauté : une journée arrêtée avant ce mois et après la dernière clôture mensuelle, c'est un
        // mois qui sortirait du cumul d'exercice.
        $derniereMensuelle = $this->dernierePeriode($pdv, TypeCloture::Mensuelle, $debut);
        $oubliee = $this->premiereJourneeArretee($pdv, $derniereMensuelle?->getPeriodEnd()->modify('+1 day'), $debut);
        if ($oubliee !== null) {
            throw new ConflictHttpException(sprintf(
                'Le mois %s porte des journées arrêtées et n\'est pas clôturé : clôturez-le d\'abord.',
                $oubliee->format('m/Y'),
            ));
        }

        $this->refuserSiJourneeNonArretee($pdv, $suivant);

        // Les journées du mois, et la dernière journée arrêtée avant lui (d'où part le cumul perpétuel).
        $journees = $this->journees($pdv, $debut, $suivant);
        $avant = $this->derniereJournee($pdv, $debut);

        $ventes = 0;
        $centimes = 0;
        foreach ($journees as $journee) {
            $ventes += $journee->getSalesCount();
            $centimes += $this->calculateur->centimes($journee->getDailyTotal());
        }
        $precedent = $avant?->getGrandTotal() ?? '0.00';
        $perpetuel = $journees === [] ? $precedent : $journees[\count($journees) - 1]->getGrandTotal();
        $this->exigerCumulCoherent($precedent, $centimes, $perpetuel, $debut->format('m/Y'));

        // Cumul d'exercice : la dernière clôture mensuelle de la même année, plus ce mois. Exercice =
        // année civile (décision à valider, voir la PR).
        $exercice = $month === 1 ? null : $this->dernierePeriode($pdv, TypeCloture::Mensuelle, $debut, $debut->modify('first day of january this year'));
        $cumulExercice = ($exercice !== null ? $this->calculateur->centimes($exercice->getFiscalYearTotal()) : 0) + $centimes;

        return $this->sceller(
            $pdv,
            TypeCloture::Mensuelle,
            $debut,
            $fin,
            \count($journees),
            $ventes,
            $centimes,
            $cumulExercice,
            $precedent,
            $perpetuel,
            $author,
        );
    }

    public function closeYear(PointDeVente $pdv, int $year, ?Utilisateur $author): PeriodClosure
    {
        $debut = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $suivant = $debut->modify('+1 year');
        $fin = $suivant->modify('-1 day');

        $this->refuserSiAVenir($pdv, $fin, sprintf('L\'année %d n\'est pas terminée sur ce point de vente.', $year));
        $this->refuserSiDejaClose($pdv, TypeCloture::Annuelle, $debut);

        $derniereAnnuelle = $this->dernierePeriode($pdv, TypeCloture::Annuelle, $debut);
        $oubliee = $this->premiereJourneeArretee($pdv, $derniereAnnuelle?->getPeriodEnd()->modify('+1 day'), $debut);
        if ($oubliee !== null) {
            throw new ConflictHttpException(sprintf(
                'L\'année %s porte des journées arrêtées et n\'est pas clôturée : clôturez-la d\'abord.',
                $oubliee->format('Y'),
            ));
        }

        // Toutes les journées arrêtées de l'année doivent être couvertes par une clôture mensuelle :
        // l'année additionne des mois, un mois manquant en sortirait.
        $derniereMensuelle = $this->dernierePeriode($pdv, TypeCloture::Mensuelle, $suivant, $debut);
        $nonCouverte = $this->premiereJourneeArretee($pdv, $derniereMensuelle?->getPeriodEnd()->modify('+1 day') ?? $debut, $suivant);
        if ($nonCouverte !== null) {
            throw new ConflictHttpException(sprintf(
                'Le mois %s porte des journées arrêtées et n\'est pas clôturé : clôturez-le avant l\'année.',
                $nonCouverte->format('m/Y'),
            ));
        }
        $this->refuserSiJourneeNonArretee($pdv, $suivant);

        $mois = $this->periodes($pdv, TypeCloture::Mensuelle, $debut, $suivant);
        $avant = $this->derniereJournee($pdv, $debut);

        $ventes = 0;
        $centimes = 0;
        $journees = 0;
        foreach ($mois as $m) {
            $ventes += $m->getSalesCount();
            $journees += $m->getDailyClosureCount();
            $centimes += $this->calculateur->centimes($m->getPeriodTotal());
        }
        $precedent = $avant?->getGrandTotal() ?? '0.00';
        $perpetuel = $mois === [] ? $precedent : $mois[\count($mois) - 1]->getGrandTotal();
        $this->exigerCumulCoherent($precedent, $centimes, $perpetuel, (string) $year);

        return $this->sceller(
            $pdv,
            TypeCloture::Annuelle,
            $debut,
            $fin,
            $journees,
            $ventes,
            $centimes,
            $centimes,
            $precedent,
            $perpetuel,
            $author,
        );
    }

    private function sceller(
        PointDeVente $pdv,
        TypeCloture $type,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        int $journees,
        int $ventes,
        int $centimes,
        int $cumulExercice,
        string $precedent,
        string $perpetuel,
        ?Utilisateur $author,
    ): PeriodClosure {
        $total = $this->calculateur->decimal($centimes);
        [, $relu] = $this->daily->sealedSalesBetween($pdv, $debut, $fin->modify('+1 day'));

        // Le dernier maillon AVANT le scellement de cette clôture : elle arrête ce qui la précède.
        $dernierMaillon = $this->scellement->dernierMaillon($pdv);

        $cloture = (new PeriodClosure())
            ->setPointDeVente($pdv)
            ->setType($type)
            ->setPeriodStart($debut)
            ->setPeriodEnd($fin)
            ->setDailyClosureCount($journees)
            ->setSalesCount($ventes)
            ->setPeriodTotal($total)
            ->setFiscalYearTotal($this->calculateur->decimal($cumulExercice))
            ->setPreviousGrandTotal($precedent)
            ->setGrandTotal($perpetuel)
            ->setRecomputedTotal($relu)
            ->setLastSequence($dernierMaillon?->getNumeroSequence())
            ->setAuthor($author)
            ->setEtablissement($pdv->getEtablissement());
        $this->em->persist($cloture);

        $this->scellement->sceller(new OperationAScellerDto(
            $pdv,
            $type === TypeCloture::Annuelle ? TypeOperationScellee::ClotureAnnuelle : TypeOperationScellee::ClotureMensuelle,
            'PeriodClosure',
            $cloture->getId(),
            [
                'cloture' => (string) $cloture->getId(),
                'type' => $type->value,
                'pointDeVente' => (string) $pdv->getId(),
                'debut' => $debut->format('Y-m-d'),
                'fin' => $fin->format('Y-m-d'),
                'nombreJournees' => $journees,
                'nombreVentes' => $ventes,
                'totalPeriode' => $total,
                'cumulExercice' => $this->calculateur->decimal($cumulExercice),
                'cumulPrecedent' => $precedent,
                'cumulPerpetuel' => $perpetuel,
                'totalRelu' => $relu,
                'derniereSequence' => $dernierMaillon?->getNumeroSequence(),
                'arreteLe' => $cloture->getClosedAt()->format(\DATE_ATOM),
            ],
        ));

        $this->em->flush();

        return $cloture;
    }

    private function refuserSiAVenir(PointDeVente $pdv, \DateTimeImmutable $fin, string $message): void
    {
        // La période doit être ENTIÈREMENT passée : son dernier jour strictement avant aujourd'hui, dans
        // le fuseau de l'établissement. Clore le mois en cours le 31 au soir laisserait les ventes de la
        // fin de soirée hors de la clôture qui prétend les couvrir.
        //
        // On compare des DATES, pas des instants : `$fin` est minuit à l'heure du serveur, le jour
        // courant est minuit à l'heure de l'établissement. Comparés comme instants, aux Antilles, le
        // dernier jour du mois passerait pour « avant aujourd'hui » le jour même.
        if ($fin->format('Y-m-d') >= $this->daily->currentDay($pdv)->format('Y-m-d')) {
            throw new UnprocessableEntityHttpException($message);
        }
    }

    private function refuserSiDejaClose(PointDeVente $pdv, TypeCloture $type, \DateTimeImmutable $debut): void
    {
        $existante = $this->em->getRepository(PeriodClosure::class)
            ->findOneBy(['pointDeVente' => $pdv, 'type' => $type, 'periodStart' => $debut]);
        if ($existante instanceof PeriodClosure) {
            throw new ConflictHttpException('Cette période est déjà clôturée sur ce point de vente : une clôture est irréversible (NF525).');
        }
    }

    private function refuserSiJourneeNonArretee(PointDeVente $pdv, \DateTimeImmutable $suivant): void
    {
        $jour = $this->daily->firstUnclosedSalesDay($pdv, $suivant);
        if ($jour !== null) {
            throw new ConflictHttpException(sprintf(
                'La journée du %s porte des ventes et n\'est pas arrêtée : arrêtez-la d\'abord.',
                $jour->format('Y-m-d'),
            ));
        }
    }

    /**
     * Le cumul perpétuel doit valoir le report plus la période. Les arrêtés de journée sont immuables :
     * si l'égalité casse, c'est que la base a été touchée hors application. On ne scelle pas une
     * clôture par-dessus une incohérence — elle l'endosserait.
     */
    private function exigerCumulCoherent(string $precedent, int $centimes, string $perpetuel, string $libelle): void
    {
        if ($this->calculateur->centimes($precedent) + $centimes !== $this->calculateur->centimes($perpetuel)) {
            throw new ConflictHttpException(sprintf(
                'Période %s : le cumul perpétuel des arrêtés de journée est incohérent (%s + période ≠ %s). '
                . 'Vérifiez la chaîne avant de clôturer.',
                $libelle,
                $precedent,
                $perpetuel,
            ));
        }
    }

    /** @return list<DailyClosure> */
    private function journees(PointDeVente $pdv, \DateTimeImmutable $debut, \DateTimeImmutable $suivant): array
    {
        /** @var list<DailyClosure> $liste */
        $liste = $this->em->getRepository(DailyClosure::class)->createQueryBuilder('c')
            ->andWhere('c.pointDeVente = :pdv')
            ->andWhere('c.businessDay >= :debut')
            ->andWhere('c.businessDay < :suivant')
            // D58 — l'identifiant, jamais l'entité.
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('debut', $debut, 'date_immutable')
            ->setParameter('suivant', $suivant, 'date_immutable')
            ->orderBy('c.businessDay', 'ASC')
            ->getQuery()
            ->getResult();

        return $liste;
    }

    private function derniereJournee(PointDeVente $pdv, \DateTimeImmutable $avant): ?DailyClosure
    {
        /** @var DailyClosure|null $c */
        $c = $this->em->getRepository(DailyClosure::class)->createQueryBuilder('c')
            ->andWhere('c.pointDeVente = :pdv')
            ->andWhere('c.businessDay < :avant')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('avant', $avant, 'date_immutable')
            ->orderBy('c.businessDay', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $c;
    }

    /** La première journée arrêtée dans [$depuis, $avant[, ou `null`. `$depuis` nul = depuis toujours. */
    private function premiereJourneeArretee(PointDeVente $pdv, ?\DateTimeImmutable $depuis, \DateTimeImmutable $avant): ?\DateTimeImmutable
    {
        $qb = $this->em->getRepository(DailyClosure::class)->createQueryBuilder('c')
            ->select('MIN(c.businessDay)')
            ->andWhere('c.pointDeVente = :pdv')
            ->andWhere('c.businessDay < :avant')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('avant', $avant, 'date_immutable');
        if ($depuis !== null) {
            $qb->andWhere('c.businessDay >= :depuis')->setParameter('depuis', $depuis, 'date_immutable');
        }
        $min = $qb->getQuery()->getSingleScalarResult();

        return \is_string($min) ? new \DateTimeImmutable($min) : null;
    }

    /** La dernière clôture du type donné commençant dans [$depuis, $avant[. */
    private function dernierePeriode(PointDeVente $pdv, TypeCloture $type, \DateTimeImmutable $avant, ?\DateTimeImmutable $depuis = null): ?PeriodClosure
    {
        $qb = $this->em->getRepository(PeriodClosure::class)->createQueryBuilder('p')
            ->andWhere('p.pointDeVente = :pdv')
            ->andWhere('p.type = :type')
            ->andWhere('p.periodStart < :avant')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('type', $type->value)
            ->setParameter('avant', $avant, 'date_immutable')
            ->orderBy('p.periodStart', 'DESC')
            ->setMaxResults(1);
        if ($depuis !== null) {
            $qb->andWhere('p.periodStart >= :depuis')->setParameter('depuis', $depuis, 'date_immutable');
        }

        /** @var PeriodClosure|null $p */
        $p = $qb->getQuery()->getOneOrNullResult();

        return $p;
    }

    /** @return list<PeriodClosure> */
    private function periodes(PointDeVente $pdv, TypeCloture $type, \DateTimeImmutable $debut, \DateTimeImmutable $suivant): array
    {
        /** @var list<PeriodClosure> $liste */
        $liste = $this->em->getRepository(PeriodClosure::class)->createQueryBuilder('p')
            ->andWhere('p.pointDeVente = :pdv')
            ->andWhere('p.type = :type')
            ->andWhere('p.periodStart >= :debut')
            ->andWhere('p.periodStart < :suivant')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('type', $type->value)
            ->setParameter('debut', $debut, 'date_immutable')
            ->setParameter('suivant', $suivant, 'date_immutable')
            ->orderBy('p.periodStart', 'ASC')
            ->getQuery()
            ->getResult();

        return $liste;
    }
}
