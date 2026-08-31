# Spec — Campagnes marketing (`CMP-0`)

> Rédigée par `claude-A` le 25/08/2026, sur demande de Maxime.
> Décision fondatrice : **D42**. Frontière avec Revenue Recovery : voir §2.

---

## 0. Constat vérifié avant conception (à ne pas re-découvrir en plan)

Ces cinq points ont été **vérifiés dans le dépôt**, pas supposés. Ils déterminent tout ce qui suit.

1. **Le consentement RGPD est modélisé, et il est bon.** `Crm\Entity\Consentement`,
   `CanalConsentement` (`email`, `sms`, `courrier`), `EtatConsentement` (`accorde`, `refuse`,
   `a_renouveler`). Rien à concevoir : à **utiliser**, et de telle sorte qu'on ne puisse pas
   l'oublier.
2. **Rien n'envoie quoi que ce soit à un client aujourd'hui.** `SmartFlow\Port\
   ClientNotificationInterface` existe, et son seul adaptateur **écrit dans un journal**. Le canal
   d'envoi réel n'existe pas et dépend d'un prestataire (§9).
3. **Aucune notion d'audience, de segment ou de ciblage n'existe** dans tout le dépôt. C'est le cœur
   du module et son seul vrai travail de conception.
4. **Les données de comportement sont déjà là** : `dateDerniereVisite`, `caCumule`, abonnement,
   solde de carte multi-entrées, activités pratiquées, établissement de création. C'est ce qu'aucun
   outil généraliste ne possède.
5. **L'ordonnanceur existe depuis le 25/08** (`platform:scheduler:run`, D36). Une campagne récurrente
   n'a donc pas à inventer sa propre horloge : elle s'y déclare.

---

## 1. Objectif

Permettre à un exploitant de **provoquer une venue qui n'allait pas avoir lieu**, puis de **savoir si
elle a eu lieu**.

La seconde moitié de cette phrase est la raison d'être du module. Un outil d'emailing dit qui a
ouvert ; cette plateforme tient la vente, donc elle peut dire **qui est revenu, ce qu'il a acheté et
combien ça a rapporté**. C'est le seul avantage qu'un concurrent généraliste ne peut pas copier —
**si le module ne porte pas l'attribution, il ne vaut pas la peine d'être écrit** (D42).

---

## 2. Périmètre

### 2.1 Dans le périmètre

Audience, message, canaux, planification, consentement, pression commerciale, attribution.

### 2.2 Hors périmètre, et pourquoi

| Exclu | Raison |
|---|---|
| **Revenue Recovery** | Réactif et individuel : un **événement** arrivé à **un** client. Une campagne est proactive et collective : une **décision** de l'exploitant sur une **audience**. Panier abandonné → recovery. « Les abonnés qui n'ont pas nagé depuis trois mois » → campagne. |
| **Publication sociale** (`Social`) | Une publication s'adresse à un public **inconnu et non consenti**. Une campagne s'adresse à des personnes **identifiées**, dont on détient le consentement. Les deux peuvent porter le même message ; ils n'ont ni la même contrainte légale, ni la même mesure. |
| **Éditeur de courriel visuel** | On ne réécrit pas un éditeur graphique. Gabarits simples, variables nommées, prévisualisation. |
| **Envoi réel** | `CMP-6`, `EXTERNE` — voir §9. |

**Règle d'arbitrage en cas de doute** : si un lot ne rentre dans aucune des deux cases
(recovery / campagne), c'est que l'un des deux modules ment. On tranche **avant** d'écrire, pas après
— le précédent SF-0 a coûté 415 lignes de travail parallèle.

---

## 3. Acteurs & droits

| Acteur | Ce qu'il fait | Permission pressentie |
|---|---|---|
| Responsable d'établissement | crée, planifie, lance une campagne sur **son** périmètre | `campagne.gerer` |
| Agent | consulte les campagnes et leurs résultats | `campagne.lire` |
| Administrateur groupe | campagne portant sur plusieurs établissements du groupe | `campagne.gerer` + périmètre |
| Délégué à la protection des données | consulte qui a été contacté, quand, sur quelle base légale | `campagne.lire_journal` |

Les permissions sont **semées par migration et rattachées à au moins un rôle** — une permission
qu'aucun rôle ne détient protège aussi bien qu'un mur sans porte (constat du 24/08).

---

## 4. Comportements & règles

### 4.1 Audience (`CMP-2`)

**RG-CMP-01 — Une audience est une liste de critères, jamais une requête écrite à la main.** Un
exploitant de piscine ne rédige pas de SQL, et une requête libre serait une porte ouverte sur les
données des autres établissements.

Critères de première version, tous issus de données existantes :

