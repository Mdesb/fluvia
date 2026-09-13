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

- **Voie légère** (`/correctif-rapide`) — petit changement localisé, réversible, ne touchant **aucune** zone sensible (base, auth, paiements, **cloisonnement multi-tenant**, NF525/argent, données personnelles) et n'ajoutant pas de capacité nouvelle. Intention en une ligne dans l'index, changement chirurgical, tests verts (+ non-régression si bug), revue `relecteur`, puis **auto-merge dès que la CI est verte** (le gate est la CI, plus de feu vert manuel).
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
- **Une branche par changement** : `feature/<nom>` (SDD complet) ou `fix/<nom>` (voie légère). **Jamais de push direct sur `main`.** Merge = **automatique dès que la CI est verte** (auto-merge GitHub en squash, workflow `auto-merge.yml`) : le gate est la CI, plus de feu vert manuel. Pour un travail en cours, ouvre ta PR en **brouillon** — l'auto-merge ne s'arme qu'au passage « ready ».
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

## Garde-fous du harnais (plugin `garde-fous-sdd`)

Depuis le 12/09/2026, une partie des règles de ce fichier n'est plus seulement **demandée** : elle est
**appliquée** par des hooks Claude Code, avant que la commande ne s'exécute. Un refus n'est pas une
panne : la raison affichée dit quoi faire.

**Ce que le harnais refuse**
- `git commit` ou `git merge` sur `main` · `git push --force` · tout push vers `main` · `git add -A` / `git add .`.
- Écrire dans le worktree d'une autre session (`/home/debian/wt/<autre>`) ou hors du projet.
- Un secret présumé dans un fichier écrit ou dans l'index au moment du commit (faux positif avéré :
  un extrait de la ligne dans `.kit-sdd/secrets-autorises.txt`, avec la raison).
- Une pile de test au jeton générique (`test`, `claude`, `monjeton`…) ou qui ne porte pas ton identité ;
  la suite de tests dans le clone de déploiement `/home/debian/billetterie`.
- `doctrine:migrations:diff`, un `git worktree add` depuis le dépôt nu, `composer install --no-dev` hors deploy.
- Un commit qui touche une **zone sensible** (Securite, Caisse, Compta, Facturation, Recouvrement, Finance,
  migrations, infra, garde-fous, workflows…) sans revue adversariale consignée : lance
  `/garde-fous-sdd:revue-sensible`, elle fait la revue (`security-reviewer` + `relecteur`) et écrit le verdict
  dans `.kit-sdd/revues/<branche>.md`.

**Ce que le harnais ajoute**
- Au démarrage : identité, branche, PR ouvertes, piles de test oubliées.
- À chaque demande de dev sans aiguillage : un rappel de `/aiguiller` (une fois par session).
- À l'arrêt : une relance si une pile de test à ton nom est encore montée, et le relevé des tokens.
- Un journal d'audit (`~/.kit-sdd/journal/fluvia/`) : `/garde-fous-sdd:journal` le lit.

