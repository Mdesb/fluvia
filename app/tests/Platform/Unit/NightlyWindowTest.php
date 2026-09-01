<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Scheduling\NightlyWindow;
use PHPUnit\Framework\TestCase;

/**
 * LA FENÊTRE NOCTURNE, ET LES DEUX NUITS OÙ 02H00 NE SE COMPORTE PAS NORMALEMENT.
 *
 * Maxime a choisi 02h00 en disant « attention aux fuseaux horaires ». C'est précisément l'heure du
 * changement d'heure en Europe/Paris : deux fois par an, l'heure choisie est la seule de la journée
 * qui n'existe pas une fois, ou qui existe deux fois.
 *
 * ⚠ AUCUN TEST ICI NE LIT L'HORLOGE (D20). Chaque instant est écrit en UTC dans le test, ce qui rend
 * les deux nuits de bascule reproductibles n'importe quel jour de l'année — sans quoi elles ne
 * seraient vérifiables que deux dimanches par an, c'est-à-dire jamais.
 */
final class NightlyWindowTest extends TestCase
{
    private static function utc(string $instant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instant, new \DateTimeZone('UTC'));
    }

    // ── Une nuit ordinaire ──────────────────────────────────────────────────────────────────────

    public function testElleEstFermeeAvantDeuxHeures(): void
    {
        // 00h30 UTC = 02h30 à Paris… non : le 15/09 Paris est en CEST (UTC+2), donc 23h30 UTC la
        // veille vaut 01h30 locales. On est avant l'ouverture.
        self::assertFalse(
            NightlyWindow::isOpen(self::utc('2026-09-14 23:30:00'), '02:00', null),
            'À 01h30 locales, la fenêtre de 02h00 ne doit pas être ouverte.',
        );
    }

    public function testElleEstOuverteDansLaFenetre(): void
    {
        self::assertTrue(
            NightlyWindow::isOpen(self::utc('2026-09-15 00:30:00'), '02:00', null),
            'À 02h30 locales, la fenêtre de 02h00 doit être ouverte.',
        );
    }

    public function testElleSeReferme(): void
    {
        // 04h00 UTC = 06h00 locales : trois heures après l'ouverture, la fenêtre est passée.
        self::assertFalse(
            NightlyWindow::isOpen(self::utc('2026-09-15 04:00:00'), '02:00', null),
            'Six heures locales, c est hors de la fenêtre de trois heures.',
        );
    }

    /**
     * ⚠ UNE NUIT MANQUÉE NE SE RATTRAPE PAS EN PLEIN APRÈS-MIDI.
     *
     * C'est le choix qui distingue cette fenêtre d'un simple `everyMinutes`. Ces tâches ont un effet
     * visible au dehors — un prélèvement annoncé, une facture. Les faire partir à 14h00 parce que
     * l'ordonnanceur dormait la nuit précédente serait correct et inacceptable.
     */
    public function testElleNeRattrapePasEnJournee(): void
    {
        self::assertFalse(
            NightlyWindow::isOpen(self::utc('2026-09-15 12:00:00'), '02:00', null),
            'À 14h00 locales, une tâche nocturne attend la nuit suivante.',
        );
    }

    public function testElleNeRepartPasLaMemeNuit(): void
    {
        $deja = self::utc('2026-09-15 00:05:00');

        self::assertFalse(
            NightlyWindow::isOpen(self::utc('2026-09-15 01:00:00'), '02:00', $deja),
            'Elle a tourné à 02h05 ; à 03h00 la même nuit, elle ne doit pas repartir.',
        );
    }

    public function testElleRepartLaNuitSuivante(): void
    {
        $hier = self::utc('2026-09-15 00:05:00');

        self::assertTrue(
            NightlyWindow::isOpen(self::utc('2026-09-16 00:05:00'), '02:00', $hier),
            'La nuit suivante est une autre date locale : elle repart.',
        );
    }

    // ── Mars : 02h00 N'EXISTE PAS ───────────────────────────────────────────────────────────────

    /**
     * Le 29/03/2026, les horloges de Paris sautent de 02h00 à 03h00. Il n'y a pas d'instant dont
     * l'heure locale vaut 02.
     *
     * ⚠ C'EST LE CAS QUI FAIT SAUTER UNE NUIT. Une implémentation qui teste « heure locale == 02 »
     * ne déclenche jamais cette nuit-là : les abonnements ne sont pas traités, les préavis SEPA ne
     * partent pas, et le manque ne se voit que par ce qui ne s'est pas produit.
     */
    public function testAuPassageAHeureDEteLaFenetreSOuvreQuandMeme(): void
    {
        // 01h00 UTC = 03h00 CEST, la première heure locale qui existe ce jour-là.
        self::assertTrue(
            NightlyWindow::isOpen(self::utc('2026-03-29 01:00:00'), '02:00', null),
            'La nuit où 02h00 n existe pas, la fenêtre doit s ouvrir à la première heure qui existe.',
        );
    }

    public function testAuPassageAHeureDEteElleResteFermeeAvant(): void
    {
        // 00h30 UTC = 01h30 CET : avant le saut, et avant l'ouverture.
        self::assertFalse(
            NightlyWindow::isOpen(self::utc('2026-03-29 00:30:00'), '02:00', null),
            'Avant le saut, il est 01h30 locales : trop tôt.',
        );
    }

    // ── Octobre : 02H00 EXISTE DEUX FOIS ────────────────────────────────────────────────────────

    /**
     * Le 25/10/2026, les horloges de Paris reculent : 03h00 CEST redevient 02h00 CET. L'heure locale
     * 02h00 arrive donc deux fois, à une heure réelle d'écart.
     *
     * ⚠ C'EST LE CAS GRAVE, ET IL EST SILENCIEUX. Deux passages veulent dire deux préavis SEPA
     * envoyés aux mêmes personnes, ou deux facturations du même mois — sur des clients réels, une
     * nuit par an. Personne ne relit les journaux du 25 octobre ; on l'apprend par une réclamation.
     *
     * ⚠ CE TEST NE DISCRIMINE PAS CONTRE UNE GARDE EN DURÉE, et il faut le dire. Je l'ai cru, puis
     * mesuré : en remplaçant la garde par date par un `+20 heures`, les douze tests restent verts.
     * Les deux 02h00 sont à une heure réelle d'écart, donc tout intervalle d'au moins une heure les
     * sépare. Ce test prouve qu'on ne facture pas deux fois — il ne prouve pas POURQUOI on a choisi
     * la date. Cette raison-là est la dérive, et c'est `testElleNeDerivePasHorsDeLaFenetre` qui la
     * porte.
     */
    public function testAuPassageAHeureDHiverElleNeTournePasDeuxFois(): void
    {
        // Premier 02h00, en CEST.
        $premier = self::utc('2026-10-25 00:00:00');
        self::assertTrue(
            NightlyWindow::isOpen($premier, '02:00', null),
            'Le premier 02h00 doit ouvrir la fenêtre.',
        );

        // Second 02h00, en CET, une heure réelle plus tard.
        self::assertFalse(
            NightlyWindow::isOpen(self::utc('2026-10-25 01:00:00'), '02:00', $premier),
            'Le second 02h00 de la nuit d octobre ne doit PAS relancer la tâche.',
        );
    }

    /**
     * Le témoin qui prouve que le test précédent teste quelque chose.
     *
     * ⚠ SANS LUI, `testAuPassageAHeureDHiver…` PASSERAIT AUSSI SI LA FENÊTRE NE S'OUVRAIT JAMAIS.
     * Un `isOpen` qui rendrait `false` en toute circonstance satisfait la seconde assertion. On
     * vérifie donc que l'écart d'une heure entre les deux instants est bien réel — et que c'est la
     * garde par date locale, et non une fenêtre fermée, qui produit le refus.
     */
    public function testLesDeuxInstantsDOctobreSontBienAUneHeureDEcartEtDansLaFenetre(): void
    {
        $premier = self::utc('2026-10-25 00:00:00')->setTimezone(new \DateTimeZone('Europe/Paris'));
        $second = self::utc('2026-10-25 01:00:00')->setTimezone(new \DateTimeZone('Europe/Paris'));

        self::assertSame('02:00', $premier->format('H:i'), 'Le premier instant doit être 02h00 locales.');
        self::assertSame('02:00', $second->format('H:i'), 'Le second aussi — c est tout le problème.');
        self::assertSame(3600, $second->getTimestamp() - $premier->getTimestamp());

        // Et si la tâche n'avait PAS tourné au premier passage, le second l'ouvrirait : la fenêtre
        // est bien ouverte à cet instant, donc le refus ci-dessus vient de la garde, pas de l'heure.
        self::assertTrue(
            NightlyWindow::isOpen(self::utc('2026-10-25 01:00:00'), '02:00', null),
            'Sans exécution précédente, le second 02h00 doit ouvrir la fenêtre.',
        );
    }

    // ── La dérive : ce que la garde par DATE protège vraiment ──────────────────────────────────

    /**
     * ⚠ LE TEST QUI DISTINGUE LA GARDE PAR DATE D'UNE GARDE EN DURÉE.
     *
     * Une garde en durée rend la tâche due à `dernière fin + 24 h`. L'heure d'exécution recule donc
     * chaque nuit de la durée d'exécution, jusqu'à sortir de la fenêtre — et la tâche cesse alors
     * d'être due, définitivement, sans erreur et sans trace. Modélisé avec une tâche de douze
     * minutes ayant fini une fois à 04h50, fenêtre 02h00–05h00 :
     *
     *     nuit 2   due à 04h50   dans la fenêtre     finit à 05h02
     *     nuit 3   due à 05h02   HORS FENÊTRE        sautée — et toutes les suivantes
     *
     * Ici : elle a fini à 04h50 la nuit du 15. La nuit du 16 à 02h10, il s'est écoulé 21 h 20. Une
     * garde en 24 h la déclarerait NON due et sauterait l'ouverture ; la garde par date la déclare
     * due, parce que c'est une autre nuit.
     */
    public function testElleNeDerivePasHorsDeLaFenetre(): void
    {
        // 02h50 UTC = 04h50 locales le 15/09 : une nuit où la tâche a fini tard.
        $finTardive = self::utc('2026-09-15 02:50:00');

        self::assertTrue(
            NightlyWindow::isOpen(self::utc('2026-09-16 00:10:00'), '02:00', $finTardive),
            'À 02h10 la nuit suivante, elle doit être due — même si 24 h ne se sont pas écoulées.',
        );
    }

    /**
     * Le témoin de l'énoncé ci-dessus : 21 h 20 seulement se sont écoulées.
     *
     * Sans lui, `testElleNeDerivePasHorsDeLaFenetre` passerait aussi si l'écart était de 25 heures —
     * et ne dirait alors rien d'une garde en durée.
     */
    public function testLEcartDuTestDeDeriveEstBienInferieurAVingtQuatreHeures(): void
    {
        $ecart = self::utc('2026-09-16 00:10:00')->getTimestamp()
            - self::utc('2026-09-15 02:50:00')->getTimestamp();

        self::assertLessThan(24 * 3600, $ecart, 'Le test de dérive doit porter sur moins de 24 h.');
        self::assertGreaterThan(20 * 3600, $ecart, 'Et sur assez de temps pour être une vraie nuit.');
    }

    // ── La prochaine ouverture, pour `--status` ─────────────────────────────────────────────────

    public function testLaProchaineOuvertureEstCetteNuitSiElleNEstPasPassee(): void
    {
        $prochaine = NightlyWindow::nextOpening(self::utc('2026-09-14 23:00:00'), '02:00');

        self::assertSame(
            '2026-09-15 02:00',
            $prochaine->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'),
        );
    }

    public function testLaProchaineOuvertureEstLaNuitSuivanteSiElleEstPassee(): void
    {
        $prochaine = NightlyWindow::nextOpening(self::utc('2026-09-15 12:00:00'), '02:00');

        self::assertSame(
            '2026-09-16 02:00',
            $prochaine->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'),
        );
    }
}
