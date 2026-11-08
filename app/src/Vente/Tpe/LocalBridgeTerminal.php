<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Le terminal PILOTÉ par le pont local du poste de caisse (connecteur « caisse_ap_pont », #340).
 *
 * Le serveur ne peut pas joindre le terminal : il est sur le réseau du site, derrière sa box. C'est
 * le navigateur de la caisse qui, la tentative ouverte, demande le débit au pont
 * (`bridge/caisse-ap`, `POST http://127.0.0.1:8765/v1/payments`) ; le pont parle Caisse-AP au
 * terminal, puis rend une issue SIGNÉE de sa clé privée. Le navigateur la relaie à
 * `POST /ventes/{id}/issue-pont`, qui vérifie la signature avec la clé publique du point de vente
 * (`PointDeVente.tpe.clePont`) avant de la déposer par le chemin unique de l'issue
 * ({@see TerminalOutcomeSink}). Le navigateur transporte l'issue ; il ne peut pas la changer.
 *
 * `demarrer()` n'envoie donc rien — mais refuse (4xx : rien n'est parti) un point de vente dont la clé
 * du pont n'est pas réglée : aucune issue ne pourrait jamais être reçue.
 */
final class LocalBridgeTerminal implements TerminalPaiementInterface
{
    public const CONNECTOR = 'caisse_ap_pont';

    public function connector(): string
    {
        return self::CONNECTOR;
    }

    /**
     * Le pont attend le terminal 180 s au plus (code PIN compris) puis rend « timeout » ; au-delà de
     * 5 minutes sans issue relayée (navigateur fermé, poste éteint), la tentative est à déclarer.
     */
    public function staleAfterSeconds(): int
    {
        return 300;
    }

    public function demarrer(TerminalAttempt $tentative): void
    {
        if (BridgeOutcomeAuthenticator::publicKeyOf($tentative->pointOfSale) === null) {
            throw new UnprocessableEntityHttpException(
                'Le pont du terminal de paiement n\'est pas appairé à ce point de vente (clé du pont absente) : '
                . 'la carte n\'a pas été débitée. Encaissez par un autre moyen.'
            );
        }
    }

    public function annuler(TerminalAttempt $tentative): void
    {
        // Rien à prévenir depuis le serveur : on annule sur le terminal (touche rouge), qui rend
        // « abandon » au pont ; une acceptation relayée ensuite l'emporte encore (CA-3).
    }
}
