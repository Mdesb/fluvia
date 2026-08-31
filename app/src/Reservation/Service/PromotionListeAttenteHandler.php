<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\ListeAttente;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutListeAttente;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Port\NotificationReservationInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Promotion automatique de la liste d'attente au désistement (RG-M5-06, CA-5) : sans validation
 * manuelle. Le délai de confirmation de la promotion (défaut 15 min, §7 point 11 — ⚠ HYPOTHÈSE,
 * non chiffré par les sources) est porté par `ListeAttente.dateExpirationPromotion` ;
 * `expirerPromotionsDepassees()` matérialise l'expiration : la réservation issue de la promotion
 * (si toujours confirmée et sans présence) est libérée et le rang suivant promu.
 *
 * **Correctif du 24/08 (défaut préexistant, hors ACT-1).** La promotion créait une `Reservation`
 * sans jamais incrémenter `Ressource.occupationCourante`, alors que `ReserverProcessor` le fait.
 * Ce n'était pas un simple sous-comptage : la réservation issue d'une promotion est ensuite annulable
 * par les chemins ordinaires, qui **décrémentent** — la jauge perdait alors une unité appartenant à
 * une autre réservation. La promotion incrémente donc désormais, et les deux sorties propres à ce
 * service (expiration de promotion) décrémentent, pour que ce qu'on relâche soit ce qu'on a pris.
 */
final class PromotionListeAttenteHandler
{
    private const DELAI_CONFIRMATION_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JaugeCreneauGuard $jauge,
        private readonly JaugeRessourceMereHandler $jaugeMere,
        private readonly ConsumedSlotResolver $creneauxConsommes,
        private readonly NotificationReservationInterface $notification,
    ) {
    }

    public function promouvoirSiPlaceDisponible(Creneau $creneau): ?Reservation
    {
        if ($this->jauge->estComplet($creneau)) {
            return null;
        }

        /** @var ListeAttente|null $inscription */
        $inscription = $this->em->getRepository(ListeAttente::class)->createQueryBuilder('l')
            ->andWhere('l.creneau = :creneau')
            ->andWhere('l.statut = :statut')
            ->setParameter('creneau', $creneau->getId(), 'uuid')
            ->setParameter('statut', StatutListeAttente::EnAttente->value)
            ->orderBy('l.rang', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($inscription === null) {
            return null;
        }

        // ACT-1 — le premier de la liste peut demander plus que ce qui vient de se libérer. On ne
        // promeut alors PERSONNE : sauter au suivant qui « rentre » romprait le premier arrivé
        // premier servi de RG-M5-06, et ce n'est pas à moi d'arbitrer une politique commerciale que
        // ni D16 ni RG-M5-06 ne posent. Question ouverte à claude-A dans mon rapport.
        if (!$this->jauge->peutAccueillir($creneau, $inscription->getQuantity())) {
            return null;
        }

        // Le créneau peut avoir de la place sans que la ressource porteuse en ait (RG-M5-08, CA-14) :
        // le même double contrôle que `ReserverProcessor`, sans quoi la promotion serait le seul
        // chemin capable de faire déborder la jauge globale.
        $ressourcePorteuse = $creneau->getRessource();
        if ($ressourcePorteuse !== null && $this->jaugeMere->jaugeDepassee($ressourcePorteuse, $inscription->getQuantity())) {
            return null;
        }

        // ACT-1 point 3 / D33 — un promu consomme exactement ce qu'aurait consommé une réservation
        // ordinaire sur ce créneau. L'oublier ici ferait de la promotion un chemin qui remplit la
        // salle sans jamais apparaître dans son service : même famille que le défaut de jauge
        // corrigé juste avant, par le même mécanisme.
        $consommes = $this->creneauxConsommes->resolve($creneau);
        foreach ($consommes as $consomme) {
            if (!$this->jauge->peutAccueillir($consomme, $inscription->getQuantity())) {
                return null;
            }
        }

        $reservation = new Reservation();
        $reservation->setCreneau($creneau)
            ->setOrganisateur($inscription->getBeneficiaire())
            ->setEtablissement($creneau->getEtablissement())
            ->setModeDecompte(ModeDecompteReservation::Gratuit)
            ->setQuantity($inscription->getQuantity())
            ->setMontantDu('0.00');
        foreach ($consommes as $consomme) {
            $reservation->addConsumedSlot($consomme);
        }
        $this->em->persist($reservation);

        if ($ressourcePorteuse !== null) {
            $this->jaugeMere->incrementer($ressourcePorteuse, $inscription->getQuantity());
        }

        $inscription->setStatut(StatutListeAttente::Promue);
        $inscription->setPromueEn($reservation);
        $inscription->setDateExpirationPromotion((new \DateTimeImmutable())->modify(sprintf('+%d minutes', self::DELAI_CONFIRMATION_MINUTES)));

        $this->notification->notifierPromotionListeAttente($inscription);
        $this->em->flush();

        return $reservation;
    }

    /** @return int nombre de promotions expirées traitées */
    public function expirerPromotionsDepassees(\DateTimeImmutable $maintenant): int
    {
        /** @var list<ListeAttente> $expirees */
        $expirees = $this->em->getRepository(ListeAttente::class)->createQueryBuilder('l')
            ->andWhere('l.statut = :statut')
            ->andWhere('l.dateExpirationPromotion IS NOT NULL')
            ->andWhere('l.dateExpirationPromotion < :maintenant')
            ->setParameter('statut', StatutListeAttente::Promue->value)
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->getQuery()
            ->getResult();

        foreach ($expirees as $inscription) {
            $reservation = $inscription->getPromueEn();
            if ($reservation !== null && $reservation->getStatut() === StatutReservation::Confirmee && !$reservation->isPresenceConfirmee()) {
                $reservation->setStatut(StatutReservation::AnnuleeLibre);
                // Symétrie exigée par claude-A : c'est LA sortie propre à ce service, et la seule
                // qui ne passe ni par `AnnulerReservationProcessor` ni par `BasculerNoShowCommand`.
                // Sans elle, incrémenter à la promotion transformerait un sous-comptage inoffensif
                // en fuite de compteur — pire qu'avant.
                $ressourceExpiree = $reservation->getCreneau()?->getRessource();
                if ($ressourceExpiree !== null) {
                    $this->jaugeMere->decrementer($ressourceExpiree, $reservation->getQuantity());
                }
            }
            $inscription->setStatut(StatutListeAttente::Expiree);
        }
        $this->em->flush();

        foreach ($expirees as $inscription) {
            $creneau = $inscription->getCreneau();
            if ($creneau !== null) {
                $this->promouvoirSiPlaceDisponible($creneau);
            }
        }

        return \count($expirees);
    }
}
