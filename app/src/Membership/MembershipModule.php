<?php

declare(strict_types=1);

namespace App\Membership;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Membership` — **l'abonnement d'un adhérent**, transverse aux métiers.
 *
 * ⚠ NE PAS CONFONDRE AVEC `App\Subscription`, ET C'EST TOUTE LA RAISON DU NOM ANGLAIS.
 *
 * `App\Subscription` est la facturation SaaS de l'ÉDITEUR : les plans Fluvia vendus aux
 * établissements (`Plan`, `SubscriptionInvoice`, `EditorSubscription`). Ici, c'est l'inverse du
 * miroir : ce qu'un ADHÉRENT achète à l'établissement. Les deux se disent « abonnement » en
 * français, et c'est précisément le genre d'homonymie qui a déjà coûté au dépôt — l'écran
 * `ReversementsOta.jsx` ne sert pas les OTA de la boutique mais celles du musée, et il a gardé une
 * liste vide sans que personne comprenne pourquoi. Deux mots distincts ferment cette porte
 * d'avance. L'interface, elle, continue d'afficher « Abonnements » (D5 sépare le technique de la
 * présentation).
 *
 * ⚠ `capability()` REND `null`, ET C'EST UNE CONTRADICTION ASSUMÉE AVEC LA SPEC.
 *
 * `features/abonnement/specs/spec-abonnement-transverse.md` §2 demande une capacité au catalogue,
 * en justifiant : « sinon le module est présent mais inaccessible ». Cette justification est
 * inexacte, et {@see ModuleManifest} le dit : `null` désigne un **service transverse**, cas prévu et
 * exempté du garde-fou n°41. Le cas « présent mais inaccessible » est celui d'un code **inventé,
 * absent du catalogue** — là, `ModuleAccess::hasModule()` répond `false` pour toujours, sans erreur
 * ni journal.
 *
 * Sur le fond, l'argument de `GroupModule` vaut mot pour mot ici : piscine, padel, patinoire, musée
 * et sport vendent tous des abonnements. En faire une capacité à cocher créerait une porte fermée là
 * où il n'en faut pas. Le titre de la spec dit d'ailleurs « abonnement **transverse** ».
 *
 * Et une capacité coûterait quelque chose aujourd'hui : sans écran (lot 3), il faudrait la déclarer
 * `peutServir = false` comme `lodging`/`stay`/`dining` — c'est-à-dire annoncer dans la vitrine une
 * option qu'on ne peut pas vendre. `null` ne touche RIEN de visible par un client, et poser la
 * capacité plus tard est une ligne. L'inverse serait un retrait d'option déjà annoncée.
 *
 * `dependencies()` reste vide alors que l'entité référence `App\Crm`, `App\Offre`, `App\Sepa` et
 * `App\Organisation` : on ne déclare pas de dépendance vers un module sans manifeste (RG-PLAT-07
 * ferait échouer le démarrage). Même choix que `GroupModule` et `MuseeModule` ; le typage exprime
 * déjà le lien.
 */
final class MembershipModule implements ModuleManifest
{
    public function id(): string
    {
        return 'membership';
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
            'membership.read',
            'membership.manage',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        // RG-PLAT-06 : catalogue d'abord, declaration ensuite. Le socle n'emet rien.
        return [];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [];
    }

    /** @return list<string> */
    public function routes(): array
    {
        // Aucun ecran au lot 0 : la navigation arrive au lot 3, avec l'onglet Exploitation.
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
