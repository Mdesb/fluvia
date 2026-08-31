<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use App\Crm\Entity\Client;

/**
 * Les variables qu'un message sait remplir — et le refus de faire semblant pour les autres.
 *
 * **RG-CMP-04 : une variable non résolue EMPÊCHE l'envoi à cette personne.** Elle ne se remplace pas
 * par du vide. « Bonjour , » coûte plus cher en confiance qu'il ne rapporte en visites, et personne
 * ne relit ses propres envois assez vite pour s'en apercevoir.
 *
 * L'exclusion est **individuelle** : les autres destinataires partent. Bloquer toute la campagne
 * parce qu'un client sur mille n'a pas de prénom serait une punition collective pour une donnée
 * manquante.
 *
 * ── UNE SEULE LISTE, ET ELLE EST COURTE ─────────────────────────────────────────────────────────
 *
 * Le validateur de `Campaign` s'y réfère pour refuser une variable inconnue à l'écriture ; le
 * service d'envoi s'y réfère pour la remplir. Deux listes divergeraient, et une variable acceptée à
 * la saisie finirait par exclure tout le monde à l'envoi.
 *
 * Les variables qui manquent — solde de carte, date d'échéance d'abonnement — vivent dans d'autres
 * modules. Les lire d'ici demanderait un port, pas un appel direct (D2). Elles sont volontairement
 * absentes plutôt que approximées : une date d'échéance fausse dans un message est pire que pas de
 * message.
 */
final class MessageVariables
{
    /** @var list<string> */
    public const CONNUES = ['prenom', 'nom', 'civilite'];

    /**
     * Remplit les variables d'un texte, ou rend `null` si l'une d'elles manque pour ce client.
     *
     * `null` et pas une chaîne à trous : l'appelant doit être obligé de traiter le cas, et un texte
     * partiellement rempli est exactement ce qu'on veut empêcher de partir.
     */
    public static function remplir(string $texte, Client $client): ?string
    {
        $valeurs = [
            'prenom' => trim((string) $client->getPrenom()),
            'nom' => trim((string) $client->getNom()),
            'civilite' => trim((string) $client->getCivilite()),
        ];

        $resultat = preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            static function (array $trouve) use ($valeurs): string {
                $valeur = $valeurs[strtolower($trouve[1])] ?? '';

                // Une valeur vide est traitée comme absente : c'est le même problème vu de la base.
                // Un client dont le prénom est une chaîne vide n'est pas mieux loti que celui dont
                // le champ est nul.
                return $valeur !== '' ? $valeur : "\0";
            },
            $texte,
        );

        if (!\is_string($resultat) || str_contains($resultat, "\0")) {
            return null;
        }

        return $resultat;
    }
}
