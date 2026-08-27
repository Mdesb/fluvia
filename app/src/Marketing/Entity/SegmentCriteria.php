<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

/**
 * Les critères qu'un segment sait appliquer — la liste fermée, et le seul endroit qui la porte.
 *
 * **Fermée, parce qu'une liste ouverte ne se cloisonne pas.** Le contrôle de périmètre porte sur les
 * clients résolus ; une expression libre rendrait ce contrôle impossible à tenir, et un exploitant
 * curieux pourrait décrire les clients d'un autre groupe.
 *
 * **Une seule liste, parce que deux divergent.** Le validateur de `Segment` s'y réfère pour refuser
 * un critère inconnu, et `SegmentResolver` s'y réfère pour l'appliquer. Si l'une des deux avait sa
 * propre copie, un critère accepté à la saisie finirait ignoré à l'exécution — c'est-à-dire une
 * audience plus large que ce que l'écran a montré.
 */
final class SegmentCriteria
{
    /** Aucune visite depuis N jours — le critère de reconquête, celui qu'on écrit en premier. */
    public const SANS_VISITE_DEPUIS_JOURS = 'sansVisiteDepuisJours';

    /** Chiffre d'affaires cumulé au moins égal à ce montant. */
    public const CA_CUMULE_MIN = 'caCumuleMin';

    /** Chiffre d'affaires cumulé au plus égal à ce montant. */
    public const CA_CUMULE_MAX = 'caCumuleMax';

    /** Âge minimal, calculé depuis la date de naissance — jamais stocké (il vieillit tout seul). */
    public const AGE_MIN = 'ageMin';

    /** Âge maximal. */
    public const AGE_MAX = 'ageMax';

    /** Type de client : `physique` ou `morale`. */
    public const TYPE = 'type';

    /**
     * Établissement de rattachement — confronté au périmètre, jamais cru sur parole.
     *
     * Un critère d'établissement fourni par l'appelant ne contourne pas le cloisonnement : il le
     * restreint. Viser un établissement hors périmètre ne rend pas d'erreur, ça rend zéro client —
     * et c'est le bon comportement : un refus explicite confirmerait l'existence de l'établissement.
     */
    public const ETABLISSEMENT = 'etablissement';

    /** @var list<string> */
    public const CLES_CONNUES = [
        self::SANS_VISITE_DEPUIS_JOURS,
        self::CA_CUMULE_MIN,
        self::CA_CUMULE_MAX,
        self::AGE_MIN,
        self::AGE_MAX,
        self::TYPE,
        self::ETABLISSEMENT,
    ];
}