- dernière visite (avant / après une date, ou « depuis plus de N jours »)
- montant cumulé (au-dessus / en dessous d'un seuil)
- abonnement : actif, échu, échéant dans N jours
- carte multi-entrées : solde restant au-dessous d'un seuil
- activité pratiquée (piscine, padel, patinoire, musée…)
- tranche d'âge, à partir de la date de naissance
- établissement de rattachement

**RG-CMP-02 — Le nombre de personnes touchées est affiché avant tout envoi.** Sans ce chiffre,
l'exploitant découvre l'ampleur de son geste après l'avoir fait.

**RG-CMP-03 — Les critères sont stockés, la liste est matérialisée à l'envoi.** Une campagne
programmée pour la semaine prochaine doit viser qui remplit les critères **ce jour-là**, pas
aujourd'hui. Mais la liste effectivement contactée est **conservée** — sans elle, l'attribution (§4.5)
est impossible et le journal RGPD (§8) est faux.

### 4.2 Message & canaux (`CMP-3`)

**RG-CMP-04 — Un message porte des variables nommées, jamais de concaténation** : `{{prenom}}`,
`{{solde_carte}}`, `{{date_echeance}}`. Une variable non résolue **empêche l'envoi** au lieu de
produire « Bonjour , ».

**RG-CMP-05 — Un canal indisponible n'est pas un canal silencieux.** Tant que `CMP-6` n'est pas livré,
l'adaptateur de journal est le seul canal, et l'écran le dit : « aucun envoi réel — journal
uniquement ». On ne laisse jamais croire qu'un message est parti.

### 4.3 Consentement — la règle qui prime sur toutes les autres

**RG-CMP-06 — Le consentement est vérifié à l'envoi, par destinataire et par canal, et il est
impossible à contourner.** Le contrôle vit dans le service d'envoi, pas dans l'écran ni dans la
constitution de l'audience : un chemin d'envoi qui l'oublierait ne doit pas exister.

**RG-CMP-07 — Échec fermé.** `accorde` sur le canal visé → envoi. `refuse`, `a_renouveler`, ou
**absence de consentement** → exclusion. L'absence n'est pas un « peut-être ».

**RG-CMP-08 — Les exclus sont comptés et affichés**, par motif. « 1 240 ciblés, 310 exclus faute de
consentement » est une information dont l'exploitant a besoin — et c'est souvent le signal qu'il faut
d'abord travailler le recueil du consentement, pas le message.

### 4.4 Planification & déclencheurs (`CMP-4`)

Trois formes, et une seule mécanique :

1. **Ponctuelle** — une date, une heure.
2. **Récurrente** — se déclare dans `ScheduleCatalog` (D36), pas dans une horloge maison.
3. **Déclenchée par un événement de domaine** — anniversaire, abonnement échéant dans 30 jours, carte
   descendue à une entrée. Les événements existent déjà pour partie ; ceux qui manquent sont des
   préalables au sens de D22 : **l'émetteur avant le module**.

**RG-CMP-09 — Une campagne déclenchée est individuelle par nature.** Elle est ici parce que
l'exploitant l'a **décidée à l'avance pour une population** — c'est ce qui la distingue de Revenue
Recovery, où c'est l'événement qui décide.

### 4.5 Attribution (`CMP-5`) — la raison d'être du module

**RG-CMP-10 — On mesure la venue, pas l'ouverture.** Fenêtre d'attribution paramétrable (30 jours par
défaut) : toute vente d'un destinataire dans la fenêtre est rattachée à la campagne.

**RG-CMP-11 — Un groupe témoin, sinon on ne mesure rien.** Une part de l'audience (10 % par défaut,
réglable, désactivable) est **délibérément non contactée**. Sans lui, on mesure combien de gens sont
revenus — pas combien sont revenus **grâce à** la campagne. La différence entre les deux groupes est
le seul chiffre honnête, et c'est celui qu'aucun outil généraliste ne peut produire.

**RG-CMP-12 — Le rattachement est déclaré, jamais déduit après coup.** La liste contactée et la liste
témoin sont figées à l'envoi. Recalculer l'audience au moment de mesurer produirait un chiffre
flatteur et faux.

### 4.6 Pression commerciale

**RG-CMP-13 — Un plafond de sollicitation par client, par canal et par période.** Un client qui reçoit
cinq campagnes en une semaine se désabonne, et on perd le canal pour toujours. Le plafond est un
réglage d'établissement, appliqué **au moment de l'envoi**, et les écartés sont comptés comme les
non-consentants (RG-CMP-08).

---

## 5. Objets de données (pressentis, sans code)

| Objet | Ce qu'il porte |
|---|---|
| `Campaign` | libellé, établissement, statut (brouillon / planifiée / envoyée / arrêtée), fenêtre d'attribution, part du groupe témoin |
| `Audience` | les **critères**, rattachés à la campagne |
| `CampaignMessage` | canal, objet, corps avec variables, gabarit |
| `CampaignRecipient` | **la liste figée** : client, canal, groupe (contacté / témoin), état (envoyé / exclu), motif d'exclusion |
| `CampaignOutcome` | ventes rattachées dans la fenêtre, par groupe |

`Campaign` porte un établissement : **il ne figure dans aucun groupe d'écriture** (D41).

---

## 6. Événements consommés / produits

