# Spec — Noyau de plateforme : bus d'événements & registre de modules (`C5`)

- **Lot / module :** C5 — `App\Platform`
- **Contrat source :** `COORDINATION/CONTRACT/noyau-commun.md`, `manifeste-module.md`, `catalogue-evenements.md`
- **Décisions :** D2 (contract-first), D3 (cloisonnement), D5 (anglais), D6 (tenant du sujet), D7 (bus synchrone)
- **Statut :** brouillon

## 1. Objectif
Donner au cœur les deux briques dont **tous** les modules à venir dépendent : un **bus d'événements**
(les modules réagissent à des faits, ils ne s'appellent jamais entre eux) et un **registre de modules**
(chaque module déclare son manifeste ; le cœur sait ce qui existe, ce qui est actif, qui écoute quoi).

## 2. Périmètre

**Inclus**
- Enveloppe `DomainEvent` normalisée : `name`, `occurredAt`, `tenant`, `actor`, `subject`, `payload`.
- `EventBus` **synchrone in-process** au-dessus de l'`EventDispatcher` Symfony (D7).
- Validation du nommage `domain.fact_past_tense`, en anglais (D5).
- Interface `ModuleManifest` + `ModuleRegistry` (découverte par tag DI, résolution des dépendances).
- Test d'activation à deux niveaux `hasModule()` / `hasFeature()`, **câblé sur l'existant**
  `App\Fonctionnalite\Service\Fonctionnalites` (déjà porteur de `estActive()`).
- Commande `platform:modules` pour inspecter le registre.
- Reprise des **5 émetteurs d'événements existants** vers l'enveloppe (`Recouvrement`, `Crm`, `Acces`).

**Exclu (pour l'instant)**
- **Asynchrone** : pas de `symfony/messenger`, pas de worker, pas de file (D7).
- **Event store** : les événements ne sont pas persistés, pas de rejeu, pas d'historique.
  *(L'audit existant couvre déjà la traçabilité métier.)*
- Webhooks sortants, diffusion inter-applications, versionnage d'événements.
- Écran d'administration des modules (le registre est lisible en CLI en v0).

## 3. Acteurs & droits
Le noyau n'expose **aucune API HTTP** en v0 : c'est de l'infrastructure consommée par les modules.

| Acteur | Peut | Permission |
|---|---|---|
| Module (code serveur) | publier un événement, déclarer un manifeste | — (interne) |
| Exploitant (CLI) | lister les modules, leurs événements et leurs dépendances | accès shell |

## 4. Comportements & règles

- **RG-PLAT-01 — Enveloppe obligatoire.** Tout événement publié sur le bus est un `DomainEvent`
  complet. Un champ obligatoire manquant est une erreur de programmation (exception), pas un
  avertissement.
- **RG-PLAT-02 — Nommage.** `name` respecte `^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$`
  (`domain.fact_past_tense`), en anglais (D5). Un nom non conforme est refusé à la construction.
- **RG-PLAT-03 — Tenant obligatoire, dérivé du sujet.** `tenant.establishmentId` est **requis** et
  provient de l'**entité sujet** (l'établissement de la facture, de la réservation…), jamais de
  `ContexteEtablissement` (D6). Un événement sans établissement est **refusé** — échec fermé (D3).
- **RG-PLAT-04 — Charge utile minimale.** Le `payload` porte des identifiants et le strict minimum :
  jamais de secret, jamais de donnée personnelle non nécessaire (`noyau-commun.md`, invariant).
- **RG-PLAT-05 — Propagation des erreurs.** Un abonné qui lève une exception **interrompt** l'émetteur
  (le bus est synchrone, dans sa transaction) : la cohérence prime. Un traitement *best-effort*
  (e-mail, notification) doit être explicitement enveloppé par l'abonné — il ne casse jamais l'action
  métier (invariant 6 du noyau).
- **RG-PLAT-06 — Déclaration au manifeste.** Un module ne peut publier qu'un événement déclaré dans
  ses `eventsEmitted()`, et cet événement doit exister au `catalogue-evenements.md`. Vérifié par test.
- **RG-PLAT-07 — Dépendances résolues.** Le registre refuse de démarrer si un module déclare une
  dépendance vers un `id` inconnu, ou si un cycle existe.
- **RG-PLAT-08 — Deux niveaux d'activation.** `hasModule(capability)` et `hasFeature(code)` répondent
  pour un **établissement donné**. Module actif n'implique pas toutes ses features actives.
- **RG-PLAT-09 — Activation non destructive.** Désactiver une capacité ou une feature ne supprime
  aucune donnée : seule l'exposition change (`manifeste-module.md`).

## 5. Objets de données
**Aucune entité persistée, aucune migration.** Le bus est sans état ; le registre est construit au
démarrage depuis les services taggés. L'activation par établissement s'appuie sur l'entité existante
`App\Fonctionnalite\Entity\FonctionnaliteEtablissement`.

| Objet (valeur, non persisté) | Champ | Type | Contraintes |
|---|---|---|---|
| `DomainEvent` | `name` | `string` | RG-PLAT-02 |
| | `occurredAt` | `DateTimeImmutable` | UTC |
| | `tenant` | `EventTenant` | `establishmentId: Uuid`, requis (RG-PLAT-03) |
| | `actor` | `?EventActor` | `userId: Uuid`, `null` = système |
| | `subject` | `EventSubject` | `type: string` (nom court anglais), `id: string` |
| | `payload` | `array<string,scalar\|array>` | RG-PLAT-04 |

## 6. Critères d'acceptation
- **CA-1** — *Étant donné* un module qui publie `supplier_invoice.recorded` avec une enveloppe
  complète, *quand* un autre module y est abonné, *alors* son abonné reçoit l'événement dans la même
  requête, sans que les deux modules se connaissent.
- **CA-2** — *Étant donné* un événement construit sans `establishmentId`, *quand* on tente de le
  publier, *alors* une exception est levée et **rien** n'est diffusé.
- **CA-3** — *Étant donné* un nom d'événement non conforme (`FactureEnregistree`, `finance.Recorded`),
  *quand* on construit l'enveloppe, *alors* elle est refusée.
- **CA-4** — *Étant donné* un module déclarant `eventsEmitted: ['invoice.issued']`, *quand* la suite de
  tests s'exécute, *alors* elle vérifie que `invoice.issued` figure au catalogue d'événements.
- **CA-5** — *Étant donné* un module dont la capacité est inactive sur l'établissement, *quand* on
  interroge `hasModule()`, *alors* la réponse est `false` sans erreur.
- **CA-6** — *Étant donné* un manifeste déclarant une dépendance inconnue, *quand* le registre se
  construit, *alors* le démarrage échoue avec un message nommant le module et la dépendance.
- **CA-7** — *Étant donné* les 5 émetteurs existants migrés, *quand* la suite de tests complète tourne,
  *alors* elle est verte sans modification des comportements métier.

## 7. Cas limites
- **Événement sans établissement** (tâche planifiée globale, action super-admin) : refusé en v0
  (RG-PLAT-03). Si un besoin réel apparaît, il fera l'objet d'une décision explicite — pas d'un `null`
  toléré en douce.
- **Abonné lent** : le bus étant synchrone, un abonné lent ralentit l'émetteur. Assumé en v0 (D7) ;
  c'est le signal qui justifiera l'asynchrone le jour venu.
- **Deux modules abonnés au même événement** : ordre non garanti ; aucun abonné ne doit dépendre d'un
  autre abonné (sinon c'est un couplage déguisé, interdit par D2).
- **Réentrance** : un abonné qui publie un événement déclenchant le premier ⇒ boucle. Garde-fou :
  profondeur de publication bornée, erreur explicite au dépassement.

## 8. Dépendances
- **Dépend de :** `App\Fonctionnalite\Service\Fonctionnalites` (activation), `App\Organisation\Entity\Etablissement` (tenant).
- **Débloque :** C6 / FIN-0..FIN-4 (claude-B), Smart Flow, Revenue Recovery — tout module soumis à D2.
