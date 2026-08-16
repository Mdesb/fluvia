<?php

declare(strict_types=1);

namespace App\Sepa\Service;

/**
 * Coffre IBAN réversible (module partagé App\Sepa). Contrairement à `TokenisationIbanInterface`
 * (jeton HMAC non réversible, utilisé pour l'affichage/recherche), ce port permet de **retrouver
 * l'IBAN en clair** — nécessaire pour que `Pain008Generator` porte le véritable IBAN dans le fichier
 * de remise pain.008 transmis à la banque.
 *
 * ⚠ L'IBAN déchiffré ne doit **jamais** être exposé en API ni journalisé en clair : le déchiffrement
 * n'a lieu que côté serveur, au moment strict de la génération du XML de remise.
 */
interface ChiffreurIbanInterface
{
    /** Chiffre un IBAN en clair (normalisé : majuscules, sans espaces) — valeur opaque à persister. */
    public function chiffrer(string $ibanClair): string;

    /** Déchiffre une valeur produite par `chiffrer()` — restitue l'IBAN en clair normalisé. */
    public function dechiffrer(string $ibanChiffre): string;
}
