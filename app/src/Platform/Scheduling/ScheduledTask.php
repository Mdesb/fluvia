<?php

declare(strict_types=1);

namespace App\Platform\Scheduling;

/**
 * Une tâche périodique déclarée : quelle commande, à quelle fréquence, et **pourquoi**.
 *
 * Le « pourquoi » n'est pas de la documentation de politesse. Une fréquence sans justification se
 * modifie au jugé, et c'est ainsi qu'une purge quotidienne devient horaire parce que quelqu'un trouvait
 * ça « plus sûr ». Ici la raison voyage avec la valeur.
 */
final readonly class ScheduledTask
{
    /**
     * @param non-empty-string $command    nom exact de la commande console
     * @param positive-int     $everyMinutes intervalle minimal entre deux exécutions
     * @param non-empty-string $why        ce qui se passe si elle ne tourne pas
     */
    public function __construct(
        public string $command,
        public int $everyMinutes,
        public string $why,
        public bool $critical = false,
        /**
         * Le premier passage d'une commande qui n'a jamais tourné est-il **sûr** ?
         *
         * **Le défaut est `false`, et c'est délibéré.** Trouvé par `claude-G` sur
         * `padel:eclairage:commander` : elle balaie toutes les réservations **sans borne de date** et
         * déclencherait, à sa première exécution, un allumage pour chaque créneau passé et une
         * extinction pour chaque créneau fini — sur un **port qui pilote un relais physique**.
         * Aujourd'hui l'adaptateur est un simulateur ; le jour où un vrai relais est branché, la
         * première exécution allume et éteint les projecteurs de chaque terrain autant de fois qu'il y
         * a de réservations dans l'historique.
         *
         * **Et je ne sais pas combien des vingt et une autres ont le même profil** — je ne les ai pas
         * écrites. Un défaut à `true` reviendrait à affirmer qu'elles sont sûres sans l'avoir vérifié :
         * c'est exactement le genre d'affirmation qui a produit les défauts de cette semaine.
         *
         * Conséquence : une tâche jamais exécutée n'est **pas** lancée automatiquement. Elle attend un
         * passage où quelqu'un regarde vraiment ce qu'elle fait, après quoi elle se planifie
         * normalement.
         *
         * ⚠ CETTE PHRASE DISAIT « `--premier-passage`, ou `--only` » JUSQU'AU 01/09/2026, et c'était
         * devenu faux le matin même. `--only` ne vaut plus supervision : l'ordonnanceur l'émet pour
         * chaque tâche à chaque cycle, si bien que le verrou décrit ici était court-circuité en
         * permanence depuis sa naissance (D109). La levée vit désormais en D109 et nulle part
         * ailleurs — une phrase qui nomme une porte de sortie lui survit rarement.
         *
         * `true` se déclare quand on a **lu la commande** et vérifié qu'elle borne son travail dans le
         * temps au lieu de rattraper l'historique.
         */
        public bool $safeOnFirstRun = false,
        /**
         * L'heure LOCALE d'ouverture de la fenetre nocturne (`HH:MM`), ou `null` pour une tache qui
         * se contente de son intervalle.
         *
         * Arbitre par Maxime le 01/09 : les taches d'argent tournent la nuit, a partir de 02h00,
         * dans un ordre declare. Deux raisons, et la seconde n'est pas la charge machine :
         *
         *   - le VOLUME. Plus il y a d'adherents, plus ces taches durent ; les faire tourner en
         *     journee met un pic de base de donnees au milieu des ventes au comptoir.
         *   - la VISIBILITE. Une facturation, un preavis SEPA, un renouvellement ont un effet au
         *     dehors. Personne ne veut voir partir trois cents preavis a 14h20 un mardi.
         *
         * ⚠ QUAND CE CHAMP EST POSE, `everyMinutes` NE DECIDE PLUS RIEN. La fenetre est une garde
         * « une fois par nuit locale », pas un intervalle. Laisser les deux se prononcer donnerait
         * deux verites contradictoires sur la meme tache, et la plus permissive gagnerait au premier
         * desaccord.
         *
         * ⚠ ET 02H00 EST L'HEURE DU CHANGEMENT D'HEURE EN EUROPE/PARIS. Elle n'existe pas la nuit de
         * mars, elle arrive deux fois celle d'octobre. Toute la resolution vit dans `NightlyWindow`,
         * dont les tests couvrent les deux nuits sans attendre le dernier dimanche du mois.
         *
         * @var non-empty-string|null
         */
        public ?string $nightlyAt = null,
        /**
         * L'ordre d'execution a l'interieur d'un cycle. Petit = tot.
         *
         * Maxime a demande « les taches d'argent d'abord ». L'ordre n'est pas cosmetique : le
         * renouvellement CREE les echeances que le preavis annonce et que la facturation encaisse.
         * A l'envers, le preavis porterait sur une echeance qui n'existe pas encore — donc aucun
         * preavis, sans erreur, et un prelevement non annonce le mois suivant.
         *
         * ⚠ CE CHAMP NE GARANTIT PAS L'ORDRE A LUI SEUL. `infra/ordonnanceur.sh` appelle `--only`
         * tache par tache, dans l'ordre de SA liste blanche. Tant qu'il fait ainsi, c'est cette
         * liste qui decide, et ce rang ne s'applique qu'a un appel nu de `platform:scheduler:run`.
         * Les deux doivent dire la meme chose ; ce fichier est la reference.
         */
        public int $order = 500,
    ) {
    }
}
