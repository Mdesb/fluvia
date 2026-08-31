<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/**
 * LES ÉTAPES D'UNE AFFAIRE — et surtout **qui les fait avancer**.
 *
 * | Étape | Qui la pose |
 * |---|---|
 * | `ToQualify` | l'humain |
 * | `Qualified` | l'humain |
 * | `QuoteSent` | **le système**, à l'émission du devis |
 * | `Won` | **le système**, à l'acceptation du devis |
 * | `Lost` | le système au refus, ou l'humain avec un motif |
 *
 * **C'est la répartition qui compte, pas la liste.** Le défaut de tous les CRM est le même : le
 * commercial oublie de déplacer sa carte, et au bout d'un mois le tableau ne décrit plus rien —
 * personne ne s'en sert, et personne ne dit pourquoi.
 *
 * > **Une étape que le logiciel peut déduire ne doit pas être tenue à la main, sinon le pipeline ment.**
 *
 * `CommercialDocument` sait déjà quand un devis est émis, accepté ou refusé. Les trois dernières
 * étapes se lisent donc **du document**, jamais d'une valeur recopiée — une copie prend
 * inévitablement du retard, et une étape en retard est pire qu'absente : elle a l'air d'être à jour.
 *
 * Seules les deux premières demandent une saisie. C'est le minimum irréductible : aucun système ne
 * peut savoir qu'un appel a bien tourné.
 */
enum OpportunityStage: string
{
    /** Une demande est arrivée. On ne sait pas encore si elle a un objet. */
    case ToQualify = 'to_qualify';

    /** Besoin, date et budget sont connus. C'est de là qu'un devis peut partir. */
    case Qualified = 'qualified';

    /** Devis émis — posé par le document, pas par la main. */
    case QuoteSent = 'quote_sent';

    /** Devis accepté. */
    case Won = 'won';

    /** Devis refusé, ou affaire abandonnée avant devis. */
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::ToQualify => 'Demande reçue',
            self::Qualified => 'Qualifiée',
            self::QuoteSent => 'Devis envoyé',
            self::Won => 'Gagnée',
            self::Lost => 'Perdue',
        };
    }

    /**
     * Vrai quand l'étape se pose à la main.
     *
     * `Lost` en fait partie : une affaire peut mourir **avant** qu'un devis existe — le client ne
     * rappelle pas, le projet est annulé. C'est le cas le plus fréquent, et un pipeline qui ne saurait
     * perdre qu'après un devis refusé garderait éternellement des affaires mortes en « Qualifiée ».
     */
    public function manuallySettable(): bool
    {
        return match ($this) {
            self::ToQualify, self::Qualified, self::Lost => true,
            self::QuoteSent, self::Won => false,
        };
    }

    /** Vrai quand l'affaire est close : elle sort du prévisionnel. */
    public function closed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }
}
