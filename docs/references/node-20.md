# Node.js 20 — fiche de référence

- Version du projet : v20.20.2 (Node de l'hôte ; hors verrou)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://nodejs.org/docs/latest-v20.x/api/test.html (doc v20.20.2, lu le 2026-10-07)
  - [officielle] https://nodejs.org/docs/latest-v20.x/api/assert.html (doc v20.20.2, lu le 2026-10-07)
  - [officielle] https://nodejs.org/en/about/previous-releases (lu le 2026-10-07)
- Questions couvertes : `node --test`, `node:test`, `node:assert` en Node 20 — stabilité, code de retour exploitable par un script shell.

## À utiliser

### `node:test` et `node --test`
- Stabilité **2 – Stable** depuis v20.0.0 (« le lanceur de tests est désormais stable »). [officielle] test.html (v20)
- Fichiers trouvés par défaut (sans argument) : `test.js|cjs|mjs`, noms `test-*.…`, noms `*.test.…`, `*-test.…`, `*_test.…`, et tout `.js/.cjs/.mjs` d'un dossier `test` ; `node_modules` ignoré sauf s'il est cité. [officielle] test.html (v20)
- Fichiers/dossiers explicites : `node --test a.test.mjs b.test.mjs dossier/`. [officielle] test.html (v20)
- **Code de sortie** : si au moins un test échoue, le code de sortie du processus vaut **1** ; 0 si tout passe → exploitable par `set -e` ou `if node --test …; then`. [officielle] test.html (v20)
- Rapport : `spec` si stdout est un terminal, `tap` sinon (cas d'un script / CI). [officielle] test.html (v20)
- Stables : `test()`, `describe()`/`it()`, `only`, `mock.fn()`. EXPÉRIMENTAUX en v20 : mode `--watch`, couverture `--experimental-test-coverage`, `MockTimers`. [officielle] test.html (v20)

### `node:assert`
- Stabilité **2 – Stable**. [officielle] assert.html (v20)
- Utiliser le mode STRICT : `import assert from 'node:assert/strict'` (ou `{ strict as assert }`) — `equal`/`deepEqual` s'y comportent comme `strictEqual`/`deepStrictEqual`. Le mode hérité (`==`) est déprécié et donne des résultats surprenants. [officielle] assert.html (v20)
- `assert.rejects()` / `assert.doesNotReject()` renvoient une promesse : **`await` obligatoire**, sinon l'échec n'est pas vu par le test. [officielle] assert.html (v20)

## Obsolète ou retiré dans cette version — ne pas utiliser

| Ancien | Remplacé par | Depuis | Source |
|---|---|---|---|
| assertions en mode hérité (`node:assert` non strict) | `node:assert/strict` | déprécié (v20) | [officielle] assert.html |

## Sécurité (confirmé sur la doc officielle)
- **Node 20 est en FIN DE VIE (statut « EOL »)** ; v20.20.2 est la dernière version publiée de la branche (dernière mise à jour : 24/03/2026). Les branches 22 (« Jod ») et 24 (« Krypton ») sont en statut LTS. [officielle] previous-releases (lu le 2026-10-07)
  → aucune nouvelle version v20 n'est attendue (la page ne formule pas mot pour mot la conséquence « plus de correctif de sécurité ») : à signaler à l'humain ; le choix de version est hors périmètre du documentaliste.

## Pièges connus
- Oublier `await` devant `assert.rejects()` = test faussement vert. [officielle] assert.html
- Passer un motif glob en argument de `--test` : prise en charge en v20 non vérifiée → lister les fichiers explicitement (À VÉRIFIER).
- Les fonctions expérimentales (couverture, minuteurs simulés) peuvent changer entre correctifs.

## À confirmer par le plan
- Si le script shell doit distinguer « échec de test » et « erreur de chargement » : seul le code 1 en cas d'échec est documenté ici ; les autres codes ne sont pas lus.
