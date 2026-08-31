<?php

declare(strict_types=1);

namespace App\Opening\Service;

use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Etablissement;
use App\Opening\Entity\OpeningException;
use App\Opening\Entity\OpeningSlot;
use App\Opening\Entity\OpeningSetting;
use App\Opening\Enum\OpeningExceptionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * QUAND LE SITE EST-IL OUVERT — la seule réponse, pour l'agenda comme pour le contrôle d'accès.
 *
 * ── UN SEUL CALCUL, DEUX APPELANTS, ET C'EST LE POINT ───────────────────────────────────────────
 *
 * L'agenda dessine les plages ; `ValidationPassageHandler` refuse hors plages. Si les deux
 * calculaient chacun de leur côté, l'écran finirait par montrer une heure d'ouverture pendant
 * laquelle la porte refuse — le pire des deux mondes, parce que l'exploitant aurait sous les yeux
 * la preuve écrite qu'il devrait pouvoir entrer.
 *
 * ── LE FUSEAU N'EST PAS UN DÉTAIL ───────────────────────────────────────────────────────────────
 *
 * Les horaires sont saisis en heure LOCALE — « on ouvre à 9 h » veut dire 9 h à la porte. Le
 * passage, lui, arrive horodaté par une borne ou par le serveur, en UTC. Comparer les deux sans
 * conversion décale toute la grille de deux heures l'été : un site refuserait ses adhérents jusqu'à
 * 11 h et laisserait entrer jusqu'à 20 h. Le conteneur PHP du projet tourne en UTC — ce piège a
 * déjà horodaté des migrations à contresens le 24/08. On convertit donc AVANT toute comparaison,
 * dans le fuseau que porte l'établissement.
 *
 * ── LA TRANCHE QUI TRAVERSE MINUIT SE LIT SUR DEUX JOURS ────────────────────────────────────────
 *
 * Une tranche « samedi 22 h → 02 h » couvre une heure du DIMANCHE. Chercher les tranches du seul
 * jour demandé la manquerait, et un passage à 1 h du matin serait refusé alors que la soirée est en
 * cours. On lit donc toujours la veille en plus, et on ne garde que ce qui recoupe le jour visé.
 */
