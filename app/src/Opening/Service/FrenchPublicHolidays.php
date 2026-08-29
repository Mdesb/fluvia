<?php

declare(strict_types=1);

namespace App\Opening\Service;

/**
 * LES JOURS FÉRIÉS FRANÇAIS — CALCULÉS, JAMAIS DEMANDÉS À QUELQU'UN.
 *
 * ── POURQUOI ON NE VA PAS LES CHERCHER ──────────────────────────────────────────────────────────
 *
 * Il existe des API qui les publient. Elles demandent un réseau, parfois une clé, et elles tombent
 * un dimanche. Or onze des onze jours fériés français sont DÉTERMINÉS : huit à date fixe, trois
 * dérivés de Pâques — et Pâques se calcule depuis 1583 par l'algorithme de Meeus/Butcher, en une
 * vingtaine de lignes, exactement, hors ligne, pour toujours.
 *
 * > **Une dépendance qu'on peut calculer vaut mieux qu'une dépendance qui peut tomber.**
 *
 * C'est le même raisonnement qui a fait préférer l'Annuaire des Entreprises à l'API Sirene, poussé
 * un cran plus loin : ici on se passe du réseau entièrement.
 *
 * ── LE PIÈGE ALSACE-MOSELLE ─────────────────────────────────────────────────────────────────────
 *
 * Le Bas-Rhin, le Haut-Rhin et la Moselle ont **deux jours fériés de plus** : le Vendredi saint et
 * le 26 décembre. Ce n'est pas une curiosité locale, c'est du droit local applicable, et l'oublier
 * a une conséquence concrète : un club de Strasbourg ouvert le Vendredi saint parce que notre
 * calendrier l'ignore, c'est du personnel convoqué un jour chômé.
 *
 * Le drapeau est porté par le réglage de l'établissement, pas déduit en silence d'une adresse :
 * une adresse de siège n'est pas toujours l'adresse du site, et se tromper ici coûte plus cher que
 * de poser la question.
 *
 * ── CE QUE CETTE CLASSE NE FAIT PAS ─────────────────────────────────────────────────────────────
 *
 * Elle ne FERME rien. Elle rend une liste de dates et de noms ; c'est l'exploitant qui décide
 * lesquels ferment son site — il y a des patinoires qui font leur année le 25 décembre. « Pré-
 * paramétré » veut dire proposé, pas imposé.
 */
/**
 * ⚠ CE MODULE EST FRANÇAIS PAR CONSTRUCTION, ET CE N'EST PAS UN OUBLI.
 *
 * Onze jours fériés du code du travail français, le calcul de Pâques, et le droit local
 * d'Alsace-Moselle qui en ajoute deux. À côté, les vacances scolaires viennent d'un jeu de données
 * ministériel français et se choisissent par zone A, B ou C.
 *
 * Maxime veut couvrir l'Europe (revue du 29/08). Ce paragraphe est écrit pour celui qui ajoutera la
 * Belgique ou l'Espagne, parce que la forme de l'ajout dépend de ce qu'il aura lu :
 *
 *   · un pays de plus n'est PAS une option supplémentaire dans cet écran. Les zones scolaires, les
 *     jours fériés et les règles locales ne se ressemblent pas d'un pays à l'autre — l'Allemagne
 *     fixe ses fériés par Land, l'Espagne par communauté autonome ;
 *   · le port `SchoolHolidaysInterface` existe déjà et attend un second adaptateur. Les fériés, eux,
 *     n'ont pas encore de port : cette classe est appelée directement. C'est là que commencera le
 *     travail, et il vaut mieux le savoir avant d'ajouter un `if` par pays ici.
 */
final readonly class FrenchPublicHolidays
{
    /** Les huit dates fixes : [mois, jour, nom]. */
    private const FIXES = [
        [1, 1, 'Jour de l’an'],
        [5, 1, 'Fête du Travail'],
        [5, 8, 'Victoire 1945'],
        [7, 14, 'Fête nationale'],
        [8, 15, 'Assomption'],
        [11, 1, 'Toussaint'],
        [11, 11, 'Armistice 1918'],
        [12, 25, 'Noël'],
    ];

    /**
     * Les jours fériés d'une année civile.
     *
     * @return list<array{date: string, nom: string, local: bool}>
     */
    public function pourAnnee(int $annee, bool $alsaceMoselle = false): array
    {
        $paques = $this->paques($annee);

        $jours = [];
        foreach (self::FIXES as [$mois, $jour, $nom]) {
            $jours[] = [
                'date' => sprintf('%04d-%02d-%02d', $annee, $mois, $jour),
                'nom' => $nom,
                'local' => false,
            ];
        }

        // Les trois mobiles, tous comptés depuis Pâques.
        $jours[] = ['date' => $paques->modify('+1 day')->format('Y-m-d'), 'nom' => 'Lundi de Pâques', 'local' => false];
        $jours[] = ['date' => $paques->modify('+39 days')->format('Y-m-d'), 'nom' => 'Ascension', 'local' => false];
        $jours[] = ['date' => $paques->modify('+50 days')->format('Y-m-d'), 'nom' => 'Lundi de Pentecôte', 'local' => false];

        if ($alsaceMoselle) {
            // `local: true` : l'écran doit pouvoir dire POURQUOI ces deux-là apparaissent, sinon un
            // exploitant du Bas-Rhin qui compare avec un collègue parisien croit à un bogue.
            $jours[] = ['date' => $paques->modify('-2 days')->format('Y-m-d'), 'nom' => 'Vendredi saint', 'local' => true];
            $jours[] = ['date' => sprintf('%04d-12-26', $annee), 'nom' => 'Saint-Étienne', 'local' => true];
        }

        usort($jours, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return array_values($jours);
    }

    /**
     * Les jours fériés qui tombent entre deux dates, années civiles traversées comprises.
     *
     * @return list<array{date: string, nom: string, local: bool}>
     */
    public function entre(\DateTimeImmutable $du, \DateTimeImmutable $au, bool $alsaceMoselle = false): array
    {
        $retenus = [];
        for ($annee = (int) $du->format('Y'); $annee <= (int) $au->format('Y'); ++$annee) {
            foreach ($this->pourAnnee($annee, $alsaceMoselle) as $jour) {
                if ($jour['date'] >= $du->format('Y-m-d') && $jour['date'] <= $au->format('Y-m-d')) {
                    $retenus[] = $jour;
                }
            }
        }

        return $retenus;
    }

    /**
     * DIMANCHE DE PÂQUES — algorithme de Meeus/Butcher (calendrier grégorien).
     *
     * Il n'est pas commenté ligne à ligne, et c'est délibéré : ces divisions n'ont pas de sens
     * individuellement, elles n'en ont qu'ensemble. Le commenter donnerait l'illusion qu'on peut le
     * modifier en comprenant une ligne. Ce qu'il faut savoir tient en deux phrases : il est exact
     * pour toute année grégorienne, et il se vérifie sur des dates connues — c'est ce que fait le
     * test, sur cinq années dont deux de bornes.
     */
    private function paques(int $annee): \DateTimeImmutable
    {
        $a = $annee % 19;
        $b = intdiv($annee, 100);
        $c = $annee % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mois = intdiv($h + $l - 7 * $m + 114, 31);
        $jour = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $annee, $mois, $jour));
    }
}
