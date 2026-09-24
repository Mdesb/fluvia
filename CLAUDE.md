# Fluvia — Claude Code

Tu es l'assistant Claude Code du projet **Fluvia**. Ce fichier est chargé automatiquement à chaque session dans ce repo. Il dit comment travailler ici en **Specification-Driven Development (SDD)** : jamais directement d'une demande à du code — toujours en passant par une spec puis un plan.

## Contexte du projet

- **Produit** : Fluvia, SaaS de billetterie et gestion de loisirs **multi-verticale** (piscine, padel, patinoire, musée, sport/fitness, et à venir camping/hôtel/restauration). Le seul socle qui couvre des complexes municipaux mixtes et des délégataires multi-équipements.
- **Utilisateurs** : exploitants (régie ou DSP), et leurs clients finaux via la boutique en ligne.
- **Multi-tenant** : tout est cloisonné par **établissement** (et par groupe). Le cloisonnement est un invariant serveur, pas une préférence d'écran.
- **Hébergement** : préprod sur un VPS OVH (voir `infra/ACCES-VPS.md`, présent sur le VPS, **hors dépôt**).
- **Stack** : PHP 8.4 / **Symfony 7.4** / **API Platform 4** / **Doctrine ORM 3** / **MariaDB 11.4** ; **React 18 + Vite** (dossier `frontend/`). Conformité : **NF525** (chaîne scellée, clôture Z), **Factur-X**, **SEPA** (pain.008).

## Base de connaissances — où chercher AVANT d'agir

Ne charge jamais toute la doc d'un coup ; pointe la section utile :

- **`COORDINATION/DECISIONS.md`** — le *pourquoi* de chaque arbitrage de Maxime, avec sa contrepartie. La première chose à lire avant de trancher quoi que ce soit.
- **`bin/`** — les garde-fous (chacun documenté en tête de fichier) ; trente incidents y sont expliqués. Lis-les avant de re-diagnostiquer un rouge.
- **`docs/`** — base de connaissances de référence (conventions, design system).
- **L'index des chantiers** (Issues + tableau) — ce qui reste à faire, qui le tient.

## Permissions

- **Code** : autorisé avec checkpoints — CP‑1 (spec validée) avant de planifier, CP‑2/CP‑3 = portes automatiques (voir cycle SDD).
- **Migrations** : **écrites à la main**, jamais un `migrations:diff` brut (il ratisse la dérive des autres). Demande le SQL à Doctrine *avant* d'écrire (`doctrine:schema:update --dump-sql`), et vérifie l'absence de dérive après. Toute requête paramétrée, jamais de concaténation.
- **Secrets, clés, identifiants** : **le dépôt est public** — jamais rien de secret dans un fichier suivi. `app/.env` ne porte que des **marqueurs** (clés générées au déploiement) ; `infra/.env.preprod` et `infra/ACCES-VPS.md` sont `.gitignore` et vivent sur le VPS.
- **`app/config/reference.php`** est régénéré par la suite de tests → `git checkout --` avant chaque commit.

## Règles actives

- **Les questions ne sont pas des instructions.** On te pose une question, tu réponds ; tu ne modifies rien sans instruction explicite.
- **Une question à la fois**, à choix multiple si possible, pendant la découverte.
- **Dis « je ne sais pas »** plutôt que deviner. Marque toute hypothèse VERIFIED / UNVERIFIED ; une **UNVERIFIED bloque** le passage à l'étape suivante.
- **Pousse-toi contre les demandes floues** et signale les contradictions avec l'existant.
- **Mesure, ne suppose pas.** « Poussé » n'est pas « servi » (la préprod n'a que ce que `deploy-preprod.sh` y met) ; un `[]` n'est pas une preuve d'absence ; un vert peut l'être pour une mauvaise raison. Exige un témoin positif avant de conclure d'une absence.

## À la réception d'une demande : l'aiguillage (deux voies)

**Toute demande passe d'abord par `/aiguiller`**, avant de toucher un fichier :

