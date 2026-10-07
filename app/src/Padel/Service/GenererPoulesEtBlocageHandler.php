<?php

declare(strict_types=1);

namespace App\Padel\Service;

use App\Padel\Entity\InscriptionTournoi;
use App\Padel\Entity\MatchTournoi;
use App\Padel\Entity\Poule;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Entity\Tournoi;
use App\Padel\Enum\FormatTournoi;
use App\Padel\Enum\StatutMatchTournoi;
use App\Padel\Enum\StatutPaiementInscriptionTournoi;
use App\Padel\Enum\StatutTournoi;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\ChevauchementCreneauGuard;
use App\Reservation\Service\JaugeRessourceMereHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Génère les poules d'un tournoi (répartition des paires payées) et bloque les terrains nécessaires
 * (US-PADEL-05, CA-6). Les créneaux de blocage sont des `Reservation` socle créées par le système
 * (§4.5 spec, « terrains bloqués »), au même titre qu'une réservation normale — le padel **appelle
 * directement les services du socle** (`ChevauchementCreneauGuard`), sans redéfinir le moteur
 * (décision n°8 du plan).
 *
 * ⚠ HYPOTHÈSE — l'algorithme de génération n'est pas précisé par les sources (spec §4.5 point ouvert
 * n°8) : ce lot retient un découpage **séquentiel** (ordre d'inscription) plutôt qu'« aléatoire
 * équilibré par niveau », par souci de déterminisme testable — à confirmer produit (Risque n°7 du plan).
 */
final class GenererPoulesEtBlocageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChevauchementCreneauGuard $guard,
        private readonly JaugeRessourceMereHandler $jaugeMere,
    ) {
    }

    /**
     * @param list<TerrainPadel> $terrains
     *
     * @return list<MatchTournoi>
     */
    public function generer(Tournoi $tournoi, array $terrains, \DateTimeImmutable $debut, int $dureeMinutes, int $tailleMaxPoule = 4): array
    {
        if ($tournoi->getFormat() !== FormatTournoi::Poules) {
            throw new UnprocessableEntityHttpException('Génération de poules réservée au format « poules » (US-PADEL-05).');
        }
        if ($terrains === []) {
            throw new UnprocessableEntityHttpException('Au moins un terrain est requis pour bloquer les créneaux du tournoi.');
        }

        /** @var list<InscriptionTournoi> $inscriptions */
        $inscriptions = $this->em->getRepository(InscriptionTournoi::class)->findBy([
            'tournoi' => $tournoi,
            'statutPaiement' => StatutPaiementInscriptionTournoi::Paye,
        ]);
        if (\count($inscriptions) < 2) {
            throw new UnprocessableEntityHttpException('Au moins 2 paires payées sont requises pour générer des poules.');
        }

        $poules = [];
        $lettres = range('A', 'Z');
        foreach (array_chunk($inscriptions, $tailleMaxPoule) as $index => $groupe) {
            $poule = new Poule();
            $poule->setTournoi($tournoi)->setLibelle('Poule ' . ($lettres[$index] ?? (string) ($index + 1)));
            $this->em->persist($poule);
            foreach ($groupe as $inscription) {
                $inscription->setPoule($poule);
            }
            $poules[] = ['poule' => $poule, 'inscriptions' => $groupe];
        }

        /** @var array<string, \DateTimeImmutable> $prochainCreneauParTerrain */
        $prochainCreneauParTerrain = [];
        $curseurTerrain = 0;
        $matches = [];

        foreach ($poules as $entree) {
            /** @var Poule $poule */
            $poule = $entree['poule'];
            /** @var list<InscriptionTournoi> $paires */
            $paires = $entree['inscriptions'];

            for ($i = 0; $i < \count($paires); ++$i) {
                for ($j = $i + 1; $j < \count($paires); ++$j) {
                    $terrain = $terrains[$curseurTerrain % \count($terrains)];
                    ++$curseurTerrain;
                    $terrainCle = (string) $terrain->getId();
                    $creneauDebut = $prochainCreneauParTerrain[$terrainCle] ?? $debut;
                    $creneauFin = $creneauDebut->modify(sprintf('+%d minutes', $dureeMinutes));

                    $ressource = $terrain->getRessource();
                    if ($ressource === null) {
                        throw new UnprocessableEntityHttpException('Terrain sans ressource socle rattachée.');
                    }
                    if ($this->guard->enConflit($ressource, $creneauDebut, $creneauFin)) {
                        throw new ConflictHttpException('Conflit de créneau lors du blocage des terrains du tournoi (RG-M5-03).');
                    }

                    $creneau = new Creneau();
                    $creneau->setRessource($ressource)
                        ->setDebut($creneauDebut)
                        ->setFin($creneauFin)
                        ->setCapacite($ressource->getCapacitePropre())
                        ->setEtablissement($tournoi->getEtablissement())
                        ->setStatut(StatutCreneau::Planifie);
                    $this->em->persist($creneau);

                    $paireA = $paires[$i];
                    $paireB = $paires[$j];
                    $organisateur = $paireA->getJoueur1();
                    if ($organisateur === null) {
                        throw new UnprocessableEntityHttpException('Paire sans joueur1 : impossible de créer le blocage système.');
                    }

                    $reservation = new Reservation();
                    $reservation->setCreneau($creneau)
                        ->setOrganisateur($organisateur)
                        ->setEtablissement($tournoi->getEtablissement())
                        ->setModeDecompte(ModeDecompteReservation::Gratuit)
                        ->setMontantDu('0.00')
                        ->setStatut(StatutReservation::Confirmee);
                    $this->em->persist($reservation);
                    // Le blocage tient la place autant qu'une réservation : il pèse sur la jauge
                    // globale du terrain, sinon son annulation rendrait une unité jamais posée.
                    $this->jaugeMere->incrementer($ressource);

                    $match = new MatchTournoi();
                    $match->setTournoi($tournoi)
                        ->setPoule($poule)
                        ->setPaireA($paireA)
                        ->setPaireB($paireB)
                        ->setTerrain($terrain)
                        ->setReservationBlocage($reservation)
                        ->setStatut(StatutMatchTournoi::AJouer);
                    $this->em->persist($match);
                    $matches[] = $match;

                    $prochainCreneauParTerrain[$terrainCle] = $creneauFin;
                }
            }
        }

        $tournoi->setStatut(StatutTournoi::EnCours);
        $this->em->flush();

        return $matches;
    }
}
