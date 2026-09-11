<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Enum;
use App\Membership\Entity\Membership;

/**
 * Les neuf types d'activite de D15.
 *
 * D15 (22/08) : « Neuf types d'activite couvrent l'ensemble des metiers evoques — billetterie/entree,
 * reservation de ressource, abonnement, location de materiel, vente de produits, cours/encadrement,
 * prestation sur rendez-vous, hebergement, restauration. Vingt metiers deviennent des combinaisons
 * de neuf briques. »
 *
 * ⚠ **POURQUOI PAS `ActivityType`, LE NOM EVIDENT.** Il est deja pris :
 * {@see \App\Crm\Enum\ActivityType} designe un ECHANGE COMMERCIAL — appel, courriel,
 * rendez-vous, note. Deux enumerations du meme nom pour deux notions sans rapport ne cassent rien
 * (deux espaces de noms), mais elles obligent quiconque lit `ActivityType` dans une revue a
 * chercher d'abord laquelle. Le nom retenu reprend la phrase de D15 : « Un ETABLISSEMENT compose
 * des activites. »
 *
 * ⚠ **NEUF, ET PAS DIX.** `specs/verticales/paquet.md` le pose comme une regle : « Un paquet qui a
 * besoin d'un dixieme type est un SIGNALEMENT, pas une extension : il remonte a l'integrateur, il
 * ne s'invente pas un type local. » Ajouter un cas ici sans arbitrage, c'est defaire D15 en douce.
 *
 * ⚠ **QUATRE ORTHOGRAPHES SONT REPRISES, CINQ SONT PROPOSEES.** `paquet.md` ecrit `entry`,
 * `resource_booking`, `coaching` et `equipment_rental` : celles-la ne se rediscutent pas. Les cinq
 * autres n'ont d'orthographe nulle part ; elles sont proposees ici et appartiennent au perimetre de
 * `specs/verticales/**`. Tant qu'aucun paquet verticale n'est publie, les renommer coute une
 * migration `UPDATE` d'une colonne. Apres, il faudra aussi reprendre les manifestes.
 *
 * ⚠ **`Membership` ET NON `Subscription`.** `App\Subscription` designe deja l'abonnement de
 * l'EXPLOITANT a Fluvia — ce qu'il nous paie. Ici il s'agit de l'abonnement de l'ADHERENT a son
 * club. Reutiliser le mot mettrait deux notions d'argent opposees sous le meme nom, au coeur du
 * modele.
 *
 * ⚠ **`Lodging` ET `Dining` EXISTENT AUSSI DANS `CapaciteCode`.** Ce n'est pas une collision
 * technique — deux enumerations, deux colonnes — mais une collision de LECTURE : un controle qui
 * chercherait la chaine `'lodging'` ne saurait pas laquelle des deux il regarde. Tout controle sur
 * cette enumeration lit donc les CAS (`case Lodging`), jamais les valeurs nues.
 */
enum EstablishmentActivity: string
{
    /** Billetterie, entree a l'unite ou par carte. */
    case Entry = 'entry';

    /** Reservation d'une ressource : un terrain, une ligne d'eau, une table, une piste. */
    case ResourceBooking = 'resource_booking';

    /** Abonnement de l'adherent, avec sa reconduction et ses impayes. */
    case Membership = 'membership';

    /** Location d'un objet contre caution : patins, chaussons, casier, audioguide. */
    case EquipmentRental = 'equipment_rental';

    /** Vente de marchandises, sur place ou a distance. */
    case ProductSale = 'product_sale';

    /** Cours et encadrement, avec la qualification de celui qui encadre. */
    case Coaching = 'coaching';

    /** Prestation sur rendez-vous : visite guidee, seance, soin. */
    case Appointment = 'appointment';

    /** Hebergement a la nuitee. */
    case Lodging = 'lodging';

    /** Restauration servie sur place. */
    case Dining = 'dining';

    /** Le libelle montre a l'exploitant quand on lui demande ce qu'il fait. */
    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Billetterie et entrées',
            self::ResourceBooking => 'Réservation de créneaux',
            self::Membership => 'Abonnements',
            self::EquipmentRental => 'Location de matériel',
            self::ProductSale => 'Vente de produits',
            self::Coaching => 'Cours et encadrement',
            self::Appointment => 'Rendez-vous',
            self::Lodging => 'Hébergement',
            self::Dining => 'Restauration',
        };
    }
}
