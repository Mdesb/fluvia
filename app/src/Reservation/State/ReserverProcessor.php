<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Crm\Entity\Beneficiaire;
use App\Offre\Entity\ServiceInclus;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use App\Reservation\Service\QuotaFormuleResolver;
use App\Reservation\Service\ResolveurRegleAnnulation;
use App\Reservation\Service\VenteReservationHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Réserve une place sur un Créneau (POST /reservation/reservations, US-RES-02/03). Décompte le quota
 * inclus d'une formule si disponible (RG-M5-02, CA-3), sinon déclenche une vente à l'unité (M2) avant
 * confirmation. Refuse si le créneau est complet (CA-4, redirige vers la liste d'attente) ou si la
 * jauge de la ressource mère serait dépassée (RG-M5-08, CA-14).
 *
 * @implements ProcessorInterface<mixed, Reservation>
 */
final class ReserverProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly JaugeCreneauGuard $jauge,
        private readonly JaugeRessourceMereHandler $jaugeMere,
        private readonly QuotaFormuleResolver $quotaResolver,
        private readonly ResolveurRegleAnnulation $resolveurRegle,
        private readonly VenteReservationHandler $venteHandler,
        private readonly ProjectionAccesReservationHandler $projectionAcces,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reservation
    {
        $corps = $this->lecteur->corps();

        $creneau = $this->resoudre(Creneau::class, $corps['creneau'] ?? null, 'creneau');
        \assert($creneau instanceof Creneau);
        $organisateur = $this->resoudre(Beneficiaire::class, $corps['organisateur'] ?? null, 'organisateur');
        \assert($organisateur instanceof Beneficiaire);

        if ($creneau->getStatut() === StatutCreneau::Annule || $creneau->getStatut() === StatutCreneau::Termine) {
            throw new ConflictHttpException('Ce créneau n\'est plus ouvert à la réservation.');
        }

        if (!$this->security->isGranted('PERM', 'reservation.reserver')) {
            $utilisateur = $this->security->getUser();
            $clientLie = $utilisateur instanceof Utilisateur ? $utilisateur->getClientLie() : null;
            $client = $organisateur->getClient();
            if ($clientLie === null || $client === null || (string) $clientLie !== (string) $client->getId()) {
                throw new UnprocessableEntityHttpException('Un client ne peut réserver que pour lui-même (reservation.reserver_soi).');
            }
        }

        $ressource = $creneau->getRessource();
        if ($this->jauge->estComplet($creneau) || ($ressource !== null && $this->jaugeMere->jaugeDepassee($ressource))) {
            throw new ConflictHttpException('Créneau complet : seule l\'inscription en liste d\'attente est proposée (RG-M5-01, CA-4).');
        }

        $reservation = new Reservation();
        $reservation->setCreneau($creneau)
            ->setOrganisateur($organisateur)
            ->setEtablissement($creneau->getEtablissement());

        $activite = $creneau->getActivite();
        $service = $activite !== null ? $this->quotaResolver->resoudre($organisateur->getId(), $activite, new \DateTimeImmutable()) : null;

        if ($service !== null) {
            // Ré-attache une référence gérée par l'EM courant (le port frontière M1/M4 peut renvoyer
            // une entité chargée par un autre contexte, ex. un stub de test) — évite toute ambiguïté
            // Doctrine « nouvelle entité non cascade-persist » sans introduire de cascade persist M5->M1.
            $serviceGere = $this->em->getRepository(ServiceInclus::class)->find($service->getId());
            $reservation->setModeDecompte(ModeDecompteReservation::QuotaFormule);
            $reservation->setServiceInclusRef($serviceGere);
            $reservation->setMontantDu('0.00');
        } else {
            $tarif = $creneau->tarifReference();
            if ((float) $tarif <= 0.0) {
                $reservation->setModeDecompte(ModeDecompteReservation::Gratuit);
                $reservation->setMontantDu('0.00');
            } else {
                $session = $this->resoudreSessionOptionnelle($corps['session'] ?? null);
                if ($session === null) {
                    throw new UnprocessableEntityHttpException('Aucun quota disponible : une vente à l\'unité est nécessaire (session de caisse requise, RG-M5-02).');
                }
                $clientRef = $organisateur->getClient()?->getId();
                $vente = $this->venteHandler->creerVente($session, $tarif, $clientRef, 'Réservation ' . (string) $creneau->getId(), $activite?->getProduitTarifReference()?->getId());
                $reservation->setModeDecompte(ModeDecompteReservation::VenteUnite);
                $reservation->setVenteRattachee($vente);
                $reservation->setMontantDu($tarif);
            }
        }

        $regle = $this->resolveurRegle->resoudre($creneau);
        if ($regle !== null) {
            $reservation->setDateLimiteAnnulation($creneau->getDebut()->modify(sprintf('-%d minutes', $regle->getDelaiFrancMinutes())));
        }

        $this->em->persist($reservation);
        if ($ressource !== null) {
            $this->jaugeMere->incrementer($ressource);
        }
        $this->em->flush();

        $this->projectionAcces->projeterSiApplicable($reservation);

        return $reservation;
    }

    private function resoudreSessionOptionnelle(mixed $reference): ?SessionCaisse
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            return null;
        }

        $session = $this->em->getRepository(SessionCaisse::class)->find($uuid);
        if ($session === null) {
            return null;
        }

        // D8 — la session vient d'un identifiant fourni par le client et etait resolue par un `find()`
        // direct, sans aucun controle. Ce n'est pas qu'une fuite : plus bas, **l'etablissement de la
        // session determine celui de l'objet cree**. Passer la session d'un autre etablissement n'y
        // donnait donc pas seulement acces — cela y creait une ecriture.
        //
        // Troisieme et derniere porte de la meme famille (n10) : les deux autres,
        // `MouvementCaisseProcessor` et `EmettreVenteNoShowProcessor`, ont ete fermees le 19 et le 23/08.
        //
        // Echec ferme en 404 : un 403 confirmerait l'existence de la session ailleurs. Une session sans
        // etablissement echoue aussi — fermeture par defaut.
        $actif = $this->contexte->etablissementActif();
        if ((string) $session->getEtablissement()?->getId() !== (string) $actif?->getId()) {
            throw new NotFoundHttpException('Session introuvable.');
        }

        return $session;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
