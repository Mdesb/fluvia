<?php

declare(strict_types=1);

namespace App\Acces\Hardware;

/**
 * Ramène l'identifiant lu par n'importe quel lecteur à la forme stockée sur le `Support` (#337).
 *
 * C'est ce qui rend Fluvia compatible avec les badges RFID déjà en circulation chez un client : on
 * n'écrit rien sur la carte, on reconnaît son numéro de série quelle que soit la façon dont le
 * lecteur le rend. Le comptoir (appairage) et la porte (passage) passent par la même fonction —
 * deux normalisations distinctes finiraient par diverger, et un badge appairé ne passerait plus.
 *
 * Refuse explicitement une lecture incohérente avec le format déclaré : deviner un format serait
 * pire qu'échouer, parce qu'une devinette fausse rend le badge d'un autre.
 */
final class CredentialNormalizer
{
    /** UID MIFARE : 4 octets (simple), 7 octets (double), 10 octets (triple). */
    private const UID_LENGTHS = [8, 14, 20];

    public function normalize(string $raw, CredentialFormat $format): string
    {
        $value = trim($raw);
        if ($value === '') {
            throw new \InvalidArgumentException('Identifiant vide.');
        }

        return match ($format) {
            CredentialFormat::Raw => $value,
            CredentialFormat::HexMsbFirst => $this->hex($value),
            CredentialFormat::HexLsbFirst => implode('', array_reverse(str_split($this->hex($value), 2))),
            CredentialFormat::Decimal => $this->fromDecimal($value),
        };
    }

    private function hex(string $value): string
    {
        // Séparateurs courants des lecteurs (`04:A1:B2:C3`, `04 A1 B2 C3`, `04-A1-B2-C3`).
        $hex = strtoupper(str_replace([':', ' ', '-'], '', $value));
        if (!ctype_xdigit($hex) || \strlen($hex) % 2 !== 0) {
            throw new \InvalidArgumentException(sprintf('« %s » n\'est pas un identifiant hexadécimal par octets.', $value));
        }

        return $hex;
    }

    private function fromDecimal(string $value): string
    {
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('« %s » n\'est pas un identifiant décimal.', $value));
        }
        // Un UID de 7 octets tient dans un entier 64 bits signé (2^56) ; au-delà, on refuse plutôt
        // que de rendre un nombre tronqué, qui serait l'identifiant d'un autre badge.
        if (\strlen($value) > 17 || (int) $value < 0) {
            throw new \InvalidArgumentException(sprintf('« %s » dépasse la taille d\'un UID de 7 octets.', $value));
        }

        $hex = strtoupper(dechex((int) $value));
        // Le décimal perd les octets nuls de tête : on complète à la longueur d'UID la plus proche.
        foreach (self::UID_LENGTHS as $length) {
            if (\strlen($hex) <= $length) {
                return str_pad($hex, $length, '0', STR_PAD_LEFT);
            }
        }

        throw new \InvalidArgumentException(sprintf('« %s » dépasse la taille d\'un UID MIFARE.', $value));
    }
}
