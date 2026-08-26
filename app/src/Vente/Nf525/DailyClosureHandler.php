<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Caisse\Entity\PointDeVente;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\Entity\DailyClosure;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Arrête la journée d'un point de vente et scelle l'arrêté (D57).
 *
 * **Trois refus, et chacun protège le cumul perpétuel** — c'est-à-dire la seule chose qui rende une
 * suppression détectable. Un cumul faux n'est pas un cumul approximatif : c'est un mécanisme de
 * contrôle qui rendra « tout va bien » sur une base amputée.
 */
final class DailyClosureHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementHandler $scellement,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    public function close(PointDeVente $pdv, \DateTimeImmutable $day, ?Utilisateur $author): DailyClosure
    {
        $jour = $day->setTime(0, 0);

        // 1 — On n'arrête pas une journée qui n'a pas eu lieu. Sans ce refus, clore demain figerait un
        // cumul que les ventes de demain viendraient contredire : la clôture affirmerait un total, et
        // la journée qu'elle prétend couvrir se remplirait après coup.
        // **Dans le fuseau de l'établissement, pas dans celui du serveur.** À 3 h du matin à Paris,
        // il est encore 21 h la veille aux Antilles : comparer à l'« aujourd'hui » du serveur y
        // clôturerait une journée EN COURS, avec des ventes encore à venir dessus. Elles tomberaient
        // alors dans la journée suivante, et le refus « journée sautée » ne les rattraperait pas,
        // puisque leur journée aurait été close. Une clôture fausse, et scellée.
        if ($jour > $this->aujourdhui($pdv)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'On ne clôt pas une journée à venir (il est le %s sur ce point de vente).',
                $this->aujourdhui($pdv)->format('Y-m-d'),
            ));
        }

        // 2 — Une journée ne se clôt qu'une fois. Deux clôtures du même jour compteraient deux fois la
        // même journée dans le cumul : le mécanisme censé détecter une disparition fabriquerait un
        // excédent, ce qui est le défaut symétrique et tout aussi grave.
        $existante = $this->em->getRepository(DailyClosure::class)
            ->findOneBy(['pointDeVente' => $pdv, 'businessDay' => $jour]);
        if ($existante instanceof DailyClosure) {
            throw new ConflictHttpException(sprintf(
                'Journée du %s déjà clôturée sur ce point de vente : une clôture est irréversible (NF525).',
                $jour->format('Y-m-d'),
            ));
        }

        $precedente = $this->derniereCloture($pdv, $jour);

        // 3 — **Le refus qui compte, et le moins évident.** Clore le 12 en laissant le 10 ouvert alors
        // qu'il porte des ventes ferait sauter ces ventes du cumul : le total du 12 partirait du cumul
        // du 9. Les ventes du 10 existeraient en base et seraient absentes de l'arrêté — exactement la
        // signature d'une suppression, produite ici par une clôture dans le désordre. La chaîne des
        // cumuls doit être continue pour dire quoi que ce soit.
        $oubliee = $this->premierJourNonClos($pdv, $precedente?->getBusinessDay(), $jour);
        if ($oubliee !== null) {
            throw new ConflictHttpException(sprintf(
                'La journée du %s porte des ventes et n\'est pas clôturée : clôturez-la d\'abord, sinon '
                . 'ses ventes disparaîtraient du cumul perpétuel.',
                $oubliee->format('Y-m-d'),
            ));
        }

        [$nombre, $total] = $this->totalDuJour($pdv, $jour);

        $precedentCumul = $precedente?->getGrandTotal() ?? '0.00';
        $cumul = $this->calculateur->decimal(
            $this->calculateur->centimes($precedentCumul) + $this->calculateur->centimes($total),
        );

        // Le dernier maillon **avant** le scellement de cette clôture : la clôture arrête ce qui la
        // précède, elle ne se compte pas elle-même.
        $dernierMaillon = $this->scellement->dernierMaillon($pdv);

        $cloture = new DailyClosure();
        $cloture->setPointDeVente($pdv)
            ->setBusinessDay($jour)
            ->setSalesCount($nombre)
            ->setDailyTotal($total)
            ->setPreviousGrandTotal($precedentCumul)
            ->setGrandTotal($cumul)
            ->setLastSequence($dernierMaillon?->getNumeroSequence())
            ->setAuthor($author)
            ->setEtablissement($pdv->getEtablissement());
        $this->em->persist($cloture);

        $this->scellement->sceller(new OperationAScellerDto(
            $pdv,
            TypeOperationScellee::ClotureJournaliere,
            'DailyClosure',
            $cloture->getId(),
            [
                'cloture' => (string) $cloture->getId(),
                'pointDeVente' => (string) $pdv->getId(),
                'journee' => $jour->format('Y-m-d'),
                'nombreVentes' => $nombre,
                'totalJour' => $total,
                'cumulPrecedent' => $precedentCumul,
                'cumulPerpetuel' => $cumul,
                'derniereSequence' => $dernierMaillon?->getNumeroSequence(),
                'arreteLe' => $cloture->getClosedAt()->format(\DATE_ATOM),
            ],
        ));

        $this->em->flush();

        return $cloture;
    }

    /**
     * Ventes scellées de la journée : nombre et total.
     *
     * `estScellee()` couvre `validee`, `annulee` et `avoir_emis` — tout ce qui est entré dans la
     * chaîne. Une vente annulée reste dans le cumul parce qu'elle **est** dans la chaîne : le cumul
     * totalise ce qui a été scellé, il ne calcule pas un résultat. Retirer les annulations d'ici
     * rendrait le cumul incapable de faire ce pour quoi il existe.
     *
     * @return array{0: int, 1: string}
     */
    private function totalDuJour(PointDeVente $pdv, \DateTimeImmutable $jour): array
    {
        $bornes = $this->bornes($pdv, $jour);
        $ligne = $this->em->getRepository(Vente::class)->createQueryBuilder('v')
            ->select('COUNT(v.id) AS nb', 'COALESCE(SUM(v.total), 0) AS total')
            ->andWhere('v.pointDeVente = :pdv')
            ->andWhere('v.statut != :encours')
            ->andWhere('v.date >= :debut')
            ->andWhere('v.date < :fin')
            // D58 — l'identifiant, jamais l'entité : un paramètre d'entité sur une relation à
            // identifiant `Uuid` ne compte rien et ne lève pas. Une clôture qui totalise zéro parce
            // que la requête n'a rien trouvé serait indiscernable d'une journée sans vente.
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('encours', StatutVente::EnCours->value)
            ->setParameter('debut', $bornes[0])
            ->setParameter('fin', $bornes[1])
            ->getQuery()
            ->getSingleResult();

        return [(int) $ligne['nb'], $this->calculateur->decimal($this->calculateur->centimes((string) $ligne['total']))];
    }

    /**
     * Le fuseau dans lequel ce point de vente vit sa journée.
     *
     * Il vient de l'établissement (`claude-A`, à ma demande) : sans lui, tout exploitant à l'ouest de
     * Paris se ferait clôturer une journée en cours par la tâche de nuit.
     */
    private function fuseau(PointDeVente $pdv): \DateTimeZone
    {
        return new \DateTimeZone($pdv->getEtablissement()?->getFuseauHoraire() ?? date_default_timezone_get());
    }

    /** La date du jour **sur ce point de vente**, qui n'est pas forcément celle du serveur. */
    private function aujourdhui(PointDeVente $pdv): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', $this->fuseau($pdv)))->setTime(0, 0);
    }

    /**
     * Les bornes d'une journée d'exploitation, **converties dans l'heure du serveur**.
     *
     * `vente_vente.date` est un `DATETIME` sans fuseau : la base stocke l'heure du serveur et ne sait
     * pas d'où elle vient. Comparer une borne exprimée aux Antilles à une colonne écrite à Paris
     * donnerait une journée décalée de quatre heures — silencieusement, puisque la requête
     * fonctionnerait parfaitement et rendrait simplement les mauvaises ventes.
     *
     * On construit donc la journée dans le fuseau de l'établissement, puis on la ramène dans celui du
     * serveur pour interroger. Le jour où les dates seront stockées en UTC, c'est ici que ça change.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function bornes(PointDeVente $pdv, \DateTimeImmutable $jour): array
    {
        $debut = new \DateTimeImmutable($jour->format('Y-m-d') . ' 00:00:00', $this->fuseau($pdv));
        $serveur = new \DateTimeZone(date_default_timezone_get());

        return [$debut->setTimezone($serveur), $debut->modify('+1 day')->setTimezone($serveur)];
    }

    private function derniereCloture(PointDeVente $pdv, \DateTimeImmutable $jour): ?DailyClosure
    {
        /** @var DailyClosure|null $cloture */
        $cloture = $this->em->getRepository(DailyClosure::class)->createQueryBuilder('c')
            ->andWhere('c.pointDeVente = :pdv')
            ->andWhere('c.businessDay < :jour')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('jour', $jour)
            ->orderBy('c.businessDay', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $cloture;
    }

    /**
     * Première journée porteuse de ventes, postérieure au dernier arrêté et antérieure à celui qu'on
     * s'apprête à poser — c'est-à-dire une journée qui serait sautée.
     */
    private function premierJourNonClos(PointDeVente $pdv, ?\DateTimeImmutable $dernierArrete, \DateTimeImmutable $jour): ?\DateTimeImmutable
    {
        // Bornes converties, pour la même raison que `bornes()` : la colonne est en heure serveur.
        $depuis = $dernierArrete !== null
            ? $this->bornes($pdv, $dernierArrete->modify('+1 day'))[0]
            : new \DateTimeImmutable('@0');
        $jour = $this->bornes($pdv, $jour)[0];

        $date = $this->em->getRepository(Vente::class)->createQueryBuilder('v')
            ->select('MIN(v.date)')
            ->andWhere('v.pointDeVente = :pdv')
            ->andWhere('v.statut != :encours')
            ->andWhere('v.date >= :depuis')
            ->andWhere('v.date < :jour')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('encours', StatutVente::EnCours->value)
            ->setParameter('depuis', $depuis)
            ->setParameter('jour', $jour)
            ->getQuery()
            ->getSingleScalarResult();

        return \is_string($date) ? (new \DateTimeImmutable($date))->setTime(0, 0) : null;
    }
}
