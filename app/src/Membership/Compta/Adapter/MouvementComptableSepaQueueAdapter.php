<?php

declare(strict_types=1);

namespace App\Membership\Compta\Adapter;

use App\Organisation\Entity\Etablissement;
use App\Membership\Compta\Port\ProjectionEcritureSepaInterface;
use App\Membership\Entity\Membership;
use App\Membership\Entity\MouvementComptableSepa;
use App\Membership\Enum\TypeMouvementComptableSepa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur par défaut du port de projection comptable — persiste un `MouvementComptableSepa` en
 * file d'attente append-only, **sans écrire dans `App\Compta\Entity\EcritureComptable`** (Risque n°1
 * du plan, aucun fichier `App\Compta\*` modifié). Journalise déjà correctement la traçabilité exigée
 * (RG-SPORT §4.5) — le raccordement GL réel reste à faire par une extension M6 future.
 */
final class MouvementComptableSepaQueueAdapter implements ProjectionEcritureSepaInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function enregistrerEncaissement(Uuid $etablissementId, Uuid $abonnementId, int $montantCentimes, \DateTimeImmutable $date, string $origine): void
    {
        $this->enregistrer($etablissementId, $abonnementId, TypeMouvementComptableSepa::Encaissement, $montantCentimes, $date, $origine);
    }

    public function enregistrerImpaye(Uuid $etablissementId, Uuid $abonnementId, int $montantCentimes, \DateTimeImmutable $date): void
    {
        $this->enregistrer($etablissementId, $abonnementId, TypeMouvementComptableSepa::Impaye, $montantCentimes, $date, 'rejet_sepa');
    }

    private function enregistrer(Uuid $etablissementId, Uuid $abonnementId, TypeMouvementComptableSepa $type, int $montantCentimes, \DateTimeImmutable $date, string $origine): void
    {
        $etablissement = $this->em->getRepository(Etablissement::class)->find($etablissementId);
        $abonnement = $this->em->getRepository(Membership::class)->find($abonnementId);
        if (!$etablissement instanceof Etablissement || !$abonnement instanceof Membership) {
            return;
        }

        $mouvement = new MouvementComptableSepa();
        $mouvement->setEtablissement($etablissement)
            ->setAbonnement($abonnement)
            ->setType($type)
            ->setMontantCentimes($montantCentimes)
            ->setDateFaitGenerateur($date)
            ->setOrigine($origine);

        $this->em->persist($mouvement);
        $this->em->flush();
    }
}
