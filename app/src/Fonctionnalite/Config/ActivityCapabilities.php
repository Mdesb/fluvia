<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Config;

use App\Fonctionnalite\Enum\EstablishmentActivity;
use App\Fonctionnalite\Enum\CapabilityCoverage;
use App\Fonctionnalite\Enum\CapaciteCode;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Membership\Entity\Membership;

/**
 * Ce que chaque activite allume — et le classement des vingt-six capacites.
 *
 * ── POURQUOI EN CODE ET PAS EN BASE ────────────────────────────────────────────────────────────
 *
 * Une capacite EST DEJA un artefact de code : l'ajouter demande un cas dans {@see CapaciteCode} ET
 * un descripteur dans {@see CatalogueCapacites}. Le 06/09, quelqu'un n'a fait que le premier :
 * l'accueil du site public a rendu 500 pendant que `/metiers` et `/blog` repondaient normalement.
 *
 * Mettre ce rattachement en base ajouterait une TROISIEME liste que rien ne relierait aux deux
 * autres, et qui pourrait rester silencieusement incomplete pour une capacite neuve — le defaut
 * meme que le referentiel des metiers existe pour supprimer, reintroduit un etage plus bas.
 *
 * Et cela ne coute rien a l'objectif « ajouter un metier sans deploiement » : ajouter un METIER
 * reste une ligne de donnees ; c'est ajouter une CAPACITE qui demande un deploiement, ce qui etait
 * deja vrai avant ce lot.
 *
 * ── POURQUOI DES `match` SANS BRANCHE PAR DEFAUT ───────────────────────────────────────────────
 *
 * ⚠ **UN `?? []` EST INTERDIT DANS CE FICHIER.** C'est la forme silencieuse : `PresetVerticale`
 * l'emploie, et un metier sans prereglage y recoit deux capacites sur quinze sans que rien ne le
 * dise — le test cense l'attraper reste vert, parce qu'il ne verifie que la non-vacuite et que les
 * deux communes la garantissent toujours.
 *
 * Les deux `match` ci-dessous n'ont donc pas de branche par defaut : une dixieme activite ou une
 * vingt-septieme capacite fait lever `\UnhandledMatchError`. C'est bruyant, et c'est voulu — sur
 * les neuf endroits ou la liste des metiers est figee aujourd'hui, le seul qui ait ete corrige le
 * jour meme est precisement celui qui criait.
 */
final class ActivityCapabilities
{
    /**
     * Les capacites qu'une activite allume.
     *
     * ⚠ **LA TABLE S'ECRIT DANS CE SENS, ET DANS CELUI-LA SEULEMENT.** Neuf entrees lisibles d'un
     * coup d'oeil. Le sens inverse — « quelles activites servent cette capacite ? » — se DERIVE en
     * parcourant les neuf ({@see self::coverageOf()}). Deux tables ecrites a la main divergeraient
     * au premier ajout ; une ecrite et une derivee, non.
     *
     * `Casiers` sous `EquipmentRental` n'est pas une facilite de classement : `paquet.md` ecrit
     * litteralement `type: equipment_rental` / `subject: locker` pour la piscine.
     *
     * @return list<CapaciteCode>
     */
    public static function of(EstablishmentActivity $activity): array
    {
        return match ($activity) {
            EstablishmentActivity::Entry => [
                CapaciteCode::ControleAcces,
            ],
            EstablishmentActivity::ResourceBooking => [
                CapaciteCode::Reservation,
                CapaciteCode::NoShow,
            ],
            EstablishmentActivity::Membership => [
                CapaciteCode::Sepa,
                CapaciteCode::Recouvrement,
                CapaciteCode::PorteMonnaie,
            ],
            EstablishmentActivity::EquipmentRental => [
                CapaciteCode::LocationMateriel,
                CapaciteCode::Casiers,
            ],
            EstablishmentActivity::ProductSale => [
                CapaciteCode::BoutiqueEnLigne,
            ],
            EstablishmentActivity::Coaching => [
                CapaciteCode::Encadrants,
                CapaciteCode::Reservation,
            ],
            EstablishmentActivity::Appointment => [
                CapaciteCode::Reservation,
                CapaciteCode::Agenda,
            ],
            EstablishmentActivity::Lodging => [
                CapaciteCode::Lodging,
                CapaciteCode::Stay,
            ],
            EstablishmentActivity::Dining => [
                CapaciteCode::Dining,
            ],
        };
    }

