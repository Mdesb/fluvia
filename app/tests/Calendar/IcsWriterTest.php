<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\Service\IcsWriter;
use PHPUnit\Framework\TestCase;

/**
 * LA QUATRIÈME RÈGLE : UNE LIGNE NE DÉPASSE PAS 75 OCTETS.
 *
 * `IcsWriter` en documente trois — CRLF, échappement, UID stable — et les tient. La RFC 5545 en
 * impose une quatrième, au §3.1 : au-delà de 75 octets, la ligne se PLIE, et la suite commence par
 * une espace.
 *
 * ── POURQUOI C'EST LE GENRE DE DÉFAUT QU'ON NE VOIT PAS ─────────────────────────────────────────
 *
 * Google Agenda accepte les lignes longues. Apple aussi, la plupart du temps. Ce sont les
 * agrégateurs et les versions anciennes d'Outlook qui tronquent — silencieusement, à 75 octets. Le
 * résultat n'est pas un abonnement en erreur : c'est un titre coupé au milieu d'un mot, chez
 * certains utilisateurs seulement, sans que personne ne fasse le lien avec la longueur du titre.
 *
 * Et 75 octets, ce n'est pas long : « Réunion de préparation de la saison d'été avec les
 * responsables de bassin » les dépasse, et c'est un titre que quelqu'un écrira.
 *
 * ── ON COMPTE DES OCTETS, PAS DES CARACTÈRES ────────────────────────────────────────────────────
 *
 * La RFC parle d'octets. Un accent en UTF-8 en occupe deux : compter les caractères laisserait
 * passer des lignes trop longues dans exactement les textes français où le défaut se produit. Mais
 * le pli ne doit PAS tomber au milieu d'un caractère multi-octet, sous peine de produire un fichier
 * qui n'est plus de l'UTF-8 valide — d'où les deux assertions complémentaires ci-dessous.
 */
final class IcsWriterTest extends TestCase
{
    private const LONG_TITRE = 'Réunion de préparation de la saison d’été avec les responsables de bassin et de la caisse';
    // ⚠ SANS VIRGULE NI POINT-VIRGULE, ET C'EST DÉLIBÉRÉ. L'écriture les échappe en « \, » : les
    // comparer au texte brut ferait rougir ce test pour une raison qui n'a rien à voir avec le
    // pliage. L'échappement a son propre test, plus bas.
    private const LONGUE_NOTE = 'Ordre du jour : horaires d’ouverture puis renfort saisonnier puis tarifs de groupe et enfin le point sur les vestiaires refaits en mai';

    public function testAucuneLigneNeDepasseSoixanteQuinzeOctets(): void
    {
        $ics = $this->ecrire();

        $trop = [];
        foreach (explode("\r\n", $ics) as $ligne) {
            if (\strlen($ligne) > 75) {
                $trop[] = \strlen($ligne) . ' octets : ' . substr($ligne, 0, 40) . '…';
            }
        }

        self::assertSame(
            [],
            $trop,
            "Ces lignes dépassent la limite de la RFC 5545 §3.1 ; certains lecteurs les tronquent en silence.\n"
            . implode("\n", $trop),
        );
    }

    /**
     * ⚠ LE PLI NE COUPE PAS UN CARACTÈRE. Plier sur un octet au milieu d'un « é » produirait un
     * fichier qui n'est plus de l'UTF-8 valide — et un lecteur qui refuse tout l'agenda, pas
     * seulement l'événement.
     */
    public function testLePliNeCassePasUnCaractereAccentue(): void
    {
        // ⚠ CES DONNÉES SONT CALCULÉES POUR TOMBER SUR LA FRONTIÈRE, PAS CHOISIES AU HASARD.
        //
        // Première version : je réutilisais le titre de réunion ordinaire. Le test passait — y
        // compris quand je remplaçais le découpage par caractères par un `str_split` qui coupe les
        // octets. Vérifié en le cassant : vert. Ses plis tombaient tous sur de l'ASCII, par chance.
        //
        // « SUMMARY: » pèse 8 octets, chaque « é » en pèse 2 : le 75e octet est donc le PREMIER des
        // deux du 34e « é ». Une coupure par octets le tranche en deux, et le fichier cesse d'être
        // de l'UTF-8 valide — un lecteur refuse alors l'agenda ENTIER, pas seulement l'événement.
        $ics = (new IcsWriter())->rediger(
            [[
                'id' => 'b3f1c2d4-0000-4000-8000-000000000003',
                'title' => str_repeat('é', 40),
                'start' => '2026-06-01T09:00:00+00:00',
                'end' => '2026-06-01T10:00:00+00:00',
                'type' => 'meeting',
            ]],
            'Piscine',
        );

        self::assertTrue(mb_check_encoding($ics, 'UTF-8'), 'Le fichier produit n’est plus de l’UTF-8 valide : un pli est tombé au milieu d’un caractère.');

        // Et le titre se retrouve entier après dépliage : un pli qui perd un caractère produirait
        // un fichier valide et FAUX, ce qui est pire.
        self::assertStringContainsString('SUMMARY:' . str_repeat('é', 40), str_replace("\r\n ", '', $ics));
    }

