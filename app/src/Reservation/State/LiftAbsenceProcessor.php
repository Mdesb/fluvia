<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Enum\StatutReservation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * LEVER UNE ABSENCE (07/10/2026) — `no_show_facture` → `terminee_sans_constat`.
 *
 * Rien ne sortait une réservation de `no_show_facture` : une absence constatée à tort (la bascule
 * automatique, interdite par D95, a tourné en préprod) le restait, et le reporting la comptait. Ce geste
 * la range dans un état neutre ; il ne rembourse ni ne recrédite rien.
 *
 * ⚠ IL NE SERT JAMAIS À NE PAS FACTURER. L'exonération a son droit (`reservation.exonerer`) et son
 * motif ; tant que la facturation d'absence n'est pas exonérée (`a_facturer`, `facturee`, `contestee`),
 * la levée est refusée. Motif obligatoire, gardé sur la réservation : l'audit porte qui, quand, avant,
 * après et pourquoi.
 *
 * @implements ProcessorInterface<mixed, Reservation>
 */
final class LiftAbsenceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $body,
        private readonly Security $security,
        private readonly CalculateurDroits $rights,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reservation
    {
        // ⚠ PAS D'`assert()` : sur un POST, API Platform ne rend pas 404 quand la lecture ne trouve rien
        // (`ReadProvider`) ; une réservation hors de l'établissement actif arrive ici à `null`. Et le
        // droit se recalcule sur l'établissement DE LA RÉSERVATION, pas sur l'en-tête (D6) : 404, pas 403.
        $user = $this->security->getUser();
        if (!$data instanceof Reservation || !$user instanceof Utilisateur
            || !$this->rights->autorise($this->rights->codesEffectifs($user, $data->getEtablissement()?->getId()), 'reservation', 'lever_absence')) {
            throw new NotFoundHttpException('Réservation introuvable.');
        }

        if ($data->getStatut() !== StatutReservation::NoShowFacture) {
            throw new ConflictHttpException('Seule une absence facturée peut être levée (statut actuel : ' . $data->getStatut()->value . ').');
        }

        $reason = $this->body->corps()['motif'] ?? null;
        $reason = \is_string($reason) ? trim($reason) : '';
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new UnprocessableEntityHttpException('Le motif est obligatoire (255 caractères au plus) pour lever une absence.');
        }

        $billing = $this->em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $data]);
        if ($billing !== null && $billing->getStatut() !== StatutFacturationNoShow::Exoneree) {
            throw new ConflictHttpException('La facturation de cette absence est « ' . $billing->getStatut()->value . ' » : on ne lève une absence qu\'une fois sa facturation exonérée.');
        }

        $data->setStatut(StatutReservation::TermineeSansConstat)->setAbsenceLiftReason($reason);
        $this->em->flush();

        return $data;
    }
}
