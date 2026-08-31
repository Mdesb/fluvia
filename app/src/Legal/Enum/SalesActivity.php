<?php

declare(strict_types=1);

namespace App\Legal\Enum;

/**
 * CE QUE L'EXPLOITANT VEND — et c'est cette liste, pas le mot « billetterie », qui décide des clauses.
 *
 * **Pourquoi une énumération plutôt qu'un texte unique.** Maxime, le 27/08 : *« n'oublie pas qu'on ne
 * fait pas que de la billetterie »*. Le rappel n'est pas cosmétique : **le droit de rétractation change
 * complètement d'une activité à l'autre**, et un modèle de CGV unique serait faux pour la majorité des
 * exploitants du produit.
 *
 * | Ce qui est vendu | Rétractation |
 * |---|---|
 * | Billet daté, créneau de padel, visite guidée | **aucune** — art. L221-28 12° : activité de loisirs fournie à une date déterminée |
 * | Nuitée, séjour | **aucune** — même exception, branche « hébergement autre que résidentiel » |
 * | Mug, cadenas, marchandise de la boutique | **14 jours**, avec formulaire type obligatoire |
 * | Abonnement sans date déterminée | **14 jours**, sauf exécution commencée sur demande expresse |
 * | Cours, stage à dates fixées | **aucune** si les dates sont déterminées ; sinon 14 jours |
 *
 * **Le piège, et c'est celui qui coûte cher.** Un exploitant qui vend des entrées *et* des mugs applique
 * l'exception aux deux, parce que son modèle de CGV a été écrit pour la billetterie. Il refuse alors un
 * remboursement légalement dû — et c'est le genre de clause qu'une association de consommateurs relève
 * sans même acheter.
 *
 * > **Une clause fausse ne se distingue pas d'une clause juste tant que personne ne la conteste.**
 *
 * ⚠ **Ces textes sont des modèles à faire relire.** Ils encodent une lecture des textes en vigueur, pas
 * un avis juridique : l'exploitant reste responsable de ce qu'il publie, et le générateur le dit à
 * l'écran plutôt que de le cacher dans une note de bas de page.
 */
enum SalesActivity: string
{
    /** Billets et créneaux à date ou période déterminée (spectacle, piscine, patinoire, padel, musée). */
    case DatedLeisure = 'dated_leisure';

    /** Hébergement fourni à une date déterminée (nuitée, séjour). */
    case Accommodation = 'accommodation';

    /** Marchandises physiques expédiées ou retirées (boutique, click and collect). */
    case PhysicalGoods = 'physical_goods';

    /** Abonnements et cartes rechargeables, sans date d'exécution déterminée. */
    case Subscription = 'subscription';

    /** Cours, stages et locations de matériel. */
    case CourseOrRental = 'course_or_rental';

    /** Restauration et consommations sur place. */
    case Catering = 'catering';

    public function label(): string
    {
        return match ($this) {
            self::DatedLeisure => 'Billets et créneaux à date déterminée',
            self::Accommodation => 'Hébergement (nuitées, séjours)',
            self::PhysicalGoods => 'Marchandises physiques',
            self::Subscription => 'Abonnements et cartes',
            self::CourseOrRental => 'Cours, stages et locations',
            self::Catering => 'Restauration',
        };
    }

    /**
     * Vrai quand l'exception de l'article L221-28 12° s'applique : prestation de loisirs ou
     * d'hébergement **fournie à une date ou selon une périodicité déterminée**.
     *
     * `CourseOrRental` renvoie `false` **délibérément**. Un stage aux dates fixées entre bien dans
     * l'exception, une location de matériel à durée libre n'y entre pas, et l'écran ne peut pas
     * trancher à la place de l'exploitant. **Le défaut par défaut ne peut pas mentir** : entre annoncer
     * à tort un droit de rétractation et le refuser à tort, seul le second se retourne contre le
     * vendeur.
     */
    public function withdrawalExempted(): bool
    {
        return match ($this) {
            self::DatedLeisure, self::Accommodation, self::Catering => true,
            self::PhysicalGoods, self::Subscription, self::CourseOrRental => false,
        };
    }
}
