<?php

declare(strict_types=1);

namespace App\Platform\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Piscine\DataFixtures\PiscineFixtures;
use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\CreneauBassin;
use App\Piscine\Entity\QualificationEncadrant;
use App\Piscine\Enum\StatutCreneauBassin;
use App\Piscine\Enum\TypeEncadrement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Reservation\Entity\Beneficiaire;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\RevenueRecovery\Entity\RecoveryAttempt;
use App\RevenueRecovery\Entity\RecoveryCase;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\RevenueRecovery\Enum\RecoveryAttemptStatus;
use App\RevenueRecovery\Enum\RecoveryCaseStatus;
use App\RevenueRecovery\Enum\RecoveryChannel;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use App\Stay\Entity\Stay;
use App\Stay\Entity\StayCharge;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\Vente;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * DE QUOI OUVRIR LES CINQ ÉCRANS DE LA NUIT DU 5 SEPTEMBRE ET VOIR QUELQUE CHOSE.
 *
 * Cinq modules ont reçu leur premier écran ; quatre s'ouvraient sur une table vide, parce que leurs
 * mécanismes n'avaient jamais rien produit — faute, précisément, d'un écran pour les déclencher. Un
 * écran vide ne se relit pas : on ne distingue pas « rien à traiter » de « rien ne s'affiche ».
 *
 * ── CE QUE CETTE CLASSE CHERCHE AVANT DE CRÉER ──────────────────────────────────────────────────
 *
 * La préproduction porte déjà 422 articles de stock, 11 ressources, 52 créneaux, 16 clients et 41
 * ventes. On s'y raccroche plutôt que d'inventer un second jeu à côté : une démonstration bâtie sur
 * ses propres objets ne prouve rien du produit réel, et elle double les référentiels.
 *
 * Tout est idempotent — `app:demo:charger` est additif et se relance sans rien détruire ni
 * dupliquer. Les repères sont des valeurs FIXES (références, numéros, dates), jamais « demain » :
 * une date relative rend la recherche-avant-création inopérante dès le lendemain.
 *
 * ── UNE CHAÎNE EST EXERCÉE POUR DE VRAI, L'AUTRE NON, ET C'EST MESURÉ ───────────────────────────
 *
 * Pour Smart Flow, on PUBLIE `slot.released` : le moteur promeut lui-même la bonne inscription et
 * crée la proposition. C'est la meilleure preuve possible — et c'est sûr, parce qu'un seul abonné
 * écoute cet événement (`SlotReleasedListener`, vérifié).
 *
 * Pour la relance des recettes, on construit le dossier À LA MAIN. `payment.failed` traverse aussi
 * le catalogue de notifications (`Platform\Notification\NotificationRule`) : publier depuis une
 * fixture réveillerait un mécanisme qui n'a rien à voir avec ce qu'on démontre. Le moteur de relance
 * a ses propres tests ; le rôle de cette classe est de remplir un écran, pas de re-prouver un
 * moteur.
 *
 * ── ET DEUX DÉTAILS DE LA DÉMONSTRATION SONT DES DÉMONSTRATIONS ─────────────────────────────────
 *
 * La première tentative de relance est ÉCHUE (programmée avant-hier, toujours « à envoyer ») : c'est
 * ce qui fait apparaître le bandeau que l'écran dérive de ses données, celui qui signale que rien
 * n'envoie. Et les deux inscriptions en liste d'attente ont des fenêtres DIFFÉRENTES, dont une seule
 * couvre le créneau libéré : la place doit revenir à la seconde, pas à la première. Sans ce
 * décalage, on ne verrait pas que la fenêtre est honorée depuis le 05/09.
 */