**Contradiction avant validation.** Maxime écrit, il n'invoque aucune commande : c'est la session qui suit le
protocole que le harnais lui donne à chaque demande de dev. Sur tout objet en cycle complet (spec, plan,
décision) et sur toute entrée dans `COORDINATION/DECISIONS.md`, la session lance l'agent `contradicteur`
(une passe, ≤ 350 mots, `RAS` si rien de solide) et, quand le domaine le demande, **au plus deux
perspectives** parmi : `perspective-juridique` (RGPD, NF525, CGV, marchés publics : signale, ne tranche pas,
à valider par un professionnel), `perspective-finance` (coût, marge, trésorerie, hypothèse chiffrée
manquante), `perspective-marketing` (pour qui, quel mot, produit ou sur-mesure), `perspective-direction`
(bon moment ou hors tour, coût d'opportunité), `simplificateur` (parcours en trois étapes, zéro jargon, pour
un régisseur qui n'aime pas l'informatique), `perspective-signature` (rien du look générique des logiciels
faits par IA ; charte `docs/identite.md` à écrire d'abord si elle manque). L'auteur **répond par écrit** à
chaque objection dans la spec ou l'entrée, et la spec arrive à Maxime **avec** les objections : c'est ça,
CP-1. Le harnais plafonne à 6 lancements par session et 2 perspectives par objet (`contradicteur` dans
`kit-sdd.json`) ; au-delà, on tranche avec ce qu'on a.

**Liberté des perspectives, conditionnée au challenge.** Chaque perspective a un niveau dans `kit-sdd.json`
(`contradicteur.liberte`) : **0 observer** · **1 proposer** (livrable dans `features/<nom>/perspectives/`) ·
**2 décider** (entrée « DÉLÉGUÉE (agent) » dans `COORDINATION/DECISIONS.md`, contestable par Maxime pendant
7 jours, puis actée) · **3 saisir** (Issue prête à aiguiller). Jamais coder. Sur Fluvia : juridique 1, finance 1,
marketing 2, direction 2 (hors P0), signature 2 (hors couleur et typo), simplificateur 3. Le harnais refuse à
l'écriture une délégation sans passage du contradicteur dans la session, sans **Perspective croisée** citée,
sans **Contradiction / Réponse**, sans date de contestation, ou signée par un agent sous le niveau 2. Au
démarrage de chaque session, les délégations encore contestables sont listées : Maxime pose son veto d'une
ligne dans le journal, rien d'autre à faire.

**La façon de faire de Maxime est écrite, pas redécouverte.** `COORDINATION/MAXIME.md` est injecté à chaque
démarrage : comment lui parler, ses préférences, sa manière d'arbitrer, ce qu'il refuse. Après chaque
arbitrage (réponse à un QCM, correction, règle), la session lance l'agent `greffier`, qui y consigne une
préférence **durable** (une décision ponctuelle va dans `DECISIONS.md`), dédoublonnée et sourcée. Trois
passages par session au plus. Pas de « je » dans ce fichier : il est lu par neuf sessions.

**Les questions à Maxime se posent en QCM, avec le sélecteur interactif de la discussion** (outil
`AskUserQuestion`) quand la session en dispose : jusqu'à quatre questions sur un même objet, 2 à 4 options
chacune avec ce qu'elle implique et ce qu'on perd, l'option recommandée marquée et justifiée d'après sa façon
de faire. Le texte lettré n'est que le repli des sessions sans interface. Maxime porte les compétences
juridique et financière : ces points lui sont soumis de la même façon, jamais renvoyés à un professionnel. Le harnais renvoie reformuler toute question ouverte
posée après une analyse : c'est ce qui force l'analyse jusqu'au bout.

**Documentation toujours à jour, deux rédacteurs.** Du code changé dans la session sans documentation
touchée, et le tour ne se clôt pas : `redacteur-technique` (docs, `features/<nom>/impl/`, ce qui a changé et
pourquoi) pour les développeurs ; `redacteur-support` (`docs/support/` : fiches « comment faire » en cinq
étapes au plus, erreurs expliquées, dépannage pour le support, notes de version en langage client, INDEX)
dès qu'un écran, un libellé, un message ou un comportement visible change. Une fiche qui décrit un parcours
qui n'existe plus est pire qu'aucune fiche. La seule sortie sans doc : dire explicitement que rien de
documentable n'a changé.

**Traduction, 24 langues officielles de l'UE.** Le produit est en français ; l'agent `traducteur-ue` traduit
les clés d'interface, les messages et les fiches support vers les 23 autres langues officielles (pas les
langues régionales), par lots, avec glossaire métier (`docs/i18n/glossaire.md`), variables et pluriels
intacts, boutons pas plus longs que la source de 30 %, marque `@traduit-auto` pour relecture native. Il ne
touche jamais la source, ne traduit pas les documents juridiques, et le harnais le rappelle dès qu'un fichier
source de langue change (`i18n` dans `kit-sdd.json`). **État au 14/09/2026 : Fluvia n'a aucune infrastructure
i18n** (pas de `app/translations`, aucun `t()` dans le front, libellés français en dur). Le premier passage de
l'agent est un inventaire des chaînes en dur et la spec du chantier i18n, à aiguiller comme une fonctionnalité ;
seules les fiches `docs/support/` se traduisent dès maintenant.

**Taille du code, tenue par le harnais.** Le code le plus court qui passe les tests. Pas d'abstraction pour
un seul usage, pas d'interface sans second implémenteur, pas de fichier nouveau si une fonction suffit. Le
plan annonce une taille attendue et une section « ce qu'on ne construit pas » ; le relecteur compte les lignes
supprimables. Au commit, le **budget de diff** compte les lignes de code ajoutées hors doc, tests, verrous et
migrations : avertissement à 150, refus à 400 sauf justification écrite dans `.kit-sdd/budget/<branche>.md`.

**Les garde-fous du projet avant de rendre.** Le développeur lance `./bin/garde-fous.sh` et corrige ce qu'ils
refusent avant de rendre son rapport ; le harnais ne clôt pas un tour où du code a changé sans ça. Leçon du
14/09 : un commit relu et testé a été refusé trois fois au pre-commit pour des défauts que cette commande
montre en une minute.

**Les suites deviennent des Issues.** À la clôture d'un lot, `/garde-fous-sdd:suites` transforme les lots
suivants, bugs latents et correctifs rapides du suivi en Issues étiquetées `suite` ; le fichier de suivi pointe
vers elles. Une perspective par domaine détecté (plus de plafond fixe à deux), dans le budget de session.

**Commandes du plugin** (utiles à une session, jamais requises de Maxime) : `/garde-fous-sdd:contradire` ·
`/garde-fous-sdd:revue-sensible` · `/garde-fous-sdd:decision` (ADR dans `COORDINATION/DECISIONS.md`) ·
`/garde-fous-sdd:tests-manquants` · `/garde-fous-sdd:audit-deps` · `/garde-fous-sdd:journal` ·
`/garde-fous-sdd:couts` · `/garde-fous-sdd:doctor-kit`.

La configuration est dans `kit-sdd.json` à la racine (suivi par git : le changer, c'est une PR comme une autre).
Le plugin vit dans `/home/debian/kit-sdd` (dépôt séparé) ; `bin/installer-flotte.sh` le pose dans les profils.
