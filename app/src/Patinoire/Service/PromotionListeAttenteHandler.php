<?php

declare(strict_types=1);

namespace App\Patinoire\Service;

use App\Patinoire\Entity\ListeAttentePointure;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Enum\StatutListeAttentePointure;
use App\Patinoire\Notification\ListeAttentePointureMailer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Promeut le premier rang en attente dès qu'une unité de la pointure redevient disponible (retour
 * bon, remise en service après affûtage — RG-PAT-05/décision actée « pointure en rupture », §4.5).
 * Déclenché explicitement par `RetournerPatinsProcessor` (retour état bon) et
 * `TerminerAffutageProcessor` (maintenance_parc terminée).
 */
final class PromotionListeAttenteHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListeAttentePointureMailer $mailer,
    ) {
    }

    public function promouvoir(ParcPatins $parcPatins): void
    {
        if ($parcPatins->getQuantiteDisponible() <= 0) {
            return;
        }

        $premier = $this->em->getRepository(ListeAttentePointure::class)->createQueryBuilder('l')
            ->andWhere('l.parcPatins = :parc')
            ->andWhere('l.statut = :enAttente')
            ->setParameter('parc', $parcPatins->getId(), 'uuid')
            ->setParameter('enAttente', StatutListeAttentePointure::EnAttente->value)
            ->orderBy('l.rang', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        if (!$premier instanceof ListeAttentePointure) {
            return;
        }

        $premier->setStatut(StatutListeAttentePointure::Proposee)
            ->setDateExpirationProposition((new \DateTimeImmutable())->modify('+2 days'));
        $this->em->flush();

        $this->mailer->notifier($premier);
    }
}
