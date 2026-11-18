# Suivi d'implémentation — nf525-alerte-scellement

**État :** build <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/nf525-alerte-scellement (partie de feature/nf525-ordre-cles, #369)
**Spec :** features/nf525-alerte-scellement/specs/spec-nf525-alerte-scellement.md
**Plan :** features/nf525-alerte-scellement/plans/plan-nf525-alerte-scellement.md

## Checkpoints

- [x] **CP-1** — spec validée par Maxime le 18/11/2026
- [x] **CP-2** — plan validé par Maxime le 18/11/2026 (« Reprend »), avec les défauts Q-2 à Q-5
- [ ] **CP-3** — revue avant merge (date + qui)

## Étapes (reprises du plan)

<!-- Coche au fur et à mesure. Ne modifie jamais une étape déjà « fait » : crée une nouvelle étape. -->

- [x] Étape 1 — vocabulaire chaîne et cause (G-1)
- [x] Étape 2 — refus porteur de cause, détail, message générique (G-8, G-1)
- [x] Étape 3 — table, entité, index unique, migration (G-1)
- [x] Étape 4 — écriture sur la seconde connexion (G-1, G-2)
- [x] Étape 5 — cloche à l'ouverture (G-4)
- [ ] Étape 6 — service d'enregistrement (G-1, G-2, G-4, G-7, G-8)
- [ ] Étape 7 — branchement des trois handlers (G-1, G-2, G-7, G-8)
- [ ] Étape 8 — quarantaine hors ligne (G-8, G-3)
- [ ] Étape 9 — vérification rompue / intacte (G-3, G-7)
- [ ] Étape 10 — API établissement (G-5)
- [ ] Étape 11 — API éditeur et note (G-6)
- [ ] Étape 12 — frontal : client et fonctions pures (G-5, G-6)
- [ ] Étape 13 — frontal : écran administrateur (G-5, G-4)
- [ ] Étape 14 — frontal : écran éditeur (G-6)
- [ ] Étape 15 — caisse épinglée (G-8)
- [ ] Étape 16 — documentation, décision, clôture

## Journal de Session

<!-- Décisions prises en cours de route, écarts constatés, changements d'avis. Append-only. -->
- 18/11/2026 — Étapes 1-2 faites. Suite ciblée 544 -> 554 tests verts (+10 : SealChainRefTest 5, SealingRefusedExceptionTest 5) ; `tests/Vente` entier 291 verts. Écarts : (a) le constructeur de `SealingRefusedException` devient `(SealRefusalCause, string $technicalDetail, ?string $reference = null)` ; ajout de `cause()` et `reference()` ; (b) causes `key_regression` pour les deux branches d'ordre (clé active quittée, dernier maillon en régression) ; (c) les chemins de `test-stack.sh run` sont relatifs à `app/` (`tests/...`). Garde-fous : 3 en échec pour PHP 8.3 de l'hôte (manifeste vs catalogue, filtres déclarés, appels du frontal dans le vide).
- 18/11/2026 — Étape 3 faite. `Nf525Alert` + `Nf525AlertSchemaListener` + enums `SealAlertStatus`/`SealAlertOrigin` (Platform/Nf525) + `Version20261118130000` (noms d'index/FK repris du `--dump-sql` de Doctrine). `Nf525AlertSchemaTest` 11 tests verts ; `tests/Platform` 278 verts. Dérive : migrations rejouées sur base vierge, aucune ligne nf525 dans `schema:update --dump-sql` ; `down` vérifié. `verifier-derive-schema.sh` plante ici en mémoire (128M, il ne monte pas `zz-memory.ini`) : mesure refaite à la main avec le même montage. Garde-fous : 3 en échec attendus (PHP 8.3 hôte).
- 18/11/2026 — Étape 3, revue architecte-donnees : FK `establishment_id` volontairement RESTRICT (documentée dans migration et entité, épinglée par un test : suppression d'un établissement lié à une alerte résolue refusée) ; `unique: true` retiré de l'id (aucune dérive). Mesure de dérive faite à la main : `verifier-derive-schema.sh` est plafonné à 128 Mo ici (il ne monte pas `zz-memory.ini`) ; mêmes commandes rejouées avec le montage, aucune ligne nf525 dans le diff.
- 18/11/2026 — Étape 4 faite. `Nf525AlertConnection` (seconde connexion, `transactional()` : SET SESSION délai, rollBack explicite, close en finally, délai configurable défaut 3 s), `Nf525AlertStore` (`open`, `resolve`, point d'extension `afterOpened` pour le notifieur de l'étape 5), `OpenedAlert`. `Nf525AlertStoreTest` 14 verts ; `tests/Platform` 293 verts. Écarts : (a) le « rollBack explicite » est prouvé par le compteur global `COM_ROLLBACK` (information_schema.INNODB_TRX exige PROCESS, refusé à l'utilisateur applicatif ; `close()` remet le niveau de transaction à 0, donc rien d'observable côté DBAL) ; (b) dates écrites avec `new \DateTimeImmutable()` sans conversion, comme l'ORM pour `datetime_immutable` (pas de convention UTC dans les entités). Garde-fous : 3 en échec attendus (PHP 8.3 hôte).
- 18/11/2026 — Étape 4, retours de relecture appliqués. Listener injecté (`Nf525AlertOpenedListener`, Store `final`, plus de `afterOpened`) ; ouverture de connexion : erreur relancée en `RuntimeException` générique sans `previous` (classe/code/message journalisés, mot de passe absent de `(string) $e`, test + témoin `getParams()`) ; garde de durée (`$budget`) avant chaque liaison, cumul résiduel documenté ; fabrique de connexion et générateur de référence injectables. Le test `COM_ROLLBACK` est retiré (close() suffisait à le faire monter) : le `rollBack()` avant `close()` est prouvé par un double (`Nf525AlertDoublesTest`). Mutations mesurées : M1 (retrait `rollBack`) -> 3 tests rouges ; M2 (retrait `close`) -> 5 rouges dont la rafale de 20 `open()` (Threads_connected, connexions gardées vivantes) ; M5 (`WHERE chain_key`) -> 1 rouge (alerte résolue voisine non comptée) ; EXPLAIN épingle l'index. Course réelle B non commitée non testée (pas de processus fils) : second tour et collisions de référence couverts par doubles.
- 18/11/2026 — Étape 5 faite. Règle `nf525.chain_blocked` + champ `viaBus` (filtré dans `admittedEvents()`, « huit »), `Nf525AlertNotifier` (INSERT paramétré sur la connexion de l'alerte, résolveur existant sur la principale en lecture). `Nf525AlertBellTest` 8 verts, `NotificationFromEventTest` vert, `tests/Platform` 314 verts ; événements orphelins OK, 3 échecs attendus (PHP 8.3 hôte). Écart : le câblage autowiring Store→Notifier n'est pas observable avant l'étape 6 (le Store, sans consommateur, est retiré du conteneur compilé) ; à constater à l'étape 6, sinon déclarer l'argument dans `services.yaml`. Les tests construisent le Store à la main avec le notifieur. Test « panne » en base réelle : le notifieur écrit ses lignes puis le listener lève, alerte et cloche disparaissent.
- 18/11/2026 — Étape 5, retours de relecture. Une panne de la cloche annule l'alerte : choix gardé, conforme à D-D (« cloche à l'ouverture ⇔ alerte ouverte »). Le refus suivant retente l'ouverture et la cloche, au lieu d'une alerte ouverte que personne n'aurait vue ; le 409 part alors sans référence. Établissements dédoublonnés dans le notifieur ; test à deux établissements avec un administrateur propre à chacun (mutation « résolution une seule fois » mesurée : 1 test rouge, restauré par `cmp`).


## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->
- 18/11/2026 — Aiguillage SDD complet. Chercheur : refs/faits-chercheur.md. Arbitrages de Maxime : alerte dédiée par chaîne + cloche à l'ouverture ; admin (securite.gerer) + éditeur ; tous les établissements du profil ; résolution automatique au premier scellement réussi + note éditeur ; vérification rompue = même alerte ; message 409 générique. Spec en attente de CP-1.
- 18/11/2026 — Plan (16 étapes) : seconde connexion DBAL pour que l'alerte survive au rollback. Q-1 tranchée par Maxime : une alerte ouverte par une vérification ne se ferme qu'à une vérification intacte (spec G-7 amendée). Défauts retenus sans objection, à confirmer en CP-2 : Q-2 cause `chain_broken` ; Q-3 texte de cloche proposé par l'architecte ; Q-4 l'administrateur ne voit pas la note de l'éditeur ; Q-5 une archive en défaut ouvre l'alerte de la chaîne vivante (fermée à la prochaine vérification intacte). Fiches : l'agent `documentaliste` du plugin n'est pas chargé en session cloud ; un agent généraliste applique ses règles (doc officielle seulement, docs/references/ seulement).
