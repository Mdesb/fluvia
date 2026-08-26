<?php

declare(strict_types=1);

namespace App\Vente;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module Vente / Caisse.
 *
 * **Nommee `SalesModule` et non `VenteModule`** : D5 impose l anglais aux identifiants des fichiers
 * ajoutes, et le garde-fou n°11 a refuse le commit — a raison. Le module conserve son identifiant
 * metier `vente` (une chaine, pas une declaration), qui est la cle sous laquelle le reste du depot le
 * connait ; seul le nom de classe suit la convention.
 *
 * **Il n'en existait pas**, alors que c'est le module qui encaisse — relevé en préparant PAY-3. Huit
 * modules en déclaraient un ; celui-ci, non. Or RG-PLAT-06 vérifie la conformité des événements au
 * catalogue **à partir des manifestes** : le contrôle était donc aveugle sur le plus gros émetteur
 * potentiel du dépôt. Ce n'est pas qu'il aurait laissé passer une faute — **il n'avait rien à
 * regarder, et rendait vert.**
 *
 * **L'inventaire a été mesuré avant d'être écrit**, à ma demande, plutôt que déduit : `Vente` ne
 * construit aucun `DomainEvent` en propre — elle relaie ce que `CardRechargeInterface::recharge()`
 * lui rend et le publie après commit (D7-bis) — et ne consomme rien : aucun abonné, aucun écouteur
 * d'événement dans tout `app/src/Vente`. Les deux listes sont donc vides **parce qu'elles le sont**,
 * et non parce que personne n'a cherché. `sale.card_payment_rejected` (PAY-3) viendra s'y inscrire
 * dans le même commit que son émetteur, comme la ligne de catalogue.
 *
 * `capability()` → `null` : encaisser n'est pas une capacité qu'on vend en option. Un établissement
 * qui n'aurait pas le droit de vendre n'aurait pas de raison d'exister dans le produit.
 *
 * `dependencies()` → `[]` : `App\Offre` et `App\Caisse` n'implémentent pas encore `ModuleManifest`,
 * et les déclarer ferait échouer `ModuleRegistry::assertDependenciesAreResolved()`. C'est la même
 * réserve transitoire que `SmartFlowModule`, et elle se lève quand ces deux-là auront le leur.
 */
final class SalesModule implements ModuleManifest
{
    public function id(): string
    {
        return 'vente';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [
            'vente.lire',
            'vente.creer',
            'vente.encaisser',
            'vente.annuler',
            'vente.rembourser',
            // Distincts d'`encaisser`, et chacun pour une raison : `forcer_prix` sort de la grille,
            // `corriger_reglement` déplace de l'argent entre moyens — donc peut masquer un manquant —,
            // `vente_directe` permet de vendre sans qu'aucun tiroir ne réponde de la transaction, et
            // `cloture_journaliere` scelle un arrêté de totaux qui ne se retire pas.
            'vente.forcer_prix',
            'vente.corriger_reglement',
            'vente.vente_directe',
            'vente.cloture_journaliere',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        return [
            // PAY-3 — un refus de carte. Émis par `CardRejectionRecorder`, appelé depuis
            // `PaiementHandler` au seul endroit où le terminal répond non.
            //
            // Le consommateur est **le module** `App\Sepa`, et non une de ses classes : un manifeste
            // qui nomme le service interne d'un autre module réintroduit par la bande le couplage que
            // D2 interdit — et il vieillit mal, puisque ce nom peut changer sans que l'événement
            // bouge. Le catalogue dit qui consomme ; ici on déclare seulement ce qu'on émet.
            'sale.card_payment_rejected',
        ];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [
            'vente_directe',
            'cloture_journaliere',
        ];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
