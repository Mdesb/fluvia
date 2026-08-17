# Plan technique — Base de connaissance & Support (`App\Support`, module `support`)

- **Spec source :** specs/support/spec-support.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-SUP-01 à 14 · RG-SUP-01 à 15 · CA-1 à CA-14 (⚠ stories/règles **non numérotées
  officiellement** dans le backlog, cf. préambule spec-support.md — même réserve que les autres
  modules hors backlog de ce dépôt).

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Etablissement}`
> (RG-SOCLE-01) pour la portée locale des articles/tickets ; `PermissionVoter` (attribut `PERM`, sujet
> `"module.action"`, `app/src/Securite/Security/PermissionVoter.php`, RG-SOCLE-04) sur le module
> `support` ; `ContexteEtablissement`/en-tête `X-Etablissement` (RG-SOCLE-05) pour l'établissement actif
> d'un rédacteur local/demandeur de ticket ; `Utilisateur` (socle) comme auteur/demandeur/agent ;
> `App\Audit\Doctrine\AuditWriteSubscriber` append-only (RG-SOCLE-07) — **étendu** avec les entités
> sensibles Support (RG-SUP-15). Patron de cloisonnement Doctrine copié de
> `App\Personnel\Doctrine\PerimetrePersonnelExtension`/`App\Musee\Doctrine\PerimetreMuseeExtension`
> (mêmes principes : jointure sur `Affectation`, visibilité conditionnelle pour les objets non
> strictement mono-établissement). Style Entité/API Platform/UUID/Groups copié de
> `App\Personnel\Entity\Employe`. Pattern « utilisateur système » pour l'auteur des imports copié de
> `App\Reservation\Service\SessionSystemeResolver`/`App\Boutique\Service\SessionSystemeBoutiqueResolver`.
> **Aucun fichier du socle n'est modifié**, à l'exception strictement additive de
> `AuditWriteSubscriber::CLASSES_SURVEILLEES` (même pratique que tous les lots précédents).

---

## 0. Décisions structurantes (résumé)

1. **Nom de la commande d'import retenu : `support:importer-aide`** (tranche le point ouvert n°3 de la
   spec — l'hypothèse `support:kb:seed` n'est **pas** retenue, au profit d'un nom en français cohérent
   avec la convention `<module>:<verbe>-<complement>` déjà utilisée par `securite:delegations:expirer`,
   `boutique:liberer-paniers-expires`).
2. **`ArticleAide.cleImport` (unique si `origine=import`) est la clé canonique de dédoublonnage**, pas
   `JournalImportAide` (simple journal en lecture seule, append-only). Un champ technique additionnel
   `ArticleAide.hashImportCourant` (non présent dans le tableau §5 de la spec, ajout de ce plan) mémorise
   le dernier hash importé pour permettre un test d'idempotence en une seule requête indexée plutôt
   qu'un scan de l'historique du journal — décision purement technique, aucun impact fonctionnel.
3. **Recherche plein-texte : index MariaDB `FULLTEXT` sur une colonne dénormalisée `rechercheTexte`**
   (titre + résumé + contenu + mots-clés concaténés), maintenue à l'écriture — pas de moteur externe
   (Elasticsearch/Meilisearch), cohérent avec la volumétrie attendue d'une base de connaissance interne
   (quelques centaines à quelques milliers d'articles). Le filtrage par droits/ciblage/portée reste
   **toujours appliqué en amont** de la recherche (jamais un filtre optionnel).
4. **Le « rédacteur KB » global/local, la distinction Article local vs global, et la mise en avant de
   l'article local** (spec §4.4, ⚠ hypothèse) sont repris **tels quels** — implémentés comme un simple
   critère de tri secondaire (portee=local en tête) dans le Provider de recherche/navigation, pas une
   règle de fusion.
5. **`moduleLie`/`moduleConcerne` restent des chaînes libres (référentiel ouvert, RG-SUP-05)** — aucune
   table de référence ni contrainte FK, conformément à la constitution §4 point 4 (pas de logique figée
   par module). Un filtre `SearchFilter` (`partial`/`exact`) suffit ; aucune migration n'est nécessaire à
   l'arrivée d'un nouveau module.

---

## 1. Entités & schéma

Namespace : **`App\Support\Entity\*`** (+ `App\Support\Enum\*`, `App\Support\Command\*`,
`App\Support\Service\*`, `App\Support\Security\*`, `App\Support\Doctrine\*`, `App\Support\State\*`).
`id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`). `declare(strict_types=1)` partout.
Noms métier en français. Table prefix `support_*` (patron `App\Musee\Entity\*`/`musee_*`,
`App\Personnel\Entity\*`/`personnel_*`).

### 1.1 Catégories (US-SUP-03, RG-SUP-01)

| Entité (`App\Support\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **CategorieAide** (`support_categorie_aide`) | id | uuid | non | PK | — |
| | nom | string(150) | non | `Assert\NotBlank` | — |
| | slug | string(160) | non | **unique** | dérivé du nom, immuable après création |
| | parent | uuid | oui | index | `ManyToOne` self-référence, arbre (fil d'Ariane calculé en lecture) |
| | ordre | smallint | non, défaut 0 | — | tri manuel dans la catégorie parente |
| | dateCreation | datetime_immutable | non | — | — |

- Suppression **bloquée** si la catégorie contient au moins un `ArticleAide` **ou** une sous-catégorie
  (RG-SUP-01, cas limite §8) — vérifié par un `Voter`/garde applicative dans le Processor de suppression
  (`CategorieSuppressionProcessor`), retourne 409 Conflict si non vide.
- Catégories **globales uniquement en v1** (⚠ hypothèse spec §4.1 non tranchée) — aucun champ
  établissement sur `CategorieAide`.

### 1.2 Article d'aide, versionnage, pièces jointes (US-SUP-02/04/05/06, RG-SUP-02/03/04/05)

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **ArticleAide** (`support_article_aide`) | id | uuid | non | PK | — |
| | titre | string(200) | non | `Assert\NotBlank` | — |
| | slug | string(220) | non | **unique** | dérivé du titre à la création |
| | categorie | uuid | non | index, FK | `ManyToOne` → `CategorieAide` |
| | resume | string(500) | oui | — | aperçu recherche |
| | contenu | text | non | — | version de travail courante (Markdown) |
| | motsCles | json (`list<string>`) | oui | — | — |
| | rechercheTexte | text | non, généré à l'écriture | **`FULLTEXT INDEX support_ft_article_recherche`** | concat(titre, resume, contenu, motsCles) — §3 |
| | statut | string(10) `enumType: StatutArticle` | non, défaut `brouillon` | index | RG-SUP-02 |
| | versionPubliee | uuid | oui | FK | `ManyToOne` → `VersionArticle` (requis si `statut=publie`, contrôle applicatif) |
| | portee | string(6) `enumType: PorteeArticle` | non, défaut `global` | index | RG-SUP-04 |
| | etablissement | uuid | oui | index, FK | `ManyToOne` → `Etablissement` (socle) — requis si `portee=local`, interdit sinon (contrainte applicative + `Assert\Callback`) |
| | publicCible | string(6) `enumType: PublicCible` | non | index | RG-SUP-04 |
| | moduleLie | string(60) | oui | index | référentiel ouvert, RG-SUP-05 |
| | auteur | uuid | non | FK | `ManyToOne` → `Utilisateur` (socle) |
| | origine | string(10) `enumType: OrigineArticle` | non, défaut `manuel` | — | RG-SUP-08 |
| | cleImport | string(255) | oui | **unique partiel** (WHERE `origine='import'`) | RG-SUP-08, clé stable d'upsert |
| | hashImportCourant | string(64) | oui | — | sha256 du dernier fichier importé (§0 décision n°2, technique) |
| | dateCreation, dateDerniereModification | datetime_immutable, datetime_immutable | non/non | — | — |
| **VersionArticle** (`support_version_article`) | id, article | uuid, uuid | non/non | PK / FK index | `ManyToOne` → `ArticleAide` |
| | numero | smallint | non | **unique** (article, numero) | incrémental par article |
| | contenu | text (snapshot) | non | — | append-only, jamais modifié/supprimé |
| | statutAuMoment | string(10) `enumType: StatutArticle` | non | — | — |
| | auteur | uuid | non | FK | `ManyToOne` → `Utilisateur` |
| | origine | string(10) `enumType: OrigineArticle` | non | — | RG-SUP-08 |
| | dateCreation | datetime_immutable | non | — | — |
| **PieceJointeAide** (`support_piece_jointe_aide`) | id, article | uuid, uuid | non/non | PK / FK index | `ManyToOne` → `ArticleAide` |
| | version | uuid | oui | FK | `ManyToOne` → `VersionArticle` (optionnel, capture visuelle historisée §4.6 spec) |
| | nomFichier, typeMime | string(255), string(100) | non/non | — | — |
| | taille | int | non | `Assert\Positive`, plafond applicatif (⚠ non chiffré par les sources) | octets |
| | url | string(500) | non | — | stockage objet (hors périmètre technique de ce plan) |
| | dateCreation | datetime_immutable | non | — | — |

- **Versionnage (RG-SUP-03)** — toute création/modification d'un `ArticleAide` (manuelle ou import) passe
  par un service unique `ArticleAideEcritureService::enregistrer()` qui (a) persiste les champs modifiés,
  (b) crée systématiquement une nouvelle `VersionArticle` (numéro = max+1), (c) ne supprime jamais de
  version existante. La **publication** (`ArticlePublierProcessor`) est une action **distincte** :
  fige `versionPubliee = version courante`, passe `statut = publie`.
- **Rattachement multi-entités** : `Etablissement` uniquement si `portee=local` — sinon l'article est
  visible de tous les établissements remplissant la condition de ciblage (RG-SUP-04).

### 1.3 Import doc vivante (US-SUP-08, RG-SUP-07/08)

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **JournalImportAide** (`support_journal_import_aide`) | id | uuid | non | PK | — |
| | cheminFichier | string(500) | non | index | `docs/aide/<module>/<slug>.md` |
| | cleImport | string(255) | non | index | — |
| | hashContenu | string(64) | non | — | sha256 (front matter + corps normalisés) |
| | resultat | string(10) `enumType: ResultatImport` | non | index | `cree`/`maj`/`inchange`/`erreur` |
| | messageErreur | text | oui | — | détail si `resultat=erreur` (ajout technique, hors tableau §5 spec, nécessaire à l'observabilité) |
| | article | uuid | oui | FK | `ManyToOne` → `ArticleAide` (vide si erreur) |
| | dateImport | datetime_immutable | non | index | horodatage de l'exécution |

- **Append-only** (jamais modifié/supprimé via l'API — cf. §2), une ligne par fichier par exécution.

### 1.4 Tickets de support (US-SUP-09 à 14, RG-SUP-09 à 14)

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **TicketSupport** (`support_ticket_support`) | id | uuid | non | PK | — |
| | sujet | string(200) | non | `Assert\NotBlank` | — |
| | description | text | non | `Assert\NotBlank` | — |
| | priorite | string(8) `enumType: PrioriteTicket` | non, défaut `normale` | index | RG-SUP-10 |
| | statut | string(20) `enumType: StatutTicket` | non, défaut `nouveau` | index | RG-SUP-11 |
| | moduleConcerne | string(60) | oui | index | référentiel ouvert |
| | etablissement | uuid | non | index, FK | `ManyToOne` → `Etablissement` (socle) — établissement actif du demandeur à l'ouverture |
| | demandeur | uuid | non | index, FK | `ManyToOne` → `Utilisateur` |
| | affecteA | uuid | oui | index, FK | `ManyToOne` → `Utilisateur` (agent) |
| | niveauAffectation | string(2) `enumType: NiveauAffectation` | oui | index | `N1`/`N2`, requis dès prise en charge |
| | dateCreation, dateDerniereMaj | datetime_immutable, datetime_immutable | non/non | — | — |
| | dateResolution, dateFermeture | datetime_immutable, datetime_immutable | oui/oui | — | requis si statut atteint |
| | motifFermeture | string(255) | oui | — | — |
| **TicketArticleLie** (`support_ticket_article_lie`, table de jointure) | ticket, article | uuid, uuid | non/non | PK composite | `ManyToMany` `TicketSupport` ↔ `ArticleAide`, RG-SUP-14 |
| **MessageTicket** (`support_message_ticket`) | id, ticket | uuid, uuid | non/non | PK / FK index | `ManyToOne` → `TicketSupport` |
| | auteur | uuid | non | FK | `ManyToOne` → `Utilisateur` |
| | auteurType | string(10) `enumType: AuteurTypeMessage` | non | — | `demandeur`/`agent` |
| | contenu | text | non | `Assert\NotBlank` | — |
| | noteInterne | bool | non, défaut false | index | RG-SUP-12 — jamais `true` si `auteurType=demandeur` (contrainte applicative) |
| | dateCreation | datetime_immutable | non | index | — |
| **PieceJointeTicket** (`support_piece_jointe_ticket`) | id, message | uuid, uuid | non/non | PK / FK index | `ManyToOne` → `MessageTicket` |
| | nomFichier, typeMime, taille, url | string(255), string(100), int, string(500) | non | — | idem `PieceJointeAide` |
| | dateCreation | datetime_immutable | non | — | — |

**Enums (`App\Support\Enum\*`)** : `StatutArticle` {Brouillon, Publie, Archive}, `PorteeArticle` {Global,
Local}, `PublicCible` {Agent, Usager, Tous}, `OrigineArticle` {Manuel, Import}, `ResultatImport` {Cree,
Maj, Inchange, Erreur}, `PrioriteTicket` {Basse, Normale, Haute, Critique}, `StatutTicket` {Nouveau,
EnCours, EnAttenteClient, Resolu, Ferme}, `NiveauAffectation` {N1, N2}, `AuteurTypeMessage` {Demandeur,
Agent}.

**Objets référencés, non redéfinis** (constitution §4) : `App\Organisation\Entity\Etablissement`,
`App\Securite\Entity\{Utilisateur, Permission, Affectation}`, `App\Audit\Entity\EntreeAudit` (socle).

---

## 2. Recherche plein-texte & filtres (US-SUP-01/07, RG-SUP-06)

- **Approche retenue** — index MariaDB `FULLTEXT` (mode *natural language*) sur `ArticleAide.
  rechercheTexte`, colonne dénormalisée maintenue par `ArticleAideEcritureService` à chaque
  création/modification (concaténation `titre . ' ' . resume . ' ' . contenu . ' ' . implode(' ',
  motsCles)`). Doctrine ORM n'a pas d'attribut natif `FULLTEXT` : l'index est créé par **SQL brut** dans
  la migration structurelle (`ALTER TABLE support_article_aide ADD FULLTEXT INDEX
  support_ft_article_recherche (recherche_texte)`), documenté §4.
- **Provider dédié** `RechercheArticleAideProvider` (`GET /support/articles/recherche?q=...`) —
  construit `WHERE MATCH(recherche_texte) AGAINST (:q IN NATURAL LANGUAGE MODE)` **après** application
  des règles de visibilité (jamais brouillon/archivé, jamais `agent` pour un lecteur usager, jamais
  local hors établissement — §5) ; tri : pertinence MariaDB puis **article local en tête** (§0 décision
  n°4) puis date de publication.
- **Filtres API Platform** (`ApiFilter(SearchFilter::class)`) sur la collection publique et la recherche :
  `categorie` (exact, id), `publicCible` (exact), `moduleLie` (partial), `portee` (exact),
  `etablissement` (exact, contextuel — §5). Filtre `statut` **jamais exposé** côté public (forcé à
  `publie` par le Provider, pas par le client).
- **Volumétrie** — si le volume dépasse l'ordre de grandeur d'une KB interne (dizaine de milliers
  d'articles multi-établissements), migrer vers un moteur dédié (Meilisearch/Elasticsearch) est possible
  **sans changer le contrat d'API** (le Provider est le seul point de couplage) — non nécessaire en v1.
- **Aucun résultat** — le Provider retourne une collection vide ; c'est à l'UI de proposer l'ouverture
  d'un ticket (CA-6), condition à `support.ouvrir_ticket` accordée (agent/exploitant authentifié
  uniquement, jamais un lecteur anonyme, cas limite §8 spec).

---

## 3. API (API Platform)

`security:` via `is_granted('PERM', 'support.<action>')` (module `support`) pour les opérations
authentifiées ; `PUBLIC_ACCESS` + Provider filtrant pour la KB publique (aucune notion de compte usager,
à la différence du module Boutique). Cadrage établissement : `ContexteEtablissement`/
`App\Support\Doctrine\PerimetreSupportExtension` côté back-office (rédaction locale, tickets) ; côté KB
publique, l'établissement est un **paramètre de requête optionnel non fiable** (visiteur anonyme, §7
Risque n°6), jamais une donnée de sécurité sensible (n'élargit que la visibilité d'articles déjà publics).

| Ressource | Opérations | `security:` | Groupes | Filtres |
|---|---|---|---|---|
| **CategorieAide** | `GetCollection`/`Get` (arbre + fil d'Ariane) | `PUBLIC_ACCESS` | `categorie:read` | — |
| | `Post`/`Patch` | `support.gerer_categorie` | `categorie:write` | — |
| | `POST /support/categories/{id}/supprimer` (custom, contrôle « non vide ») | `support.gerer_categorie` | — | — |
| **ArticleAide** | `GetCollection` (back-office, tous statuts scopés) | `support.lire` ou `support.gerer_kb_globale` ou `support.gerer_kb_locale` | `article:read` | `statut`, `categorie`, `publicCible`, `portee`, `moduleLie` |
| | `GET /support/articles/publics` (custom Provider, KB publique) | `PUBLIC_ACCESS` | `article_public:read` | `categorie`, `publicCible`, `moduleLie`, `etablissement` |
| | `GET /support/articles/recherche` (custom Provider, §2) | `PUBLIC_ACCESS` | `article_public:read` | `q`, `categorie`, `publicCible`, `moduleLie`, `etablissement` |
| | `Get` item | `PUBLIC_ACCESS` + `is_granted('ARTICLE_LIRE', object)` (voter, §5) | `article:read` | — |
| | `Post` (portee=global) | `support.gerer_kb_globale` | `article:write` | — |
| | `Post` (portee=local) | `support.gerer_kb_locale` (établissement = actif, contrôlé par le Processor) | `article:write` | — |
| | `Patch` | `support.gerer_kb_globale` ou `support.gerer_kb_locale` (scope vérifié par `ArticleAideProcessor`) | `article:write` | — |
| | `POST /support/articles/{id}/publier` | idem Patch (scope) | — | RG-SUP-02 |
| | `POST /support/articles/{id}/archiver` | idem Patch (scope) | — | — |
| | `GET /support/articles/{id}/historique` (collection `VersionArticle`) | `support.lire` ou `support.gerer_kb_globale`/`gerer_kb_locale` (jamais public) | `version:read` | — |
| **VersionArticle** | `Get` item (consultation d'une version précise) | idem historique | `version:read` | — |
| **PieceJointeAide** | `Post` (upload) / `Delete` | idem écriture article parent | `piece_jointe:write` | — |
| | `GetCollection`/`Get` | hérite de la visibilité de l'article parent (`ARTICLE_LIRE`) | `piece_jointe:read` | — |
| **JournalImportAide** | `GetCollection`/`Get` (lecture seule) | `support.administrer` | `journal_import:read` | `resultat`, `cleImport` |
| | `POST /support/import/executer` (déclenchement manuel, appelle le même service que la commande) | `support.administrer` | `journal_import:read` | — |
| **TicketSupport** | `Post` (ouverture) | `support.ouvrir_ticket` | `ticket:write/read` | — |
| | `GetCollection` | `support.lire_ticket_soi` (scopé demandeur=soi) / `support.lire_ticket_etablissement` (scopé établissement) / `support.traiter_ticket_n1`/`traiter_ticket_n2` (tableau de bord, §6) / `support.administrer` | `ticket:read` | `statut`, `priorite`, `moduleConcerne`, `niveauAffectation`, `affecteA`, `etablissement` |
| | `Get` item | `is_granted('TICKET_SOI', object)` ou `support.lire_ticket_etablissement`/`traiter_ticket_n1`/`traiter_ticket_n2`/`administrer` | `ticket:read` | — |
| | `POST /support/tickets/{id}/prendre-en-charge` | `support.traiter_ticket_n1` ou `support.traiter_ticket_n2` | — | CA-9 |
| | `POST /support/tickets/{id}/statut` (`en_attente_client`/`resolu`/`ferme`) | `support.traiter_ticket_n1`/`traiter_ticket_n2` | — | CA-9 |
| | `POST /support/tickets/{id}/rouvrir` | `support.ouvrir_ticket` (soi, dans le délai) ou `support.traiter_ticket_n1`/`n2`/`administrer` | — | RG-SUP-11, ⚠ délai §7 |
| | `POST /support/tickets/{id}/escalader` | `support.traiter_ticket_n1` | — | CA-10 |
| | `POST /support/tickets/{id}/reaffecter` | `support.traiter_ticket_n2` | — | RG-SUP-13 |
| | `POST /support/tickets/{id}/lier-article` | `support.traiter_ticket_n1`/`traiter_ticket_n2` | — | CA-12 |
| | `GET /support/tickets/tableau-de-bord` (custom Provider, agrégation) | `support.traiter_ticket_n1`/`traiter_ticket_n2`/`administrer` | `ticket:read` | `statut`, `priorite`, `moduleConcerne`, `niveauAffectation`, `affecteA` (CA-13) |
| **MessageTicket** | `Post` | `support.ouvrir_ticket` (soi, sur son ticket, `noteInterne` forcé `false`) ou `support.traiter_ticket_n1`/`n2` (peut poser `noteInterne`) | `message:write` | — |
| | `GetCollection` (nested sous ticket, custom Provider `MessageTicketProvider` : filtre `noteInterne` pour le demandeur) | idem visibilité ticket | `message:read` | — |
| **PieceJointeTicket** | `Post`/`GetCollection`/`Get` | hérite de la visibilité du message parent | `piece_jointe_ticket:*` | — |

- **Groupes de sérialisation** : pattern read/write par ressource, comme les modules précédents.
  `article_public:read` **n'expose jamais** `statut`, `cleImport`, `hashImportCourant`, `auteur` (surface
  publique minimale — titre, résumé, contenu, mots-clés, catégorie, fil d'Ariane, pièces jointes).
- **Custom vs CRUD** : publication/archivage/versionnage, tout le cycle de vie ticket
  (prise en charge/statut/réouverture/escalade/réaffectation/liaison article), la recherche et le
  tableau de bord sont des **opérations métier** (State Processors/Providers → services testables), pas
  du CRUD Doctrine brut — même logique que tous les plans précédents.

---

## 4. Sécurité & droits

- **Permissions `support.*`** (module `support`, ⚠ nommage dérivé par analogie comme tous les modules
  hors backlog, à arbitrer avec M8) : `lire`, `gerer_kb_globale`, `gerer_categorie`, `gerer_kb_locale`,
  `ouvrir_ticket`, `lire_ticket_soi`, `lire_ticket_etablissement`, `traiter_ticket_n1`,
  `traiter_ticket_n2`, `administrer` — 10 actions au total (`lire_public` n'est **pas** une permission
  `PermissionVoter` : c'est un accès `PUBLIC_ACCESS` non authentifié, comme `boutique.acheter_invite`
  n'en est pas une non plus, §4 `plan-boutique.md`).
- **Voters** (`App\Support\Security\*`) :
  - `ArticleVisibiliteVoter` (attribut `ARTICLE_LIRE`) — un lecteur anonyme voit `publie` +
    `publicCible ∈ {usager, tous}` + (`portee=global` ou `portee=local` avec établissement de contexte
    correspondant) ; un agent authentifié voit en plus `publicCible=agent` ; un rédacteur/admin voit tout
    (y compris `brouillon`/`archive`) dans son périmètre (global entier, ou local pour son
    établissement).
  - `TicketSoiVoter` (attribut `TICKET_SOI`) — `ticket.demandeur === utilisateur courant`, patron
    `App\Personnel\Security\EmployeSoiVoter`/`ReservationSoiVoter`.
  - `MessageTicketVoter` (attribut `MESSAGE_LIRE`) — un demandeur ne voit jamais un `MessageTicket.
    noteInterne = true` ; un agent/admin voit tout.
- **`PerimetreSupportExtension`** (`App\Support\Doctrine`, patron `PerimetrePersonnelExtension`) :
  - `ArticleAide` (listing back-office) : visible si `portee=global`, **ou** `portee=local` et
    l'utilisateur possède une `Affectation` sur `etablissement` — appliqué en plus (jamais à la place)
    du filtrage par statut/ciblage fait par le Voter/Provider.
  - `TicketSupport` : restreint selon la permission la plus large détenue —
    `support.administrer`/`traiter_ticket_n1`/`traiter_ticket_n2` → tous les tickets du périmètre
    d'affectation (join `Affectation` sur `etablissement`) ; `support.lire_ticket_etablissement` → tickets
    de l'établissement actif uniquement ; sinon → `demandeur = utilisateur courant` uniquement
    (`support.lire_ticket_soi`).
- **Permissions réutilisées (non redéfinies)** — aucune : le module Support est transverse et ne
  s'appuie sur aucune permission d'un autre module fonctionnel (contrairement à Boutique/Musée qui
  consomment M1/M2/Réservation).

---

## 5. Mécanisme « doc vivante → KB » — commande `support:importer-aide` (cœur du module)

### 5.1 Convention de fichiers (tranche le point ouvert n°3 de la spec)

- Emplacement : `docs/aide/<module>/<slug>.md` (racine dépôt, hors `app/`), un fichier = un article.
- **Front matter YAML** délimité par `---` en tête de fichier (parsé par `symfony/yaml`, déjà dépendance
  du socle), champs :

```yaml
---
titre: "Encaisser une vente au guichet"
categorie: caisse-vente          # slug CategorieAide ; créée si absente (résolution automatique)
publicCible: agent                # agent | usager | tous
portee: global                    # global | local
etablissement: null               # requis si portee: local (code/slug établissement)
moduleLie: vente                  # référentiel ouvert, optionnel
statut: brouillon                 # brouillon | publie (défaut brouillon)
resume: "Étapes pour encaisser une vente au comptoir."   # optionnel
motsCles: [caisse, encaissement, vente]                   # optionnel
cleImport: vente/encaisser-guichet  # optionnel, dérivée de "<module>/<slug>" si absente
---
Corps Markdown de l'article...
```

- **Clé d'upsert stable** : `cleImport` explicite, sinon `<module>/<slug>` dérivé du chemin — stable tant
  que le fichier n'est ni renommé ni déplacé (limite documentée, cf. §7 Risque n°1).

### 5.2 Commande Symfony

```
php bin/console support:importer-aide [--chemin=docs/aide] [--strict] [--dry-run]
```

- Implémentée par `App\Support\Command\ImporterAideCommand`, déléguant toute la logique à
  `App\Support\Service\ImporteurAideService` (réutilisable par la commande **et** par
  `POST /support/import/executer`, §3) — patron *service applicatif + fine commande CLI*, cohérent avec
  `ExpirerDelegationsCommand`.
- **Algorithme, par fichier trouvé (Symfony `Finder`), traitement isolé (try/catch)** :
  1. Parse front matter + corps ; validation (champs requis, valeurs d'enum) — échec → persist
     `JournalImportAide(resultat=erreur, messageErreur=...)`, **passe au fichier suivant** (cas limite
     §8 spec : un fichier invalide n'affecte pas les autres).
  2. Résout `cleImport` (explicite ou dérivée) et `hashContenu = sha256(frontMatterNormalise . corps)`.
  3. Résout/crée la `CategorieAide` par slug (création silencieuse si absente, journalisée dans les logs
     applicatifs — ⚠ hypothèse additive, non explicitement demandée, cf. §7 Risque n°2).
  4. Recherche `ArticleAide` par `cleImport` (index unique) :
     - **Absent** → création : `ArticleAide(origine=import, cleImport, statut=frontMatter.statut ??
       brouillon, auteur=UtilisateurSystemeSupport, hashImportCourant=hash)` + `VersionArticle(numero=1,
       origine=import)` → `JournalImportAide(resultat=cree)`.
     - **Présent, `hashImportCourant === hash`** → **aucune écriture** (idempotence stricte) →
       `JournalImportAide(resultat=inchange)`.
     - **Présent, hash différent** → met à jour les champs depuis le front matter/corps, incrémente
       `numero`, crée une nouvelle `VersionArticle` (RG-SUP-08) ; **règle de republication (RG-SUP-08)** :
       si `ArticleAide.statut === Publie` **avant** la mise à jour : conserve `Publie` et republie
       (`versionPubliee` = nouvelle version) **uniquement si** `frontMatter.statut === 'publie'`
       explicitement ; **sinon** repasse `statut = Brouillon` **sans toucher** `versionPubliee`, qui
       continue de pointer l'ancienne version publiée (le lecteur continue de voir la dernière version
       **validée**, cohérent RG-SUP-03) ; met à jour `hashImportCourant` → `JournalImportAide(resultat=
       maj)`.
  5. `--dry-run` : exécute toute la logique de résolution/diff **sans flush**, affiche le résumé prévu.
  6. `--strict` : code de sortie non-zéro si au moins un `resultat=erreur` (utilisable en CI).
- **Auteur système** — `App\Support\Service\UtilisateurSystemeSupportResolver` (patron
  `SessionSystemeBoutiqueResolver`) résout/crée paresseusement un `Utilisateur` technique
  `systeme.support@itcotation.internal`, sans mot de passe utilisable (compte non connectable),
  réutilisé comme `auteur` de tout `ArticleAide`/`VersionArticle` d'origine `import`.
- **Rejouable à chaque livraison** — conçu pour être invoqué (a) manuellement par un développeur/agent
  SDD après rédaction de la doc vivante d'une story, (b) en étape de pipeline CI/CD après un merge
  touchant `docs/aide/**`, (c) via l'action API `support.administrer` pour un admin sans accès console.
  Chaque exécution est **sans effet de bord** si rien n'a changé (CA-7).

### 5.3 Résumé en 3 lignes (rapport de mission)

1. La commande lit tous les fichiers Markdown `docs/aide/<module>/<slug>.md` (front matter YAML +
   corps), résout une **clé stable** (`cleImport` explicite ou `<module>/<slug>`) et un **hash** du
   contenu complet.
2. Elle **upsert** un `ArticleAide` par clé : fichier nouveau → création (statut du front matter,
   défaut brouillon) ; hash inchangé → aucune écriture (idempotence stricte, testée par double
   exécution) ; hash modifié → nouvelle `VersionArticle` et, si l'article était publié, **repasse en
   brouillon sauf mention explicite `statut: publie`** dans le fichier.
3. Chaque exécution, fichier par fichier, est journalisée dans `JournalImportAide` (créé/maj/
   inchangé/erreur) ; un fichier en erreur (front matter invalide) est rejeté **isolément**, sans
   bloquer les autres — la commande est rejouable à chaque livraison de module pour tenir la KB à jour
   sans double-saisie.

---

## 6. Migrations

- **Migration structurelle `VersionSupport_structure`** — crée les 8 tables (`support_categorie_aide`,
  `support_article_aide`, `support_version_article`, `support_piece_jointe_aide`,
  `support_journal_import_aide`, `support_ticket_support`, `support_ticket_article_lie`,
  `support_message_ticket`, `support_piece_jointe_ticket`).
  - **Index/contraintes** : unique `CategorieAide.slug`, `ArticleAide.slug`, unique partiel
    `ArticleAide.cleImport` (WHERE `origine='import'`), unique `(VersionArticle.article,
    VersionArticle.numero)`, FK vers `App\Organisation\Entity\Etablissement`/`App\Securite\Entity\
    Utilisateur` (socle) — **suppose la migration socle L0 jouée d'abord**.
  - **SQL brut additionnel (raw `addSql`)** : `ALTER TABLE support_article_aide ADD FULLTEXT INDEX
    support_ft_article_recherche (recherche_texte)` (up) / `DROP INDEX support_ft_article_recherche ON
    support_article_aide` (down) — MariaDB InnoDB supporte `FULLTEXT` nativement depuis 10.0.5, aucune
    dépendance supplémentaire.
- **Migration de données `VersionSupport_permissions`** — insère `Permission(module='support', action ∈
  {lire, gerer_kb_globale, gerer_categorie, gerer_kb_locale, ouvrir_ticket, lire_ticket_soi,
  lire_ticket_etablissement, traiter_ticket_n1, traiter_ticket_n2, administrer})` — idempotente (garde
  d'existence avant insertion, même patron que les migrations de permissions précédentes).
- **Modification de fichier partagé (hors migration DB, additive)** — ajout de `ArticleAide::class`,
  `TicketSupport::class` à `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES` (RG-SUP-15) ;
  `CategorieAide`/`VersionArticle`/`MessageTicket` **non ajoutés** (volumétrie/bruit — seules les actions
  listées en RG-SUP-15 — publication/archivage article, changement de statut/escalade/réaffectation
  ticket — sont couvertes par le changeset d'`ArticleAide`/`TicketSupport` eux-mêmes).
- Migrations rejouables, réversibles (`down()` symétrique), versionnées Doctrine ; jamais de
  `schema:update --force`.

---

## 7. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Fil d'Ariane : catégorie profondeur 3 → chemin racine→…→catégorie complet retourné | Unit | RG-SUP-01, US-SUP-03 |
| Suppression catégorie non vide → 409, catégorie vide → autorisée | API | cas limite §8, RG-SUP-01 |
| Article créé sans publication → invisible en recherche/navigation publique et pour un agent ; publication explicite → visible selon ciblage | API | CA-2, RG-SUP-02 |
| Article local établissement A invisible pour un lecteur de l'établissement B ; visible pour A | API | CA-3, RG-SUP-04 |
| Filtrage `moduleLie=vente` → seuls les articles rattachés apparaissent | API | CA-4, RG-SUP-05 |
| Modification d'un article publié → nouvelle `VersionArticle`, historique complet consultable, aucune version perdue | API + Unit | CA-5, RG-SUP-03 |
| Recherche « encaisser une vente » par un lecteur anonyme → uniquement publié + `usager`/`tous` + global/local-contextuel, jamais brouillon ni `agent` | API | CA-1, RG-SUP-06 |
| Recherche sans résultat → état vide + suggestion d'ouverture de ticket pour un exploitant authentifié, aucune suggestion pour un anonyme | API | CA-6, cas limite §8 |
| Import — fichier nouveau → `ArticleAide` créé au statut du front matter | Unit (`ImporteurAideService`) | CA-7 (1/3), RG-SUP-07/08 |
| Import — ré-exécution sans changement (2 passages) → aucun doublon, aucune nouvelle version, `resultat=inchange` | Unit | CA-7 (2/3), idempotence |
| Import — contenu modifié → nouvelle `VersionArticle` ; article publié repasse `brouillon` sauf `statut: publie` explicite → reste publié et republie | Unit | CA-7 (3/3), RG-SUP-08 |
| Import — front matter invalide dans 1 fichier parmi 3 → seul ce fichier `erreur`, les 2 autres traités normalement | Unit | cas limite §8 |
| Import — `--dry-run` ne modifie rien ; `--strict` renvoie un code non-zéro si une erreur | Unit (commande) | robustesse CI |
| Ouverture de ticket par un exploitant authentifié → statut `nouveau`, rattaché à son établissement et lui-même | API | CA-8, RG-SUP-09 |
| Cycle de vie : prise en charge → `en_cours` ; résolution → `resolu` + `dateResolution` ; fermeture → `ferme` + `dateFermeture` | API | CA-9, RG-SUP-11 |
| Escalade N1→N2 : `niveauAffectation` change, `affecteA` change, action tracée (audit) | API + Unit | CA-10, RG-SUP-13 |
| Note interne : message `noteInterne=true` jamais visible du demandeur, visible des agents ; un demandeur ne peut pas poser `noteInterne=true` (403/422) | API | CA-11, RG-SUP-12 |
| Lien article↔ticket : visible du demandeur, complète (ne remplace pas) un message de réponse | API | CA-12, RG-SUP-14 |
| Tableau de bord : filtre statut=`nouveau` + niveau=`N1` → tickets correspondants y compris sans agent affecté | API | CA-13, US-SUP-14 |
| Audit : publication article / changement statut ticket / escalade-réaffectation → `EntreeAudit` créée ; tentative de modification via l'API → inexistante | API | CA-14, RG-SUP-15 |
| Cloisonnement établissement : rédacteur local sans affectation sur l'établissement B → 403 sur un article local B ; demandeur soi → ne voit jamais les tickets d'un autre établissement sans `lire_ticket_etablissement` | API | RG-SOCLE-05 |
| Recherche plein-texte : `MATCH...AGAINST` retourne les articles pertinents, tri local-en-tête respecté | Unit (`RechercheArticleAideProvider`) | §0 décision n°3/4 |

---

## 8. Tâches (voir tasks-support.md)

T1 enums (`App\Support\Enum\*`) + `CategorieAide` (arbre, fil d'Ariane, garde suppression non vide) → T2
`ArticleAide`/`VersionArticle` + `ArticleAideEcritureService` (versionnage systématique) +
`ArticlePublierProcessor`/`ArticleArchiverProcessor` → T3 `PieceJointeAide` → T4 colonne
`rechercheTexte` + index `FULLTEXT` + `RechercheArticleAideProvider` + Provider KB publique
(`ArticlePublicProvider`) + `ArticleVisibiliteVoter` → T5 `PerimetreSupportExtension` (cloisonnement
local/global) → T6 `UtilisateurSystemeSupportResolver` + `ImporteurAideService` (upsert/hash/
republication) → T7 `ImporterAideCommand` (`support:importer-aide`, options `--chemin`/`--dry-run`/
`--strict`) + endpoint `POST /support/import/executer` + `JournalImportAide` → T8 `TicketSupport` +
ouverture + cycle de vie (`prendre-en-charge`/`statut`/`rouvrir`) → T9 `MessageTicket` (+ note interne,
`MessageTicketProvider`, `MessageTicketVoter`) + `PieceJointeTicket` → T10 escalade/réaffectation N1/N2 +
liaison article (`lier-article`) → T11 tableau de bord (`GET /support/tickets/tableau-de-bord`) → T12
sécurité (permissions `support.*`, `TicketSoiVoter`) + extension `AuditWriteSubscriber` → T13 migrations
(structure + `FULLTEXT` + permissions) → T14 tests (ordonnées, cf. fichier tâches).

---

## 9. Risques / à valider

1. **⚠ Clé d'upsert `cleImport` dérivée du chemin de fichier** — un renommage/déplacement de fichier sans
   `cleImport` explicite en front matter crée un **nouvel** `ArticleAide` au lieu de mettre à jour
   l'existant (la clé dérivée change avec le chemin). Recommandation opérationnelle : toujours fixer
   `cleImport` explicitement dès qu'un article a vocation à être renommé/déplacé — non automatisable
   sans heuristique de similarité (hors périmètre v1).
2. **⚠ Création automatique de catégories par l'import** (§5.1 point 3) — non explicitement demandée par
   la spec (RG-SUP-01 ne mentionne qu'une gestion par un rédacteur habilité) ; retenue par pragmatisme
   pour ne pas bloquer l'import sur une catégorie manquante — à confirmer avec le produit, alternative
   possible : rejeter en `erreur` si la catégorie n'existe pas.
3. **⚠ Seuil de « modification substantielle » non défini (RG-SUP-08, point ouvert n°4 spec)** — ce plan
   traite **tout** octet différent (y compris un simple correctif de coquille) comme déclenchant une
   nouvelle version et, le cas échéant, une dépublication — pas de seuil de tolérance ; à confirmer si un
   seuil (ex. diff Markdown normalisé) est attendu avant mise en production.
4. **⚠ Délai de réouverture d'un ticket fermé non chiffré (RG-SUP-11, point ouvert n°6 spec)** — valeur
   par défaut à fixer en configuration (`support.delai_reouverture_jours`, ex. 15) plutôt qu'en dur dans
   le code, conformément à la constitution §4 point 4 — au-delà, seule l'ouverture d'un nouveau ticket
   référençant l'ancien est proposée côté UI (non modélisé comme un lien technique en base v1).
5. **⚠ Canal de notification (nouveau message/changement de statut) non tranché (point ouvert n°7 spec)**
   — ce plan ne modélise **aucun** envoi (ni e-mail ni in-app) ; à ajouter (probable réutilisation du
   patron `symfony/mailer` déjà utilisé par `InvitationMailer`) une fois le canal confirmé.
6. **⚠ Paramètre `etablissement` de la KB publique, fourni par un visiteur anonyme, non authentifiable
   côté serveur** — n'élargit que la visibilité d'articles déjà publics (jamais de fuite de
   brouillon/article `agent`), mais permet à un visiteur d'énumérer les établissements ayant une KB
   locale ; risque jugé faible (aucune donnée sensible exposée) mais à confirmer avec la sécurité
   applicative avant une intégration réelle dans la vitrine white-label (point d'intégration non détaillé
   par la spec, §9).
7. **⚠ Accessibilité RGAA/WCAG 2.2 AA de la surface KB publique** (constitution §4 point 5) — ce plan ne
   couvre que l'API ; la conformité effective dépend de l'implémentation front, hors périmètre.
8. **⚠ RGPD — contenu des tickets/pièces jointes** — un ticket peut contenir des données personnelles
   (captures d'écran, coordonnées) ; aucune politique de rétention/purge n'est spécifiée par la spec
   (contrairement à `App\Crm\Entity\RegleConservation`) — à définir avant mise en production si des
   données personnelles sensibles transitent par ce canal.
9. **⚠ US-SUP-01 à 14 / RG-SUP-01 à 15 non encore validées/numérotées officiellement** dans le backlog
   (spec préambule) — ajustement mineur de nommage possible sans impact structurel attendu sur le modèle
   de données.
10. **⚠ Ticket ouvert par un usager grand public (hors exploitant)** — explicitement **exclu** de ce plan
    (§2.2 spec, point ouvert n°2) ; si ce canal est demandé plus tard, il nécessitera un mécanisme
    d'authentification/anti-abus dédié (jeton, captcha) non prévu ici, par analogie avec le jeton de
    panier invité de `plan-boutique.md` Risque n°3.
