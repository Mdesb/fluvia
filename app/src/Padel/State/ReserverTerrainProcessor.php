<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Padel\Entity\ReservationPadel;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Enum\StatutPartieOuverte;
use App\Padel\Service\CalculateurTarifTerrainHandler;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\ParticipantReservation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutPaiementParticipant;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\ChevauchementCreneauGuard;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use App\Reservation\Service\ResolveurRegleAnnulation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Réserve un terrain à l'heure (POST /padel/terrains/{id}/reservations, US-PADEL-01, CA-1/CA-2/CA-10).
 * Instancie directement le moteur générique du socle Réservation (`Creneau`/`Reservation`/
 * `ParticipantReservation`, décision structurante n°8 du plan) : aucun second appel HTTP vers
 * `reservation.*`, appel direct aux services. Le tarif est calculé automatiquement (plage × statut,
 * RG-PADEL-02) puis réparti à parts égales entre 1 à 4 joueurs (§4.11).
 *
 * Corps : { "debut": datetime ISO-8601, "dureeMinutes": 60|90, "organisateur": iri|uuid,
 *   "joueurs"?: [iri|uuid,...], "ouverte"?: bool, "niveauViseMin"?: int, "niveauViseMax"?: int,
 *   "avecCoach"?: bool, "coachRessource"?: iri|uuid }
 *
 * @implements ProcessorInterface<mixed, ReservationPadel>
 */
