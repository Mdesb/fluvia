# PLAYBOOK PLATEFORME — le document unique

> **Le seul document à lire.** Il contient tout : vision, décisions, architecture, contrat, méthode,
> coordination à plusieurs Claude, et **toutes les commandes**. Les fichiers `CONTRACT/*` et les
> autres `COORDINATION/*` en sont le détail éditable ; ce playbook est la vue d'ensemble canonique.

---

## SOMMAIRE
1. Vision & décisions
2. Architecture (cœur + modules + bus d'événements)
3. Carte des modules
4. Le contrat : noyau commun · manifeste de module · catalogue d'événements
5. La méthode (SDD)
6. Travailler à plusieurs Claude (coordination)
7. Toutes les commandes (setup VPS · boucle · tests · intégration · déploiement)
8. Gestion des conflits
9. Garde-fous CI
10. Checklists
11. État & feuille de route

---

## 1. VISION & DÉCISIONS

**Vision.** Une seule coquille technique, des **modules activables à la carte**, des sites vitrines
spécialisés par métier. Le client s'inscrit, choisit ses modules, ne paie que ça. Comme Odoo, en mieux.
Cibles = **tout le monde** ; on construit ensuite des verticales/horizontales métier par-dessus.

**Décisions actées** (journal complet dans [DECISIONS.md](DECISIONS.md)) :
- **D1 — Socle unique.** Le core **Symfony 7 / API Platform / Doctrine / MariaDB** de la billetterie
  devient LA coquille. Finance, Smart Flow, Revenue Recovery, puis domaines OFS/Vespera = modules.
  Retrofit **incrémental**, jamais big-bang.
- **D2 — Contract-first.** Pas de module avant son manifeste + ses événements. Communication **par
  événements**, jamais d'appel direct module→module.
- **D3 — Cloisonnement.** Périmètre (tenant) toujours dérivé de la **session serveur**, jamais d'un id
  client. Échec fermé (403/404). *(5 violations réelles trouvées & corrigées le 19/08.)*
- **D4 — Finance sur le core.** Factures, Compta/FEC, Trésorerie, Notes de frais s'étendent sur
  l'existant (`Facturation`, `Compta`, `SEPA`). **OCR** = service transverse partagé.
- **D5 — Anglais + i18n.** Tout le technique (code, DB, API, événements, permissions) en **anglais** ;
  l'UI via **clés i18n** (FR défaut) + **agent de traduction IA au build**. Existant FR migré
  incrémentalement.

**Les 3 projets du contexte** (même ADN, 3 stacks) : Billetterie (Symfony/MariaDB → la coquille) ·
Vespera (Next.js/Prisma/PostgreSQL) · OFS Global (PHP 8.1/MariaDB). Vespera & OFS re-logés en modules
ou fédérés par API, progressivement.

---

## 2. ARCHITECTURE

Trois couches reliées par un **bus d'événements** :

```
COUCHE 3 · PACKS MÉTIER      Piscine · Padel · Musée · … · Coiffure · BTP   (fine config + règles)
COUCHE 2 · MODULES           activables, agnostiques (voir §3)
        ── BUS D'ÉVÉNEMENTS ── le cœur émet des faits, les modules réagissent
COUCHE 1 · CŒUR              identité · orgs · cloisonnement · permissions · audit · billing/features
                             · communication · i18n · automation · bus · registre de modules
SERVICES TRANSVERSES         OCR · GED · Signature · Communication · i18n
```

**Noyau de domaine agnostique** : toute activité = un chemin dans le pipeline
**Offer → Engagement → Fulfillment → Revenue**, chaque étape avec des stratégies interchangeables.
Le cœur ne dit jamais « piscine ». Contrôle d'accès et caisse guichet sont **optionnels**.

---

## 3. CARTE DES MODULES
`✓` existant · `~` en cours · `◆` à venir · **NEW** = ajouté à la roadmap

**Services transverses partagés** : OCR ◆ · GED interne ◆ **NEW** · Signature électronique ◆ **NEW** ·
Communication ~ · i18n + agent de traduction ◆.

| Famille | Modules |
|---|---|
| **Vente & Offre** | Offre/Catalogue ✓ · Vente/Caisse ✓ · Boutique ✓ · Réservation & no-show ✓ · Contrôle d'accès ✓ · Stock ✓ · Options ✓ |
| **Finance & Compta** | Factures client+fournisseur ~ · Compta+FEC ~ · Trésorerie ◆ · Notes de frais ◆ **NEW** · SEPA/abos/anti-impayés ✓ |
| **CRM & Avant-vente** | CRM ✓ · Devis ✓ · Revenue Recovery ◆ **NEW** · Check-list faisabilité prospect ◆ **NEW** · Analyse d'appels d'offres + pré-réponse IA ◆ **NEW** |
| **Terrain & Intervention** | Validation d'intervention sur site ◆ **NEW** (PV + signature + photos — réutilisable OFS) |
| **RH & Interne** | Personnel & planning ✓ · Notes de frais ◆ **NEW** · Formation paramétrable ◆ **NEW** |
| **Temps réel** | Smart Flow ◆ **NEW** (retards, slot recovery, liste d'attente, affluence) |
| **Pilotage & Support** | Reporting ✓ · Base de connaissance/Support ✓ · Autorisations graduées ✓ · Caution ✓ |

Détail : [CONTRACT/catalogue-modules.md](CONTRACT/catalogue-modules.md).

---

## 4. LE CONTRAT

### 4.1 Noyau commun (tout module peut le supposer présent)
Identité/auth · Organisations/Tenant · Cloisonnement (périmètre serveur) · Permissions **module×action**
(`is_granted('PERM','module.action')`) · Audit · Billing & Features (capacités + features) ·
Communication · **i18n** · Automation/Scheduler · **Bus d'événements** · **Registre de modules**.
Détail : [CONTRACT/noyau-commun.md](CONTRACT/noyau-commun.md).

### 4.2 Manifeste de module
Chaque module déclare : `id`, `version`, `capacite`, `dependencies`, `permissions`, `events_emitted`,
`events_consumed`, `routes`, `settings`, `features`. Deux niveaux d'activation : `hasModule()` **et**
`hasFeature()`. Détail : [CONTRACT/manifeste-module.md](CONTRACT/manifeste-module.md).

### 4.3 Catalogue d'événements (anglais, `domain.fact_past_tense`)
Enveloppe : `{ name, occurredAt, tenant{establishmentId}, actor{userId}, subject{type,id}, payload }`.
Principaux : `sale.completed` · `payment.failed` · `refund.issued` · `invoice.issued` ·
`invoice.overdue` · `booking.created` · `booking.no_show` · `slot.released` · `subscription.suspended` ·
`access.recorded` · `quote.expired` · `customer.inactive` · `supplier_invoice.recorded` ·
`expense_report.submitted` · `intervention.validated`. Détail : [CONTRACT/catalogue-evenements.md](CONTRACT/catalogue-evenements.md).

---

## 5. LA MÉTHODE (SDD)
Chaque module : **spec → plan → impl → revue de cohérence → correctif → vert → intégration**.
Agents : `sdd-analyste` (spec) · `sdd-architecte` (plan) · `sdd-dev-symfony` (impl) · `sdd-revue`
(cherche activement les défauts). Un module n'est intégré que **vert + revu**.

---

## 6. TRAVAILLER À PLUSIEURS CLAUDE

**Pas de canal live entre sessions.** On se coordonne **par le dépôt** :
- **Le dépôt est le canal** : commit = envoyer, `git pull` = recevoir.
- **[MESSAGES.md](MESSAGES.md)** : tableau d'échange async entre Claude.
- **[TASKS.md](TASKS.md)** : claim d'une tâche = un verrou, **avant** de démarrer.
- **[OWNERS.md](OWNERS.md)** : propriété des modules — on n'écrit que dans ses dossiers.
- **Un intégrateur** (un seul Claude) : possède `main` + `CONTRACT/`, fusionne après CI verte + revue,
  et **déploie seul** la préprod.

Modèle : **dépôt partagé + un worktree/branche par Claude + propriété disjointe + intégrateur**.

---

## 7. TOUTES LES COMMANDES

### 7.1 Installation VPS (une fois)
```bash
cd /home/debian/billetterie
git worktree add -b claude-A ../wt/claude-A main
git worktree add -b claude-B ../wt/claude-B main
git worktree add -b claude-C ../wt/claude-C main
```

### 7.2 Boucle de travail (chaque Claude)
```bash
cd /home/debian/wt/claude-A
git fetch origin && git rebase origin/main        # partir du dernier état
# lire MESSAGES.md, claim la tâche dans TASKS.md
# … travailler UNIQUEMENT dans ses modules (OWNERS.md) …
git add app/src/<Module> app/tests/<Module>       # staging explicite, jamais git add -A
git commit -m "…"
git push origin claude-A
# laisser un message d'intégration dans MESSAGES.md
```

### 7.3 Tests isolés (token distinct par Claude, sinon les bases de test s'écrasent)

Un worktree neuf **n'est pas testable en l'état** : `vendor/` n'y est pas, les clés JWT non plus
(`config/jwt/*.pem` est ignoré par git), et la base de test n'existe pas. Sans ces trois étapes on
obtient des `JWTEncodeFailureException` et des `TableNotFoundException` en cascade — symptômes
bruyants, cause invisible. D'où un script unique, `infra/test-stack.sh`, à préférer aux commandes
manuelles :

```bash
# une fois par worktree — dépendances dev (phpunit n'est pas dans le vendor de préprod)
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD/app:/app" -w /app billetterie-preprod-php composer install --no-scripts

# réseau + base + droits + clés JWT + schéma de test, isolés par token
./infra/test-stack.sh up claudeA

# la suite, ou un module seulement
./infra/test-stack.sh run claudeA
./infra/test-stack.sh run claudeA tests/Platform

# libérer réseau et base (les clés JWT du worktree sont conservées)
./infra/test-stack.sh down claudeA
```

**Repasse par `up` avant de tester un autre module.** Les classes de base font `dropSchema` puis
`createSchema`, et ce couple ne nettoie pas toujours une base laissée par un module différent : le
premier `setUp` échoue alors en « Base table or view already exists ». Ça ressemble à une régression,
ça n'en est pas une.

**Un token par Claude** (`claudeA`, `claudeB`, `claudeC`) : le token nomme le réseau, le conteneur de
base et la base elle-même (`app_test<TOKEN>`). Deux instances peuvent donc tester en même temps sans
que la suite de l'une détruise les fixtures de l'autre.

### 7.4 Intégration & déploiement (intégrateur uniquement)
```bash
cd /home/debian/billetterie
git fetch origin && git merge --no-ff origin/claude-A   # résoudre les conflits ici
# CI verte + revue de cohérence OK ?
git push origin main
./infra/deploy-preprod.sh                               # SEUL l'intégrateur déploie
```

---

## 8. GESTION DES CONFLITS
- **Anti-conflit n°1 = propriété disjointe** : dossiers différents ⇒ pas de conflit possible.
- **Fichiers partagés** (peu : `config/packages/api_platform.yaml`, migrations, `CONTRACT/`) :
  l'intégrateur les possède, ou patch chirurgical `git apply --cached --recount <patch>` après claim.
- **Migrations** : nom horodaté unique `Version20260819HHMMSS.php` ⇒ pas de collision.
- **Sérialisation** : les branches fusionnent **une par une** via l'intégrateur ; conflits résolus au merge.

---

## 9. GARDE-FOUS CI (le filet de confiance — permet de fusionner sans tout relire)
- **Cloisonnement** : refuser tout endpoint touchant la base sans contrôle de périmètre (exemption explicite).
- **Manifeste** : un module déclare ses événements/permissions/features.
- **Nommage anglais (D5)** : refuser un identifiant non-anglais dans une nouvelle migration/entité.
- **i18n** : refuser une chaîne d'UI en dur (doit être une clé).
- **CSRF / sécurité** de base.

---

## 10. CHECKLISTS
**Avant de pousser sa branche :** tests du module verts (token isolé) · staging explicite ·
identifiants anglais · libellés en clés i18n · événements déclarés au manifeste · TASKS.md à jour.
**Avant de merger (intégrateur) :** CI verte · revue de cohérence · pas de régression voisine ·
migration horodatée · MESSAGES.md répondu.

---

## 11. ÉTAT & FEUILLE DE ROUTE
**Posé** : sécurité durcie (5 failles cloisonnement, 146 tests verts, déployé) · contrat de plateforme
v0 · coordination 3-Claude · catalogue de modules · nommage anglais + i18n · **spec Suite Finance**
(6 fichiers, 5 lots).

**Prochaines pierres** (ordre conseillé) :
1. **Bus d'événements + registre de modules** (impl core) — débloque tout le reste.
2. **Services transverses** : GED · OCR · Signature (partagés par la moitié des modules).
3. **Suite Finance** : FIN-0 (OCR) + FIN-1 (extension Compta additive) en parallèle → FIN-2 →
   FIN-3 → FIN-4.
4. **Garde-fous CI** + (option) **agent de coordination autonome**.
5. Puis Smart Flow, Revenue Recovery, et les modules terrain/avant-vente/formation.

**À trancher côté client** : id + périmètre de chaque Claude dans OWNERS ; qui prend le rôle
d'intégrateur ; chiffrage des modules hors backlog.
