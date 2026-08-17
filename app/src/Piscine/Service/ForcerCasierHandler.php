<?php

declare(strict_types=1);

namespace App\Piscine\Service;

use App\Caution\Service\GestionCaution;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\CautionCasier;
use App\Piscine\Entity\ForcageCasier;
use App\Piscine\Entity\RelanceCasier;
use App\Piscine\Enum\EtatCasier;
use App\Piscine\Enum\StatutCaution;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Forçage administratif d'un casier non rendu, après délai (US-L6-09, CA-9, décision actée). Action
 * journalisée (agent, motif, horodatage) ; la caution passe en `retenue`. La retenue totale (montant,
 * ledger) est déléguée à `GestionCaution::forcer()` sur la caution générique liée (refactor caution
 * générique) ; `ForcageCasier` reste le journal local exposé côté Piscine (délai de forçage
 * spécifique à la verticale, non repris par le patron générique).
 */
final class ForcerCasierHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GestionCaution $gestionCaution,
    ) {
    }

    public function forcer(Casier $casier, Utilisateur $agent, string $motif): ForcageCasier
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour un forçage de casier (RG-SOCLE-07).');
        }
        if ($casier->getEtat() !== EtatCasier::NonRendu) {
            throw new UnprocessableEntityHttpException('Le forçage n\'est possible que sur un casier en retard (non rendu).');
        }

        $derniereRelance = $this->em->getRepository(RelanceCasier::class)->createQueryBuilder('r')
            ->andWhere('r.casier = :casier')
            ->setParameter('casier', $casier->getId(), 'uuid')
            ->orderBy('r.dateRelance', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        $maintenant = new \DateTimeImmutable();
        if (!$derniereRelance instanceof RelanceCasier || !$derniereRelance->forcageAutoriseA($maintenant)) {
            throw new UnprocessableEntityHttpException('Le délai de forçage n\'est pas encore dépassé.');
        }

        $forcage = new ForcageCasier();
        $forcage->setCasier($casier)->setAgent($agent)->setMotif($motif)->setHorodatage($maintenant);
        $this->em->persist($forcage);

        $caution = $this->em->getRepository(CautionCasier::class)->createQueryBuilder('c')
            ->andWhere('c.casier = :casier')
            ->andWhere('c.statut != :liberee')
            ->setParameter('casier', $casier->getId(), 'uuid')
            ->setParameter('liberee', StatutCaution::Liberee)
            ->orderBy('c.dateEncaissement', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if ($caution instanceof CautionCasier) {
            $caution->setStatut(StatutCaution::Retenue);
        }

        $cautionGenerique = $this->gestionCaution->cautionActivePour(AttribuerCasierHandler::TYPE_CIBLE, $casier->getId());
        if ($cautionGenerique !== null) {
            $this->gestionCaution->forcer($cautionGenerique, $agent, $motif);
        }

        $casier->setEtat(EtatCasier::Libre)->setBracelet(null);

        $this->em->flush();

        return $forcage;
    }
}
