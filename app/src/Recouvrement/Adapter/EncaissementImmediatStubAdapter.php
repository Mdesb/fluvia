<?php

declare(strict_types=1);

namespace App\Recouvrement\Adapter;

use App\Recouvrement\Dto\InitiationPaiementResultat;
use App\Recouvrement\Dto\ResultatConfirmationPaiement;
use App\Recouvrement\Port\EncaissementImmediatInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur d'encaissement CB par défaut — **il échoue, et c'est le seul comportement honnête.**
 *
 * ── POURQUOI IL RÉPONDAIT « OUI », ET CE QUE ÇA COÛTAIT ─────────────────────────────────────────
 *
 * Jusqu'au 08/09, `confirmerPaiement()` renvoyait `confirme: true` sans condition. « Réglé » ne
 * débitait donc personne et **ne pouvait jamais échouer** : l'écran annonçait un encaissement, la
 * dette disparaissait, l'accès se rouvrait — et aucun euro n'avait bougé. Aucun prestataire de
 * paiement n'a jamais été raccordé (Risque n°4, PSP non nommé par les sources).
 *
 * ⚠ UN BOUCHON QUI RÉUSSIT PRODUIT DES ÉCRANS QUI MENTENT ; UN BOUCHON QUI ÉCHOUE PRODUIT DES ÉCRANS
 * HONNÊTES SANS QU'ON AIT À Y PENSER. C'est toute la différence entre les deux, et elle décide du
 * reste : avec celui-ci, le refus remonte jusqu'à l'agent, qui déclare alors le canal réel par lequel
 * l'argent est rentré — virement, caisse, autre — ce que le dossier enregistre vraiment.
 *
 * Le port reste en place pour le jour où un PSP sera choisi : c'est le CÂBLAGE qui manque, pas la
 * mécanique. `initierPaiement()` continue donc de rendre une référence déterministe.
 */
final class EncaissementImmediatStubAdapter implements EncaissementImmediatInterface
{
    public function initierPaiement(Uuid $incidentId, int $montantCentimes): InitiationPaiementResultat
    {
        $reference = 'CB1CLIC-' . substr(hash('sha256', (string) $incidentId . $montantCentimes), 0, 16);

        return new InitiationPaiementResultat(
            referenceTransaction: $reference,
            urlPaiement: 'https://paiement-cb.example.test/regler/' . $reference,
        );
    }

    public function confirmerPaiement(string $referenceTransaction): ResultatConfirmationPaiement
    {
        // Voir l'avertissement de classe : aucun prestataire n'est raccordé, donc rien n'est encaissé.
        // Le message que lira l'agent est composé par `ResolutionImpayeHandler`, qui sait quoi lui
        // proposer à la place ; ici on ne fait que constater l'absence.
        return new ResultatConfirmationPaiement(confirme: false, dateConfirmation: new \DateTimeImmutable());
    }
}
