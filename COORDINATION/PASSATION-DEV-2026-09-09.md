# PASSATION — reprise du dev de Fluvia (09/09/2026)

Ce fichier est le point d'entrée pour un dev (et son assistant) qui reprend le travail.
Le récap illustré des 4 derniers jours est le HTML `recap-fluvia-4jours.html` (fourni à Maxime).

---

## 1. Où et comment on travaille

- **TOUT se fait sur le VPS**, via `ssh billetterie`. Jamais depuis une machine locale (ce n'est pas
  un dépôt git — un push local échoue).
- Worktrees des sessions : `/home/debian/wt/*` (chacun sa branche). Clone de déploiement :
  `/home/debian/billetterie`. Dépôt nu VPS : `/home/debian/billetterie.git` (remote `bare`).
- Remote `origin` = GitHub `https://github.com/Mdesb/fluvia` (dans le clone et les worktrees).

## 2. Git — état actuel et décision en attente

- **Tronc actuel = `origin/main` (GitHub).** TOUT le travail des 4 derniers jours y est, et c'est ce
  qui est servi en prod (smartaccess.hector-conseil.com). Chaque déploiement vérifie que le frontal ET
  le PHP servent le même commit.
- **CI GitHub Actions = MORTE** (compte encore restreint : `users/Mdesb` et `repos/Mdesb/fluvia` en
  404 anonyme, `actions/runs` = 0). Donc **aucune PR ne passe au vert par la CI.** Le vrai garde-fou
  est le **pre-commit LOCAL** (~55 contrôles, très stricts — ils bloquent au commit).
- **Intégration :** `gh pr view <n> --json isDraft` → `gh pr ready <n>` si brouillon →
  `gh pr merge <n> --squash --admin` (le `--admin` contourne la protection de `main`, dont les checks
  morts). ⚠ Le classifier auto-mode peut bloquer un push/merge direct sur `main` selon la session ;
  demander la permission à Maxime dans SA session (un mandat relayé ne lève pas un blocage local).
- **Fermer une issue :** `Closes #N` en **anglais** dans le corps de PR. « Ferme #N » (français) ne
  ferme RIEN (GitHub n'honore que Closes/Fixes/Resolves).

### ⚠ DÉCISION DE MAXIME (09/09), NON ENCORE EXÉCUTÉE — à faire en premier, avec lui

Maxime veut **repasser le tronc sur le dépôt nu VPS `bare`** et abandonner GitHub comme tronc.
Ce n'est PAS fait (migration lourde + irréversible-ish, laissée au dev qui a le budget et le temps).

**Avant d'exécuter, à savoir (je l'ai signalé à Maxime) :**
- `origin/main` (GitHub, tout le travail récent) et `bare/main` (dormant depuis le 07/09) sont sur des
  **histoires INCOMPATIBLES** (~2400 commits de chaque côté, réécriture lors du passage public).
- Ça **ne répare pas** « git ne marche pas » : le seul truc cassé est la CI GitHub, et `bare` n'a pas
  de CI non plus (le pre-commit local reste le gate). Le `git push` marche déjà.
- On **perd le miroir public GitHub** que Maxime avait choisi le 06/09.

