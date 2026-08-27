<?php

declare(strict_types=1);

namespace App\Marketing\Enum;

/**
 * Pourquoi une personne ciblée n'a pas été contactée.
 *
 * **Un motif par exclusion, jamais un total.** Trois cents exclus « pour diverses raisons » ne
 * disent rien ; trois cents exclus faute de consentement disent qu'il faut travailler le recueil du
 * consentement avant d'écrire un message de plus.
 *
 * Ces motifs sont **affichés à l'exploitant**, pas seulement journalisés. Un mécanisme qu'on ne peut
 * pas relire finit par ne plus être surveillé.
 */
enum ExclusionReason: string
{
    /**
     * Pas de consentement accordé sur ce canal — ou consentement refusé, expiré, à renouveler.
     *
     * Les quatre cas sont regroupés à dessein : ils appellent la même action de la part de
     * l'exploitant, et détailler « refusé » face à « jamais recueilli » exposerait une préférence
     * individuelle là où une statistique suffit.
     */
    case SansConsentement = 'sans_consentement';

    /**
     * Plafond de sollicitation atteint sur la période.
     *
     * Un client qui reçoit cinq messages en une semaine se désabonne, et on perd le canal pour
     * toujours. Mieux vaut une campagne qui touche moins de monde qu'un canal grillé.
     */
    case TropSollicite = 'trop_sollicite';

    /**
     * Une variable du message n'a pas pu être remplie pour cette personne.
     *
     * `Bonjour {{prenom}},` chez quelqu'un dont on ignore le prénom donnerait « Bonjour , » — et ce
     * genre de message coûte plus cher en confiance qu'il ne rapporte en visites. On écarte cette
     * personne **sans bloquer les autres** (RG-CMP-04, CA-4).
     */
    case VariableNonResolue = 'variable_non_resolue';

    /** Pas d'adresse ou de numéro pour ce canal : il n'y a rien où écrire. */
    case CoordonneeManquante = 'coordonnee_manquante';

    public function label(): string
    {
        return match ($this) {
            self::SansConsentement => 'Sans consentement sur ce canal',
            self::TropSollicite => 'Déjà trop sollicité',
            self::VariableNonResolue => 'Information manquante dans le message',
            self::CoordonneeManquante => 'Pas de coordonnée pour ce canal',
        };
    }
}
