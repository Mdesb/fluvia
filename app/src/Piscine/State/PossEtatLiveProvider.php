<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\Entity\JaugeFmi;
use App\Piscine\ApiResource\PossEtatLive;
use App\Piscine\Entity\CreneauPublic;
use App\Piscine\Entity\Poss;
use App\Piscine\Enum\TypePublic;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /piscine/poss/{id}/etat (US-L6-03, CA-3, plan §2.3) : compose `JaugeFmi` (L3, lecture seule)
 * et les réservations piscine (`CreneauPublic` scolaire/club). Première lecture applicative de
 * `EspaceAcces.preAlertePct` (posé par L3, jamais consommé jusqu'ici).
 *
 * @implements ProviderInterface<PossEtatLive>
 */
final class PossEtatLiveProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PossEtatLive
    {
        $id = $uriVariables['id'] ?? null;
        $poss = \is_string($id) && Uuid::isValid($id) ? $this->em->getRepository(Poss::class)->find($id) : null;
        if (!$poss instanceof Poss) {
            throw new NotFoundHttpException('POSS introuvable.');
        }

        $espace = $poss->getEspaceAcces();
        $jauge = $espace !== null ? $this->em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]) : null;

        $vue = new PossEtatLive();
        $vue->id = (string) $poss->getId();
        $vue->presents = $jauge instanceof JaugeFmi ? $jauge->getValeurCourante() : 0;
        $vue->seuilPoss = $poss->getSeuil();
        $vue->preAlerteAtteinte = $this->preAlerteAtteinte($poss, $vue->presents, $vue->seuilPoss);
        $vue->placesReserveesRestantes = $poss->isReservationsProtegees() ? $this->placesReserveesRestantes($poss) : 0;

        return $vue;
    }

    private function preAlerteAtteinte(Poss $poss, int $presents, int $seuil): bool
    {
        $pct = $poss->getPreAlertePct();
        if ($pct === null || $seuil <= 0) {
            return false;
        }

        return $presents >= (int) ceil($seuil * $pct / 100);
    }

    /** Somme des places encore libres des réservations scolaires/club (protégées du disponible libre). */
    private function placesReserveesRestantes(Poss $poss): int
    {
        $etablissement = $poss->getEtablissement();
        if ($etablissement === null) {
            return 0;
        }

        /** @var list<CreneauPublic> $creneauxPublics */
        $creneauxPublics = $this->em->getRepository(CreneauPublic::class)->createQueryBuilder('cp')
            ->innerJoin('cp.creneauBassin', 'cb')
            ->innerJoin('cb.bassin', 'b')
            ->andWhere('b.etablissement = :etab')
            ->andWhere('cp.typePublic IN (:publics)')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('publics', [TypePublic::Scolaire, TypePublic::Club])
            ->getQuery()->getResult();

        $total = 0;
        foreach ($creneauxPublics as $creneauPublic) {
            $total += max(0, $creneauPublic->getJauge() - $creneauPublic->getOccupationCourante());
        }

        return $total;
    }
}
