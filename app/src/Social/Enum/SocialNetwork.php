<?php

declare(strict_types=1);

namespace App\Social\Enum;

/**
 * Réseaux sociaux supportés par le module de publication (D14).
 *
 * **Uniquement des réseaux ouverts** pour l'instant : Mastodon et Bluesky n'exigent aucune
 * vérification d'entreprise ni revue applicative, ce qui permet de prouver toute la mécanique — file,
 * reprises, chiffrement et cloisonnement des jetons, collecte planifiée — pendant que les revues des
 * plateformes fermées sont en cours (D14, séquencement).
 *
 * Les adaptateurs Meta (Page Facebook, Instagram) sont `SOC-4`, en statut `EXTERNE` : ils attendent
 * l'immatriculation de la société porteuse et ne sont **pas** déclarés ici. Déclarer une valeur
 * d'énumération pour un réseau qu'aucun adaptateur ne sait servir donnerait un compte connectable et
 * jamais publiable — un mensonge dans le modèle, découvert par l'utilisateur (D19 : ce qui dépend d'un
 * tiers est consigné, jamais anticipé dans le code).
 */
enum SocialNetwork: string
{
    case Mastodon = 'mastodon';
    case Bluesky = 'bluesky';

    /**
     * Un hôte est-il exigé pour ce réseau ?
     *
     * Mastodon est fédéré : le jeton ne vaut que pour l'instance qui l'a émis, et publier sans savoir
     * où revient à ne pas publier. Bluesky a un fournisseur de données personnel (PDS) qui vaut par
     * défaut `https://bsky.social`, mais reste substituable — l'hôte est donc facultatif, pas absent.
     */
    public function requiresHost(): bool
    {
        return $this === self::Mastodon;
    }

    public function defaultHost(): ?string
    {
        return match ($this) {
            self::Mastodon => null,
            self::Bluesky => 'https://bsky.social',
        };
    }
}