- **Voie légère** (`/correctif-rapide`) — petit changement localisé, réversible, ne touchant **aucune** zone sensible (base, auth, paiements, **cloisonnement multi-tenant**, NF525/argent, données personnelles) et n'ajoutant pas de capacité nouvelle. Intention en une ligne dans l'index, changement chirurgical, tests verts (+ non-régression si bug), revue `relecteur`, puis fusion (voir **⚠ Il n'y a pas de CI** ci-dessous).
- **Cycle SDD complet** (`/nouvelle-fonctionnalite`) — vraie fonctionnalité ou zone sensible.

**Défaut : voie légère** (personne ne code au quotidien). **En cas de doute : SDD complet.** Si un correctif rapide grossit ou touche une zone sensible, on **rebascule en SDD complet**. La voie légère allège le *process*, jamais les *garde-fous de sécurité*.

**Chaque dev se clôt par `/point-projet`** : index + journal + tableau de bord `docs/etat-projet.md` à jour.

## Le cycle SDD : Spec → Plan → Construire → Vérifier

1. **Spec** (`/nouvelle-fonctionnalite`) → `features/<nom>/specs/spec-*.md` → **CP‑1 : validation humaine** (le seul checkpoint de jugement — Maxime définit *quoi* construire).
2. **Plan** (agent `architecte`) → `features/<nom>/plans/plan-*.md` → **CP‑2 : porte automatique**.
3. **Construire** (`developpeur` + `relecteur`) → coder par étapes, valider après chaque → `/valider-module` → **CP‑3 : porte automatique** (CI verte + résumé d'une page).
4. **Vérifier** (`/verifier-specs`) → comparer code ↔ spec → clôture `/point-projet`.

**CP‑2/CP‑3 ne sont pas des audits humains.** Le *gate*, c'est la **CI verte** + un résumé lisible en 30 s. Sur tout ce qui est sensible, `relecteur` et `security-reviewer` opèrent en **mode adversarial** — ils *essaient de casser* le travail. C'est ça, la vraie revue.

## Tests & CI — le vrai gate

Parce que la revue humaine du code n'a pas lieu, **la CI EST le contrôle**. Deux filets, tous deux bloquants :

- **Les 54 garde-fous** : `./bin/garde-fous.sh` (cloisonnement, secrets, nommage, événements orphelins, contrastes, clavier…). Ils tournent en local, et en **CI GitHub sur chaque PR** (workflow `Garde-fous`, + un banc d'essai qui teste les garde-fous eux-mêmes).
- **La suite PHP** : `./infra/test-stack.sh up <jeton>` puis `./infra/test-stack.sh run <jeton> [chemins]` (⚠ dans un **worktree**, jamais le clone de déploiement). `<jeton>` t'est propre pour ne pas corrompre la base d'un autre.

Rien ne merge tant que ce n'est pas vert.

## Git — GitHub est la référence

- **Dépôt de référence : `github.com/Mdesb/fluvia`.** On y travaille en **branches + PR + Issues** ; la préprod déploie depuis GitHub ; la CI gate les PR.
- **Une branche par changement** : `feature/<nom>` (SDD complet) ou `fix/<nom>` (voie légère). **Jamais de push direct sur `main`.** Pour un travail en cours, ouvre ta PR en **brouillon**.

  > ### ⚠ IL N'Y A PAS DE CI. RIEN NE RELIT TA PR — NI HUMAIN, NI MACHINE.
  >
  > Cette ligne disait « merge = automatique dès que la CI est verte, le gate est la CI, plus de feu vert manuel ». **C'est faux aujourd'hui.** Mesuré le 15/09/2026 : la CI **a tourné** — **36 runs** entre le 06/09 20:19 et le **07/09 09:12:30 UTC** — **puis plus rien**. (L'appel `actions/runs` sans filtre rend `0` à cause de la restriction du compte ; `?status=completed` rend **36** — c'est ce dernier qui dit vrai.) Depuis le 07/09 09:12:30, **aucun run ni suite de contrôles** sur `main`, alors que les workflows restent déclarés et `active`. La CI a donc relu des PR pendant ~13 h, puis s'est arrêtée net — et **ne relit plus rien** depuis.
  >
  > La cause est hors du dépôt : le compte `Mdesb` est **écarté par GitHub** — `github.com/Mdesb` rend 404 à un visiteur anonyme, et `api/users/Mdesb` rend 404 **même à Mdesb authentifié**. Voir `COORDINATION/BLOQUEURS-EXTERNES.md` (E-10) ; seul le support GitHub peut le lever.
  >
  > **Conséquence sur ton travail :** la protection de `main` exige deux checks qui ne peuvent pas exister, donc **aucune PR ne fusionne seule**. Chacune est forcée à la main :
  >
  > ```bash
  > gh pr merge <N> --squash --admin --repo Mdesb/fluvia
  > ```
  >
  > **Le seul gate réel est le hook de pré-commit**, qui lance `bin/garde-fous.sh` sur ta machine avant que le commit existe. Ce qu'il laisse passer entre dans `main` tel quel. Ne te repose sur aucune vérification en aval : il n'y en a pas.
- **Commits en français, conventional commits** (`feat:`, `fix:`, `docs:`, `securite:` avec portée `fix(ci):`) ; le corps dit le *pourquoi* et les conséquences.
- **Jamais de `push --force` ni de réécriture d'historique sur `main`.** (La seule exception passée : purger un secret du miroir public, opération d'intégration explicitement demandée par Maxime.)
- **Réserver un chantier avant de le toucher** : assigne-toi l'**Issue** correspondante (atomique, horodaté — pas de course). **Priorité haute d'abord.** Reste dans ton périmètre (`CODEOWNERS`).
- Les agents ne committent/poussent jamais sans confirmation humaine explicite.

## Périmètres — `CODEOWNERS`, pas un roster figé

Qui possède quoi se lit dans **`.github/CODEOWNERS`** (un *chemin* → un propriétaire), pas dans une liste de noms qui se périme. Toucher un chemin qu'on ne possède pas se demande d'abord. Maxime seul déplace un périmètre.

## Nommage et langues

- **Produit en français.** Les **fichiers ajoutés** utilisent des identifiants **anglais** (classes, méthodes, propriétés) — règle D5 ; les fichiers existants en français le restent. Le produit s'appelle **Fluvia**.

## Surfaces de documentation (et pas plus)

Un petit nombre de surfaces, chacune un rôle unique — n'en crée pas d'autres :

- **`CLAUDE.md`** — les règles et la méthode (ce fichier). Seule source des règles actives.
- **Issues + index** — la liste des chantiers.
- **`COORDINATION/DECISIONS.md`** — le journal *append-only* des décisions (le *pourquoi*). C'est notre `JOURNAL_DECISIONS`.
- **`features/<nom>/`** — spec, plan, suivi d'une fonctionnalité en cours.
- **`docs/`** — base de connaissances de référence.
- **`docs/etat-projet.md`** — le **tableau de bord** régénéré par `/point-projet` (une vue, pas une source).

## Ce qui a été retiré (migration vers GitHub)

L'ancienne coordination artisanale — `COORDINATION/ORDRES/`, `RAPPORTS/`, le battement de 15 min, « un fichier = un auteur », `TASKS.md` comme tableau, `MESSAGES.md` comme canal — **est remplacée par branches + PR + Issues**. On garde les décisions et les périmètres. Ne re-densifie pas : si quelque chose coince, le réflexe est de *retirer de la friction*, pas d'ajouter une cérémonie.

## Garde-fous du harnais (kit SDD 3.0.1)

Le plugin `garde-fous-sdd` (kit SDD 3.0.1, catalogue `kit-sdd` dans `/home/debian/kit-sdd`) **applique** une partie des règles ci-dessus par des hooks Claude Code, avant que la commande ne s'exécute. Sa configuration : `kit-sdd.json` à la racine et `.claude/settings.json`, en zone sensible comme tout `.claude/**`. Le protocole qu'il injecte en séance (« aiguille toi-même ») ne remplace pas `/aiguiller` : ce fichier reste la seule source des règles actives.

- **Ce qu'il refuse**, par `⛔ garde-fous-sdd [<règle>] — <quoi faire>` : commit, merge ou push sur `main` (aucune identité autorisée ; `gh pr merge --admin` n'est pas concerné) · `git push --force` · `git add -A` / `--all` / `.` / `./` / `-- .` / `:/` / `-u` sans chemin · `git commit -a` / `-am` · `--no-verify` et hooks désactivés · lecture ou copie de secrets · écriture hors du projet ou dans le worktree d'une autre session · commit en zone sensible sans revue consignée (`/garde-fous-sdd:revue-sensible`) · commit de plus de 400 lignes de code sans justification · les `commandes_interdites` de `kit-sdd.json`. À l'arrêt, il relance une fois un tour où du code a changé sans que `./bin/garde-fous.sh` ait tourné.
- **`.claude/settings.json`**, pour une session ouverte dans ce dépôt, plugin chargé ou non : il laisse passer sans invite les lectures git et gh, `git add`, `git switch`, `git checkout -b`, `git branch`, `git fetch`, `./bin/garde-fous.sh` et `./infra/test-stack.sh` (`down` compris). Son `deny` (la liste du fichier fait foi) refuse la lecture des secrets et des écritures **écrites en tête de commande**, sous leur forme courte, longue ou combinée : suppression, déplacement ou copie forcés de branche (`git branch -D`, `-M`, `-m -f`, `-C`…), `git switch` / `git checkout` qui jettent les modifications ou écrasent une branche (`-f`, `--force`, `--discard-changes`, `-C`, `-B`), `git fetch` forcé (`-f`, refspec `+`), `git add` de tout l'arbre (`-A`, `--all`, `.`, `./`, `-- .`, `:/`, `-u`) ou d'un fichier ignoré (`-f`), `git reset --hard`, `git clean -fd`, push forcé, `git commit -a` / `-am`, `--no-verify` / `-n`. Le kit laisse passer `git branch -D` / `-f` / `-m -f` / `-C`, `git switch -f` / `--force` / `--discard-changes`, `git checkout -f`, `git fetch` forcé et `git add -f` : pour eux, ce `deny` est le seul refus. Ces refus jugent le texte de la commande, pas le programme. Passent **sans invite** : `git branch -Df x`, `git branch x -D`, `git switch main -f`, `git fetch origin '+main:x'` ; passent **sur invite** : `git commit -m x --no-verify`, `git commit -nm x`, `git -C . branch -D x`, `bash -c '…'`.
- **Il se charge au démarrage d'une session.** Une session ouverte garde la version chargée à son démarrage. `COORDINATION/FLOTTE.md` pose qu'une session ne se ferme jamais ; le redémarrage qui charge une nouvelle version revient à Maxime (sa décision) : aucune session n'en relance une autre.
- **Prouver qu'il tourne** (la sortie d'un installateur ne prouve rien) : le contexte de démarrage contient `## garde-fous-sdd — fluvia` ; `git add -A` est refusé par `⛔ garde-fous-sdd [add_global]`, crochets compris (la 2.5 écrivait le même message sans crochets ; la version exacte se lit dans `installed_plugins.json` du profil) ; `/garde-fous-sdd:doctor-kit` rend un témoin vert sur le plugin chargé (`doctor.py` lancé depuis un dossier du kit teste ce dossier, pas la session). Le témoin du démarrage et `doctor` écrivent chacun un refus `add_global` au journal : ce n'est pas un incident.
- **Ce qu'il ne couvre pas** : une session qui pilote ce dépôt par `ssh` depuis une autre machine (le travail d'intégration actuel) — le plugin ne juge que les commandes d'une session qui l'a chargé, avec le `kit-sdd.json` de la racine de cette session, et même alors `pre_bash` ne déroule pas `ssh hôte '…'` ; l'outil PowerShell — aucun hook ne le juge avant exécution (seul le journal le voit après coup) ; `cd X && git merge …` (la branche est lue dans le répertoire de départ). Sur ces flux, **le hook de pré-commit du dépôt reste le seul contrôle** (voir « Il n'y a pas de CI »).
- **Le battement** que `COORDINATION/FLOTTE.md` décrit encore (retiré, voir plus haut) finit par `git add -A` : le kit le refuse. Modifier `FLOTTE.md` revient à Maxime.
- **Désactivés** dans `kit-sdd.json` : traduction et fiches support (ni catalogue ni dossier sur `main`), mémoire de façon de faire (dépôt public). Aucun contexte métier n'est versionné : le ⚠ qui le signale au démarrage est attendu.
