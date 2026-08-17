# Spec — Base de connaissance & Support (`App\Support`, module `support`)

- **Lot / module :** Module transverse **`App\Support`** (non positionné dans l'ordre L0→L7 de la
  constitution §5 ; consommé/référencé par **tous les modules** au titre de l'aide contextuelle, sans
  dépendance dure d'aucun d'eux vers lui).
- **Stories couvertes :** **US-SUP-01 à US-SUP-14** — ⚠ **HYPOTHÈSE / ABSENCE DE SOURCE OFFICIELLE** :
  `backlog.html` ne comporte **aucun onglet ni story `US-SUP-*`**, et `cahier-detaille.html` ne
  comporte **aucun panel dédié à une « Base de connaissance »/« Support »** (recherche vérifiée : les
  seules occurrences du mot « support » dans les sources désignent le **support d'accès physique**
  QR/RFID/wallet du module Accès — notion **homonyme et sans rapport**, à ne pas confondre). Les 14
  stories ci-dessous sont **définies par cet agent**, à la demande explicite du commanditaire (centre
  d'aide interne + reprise en production, tickets de support léger), sur le modèle déjà appliqué aux
  autres modules **hors backlog** de ce dépôt (`spec-personnel.md`, `spec-boutique.md`, `spec-stock.md`,
  `spec-reservation.md`, `spec-acces-terminal.md`). **À faire valider, numéroter et chiffrer
  officiellement** dans le backlog avant développement.
- **Règles de gestion :** **RG-SUP-01 à RG-SUP-15** (**nouvelles**, aucune ne préexiste dans le cahier).
  Règles socle **réutilisées, non redéfinies** : `RG-SOCLE-01` à `07` (`spec-socle.md`) — hiérarchie
  Groupe/Région/Établissement/Espace, Utilisateur, permissions `module × action`, établissement actif,
  journal d'audit append-only.
- **Statut :** brouillon — module **entièrement défini par cet agent** en l'absence de source cahier ;
  plusieurs points `⚠ HYPOTHÈSE` (convention de fichiers de doc vivante, règle de republication après
  import, priorité d'affichage local/global, réouverture de ticket) à trancher avant figement.

## 1. Objectif
Donner à tout utilisateur du logiciel — **agent back-office/exploitant** en priorité, et potentiellement
**usager grand public** — un **centre d'aide en ligne** cherchable et à jour (articles organisés par
catégories, publiés/brouillons, versionnés, rattachables à un module fonctionnel), et donner à un
**exploitant** un moyen **léger** de signaler un problème (**ticket de support**) suivi jusqu'à
résolution — le tout alimenté **en continu pendant le développement** par la documentation produite au
fil des livraisons de modules, pour que la base de connaissance soit **prête et à jour dès la mise en
production**, sans double-saisie ni re-rédaction a posteriori.

## 2. Périmètre

### 2.1 Inclus
**Base de connaissance (KB) :**
- **Catégories/rubriques hiérarchiques** avec **fil d'Ariane** — US-SUP-03.
- **Article d'aide** : titre, contenu, résumé, catégorie, mots-clés, statut **brouillon/publié**
  (+ archivé), **pièces jointes/captures** — US-SUP-02.
- **Versionnage** : chaque modification d'un article publié crée un **historique consultable**
  — US-SUP-06.
- **Ciblage** : **public cible** (agent interne / usager grand public / tous) et **portée**
  (**global** — éditeur/production — ou **local** à un établissement) — US-SUP-04.
- **Rattachement optionnel à un module fonctionnel** (ex. article « Encaisser une vente » lié au
  module `vente`) — US-SUP-05.
- **Recherche plein-texte**, filtrée par droits, portée et public — US-SUP-01/07.
- **Import/seed depuis la documentation vivante** produite pendant le développement (fichiers
  Markdown), pour pré-remplir puis tenir à jour la KB avec l'état réel des modules livrés — US-SUP-08.

**Support (tickets) — léger :**
- **Ouverture d'un ticket** par un exploitant (sujet, description, priorité, module concerné, pièces
  jointes) — US-SUP-09.
- **Cycle de vie** du ticket (nouveau → en cours → en attente client → résolu → fermé, réouverture
  encadrée) — US-SUP-10.
- **Échanges/commentaires** sur un ticket, avec pièces jointes, y compris **notes internes** non
  visibles du demandeur — US-SUP-11.
- **Affectation/escalade** à un support **N1/N2** — US-SUP-12.
- **Lien optionnel** d'un ticket vers un **article de KB** (référence proposée au demandeur) —
  US-SUP-13.
- **Tableau de bord** des tickets pour les agents support/admin (filtres statut, priorité, module,
  niveau) — US-SUP-14.

### 2.2 Exclu (pour l'instant), signalé explicitement
- **Chat en temps réel** (messagerie instantanée agent↔demandeur) — non spécifié, hors périmètre.
- **Téléphonie / centre d'appels** (routage d'appels, IVR, click-to-call) — hors périmètre.
- **SLA contractuels complexes** (délais de première réponse/résolution engagés contractuellement,
  pénalités, escalade automatique au dépassement) — hors périmètre ; seule une **priorité déclarative**
  (§4.10) est portée, **sans minuteur ni engagement**, cf. consigne explicite du commanditaire (« léger —
  pas un Zendesk complet »).