final readonly class OpeningCalendar
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Le planning fait-il loi au contrôle d'accès pour ce site ?
     *
     * L'ABSENCE DE RÉGLAGE VAUT « NON », et c'est le défaut voulu (voir `OpeningSetting`) : on ne
     * provisionne rien à l'ouverture d'un client, et aucun site existant ne se met à refuser du
     * monde parce qu'on a déployé ce module.
     */
    public function isEnforced(?Etablissement $etablissement): bool
    {
        if ($etablissement === null) {
            return false;
        }

        $reglage = $this->em->getRepository(OpeningSetting::class)
            ->findOneBy(['establishment' => $etablissement]);

        return $reglage instanceof OpeningSetting && $reglage->isEnforced();
    }

    /**
     * LA QUESTION QUE POSE LE CONTRÔLE D'ACCÈS : ce passage est-il autorisé par le planning ?
     *
     * Rend `true` dans tous les cas où le planning ne s'applique pas — pas d'établissement, réglage
     * absent ou décoché. **Un module qui ne fait pas loi ne doit jamais refuser**, et cette méthode
     * est le seul endroit où les deux questions (« est-ce appliqué ? » et « est-ce ouvert ? ») sont
     * jointes. Les séparer chez l'appelant ferait porter la décision à chaque appelant futur.
     */
    public function autoriseLePassage(?Etablissement $etablissement, \DateTimeImmutable $moment, ?EspaceAcces $espace = null): bool
    {
        if (!$this->isEnforced($etablissement)) {
            return true;
        }

        \assert($etablissement instanceof Etablissement);

        return $this->estOuvert($etablissement, $moment, $espace);
    }

    /** Le site (ou l'espace) est-il ouvert à cet instant précis ? */
    public function estOuvert(Etablissement $etablissement, \DateTimeImmutable $moment, ?EspaceAcces $espace = null): bool
    {
        $local = $moment->setTimezone(new \DateTimeZone($etablissement->getFuseauHoraire()));

        foreach ($this->fenetresDuJour($etablissement, $local, $espace) as $fenetre) {
            if ($local >= $fenetre['start'] && $local < $fenetre['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les fenêtres d'ouverture entre deux dates, pour l'agenda.
     *
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, libelle: ?string, jour: string}>
     */
    public function fenetres(
        Etablissement $etablissement,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
        ?EspaceAcces $espace = null,
    ): array {
        $fuseau = new \DateTimeZone($etablissement->getFuseauHoraire());
        $curseur = $du->setTimezone($fuseau)->setTime(0, 0);
        $fin = $au->setTimezone($fuseau)->setTime(0, 0);

        $toutes = [];
        // Borne dure : un agenda demande une semaine ou un mois. Au-delà de 400 jours, c'est une
        // requête qui part en vrille — on préfère une réponse tronquée à une machine à genoux.
        $gardeFou = 0;
        while ($curseur <= $fin && $gardeFou++ < 400) {
            foreach ($this->fenetresDuJour($etablissement, $curseur, $espace) as $fenetre) {
                $toutes[] = $fenetre + ['day' => $curseur->format('Y-m-d')];
            }
            $curseur = $curseur->modify('+1 day');
        }

        return $toutes;
    }

    /**
     * Les fenêtres d'ouverture qui recoupent LE JOUR de `$local`, dans le fuseau de l'établissement.
     *
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, libelle: ?string}>
     */
    private function fenetresDuJour(Etablissement $etablissement, \DateTimeImmutable $local, ?EspaceAcces $espace): array
    {
        $debutJour = $local->setTime(0, 0);
        $finJour = $debutJour->modify('+1 day');
        $veille = $debutJour->modify('-1 day');

        $plages = $this->plagesGouvernantes($etablissement, $espace);

        $fenetres = [];
        // La veille EN PLUS du jour visé : une tranche « 22 h → 02 h » de la veille couvre les deux
        // premières heures d'aujourd'hui.
        foreach ([$veille, $debutJour] as $origine) {
            $jourIso = (int) $origine->format('N');
            foreach ($plages as $plage) {
                if ($plage->getWeekday() !== $jourIso) {
                    continue;
                }
                $debut = $this->a($origine, $plage->getStartTime());
                $finBrute = $this->a($origine, $plage->getEndTime());
                $finReelle = $plage->isOvernight() ? $finBrute->modify('+1 day') : $finBrute;
                if ($finReelle <= $debutJour || $debut >= $finJour) {
                    continue;
                }
                $fenetres[] = [
                    'start' => max($debut, $debutJour),
                    'end' => min($finReelle, $finJour),
                    'label' => $plage->getLabel(),
                ];
            }
        }

        foreach ($this->exceptionsDuJour($etablissement, $debutJour, $espace) as $exception) {
            if ($exception->getType() !== OpeningExceptionType::SpecialOpening) {
                continue;
            }
            $debut = $this->a($debutJour, $exception->getStartTime() ?? new \DateTimeImmutable('00:00:00'));
            $finExc = $this->a($debutJour, $exception->getEndTime() ?? new \DateTimeImmutable('23:59:59'));
            if ($finExc > $debut) {
                $fenetres[] = ['start' => $debut, 'end' => $finExc, 'label' => $exception->getReason()];
            }
        }

        // LES FERMETURES SE RETRANCHENT EN DERNIER, ET C'EST L'ORDRE QUI COMPTE.
        //
        // Une fermeture posée avant l'ouverture exceptionnelle serait annulée par elle : un jour
        // férié rouvert par une nocturne saisie l'an dernier. La fermeture est la décision la plus
        // récente et la plus explicite ; elle passe donc après tout le reste.
        foreach ($this->exceptionsDuJour($etablissement, $debutJour, $espace) as $exception) {
            if ($exception->getType() !== OpeningExceptionType::Closure) {
                continue;
            }
            if ($exception->isAllDay()) {
                return [];
            }
            $fenetres = $this->retrancher(
                $fenetres,
                $this->a($debutJour, $exception->getStartTime() ?? new \DateTimeImmutable('00:00:00')),
                $this->a($debutJour, $exception->getEndTime() ?? new \DateTimeImmutable('23:59:59')),
            );
        }

        return $fenetres;
    }

    /**
     * LE PLUS PRÉCIS L'EMPORTE, ET IL L'EMPORTE ENTIÈREMENT.
     *
     * Dès qu'un espace porte au moins une tranche, c'est SON planning qui le gouverne — pas
     * l'intersection avec celui du site. Une règle qui se raconte en une phrase ; une intersection
     * obligerait à tenir deux plannings en tête pour répondre à « à quelle heure ferme le bassin ? ».
     *
     * @return list<OpeningSlot>
     */
    private function plagesGouvernantes(Etablissement $etablissement, ?EspaceAcces $espace): array
    {
        if ($espace !== null) {
            /** @var list<OpeningSlot> $propres */
            $propres = $this->em->getRepository(OpeningSlot::class)
                ->findBy(['establishment' => $etablissement, 'space' => $espace]);
            if ($propres !== []) {
                return $propres;
            }
        }

        /** @var list<OpeningSlot> $duSite */
        $duSite = $this->em->getRepository(OpeningSlot::class)
            ->findBy(['establishment' => $etablissement, 'space' => null]);

        return $duSite;
    }

    /**
     * Les exceptions du jour : celles du site ET celles de l'espace.
     *
     * ⚠ Contrairement aux tranches, l'exception du site N'EST PAS écrasée par celle de l'espace.
     * Un 1er janvier ferme le bâtiment, donc chacune de ses portes ; laisser une tranche d'espace
     * survivre à la fermeture du site produirait un bassin ouvert dans un centre fermé.
     *
     * @return list<OpeningException>
     */
    private function exceptionsDuJour(Etablissement $etablissement, \DateTimeImmutable $jour, ?EspaceAcces $espace): array
    {
        /** @var list<OpeningException> $duSite */
        $duSite = $this->em->getRepository(OpeningException::class)
            ->findBy(['establishment' => $etablissement, 'space' => null, 'date' => $jour->setTime(0, 0)]);

        if ($espace === null) {
            return $duSite;
        }

        /** @var list<OpeningException> $deLEspace */
        $deLEspace = $this->em->getRepository(OpeningException::class)
            ->findBy(['establishment' => $etablissement, 'space' => $espace, 'date' => $jour->setTime(0, 0)]);

        return array_merge($duSite, $deLEspace);
    }

    /** Pose une heure (issue d'une colonne `time`) sur une date, dans le fuseau de cette date. */
    private function a(\DateTimeImmutable $jour, \DateTimeImmutable $heure): \DateTimeImmutable
    {
        return $jour->setTime(
            (int) $heure->format('H'),
            (int) $heure->format('i'),
            (int) $heure->format('s'),
        );
    }

    /**
     * Retranche `[debut, fin[` de chaque fenêtre. Une coupure au milieu d'une fenêtre en produit
     * DEUX — c'est le cas de la coupure méridienne saisie en exception, et l'oublier laisserait le
     * site réputé ouvert tout l'après-midi.
     *
     * @param list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, libelle: ?string}> $fenetres
     *
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, libelle: ?string}>
     */
    private function retrancher(array $fenetres, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $restant = [];
        foreach ($fenetres as $fenetre) {
            if ($fin <= $fenetre['start'] || $debut >= $fenetre['end']) {
                $restant[] = $fenetre;

                continue;
            }
            if ($debut > $fenetre['start']) {
                $restant[] = ['start' => $fenetre['start'], 'end' => $debut, 'label' => $fenetre['label']];
            }
            if ($fin < $fenetre['end']) {
                $restant[] = ['start' => $fin, 'end' => $fenetre['end'], 'label' => $fenetre['label']];
            }
        }

        return $restant;
    }
}
