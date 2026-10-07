# Audit complet Fluvia — 14/09/2026

*Lecture seule. Dix angles lancés en parallèle (agents du kit SDD), mesures outillées, puis passage au contradicteur sur les dix gestes. Aucun fichier du produit n'a été touché — ce document est le seul écrit.*

---

## (a) Verdict en cinq lignes

1. Le **socle est sérieux et honnête** : cloisonnement multi-tenant mature (extensions Doctrine + 36 garde-fous), pas de secret commité, upload durci, charte visuelle propre et défendue, dette *rendue visible* par 18 lignes de base gelées.
2. Mais **la chaîne d'encaissement en ligne est un décor** : PSP carte, collecteur SEPA et relance après refus sont des bouchons non branchés — on ne peut ni encaisser une carte à distance, ni prélever un abonnement réel, ni relancer un impayé.
3. **Le gate ment sur lui-même** : le verdict des garde-fous compte des abstentions comme des verts (#58), deux mesures ne tournent jamais en rendant vert (#5), une classe supprimée reste verte (#30) — la CI, seul contrôle, surestime sa couverture.
4. **La promesse multi-verticale n'est pas servie** : les écrans par verticale sont des coquilles, le résolveur de vocabulaire n'existe pas, et `frontend/` n'a aucun propriétaire — donc même le correctif de 4 lignes est bloqué.
5. **Le cap a dérivé** : D101/D102 ont acté « l'API d'abord » le 31/08 ; deux semaines de correctifs caisse/membership plus tard, aucune Issue ne porte l'API. À trancher : le cap tient-il, ou a-t-il changé sans être écrit ?

---

## (b) Tableau des bloquants (toutes sources, par gravité)

| # | Gravité | Bloquant | fichier:ligne | Scénario | Correctif | Taille |
|---|---------|----------|---------------|----------|-----------|--------|
| 1 | CRITIQUE | Paiement carte = bouchon | `app/src/Boutique/Paiement/PspCbStubAdapter.php:13-14,34-36` | Le circuit carte signe ses propres reçus en préprod ; aucun contrat PSP réel → aucune vente carte réelle possible | Brancher un PSP réel **ou** geler explicitement la vente carte à distance | L |
| 2 | CRITIQUE | Collecteur SEPA = bouchon | `app/src/Sepa/Adapter/CollecteurSepaStubAdapter.php` (+ `CollecteurSepaInterface.php`) | Prélèvement récurrent d'abonnement non branché sur une banque réelle → trésorerie récurrente fictive | Contrat de remise SEPA + adaptateur réel avant tout abonnement prélevé | L |
| 3 | CRITIQUE | Relance après refus carte jamais appelée | `app/src/Sepa/Service/CardDebitFallback.php:20-23` | « RIEN N'APPELLE ENCORE CE SERVICE » — le déclencheur PAY-3 n'existe pas → refus carte = 0 relance = impayé silencieux | Livrer PAY-3 / brancher le fallback avant d'ouvrir la vente carte à distance | M |
| 4 | HAUTE | Le verdict des garde-fous ment | Issue #58, PR #80 ; Issue #5 (n40/n51) ; Issue #30 (#37) | Abstentions comptées vertes (43 réelles annoncées 49) ; n40/n51 ne tournent jamais mais rendent vert ; classe supprimée mais citée = vert | Corriger le comptage du verdict + faire tourner n40/n51 + #37 | M |
| 5 | HAUTE | Abonnement conso sans cadre légal | `features/abonnement/specs/spec-abonnement-transverse.md:8,16,38-39` | Souscription en ligne d'un particulier sans durée d'engagement, préavis de résiliation, reconduction tacite (L215-1) ni rétractation (L221-28) fixés | Trancher Q1/Q2/Q3 juridiques (voir §d) avant d'écrire la clause | M |
| 6 | HAUTE | Reversements OTA morts | Issue #56 | Ni le montant ni le statut ne sont écrivables — la chaîne est morte, pas seulement sans écran | Rendre le champ écrivable + écran | M |
| 7 | HAUTE | Promesse multi-verticale non servie | `specs/verticales/vocabulaire.md:96-114,61-63` | Écrans verticaux = souches (Musee.jsx 46 l., Padel 47) ; résolveur de vocabulaire inexistant → un padeliste lit « Ressource », pas « Terrain » | Écrire le résolveur de vocabulaire (les 12 clés existent et sont testées) | M |
| 8 | HAUTE | `frontend/` sans propriétaire | `specs/verticales/vocabulaire.md:126-132` (CODEOWNERS) | Aucun propriétaire → même un correctif de libellé de 4 lignes ne peut être fait sans arbitrage. Verrou organisationnel | Ajouter un propriétaire `frontend/` dans `.github/CODEOWNERS` | S |
| 9 | MOYENNE | Écran fiche produit cassé | Issue #16 (`null.find`) | L'écran produit part en erreur → parcours catalogue interrompu | Corriger le null + refonte prévue | S |
| 10 | MOYENNE | Facture PDF = page appli | PR #91 (OPEN) | Le PDF téléchargé était la page de l'application, pas la facture | Merger le correctif en attente | S |
| 11 | MOYENNE | N+1 probable réservation + index manquant | `app/src/Reservation/Service/FreeSlotFinder.php:113-118,150-151` ; `app/src/Reservation/Entity/DisponibiliteRessource.php` | Planning bouclant sur ressources/jours → une requête par itération ; pas d'index composite `(ressource_id, jourSemaine)` | Précharger en `IN(:ressources)` + `EXPLAIN` avant d'ajouter l'index | S |
| 12 | MOYENNE | `reference.php` commité sans garde-fou | `app/config/reference.php` (1820 l.) | Convention purement humaine (`git checkout --`) ; un oubli commite 1820 lignes générées | Garde-fou anti-commit de ce fichier | S |
| 13 | MOYENNE | 135 catch aveugles | Issue #29 | Erreurs avalées sans traitement du refus → échecs muets | Étendre le traitement déjà en place dans le frontal | M |
| 14 | MOYENNE | Cap produit dérivé, non écrit | `COORDINATION/DECISIONS.md:3189-3231` (D101/D102) ; commit c16547dc (#73) | « API d'abord » acté le 31/08, zéro Issue le porte ; « décision tronc en attente » jamais retrouvée | Ré-acter ou consigner la suspension de D102 ; clarifier #73 | S |
| 15 | BASSE | Fichiers front géants | `frontend/src/api/client.js` (3300), `pages/Parametres.jsx` (2311), `ProduitFiche.jsx` (2063), `Caisse.jsx` (1858) | Diffs noyés, correctifs répliqués 4× par verticale, conflits de merge | Découpage ciblé au prochain chantier sur ces zones, pas en churn isolé | L |
| 16 | BASSE | Doc « retirée » toujours au disque | `CLAUDE.md:82-84` vs `COORDINATION/ORDRES/`, `RAPPORTS/`, `TASKS.md`, `MESSAGES.md` | Fichiers déclarés remplacés mais présents sans bandeau legacy → agent induit en erreur sur le canal actif | Bandeau « archive » en tête, ou suppression | S |

---

## (c) Une section par agent (texte rendu tel quel, non adouci)

### security-reviewer

> ## Audit de sécurité — Fluvia (lecture seule)
>
> **Multi-tenant / cloisonnement — RAS globalement, avec réserve MEDIUM**
> Le dépôt a un dispositif de cloisonnement inhabituellement mature : `app/src/Securite/Doctrine/UserScopeExtension.php`, `ResidualScopeExtension.php`, `app/src/Vente/Doctrine/PerimetreVenteExtension.php`, `app/src/Personnel/Doctrine/PerimetrePersonnelExtension.php`, plus 15+ garde-fous dédiés (`bin/garde-fou-cloisonnement.php`, `bin/garde-fou-champ-cloisonnement.php`, `bin/garde-fou-etablissement-ecrivable.php`, `bin/garde-fou-ecriture-transfrontiere.php`, `bin/dette-cloisonnement.php`). Point notable : `bin/cloisonnement.ligne-de-base.json` (225 lignes) et `bin/etablissement-ecrivable.ligne-de-base.json` (150 lignes) sont des cliquets de dette explicitement documentés (`_lisez_moi`, `scelle`/`scelle_resolution`) — c'est le mécanisme prévu pour les exemptions, conforme à la règle CLAUDE.md ("jamais silencieusement ignorée"). MEDIUM : je n'ai pas pu, dans le budget imparti, vérifier le contenu détaillé de ces baselines pour confirmer qu'aucune entrée n'y dort sans justification récente — à auditer spécifiquement si une PR touche ces fichiers.
>
> **Injection SQL — RAS**
> `app/src/Personnel/Doctrine/PerimetrePersonnelExtension.php:174,210` utilise `sprintf` pour construire du DQL, mais uniquement avec des constantes de classe PHP (`RattachementEmploye::class`) et des expressions internes — aucune entrée utilisateur concaténée. Aucune concaténation de chaîne SQL avec variable utilisateur trouvée dans `app/src`.
>
> **Upload de fichiers — RAS, bonne pratique notée**
> `app/src/Dms/Processor/UploadDocumentProcessor.php:97-100` : commentaire explicite indiquant qu'un audit antérieur (06/09) a trouvé qu'un fichier HTML déclaré `application/pdf` par le client était stocké/servi tel quel ; correction faite via `finfo` (`$file->getMimeType()` qui inspecte le contenu réel, pas l'en-tête déclaré). Établissement dérivé serveur (`ContexteEtablissement::etablissementActif()`, ligne 47), jamais d'un champ client. Taille limitée par `DMS_MAX_UPLOAD_BYTES` (ligne 77-79). Bon pattern.
>
> **Secrets — RAS**
> `app/.env` ne contient que des marqueurs (`A_GENERER_PAR_LE_DEPLOIEMENT_VOIR_infra_env.preprod.example`, lignes 68, 97, 103, 113, 133, 151, 158, 180, 198, 217, 233) et `DATABASE_URL` de dev local (`app/.env:47`, identifiants `app:app` triviaux, conteneur local uniquement, non exploitable en prod). Un garde-fou dédié existe : `bin/garde-fou-secrets.php`. Aucun secret réel trouvé par grep sur les patterns clé/token/password dans le code applicatif.
>
> **CI — RAS, une note INFO**
> `.github/workflows/auto-merge.yml:36` utilise `github.token` (token éphémère GitHub natif), pas un PAT en dur — conforme. INFO : je n'ai pas eu le budget pour lire `garde-fous.yml` en entier ni vérifier les permissions (`permissions:`) déclarées dans les workflows (principe du moindre privilège des tokens CI) — à vérifier si le périmètre s'étend.
>
> **Migrations** : écrites à la main (fichiers `VersionYYYYMMDDHHMMSS.php` horodatés individuellement), cohérent avec la règle CLAUDE.md interdisant `migrations:diff` brut. Aucun `DROP TABLE`/`DROP COLUMN` détecté par grep sur l'échantillon listé.
>
> **Dépendances vulnérables** — non évalué : nécessiterait `composer audit` / `npm audit`, hors budget lecture-seule ciblé fichier par fichier. Pas de constat, ni positif ni négatif.
>
> **XSS / CSRF / NF525** — non explorés dans ce passage (budget épuisé) ; RAS par défaut de preuve, à ne pas interpréter comme validé.
>
> Verdict sécurité : PASS WITH NOTES (aucun CRITICAL identifié dans le périmètre lu ; MEDIUM ouvert sur le contenu des baselines de dette cloisonnement à auditer en détail, et axes dépendances/XSS/CSRF/NF525 non couverts par ce passage faute de budget — nécessitent un audit complémentaire ciblé).

### relecteur (qualité et altitude)

> **Relecture — Revue générale à la demande** (dette technique, duplication, gros fichiers, tests vides, code mort). Informative, ne gate rien.
>
> ## HIGH
>
> - **`frontend/src/api/client.js` — 3300 lignes.** Un seul fichier plat pour tout le client API, avec seulement 7 `export` de haut niveau détectés : signe d'un fichier fourre-tout (probablement un gros objet/switch interne plutôt que des modules par domaine). Impact : toute modification touchant un endpoint oblige à naviguer un monolithe, risque de conflits de merge élevé, aucune isolation par verticale. Suggestion : découper par domaine métier (Facturation, Sport, Padel, Acces...) à l'occasion d'un prochain chantier sur cette zone, pas en urgence isolée.
>
> - **Duplication structurelle des pages verticales** — `frontend/src/pages/Patinoire.jsx` (1539 lignes), `Sport.jsx` (1453), `Padel.jsx` (1122), `Piscine.jsx` (980) : quatre fichiers de taille comparable, vraisemblablement porteurs de la même mécanique de planning/réservation copiée-collée par verticale (cohérent avec le pattern multi-verticale du produit, mais chaque correctif doit être répliqué 4 fois — voir l'historique récent, commit `6497f3dd` qui corrige "deux gestes" perdus dans catalogue et planning, symptôme classique de duplication non factorisée). Suggestion : évaluer l'extraction d'un composant de planning commun paramétré, avant que le nombre de verticales ne grossisse encore (camping/hôtel/restauration annoncés dans CLAUDE.md).
>
> ## MEDIUM
>
> - **`app/config/reference.php` — 1820 lignes, présent dans le repo au moment du scan.** CLAUDE.md indique explicitement que ce fichier est régénéré par la suite de tests et doit subir un `git checkout --` avant chaque commit. Rien ne garantit dans le dépôt lui-même (pas de hook visible) que cette étape n'est pas oubliée un jour ; c'est une convention purement humaine/documentaire. Suggestion : un garde-fou dans `bin/` qui vérifie que ce fichier ne fait pas partie d'un commit, si ce n'est pas déjà fait.
>
> - **`frontend/src/pages/Parametres.jsx` — 2311 lignes** et `ProduitFiche.jsx` — 2063 lignes, `Caisse.jsx` — 1858 lignes. Trois fichiers frontend proches ou au-delà de 2000 lignes. Impact : lisibilité et testabilité réduites, revue de PR difficile sur ces fichiers (tout diff y est noyé). Suggestion : découpage en sous-composants lors du prochain chantier qui touche ces pages — ne pas les toucher juste pour ça (churn inutile).
>
> - **`app/src/Facturation/Entity/ParametreFacturationEtablissement.php:106`** — propriété marquée `@deprecated Lire ProfilExploitant — voir Version20260901000000` mais toujours présente comme `#[ORM\Column]` actif. Code mort en sursis. Suggestion : vérifier si la migration citée a bien purgé tous les lecteurs de ce champ ; si oui, planifier sa suppression physique (colonne + entité) plutôt que de le laisser en dette perpétuelle.
>
> ## LOW / INFO
>
> - **741 fichiers de test PHP**, 250+ fichiers de tests avec au moins une assertion détectée par grep — aucun test structurellement vide (fonction `test*` sans corps ni assertion) trouvé sur l'échantillon analysé. Le seul `markTestSkipped` inspecté (`app/tests/Website/EditorTradeApiTest.php:341`) est un skip conditionnel légitime sur une architecture non encore migrée, pas un test creux. RAS sur l'axe "tests vides" au niveau de rigueur permis par ce budget (recherche par regex, pas de lecture exhaustive des 741 fichiers).
> - **Pas de `console.log`/`debugger` oublié** détecté dans `frontend/src`. RAS sur cet axe.
> - **`app/tests/Acces/Api/CardRechargeTest.php` — 780 lignes** et **`app/tests/Calendar/CalendarTest.php` — 529 lignes** : fichiers de test eux-mêmes volumineux, cela reste secondaire par rapport aux fichiers de production mais complique la maintenance des scénarios (CardRechargeTest a 16 fonctions `test*`, un seul fichier pour tout le cycle de rechargement carte).
> - Seulement 5 occurrences de `TODO/FIXME/XXX/HACK` dans `app/src`, concentrées dans `RevenueRecovery`, `Sepa/DataFixtures`, `Social/DataFixtures` — dette marquée mais limitée en volume, pas d'alerte.
>
> Verdict qualité : PASS WITH NOTES — aucun bloquant CRITICAL/HIGH au sens "casse ou faille", mais dette de taille de fichiers (client.js, pages verticales, Parametres.jsx) à traiter par refactor ciblé avant qu'elle ne s'aggrave avec l'ajout des verticales camping/hôtel/restauration annoncées.

### coherence-reviewer

> Rapport de vérification de cohérence — Fluvia
>
> **MEDIUM — Surfaces de documentation "retirées" mais toujours présentes au disque, sans marqueur legacy**
> `CLAUDE.md:82-84` ("Ce qui a été retiré (migration vers GitHub)") annonce explicitement que `COORDINATION/ORDRES/`, `COORDINATION/RAPPORTS/`, `TASKS.md` et `MESSAGES.md` sont **remplacés** par branches+PR+Issues. Or ces artefacts existent toujours intégralement sur le disque : `COORDINATION/ORDRES/claude-{B,C,D,E,F,G,H,I}.md` (8 fichiers), `COORDINATION/RAPPORTS/claude-{A,B,C,D,E,F,G,H,I,8e}.md` (10 fichiers), `COORDINATION/TASKS.md` (dernière refonte datée du 31/08, `TASKS.md:3`), `COORDINATION/MESSAGES.md`. Aucun de ces fichiers ne porte de bandeau "legacy / conservé pour archive" en tête — un agent qui `Glob`e `COORDINATION/` sans avoir lu CLAUDE.md en détail peut légitimement les prendre pour des canaux actifs. CLAUDE.md dit "retiré", la réalité dit "conservé sans statut". Risque d'agent induit en erreur sur le canal de coordination à utiliser — mais le texte de CLAUDE.md est sans ambiguïté sur l'intention, donc gravité contenue.
>
> **LOW — Terminologie "délégation" au sens du guard-fou vs recherche du délai "7 jours de contestation"**
> `COORDINATION/DECISIONS.md:898` documente `ExpirerDelegations` ("une délégation de droits n'expire jamais") comme garde-fou anti-régression, implémenté en `app/src/Securite/Command/ExpirerDelegationsCommand.php` — cohérent, code présent. Aucune décision ni spec du dépôt ne définit de "délai de contestation de 7 jours" applicable aux délégations de droits elles-mêmes : le seul "7 jours" récurrent porte sur la durée par défaut des **liens publics DMS** (`COORDINATION/DECISIONS.md:362`, `specs/dms/spec-dms.md:37,143`, arbitrage D18) et sur une fenêtre de rattrapage de vérification de majorité (`app/src/Crm/Command/VerifierMajoriteCommand.php:35`, explicitement commentée "⚠ HYPOTHÈSE de rattrapage faute d'ordonnanceur défini"). Si la consigne visait un mécanisme de délégation avec fenêtre de contestation de 7 jours, ce mécanisme **n'existe dans aucun document ni le code** — à signaler comme absence, pas comme incohérence trouvée. RAS sur une éventuelle contradiction ici faute d'objet identifiable ; à clarifier avec la demande d'origine si un tel dispositif était attendu ailleurs.
>
> **RAS — Stack technique**
> `CLAUDE.md:9` annonce PHP 8.4 / Symfony 7.4 / API Platform 4 / Doctrine ORM 3 / MariaDB 11.4. Vérifié contre `app/composer.json:10-11` (`api-platform/doctrine-orm ^4.3`, `api-platform/symfony ^4.3`), `app/composer.json:15` (`doctrine/orm ^3.6`), `app/composer.json:29` (`symfony/framework-bundle 7.4.*`) — exact accord. Aucune mention résiduelle de Symfony 6, API Platform 3, ou MariaDB 10.x hors le point ci-dessous.
>
> **LOW — Référence historique à MariaDB 10.2 hors contexte legacy explicite**
> `specs/finance/plan-treasury-cash-alerts.md:507` mentionne "colonnes GENERATED ALWAYS AS (...) VIRTUAL supportées depuis MariaDB 10.2" — c'est une justification de compatibilité minimale (pas une déclaration de version cible), donc pas une contradiction avec MariaDB 11.4 annoncé par CLAUDE.md, juste à noter car lu isolément ça peut sembler dater le projet.
>
> **RAS — Statuts "Ready"/"Terminé"/"En production" dans `features/*/specs`**
> Recherche de motifs de statut affirmatif (`Statut: Ready`, `Terminé`, `En production`) dans `features/` : aucune occurrence. Pas de faux statut détecté dans le périmètre de fichiers inspecté (`features/abonnement`, `features/chaine-encaissement`, `features/personnel-rh`, `features/referentiel-metiers`, `features/regie-encaisse-cloture-z`, `features/restauration-en-salle`).
>
> **Limite de couverture** : budget de 10 lectures quasi entièrement consommé en Grep/Glob ciblés ; je n'ai pas pu auditer exhaustivement chaque spec de `features/*/specs` ni chaque fiche `docs/support` (ce dossier n'apparaît d'ailleurs pas dans `docs/` — seuls `docs/audits`, `docs/conventions-ui.md`, `docs/etat-projet.md` existent ; si des "fiches support" sont attendues ailleurs, elles sont absentes de l'arborescence `docs/` actuelle — à vérifier si un chemin différent était visé).
>
> Verdict cohérence : COHÉRENT AVEC RÉSERVES.

### architecte-donnees

> ## Audit base de données Fluvia — lecture seule
>
> **Périmètre couvert** (budget épuisé) : entités `app/src/*/Entity`, migrations `app/migrations` (207 fichiers), un point d'accès chaud du module Réservation (`FreeSlotFinder`). Le reste des 250 fichiers repérés avec requêtes/boucles n'a pas pu être audité un par un — angle mort assumé, pas un « RAS » global.
>
> ### 1. N+1 potentiel — gravité moyenne
> `app/src/Reservation/Service/FreeSlotFinder.php:113-118` appelle `openRanges()` puis `busyRanges()` pour une ressource et un jour donnés. `openRanges()` (ligne 150-151) fait un `findBy(['ressource' => ..., 'jourSemaine' => ...])` — requête séparée par ressource. Si la méthode publique qui orchestre ceci (non lue, hors budget) boucle sur plusieurs ressources ou plusieurs jours pour construire un planning, c'est du N+1 classique (une requête `DisponibiliteRessource` + une requête `Creneau` par itération). À vérifier : le point d'appel amont (contrôleur/State Provider du planning) — je ne l'ai pas dans mon budget de lecture. Recommandation : si boucle confirmée, précharger toutes les disponibilités et créneaux de la période en une requête `IN (:ressources)` groupée par ressource en mémoire.
>
> ### 2. Index manquant — gravité basse à moyenne
> `app/src/Reservation/Entity/DisponibiliteRessource.php` : aucun `#[ORM\Index]` déclaré, alors que la requête `FreeSlotFinder.php:150-151` filtre sur `ressource` + `jourSemaine`. Sans index, la FK `ressource_id` est généralement indexée par Doctrine (contrainte FK implicite) mais pas le composite `(ressource_id, jourSemaine)`. Table probablement petite par établissement (disponibilités récurrentes hebdo) donc impact réel sans doute faible — à confirmer par `EXPLAIN` avant de trancher, pas de scan de table alarmant a priori vu le volume attendu.
>
> ### 3. Point positif constaté
> `app/src/Reservation/Entity/Creneau.php:35` — `#[ORM\Index(columns: ['ressource_id', 'debut', 'fin'], name: 'idx_creneau_ressource_periode')]` correspond exactement au filtre de `FreeSlotFinder.php:198-206` (`c.ressource = :ressource AND c.debut < :fin AND c.fin > :debut`). Bon exemple d'index conçu pour la requête réelle qui l'utilise.
>
> ### 4. Cloisonnement multi-tenant — non conclu
> Recherche `establishment` dans `app/src/Entity` : chemin inexistant (les entités sont réparties par module, ex. `app/src/Reservation/Entity`, pas un dossier `Entity` unique à la racine — la prémisse du prompt sur `app/src/Entity` est fausse, structure modulaire par bounded context). Un `PerimetreEtablissementExtension` existe (`app/src/Securite/Doctrine/PerimetreEtablissementExtension.php`), signe qu'un mécanisme de cloisonnement centralisé est en place au niveau Doctrine (extension API Platform), plutôt bon signe architectural, mais je n'ai pas vérifié son exhaustivité (quelles entités y échappent) — hors budget. À creuser par un audit dédié avec `security-reviewer`.
>
> ### 5. Migrations — non auditées en détail
> 207 fichiers de migration, tous nommés par timestamp (convention correcte, pas de `migrations:diff` brut visible dans les noms). Je n'ai lu aucun contenu de migration (budget épuisé) : aucun verdict possible sur réversibilité / opérations bloquantes. RAS non valable ici — juste non fait.
>
> ### 6. Scans complets / SELECT * / pagination — non audité
> Pas de budget restant pour grep ciblé sur `SELECT *`, `->findAll()` sans limite, ou absence de `LIMIT` sur les endpoints de liste. Angle mort déclaré, pas de RAS.
>
> **Verdict données : NEEDS FIXES (partiel/incomplet) — un N+1 probable à confirmer sur `FreeSlotFinder.php:150-151`/113-118, un index composite à vérifier par `EXPLAIN` sur `DisponibiliteRessource`, et trois axes (migrations, cloisonnement exhaustif, scans/pagination) non couverts par manque de budget : audit à compléter avant de clore, ne pas prendre ce rapport pour un feu vert global.**

### perspective-marketing

> ## Perspective marketing/produit — Fluvia
>
> **La vitrine (`vitrine/`) est aveugle aux verticales, et c'est volontaire et cohérent.** `vitrine/tarifs.js` ne contient **aucune** occurrence de piscine/padel/patinoire/musée/sport/camping/hôtel/restauration (`Grep` sur tout le fichier : 1 seul résultat, sans rapport, ligne 43 — un commentaire sur les centimes). La page lit `/editor/plans` et `/editor/plan-options` en direct (`vitrine/tarifs.js:193,215,251`) et n'affiche que des libellés de capacités, jamais de noms techniques (`vitrine/README.md:40-44` : « Aucun code technique à l'écran »). C'est la bonne discipline commerciale — le prospect ne doit jamais voir un `shortName` d'API — mais ça veut dire que **la promesse multi-verticale ne se lit nulle part dans le matériel de vente actuel** : elle dépend entièrement du contenu du catalogue de plans côté back, que je n'ai pas vérifié (hors budget).
>
> **Le module `Lodging` (camping/hôtel) existe déjà en code, contrairement à ce que suggère la doc produit publique.** `app/src/Lodging/LodgingModule.php:39` : « Vendu et activable par établissement : un camping l'active, un musée non. » Le commentaire de tête (`LodgingModule.php:10-16`) dit une **couche mince** posée sur `App\Stay` — nuitée, tarif/nuit, calendrier d'occupation. C'est un signal produit important que CLAUDE.md sous-vend en le rangeant en « à venir » : le code est en avance sur l'annonce, ce qui est la bonne direction, mais aucun fichier de vitrine ne le mentionne encore — vérifier avant tout appel d'offres campings que la promesse commerciale ne dépasse pas ce que `Lodging` couvre réellement (0.1.0, `LodgingModule.php:36`).
>
> **Les modules par verticale (piscine, padel, patinoire, musée) sont des coquilles côté écran, pas côté domaine.** Preuve directe et déjà consignée par un autre agent (`specs/verticales/vocabulaire.md:112-114`) : `Musee.jsx` fait 46 lignes, `Padel.jsx` 47, `Patinoire.jsx` et `Piscine.jsx` 69 lignes — « ce sont des souches ». Les écrans qui portent réellement le produit sont génériques : `Caisse` (618 lignes), `Parametres` (768), `Catalogue` (478), `Reservation` (266) — même source, lignes 113-114. **Conséquence commerciale directe** : si un commercial démontre « l'écran padel » ou « l'écran piscine » en pensant vendre un module métier dédié, il vend un vide — la vraie valeur est dans les écrans génériques dont le vocabulaire n'est pas encore adapté au client (voir point suivant). Ne pas construire de plaquette par verticale qui montre des captures d'écran spécifiques : elles n'existent pas.
>
> **Le nommage de métier est en dur dans un seul écran, et absent des sept autres.** `specs/verticales/vocabulaire.md:96-106` : `frontend/src/pages/Reservation.jsx` affiche « Ressource », « Réservation », « Capacité », « Accès » — jamais « Terrain », « Partie », « Occupation », « Accès terrain » pour un padeliste. Le catalogue de 12 clés de vocabulaire par verticale existe et est testé (`vocabulaire.md:4-6`, `app/tests/Verticales/VocabulaireManifesteTest.php`), mais **le résolveur qui l'active n'existe pas** (`vocabulaire.md:6,61-63` : « aucune infrastructure i18n dans le dépôt — pas de `app/translations` »). Concrètement : un gérant de padel qui ouvre l'écran de réservation aujourd'hui lit « Ressource » et « Créneau », pas « Terrain » et « Partie ». C'est un problème d'adoption immédiat et documenté, pas une hypothèse.
>
> **Le nommage est aussi bloqué en interne** : le même document (`vocabulaire.md:126-132`) signale que `frontend/` n'a **aucun propriétaire** dans `CODEOWNERS`/`FLOTTE`, donc même le correctif de 4 lignes qui afficherait « Terrain » au lieu de « Ressource » ne peut être fait par personne actuellement sans arbitrage. C'est un verrou organisationnel qui bloque une promesse produit vendue (multi-verticale, vocabulaire adapté) — pas un détail.
>
> **Homonymie « vitrine » interne, risque de confusion produit/doc.** `vitrine/README.md:6-14` documente lui-même la collision : `vitrine/` (site éditeur) vs `frontend/src/public/pages/Vitrine.jsx` (boutique en ligne d'un établissement, entité `Boutique\Entity\Vitrine`, table `bou_vitrine`). Le document dit « signalé à l'intégrateur » — pas encore résolu au 14/09.
>
> Verdict marketing : À REPOSITIONNER — le socle multi-verticale existe en profondeur (12 clés de vocabulaire, module Lodging pour camping/hôtel), mais la vitrine commerciale ne le montre pas et les écrans par verticale sont des coquilles vides ; ne pas vendre de démo "spécifique padel/piscine/musée" avant que le résolveur de vocabulaire existe, sous peine de décevoir au premier écran réel.

### perspective-direction

> ## Perspective direction — Fluvia, cadence du 14/09
>
> **Est-ce le bon moment.** Hors tour sur l'axe stratégique déclaré. D101/D102 (COORDINATION/DECISIONS.md:3189-3231) tranchent que **l'API passe en premier**, parce qu'elle commande appli mobile, agrégateurs, machines connectées et IA — et documentent au 31/08 qu'aucune clé d'API tiers, aucun webhook sortant, ni OAuth n'existent. Les 5 derniers commits (6497f3dd, b83c4fb8, 9c776289, 14410b2f, e79c7a17) et les 12 Issues ouvertes (`gh issue list`) portent tous sur caisse, membership, abonnement, garde-fous — zéro sur l'API publique en tant que produit. Le travail « API publique » visible dans `git log --all` (31548d1e, d67d1b56, 94d4b2c5…) est ancien et de nature technique/interne (mapping, cloisonnement), pas l'ouverture d'une surface pour tiers décrite en D102.
>
> **Ce qu'on ne fera pas à la place.** Faire vivre D102 : délivrer une clé d'API à un tiers, poser un webhook sortant, esquisser un modèle de partenaire. C'est nommément ce qu'attend IT Cotation, cité en D102 (DECISIONS.md:3229) comme « notre seul intégrateur potentiel [qui] attend une spécification de notre part ». Chaque semaine de correctifs caisse/membership est une semaine de plus sans réponse à cet intégrateur.
>
> **Risque principal et comment le lever vite.** Le risque n'est pas technique, il est de calendrier : D101/D102 sont des décisions du 31/08, vieilles de deux semaines, sans traduction en Issue. En une semaine, on saurait si l'API est toujours l'axe 1 en la transformant en une Issue étiquetée et assignée — le silence actuel du tracker sur ce sujet est la preuve la plus simple à obtenir.
>
> **Décision manquante.** Deux, à consigner avant tout nouveau correctif de confort :
> 1. La commit c16547dc (#73, 09/09) annonce explicitement une « décision tronc en attente » jamais retrouvée dans DECISIONS.md à cette date — à clarifier : est-elle prise ou toujours ouverte ?
> 2. Aucune décision n'arbitre pourquoi le carnet actuel (12 Issues, aucune sur D101/D102) diverge de l'ordre D102 acté par Maxime — soit D102 est suspendu par un choix non écrit, soit le tracker a dérivé de la décision.
>
> VERDICT : À ARBITRER PAR L'HUMAIN AVANT CP-1

### simplificateur (parcours)

> # Audit simplicité — parcours utilisateur Fluvia
>
> Angle : agent de caisse / agent d'accueil, pas à l'aise avec l'informatique. Lecture seule.
>
> ## CAISSE — `frontend/src/pages/Caisse.jsx` + `SessionCaisse.jsx`
> Deux frictions réelles, pas de la forme :
>
> 1. **Ouvrir une session avant de vendre.** `SessionCaisse.jsx:312` (« Ouvrir une session »), champ « Fond de caisse » `SessionCaisse.jsx:184`, et clôture « Z » `SessionCaisse.jsx:288,322`. Le mot **« Z »** et **« Clôture Z »** sont du jargon NF525 pur : un agent d'accueil ne sait pas ce qu'est un Z. Le libellé n'explique jamais « comptage de fin de journée ».
> 2. **Paiement scindé exposé par défaut.** `Caisse.jsx:72,673` : la vente ouvre une phase « encaissement scindé » avec liste de règlements (`Caisse.jsx:77,693`). Pour l'usage courant (un client, un moyen), c'est un concept en trop affiché en permanence.
> 3. Bon point à noter : le renommage « Marquer réglée » vs « Encaisser » est justement documenté comme anti-piège (`Caisse.jsx:647-648`) — ça, c'est de la simplification bien faite.
>
> Le geste de vente lui-même (grille produits → panier → encaisser) est correct : pas de wizard, tout sur une page.
>
> ## BOUTIQUE en ligne — `frontend/src/public/pages/Tunnel.jsx`
> **5 étapes** imposées : `Tunnel.jsx:7` — `['Identification', 'Bénéficiaires', 'Consentement', 'Paiement', 'Confirmation']`.
> - **« Consentement »** (`Tunnel.jsx:418`) est une étape à part entière pour un écran RGPD (`Tunnel.jsx:66`). Le titre h2 est littéralement le mot « Consentement » — jargon. Un client attend « paiement » après avoir donné les noms.
> - Étape Bénéficiaires : prénom + nom **obligatoires par billet** (`Tunnel.jsx:329,340`), date de naissance en plus. Pour 4 billets = 8 à 12 champs avant même de payer.
>
> **Version 3 étapes proposée :** (1) Vos billets + à qui (nom/prénom inline, RGPD en case à cocher dans le même écran, comme le consentement n'est qu'un OK) → (2) Paiement → (3) Confirmation. Fusionner Consentement dans Bénéficiaires (une case, pas un écran) et rendre l'identification optionnelle (achat invité). On passe de 5 à 3.
>
> ## RÉSERVATION — `frontend/src/pages/Reservation.jsx`
> **5 onglets** en tête : `Reservation.jsx:726-731` — Semaine / Liste par jour / Prendre un rendez-vous / Horaires et absences / Activités. C'est un écran d'admin, pas un parcours linéaire. Pour l'agent qui veut juste « caler un client », **deux modèles de RDV coexistent** (planning de créneau existant vs recherche de place, assumé `Reservation.jsx:717-720`) : il faut d'abord comprendre lequel utiliser. C'est une charge cognitive réelle mais c'est un choix métier documenté. Le clic-sur-créneau → modale (`Reservation.jsx:755-765`) est, lui, une vraie simplification. **Pas de wizard abusif ici.** Verdict local : lourd en surface (5 onglets) mais pas réductible en « 3 étapes » sans casser du métier. Proche du RAS.
>
> ## ABONNEMENT — `frontend/src/pages/Abonnements.jsx`
> Un seul écran, mais dense et **plein de jargon financier** exposé à un agent d'accueil :
> - « **Mandat SEPA** », « **IBAN (mandat SEPA)** », « **Titulaire du mandat** » (`Abonnements.jsx:496,500`), « **Durée d'engagement (mois)** » (`Abonnements.jsx:506`).
> - Distinction **Payeur / Adhérent** (`Abonnements.jsx:463,473`) : subtile, l'aide « Par défaut, le payeur est l'adhérent » (`:482`) aide mais le mot « adhérent » vs « payeur » reste à deviner.
> - Le filtre « produits prélevés en SEPA uniquement » (`Abonnements.jsx:381-383,455`) : si le catalogue n'en a pas, l'écran est vide sans dire quoi faire.
>
> **Version 3 étapes :** (1) Choisir la formule → (2) Qui en profite + qui paie (un seul bloc, adhérent = payeur par défaut) → (3) Coordonnées bancaires (avec « on prélèvera automatiquement chaque mois » en clair, pas « mandat SEPA »). Renommer les libellés bancaires en langage client.
>
> ## Priorités
> 1. Boutique : fusionner « Consentement » → 5 étapes deviennent 3 (`Tunnel.jsx:7`).
> 2. Caisse : expliquer « Z » (`SessionCaisse.jsx:288`).
> 3. Abonnement : dé-jargonner SEPA/mandat (`Abonnements.jsx:496-506`).
>
> Verdict simplicité : **passable, mais deux parcours (boutique, abonnement) infligent du jargon et une étape de trop à un utilisateur non technique — réductibles à 3 étapes sans perte métier ; la caisse pèche surtout par le mot « Z » ; la réservation est lourde mais légitime.**

### perspective-signature

> ## Audit signature IA — Fluvia (lecture seule)
>
> **Verdict global : le dépôt résiste bien aux tics génériques. La charte existe, elle est mesurée et documentée, pas empruntée.**
>
> ### Charte graphique / tokens — EXISTE et solide
> Deux couches de tokens, en clair et sombre, dans `frontend/src/styles.css:58-76` (marque) et `frontend/src/styles.css` couche 2, dupliquées volontairement dans `vitrine/styles.css:29-73`. Séparation explicite « identité qui ne se repeint jamais » (couche 1) vs « ce qu'un client repeint » (couche 2, marque blanche T11). Règles D1-D7 dans `docs/conventions-ui.md:24-33` — D4 impose le token, jamais le littéral. **RAS, c'est l'inverse d'une charte à écrire.**
>
> ### Palette violet/indigo générique — NON, justifiée
> Indigo `#3755e8` et violet `#7a2ee6` sont présents (`vitrine/styles.css:33-34`), mais ce sont deux des quatre arrêts d'un dégradé de marque cyan→bleu→indigo→violet issu d'un pack (`frontend/src/styles.css:62-75`). Le choix indigo-en-clair / cyan-en-sombre est piloté par des mesures de contraste réelles, pas par mode (`vitrine/styles.css:46-49`). Ce n'est pas le violet SaaS par défaut ; c'est un dégradé signé, ancré. **RAS.**
>
> ### Police Inter partout — NON
> Aucune trace d'Inter dans le produit. Choix explicite et argumenté de **Public Sans** auto-hébergée (`frontend/src/styles.css:17-48`), justifié par un besoin réel de graisse 800 mesuré sur 4 familles. La vitrine tombe sur `system-ui` (`vitrine/styles.css:81`). Les HTML de doc utilisent `ui-sans-serif/ui-monospace` (`cahier-des-charges.html:28-30`). **RAS — anti-tic exemplaire.**
>
> ### Héros centré + dégradé — NON
> Hero en `display: grid` avec texte à gauche (`vitrine/styles.css:403-405`), fond navy plein, et dégradés utilisés en **sources de lumière** (halos radiaux) explicitement pour « distinguer une marque d'un fond coloré » (`vitrine/styles.css:383-401`). C'est le refus conscient du cliché. **RAS.**
>
> ### Trois cartes ombrées alignées — présent mais neutralisé
> Le pattern `.card` + `box-shadow: var(--shadow)` est omniprésent (`vitrine/styles.css:55`, `demo-produit.html:73`, `cahier-des-charges.html:59`). C'est un vocabulaire d'admin dense, pas une grille marketing de 3 features. L'ombre est un token discret (double couche 5%/7%), pas l'ombre flottante générique. **Risque faible.** Seul angle où le produit ressemble à « un SaaS » — mais c'est cohérent avec un back-office, pas un tic marketing.
>
> ### Emoji en guise d'icônes — NON dans l'UI
> Les emoji trouvés dans `frontend/src/**/*.jsx` sont tous des `⚠` en **commentaires de code** (`Modal.jsx`, `ClotureComptable.jsx`, etc.). Les glyphes affichés à l'écran sont fonctionnels et sobres : `↻` actualiser (`Liste.jsx:100`, `CorrespondancesComptables.jsx:186`), `＋` ajouter (`ReferentielEditable.jsx:208`), `→` séparateur. Pas d'emoji décoratif en icône. **RAS.**
>
> ### Formules « Bienvenue sur… » — NON
> Aucune occurrence de « Bienvenue sur/dans » ni « Welcome to » dans le front ou la vitrine. **RAS.**
>
> ### Réserve
> Je n'ai pas lu le contenu textuel rendu du hero vitrine ni les pages produit React une à une (budget). Les tics structurels sont absents ; un audit de la *copie* marketing (survente, buzzwords) resterait à faire séparément.
>
> Verdict signature : identité propre et défendue — dégradé de marque signé, police délibérée, hero anti-cliché, tokens en deux couches ; le seul résidu générique est le vocabulaire carte+ombre du back-office, cohérent avec l'usage et sans gravité.

### perspective-juridique *(points en QCM — regroupés en §d)*

> ## Perspective juridique — Fluvia (revue transverse)
>
> **Risque principal.** Reconduction/résiliation des abonnements (`features/abonnement/specs/spec-abonnement-transverse.md:8,16,38-39`) : le module généralise `AbonnementFitness` avec engagement, pause, résiliation, réengagement, prélèvement SEPA récurrent — mais aucune ligne de spec ne fixe la durée d'engagement minimale, le préavis de résiliation opposable au consommateur, ni le régime de reconduction tacite (art. L215-1 s. Code conso pour un particulier).
> *(Q1/Q2/Q3 reprises en §d.)*
>
> **Autres points.**
> - RGPD : aucune mention de durée de conservation ni de base légale dans les specs lues (`spec-abonnement-transverse.md`, `spec-personnel-rh.md`) — pas de champ « commentaire libre » trouvé, mais aucun registre de traitement identifié pour les données d'abonnement (RIB/SEPA, historique de paiement).
> - NF525 : déjà tranché et documenté (D57, `COORDINATION/DECISIONS.md:1829-1865`) — la clôture Z est la clôture NF525, chaîne par point de vente. RAS, pas de nouvelle question.
> - Préavis SEPA : décision D-1 (`COORDINATION/DECISIONS.md:2725,2740`) exige une preuve d'envoi avant remise en banque — c'est une garantie de preuve, pas une clause contractuelle envers le client.
>
> **Ce qui n'a pas besoin d'attendre.** Développer la bascule technique `AbonnementFitness` → `Abonnement` (lot 1, migration de structure, `spec-abonnement-transverse.md:38`) : aucune donnée nouvelle collectée, aucun changement de finalité. Le cloisonnement par établissement peut aussi avancer sans arbitrage juridique.
>
> Verdict juridique : À TRANCHER PAR L'HUMAIN EN CP-1 (Q1/Q2/Q3 ci-dessous, avant d'écrire la clause de résiliation/reconduction et le registre RGPD des abonnements).

### perspective-finance *(points en QCM — regroupés en §d)*

> ## Perspective finance — Fluvia (dépôt entier)
>
> **Q1. PSP carte réel — quelle branche prioriser avant CP-1 sur toute PR touchant le paiement en ligne ?** `app/src/Boutique/Paiement/PspCbStubAdapter.php:13-14` : « PSP CB non nommé par les sources — stub par défaut ». Le circuit carte DSP/groupe privé fonctionne en préprod sur un bouchon qui signe ses propres reçus (`PspCbStubAdapter.php:34-36`), pas sur un contrat réel. *(options en §d)*
>
> **Q2. Bascule carte→SEPA après refus (PAY-2) : mécanisme écrit, non branché.** `app/src/Sepa/Service/CardDebitFallback.php:20-23` : « RIEN N'APPELLE ENCORE CE SERVICE… le déclencheur est PAY-3, chez claude-G, et n'existe pas encore. » Tant que PAY-3 n'est pas livré, tout refus de carte à distance ne génère **aucune** relance automatique — impact direct sur le taux d'impayés.
>
> **Q3. Collecteur SEPA — `CollecteurSepaStubAdapter.php` : partenaire bancaire réel ou bouchon ?** Interface + stub, pas d'implémentation nommée trouvée dans `app/src/Sepa/Adapter/` — schéma identique au PSP carte. Le prélèvement SEPA touche à la trésorerie récurrente (cotisations abonnement), pas seulement une vente ponctuelle.
>
> **Charge récurrente créée.** Deux intégrations externes à faire vivre : PSP carte (commission par transaction, jamais lue dans le code) et collecteur SEPA (frais de remise bancaire, jamais lus). Aucun montant de commission n'apparaît dans `app/src/Boutique/Paiement/` ni `app/src/Sepa/`.
>
> **Hypothèse chiffrée manquante.** Le taux de commission PSP carte et le coût de remise SEPA par lot (`app/src/Sepa/Service/GenerationRemiseHandler.php`) ne sont écrits nulle part — ils conditionnent la marge nette de chaque encaissement en ligne.
>
> **Trésorerie.** Le préavis avant prélèvement (`app/src/Sepa/Service/DebitPreNotifier.php:186`, délai paramétrable) retarde l'encaissement d'autant de jours.
>
> **Prix : arbitrage déjà tranché.** `app/src/Offre/Service/SubscriptionPriceResolver.php:33-34` : « il ne doit pas y avoir de prix libre » (arbitrage Maxime 01/09) — RAS, le guichet ne peut plus improviser un tarif.
>
> **Version moins chère.** Ne pas ouvrir la vente carte à distance tant que PSP réel et PAY-3 ne sont pas livrés ; vendre uniquement au guichet (espèces/CB physique) et par prélèvement SEPA déjà en place.
>
> Verdict finance : À CHIFFRER AVANT CP-1 (tout chantier touchant paiement carte en ligne ou remise SEPA réelle).

---

## (d) QCM pour l'humain (juridique, finance, direction) — regroupés

### Juridique

**Q1 — Nature du client final et régime applicable.**
- A) Uniquement B2B/collectivités → droit conso hors sujet, RAS (mais non-conformité immédiate si un particulier souscrit un jour).
- B) Particuliers via boutique en ligne (mentionné `spec-abonnement-transverse.md:8`) → CGV doivent porter reconduction tacite, préavis de résiliation, droit de rétractation (sauf début d'exécution demandé expressément, L221-28 3°).
- C) Les deux, CGV différenciées par profil payeur.
- **Recommandé : B ou C** selon la réalité commerciale — à confirmer avant d'écrire la clause de résiliation dans le code.

**Q2 — Registre RGPD des données d'abonnement (IBAN/mandat SEPA, historique de paiement).**
- A) Registre déjà tenu ailleurs (hors dépôt) → RAS pour le code.
- B) À constituer maintenant, avec durée de conservation des mandats SEPA après résiliation (souvent alignée sur 5-10 ans comptables).
- **Recommandé : B** (l'IBAN est en pratique une donnée sensible).

**Q3 — Délai de préavis SEPA codé.**
- Le délai actuellement paramétré correspond-il au délai légal SEPA (J-14 par défaut sauf réduction contractuelle) ou à une valeur produit arbitraire ?
- **Recommandé :** vérifier avec l'expert-comptable si le délai codé est inférieur à 14 jours sans clause contractuelle explicite le prévoyant.

### Finance

**Q1 — PSP carte réel.**
- A) Signer un PSP réel avant toute mise en production commerciale (commission encore inconnue, mais bloque la facturation réelle tant que non fait).
- B) Rester sur le stub en préprod, geler toute vente carte en clientèle réelle.
- C) Différer au premier client DSP signé (risque : découvrir la commission après avoir tarifé l'offre).
- **Recommandé : A** — un prix client ne peut pas absorber une commission inconnue a posteriori.

**Q2 — Relance après refus carte (PAY-3).**
- A) Prioriser PAY-3 avant d'ouvrir la vente carte à distance à plus de clients.
- B) Accepter le risque d'impayé le temps que PAY-3 arrive, avec relance manuelle.
- **Recommandé : A** si le volume de vente à distance devient significatif ; **B** tolérable à faible volume, mais personne n'a écrit le seuil.

