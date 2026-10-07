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
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\ConsumedSlotResolver;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Reservation\Service\RequestedQuantityReader;
use App\Reservation\Service\StockCardCreditHandler;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use App\Reservation\Service\QuotaFormuleResolver;
use App\Reservation\Service\ResolveurRegleAnnulation;
use App\Reservation\Service\VenteReservationHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
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
        private readonly ConsumedSlotResolver $creneauxConsommes,
        private readonly JaugeRessourceMereHandler $jaugeMere,
        private readonly RequestedQuantityReader $quantiteDemandee,
        private readonly StockCardCreditHandler $carteStock,
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

        // ⚠ SANS CE REFUS, LA CRÉATION D'OCCURRENCES EN CONFLIT SERAIT UNE RÉGRESSION.
        //
        // `CreerCreneauProcessor` crée désormais les occurrences de récurrence en conflit plutôt que
        // de les perdre en silence, marquées `enAttenteArbitrage` (décision de Maxime du 31/08).
        // Elles chevauchent, par construction, une autre occupation de la même ressource. Sans ce
        // contrôle, elles seraient réservables : deux personnes recevraient le même court à la même
        // heure — exactement ce que RG-M5-03 interdit à la création, obtenu par la porte de derrière.
        //
        // Mesuré avant d'écrire : ce drapeau n'était consulté NULLE PART. Sans conséquence tant que
        // rien ne le posait ; grave à la seconde où quelque chose le pose.
        //
        // Le message dit quoi faire et pas seulement que c'est non : la séance existe, elle attend
        // une décision, et l'écran de planning porte l'arbitrage.
        if ($creneau->isEnAttenteArbitrage()) {
            throw new ConflictHttpException(
                'Cette séance attend un arbitrage : elle chevauche une autre occupation de la même '
                .'ressource. Depuis le planning, choisissez une autre ressource ou confirmez-la telle '
                .'quelle — elle deviendra réservable (RG-M5-11).'
            );
        }

        if (!$this->security->isGranted('PERM', 'reservation.reserver')) {
            $utilisateur = $this->security->getUser();
            $clientLie = $utilisateur instanceof Utilisateur ? $utilisateur->getClientLie() : null;
            $client = $organisateur->getClient();
            if ($clientLie === null || $client === null || (string) $clientLie !== (string) $client->getId()) {
                throw new UnprocessableEntityHttpException('Un client ne peut réserver que pour lui-même (reservation.reserver_soi).');
            }
        }

        $quantite = $this->quantiteDemandee->read($corps);

        $ressource = $creneau->getRessource();

        // ── LE CONTRÔLE DE JAUGE ET L'ÉCRITURE DANS UNE MÊME TRANSACTION, SOUS VERROU ─────────────
        //
        // Le contrôle lisait l'état validé, et la réservation s'écrivait au `flush()` final, hors de
        // toute transaction : deux demandes simultanées sur la dernière place passaient toutes deux.
        // Le serveur refusait bien un créneau complet, sauf à la seconde près où cela comptait.
        //
        // Les créneaux consommés (D33) se résolvent AVANT d'ouvrir la transaction : c'est une lecture,
        // et en REPEATABLE READ la première lecture fige l'instantané. Faite après `beginTransaction()`,
        // elle ferait compter la jauge d'avant l'attente — voir `JaugeCreneauGuard::verrouiller()`.
        //
        // Le débit de la carte et la vente à l'unité s'écrivent DANS la transaction (points de
        // sauvegarde) : un échec les annule avec la réservation. La compensation qui rendait l'unité
        // de carte après un `flush()` raté a donc disparu — elle créditerait désormais une unité que
        // l'annulation a déjà rendue.
        //
        // Pas de `wrapInTransaction()` : il ferme l'EntityManager sur toute exception, y compris un
        // simple 409 « complet », et la requête suivante du même noyau échouerait.
        $consommes = $this->creneauxConsommes->resolve($creneau);
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $this->jauge->verrouiller($consommes, $ressource?->ressourcePorteuseJauge());
            $reservation = $this->reserverSousVerrou($corps, $creneau, $organisateur, $quantite, $ressource, $consommes);
            $this->em->flush();
            $connexion->commit();
        } catch (RetryableException $concurrence) {
            // Interblocage ou attente de verrou expirée : rien n'a été écrit, et la demande est
            // rejouable telle quelle. Un 500 ferait croire à une panne.
            $this->annuler($connexion);

            throw new ConflictHttpException('Une autre réservation était en cours sur ce créneau : la vôtre n\'a pas été enregistrée. Réessayez.', $concurrence);
        } catch (\Throwable $echec) {
            $this->annuler($connexion);

            throw $echec;
        }

        $this->projectionAcces->projeterSiApplicable($reservation);

        return $reservation;
    }

    /**
     * Défait la transaction ouverte par `process()`.
     *
     * Un échec du `rollBack()` lui-même (transaction déjà défaite par le serveur sur un interblocage)
     * ne doit pas masquer l'erreur qui l'a provoqué : c'est elle que l'appelant relance.
     */
    private function annuler(Connection $connexion): void
    {
        if (!$connexion->isTransactionActive()) {
            return;
        }
        try {
            $connexion->rollBack();
        } catch (\Throwable) {
            // L'erreur d'origine est relancée par l'appelant.
        }
    }

    /**
     * Contrôles de jauge, décompte et construction de la réservation — appelé SOUS le verrou posé par
     * `process()`. Ne valide rien : `process()` écrit et valide.
     *
     * @param array<string, mixed> $corps
     * @param list<Creneau>        $consommes
     */
    private function reserverSousVerrou(array $corps, Creneau $creneau, Beneficiaire $organisateur, int $quantite, ?Ressource $ressource, array $consommes): Reservation
    {
        if ($this->jauge->estComplet($creneau) || ($ressource !== null && $this->jaugeMere->jaugeDepassee($ressource))) {
            throw new ConflictHttpException('Créneau complet : seule l\'inscription en liste d\'attente est proposée (RG-M5-01, CA-4).');
        }

        // ACT-1 / D16 — le créneau n'est pas complet, et il peut malgré tout ne pas rester assez de
        // place POUR CETTE demande : trois couverts libres refusent une table de huit. Les deux
        // refus sont distincts, et leur message aussi — « complet » appelle la liste d'attente,
        // « places insuffisantes » appelle une table plus petite ou un autre service.
        if (!$this->jauge->peutAccueillir($creneau, $quantite)) {
            throw new ConflictHttpException(sprintf(
                'Places insuffisantes : %d demandée(s), %d restante(s) sur ce créneau (RG-M5-01, ACT-1).',
                $quantite,
                $this->jauge->placesRestantes($creneau),
            ));
        }
        if ($ressource !== null && $this->jaugeMere->jaugeDepassee($ressource, $quantite)) {
            throw new ConflictHttpException(sprintf(
                'Jauge globale de la ressource dépassée : %d unité(s) demandée(s) (RG-M5-08, CA-14).',
                $quantite,
            ));
        }

        // ACT-1 point 3 / D33 — le créneau visé reste unique, mais la réservation consomme aussi les
        // créneaux des ressources ancêtres qui le couvrent : une table libre ne suffit pas si le
        // service n'a plus de couverts. Résolus avant la transaction (`process()`), contrôlés ici sous
        // verrou, et stockés plus bas — jamais redérivés au contrôle suivant.
        foreach ($consommes as $consomme) {
            if ($this->jauge->peutAccueillir($consomme, $quantite)) {
                continue;
            }
            $englobant = $consomme->getRessource();

            throw new ConflictHttpException(sprintf(
                'Capacité englobante atteinte sur « %s » : %d demandée(s), %d restante(s) (RG-M5-08, D33).',
                $englobant?->getLibelle() ?? 'créneau englobant',
                $quantite,
                $this->jauge->placesRestantes($consomme),
            ));
        }

        $reservation = new Reservation();
        $reservation->setCreneau($creneau)
            ->setOrganisateur($organisateur)
            ->setQuantity($quantite)
            ->setEtablissement($creneau->getEtablissement());

        // CHAINAGE : cette reservation prolonge-t-elle un rendez-vous deja pose ?
        $precedente = $this->resoudreRattachement($corps['rattacherA'] ?? null, $organisateur, $creneau);
        if ($precedente !== null) {
            $groupe = $precedente->getGroupeRendezVous();
            if ($groupe === null) {
                // Le groupe nait au SECOND acte : un rendez-vous d'un seul acte n'a pas besoin d'en
                // porter un, et en creer un a chaque reservation remplirait la colonne de valeurs
                // qui ne relient rien.
                $groupe = Uuid::v4();
                $precedente->setGroupeRendezVous($groupe);
            }
            $reservation->setGroupeRendezVous($groupe);
        }
        foreach ($consommes as $consomme) {
            $reservation->addConsumedSlot($consomme);
        }

        $activite = $creneau->getActivite();
        $carteRef = $this->uuid($corps['carte'] ?? null);
        $service = $carteRef === null && $activite !== null
            ? $this->quotaResolver->resoudre($organisateur->getId(), $activite, new \DateTimeImmutable())
            : null;

        if ($carteRef !== null) {
            // CQ-3 + CQ-6 — carte de N réservations, décomptée ICI et non au passage (D24, arbitrage
            // du 24/08). La carte est **désignée explicitement** par la requête : c'est le cas du
            // comptoir, où l'agent scanne la carte. La résolution automatique « la carte de séances
            // de ce bénéficiaire » demande le rattachement du droit à un porteur (CQ-0), qui n'existe
            // pas encore — la mécanique posée ici n'aura qu'un résolveur à recevoir en amont.
            //
            // La carte désignée l'emporte sur un quota de formule éventuel : l'agent qui la présente
            // exprime une intention, et la deviner autrement serait pire. Question ouverte à
            // claude-A si l'usage montre le contraire.
            $etablissementCarte = $creneau->getEtablissement();
            if ($etablissementCarte === null) {
                throw new UnprocessableEntityHttpException('Créneau sans établissement : carte inutilisable.');
            }
            // Le périmètre vient du créneau, donc de la session serveur — jamais du corps (D3/D8).
            $droitCarte = $this->carteStock->debiter($carteRef, $etablissementCarte);
            $reservation->setModeDecompte(ModeDecompteReservation::CarteStock);
            $reservation->setCreditDroitRef($droitCarte->getId());
            $reservation->setMontantDu('0.00');
        } elseif ($service !== null) {
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
                // ⚠ DEUX `?->` QUI RETOMBAIENT SUR `null`, ET LE HANDLER INVENTAIT UN PRODUIT.
                // Mesuré le 04/09 : les 3 activités de la préproduction sont sans produit tarifaire,
                // donc cette chaîne rendait `null` à tous les coups. La vente partait avec un
                // `Uuid::v4()` — un produit inexistant, invisible à la ventilation comptable.
                $produitRef = $activite?->getProduitTarifReference()?->getId();
                if ($produitRef === null) {
                    throw new UnprocessableEntityHttpException(sprintf(
                        'L\'activité de ce créneau n\'a pas de produit tarifaire : la vente ne peut pas être '
                        . 'rattachée à la comptabilité. Renseignez `produitTarifReference` sur l\'activité%s.',
                        $activite === null ? '' : ' « ' . $activite->getLibelle() . ' »',
                    ));
                }

                // ARRHES OU ACOMPTE : on encaisse le versement, pas le prix plein. Le calcul
                // vit sur l'activite parce que `ConfirmerReservationProcessor` en a besoin aussi.
                $aEncaisser = $activite?->versementAEncaisser($tarif) ?? $tarif;
                $vente = $this->venteHandler->creerVente(
                    $session,
                    $aEncaisser,
                    $clientRef,
                    'Réservation ' . (string) $creneau->getId(),
                    $produitRef,
                    // ⚠ L'ORIGINE DE LA RECETTE (§8.12) : sans elle, « combien la visite guidée
                    // a-t-elle rapporté » reste sans réponse — la ventilation ne voit que la
                    // catégorie comptable du produit.
                    $activite?->getId(),
                    $creneau->getRessource()?->getId(),
                );
                $reservation->setModeDecompte(ModeDecompteReservation::VenteUnite);
                $reservation->setVenteRattachee($vente);
                // `montantDu` RESTE LE PRIX ENTIER, et ce n'est pas un oubli : il a des lecteurs
                // hors de ce module -- le padel le divise par quatre pour partager entre joueurs.
                // Le redefinir en << solde >> changerait ce partage sans que rien ne le dise. Le
                // solde se calcule : `montantDu - versementRetenu`.
                $reservation->setMontantDu($tarif);
                $reservation->setVersementRetenuMontant($aEncaisser === $tarif ? '0.00' : $aEncaisser);
            }
        }

        $regle = $this->resolveurRegle->resoudre($creneau);
        if ($regle !== null) {
            $reservation->setDateLimiteAnnulation($creneau->getDebut()->modify(sprintf('-%d minutes', $regle->getDelaiFrancMinutes())));
        }

        $this->em->persist($reservation);
        if ($ressource !== null) {
            $this->jaugeMere->incrementer($ressource, $quantite);
        }

        return $reservation;
    }

    /**
     * La reservation que celle-ci prolonge, ou `null`.
     *
     * D8 -- UN IDENTIFIANT VENU DU CORPS NE SE RESOUT PAS PAR UN `find()` NU. Trois controles, et
     * chacun ferme une porte differente :
     *
     *   1. MEME ETABLISSEMENT que le creneau vise. Sans lui, on rattache un rendez-vous a celui
     *      d'un autre site -- et l'existence meme de cette reservation devient observable depuis
     *      l'exterieur, ce qui est deja une fuite.
     *   2. MEME BENEFICIAIRE. Chainer le rendez-vous de quelqu'un d'autre au sien n'a aucun sens
     *      metier, et rendrait ses horaires lisibles a travers le groupe.
     *   3. PAS DEJA ANNULEE. Prolonger un rendez-vous qui n'existe plus fabriquerait un groupe dont
     *      le premier acte a disparu -- l'ecran afficherait << 2 prestations >> pour une seule.
     *
     * Un identifiant qui ne passe pas un de ces controles est traite comme ABSENT, pas comme une
     * erreur : la reservation se pose seule. Refuser toute la demande ferait perdre au client un
     * creneau valide a cause d'un lien accessoire.
     */
    private function resoudreRattachement(mixed $reference, Beneficiaire $organisateur, Creneau $creneau): ?Reservation
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            return null;
        }

        $precedente = $this->em->getRepository(Reservation::class)->find($uuid);
        if (!$precedente instanceof Reservation) {
            return null;
        }

        $etabPrecedente = $precedente->getEtablissement()?->getId();
        $etabCreneau = $creneau->getEtablissement()?->getId();
        if ($etabPrecedente === null || $etabCreneau === null || (string) $etabPrecedente !== (string) $etabCreneau) {
            return null;
        }

        $benefPrecedente = $precedente->getOrganisateur()?->getId();
        if ($benefPrecedente === null || (string) $benefPrecedente !== (string) $organisateur->getId()) {
            return null;
        }

        if (!$precedente->getStatut()->occupePlace() && $precedente->getStatut() !== StatutReservation::AConfirmer) {
            return null;
        }

        return $precedente;
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
