<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
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
use App\Reservation\Service\VenteReservationHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        private readonly VenteReservationHandler $venteHandler,
        private readonly ContexteEtablissement $contexte,
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
        // ⚠ LE PARAMÉTRAGE EST RÉSOLU DANS TOUS LES CAS, ET PLUS SEULEMENT AVEC UN COACH. Il porte
        // aussi `produitTerrainRef`, le produit du catalogue sous lequel le créneau se vend : le
        // laisser dans le `if` ci-dessous rendait la vente impossible pour une partie sans coach,
        // c'est-à-dire pour la quasi-totalité d'entre elles.
        $parametrage = $this->calculateur->parametrage($terrain);
        if ($avecCoach) {
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

        // ⚠ LE CRÉNEAU DEVIENT UNE LIGNE COMPTABLE — c'était tout l'objet de R15. Sans ce bloc, la
        // réservation portait `VenteUnite` et un montant dû, et RIEN ne les transformait jamais en
        // écriture : ni ici, ni au paiement d'une part (`PayerPartProcessor` ne fait qu'un
        // changement de statut, par conception documentée).
        //
        // Le produit vient de `ParametragePadel::$produitTerrainRef`, qui existait déjà et que
        // personne ne lisait. Sans lui, `VenteReservationHandler` tire un `Uuid::v4()` au hasard :
        // une ligne qui désigne un produit inexistant, donc sans catégorie comptable, donc
        // invisible à la ventilation.
        $session = $this->sessionOptionnelle($corps['session'] ?? null);
        if ($session !== null && $prix > 0.0) {
            $vente = $this->venteHandler->creerVente(
                $session,
                $montantDu,
                $organisateur->getClient()?->getId(),
                'Réservation terrain ' . (string) $creneau->getId(),
                $parametrage?->getProduitTerrainRef(),
            );
            $reservation->setVenteRattachee($vente);
        }

        $regle = $this->resolveurRegle->resoudre($creneau);
        if ($regle !== null) {
            $reservation->setDateLimiteAnnulation($debut->modify(sprintf('-%d minutes', $regle->getDelaiFrancMinutes())));
        }

        // ── LA CONFIRMATION, QUAND L'EXPLOITANT EN DEMANDE UNE (R15 a) ─────────────────────────
        //
        // Maxime : « il faut une confirmation de la réservation, par exemple 24 heures avant le
        // début de la session… pour toutes les réservations de moins de 24 heures, le paiement est
        // demandé dès le départ ».
        //
        // ⚠ INERTE PAR DÉFAUT. Aucune `RegleAnnulation` ne déclare de délai aujourd'hui : ce bloc
        // ne s'exécute pas, et la réservation reste `Confirmee` comme avant. Rien ne bascule tant
        // qu'un exploitant ne l'active pas, avec la portée qu'il choisit déjà pour l'annulation.
        $delaiConfirmation = $regle?->getConfirmationDelayMinutes();
        if ($delaiConfirmation !== null) {
            $echeance = $debut->modify(sprintf('-%d minutes', $delaiConfirmation));

            // ⚠ RÉSERVÉ APRÈS L'ÉCHÉANCE = À CONFIRMER TOUT DE SUITE, et non une échéance dans le
            // passé. C'est exactement le cas que Maxime a nommé : « pour toutes les réservations de
            // moins de 24 heures, le paiement est demandé dès le départ ». Une échéance passée
            // ferait expirer la réservation à la seconde où elle est prise.
            $maintenant = new \DateTimeImmutable();
            $reservation->setConfirmationDueAt($echeance > $maintenant ? $echeance : $maintenant);
            $reservation->setStatut(StatutReservation::AConfirmer);
        }
        $this->em->persist($reservation);

        // ⚠ LES PARTS SONT INDICATIVES, ET SEUL L'ORGANISATEUR DOIT. Arbitrage de Maxime sur
        // R15 (b) : « l'organisateur reste redevable du montant global ». Laisser les trois autres
        // en `EnAttente` afficherait trois impayés que personne ne réclamera jamais — et ferait
        // relancer des joueurs qui ne doivent rien.
        //
        // La créance réelle est `Reservation::$montantDu`, portée par la réservation elle-même.
        $partBase = number_format($prix / 4, 2, '.', '');
        foreach ($joueurs as $index => $joueur) {
            $organisateurDuGroupe = $index === 0;
            $participant = new ParticipantReservation();
            $participant->setPersonne($joueur)
                ->setEstOrganisateur($organisateurDuGroupe)
                ->setPartMontant($partBase)
                ->setStatutPaiement($organisateurDuGroupe
                    ? StatutPaiementParticipant::EnAttente
                    : StatutPaiementParticipant::ImputeOrganisateur);
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

    /**
     * La session de caisse fournie par l'appelant, ou `null` si aucune n'accompagne la réservation.
     *
     * ⚠ D8 — QUATRIÈME PORTE DE LA MÊME FAMILLE, ET ELLE S'OUVRAIT EN AJOUTANT CE PARAMÈTRE.
     * L'établissement de la session détermine celui de la vente créée. Résoudre par un `find()` nu
     * ne donnerait pas seulement accès à la session d'un autre établissement : cela y créerait une
     * écriture. Les trois autres portes (`ReserverProcessor`, `MouvementCaisseProcessor`,
     * `EmettreVenteNoShowProcessor`) ont été fermées les 19 et 23/08 ; celle-ci naît fermée.
     *
     * Échec en 404 et non en 403 : un 403 confirmerait que la session existe ailleurs.
     *
     * ⚠ ET LA SESSION DOIT ÊTRE OUVERTE (RG-M2-01). `VenteDiffereeAgentStrategie` le vérifie,
     * `ReserverProcessor` ne le fait pas — on suit ici le plus strict des deux : encaisser sur une
     * session close produirait une vente qu'aucune clôture ne rattraperait.
     */
    private function sessionOptionnelle(mixed $reference): ?SessionCaisse
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            return null;
        }

        $session = $this->em->getRepository(SessionCaisse::class)->find($uuid);
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