    /**
     * Sous quel regime une capacite tombe.
     *
     * ⚠ **`Vertical` EST DELEGUE, PAS RECOPIE.** Ecrire ici les cinq noms de verticales ferait
     * diverger cette liste de {@see CatalogueCapacites::estVerticale()} le jour d'une sixieme —
     * c'est-a-dire exactement le defaut d'origine, sous un autre nom.
     */
    public static function coverageOf(CapaciteCode $code): CapabilityCoverage
    {
        if (CatalogueCapacites::estVerticale($code)) {
            return CapabilityCoverage::Vertical;
        }

        return match ($code) {
            // Servies par une activite. La liste doit etre l'exacte union de `of()` — c'est un test.
            CapaciteCode::ControleAcces,
            CapaciteCode::Reservation,
            CapaciteCode::NoShow,
            CapaciteCode::Sepa,
            CapaciteCode::Recouvrement,
            CapaciteCode::PorteMonnaie,
            CapaciteCode::LocationMateriel,
            CapaciteCode::Casiers,
            CapaciteCode::BoutiqueEnLigne,
            CapaciteCode::Encadrants,
            CapaciteCode::Agenda,
            CapaciteCode::Lodging,
            CapaciteCode::Stay,
            CapaciteCode::Dining => CapabilityCoverage::ByActivity,

            // ⚠ Le socle, avec l'argument de `PresetVerticale::COMMUNES` : tout exploitant tient des
            //   comptes et vend des marchandises. Les omettre ne decrirait pas un metier, ca
            //   retirerait un module qui fonctionne aujourd'hui chez tout le monde.
            CapaciteCode::Comptabilite,
            CapaciteCode::Stock => CapabilityCoverage::Common,

            // ⚠ Regles propres — jamais suggerees. Ce n'est pas un trou : c'est ce que D15 appelle
            //   « une regle reellement nouvelle », le seul cas ou un module de code subsiste.
            CapaciteCode::Poss,
            CapaciteCode::AccesNocturne,
            CapaciteCode::Finance,
            CapaciteCode::Social,
            CapaciteCode::Connecteurs => CapabilityCoverage::OwnRule,

            // Les cinq verticales sont deja sorties plus haut. Les nommer ici serait la recopie
            // qu'on refuse ; ce `match` n'a donc pas a les traiter, et c'est pourquoi il peut
            // rester sans branche par defaut.
            CapaciteCode::Piscine,
            CapaciteCode::Sport,
            CapaciteCode::Padel,
            CapaciteCode::Patinoire,
            CapaciteCode::Musee => CapabilityCoverage::Vertical,
        };
    }

    /**
     * Les modules a suggerer pour un jeu d'activites.
     *
     * L'union de ce que les activites allument, plus le socle, moins les verticales — qui ne sont
     * pas des modules vendables. Les regles propres n'y sont jamais : un metier qui en a besoin les
     * recoit par son module de code, pas par une suggestion.
     *
     * @param list<EstablishmentActivity> $activities
     *
     * @return list<string> les valeurs de {@see CapaciteCode}, dedupliquees
     */
    public static function modulesFor(array $activities): array
    {
        $codes = [];

        foreach ($activities as $activity) {
            foreach (self::of($activity) as $code) {
                $codes[$code->value] = true;
            }
        }

        foreach (CapaciteCode::cases() as $code) {
            if (CapabilityCoverage::Common === self::coverageOf($code)) {
                $codes[$code->value] = true;
            }
        }

        return array_keys($codes);
    }
}
