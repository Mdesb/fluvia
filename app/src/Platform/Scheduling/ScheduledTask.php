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
    ) {
    }
}
