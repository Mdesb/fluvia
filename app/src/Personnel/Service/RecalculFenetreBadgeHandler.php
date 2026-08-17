<?php

declare(strict_types=1);

namespace App\Personnel\Service;

use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Entity\PorteeAccesEmploye;
use App\Personnel\Enum\ModeHoraireBadge;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Personnel\Enum\StatutCreneauTravail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recalcule la fenêtre de validité (`DroitAcces.fenetreDebut/Fin`) d'un badge staff en mode
 * `shifts_uniquement` (décision n°3 du plan) : fixée au prochain/au `CreneauTravail` confirmé en
 * cours de l'employé sur l'établissement du badge (± `margeAvantApres`, portée par
 * `DroitAcces.margeAvanceDefaut/margeRetardDefaut`, appliquée nativement par
 * `App\Acces\Service\ResolveurMarges`, aucune modification). En dehors de tout shift, la fenêtre est
 * mise dans le passé pour que `ResolveurMarges` refuse tout passage, sans code additionnel. Mode
 * `permanent` : fenêtre `null` (aucune contrainte, §4.2 du plan L3).
 *
 * Déclenché en écoute applicative (appel direct depuis les processors de création/confirmation/
 * annulation d'`AffectationTravail` et de validation d'`Absence`) et par la commande planifiée
 * `personnel:recalculer-fenetres-badges` (cron ~5 min, `recalculerTous()`).
 */
final class RecalculFenetreBadgeHandler
{
    private const INSTANT_PASSE = '1970-01-01T00:00:00+00:00';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function recalculer(BadgeStaff $badge): void
    {
        if ($badge->getStatut() !== StatutBadgeStaff::Actif) {
            return;
        }

        $droit = $badge->getDroitAcces();
        if ($droit === null) {
            return;
        }

        $portee = $this->em->getRepository(PorteeAccesEmploye::class)->findOneBy(['badgeStaff' => $badge]);
        $mode = $portee?->getModeHoraire();

        if ($mode === ModeHoraireBadge::Permanent) {
            $droit->setFenetreDebut(null)->setFenetreFin(null);
            $this->em->flush();

            return;
        }

        // shifts_uniquement (ou portée non résolue, par défaut prudent = fenêtre bornée).
        $maintenant = new \DateTimeImmutable();
        $employe = $badge->getEmploye();
        $etablissement = $badge->getEtablissement();
        if ($employe === null || $etablissement === null) {
            return;
        }

        $creneau = $this->creneauEnCoursOuProchain($employe->getId(), $etablissement->getId(), $maintenant);

        if ($creneau === null) {
            $instantPasse = new \DateTimeImmutable(self::INSTANT_PASSE);
            $droit->setFenetreDebut($instantPasse)->setFenetreFin($instantPasse);
        } else {
            $droit->setFenetreDebut($creneau->getDebut())->setFenetreFin($creneau->getFin());
        }

        $this->em->flush();
    }

    /** Recalcule tous les badges actifs en mode shifts_uniquement (cron `personnel:recalculer-fenetres-badges`). */
    public function recalculerTous(): void
    {
        $badges = $this->em->getRepository(BadgeStaff::class)->findBy(['statut' => StatutBadgeStaff::Actif]);
        foreach ($badges as $badge) {
            $this->recalculer($badge);
        }
    }

    private function creneauEnCoursOuProchain(Uuid $employeId, Uuid $etablissementId, \DateTimeImmutable $maintenant): ?CreneauTravail
    {
        $qb = $this->em->getRepository(CreneauTravail::class)->createQueryBuilder('c')
            ->innerJoin(AffectationTravail::class, 'a', 'WITH', 'a.creneauTravail = c')
            ->andWhere('a.employe = :employe')
            ->andWhere('c.etablissement = :etablissement')
            ->andWhere('a.statut != :annulee')
            ->andWhere('c.statut != :creneauAnnule')
            ->setParameter('employe', $employeId, 'uuid')
            ->setParameter('etablissement', $etablissementId, 'uuid')
            ->setParameter('annulee', StatutAffectationTravail::Annulee->value)
            ->setParameter('creneauAnnule', StatutCreneauTravail::Annule->value);

        // En cours en priorité.
        $enCours = (clone $qb)
            ->andWhere('c.debut <= :maintenant')
            ->andWhere('c.fin >= :maintenant')
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->orderBy('c.debut', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if ($enCours instanceof CreneauTravail) {
            return $enCours;
        }

        // Sinon le prochain à venir.
        return (clone $qb)
            ->andWhere('c.debut > :maintenant')
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->orderBy('c.debut', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
