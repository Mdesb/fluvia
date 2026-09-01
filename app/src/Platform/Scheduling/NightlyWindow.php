<?php

declare(strict_types=1);

namespace App\Platform\Scheduling;

/**
 * LA FENÊTRE NOCTURNE : à partir de quand, jusqu'à quand, et **dans quel fuseau**.
 *
 * Arbitré par Maxime le 01/09 : les tâches lourdes et les tâches d'argent tournent la nuit, dans une
 * fenêtre qui commence à **02h00**, et dans un ordre déclaré — l'argent d'abord. Sa phrase exacte
 * était « 2h00 **mais attention aux fuseaux horaires** ».
 *
 * ── ⚠ ELLE AVAIT PLUS RAISON QU'IL N'Y PARAÎT : 02h00 EST L'HEURE DU CHANGEMENT D'HEURE ─────────
 *
 * En Europe/Paris, le basculement se fait précisément à 02h00 locales. Deux fois par an, l'heure
 * choisie est donc la seule de la journée qui ne se comporte pas normalement :
 *
 *     dernier dimanche de mars      02h00 N'EXISTE PAS — les horloges sautent de 02h00 à 03h00
 *     dernier dimanche d'octobre    02h00 EXISTE DEUX FOIS — 02h00 CEST puis 02h00 CET
 *
 * Une implémentation naïve — « déclencher quand l'heure locale vaut 02 » — **saute la nuit de mars**
 * (l'égalité n'est jamais vraie) et **facture deux fois la nuit d'octobre**. Le second cas est le
 * grave : deux prélèvements annoncés, deux facturations, sur des clients réels, une nuit par an.
 * C'est le genre de défaut qu'on découvre par une réclamation, treize mois plus tard.
 *
 * D'où les deux choix de ce fichier :
 *
 *   1. **Un INTERVALLE, pas un instant.** « À partir de 02h00, pendant trois heures » est vrai en
 *      mars (la fenêtre s'ouvre de fait à 03h00, la première heure locale qui existe) comme en
 *      octobre.
 *   2. **Une garde par DATE LOCALE, pas par durée écoulée.** « A déjà tourné aujourd'hui » se lit
 *      sur le calendrier local, et non sur un intervalle depuis la dernière fois.
 *
 * ⚠ SUR CE SECOND POINT, MA PREMIÈRE JUSTIFICATION ÉTAIT FAUSSE, ET C'EST LE TÉMOIN QUI L'A DIT.
 *
 * J'avais écrit qu'une garde en durée « laisserait passer » la seconde 02h00 d'octobre. Mesuré :
 * elle ne la laisse PAS passer. Les deux 02h00 sont à une heure réelle d'écart, donc tout intervalle
 * d'au moins une heure les sépare — un `everyMinutes: 1440` bloque le doublon aussi bien que la date.
 * J'ai remplacé la garde par une garde en durée et les douze tests sont restés verts.
 *
 * Ce que la date protège vraiment est la **dérive**, et elle est plus sournoise parce qu'elle ne se
 * produit qu'après plusieurs nuits. Une garde en durée rend la tâche due `dernière fin + 24 h` : la
 * fin recule donc chaque nuit de la durée d'exécution. Modélisé avec une tâche de douze minutes qui
 * a fini une fois à 04h50, fenêtre 02h00–05h00 :
 *
 *     nuit 2   due à 04h50   dans la fenêtre     finit à 05h02
 *     nuit 3   due à 05h02   HORS FENÊTRE        sautée — et toutes les suivantes
 *
 * La tâche ne s'arrête pas en erreur : elle cesse simplement d'être due dans la fenêtre, pour
 * toujours, sans que rien ne le signale. La date locale n'a pas cette dérive : « une fois par nuit »
 * reste vrai que la tâche finisse à 02h01 ou à 04h59.
 *
 * ── ⚠ LE SERVEUR EST EN UTC, ET C'EST LE PIÈGE ORDINAIRE ────────────────────────────────────────
 *
 * `date` dans les conteneurs rend 20h46 quand il est 22h46 à Paris. Une fenêtre écrite en heure
 * serveur tournerait donc à 04h00 locales l'été et 03h00 l'hiver — et **glisserait d'une heure deux
 * fois par an sans que rien ne le signale**. Toute la conversion vit ici, dans un seul fichier, et
 * `TIMEZONE` n'est lue nulle part ailleurs.
 *
 * ── CE QUE CE FICHIER NE FAIT PAS ───────────────────────────────────────────────────────────────
 *
 * Une nuit manquée — ordonnanceur arrêté, serveur éteint — n'est **pas** rattrapée le lendemain à
 * 14h00. La fenêtre se referme et la tâche attend la nuit suivante. C'est délibéré : ces tâches
 * relèvent du cas 3 du catalogue — un effet visible au dehors, correct et pourtant inacceptable en
 * plein après-midi. Le prix est qu'une nuit sautée est un vrai manque ; `--status` doit le dire, et
 * c'est à l'appelant de le montrer, pas à cette classe de le masquer.
 */