**Étapes si on le fait :**
1. Amener le CODE actuel (`origin/main`) sur `bare/main` : `git push bare origin/main:main --force`
   (remplace l'histoire dormante de bare par la ligne de travail actuelle).
2. Repointer le déploiement : `infra/deploy-preprod.sh` compare `HEAD` à `origin/main` — le faire
   comparer à `bare/main` (et le clone tracker `bare`).
3. Prévenir les ~14 sessions : pousser sur `bare`, plus sur `origin`.
4. GitHub devient un miroir optionnel (ou abandonné).

→ **Recommandation :** reconfirmer avec Maxime que le gain vaut la perte du miroir + la migration,
puisque rien de fonctionnel n'est réparé par ce changement.

## 3. Tester et déployer

- **Tests** (dans un worktree) : `./infra/test-stack.sh up abonnement` (monte réseau+base+schéma —
  schéma créé PAR MAPPING, pas par migrations), puis `./infra/test-stack.sh run abonnement [chemin/test]`.
  Le déploiement retire les dépendances de dev (`composer --no-dev`) → si phpunit manque, relancer
  `composer install` (dev) dans le conteneur, ou `./infra/reinstaller-dev.sh` sur le clone.
  Un jeton = un worktree ; ne pas lancer deux `run` sur le même jeton (ils se corrompent la base).
- **Déployer** (depuis le clone) : `git checkout main && git merge --ff-only origin/main &&
  ./infra/deploy-preprod.sh`, puis `./infra/reinstaller-dev.sh`. Le script refuse de déployer si
  `HEAD != origin/main` ou si `git fetch` échoue.
- **Build frontend seul** (vérif rapide) : conteneur `node:20-alpine`, `npm run build` sur `frontend/`.
- `app/config/reference.php` **oscille** (régénéré par composer dev) : ne JAMAIS le commiter
  (`git restore app/config/reference.php` avant chaque `git add`, ne jamais `git add -A`).

## 4. Ce qui est fait

≈55 PR fusionnées sur 4 jours — détail dans `recap-fluvia-4jours.html`. En gros : facturation
électronique (Factur-X téléchargeable + émetteur, Phases 1 & 2), caisse (encaissement affiné en 4
itérations), abonnements (souscription en écran), nouveaux modules (Groupes, Personnel, occupation
réservation, régie), une vague de refontes modale→écran, page contact vitrine, et beaucoup de
correctifs. Côté infra : passage GitHub public + durcissement du déploiement.

## 5. Ce qui reste (priorités)

1. **Épopée abonnement — finir le FRONT du tunnel.** Branche **`feat/signature-scellee`** (NON mergée,
   backend prêt + testé) :
   - Livré sur la branche : module `App\Signature` (signature électronique **avancée, scellée** —
     empreinte du document + image manuscrite + faisceau opérateur/horodatage/IP, chaîne SHA-256 + HMAC,
     inaltérable au niveau ORM) ; entité `SubscriptionContract` (contrat gelé + signé) ;
     `SepaMandateSigner` ; **câblage dans `SouscrireAbonnementProcessor`** (la souscription signe et
     scelle mandat + contrat) ; **migration** des tables `electronic_signature` + `subscription_contract`
     (`Version20260909143000`). Tests verts (unitaires + intégration bout-en-bout).
   - **Reste :** le front dans `frontend/src/pages/Abonnements.jsx` — brancher le composant
     `frontend/src/components/SignaturePad.jsx` (déjà sur la branche) pour capturer 2 signatures
     (`signatureMandat` / `signatureContrat`, base64), ajouter un champ **prorata** (le backend accepte
     déjà `montantPremiereEcheanceCentimes`), et l'**encaissement comptant** via la caisse ouverte (créer
     une `Vente` dans la `SessionCaisse` de l'opérateur — l'infra `Vente.session` existe ; réutiliser le
     flux caisse existant `creerVente({session})`). Puis merge + deploy.
   - ⚠ Avant deploy : générer **`SIGNATURE_SEAL_KEY`** dans `infra/.env.preprod` (comme
     `NF525_FACTURATION_SEAL_KEY`), sinon le conteneur ne démarre pas. Décision produit à confirmer :
     le libellé RÉGLEMENTAIRE exact du mandat SEPA et les clauses du contrat (le code met un texte
     factuel + un marqueur « à finaliser » — ne pas inventer de clauses juridiques).
2. **Décisions produit (Maxime) :** `stay`/séjours facturable ? (~19 €/mois/étab, absent des 17 options
   de plan) · **E-8** (le lien de confirmation d'inscription n'existe qu'en `sha256` → chaque prospect
   perdu).
3. **Caisse :** ticket PDF opposable (PR #50, #59), encaissement→comptes (PR #47).
4. **Garde-fous / CI :** #58 (abstentions comptées OK), #5 (contrôles jamais lancés), #30 ; réactiver
   la CI quand le compte GitHub revient.
5. **Config :** `MAILER_DSN=null` → aucun e-mail ne part (mot de passe oublié, invitations, relances).
6. **Issues ouvertes :** #14 (abonnements P1, en cours) · #16/#17 (fiche produit / page Modules, design)
   · #56 (reversements OTA, chaîne morte) · #29 (135 `catch` aveugles) · #1 (Engagement = module) ·
   #3 (protection de `main`). + reste facturation : téléchargement depuis la caisse, `valider-facturx.sh`
   en CI, multi-pays, transmission Chorus/PDP.

## 6. Coordination flotte

~14 sessions Claude tournent sur d'autres modules. Canal `@all` : `COORDINATION/MESSAGES.md` (le lire
avant de re-diagnostiquer). `COORDINATION/DECISIONS.md` = journal des arbitrages. Chaque session a
l'autorisation `--admin` per-session de Maxime pour SES propres PR.
