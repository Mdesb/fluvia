<?php

declare(strict_types=1);

namespace App\Website\Config;

use App\Fonctionnalite\Enum\EstablishmentActivity;
use App\Membership\Entity\Membership;

/**
 * Les cinq metiers servis TANT QU'AUCUNE LIGNE N'EXISTE en base.
 *
 * ── ⚠ CE BLOC A ETE DEPLACE, PAS RETAPE ────────────────────────────────────────────────────────
 *
 * `NOMS` vient de `MetierCatalog`, decoupe et recolle par script, octets verifies identiques. Les
 * chapos sont des concatenations sur plusieurs lignes portant des apostrophes typographiques : les
 * retaper, c'etait une chance sur cinq d'en normaliser une. Le rendu aurait change d'un octet, et
 * AUCUN test existant ne l'aurait vu — ils cherchent des sous-chaines courtes (« POSS »,
 * « affutage », « audioguide »).
 *
 * ── ⚠ UNE SEULE LISTE, TROIS LECTEURS ──────────────────────────────────────────────────────────
 *
 * Cette classe sert, et ne servira jamais, que trois choses :
 *   1. `MetierCatalog`, quand la base ne porte aucune ligne ;
 *   2. la commande `website:trades:seed`, qui materialise ces entrees ;
 *   3. le garde-fou n°55, qui compare cette liste a l'enumeration `Metier`.
 *
 * Trois usages, une source : ils ne peuvent pas diverger. C'est tout l'interet.
 *
 * ── ⚠ ELLE NE GRANDIT JAMAIS ───────────────────────────────────────────────────────────────────
 *
 * Un sixieme metier est une LIGNE DE BASE, pas une entree ici. Ajouter un metier a ce fichier
 * demanderait un deploiement — exactement ce que le referentiel existe pour supprimer. Le garde-fou
 * n°55 refuse une entree qui n'aurait pas son cas dans `Metier`, et `Metier` est gelee a cinq.
 *
 * ── ⚠ LES CODES NE SE DERIVENT PAS DE `Metier` ─────────────────────────────────────────────────
 *
 * `codes()` lit les cles de `NOMS`, pas `Metier::cases()`. C'est deliberement redondant : si les
 * deux venaient de la meme source, le garde-fou qui les compare ne pourrait jamais tomber. Un
 * temoin qui partage l'angle mort de ce qu'il surveille ne surveille rien.
 */
final class TradeFallback
{
    /**
     * Ce que chaque métier appelle son quotidien.
     *
     * Le `nom` sert au menu, le `titre` à l'onglet et aux moteurs — les deux diffèrent parce qu'un
     * titre de recherche contient les mots qu'on tape, et un libellé de menu doit tenir sur une ligne.
     *
     * @var array<string, array{nom: string, titre: string, chapo: string}>
     */
    public const NOMS = [
        'piscine' => [
            'nom' => 'Piscines et centres aquatiques',
            'titre' => 'Logiciel de gestion pour piscine et centre aquatique',
            'chapo' => "Entrées, créneaux de bassin, casiers, encadrants : la journée d'une piscine tient "
                ."sur des contraintes que peu de logiciels connaissent. Fluvia les porte, du POSS à la caution "
                ."d'un casier forcé.",
        ],
        'sport' => [
            'nom' => 'Salles de sport et fitness',
            'titre' => 'Logiciel de gestion pour salle de sport et club de fitness',
            'chapo' => "Abonnements prélevés, accès contrôlé, ouverture sans personnel : une salle vit de la "
                ."régularité de ses encaissements et de la fiabilité de sa porte. Fluvia lie les deux.",
        ],
        'padel' => [
            'nom' => 'Padel et sports de raquette',
            'titre' => 'Logiciel de réservation pour club de padel',
            'chapo' => "Terrains réservés à la demi-heure, joueurs qui ne viennent pas, tournois à organiser, "
                ."éclairage à ne pas laisser allumé. Un club de padel se pilote au créneau.",
        ],
        'patinoire' => [
            'nom' => 'Patinoires',
            'titre' => 'Logiciel de gestion pour patinoire',
            'chapo' => "Un parc de patins à louer, à affûter et à rendre, des séances publiques et scolaires, "
                ."une saison qui dure quelques mois. Une patinoire ne se gère pas comme une salle ouverte à l'année.",
        ],
        'musee' => [
            'nom' => 'Musées et sites de visite',
            'titre' => 'Logiciel de billetterie pour musée et site de visite',
            'chapo' => "Billetterie horodatée, jauges par salle, visites guidées, gratuités à justifier, "
                ."revendeurs en ligne à rapprocher. Un musée compte ses entrées autrement qu'un équipement sportif.",
        ],
    ];