final class NightlyWindow
{
    /**
     * ⚠ LE FUSEAU DU PRODUIT, PAS CELUI DU SERVEUR. Les deux diffèrent d'une ou deux heures selon la
     * saison. Un exploitant qui lit « 02h00 » dans une documentation attend 02h00 chez lui.
     */
    public const TIMEZONE = 'Europe/Paris';

    /**
     * Durée par défaut de la fenêtre, en minutes.
     *
     * Trois heures : assez pour absorber une tâche longue et un ordonnanceur qui ne passe qu'à la
     * minute, assez court pour qu'un débordement se voie au lieu de se fondre dans la journée.
     */
    public const DEFAULT_DURATION_MINUTES = 180;

    /**
     * La fenêtre est-elle ouverte, et la tâche n'a-t-elle pas déjà tourné cette nuit ?
     *
     * @param non-empty-string $startsAt heure locale d'ouverture, au format `HH:MM`
     * @param positive-int     $durationMinutes
     */
    public static function isOpen(
        \DateTimeImmutable $now,
        string $startsAt,
        ?\DateTimeImmutable $lastFinishedAt,
        int $durationMinutes = self::DEFAULT_DURATION_MINUTES,
    ): bool {
        $zone = new \DateTimeZone(self::TIMEZONE);
        $local = $now->setTimezone($zone);

        $opensAt = self::opening($local, $startsAt);
        $closesAt = $opensAt->modify(sprintf('+%d minutes', $durationMinutes));

        if ($local < $opensAt || $local >= $closesAt) {
            return false;
        }

        if ($lastFinishedAt === null) {
            return true;
        }

        // ⚠ LA COMPARAISON PORTE SUR LA DATE LOCALE, PAS SUR UNE DURÉE ÉCOULÉE.
        //
        // Non pas pour la nuit d'octobre — un intervalle de 24 h bloque le doublon aussi bien, les
        // deux 02h00 étant à une heure réelle d'écart. Mais parce qu'une garde en durée fait
        // DÉRIVER l'heure d'exécution : due à `dernière fin + 24 h`, la tâche recule chaque nuit de
        // sa propre durée, et finit par tomber hors de la fenêtre — définitivement, en silence.
        // Voir le détail chiffré en tête de fichier.
        return $lastFinishedAt->setTimezone($zone)->format('Y-m-d') !== $local->format('Y-m-d');
    }

    /**
     * L'instant d'ouverture pour la journée locale de `$local`.
     *
     * ⚠ ON CONSTRUIT L'INSTANT, ON NE COMPARE PAS DES CHAÎNES. `setTime(2, 0)` sur une date où 02h00
     * n'existe pas rend 03h00 en Europe/Paris — PHP résout l'heure inexistante vers l'avant. C'est
     * exactement le comportement voulu : la nuit de mars, la fenêtre s'ouvre à la première heure
     * locale qui existe, au lieu de ne jamais s'ouvrir.
     */
    private static function opening(\DateTimeImmutable $local, string $startsAt): \DateTimeImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $startsAt));

        return $local->setTime($hour, $minute);
    }

    /**
     * L'ouverture de la PROCHAINE fenêtre, pour l'afficher dans `--status`.
     *
     * Une tâche nocturne qui n'a pas tourné depuis trois jours n'est pas « en retard de 4320
     * minutes » : elle a manqué trois nuits. Le dire en nuits est ce qui rend le manque lisible.
     */
    public static function nextOpening(\DateTimeImmutable $now, string $startsAt): \DateTimeImmutable
    {
        $zone = new \DateTimeZone(self::TIMEZONE);
        $local = $now->setTimezone($zone);
        $opensAt = self::opening($local, $startsAt);

        if ($local < $opensAt) {
            return $opensAt;
        }

        return self::opening($local->modify('+1 day'), $startsAt);
    }
}