    /**
     * ⚠ ET SURTOUT : LE PLI SE DÉFAIT. Un pliage qui perd des caractères ou en ajoute donnerait un
     * fichier conforme et FAUX — le pire des deux mondes. On déplie comme le fait un lecteur (CRLF
     * suivi d'une espace) et on exige de retrouver le titre.
     */
    public function testDeplierRestitueLeTitreEntier(): void
    {
        $ics = $this->ecrire();

        $deplie = str_replace("\r\n ", '', $ics);

        self::assertStringContainsString('SUMMARY:' . self::LONG_TITRE, $deplie);
        self::assertStringContainsString('DESCRIPTION:' . self::LONGUE_NOTE, $deplie);
    }

    /**
     * L'ÉCHAPPEMENT ÉTAIT DOCUMENTÉ ET NON TESTÉ.
     *
     * La règle n°2 de la classe l'annonce — virgule, point-virgule et barre oblique inverse — et le
     * code la tient. Rien ne l'empêchait de cesser de la tenir. Une virgule non échappée coupe la
     * propriété en deux : « Réunion, salle B » devient un événement nommé « Réunion » suivi d'un
     * paramètre inconnu, que le lecteur ignore ou rejette.
     *
     * ⚠ L'ORDRE COMPTE, ET CE TEST LE GARDE : la barre oblique s'échappe EN PREMIER. L'échapper
     * après les virgules ré-échapperait celles qu'on vient d'écrire.
     */
    public function testLaVirguleLePointVirguleEtLaBarreSontEchappes(): void
    {
        $titre = 'Réunion, salle B; niveau 2' . \chr(92) . 'sous-sol';

        $ics = (new IcsWriter())->rediger(
            [[
                'id' => 'b3f1c2d4-0000-4000-8000-000000000002',
                'title' => $titre,
                'start' => '2026-06-01T09:00:00+00:00',
                'end' => '2026-06-01T10:00:00+00:00',
                'type' => 'meeting',
            ]],
            'Piscine',
        );

        // ⚠ ON SIMULE UN LECTEUR, ON NE COMPTE PAS DES BARRES DANS UN LITTÉRAL.
        //
        // Première version : j'écrivais la chaîne attendue à la main. En guillemets simples, deux
        // barres n'en valent qu'une, l'écriture en produit deux à partir d'une seule — le test
        // rougissait en accusant le code, qui avait raison. Compter des échappements dans un
        // littéral, c'est refaire à la main le travail qu'on veut vérifier, avec les mêmes chances
        // de se tromper et aucune d'être corrigé.
        //
        // On fait donc ce que fait un lecteur d'agenda : on déplie, on déséchappe, et on exige de
        // retrouver le titre EXACT. L'ordre inverse est imposé aussi — la barre en DERNIER, sinon
        // on désécharperait ce qu'on vient de produire.
        $deplie = str_replace("\r\n ", '', $ics);
        self::assertSame(1, preg_match('/^SUMMARY:(.*)$/m', $deplie, $trouve), 'Aucune ligne SUMMARY.');

        $lu = str_replace([chr(92) . ',', chr(92) . ';', chr(92) . 'n'], [',', ';', "\n"], rtrim($trouve[1], "\r"));
        $lu = str_replace(chr(92) . chr(92), chr(92), $lu);

        self::assertSame($titre, $lu, 'Un lecteur ne retrouve pas le titre écrit : l’échappement est faux ou incomplet.');
    }

    /** Les trois règles déjà documentées ne doivent pas régresser en ajoutant la quatrième. */
    public function testLesSeparateursRestentCrlfEtLeFichierSeTermineParUnSaut(): void
    {
        $ics = $this->ecrire();

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $ics), 'Un saut de ligne sans retour chariot : Apple et Outlook refusent le fichier.');
    }

    private function ecrire(): string
    {
        return (new IcsWriter())->rediger(
            [[
                'id' => 'b3f1c2d4-0000-4000-8000-000000000001',
                'title' => self::LONG_TITRE,
                'detail' => self::LONGUE_NOTE,
                'start' => '2026-06-01T09:00:00+00:00',
                'end' => '2026-06-01T11:00:00+00:00',
                'type' => 'meeting',
            ]],
            'Piscine municipale des Trois Fontaines',
        );
    }
}