final class ReserverTerrainProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly ChevauchementCreneauGuard $guard,
        private readonly CalculateurTarifTerrainHandler $calculateur,
        private readonly ResolveurRegleAnnulation $resolveurRegle,
        private readonly ProjectionAccesReservationHandler $projectionAcces,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReservationPadel
    {
        \assert($data instanceof TerrainPadel);
        $terrain = $data;
        $ressource = $terrain->getRessource();
        if ($ressource === null) {
            throw new UnprocessableEntityHttpException('Terrain sans ressource socle rattachée.');
        }

        $corps = $this->lecteur->corps();

        $dureeMinutes = isset($corps['dureeMinutes']) ? (int) $corps['dureeMinutes'] : 60;
        if (!\in_array($dureeMinutes, $terrain->getDureesAutoriseesMinutes(), true)) {
            throw new UnprocessableEntityHttpException(sprintf('Durée %d min non autorisée sur ce terrain.', $dureeMinutes));
        }

        $debut = $this->dateTime($corps['debut'] ?? null);
        $fin = $debut->modify(sprintf('+%d minutes', $dureeMinutes));

        $organisateur = $this->resoudre(Beneficiaire::class, $corps['organisateur'] ?? null, 'organisateur');
        \assert($organisateur instanceof Beneficiaire);

        if (!$this->security->isGranted('PERM', 'padel.reserver')) {
            $utilisateur = $this->security->getUser();
            $clientLie = $utilisateur instanceof Utilisateur ? $utilisateur->getClientLie() : null;
            $client = $organisateur->getClient();
            if ($clientLie === null || $client === null || (string) $clientLie !== (string) $client->getId()) {
                throw new UnprocessableEntityHttpException('Un joueur ne peut réserver que pour lui-même (padel.reserver_soi).');
            }
        }

        $ouverte = ($corps['ouverte'] ?? false) === true;

        /** @var list<Beneficiaire> $joueurs */
        $joueurs = [$organisateur];
        foreach ((array) ($corps['joueurs'] ?? []) as $reference) {
            $joueur = $this->resoudre(Beneficiaire::class, $reference, 'joueurs');
            \assert($joueur instanceof Beneficiaire);
            if (!\in_array($joueur, $joueurs, true)) {
                $joueurs[] = $joueur;
            }
        }
        $maxInitial = $ouverte ? 2 : 4;
        if (\count($joueurs) > $maxInitial) {
            throw new UnprocessableEntityHttpException($ouverte
                ? 'Une partie ouverte ne peut être créée qu\'avec 1 ou 2 joueurs déjà inscrits (§4.3).'
                : 'Un terrain padel accueille au maximum 4 joueurs (cahier §2).');
        }

        if ($this->guard->enConflit($ressource, $debut, $fin)) {
            throw new ConflictHttpException('Terrain déjà réservé sur cette fenêtre (RG-PADEL-01, CA-2).');
        }

        $avecCoach = ($corps['avecCoach'] ?? false) === true;
        $coachRessource = null;
        if ($avecCoach) {
            $coachRessource = $this->resoudre(Ressource::class, $corps['coachRessource'] ?? null, 'coachRessource');
            \assert($coachRessource instanceof Ressource);
            if ($this->guard->enConflit($coachRessource, $debut, $fin)) {
                throw new ConflictHttpException('Coach déjà engagé sur une réservation chevauchante (US-PADEL-09, CA-10).');
            }
        }

        $tarif = $this->calculateur->resoudre($terrain, $organisateur, $debut, $dureeMinutes);
        $prix = (float) $tarif->prix;
        if ($avecCoach) {
            $parametrage = $this->calculateur->parametrage($terrain);
            $prix += (float) ($parametrage?->getMajorationCoachMontant() ?? '0.00');
        }
        $montantDu = number_format($prix, 2, '.', '');

        $creneau = new Creneau();
        $creneau->setRessource($ressource)
            ->setDebut($debut)
            ->setFin($fin)
            ->setCapacite($ressource->getCapacitePropre())
            ->setEtablissement($ressource->getEtablissement())
            ->setStatut(StatutCreneau::Planifie);
        $this->em->persist($creneau);

        $reservation = new Reservation();
        $reservation->setCreneau($creneau)
            ->setOrganisateur($organisateur)
            ->setEtablissement($ressource->getEtablissement())
            ->setModeDecompte($prix > 0.0 ? ModeDecompteReservation::VenteUnite : ModeDecompteReservation::Gratuit)
            ->setMontantDu($montantDu);

        $regle = $this->resolveurRegle->resoudre($creneau);
        if ($regle !== null) {
            $reservation->setDateLimiteAnnulation($debut->modify(sprintf('-%d minutes', $regle->getDelaiFrancMinutes())));
        }
        $this->em->persist($reservation);

        $partBase = number_format($prix / 4, 2, '.', '');
        foreach ($joueurs as $index => $joueur) {
            $participant = new ParticipantReservation();
            $participant->setPersonne($joueur)
                ->setEstOrganisateur($index === 0)
                ->setPartMontant($partBase)
                ->setStatutPaiement(StatutPaiementParticipant::EnAttente);
            $reservation->addParticipant($participant);
            $this->em->persist($participant);
        }
        $this->em->flush();

        $this->projectionAcces->projeterSiApplicable($reservation);

        $reservationPadel = new ReservationPadel();
        $reservationPadel->setReservation($reservation)
            ->setTerrain($terrain)
            ->setAvecCoach($avecCoach)
            ->setCoachRessource($coachRessource)
            ->setOuverte($ouverte);

        if ($ouverte) {
            $reservationPadel->setNiveauViseMin(isset($corps['niveauViseMin']) ? (int) $corps['niveauViseMin'] : null);
            $reservationPadel->setNiveauViseMax(isset($corps['niveauViseMax']) ? (int) $corps['niveauViseMax'] : null);
            $reservationPadel->setStatutPartie(\count($joueurs) >= 4 ? StatutPartieOuverte::Complete : StatutPartieOuverte::Ouverte);
        }

        if ($avecCoach && $coachRessource !== null) {
            $creneauCoach = new Creneau();
            $creneauCoach->setRessource($coachRessource)
                ->setDebut($debut)
                ->setFin($fin)
                ->setCapacite($coachRessource->getCapacitePropre())
                ->setEtablissement($coachRessource->getEtablissement())
                ->setStatut(StatutCreneau::Planifie);
            $this->em->persist($creneauCoach);

            $reservationCoach = new Reservation();
            $reservationCoach->setCreneau($creneauCoach)
                ->setOrganisateur($organisateur)
                ->setEtablissement($coachRessource->getEtablissement())
                ->setModeDecompte(ModeDecompteReservation::Gratuit)
                ->setMontantDu('0.00')
                ->setStatut(StatutReservation::Confirmee);
            $this->em->persist($reservationCoach);
            $reservationPadel->setReservationCoach($reservationCoach);
        }

        $this->em->persist($reservationPadel);
        $this->em->flush();

        return $reservationPadel;
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

    private function dateTime(mixed $valeur): \DateTimeImmutable
    {
        if (!\is_string($valeur) || $valeur === '') {
            throw new UnprocessableEntityHttpException('Champ « debut » obligatoire (datetime ISO-8601).');
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException('Champ « debut » invalide (datetime ISO-8601 attendu).');
        }
    }
}