- **Ticket ouvert par un usager final grand public** (client de la billetterie, non-exploitant) — le
  module de tickets est conçu ici **pour l'exploitant** (staff du client IT Cotation) **vers le support**
  (équipe éditeur/N1-N2), pas comme un service client B2C — ⚠ **point ouvert, cf. récapitulatif final**
  point 1 : l'ouverture d'un ticket par un usager (ex. depuis l'app client L8) n'est **pas exclue en
  soi** par l'intention du commanditaire mais **n'est pas demandée explicitement** ; à confirmer.
- **Enquête de satisfaction (CSAT/NPS)** post-résolution — non demandée, hors périmètre v1.
- **Traduction/multilinguisme des articles** — non demandé pour ce module (à la différence de la
  vitrine boutique L8 qui est multilingue, `spec-boutique.md` §4.1) ; ⚠ HYPOTHÈSE à confirmer si le
  centre d'aide est un jour exposé à des usagers non francophones.
- **Protocole technique d'un éventuel widget d'aide contextuelle embarqué dans chaque écran** (bulle
  d'aide, suggestions in-app) — la **capacité** (article rattaché à un module, §4.5) est spécifiée ici ;
  son **intégration UI** dans chaque module reste à faire au plan technique, non détaillée ici.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur
le module **`support`**, portées par l'**établissement actif** pour tout ce qui est local (KB locale,
tickets) ; l'UI **masque** ce qui n'est pas autorisé (`RG-SOCLE-04`). Le **lecteur usager** n'est pas un
utilisateur staff : il consulte la KB **publique** sans authentification requise pour les articles ciblés
`usager`/`tous` (à la manière de la vitrine boutique, `spec-boutique.md` §3, mais **sans** notion
d'achat ni de compte ici).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Lecteur usager (grand public, sans compte)** | Parcourir/rechercher les articles **publiés**, ciblés `usager` ou `tous`, en portée **globale** ou **locale à l'établissement consulté** | Voir un brouillon/archivé, un article ciblé `agent`, ouvrir un ticket | `support × lire_public` *(accès non authentifié, cf. §7)* |
| **Agent (utilisateur staff, tout module)** | Parcourir/rechercher **toute la KB publiée** (ciblage `agent`/`usager`/`tous`), consulter l'aide contextuelle liée à l'écran qu'il utilise | Voir un brouillon d'un autre auteur, modifier un article | `support × lire` |
| **Rédacteur KB (éditeur/production)** | Créer/modifier/publier/archiver des **articles globaux**, gérer les **catégories globales**, consulter l'historique de versions | Publier un article **local** d'un établissement qu'il ne gère pas | `support × gerer_kb_globale`, `support × gerer_categorie` |
| **Rédacteur KB (établissement)** | Créer/modifier/publier/archiver des **articles locaux** de **son** établissement actif, dans les catégories existantes | Créer/modifier un article **global**, créer une catégorie globale | `support × gerer_kb_locale` |
| **Exploitant (demandeur de ticket)** | Ouvrir un ticket, consulter/répondre à **ses propres tickets** (et, s'il en a le droit, ceux de son établissement) | Voir les tickets d'un autre établissement, réaffecter/escalader un ticket, poster une note interne | `support × ouvrir_ticket`, `support × lire_ticket_soi` |
| **Responsable établissement (délégation)** | Consulter/suivre **tous les tickets de son établissement**, relancer | Traiter un ticket (rôle agent support) | `support × lire_ticket_etablissement` |
| **Agent support N1** | Prendre en charge, répondre, résoudre/clore un ticket **N1**, escalader vers N2, lier un article KB, poster une note interne | Créer/modifier un article global | `support × traiter_ticket_n1` |
| **Agent support N2** | Idem N1 + traiter les tickets **escaladés** N2, réaffecter entre agents | — | `support × traiter_ticket_n2` |
| **Administrateur (support)** | Gérer les catégories globales, réaffecter/rouvrir un ticket, exécuter/superviser l'**import de la doc vivante** (§4.8), purger un article obsolète | Contourner l'audit | `support × administrer` |
| **Système** | Exécuter l'import/seed doc vivante, calculer les résultats de recherche filtrés par droits, journaliser un import, notifier (nouvelle réponse, changement de statut) | Publier un article sans passage par un rédacteur habilité | *(acteur technique — pas de permission humaine)* |

- ⚠ HYPOTHÈSE — Les noms de permissions `support × …` ne sont **nommés nulle part** dans les sources (le
  cahier n'ayant pas de panel dédié) ; découpage **dérivé par analogie** avec les autres modules de ce
  dépôt (`personnel`, `boutique`), **à arbitrer avec M8**.
- ⚠ HYPOTHÈSE — La distinction **Rédacteur KB global vs local** n'est pas nommée par le commanditaire,
  qui parle d'un seul « rédacteur KB » ; retenue par cohérence avec la double portée globale/locale
  explicitement demandée (§4.4), sur le modèle **Administrateur groupe / Administrateur établissement**
  du socle (`spec-socle.md` §3).

## 4. Comportements & règles

### 4.1 Catégories & fil d'Ariane (US-SUP-03)
- **RG-SUP-01** — Une **CategorieAide** forme un **arbre** (parent optionnel, profondeur non limitée par
  la donnée). La navigation dans un article affiche son **fil d'Ariane** complet (racine → … → catégorie
  de l'article).
- Les catégories sont **globales** par défaut (référentiel commun éditeur/production) — ⚠ HYPOTHÈSE :
  aucune catégorie strictement locale à un établissement n'est prévue en v1 (les articles **locaux**
  s'insèrent dans l'arbre **global** existant, §4.4) ; à confirmer si un établissement a besoin d'une
  rubrique propre.
- Une **CategorieAide** contenant des articles ne peut être **supprimée** (intégrité, cohérent
  `RG-SOCLE-01`/suppression bloquée d'une Établissement rattachée).

### 4.2 Article d'aide — structure, statut, pièces jointes (US-SUP-02)
- **RG-SUP-02** — Un **ArticleAide** porte un titre, un contenu (texte enrichi/Markdown), un résumé
  (aperçu recherche), une **CategorieAide**, des **mots-clés**, des **pièces jointes/captures**, et un
  **statut** : `brouillon` (visible seulement des rédacteurs/admin), `publié` (visible selon ciblage §4.4)
  ou `archivé` (retiré de la recherche/navigation, reste accessible par lien direct, cf. §7).
- Un article **brouillon** ne peut être **ni recherché ni navigué** par un lecteur usager/agent ; sa
  publication est une action **explicite** (bouton « Publier »), distincte de l'enregistrement.
- Toute modification d'un article — brouillon ou publié — est enregistrée (§4.3) ; **publier** un article
  fige la **VersionArticle courante** comme **version publiée de référence**.

### 4.3 Versionnage & historique (US-SUP-06)
- **RG-SUP-03** — Chaque enregistrement (création, modification, publication) d'un `ArticleAide` crée une
  **VersionArticle** (snapshot du contenu, auteur, horodatage, statut au moment de l'enregistrement).
  L'**historique complet** est consultable par un rédacteur/admin ; **aucune version n'est supprimée**
  (append-only, cohérent `RG-SOCLE-07`).
- Le lecteur (usager/agent) voit toujours la **dernière version publiée** ; un rédacteur en train
  d'éditer voit son **brouillon en cours**, potentiellement postérieur à la dernière version publiée.

### 4.4 Ciblage — public & portée globale/locale (US-SUP-04)
- **RG-SUP-04** — Un `ArticleAide` porte un **publicCible** (`agent`, `usager`, `tous`) et une **portée**
  (`global` — rédigé par l'éditeur/production, visible de **tous les établissements** qui remplissent la
  condition de public — ou `local` — rattaché à **un seul Établissement**, requis dans ce cas).
- Un article **local** n'est **jamais visible** en dehors de son établissement, y compris pour un même
  groupe/région (cohérent `RG-SOCLE-05`, cloisonnement strict par établissement).
- ⚠ HYPOTHÈSE — En cas de **coexistence** d'un article global et d'un article local traitant du même
  sujet pour un même établissement, **aucune fusion ni règle de priorité stricte** n'est imposée par le
  commanditaire ; retenue par défaut : les deux apparaissent dans les résultats de recherche de
  l'établissement concerné, l'article **local est mis en avant** (tri en tête, car plus spécifique) —
  **à confirmer** avec le produit.
- Un lecteur usager anonyme (§7, pas d'établissement identifié) ne voit que les articles **globaux**
  ciblant `usager`/`tous` — les articles **locaux** ne lui sont montrés que dans un contexte où
  l'établissement est connu (ex. centre d'aide intégré à la vitrine white-label d'un établissement,
  `spec-boutique.md` §4.1) — ⚠ HYPOTHÈSE, intégration non détaillée (§2.2).

### 4.5 Rattachement à un module fonctionnel (US-SUP-05)
- **RG-SUP-05** — Un `ArticleAide` peut porter une référence **`moduleLie`** vers un module fonctionnel
  du logiciel (ex. `vente`, `offre`, `acces`, `reservation`, `personnel`, `boutique`, `compta`, `crm`,
  `reporting`, `backoffice`, `piscine`, `padel`, `patinoire`, `sport`, `musee`, `stock`, `support`…). La
  **liste des modules** est un **référentiel ouvert et paramétrable** (cohérent constitution §4 point 4,
  « aucune logique métier codée en dur ») — ⚠ HYPOTHÈSE : non figée dans une énumération technique
  fermée, pour ne pas nécessiter de migration à chaque nouveau module livré.
- Ce rattachement permet de **filtrer** la KB par module (ex. tous les articles liés à `vente`) et
  d'alimenter une future **aide contextuelle** par écran (§2.2, hors périmètre l'intégration UI elle-même
  ici).
- Un article peut n'avoir **aucun** module lié (article transverse : « Se connecter », « Gérer mon
  compte »…).

### 4.6 Pièces jointes / captures (US-SUP-02)
- Une **PieceJointeAide** (image, capture d'écran, PDF) est rattachée à un `ArticleAide` (ou à une
  `VersionArticle` spécifique pour garder l'historique visuel) ; taille/format acceptés — ⚠ HYPOTHÈSE
  non chiffrée par les sources, à paramétrer au plan technique.

### 4.7 Recherche plein-texte (US-SUP-01/07)
- **RG-SUP-06** — La recherche porte sur titre, résumé, contenu et mots-clés des `ArticleAide`
  **publiés**, filtrés par les mêmes règles de visibilité que la navigation (§4.2/4.4) : jamais de
  brouillon/archivé, jamais d'article local hors établissement, jamais d'article ciblé `agent` pour un
  lecteur usager.
- **Aucun résultat** — état vide explicite, avec **suggestion d'ouvrir un ticket** pour un agent/
  exploitant authentifié (§7).

### 4.8 Import/seed depuis la documentation vivante (US-SUP-08) — mécanisme « doc vivante → KB »
- **RG-SUP-07 (doc vivante, décision structurante de ce module)** — La **documentation technique SDD**
  de ce dépôt (`specs/<lot>/spec-*.md`) est un artefact **d'analyse**, destiné aux agents et au
  commanditaire (traçabilité RG/US), **pas** un article d'aide utilisable tel quel par un agent
  back-office ou un usager. La KB est alimentée par une **documentation utilisateur distincte**, écrite
  en langage produit au fil des livraisons — la **« doc vivante »** — qui devient la **source de vérité
  versionnée** (suivie en git, revue comme du code) à partir de laquelle la KB est **publiée**.
- **RG-SUP-08 (convention & import)** — ⚠ HYPOTHÈSE (convention non actée, à valider) : la doc vivante
  est portée par des fichiers **Markdown** avec **en-tête structuré** (front matter), un fichier par
  article, sous une convention de type `docs/aide/<module>/<slug>.md`, portant a minima : `titre`,
  `categorie`, `publicCible`, `portee` (+ `etablissement` si `local`), `moduleLie`, `statut` (défaut
  `brouillon`) et une **clé d'import stable** (`cleImport`, dérivée du chemin si absente).
- Un **import/seed** (commande dédiée — ⚠ HYPOTHÈSE nom `support:kb:seed`, non arbitré) lit l'ensemble
  des fichiers de la convention et **upsert** un `ArticleAide` par `cleImport` :
  - fichier **nouveau** → `ArticleAide` **créé** au statut du front matter (défaut `brouillon`) ;
  - fichier **modifié** (hash de contenu différent du dernier import) → **nouvelle `VersionArticle`** ;
    si l'article était **publié**, il **repasse en `brouillon`** sauf si le front matter porte
    explicitement `statut: publie` (republication assumée sans revue) — ⚠ HYPOTHÈSE, seuil de
    « modification substantielle » non défini, à confirmer avec le produit ;
  - fichier **inchangé** → **aucune opération** (idempotence : ré-exécuter l'import n'importe quand ne
    duplique rien et ne régénère pas de version inutile).
- Chaque exécution est **journalisée** (`JournalImportAide` : fichier, clé, résultat
  créé/maj/inchangé/erreur, horodatage) — un fichier en erreur (front matter invalide) est **rejeté
  isolément**, sans bloquer l'import des autres fichiers.
- Ce mécanisme est **rejouable à chaque livraison de module** (à chaque US/RG close, cf. constitution §8
  Definition of Done point 5 « spec/plan mis à jour si l'implémentation a divergé », généralisé ici à la
  doc vivante) : c'est par lui que « ce qui a été fait » **alimente et tient à jour** la base de
  connaissance, sans double-saisie côté back-office, et que la partie documentaire est **reprise en
  production** dès la mise en service (la KB n'est jamais vide au lancement).

### 4.9 Ouverture d'un ticket de support (US-SUP-09)
- **RG-SUP-09** — Un **TicketSupport** est ouvert par un **Utilisateur exploitant authentifié** (staff,
  socle L0) avec : sujet, description, **priorité** déclarative, **module concerné** (référence au même
  référentiel ouvert que §4.5), pièces jointes optionnelles. Il est **rattaché** à l'établissement actif
  du demandeur et au demandeur lui-même.
- **Aucun canal anonyme** — un usager sans compte ne peut pas ouvrir de ticket (§2.2, point ouvert 1) ;
  seule la KB lui est ouverte.

### 4.10 Priorité (US-SUP-09)
- **RG-SUP-10** — La **priorité** (`basse`, `normale`, `haute`, `critique`) est **déclarative**, choisie
  par le demandeur à l'ouverture, modifiable par un agent support ; elle **influence le tri** du tableau
  de bord (§4.14) mais **ne déclenche aucun minuteur ni escalade automatique** (pas de SLA contractuel,
  §2.2).

### 4.11 Cycle de vie du ticket (US-SUP-10)
- **RG-SUP-11** — Un `TicketSupport` suit le cycle : `nouveau` → `en_cours` (pris en charge) →
  (optionnel) `en_attente_client` (relance envoyée à l'agent, attente d'une réponse du demandeur) →
  `resolu` → `ferme`. Chaque changement de statut est **horodaté** et **tracé** (auteur, §4.15).
- **Réouverture** — ⚠ HYPOTHÈSE (délai non tranché par les sources) : un ticket `ferme` peut être
  **rouvert** par le demandeur ou un agent support dans un délai à définir (ex. 15 jours) ; au-delà,
  seule l'**ouverture d'un nouveau ticket** référençant l'ancien est proposée — comportement retenu par
  cohérence avec des pratiques de support léger usuelles, **à confirmer**.

### 4.12 Échanges & pièces jointes (US-SUP-11)
- **RG-SUP-12** — Un `TicketSupport` porte un **fil de `MessageTicket`** (demandeur ↔ agent), chacun
  pouvant porter des pièces jointes. Un message peut être marqué **note interne** (`noteInterne = true`) :
  visible **uniquement** des agents support/admin, jamais du demandeur — pour une communication d'équipe
  sur le ticket sans exposer d'information sensible/interne au client.
- Tout nouveau `MessageTicket` **non interne** notifie le destinataire concerné (agent affecté si le
  demandeur répond ; demandeur si l'agent répond) — ⚠ HYPOTHÈSE : canal de notification (e-mail,
  in-app) non tranché par les sources.

### 4.13 Affectation & escalade N1/N2 (US-SUP-12)
- **RG-SUP-13** — Un `TicketSupport` porte un **niveau d'affectation** (`N1` par défaut dès prise en
  charge, `N2` après escalade) et, optionnellement, un **agent affecté** (`affecteARef`). Un agent N1
  peut **escalader** un ticket vers N2 (change le niveau et, le cas échéant, l'agent affecté) ; un agent
  N2 peut **réaffecter** entre agents. L'escalade est **tracée** (auteur, motif optionnel, horodatage).
- Un ticket **sans agent affecté** reste au statut `nouveau`, visible de **tous** les agents N1 dans le
  tableau de bord (§4.14), jusqu'à prise en charge par l'un d'eux.

### 4.14 Lien vers un article de KB (US-SUP-13)
- **RG-SUP-14** — Un agent support peut **lier** un `TicketSupport` à un ou plusieurs `ArticleAide`
  existants (référence de résolution, ex. « voir l'article Encaisser une vente ») ; ce lien est visible
  du demandeur sur son ticket. Ce lien **ne remplace pas** un message de réponse ; il le **complète**.
- ⚠ HYPOTHÈSE — Une **suggestion automatique** d'articles pertinents (recherche par mots-clés du sujet/
  description du ticket) est un comportement **souhaitable** mais **non spécifié en détail** ici
  (algorithme de pertinence hors périmètre fonctionnel de cette spec) ; retenue comme amélioration
  possible, pas un critère d'acceptation v1.

### 4.15 Tableau de bord agent & audit (US-SUP-14)
- Le **tableau de bord** des tickets (agent support/admin) est une **vue de restitution** — pas un objet
  de données propre, comme le roster de `spec-personnel.md` §4.5 — filtrable par statut, priorité,
  module concerné, établissement, niveau d'affectation (`N1`/`N2`), et agent affecté.
- **RG-SUP-15 (audit)** — Toute action sensible (publication/archivage d'un article, changement de statut
  d'un ticket, escalade/réaffectation, purge d'un article) crée une **EntreeAudit** (réutilise
  `RG-SOCLE-07`, append-only, non modifiable via l'API).

## 5. Objets de données
Tout objet est rattaché à un **Établissement** via le socle (`RG-SOCLE-01`) quand sa portée est locale ;
identifiants = **UUID** (constitution §3). Les objets `Utilisateur`, `Etablissement`, `EntreeAudit`
(socle) sont **référencés, non redéfinis** — voir `spec-socle.md` §5.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **CategorieAide** | id | uuid | PK | RG-SUP-01 |
| | nom, slug | string, string | requis, slug unique | — |
| | parentRef | ref CategorieAide? | optionnel | arbre |
| | ordre | int | tri manuel | — |
| **ArticleAide** | id | uuid | PK | RG-SUP-02 |
| | titre, slug | string, string | requis, slug unique | — |
| | categorieRef | ref CategorieAide | requis | RG-SUP-01 |
| | resume | string? | optionnel | aperçu recherche |
| | contenu | texte (Markdown/riche) | requis | version de travail courante |
| | motsCles[] | string[] | optionnel | recherche §4.7 |
| | statut | enum {brouillon, publie, archive} | défaut = brouillon | RG-SUP-02 |
| | versionPublieeRef | ref VersionArticle? | requis si statut=publie | RG-SUP-03 |
| | portee | enum {global, local} | requis | RG-SUP-04 |
| | etablissementRef | ref Etablissement (socle)? | requis si portee=local | RG-SUP-04 |
| | publicCible | enum {agent, usager, tous} | requis | RG-SUP-04 |
| | moduleLie | string (référentiel ouvert)? | optionnel | RG-SUP-05 |
| | pieceJointes[] | ref PieceJointeAide[] | optionnel | §4.6 |
| | auteurRef | ref Utilisateur (socle) | requis | — |
| | origine | enum {manuel, import} | défaut = manuel | RG-SUP-08 |
| | cleImport | string? | requis et unique si origine=import | RG-SUP-08 |
| | dateCreation, dateDerniereModification | datetime | requis | — |
| **VersionArticle** | id, articleRef | uuid, ref ArticleAide | PK | RG-SUP-03 |
| | numero | int | incrémental par article | — |
| | contenu | texte (snapshot) | requis | append-only |
| | statutAuMoment | enum {brouillon, publie, archive} | requis | — |
| | auteurRef | ref Utilisateur | requis | — |
| | origine | enum {manuel, import} | requis | RG-SUP-08 |
| | dateCreation | datetime | requis | — |
| **PieceJointeAide** | id, articleRef | uuid, ref | PK | §4.6 |
| | nomFichier, typeMime, taille, url | string, string, int, string | requis | — |
| **JournalImportAide** | id | uuid | PK | RG-SUP-08 |
| | cheminFichier, cleImport | string, string | requis | — |
| | hashContenu | string | requis | détection de changement |
| | resultat | enum {cree, maj, inchange, erreur} | requis | — |
| | articleRef | ref ArticleAide? | vide si erreur | — |
| | dateImport | datetime | requis | — |
| **TicketSupport** | id | uuid | PK | RG-SUP-09 |
| | sujet, description | string, texte | requis | — |
| | priorite | enum {basse, normale, haute, critique} | défaut = normale | RG-SUP-10 |
| | statut | enum {nouveau, en_cours, en_attente_client, resolu, ferme} | défaut = nouveau | RG-SUP-11 |
| | moduleConcerne | string (référentiel ouvert)? | optionnel | RG-SUP-05 (réutilisé) |
| | etablissementRef | ref Etablissement | requis | établissement actif du demandeur |
| | demandeurRef | ref Utilisateur | requis | RG-SUP-09 |
| | affecteARef | ref Utilisateur (agent)? | optionnel | RG-SUP-13 |
| | niveauAffectation | enum {N1, N2}? | requis dès prise en charge | RG-SUP-13 |
| | articleAideLieRefs[] | ref ArticleAide[] | optionnel | RG-SUP-14 |
| | dateCreation, dateDerniereMaj | datetime | requis | — |
| | dateResolution, dateFermeture | datetime? | requis si statut atteint | — |
| | motifFermeture | string? | optionnel | — |
| **MessageTicket** | id, ticketRef | uuid, ref TicketSupport | PK | RG-SUP-12 |
| | auteurRef | ref Utilisateur | requis | demandeur ou agent |
| | auteurType | enum {demandeur, agent} | requis | — |
| | contenu | texte | requis | — |
| | noteInterne | bool | défaut = false | RG-SUP-12 |
| | pieceJointes[] | ref PieceJointeTicket[] | optionnel | — |
| | dateCreation | datetime | requis | — |
| **PieceJointeTicket** | id, messageRef | uuid, ref MessageTicket | PK | §4.12 |
| | nomFichier, typeMime, taille, url | string, string, int, string | requis | — |

## 6. États & cycle de vie
```
ArticleAide :  brouillon → publié → archivé
               brouillon ↔ publié (republication après modification, RG-SUP-08 pour l'import)
```
```
TicketSupport : nouveau → en_cours → [en_attente_client] → résolu → fermé
                fermé → nouveau/en_cours (réouverture encadrée, ⚠ délai non tranché §4.11)
```
```
Niveau d'affectation : N1 → N2 (escalade, pas de retour automatique N2 → N1)
```

## 7. Critères d'acceptation
- **CA-1 (US-SUP-01/07, RG-SUP-04/06)** — *Étant donné* un lecteur usager sans compte, *quand* il
  recherche « encaisser une vente », *alors* seuls des articles **publiés**, ciblés `usager`/`tous`,
  **globaux** (ou locaux à l'établissement du contexte consulté) apparaissent — jamais un brouillon ni
  un article ciblé `agent`.
- **CA-2 (US-SUP-02, RG-SUP-02)** — *Quand* un rédacteur crée un article et l'enregistre **sans le
  publier**, *alors* il reste **invisible** en recherche/navigation pour tout lecteur usager/agent ;
  *quand* il clique « Publier », *alors* il devient visible selon son ciblage.
- **CA-3 (US-SUP-04, RG-SUP-04)** — *Étant donné* un article **local** créé par le rédacteur de
  l'établissement A, *quand* un lecteur de l'établissement B recherche le même sujet, *alors* cet
  article **n'apparaît pas** dans ses résultats.
- **CA-4 (US-SUP-05)** — *Étant donné* un article rattaché au module `vente`, *quand* on filtre la KB par
  `moduleLie = vente`, *alors* cet article apparaît dans les résultats filtrés.
- **CA-5 (US-SUP-06, RG-SUP-03)** — *Quand* un rédacteur modifie un article déjà **publié**, *alors* une
  **nouvelle VersionArticle** est créée et l'**historique** reste consultable, sans perte des versions
  précédentes.
- **CA-6 (US-SUP-07, RG-SUP-06)** — *Étant donné* une recherche **sans aucun résultat**, *alors* un état
  vide explicite s'affiche, proposant à un exploitant authentifié d'**ouvrir un ticket**.
- **CA-7 (US-SUP-08, RG-SUP-07/08)** — *Quand* l'import de la doc vivante s'exécute sur un fichier
  **nouveau**, *alors* un `ArticleAide` est **créé** (statut du front matter, défaut `brouillon`) ;
  *quand* il est **ré-exécuté sans changement**, *alors* **aucune** nouvelle version ni doublon n'est
  créé (idempotence) ; *quand* le contenu d'un fichier **change**, *alors* une **nouvelle
  VersionArticle** est créée et un article auparavant **publié repasse en `brouillon`**, sauf mention
  explicite `statut: publie` dans le fichier.
- **CA-8 (US-SUP-09, RG-SUP-09)** — *Étant donné* un exploitant authentifié, *quand* il ouvre un ticket
  avec sujet/description/priorité/module concerné, *alors* le ticket est créé au statut `nouveau`,
  rattaché à **son établissement** et à **lui-même** comme demandeur.
- **CA-9 (US-SUP-10, RG-SUP-11)** — *Quand* un agent N1 prend en charge un ticket `nouveau`, *alors* son
  statut passe à `en_cours` ; *quand* il le marque résolu, *alors* le statut passe à `resolu` et
  `dateResolution` est horodatée ; *quand* le ticket est ensuite fermé, *alors* `dateFermeture` est
  horodatée.
- **CA-10 (US-SUP-12, RG-SUP-13)** — *Quand* un agent N1 escalade un ticket, *alors* `niveauAffectation`
  passe à `N2`, l'agent affecté change en conséquence, et l'action est **tracée** (audit).
- **CA-11 (US-SUP-11, RG-SUP-12)** — *Étant donné* un ticket en cours, *quand* un agent poste un
  **message marqué note interne**, *alors* ce message n'apparaît **jamais** dans le fil visible du
  demandeur, seulement dans celui des agents.
- **CA-12 (US-SUP-13, RG-SUP-14)** — *Quand* un agent lie un article de KB à un ticket, *alors* cet
  article apparaît sur le ticket, **visible du demandeur**, en complément (non substitut) d'un message
  de réponse.
- **CA-13 (US-SUP-14)** — *Étant donné* le tableau de bord agent, *quand* on filtre par statut `nouveau`
  et niveau `N1`, *alors* seuls les tickets correspondants s'affichent, y compris ceux **sans agent
  affecté**.
- **CA-14 (RG-SUP-15)** — *Quand* un article est publié, un ticket change de statut, ou un ticket est
  escaladé/réaffecté, *alors* une **EntreeAudit** est créée (auteur, horodatage, action, cible) ; *quand*
  on tente de la modifier via l'API, *alors* l'opération n'existe pas (cohérent `RG-SOCLE-07`).

## 8. Cas limites
- **Article local vs global sur le même sujet** — Coexistence **sans fusion automatique** ; l'article
  local est **mis en avant** (tri) pour l'établissement concerné — ⚠ HYPOTHÈSE non tranchée par les
  sources (§4.4).
- **Ticket sans compte (usager anonyme)** — **Non supporté** : seule la KB publique lui est ouverte ; le
  canal ticket est réservé aux exploitants authentifiés (§2.2, §4.9) — point ouvert 1 (le commanditaire
  n'exclut pas explicitement un futur canal usager, mais ne le demande pas non plus).
- **Article obsolète (statut archivé)** — Retiré de la **recherche et de la navigation par catégorie**,
  mais reste **accessible par lien direct** avec un **bandeau « archivé »** — ⚠ HYPOTHÈSE : comportement
  retenu par défaut, non détaillé par le commanditaire.
- **Recherche sans résultat** — État vide explicite + suggestion d'ouverture de ticket (§4.7, CA-6) ; pour
  un lecteur usager anonyme (sans permission `support × ouvrir_ticket`), **aucune suggestion de ticket**
  n'est proposée — ⚠ HYPOTHÈSE, canal de contact alternatif non défini pour ce cas.
- **Fichier de doc vivante avec front matter invalide** — Import de **ce fichier uniquement rejeté**
  (résultat `erreur` journalisé), les autres fichiers de l'import **ne sont pas affectés** (traitement
  atomique par fichier, §4.8).
- **Suppression d'une CategorieAide contenant des articles** — **Refusée** (intégrité, cohérent
  `RG-SOCLE-01`).
- **Établissement désactivé alors qu'il porte des articles locaux** — ⚠ HYPOTHÈSE : les articles locaux
  associés devraient être **archivés automatiquement** (cohérence avec le cloisonnement `RG-SOCLE-05`),
  comportement **non tranché** par les sources, à confirmer.
- **Réouverture d'un ticket fermé au-delà d'un délai** — ⚠ HYPOTHÈSE (délai exact non tranché, §4.11) :
  au-delà, seule l'ouverture d'un **nouveau ticket référençant l'ancien** est proposée.
- **Demandeur ayant perdu son affectation à l'établissement** (ex. fin de contrat, cf.
  `spec-personnel.md` §4.8) **et propriétaire de tickets en cours** — ⚠ HYPOTHÈSE : les tickets restent
  visibles et traitables par les agents support, mais le demandeur (ayant perdu son accès logiciel,
  `RG-SOCLE-05`) ne peut plus s'y connecter pour y répondre ; comportement de repli non détaillé.
- **Modification concurrente du même article par deux rédacteurs** — ⚠ HYPOTHÈSE : aucun verrouillage
  collaboratif spécifié ; retenu par défaut « dernier enregistrement gagne » (écrasement), sans détection
  de conflit temps réel — hors périmètre v1.

## 9. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) pour la portée locale des articles/tickets ; **Utilisateur** et permissions
  `module × action` sur le module **`support`** (`RG-SOCLE-02/03/04`) ; **établissement actif**
  (`RG-SOCLE-05`) pour le cloisonnement des articles/tickets locaux ; **journal d'audit append-only**
  (`RG-SOCLE-07`) pour toute action sensible (§4.15).
- **Référencé par (aide contextuelle), sans dépendance dure d'aucun module vers celui-ci :** tous les
  modules du dépôt peuvent porter un `moduleLie`/`moduleConcerne` (§4.5, §4.9) — référentiel **ouvert et
  paramétrable**, aucune énumération technique fermée à maintenir à chaque nouveau module livré.
- **Point d'intégration potentiel (non détaillé ici) :** exposition de la KB **publique** (`usager`)
  depuis la vitrine white-label / l'app client (`specs/L8-boutique/spec-boutique.md`) — cf. §4.4, point
  ouvert ; aucune dépendance technique n'est prise ici, l'intégration UI reste à cadrer au plan
  technique.
- **Constitution** — surface **KB publique** potentiellement soumise à **RGAA/WCAG 2.2 AA** si exposée
  aux usagers (constitution §4 point 5) ; hébergement France / RGPD pour les pièces jointes et contenus
  de tickets (constitution §1).

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ NUMÉROTATION `US-SUP` HORS BACKLOG** : ces stories n'existent pas dans `backlog.html` et doivent
   être **validées et intégrées officiellement** avant développement (en-tête, §1).
2. **⚠ POINT OUVERT — Tickets usager (client final) vs exploitant uniquement** : ce document retient que
   seul un **exploitant authentifié** (staff) ouvre des tickets, la KB seule étant ouverte à l'usager
   grand public ; le commanditaire ne l'exclut pas explicitement pour l'avenir mais ne le demande pas non
   plus — **à trancher** avant tout plan technique impliquant un canal usager (§2.2, §4.9, §8).
3. **⚠ HYPOTHÈSE — Convention de fichiers de la « doc vivante »** (`docs/aide/<module>/<slug>.md`, champs
   de front matter, nom de la commande d'import) : **non actée**, proposée par cet agent comme mécanisme
   observable et testable — **à valider avec le commanditaire/l'équipe technique** avant implémentation
   (§4.8).
4. **⚠ HYPOTHÈSE — Règle de republication après import** : un article publié dont le fichier source
   change **repasse en brouillon** sauf mention explicite `statut: publie` — seuil de « modification
   substantielle » non défini (§4.8).
5. **⚠ HYPOTHÈSE — Priorité d'affichage article local vs global** sur un même sujet : local mis en avant,
   non tranché par les sources (§4.4, §8).
6. **⚠ HYPOTHÈSE — Délai de réouverture d'un ticket fermé** : non chiffré (§4.11, §8).
7. **⚠ HYPOTHÈSE — Canal de notification** (e-mail/in-app) sur nouveau message ticket : non tranché
   (§4.12).
8. **⚠ HYPOTHÈSE — Suggestion automatique d'articles KB pertinents sur un ticket** : souhaitable, non
   spécifiée en détail, pas un critère v1 (§4.14).
9. **⚠ HYPOTHÈSE — Multilinguisme de la KB** : non demandé pour ce module, à la différence de la vitrine
   boutique L8 (§2.2).
10. **⚠ HYPOTHÈSE — Noms des permissions `support × …`** : dérivées par analogie avec les autres modules
    de ce dépôt, à **arbitrer avec M8** (§3).
11. **⚠ HYPOTHÈSE — Archivage automatique des articles locaux d'un établissement désactivé** : non
    tranché (§8).