**Consommés** : ceux des déclencheurs (§4.4). **Produits** : `campaign.sent`, `campaign.recipient_excluded`,
`campaign.attributed`.

Chaque émission passe son **instant métier** explicitement (D37) — l'instant où le fait s'est produit
pour le client, jamais l'heure d'exécution. Et un événement n'entre au catalogue **que dans le commit
de son émetteur** (D22).

---

## 7. API & écrans-ou-modales (D13)

- **Écran** : liste des campagnes, avec leur résultat.
- **Écran** : la campagne elle-même — audience, message, planification, résultat. Assez dense pour
  justifier un écran.
- **Modale** : prévisualisation de l'audience (le compte et un échantillon).
- **Modale** : confirmation d'envoi, qui rappelle le nombre de contactés, d'exclus et de témoins.

Le front **rejoue entièrement** la règle de droits ou ne filtre pas (D39), et utilise `api/droits.js`.

---

## 8. Sécurité & cloisonnement

**RG-CMP-14 — Une audience ne sort jamais du périmètre de son auteur.** Le contrôle porte sur les
clients **résolus**, pas sur les critères. Un critère d'établissement fourni par le client est
confronté au périmètre, refus en **404**.

**RG-CMP-15 — Le journal des contacts est conservé et consultable** : qui, quand, sur quel canal, sur
quelle base légale. C'est une obligation, et c'est aussi le contrôle citoyen du module —
*un mécanisme qu'on ne peut pas relire finit par ne plus être surveillé*.

**RG-CMP-16 — Un export de l'audience est un traitement, pas un bouton.** S'il existe, il est tracé
comme un accès aux données personnelles.

---

## 9. Dépendances & préalables

| Préalable | État |
|---|---|
| `CMP-1` — remonter `ClientNotificationInterface` de `SmartFlow` vers `Platform` | à faire, `claude-A` |
| Événements déclencheurs manquants | D22 : l'émetteur avant le module |
| `CMP-6` — prestataire d'envoi réel | **EXTERNE** : contrat, donc immatriculation |

**On ne l'attend pas (D19).** Le module se construit contre le port et se livre avec l'adaptateur de
journal : audience, consentement, planification, attribution — tout est écrit, testé et démontrable
**sans qu'une seule adresse ne soit contactée**. Le jour où le prestataire existe, on écrit un
adaptateur et rien d'autre ne bouge.

---

## 10. Questions ouvertes à arbitrer par Maxime

1. **Le groupe témoin par défaut.** 10 % non contactés, c'est 10 % de chiffre d'affaires potentiel
   sacrifié pour savoir si la campagne sert à quelque chose. Défendable, mais c'est un choix
   commercial et pas technique. Activé par défaut, ou proposé ?
2. **Le plafond de sollicitation.** Combien de messages par client et par mois avant qu'on ne
   considère qu'on l'abîme ?
3. **Le SMS.** Il coûte à l'unité, contrairement au courriel. Faut-il un garde-fou de budget par
   campagne, ou la confiance suffit-elle ?
4. **La fenêtre d'attribution.** 30 jours convient pour une piscine ; un musée a des cycles plus
   longs. Réglage par établissement, ou par campagne ?

---

## 11. Critères d'acceptation

- **CA-1** — Une audience construite sur « pas venu depuis 90 jours » affiche son effectif avant envoi.
- **CA-2** — Un destinataire sans consentement sur le canal visé est **exclu**, et compté comme tel.
- **CA-3** — Un destinataire dont le consentement est `a_renouveler` est **exclu** (échec fermé).
- **CA-4** — Une variable non résolue **empêche** l'envoi de ce destinataire, sans bloquer les autres.
- **CA-5** — Le groupe témoin n'est pas contacté, et il apparaît dans le résultat.
- **CA-6** — Une vente d'un destinataire dans la fenêtre est rattachée ; hors fenêtre, non.
- **CA-7** — Un client au plafond de sollicitation est exclu avec le motif.
- **CA-8** — Une campagne d'un établissement A ne peut pas viser un client du seul établissement B :
  **404**, et le nom du client ne fuit pas dans le refus.
- **CA-9** — Tant que `CMP-6` n'existe pas, l'écran annonce « journal uniquement » et aucun envoi réel
  n'a lieu.
- **CA-10** — Le journal des contacts est consultable et complet.

---

## 12. Cas limites

- **Un client fusionné** entre l'envoi et la mesure : la vente est rattachée au survivant.
- **Un client qui retire son consentement** après l'envoi : il reste dans le journal — on ne réécrit
  pas l'histoire — mais il n'est plus contacté ensuite.
- **Une campagne récurrente dont l'audience devient vide** : elle ne tombe pas en erreur, elle le dit.
- **Un destinataire dans deux campagnes simultanées** : le plafond de sollicitation tranche, et le
  motif d'exclusion nomme la campagne concurrente.
- **Une vente d'un membre du groupe témoin** : elle est mesurée, c'est tout l'objet du groupe témoin.
- **Une campagne arrêtée en cours d'envoi** : les déjà-contactés restent au journal et à l'attribution.
