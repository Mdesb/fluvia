<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

/**
 * Le terminal EXTERNE, déclaratif (connecteur « externe », spec TPE lot 1 §5) : celui du commerce ou
 * de la régie, qui n'est relié à rien. `demarrer()` n'envoie rien — la tentative passe `sent` et
 * attend : l'écran dit « Encaissez XX,XX € sur le terminal, puis indiquez le résultat », et le
 * caissier déclare Accepté (référence du ticket CB obligatoire), Refusé ou Annulé
 * (`POST /ventes/{id}/declarer-reglement`). Sa déclaration est l'issue du terminal : elle passe par
 * le même chemin qu'un connecteur relié ({@see TerminalOutcomeSink}), signée par le caissier.
 *
 * Aucune simulation, aucun en-tête, aucune variable d'environnement : c'est le connecteur d'un point
 * de vente qui n'en déclare aucun.
 */
final class ExternalTerminal implements TerminalPaiementInterface
{
    public const CONNECTOR = 'externe';

    public function connector(): string
    {
        return self::CONNECTOR;
    }

    /**
     * Le caissier encaisse puis déclare : une demi-heure sans déclaration, la tentative passe « sans
     * issue » et se déclare de même (« Qu'affiche le terminal ? »). Le délai ne libère rien.
     */
    public function staleAfterSeconds(): int
    {
        return 1800;
    }

    public function demarrer(TerminalAttempt $tentative): void
    {
        // Rien à envoyer : le montant s'affiche à l'écran, le caissier le saisit sur le terminal.
    }

    public function annuler(TerminalAttempt $tentative): void
    {
        // Rien à prévenir : le caissier abandonne la transaction sur le terminal lui-même.
    }
}
