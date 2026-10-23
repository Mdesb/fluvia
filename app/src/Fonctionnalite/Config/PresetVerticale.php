<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Config;

use App\Fonctionnalite\Enum\CapaciteCode;
use App\Fonctionnalite\Enum\Metier;

/**
 * Preset de capacités par verticale métier — configuration PHP (pas un `if` métier éparpillé,
 * §4 constitution.md), appliqué à un établissement via `App\Fonctionnalite\Service\Fonctionnalites::appliquerPreset()`.
 *
 * ⚠ HYPOTHÈSE (aucune source ne fige cette liste) : les jeux de capacités par verticale sont déduits
 * des modules déjà livrés (Piscine L6, Sport L-Sport) pour `piscine`/`sport` ; `padel`/`patinoire`/
 * `musee` (verticales non encore construites, cf. §5 constitution.md) reçoivent un jeu plausible par
 * analogie, à confirmer avec IT Cotation avant construction réelle de ces verticales.
 */
final class PresetVerticale
{
    /** @var array<string, list<string>> */
    private const CAPACITES = [
        // Piscine (L6) : POSS, bassins/créneaux, casiers/caution, encadrants MNS/BNSSA, SEPA/recouvrement
        // (régie), porte-monnaie (RG-M4-03).
        'piscine' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Reservation->value,
            CapaciteCode::Poss->value,
            CapaciteCode::Casiers->value,
            CapaciteCode::Encadrants->value,
            CapaciteCode::PorteMonnaie->value,
            CapaciteCode::Sepa->value,
            CapaciteCode::Recouvrement->value,
        ],
        // Sport/Fitness : abonnement + anti-impayés couplé à l'accès, SEPA, accès nocturne, no-show
        // (réservation de cours).
        'sport' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Sepa->value,
            CapaciteCode::Recouvrement->value,
            CapaciteCode::AccesNocturne->value,
            CapaciteCode::PorteMonnaie->value,
            CapaciteCode::NoShow->value,
        ],
        // Padel : réservation de terrain à forte volumétrie, no-show pénalisant, vente à distance.
        'padel' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Reservation->value,
            CapaciteCode::NoShow->value,
            CapaciteCode::BoutiqueEnLigne->value,
            CapaciteCode::PorteMonnaie->value,
        ],
        // Patinoire : créneaux publics/scolaires, casiers, location de patins, encadrants.
        'patinoire' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Reservation->value,
            CapaciteCode::Casiers->value,
            CapaciteCode::LocationMateriel->value,
            CapaciteCode::Encadrants->value,
        ],
        // Musée : billetterie/créneaux de visite, vente à distance ; pas de casiers/SEPA par défaut.
        // `agenda` : SEULE verticale où un produit daté existe déjà dans le modèle — une
        // `Musee\Entity\Exposition` porte un produit, une date de début, une date de fin et une
        // jauge. Ailleurs, le daté vit dans la Réservation, qui ne référence aucun produit.
        'musee' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Reservation->value,
            CapaciteCode::BoutiqueEnLigne->value,
            CapaciteCode::Agenda->value,
        ],
    ];

    /**
     * Capacités communes à TOUTES les verticales, ajoutées à chaque preset.
     *
     * ⚠ POURQUOI `comptabilite` ET `stock` SONT ICI ET PAS DANS CHAQUE LISTE. Tout exploitant tient
     * des comptes et vend des marchandises : les omettre d'une verticale ne décrirait pas un métier,
     * ça retirerait un module qui fonctionne aujourd'hui chez tout le monde.
     *
     * ⚠ ET C'EST LE POINT DE PRUDENCE PRINCIPAL. Ces deux modules sont visibles de tous
     * aujourd'hui parce que rien ne les garde. Le jour où un écran s'adossera à ces capacités, un
     * établissement qui ne les porte pas perdra ce qu'il utilisait la veille — il faudra donc
     * accorder les capacités à l'existant AVANT de brancher quoi que ce soit.
     *
     * `agenda` n'est pas commun : il décrit une offre datée, ce que la plupart des verticales
     * n'ont pas dans leur catalogue.
     *
     * @var list<string>
     */
    private const COMMUNES = [
        CapaciteCode::Comptabilite->value,
        CapaciteCode::Stock->value,
    ];

    /**
     * ⚠ LE PRÉRÉGLAGE ACTIVE AUSSI LA VERTICALE ELLE-MÊME, DEPUIS LE 08/10. Le menu masque désormais
     * les modules d'un établissement qui ne les a pas activés (décision de Maxime du 08/10), et
     * l'écran « Piscine » se garde par la capacité `piscine`. Sans elle dans son propre préréglage,
     * une piscine ouverte avec le métier « piscine » n'aurait pas vu son écran métier. Le code de
     * la verticale est sa valeur `Metier`, que `CatalogueCapacites::estVerticale()` reconnaît déjà.
     *
     * Sans métier (`null` : une structure ouverte sans métier reconnu, un cinéma par exemple), on
     * n'active que les communes : tout exploitant tient des comptes et vend des marchandises.
     *
     * @return list<string>
     */
    public static function capacites(?Metier $metier): array
    {
        $propres = $metier === null ? [] : [...(self::CAPACITES[$metier->value] ?? []), $metier->value];

        // `array_values` + `array_unique` : une verticale qui listerait déjà une commune ne doit
        // pas la recevoir deux fois — un doublon ferait échouer l'insertion, pas la lecture.
        return array_values(array_unique([...$propres, ...self::COMMUNES]));
    }
}
