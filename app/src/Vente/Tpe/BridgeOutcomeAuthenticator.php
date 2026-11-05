<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Enum\StatutTPE;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * L'issue rendue par le pont local (`bridge/caisse-ap`), vérifiée (#340).
 *
 * Le pont signe en Ed25519 le TEXTE JSON de l'issue ; Fluvia vérifie ces octets exacts avec la clé
 * publique du point de vente (`PointDeVente.tpe.clePont`, 32 octets en base64), puis seulement les
 * lit. Aucune canonicalisation : ce qui est vérifié est ce qui est lu.
 *
 * L'issue doit nommer la tentative et son montant demandé : une issue signée pour une autre tentative,
 * ou pour un autre montant, n'est pas celle-ci.
 */
final class BridgeOutcomeAuthenticator
{
    /** La clé publique du pont de ce point de vente, en octets ; nulle si elle n'est pas (bien) réglée. */
    public static function publicKeyOf(PointDeVente $pointDeVente): ?string
    {
        $reglage = $pointDeVente->getTpe();
        $cle = \is_array($reglage) ? ($reglage['clePont'] ?? null) : null;

        return \is_string($cle) ? self::decodeKey($cle) : null;
    }

    /** La clé décodée, si c'est bien une clé publique Ed25519 en base64 (32 octets). */
    public static function decodeKey(string $base64): ?string
    {
        $octets = base64_decode(trim($base64), true);

        return \is_string($octets) && \strlen($octets) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $octets : null;
    }

    /**
     * @param string $attemptUuid l'identifiant RFC 4122 de la tentative
     * @param string $amount      le montant demandé à la tentative (« 45.00 »)
     *
     * @throws UnprocessableEntityHttpException signature fausse, issue illisible, ou issue d'une autre tentative
     */
    public function verify(PointDeVente $pointDeVente, string $result, string $signature, string $attemptUuid, string $amount): ResultatTpe
    {
        $cle = self::publicKeyOf($pointDeVente)
            ?? throw new UnprocessableEntityHttpException('Le pont n\'est pas appairé à ce point de vente : aucune issue ne peut être reçue.');
        $sig = base64_decode($signature, true);
        if (!\is_string($sig) || \strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $result, $cle)) {
            throw new UnprocessableEntityHttpException('Issue du terminal non signée par le pont de ce point de vente : elle est ignorée.');
        }

        $issue = json_decode($result, true);
        if (!\is_array($issue) || ($issue['v'] ?? null) !== 1 || ($issue['bridge'] ?? null) !== 'fluvia-caisse-ap') {
            throw new UnprocessableEntityHttpException('Issue du pont illisible.');
        }
        if (!\is_string($issue['attempt'] ?? null) || strtolower($issue['attempt']) !== strtolower($attemptUuid)
            || ($issue['requestedAmount'] ?? null) !== $amount) {
            throw new UnprocessableEntityHttpException('Cette issue est celle d\'une autre demande au terminal : elle est ignorée.');
        }
        $statut = \is_string($issue['status'] ?? null) ? StatutTPE::tryFrom($issue['status']) : null;
        if ($statut === null) {
            throw new UnprocessableEntityHttpException('Issue du pont illisible (statut).');
        }
        $texte = static fn (string $cle): ?string => \is_string($issue[$cle] ?? null) && $issue[$cle] !== '' ? mb_substr($issue[$cle], 0, 64) : null;

        return new ResultatTpe(
            $statut,
            reference: $texte('authorization') !== null ? 'CAP-' . $texte('authorization') : null,
            montantDebite: $statut === StatutTPE::Accepte ? $texte('debitedAmount') : null,
            numeroAutorisation: $texte('authorization'),
            identifiantTerminal: $texte('terminal'),
            reseau: $texte('network'),
        );
    }
}
