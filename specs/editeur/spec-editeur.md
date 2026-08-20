# Spec — Administration de l'éditeur & tunnel de souscription (`ED`)

- **Lot / module :** `ED` — `App\Subscription` (nouveau) + activation de modules existants
- **Nommage :** le module s'appelle `App\Subscription`, en anglais (D5, qui s'applique à tout code
  neuf). C'est l'abonnement qui porte le catalogue d'offres, le cycle de vie et le provisioning ;
  l'administration de l'éditeur n'est que l'usage qu'on en fait.
- **Contrat source :** `COORDINATION/CONTRACT/{noyau-commun,manifeste-module,catalogue-evenements}.md`
- **Décisions :** D2 (contract-first), D3 (cloisonnement), D6 (tenant du sujet), D10 (SEPA d'abord),
  D11 (démo jetable + reprise), D12 (l'éditeur est un établissement de la plateforme)
- **Statut :** brouillon

## 1. Objectif

Permettre à l'éditeur de **vendre la plateforme depuis la plateforme** : suivre ses prospects, envoyer
des devis, facturer des abonnements mensuels composés d'une formule et de modules optionnels, et
provisionner automatiquement le client qui vient de payer.

L'éditeur est un `Etablissement` comme un autre (D12), avec les modules CRM, Devis, Facturation, SEPA
et Recouvrement activés. Il n'y a **pas** de seconde application.

## 2. Périmètre

**Inclus**
- Catalogue d'offres : formules (`Plan`) et options (`PlanOption`, adossées aux modules).
- Cycle de vie de l'abonnement : souscription, ajout/retrait d'option en cours de période, prorata,
  résiliation.
- Tunnel de souscription : panier de modules → mandat SEPA → confirmation → provisioning.
- Provisioning idempotent : création de l'établissement client, de son administrateur, activation des
  modules souscrits.
- Reprise du paramétrage de démo (D11).
- Accès d'assistance borné et audité (§4, RG-ED-07).

**Exclu (pour l'instant)**
- **Encaissement carte** (D10) — SEPA seul au lancement.
- Site vitrine et référencement — repoussé par le client.
- Comptabilité analytique de l'éditeur, reconnaissance de revenu, TVA multi-pays.
- Facturation à l'usage (au billet vendu, au visiteur) : on facture des modules, pas des volumes.

## 3. Acteurs & droits

| Acteur | Peut | Périmètre |
|---|---|---|
| Prospect anonyme | explorer la démo, composer son panier, signer un mandat SEPA | aucun établissement |
| Commercial (éditeur) | CRM, devis, offres, abonnements | **l'établissement éditeur uniquement** |
| Client (admin) | paramétrer son établissement, consulter ses factures | **son établissement uniquement** |
| Système (provisioning) | créer un établissement, activer des modules | opération de service, jamais une session humaine |
| Assistance (éditeur) | lire l'établissement d'un client | **sur accès explicitement ouvert**, borné, audité |

## 4. Comportements & règles

- **RG-ED-01 — L'éditeur est un tenant.** Ses prospects, devis, factures et abonnements sont **ses
  propres données**, dans **son** établissement. Aucun accès inter-établissement n'est nécessaire pour
  facturer : D3 s'applique tel quel, sans exception.
- **RG-ED-02 — Un client payant = un établissement.** La fiche `Client` du CRM éditeur porte la
  référence de l'établissement provisionné. C'est ce lien, et lui seul, qui relie le commerce à
  l'exploitation.
- **RG-ED-03 — Une option est un module.** Une option facturable correspond à une `capability` du
  registre de modules. Vendre une option et l'activer sont **la même donnée** : pas de second
  catalogue commercial qui divergerait du catalogue technique.
- **RG-ED-04 — Le provisioning réagit à un événement, il n'est pas appelé.** Le tunnel émet
  `subscription.activated` ; le provisioning y est abonné. Le tunnel ne connaît pas le provisioning
  (D2), ce qui permet de rejouer, de différer, ou d'ajouter un effet (e-mail de bienvenue) sans y
  toucher.
- **RG-ED-05 — Le provisioning est idempotent.** Rejouer le même `subscription.activated` ne crée ni
  second établissement, ni second administrateur. Un webhook bancaire se répète ; un provisioning qui
  ne le supporte pas facture deux fois.
- **RG-ED-06 — Échec de paiement ⇒ suspension, jamais suppression.** Un impayé suspend l'exposition des
  modules (`hasModule` répond `false`), il n'efface aucune donnée (RG-PLAT-09). Le client qui régularise
  retrouve tout.
- **RG-ED-07 — L'assistance est un accès ouvert, pas un privilège.** Lire l'établissement d'un client
  exige un **accès d'assistance** explicite : borné dans le temps, tracé à l'audit, et attribué à une
  personne nommée. Il n'existe **aucun rôle qui voit tous les établissements par défaut**.
- **RG-ED-08 — Le paramétrage de démo est un export rejouable** (D11), pas une copie de base. Il porte
  des données de configuration (offres, tarifs, horaires), jamais des données personnelles.

## 5. Objets de données

| Objet | Champs | Notes |
|---|---|---|
| `Plan` | `code`, `label`, `monthlyPrice`, `includedCapabilities[]` | la formule de base |
| `PlanOption` | `capability`, `monthlyPrice` | une option = un module (RG-ED-03) |
| `Subscription` | `client`, `plan`, `options[]`, `status`, `startedAt`, `endedAt` | porté par l'établissement éditeur |
| `SubscriptionItem` | `subscription`, `capability`, `unitPrice`, `activeFrom`, `activeTo` | permet le prorata |
| `ProvisioningRequest` | `subscription`, `establishment?`, `status`, `attempts` | trace l'idempotence (RG-ED-05) |
| `SupportAccess` | `grantee`, `establishment`, `grantedAt`, `expiresAt`, `reason` | RG-ED-07 |

Facture, devis, client, mandat SEPA et relance **réutilisent les entités existantes** (`Facturation`,
`Crm`, `Sepa`, `Recouvrement`) : ce lot n'en recrée aucune.

## 6. Critères d'acceptation

- **CA-1** — *Étant donné* un prospect ayant composé un panier et signé un mandat SEPA, *quand* le
  paiement est confirmé, *alors* son établissement existe, son administrateur peut se connecter, et
  seuls les modules souscrits répondent `true` à `hasModule()`.
- **CA-2** — *Étant donné* le même `subscription.activated` rejoué trois fois, *alors* il n'existe
  qu'un établissement et qu'un administrateur (RG-ED-05).
- **CA-3** — *Étant donné* un commercial de l'éditeur, *quand* il interroge l'API, *alors* il ne voit
  **aucune** donnée d'un établissement client — seulement les siennes (RG-ED-01).
- **CA-4** — *Étant donné* une option ajoutée en cours de mois, *alors* la facture suivante porte un
  prorata calculé depuis `activeFrom`, et la capacité est active immédiatement.
- **CA-5** — *Étant donné* un prélèvement rejeté, *alors* les modules cessent d'être exposés et
  **aucune donnée n'est supprimée** ; la régularisation les réexpose (RG-ED-06).
- **CA-6** — *Étant donné* un accès d'assistance expiré, *quand* l'agent tente une lecture, *alors*
  elle est refusée en échec fermé, et la tentative est tracée (RG-ED-07).
- **CA-7** — *Étant donné* un paramétrage réalisé en démo, *quand* le client souscrit, *alors* offres,
  tarifs et horaires sont rejoués sur son établissement réel (RG-ED-08).

## 7. Cas limites

- **Le prospect abandonne après le mandat mais avant la confirmation** : aucun établissement n'est
  créé ; le mandat reste orphelin et doit être purgé.
- **Retrait d'une option en cours de période** : la capacité reste active jusqu'à la fin de la période
  payée. Couper à l'instant du clic ferait payer un service qu'on retire.
- **Le client résilie puis revient** : son établissement est conservé, ses modules éteints. C'est
  RG-ED-06 poussé à sa conclusion — et c'est le meilleur argument de reconquête qui soit.
- **Deux souscriptions simultanées pour le même e-mail** : le provisioning doit trancher sur une clé
  métier stable, pas sur l'ordre d'arrivée des webhooks.

## 8. Dépendances

- **Dépend de :** `Platform` (bus, registre, `ModuleAccess`), `Fonctionnalite` (activation),
  `Organisation` (établissements), `Securite` (comptes, affectations), `Sepa`, `Facturation`, `Crm`,
  `Recouvrement`.
- **Événements à ajouter au catalogue :** `subscription.activated`, `subscription.cancelled`,
  `subscription_option.added`, `subscription_option.removed`, `establishment.provisioned`.
  `subscription.created`, `subscription.suspended`, `payment.failed` et `invoice.overdue` existent déjà.
- **Débloque :** la commercialisation de la plateforme.
