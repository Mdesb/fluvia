<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Service\VersionSnapshotSequencer;
use App\Organisation\Entity\Etablissement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * CQ-3 + CQ-6 — débit et restitution d'une **carte de N réservations** (quota de stock, D24).
 *
 * Lit et écrit directement `App\Acces\Entity\DroitAcces` : même précédent de couplage que
 * `ProjectionAccesReservationHandler` et `ApplyNoShowCreditIssueHandler` (CQ-5) — aucun fichier
 * `App\Acces\*` n'est modifié pour ce comportement.
 *
 * **Le décompte a lieu à la réservation, pas au passage** (D24, arbitrage claude-A du 24/08). Une
 * carte de dix réservations autorise dix *actes de réservation* : le solde vit donc sur le droit de
 * type carte, qui survit aux dix, et non sur le droit `Booking` projeté par réservation — celui-ci
 * reste l'accès physique au créneau, `creditRestant` à `null`.
 *
 * **Écritures atomiques, jamais de read-modify-write** : `UPDATE ... WHERE credit_restant > 0`, puis
 * `refresh()` pour que le suivi de changements de Doctrine ne réécrive pas une valeur périmée par-
 * dessus. Même patron que `CardRechargeHandler` et `ApplyNoShowCreditIssueHandler`, et pour la même
 * raison : deux guichets qui débitent la même carte à la même seconde ne doivent pas pouvoir la
 * faire passer sous zéro.
 */
final class StockCardCreditHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly VersionSnapshotSequencer $sequencer,
    ) {
    }

    /**
     * Débite une unité et rend le droit débité.
     *
     * `$etablissement` vient du créneau, donc de la session serveur, jamais du corps de la requête :
     * c'est la contrainte de D3/D8, et elle est décisive ici puisque `$droitRef` est fourni par le
     * client. Échec fermé en 404 et non 403 — confirmer l'existence d'une carte d'un autre
     * établissement serait déjà une fuite.
     */
    public function debiter(Uuid $droitRef, Etablissement $etablissement): DroitAcces
    {
        $droit = $this->em->getRepository(DroitAcces::class)->find($droitRef);

        if (!$droit instanceof DroitAcces
            || (string) $droit->getEtablissement()?->getId() !== (string) $etablissement->getId()) {
            throw new NotFoundHttpException('Carte introuvable.');
        }

        if ($droit->getSourceType() !== TypeDroitAcces::CarteQuota) {
            throw new ConflictHttpException(
                'Ce droit n\'est pas une carte à crédit : il ne peut pas payer une réservation (CQ-3).'
            );
        }

        if ($droit->getStatutProjection() !== StatutProjectionDroit::Valide) {
            throw new ConflictHttpException('Carte dévalidée : réservation refusée (CQ-3).');
        }

        if ($droit->getCreditRestant() === null) {
            throw new ConflictHttpException('Cette carte ne porte pas de crédit décomptable (CQ-3).');
        }

        $maintenant = new \DateTimeImmutable();
        $fin = $droit->getFenetreFin();
        if ($fin !== null && $fin < $maintenant) {
            // Une carte expirée garde son solde mais ne paie plus rien : le crédit n'est pas perdu,
            // il est seulement inutilisable tant que la carte n'est pas rechargée (D26 — une recharge
            // prolonge la validité).
            throw new ConflictHttpException('Carte expirée : réservation refusée (CQ-3).');
        }

        $droitId = $droit->getId();
        $debite = false;

        $this->connection->transactional(function () use ($droit, $droitId, &$debite): void {
            $affectees = (int) $this->connection->executeStatement(
                'UPDATE acces_droit_acces SET credit_restant = credit_restant - 1 WHERE id = UNHEX(:hex) AND credit_restant > 0',
                ['hex' => bin2hex($droitId->toBinary())],
            );
            if ($affectees === 0) {
                return; // solde épuisé, ou vidé par un autre guichet entre la lecture et l'écriture.
            }

            $this->em->refresh($droit);
            $debite = true;
            $this->bousculerSnapshot($droit);
            $this->em->flush();
        });

        if (!$debite) {
            throw new ConflictHttpException('Carte épuisée : plus aucune réservation disponible (CQ-3).');
        }

        return $droit;
    }

    /**
     * Rend une unité à la carte. Silencieux si le droit a disparu ou ne porte plus de crédit : la
     * restitution est appelée depuis des chemins d'annulation qui ne doivent jamais échouer à cause
     * d'elle — annuler une réservation reste possible même si la carte a été supprimée entre-temps.
     */
    public function restituer(?Uuid $droitRef): void
    {
        if ($droitRef === null) {
            return;
        }

        $droit = $this->em->getRepository(DroitAcces::class)->find($droitRef);
        if (!$droit instanceof DroitAcces || $droit->getCreditRestant() === null) {
            return;
        }

        $droitId = $droit->getId();

        $this->connection->transactional(function () use ($droit, $droitId): void {
            $affectees = (int) $this->connection->executeStatement(
                'UPDATE acces_droit_acces SET credit_restant = credit_restant + 1 WHERE id = UNHEX(:hex) AND credit_restant IS NOT NULL',
                ['hex' => bin2hex($droitId->toBinary())],
            );
            if ($affectees === 0) {
                return;
            }

            $this->em->refresh($droit);
            $this->bousculerSnapshot($droit);
            $this->em->flush();
        });
    }

    /**
     * Un terminal hors ligne doit voir le nouveau solde au prochain rafraîchissement : c'est
     * `Support.versionMaj` qui pilote le delta du snapshot (D23, RG-CQ1-03). Sans ce coup de pouce,
     * un lecteur refuserait une carte qu'on vient de créditer — ou en accepterait une qu'on vient
     * de vider.
     */
    private function bousculerSnapshot(DroitAcces $droit): void
    {
        $appairage = $this->em->getRepository(Appairage::class)->findOneBy(['droit' => $droit, 'actif' => true]);
        $support = $appairage?->getSupport();
        if (!$support instanceof Support) {
            return;
        }

        $version = $this->sequencer->suivant();
        $this->connection->executeStatement(
            'UPDATE acces_support SET version_maj = :v WHERE id = UNHEX(:hex)',
            ['v' => $version, 'hex' => bin2hex($support->getId()->toBinary())],
        );
        $support->setVersionMaj($version);
    }
}
