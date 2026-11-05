<?php

declare(strict_types=1);

namespace App\Acces\Hardware;

/**
 * La forme sous laquelle un lecteur rend l'identifiant d'un badge RFID (#337).
 *
 * ⚠ LE MÊME BADGE N'A PAS LE MÊME NUMÉRO SUR DEUX LECTEURS. Un badge MIFARE porte un numéro de
 * série (UID) de 4 ou 7 octets ; selon le modèle et son réglage, le lecteur le rend en hexadécimal
 * dans l'ordre des octets, en hexadécimal inversé, ou en décimal. Sans normalisation, un badge
 * appairé au comptoir avec un lecteur USB réglé en décimal serait « inconnu » au tourniquet réglé
 * en hexadécimal — et un parc de badges existants serait à réappairer en entier.
 *
 * La forme canonique stockée est l'hexadécimal majuscule, octet de poids fort en tête
 * (`HexMsbFirst`). `Raw` laisse la chaîne telle quelle : c'est le cas des QR et des wallets, dont
 * l'identifiant est un code signé, pas un numéro de série.
 */
enum CredentialFormat: string
{
    /** Chaîne transmise telle quelle (QR, wallet, ou lecteur déjà réglé sur la forme canonique). */
    case Raw = 'raw';

    /** Hexadécimal, octet de poids fort en tête (`04A1B2C3`). Forme canonique. */
    case HexMsbFirst = 'hex_msb_first';

    /** Hexadécimal, octets inversés (`C3B2A104` pour `04A1B2C3`). */
    case HexLsbFirst = 'hex_lsb_first';

    /** Entier décimal non signé de l'UID lu octet de poids fort en tête (`77705923` pour `04A1B2C3`). */
    case Decimal = 'decimal';
}
