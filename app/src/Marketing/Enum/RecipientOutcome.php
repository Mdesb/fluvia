<?php

declare(strict_types=1);

namespace App\Marketing\Enum;

/**
 * Ce qu'il est advenu d'un destinataire — et pourquoi il faut le dire par personne.
 *
 * **« 1 240 ciblés, 310 exclus faute de consentement » est une information dont l'exploitant a
 * besoin** (RG-CMP-08). C'est souvent le signal qu'il faut d'abord travailler le recueil du
 * consentement, pas le message. Un chiffre global de « 930 envoyés » cacherait exactement ça.
 *
 * **`Journalise` et pas `Envoye`.** Tant qu'aucun prestataire n'est branché, rien ne part. Écrire
 * « envoyé » ferait croire à l'exploitant que son client a reçu quelque chose — et il attendrait un
 * retour qui ne viendra jamais. Le jour où un adaptateur réel existe, il rendra `Envoye`, et c'est
 * la seule chose qui changera.
 */
enum RecipientOutcome: string
{
    /** Le message est parti pour de bon. N'existe pas encore : aucun prestataire n'est raccordé. */
    case Envoye = 'envoye';

    /** Le message a été écrit au journal. C'est le seul état atteignable aujourd'hui. */
    case Journalise = 'journalise';

    /** Écarté, pour un motif que porte `ExclusionReason`. */
    case Exclu = 'exclu';

    /**
     * Membre du groupe témoin : délibérément NON contacté.
     *
     * Ce n'est pas une exclusion — c'est une décision de mesure. Le confondre avec un exclu
     * fausserait les deux chiffres : on croirait avoir raté 10 % de son audience, et on perdrait le
     * seul point de comparaison qui permet de dire si la campagne a servi à quelque chose.
     */
    case Temoin = 'temoin';

    public function label(): string
    {
        return match ($this) {
            self::Envoye => 'Envoyé',
            self::Journalise => 'Journalisé',
            self::Exclu => 'Exclu',
            self::Temoin => 'Groupe témoin',
        };
    }
}