final class ScreenWalkthroughFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    /** Repères fixes : c'est par eux que la relance retrouve ce qu'elle a déjà créé. */
    public const SEJOUR_CLOS = 'SEJ-DEMO-CLOS';
    public const AVOIR_NUMERO = 'AV-DEMO-001';
    public const SUJET_RELANCE = 'demo-paiement-refuse-001';

    /** Le créneau libéré de la démonstration. Date FIXE, sinon la recherche-avant-création échoue. */
    private const CRENEAU_LIBERE = '2026-12-01 10:00:00';
    private const CRENEAU_BASSIN = '2026-12-02 09:00:00';

    public function __construct(
        private readonly EventBus $bus,
    ) {
    }

    /** @return list<class-string> */
    public function getDependencies(): array
    {
        return [SocleFixtures::class, PiscineFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $etab = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        if (!$etab instanceof Etablissement) {
            return;
        }

        $this->ouvrirLesDeuxMenus($manager);
        $this->relanceDesRecettes($manager, $etab);
        $this->placesLiberees($manager, $etab);
        $this->sejourClosAvecSolde($manager, $etab);
        $this->creneauSurveilleEtDiplomeExpire($manager, $etab);
        $this->retourClient($manager, $etab);

        $manager->flush();
    }

    /**
     * ⚠ SANS CECI, DEUX ÉCRANS SONT INVISIBLES — Y COMPRIS POUR UN ADMINISTRATEUR.
     *
     * `RevenueRecoveryFixtures` et `SmartFlowFixtures` créent leurs permissions et ne les donnent à
     * personne, là où `StayFixtures` les accorde. C'est ce qui explique le constat du 05/09 : trois
     * modules sur quarante avaient des droits que zéro rôle portait, et c'étaient les deux plus
     * récents. On aligne sur la convention du dépôt.
     */
    private function ouvrirLesDeuxMenus(ObjectManager $manager): void
    {
        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if (!$roleAdmin instanceof Role) {
            return;
        }

        $droits = [
            'revenue_recovery' => ['read', 'configure', 'manage'],
            'smart_flow' => ['read', 'reschedule_manage', 'reschedule_read_own', 'manage'],
        ];

        foreach ($droits as $module => $actions) {
            foreach ($actions as $action) {
                $permission = $manager->getRepository(Permission::class)
                    ->findOneBy(['module' => $module, 'action' => $action]);
                if (!$permission instanceof Permission) {
                    $permission = $this->permissionNommee($manager, $module, $action);
                    $manager->persist($permission);
                }
                $roleAdmin->addPermission($permission);
            }
        }
    }

    /**
     * Une politique ACTIVE, un dossier ouvert, trois relances dont une échue.
     *
     * La politique est active parce qu'une politique inactive ne montre rien : le moteur sort sans
     * rien faire, et l'écran affiche le même vide qu'avant. Aucun courriel ne peut partir pour
     * autant — la tâche d'envoi n'est pas dans la liste blanche du lanceur, et `MAILER_DSN` vaut
     * `null://null` sur cette instance.
     */
    private function relanceDesRecettes(ObjectManager $manager, Etablissement $etab): void
    {
        $sequence = $manager->getRepository(RecoverySequence::class)
            ->findOneBy(['establishment' => $etab, 'triggerType' => RecoveryTriggerType::PaymentFailed]);

        if (!$sequence instanceof RecoverySequence) {
            $sequence = (new RecoverySequence())
                ->setEstablishment($etab)
                ->setTriggerType(RecoveryTriggerType::PaymentFailed)
                ->setActive(true)
                ->setMaxAttempts(3)
                ->setSteps([
                    ['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'revenue_recovery.payment_failed'],
                    ['delayDays' => 3, 'channel' => 'email', 'templateCode' => 'revenue_recovery.payment_failed'],
                    ['delayDays' => 7, 'channel' => 'email', 'templateCode' => 'revenue_recovery.payment_failed'],
                ]);
            $manager->persist($sequence);
        }

        $dossier = $manager->getRepository(RecoveryCase::class)
            ->findOneBy(['establishment' => $etab, 'subjectRef' => self::SUJET_RELANCE]);

        if ($dossier instanceof RecoveryCase) {
            return;
        }

        $ouvertLe = new \DateTimeImmutable('-2 days');
        $dossier = (new RecoveryCase())
            ->setEstablishment($etab)
            ->setTriggerType(RecoveryTriggerType::PaymentFailed)
            ->setSubjectType('Paiement')
            ->setSubjectRef(self::SUJET_RELANCE)
            ->setAmountCents(4500)
            ->setStatus(RecoveryCaseStatus::Active)
            ->setSequence($sequence)
            ->setOpenedAt($ouvertLe);
        $manager->persist($dossier);

        // ⚠ LA PREMIÈRE EST ÉCHUE, ET C'EST VOULU. Programmée hier, toujours « à envoyer » : c'est
        // exactement ce que l'écran compte pour signaler que rien n'envoie. Une démonstration où
        // toutes les tentatives seraient à venir cacherait le seul avertissement qui compte.
        foreach ([[0, '-1 day'], [1, '+1 day'], [2, '+5 days']] as [$index, $quand]) {
            $tentative = (new RecoveryAttempt())
                ->setRecoveryCase($dossier)
                ->setStepIndex($index)
                ->setScheduledAt($ouvertLe->modify($quand))
                ->setChannel(RecoveryChannel::Email)
                ->setStatus(RecoveryAttemptStatus::Pending);
            $manager->persist($tentative);
        }
    }

    /**
     * Deux personnes en attente sur une ressource, une place qui se libère, et la fenêtre qui tranche.
     *
     * ⚠ LE RANG 1 NE DOIT PAS ÊTRE SERVI. Sa fenêtre de recherche ne couvre pas le créneau libéré ;
     * celle du rang 2 le couvre. C'est le correctif du 05/09 : jusque-là la promotion ordonnait par
     * rang sans jamais lire la fenêtre. Si l'écran montre le rang 1 promu, le correctif est défait.
     */
    private function placesLiberees(ObjectManager $manager, Etablissement $etab): void
    {
        $creneau = $this->creneauDeDemonstration($manager, $etab);
        if (!$creneau instanceof Creneau) {
            return;
        }

        $ressource = $creneau->getRessource();
        if (!$ressource instanceof Ressource) {
            return;
        }

        /** @var list<Beneficiaire> $beneficiaires */
        $beneficiaires = $manager->getRepository(Beneficiaire::class)->findBy([], ['id' => 'ASC'], 2);
        if (\count($beneficiaires) < 2) {
            return;
        }

        $fenetres = [
            // Rang 1 : cherche au printemps — ce créneau de décembre ne l'intéresse pas.
            [1, '2027-03-01 00:00:00', '2027-03-31 23:59:59'],
            // Rang 2 : cherche justement autour de cette date.
            [2, '2026-11-15 00:00:00', '2026-12-15 23:59:59'],
        ];

        $posee = false;
        foreach ($fenetres as $i => [$rang, $debut, $fin]) {
            $existante = $manager->getRepository(SlotWaitlistEntry::class)->findOneBy([
                'establishment' => $etab,
                'resourceId' => $ressource->getId(),
                'beneficiaryId' => $beneficiaires[$i]->getId(),
            ]);
            if ($existante instanceof SlotWaitlistEntry) {
                continue;
            }

            $manager->persist(
                (new SlotWaitlistEntry())
                    ->setEstablishment($etab)
                    ->setResourceId($ressource->getId())
                    ->setBeneficiaryId($beneficiaires[$i]->getId())
                    ->setSearchWindowStart(new \DateTimeImmutable($debut))
                    ->setSearchWindowEnd(new \DateTimeImmutable($fin))
                    ->setRank($rang)
                    ->setStatus(SlotWaitlistEntryStatus::Waiting),
            );
            $posee = true;
        }

        if (!$posee) {
            return;
        }

        // Les inscriptions doivent exister en base avant que le moteur ne les relise : il interroge
        // par DQL, pas l'unité de travail.
        $manager->flush();

        // ⚠ ON PUBLIE L'ÉVÉNEMENT PLUTÔT QUE DE FABRIQUER LA PROPOSITION. Le moteur promeut alors
        // lui-même, ce qui prouve la chaîne au lieu de la mimer. Sûr : `SlotReleasedListener` est le
        // seul abonné à `slot.released`. Et idempotent : la trace de libération porte une contrainte
        // d'unicité, et une seconde publication ne trouve plus d'inscription « en attente ».
        $this->bus->publish(new DomainEvent(
            'slot.released',
            new EventTenant($etab->getId()),
            new EventSubject('Slot', (string) $creneau->getId()),
            ['slot' => (string) $creneau->getId(), 'resource' => (string) $ressource->getId()],
        ));
    }

    /**
     * Un séjour CLOS avec un solde — le cas que le produit rendait impossible jusqu'au 05/09.
     *
     * Clôturer avant d'encaisser enfermait le séjour : plus aucune ligne, donc plus de règlement,
     * donc jamais soldable. Ce séjour-là existe pour qu'on vérifie l'inverse : la note s'ouvre sur un
     * séjour clos, et « Enregistrer un règlement » y est proposé.
     */
    private function sejourClosAvecSolde(ObjectManager $manager, Etablissement $etab): void
    {
        $existant = $manager->getRepository(Stay::class)->findOneBy(['reference' => self::SEJOUR_CLOS]);
        if ($existant instanceof Stay) {
            return;
        }

        $client = $manager->getRepository(Client::class)->findOneBy(['etablissementCreation' => $etab]);
        if (!$client instanceof Client) {
            return;
        }

        $arrivee = new \DateTimeImmutable('-4 days');
        $sejour = new Stay($etab, $client, self::SEJOUR_CLOS, $arrivee, $arrivee);
        $manager->persist($sejour);

        foreach ([['Nuitée', '35.00'], ['Bar — 2 demis', '7.50']] as $i => [$libelle, $montant]) {
            $manager->persist(new StayCharge(
                $sejour,
                $libelle,
                $montant,
                $arrivee->modify(sprintf('+%d hours', $i + 1)),
                'manual',
                'manual.entry',
                'demo-clos-' . $i,
            ));
        }

        // Le départ est enregistré ; le règlement ne l'est pas. C'est tout l'objet de ce séjour.
        $sejour->close(new \DateTimeImmutable('-1 day'));
    }

    /**
     * Un créneau de bassin qui EXIGE un encadrement, et un diplôme périmé à côté d'un valide.
     *
     * Sans créneau surveillé, ni « Affecter » ni le refus RG-PISC-02 ne sont visibles : la
     * préproduction n'en portait aucun. Le diplôme périmé, lui, montre que l'écran distingue « ne
     * couvre plus » de « n'existe pas » — il reste affiché, signalé, jamais supprimé.
     */
    private function creneauSurveilleEtDiplomeExpire(ObjectManager $manager, Etablissement $etab): void
    {
        $bassin = $manager->getRepository(Bassin::class)->findOneBy(['etablissement' => $etab]);
        if (!$bassin instanceof Bassin) {
            return;
        }

        $debut = new \DateTimeImmutable(self::CRENEAU_BASSIN);
        $existant = $manager->getRepository(CreneauBassin::class)->findOneBy(['bassin' => $bassin, 'debut' => $debut]);
        if (!$existant instanceof CreneauBassin) {
            $manager->persist(
                (new CreneauBassin())
                    ->setBassin($bassin)
                    ->setDebut($debut)
                    ->setFin($debut->modify('+2 hours'))
                    ->setEncadrantRequis(TypeEncadrement::Mns)
                    ->setStatut(StatutCreneauBassin::Brouillon),
            );
        }

        $admin = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        if (!$admin instanceof Utilisateur) {
            return;
        }

        $perime = $manager->getRepository(QualificationEncadrant::class)
            ->findOneBy(['encadrant' => $admin, 'type' => TypeEncadrement::Bnssa]);
        if ($perime instanceof QualificationEncadrant) {
            return;
        }

        $manager->persist(
            (new QualificationEncadrant())
                ->setEncadrant($admin)
                ->setType(TypeEncadrement::Bnssa)
                ->setDateValidite(new \DateTimeImmutable('-1 month'))
                ->setEtablissement($etab),
        );
    }

    /**
     * Un avoir, pour que l'onglet « Retours clients » ait une ligne à traiter.
     *
     * Il s'adosse à une vente réelle de la préproduction : un avoir sans origine ne ressemble à rien
     * de ce qu'un exploitant verra.
     */
    private function retourClient(ObjectManager $manager, Etablissement $etab): void
    {
        $existant = $manager->getRepository(Avoir::class)->findOneBy(['numero' => self::AVOIR_NUMERO]);
        if ($existant instanceof Avoir) {
            return;
        }

        $vente = $manager->getRepository(Vente::class)->findOneBy(['etablissement' => $etab]);
        $auteur = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);

        $avoir = (new Avoir())
            ->setNumero(self::AVOIR_NUMERO)
            ->setMontant('19.90')
            ->setMotif('Article rendu en bon état — à réintégrer en stock')
            ->setNature('remboursement')
            ->setEtablissement($etab);

        if ($vente instanceof Vente) {
            $avoir->setVenteOrigine($vente);
        }
        if ($auteur instanceof Utilisateur) {
            $avoir->setAuteur($auteur);
        }

        $manager->persist($avoir);
    }

    /** Le créneau de réservation qu'on libère — cherché d'abord, créé seulement s'il manque. */
    private function creneauDeDemonstration(ObjectManager $manager, Etablissement $etab): ?Creneau
    {
        $debut = new \DateTimeImmutable(self::CRENEAU_LIBERE);

        $existant = $manager->getRepository(Creneau::class)->findOneBy(['etablissement' => $etab, 'debut' => $debut]);
        if ($existant instanceof Creneau) {
            return $existant;
        }

        $ressource = $manager->getRepository(Ressource::class)->findOneBy(['etablissement' => $etab]);
        if (!$ressource instanceof Ressource) {
            return null;
        }

        $creneau = (new Creneau())
            ->setRessource($ressource)
            ->setEtablissement($etab)
            ->setDebut($debut)
            ->setFin($debut->modify('+1 hour'))
            ->setCapacite(1)
            ->setStatut(StatutCreneau::Planifie);
        $manager->persist($creneau);

        return $creneau;
    }
}
