<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Traçabilité de génération/envoi d'un `Export` (§2.8 plan-reporting.md). */
enum StatutExport: string
{
    case Genere = 'genere';
    case Envoye = 'envoye';
    case Echec = 'echec';
    /**
     * Généré, et sciemment NON expédié : le transport de courriel configuré n'expédie rien.
     *
     * ⚠ CE N'EST NI `Envoye` NI `Echec`, et confondre avec l'un ou l'autre ment dans les deux sens.
     * `Envoye` affirmerait une remise qui n'a pas eu lieu, à un destinataire qui n'a rien reçu —
     * et de façon irréparable, puisque `envoyeLe` serait renseigné comme pour un vrai. `Echec`
     * affirmerait une panne : or la génération a réussi, le fichier est là et se télécharge.
     *
     * Ajouté le 15/09/2026 avant d'autoriser `reporting:executer-rapports` dans
     * `TACHES_AUTORISEES` (arbitrage n°1 de `COORDINATION/A-REVOIR.md`). L'ordre importait :
     * autoriser d'abord aurait produit des lignes « envoyé » fausses et indiscernables des vraies.
     */
    case NonExpedie = 'non_expedie';

    /**
     * Le fichier de cet export est-il téléchargeable ?
     *
     * ⚠ LA RÈGLE VIT ICI, ET PLUS DANS LES PORTES. Elle était écrite deux fois, et en COMPLÉMENT
     * l'une de l'autre : `ExportTelechargerController` autorisait `Genere|Envoye`,
     * `TelechargerExportProvider` refusait `Echec`. Les deux s'accordaient par coïncidence tant
     * qu'il y avait trois états ; au quatrième, la première porte refusait un fichier que la
     * seconde servait.
     *
     * Le `match` est SANS bras par défaut : ajouter un cas à cet enum ne compile plus tant que la
     * question « ce statut a-t-il un fichier ? » n'a pas reçu de réponse. C'est le seul garde-fou
     * qui n'a pas besoin qu'on pense à lui.
     */
    public function fichierDisponible(): bool
    {
        return match ($this) {
            self::Genere, self::Envoye, self::NonExpedie => true,
            self::Echec => false,
        };
    }
}