    /**
     * Ce que chaque metier compose, au sens de D15.
     *
     * ⚠ **CES COMPOSITIONS NE SONT PAS INVENTEES ICI.** Elles sont reprises de
     * `specs/verticales/composition.md`, ou elles ont deja ete etablies verticale par verticale :
     * « Piscine : entree · reservation de ressource · cours/encadrement · location de materiel
     * (casier) », et ainsi de suite. Les redeciderait ici en ferait une seconde source.
     *
     * @var array<string, list<EstablishmentActivity>>
     */
    private const ACTIVITES = [
        'piscine' => [
            EstablishmentActivity::Entry,
            EstablishmentActivity::ResourceBooking,
            EstablishmentActivity::Coaching,
            EstablishmentActivity::EquipmentRental,
        ],
        'sport' => [
            EstablishmentActivity::Membership,
            EstablishmentActivity::Entry,
        ],
        'padel' => [
            EstablishmentActivity::ResourceBooking,
            EstablishmentActivity::EquipmentRental,
        ],
        'patinoire' => [
            EstablishmentActivity::Entry,
            EstablishmentActivity::EquipmentRental,
        ],
        'musee' => [
            EstablishmentActivity::Entry,
            EstablishmentActivity::Appointment,
            EstablishmentActivity::EquipmentRental,
            EstablishmentActivity::Membership,
        ],
    ];

    /**
     * Les codes, dans l'ordre d'affichage.
     *
     * ⚠ **L'ORDRE VIENT DE LA DECLARATION DE `NOMS`**, qui reprend celle de `Metier` : piscine,
     * sport, padel, patinoire, musee. Il n'est ni alphabetique ni arbitraire, et le changer
     * changerait le rendu de la page des metiers. C'est une condition du « rendu identique ».
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::NOMS);
    }

    /**
     * Les cinq entrees, telles que la commande de materialisation les pose.
     *
     * Le rang vaut 10, 20, 30, 40, 50 : des trous entre les valeurs, pour qu'inserer un metier
     * entre deux autres n'oblige pas a renumeroter les suivants.
     *
     * ⚠ **`slug` VAUT `code` POUR CES CINQ**, et c'est verifiable : aucun des cinq ne contient de
     * souligne. Les deux champs restent distincts dans le modele parce qu'un slug publie est une
     * promesse tenue par quelqu'un d'autre, alors qu'un code est une cle interne immuable.
     *
     * @return list<array{code: string, slug: string, name: string, searchTitle: string, lead: string, position: int, activities: list<EstablishmentActivity>}>
     */
    public static function entries(): array
    {
        $entrees = [];
        $rang = 0;

        foreach (self::NOMS as $code => $textes) {
            $rang += 10;

            $entrees[] = [
                'code' => $code,
                'slug' => $code,
                'name' => $textes['nom'],
                'searchTitle' => $textes['titre'],
                'lead' => $textes['chapo'],
                'position' => $rang,
                'activities' => self::ACTIVITES[$code] ?? [],
            ];
        }

        return $entrees;
    }
}