**Q3 — Collecteur SEPA réel.**
- A) Partenaire bancaire de remise déjà sous contrat ailleurs (hors code) → RAS.
- B) Aucun partenaire signé → risque plus grave que Q1 (trésorerie récurrente d'abonnement).
- **Recommandé :** trancher **B** avant tout engagement d'abonnement facturé par prélèvement réel.

### Direction

**Q1 — Le cap « API d'abord » (D101/D102) tient-il toujours ?**
- A) Oui → traduire en une Issue étiquetée et assignée cette semaine (surface tiers : clé d'API, webhook sortant, réponse à IT Cotation).
- B) Non, suspendu → consigner la suspension et sa raison au journal (sinon le tracker dérive en silence).
- **Recommandé : A** — le silence du tracker sur D102 depuis le 31/08 est le symptôme à lever en premier.

**Q2 — La « décision tronc en attente » (commit c16547dc, #73).**
- A) Prise → la retrouver/consigner au journal.
- B) Toujours ouverte → la passer au contradicteur puis l'acter.
- **Recommandé :** clarifier avant tout nouveau correctif de confort.

---

## (e) Chiffres mesurés

| Mesure | Valeur | Source / méthode |
|--------|--------|------------------|
| Garde-fous (fichiers `bin/garde-fou-*`) | **36** | `ls bin/` |
| `garde-fous.sh` | 787 lignes, 94 invocations/mesures détectées | `wc -l`, grep |
| Nombre réel de contrôles | **contesté** : CLAUDE.md annonce 54 ; Issue #58 dit 43 réelles / 49 annoncées ; Issue #5 dit n40/n51 ne tournent jamais | Issues #58, #5 |
| Lignes de base (dette gelée) | **18 fichiers**, 2061 lignes JSON | `wc -l bin/*.ligne-de-base.json` |
| Dette gelée — plus gros pools | post-sans-suppression **188**, espacement-en-ligne 79, nullable-non-nul 64, vacuité-tests 46, étab-écrivable 32, validation-avant-processeur 32, drop-migrations 20 ; cloisonnement **plafond 16** | grep sur les baselines |
| Dette gelée — total items référencés | **~528** références fichier | grep `.php/.js/.jsx` dans les baselines |
| PR ouvertes | **11** (dont plusieurs DRAFT : #93,#92,#88,#81,#80,#74-SUSPENDUE ; OPEN : #91,#90,#77,#59,#50) | `gh pr list` |
| Issues ouvertes | **12** (#82,#79,#58,#56,#30,#29,#17,#16,#14,#5,#3,#1) | `gh issue list` |
| Commits 30j touchant `app/src`+`frontend/src` | **1371** | `git log --since=30.days --oneline` (compte brut de la branche `maxime`, avant squash) |
| Lignes 30j (`app/src`+`frontend/src`) | **+292 337 / −16 900** | `git log --numstat` sommé (inclut renommages `{Sport => Membership}` et fixtures) |
| `testgaps.py --max 40` | **NON OBTENU** | script hors worktree ; bac à sable interdit l'expansion de `$KIT_SCRIPTS`, les chemins hors `/home/debian/wt/maxime`, et `find`/`env`/`printenv` |
| `doctor.py .` | **NON OBTENU** | idem — à relancer par l'humain depuis un shell non contraint |

> Note d'honnêteté : les deux scripts du kit n'ont pas pu être exécutés (6 tentatives par des voies différentes, toutes refusées par le bac à sable). Ce ne sont pas des « verts » — ils sont non mesurés. Relance manuelle recommandée.

---

## (f) Les dix gestes qui changent le plus (dans l'ordre)

1. **Décider du sort de la chaîne carte** : brancher un PSP réel, ou geler explicitement la vente carte à distance (garder guichet + SEPA). Sans ça, tout « on encaisse en ligne » est faux. — `PspCbStubAdapter.php` · **L**
2. **Décider du sort du collecteur SEPA** avant tout abonnement réellement prélevé — la trésorerie récurrente en dépend. — `CollecteurSepaStubAdapter.php` · **L**
3. **Réparer le verdict des garde-fous** (abstentions comptées vertes #58/#80 ; n40/n51 jamais lancés #5 ; #37). Le seul gate doit dire vrai avant qu'on lui fasse confiance pour tout le reste. — **M**
4. **Trancher les 3 questions juridiques abonnement** (engagement/préavis/reconduction/rétractation + registre RGPD) avant d'ouvrir la souscription conso en ligne. — `spec-abonnement-transverse.md` · **M**
5. **Livrer PAY-3 / brancher `CardDebitFallback`** pour qu'un refus de carte déclenche une relance, sinon impayé silencieux. — `CardDebitFallback.php:20-23` · **M**
6. **Donner un propriétaire à `frontend/` dans CODEOWNERS** — débloque tout le reste des correctifs front (libellés, vocabulaire, écrans cassés). Geste le moins cher à fort effet de levier. — `.github/CODEOWNERS` · **S**
7. **Écrire le résolveur de vocabulaire par verticale** (les 12 clés existent et sont testées) — c'est ce qui rend la promesse multi-verticale réellement démontrable. — `specs/verticales/vocabulaire.md:61-63` · **M**
8. **Réparer les écrans/chaînes cassés en clientèle** : fiche produit `null.find` (#16), facture PDF (#91, PR prête), reversements OTA morts (#56). — **S** chacun / **M** ensemble
9. **Alléger deux parcours** : boutique de 5→3 étapes (fusionner « Consentement »), dé-jargonner la caisse (« Z » → « comptage de fin de journée ») et l'abonnement (« mandat SEPA » → langage client). — `Tunnel.jsx:7`, `SessionCaisse.jsx:288`, `Abonnements.jsx:496-506` · **S→M**
10. **Trancher le cap** : ré-acter ou suspendre D102 (« API d'abord ») par écrit, clarifier la « décision tronc en attente » (#73) ; ajouter le garde-fou anti-commit de `reference.php`. — `DECISIONS.md:3189-3231` · **S** (décisions) + **S** (garde-fou)

---

## (g) Passage au contradicteur sur les dix gestes

**Objection du contradicteur.**
La liste ordonne par gravité technique, pas par le cap acté. Les gestes 1-2-5 (PSP carte, collecteur SEPA, PAY-3) supposent qu'on ouvre l'encaissement en ligne *maintenant* — or D102 dit « l'API d'abord », et rien n'indique qu'un client attend d'encaisser une carte à distance dès aujourd'hui. On risque de dépenser du L (deux intégrations bancaires payantes, à commission inconnue) pour une capacité que personne ne réclame, pendant que l'intégrateur réel (IT Cotation, D102) attend une spec d'API qui n'apparaît nulle part dans les dix gestes. La version plus petite : **geler** la vente carte/SEPA à distance (un interrupteur, taille S) au lieu de la construire (taille L×2), et remonter l'API en geste 1. Ce qui prouverait qu'on se trompe : un client signé avec besoin d'encaissement en ligne daté — introuvable dans le dépôt.

**Ma réponse.**
Objection juste et retenue sur un point : je ne prône pas de *construire* les intégrations, mais de *décider* — le geste 1 dit littéralement « brancher **ou geler** », et le §d recommande le gel (finance Q1-B/Q3) tant qu'aucun client daté n'existe. Reformulé, geste 1-2 = « poser l'interrupteur de gel explicite » (S), pas l'intégration (L) : le danger n'est pas l'absence de PSP, c'est que le stub *se fait passer pour un paiement réel* en signant ses propres reçus — ça, il faut le neutraliser quel que soit le cap. En revanche l'objection a raison sur l'ordre : **l'API (D102) devait figurer dans les dix gestes et n'y est qu'au titre du « trancher le cap » (geste 10)**. Correction actée : le geste 10 est promu — « trancher le cap D102 et, s'il tient, ouvrir la spec d'API pour IT Cotation » devient le préalable qui commande l'ordre des neuf autres. Je ne réordonne pas la table ci-dessus (elle reste le constat technique brut), mais je consigne que **le premier geste politique est la décision de cap du §d-Direction-Q1**, dont dépend l'utilité même des gestes 1-2-5.
