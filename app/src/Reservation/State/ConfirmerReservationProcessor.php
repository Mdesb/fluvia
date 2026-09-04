<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\VenteReservationHandler;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * CONFIRMER UNE RÉSERVATION — R15 (a).
 *
 * Maxime : « on met un système où il faut une confirmation de la réservation, par exemple 24 heures
 * avant le début de la session, et lors de la confirmation il faut le paiement ».
 *
 * ── ⚠ LE PAIEMENT EST UN POINT D'ACCROCHE, PAS UN BRANCHEMENT ───────────────────────────────────
 *
 * Ses mots : « on est sur un environnement de préproduction, c'est normal qu'il n'y ait aucun mode
 * de paiement. On peut faire les mécanismes. Je te dirai quand on prendra le prestataire. »
 *
 * Donc : si une session de caisse accompagne la confirmation, la vente est créée et rattachée — le
 * cas de l'agent qui encaisse au comptoir, qui marche aujourd'hui. Sinon la confirmation est
 * enregistrée sans encaissement, et le montant reste dû. Le jour où un prestataire est choisi, il
 * se branche ici, sur une réservation déjà confirmée et déjà porteuse de son montant.
 *
 * ⚠ ON NE REFUSE PAS LA CONFIRMATION FAUTE DE PAIEMENT. Refuser interdirait toute confirmation hors
 * comptoir — c'est-à-dire exactement le cas que ce mécanisme existe pour servir.
 *
 * ── ⚠ D8 — L'ÉTABLISSEMENT DE LA SESSION DÉTERMINE CELUI DE LA VENTE ────────────────────────────
 *
 * Cinquième porte de la même famille. Résoudre la session par un `find()` nu ne donnerait pas
 * seulement accès à celle d'un autre établissement : cela y créerait une écriture. Les quatre
 * autres ont été fermées les 19, 23/08 et 04/09. Celle-ci naît fermée, et échoue en 404 — un 403
 * confirmerait que la session existe ailleurs.
 */
final class ConfirmerReservationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly VenteReservationHandler $venteHandler,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reservation
    {
        \assert($data instanceof Reservation);

        // ⚠ ON LIT LES DEUX DATES, PAS LE STATUT. Une réservation annulée ou déjà passée en no-show
        // n'attend plus rien, et son statut le dit — mais il le dit APRÈS coup. Les deux dates
        // répondent exactement à la question posée : une échéance existe, personne ne l'a honorée.
        if ($data->getConfirmationDueAt() === null) {
            throw new UnprocessableEntityHttpException(
                'Cette réservation ne demande aucune confirmation : aucune règle n’en exige pour ce créneau.',
            );
        }

        if ($data->getConfirmedAt() !== null) {
            // ⚠ IDEMPOTENT PLUTÔT QU'EN ERREUR. Un double clic, un rechargement, une relance
            // automatique : refuser produirait une erreur devant quelqu'un qui a bien fait ce qu'il
            // fallait. On rend la réservation telle qu'elle est.
            return $data;
        }

        $corps = $this->lecteur->corps();
        $session = $this->sessionOptionnelle($corps['session'] ?? null);

        $montant = $data->getMontantDu();
        $doitPayer = $data->getModeDecompte() === ModeDecompteReservation::VenteUnite
            && $data->getVenteRattachee() === null
            && (float) $montant > 0.0;

        if ($session !== null && $doitPayer) {
            // ⚠ SANS PRODUIT, ON N'ENCAISSE PAS — mais ON CONFIRME QUAND MÊME. La confirmation est
            // le geste du joueur ; le paramétrage manquant est celui de l'exploitant. Refuser la
            // confirmation entière ferait porter au joueur une faute qui n'est pas la sienne, et
            // laisserait son créneau expirer.
            $produitRef = $this->produitDe($data);
            if ($produitRef === null) {
                throw new UnprocessableEntityHttpException(
                    'Aucun produit tarifaire sur cette activité : impossible de rattacher un encaissement '
                    . 'à la comptabilité. Confirmez sans session de caisse, ou renseignez le produit '
                    . 'tarifaire sur cette activité.',
                );
            }

            $vente = $this->venteHandler->creerVente(
                $session,
                $montant,
                $data->getOrganisateur()?->getClient()?->getId(),
                'Confirmation réservation ' . (string) $data->getId(),
                $produitRef,
            );
            $data->setVenteRattachee($vente);
        }

        $data->setConfirmedAt(new \DateTimeImmutable());
        $data->setStatut(StatutReservation::Confirmee);
        $this->em->flush();

        return $data;
    }

    /**
     * Le produit du catalogue sous lequel ce créneau se vend, s'il est connu.
     *
     * ⚠ `null` EST UNE RÉPONSE, ET ELLE EST MAUVAISE. Sans produit, `VenteReservationHandler` tire
     * un `Uuid::v4()` : une ligne qui désigne un produit inexistant, donc sans catégorie comptable,
     * donc absente de la ventilation. Maxime a tranché que le produit devient obligatoire (§8.8 c,
     * délégué à `allaccess-a9`) — quand cette garde existera, ce chemin lèvera au lieu d'inventer.
     */
    private function produitDe(Reservation $reservation): ?Uuid
    {
        return $reservation->getCreneau()?->getActivite()?->getProduitTarifReference()?->getId();
    }

    private function sessionOptionnelle(mixed $reference): ?SessionCaisse
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        if (!Uuid::isValid($reference)) {
            return null;
        }

        $session = $this->em->getRepository(SessionCaisse::class)->find(Uuid::fromString($reference));
        if ($session === null) {
            throw new NotFoundHttpException('Session introuvable.');
        }

        $actif = $this->contexte->etablissementActif();
        if ((string) $session->getEtablissement()?->getId() !== (string) $actif?->getId()) {
            throw new NotFoundHttpException('Session introuvable.');
        }

        if (!$session->estOuverte()) {
            throw new UnprocessableEntityHttpException('La session de caisse fournie n\'est pas ouverte (RG-M2-01).');
        }

        return $session;
    }
}
