<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\CreneauBassin;
use App\Piscine\Entity\CreneauPublic;
use App\Piscine\Entity\LigneEau;
use App\Piscine\Enum\EtatLigneEau;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Orchestration de l'écriture d'un `CreneauPublic` (US-L6-05/06/07, CA-5/6/7) : garde de
 * chevauchement (`AffectationLigneGuard`), garde de capacité disponible (RG-PISC bassin/lignes),
 * mise à jour de l'état dérivé des lignes, recalcul de la jauge grand public (`PossProrataCalculator`).
 */
final class CreneauPublicHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AffectationLigneGuard $guardChevauchement,
        private readonly PossProrataCalculator $prorata,
    ) {
    }

    public function enregistrer(CreneauPublic $creneauPublic): void
    {
        $creneauBassin = $creneauPublic->getCreneauBassin();
        if ($creneauBassin === null) {
            throw new UnprocessableEntityHttpException('Créneau bassin requis.');
        }
        $bassin = $creneauBassin->getBassin();
        if (!$bassin instanceof Bassin) {
            throw new UnprocessableEntityHttpException('Bassin introuvable pour ce créneau.');
        }

        foreach ($creneauPublic->getLignes() as $ligne) {
            \assert($ligne instanceof LigneEau);
            if ($ligne->getBassin()?->getId()?->equals($bassin->getId()) !== true) {
                throw new UnprocessableEntityHttpException('US-L6-05 : la ligne affectée n\'appartient pas au bassin du créneau.');
            }
        }

        // CA-6 : pas de chevauchement d'une même ligne entre deux publics.
        $this->guardChevauchement->verifier($creneauPublic);

        // CA-5 : réservation dépassant la capacité disponible du bassin refusée.
        $capaciteEngagee = $this->capaciteEngagee($creneauBassin, $creneauPublic);
        if ($capaciteEngagee + $creneauPublic->getJauge() > $bassin->getCapacite()) {
            throw new UnprocessableEntityHttpException('US-L6-05 : la jauge demandée dépasse la capacité disponible du bassin.');
        }

        foreach ($creneauPublic->getLignes() as $ligne) {
            \assert($ligne instanceof LigneEau);
            $ligne->setEtat(EtatLigneEau::Reservee);
        }
        // Flush intermédiaire : `PossProrataCalculator::recalculer()` compte les lignes réservées par
        // une requête DB (repository `count()`), qui ne verrait pas les changements ci-dessus tant
        // qu'ils ne sont pas physiquement écrits (le flush final du processor persiste `creneauPublic`
        // lui-même, sans configurer d'ordre garanti avec ces mises à jour de `LigneEau`).
        $this->em->flush();

        $this->prorata->recalculer($creneauBassin);
    }

    /** Libère les lignes qui ne sont plus référencées par un autre public actif, recalcule la jauge. */
    public function retirer(CreneauPublic $creneauPublic): void
    {
        $creneauBassin = $creneauPublic->getCreneauBassin();
        $lignes = $creneauPublic->getLignes()->toArray();

        $this->em->remove($creneauPublic);
        $this->em->flush();

        foreach ($lignes as $ligne) {
            \assert($ligne instanceof LigneEau);
            $encoreUtilisee = $this->em->getRepository(CreneauPublic::class)->createQueryBuilder('cp')
                ->innerJoin('cp.lignes', 'l')
                ->andWhere('l.id = :ligne')
                ->setParameter('ligne', $ligne->getId(), 'uuid')
                ->getQuery()->getOneOrNullResult() !== null;
            if (!$encoreUtilisee) {
                $ligne->setEtat(EtatLigneEau::Publique);
            }
        }
        // Flush intermédiaire avant recalcul (même remarque que `enregistrer()` : le compteur de
        // lignes réservées interroge la base, pas l'unité de travail en mémoire).
        $this->em->flush();

        if ($creneauBassin instanceof CreneauBassin) {
            $this->prorata->recalculer($creneauBassin);
        }

        $this->em->flush();
    }

    /** Somme des jauges des publics existants sur le même bassin dont le créneau chevauche (hors soi-même). */
    private function capaciteEngagee(CreneauBassin $creneauBassin, CreneauPublic $creneauPublic): int
    {
        $bassin = $creneauBassin->getBassin();

        /** @var list<CreneauPublic> $existants */
        $existants = $this->em->getRepository(CreneauPublic::class)->createQueryBuilder('cp')
            ->innerJoin('cp.creneauBassin', 'cb')
            ->andWhere('cb.bassin = :bassin')
            ->andWhere('cp.id != :moi')
            ->setParameter('bassin', $bassin?->getId(), 'uuid')
            ->setParameter('moi', $creneauPublic->getId(), 'uuid')
            ->getQuery()->getResult();

        $total = 0;
        foreach ($existants as $existant) {
            $autreCreneau = $existant->getCreneauBassin();
            if ($autreCreneau !== null && $creneauBassin->chevauche($autreCreneau)) {
                $total += $existant->getJauge();
            }
        }

        return $total;
    }
}
