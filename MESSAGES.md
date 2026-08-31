# Contournements de garde-fou, justifiés

Ce fichier existe parce que `hooks/pre-commit` le demande : *« Si tu dois vraiment
passer outre : `git commit --no-verify`, et dis pourquoi dans MESSAGES.md. »*

Un contournement non écrit est indiscernable d'un oubli. Écrit, il se relit, se
conteste, et se retire quand sa raison disparaît.

---

## 31/08 — filet de complétude : le garde-fou n°37 refuse le commit qui l'ajoute

**Par** le worktree `claude-A`, sur le lot des classes CSS fantômes.

**Ce que le contrôle disait :**

    ✗ Garde-fou présent dans l'arbre mais jamais lancé par ce hook :
      garde-fou-classes-fantomes.php
      Ajoute son appel dans hooks/pre-commit.

**Il a parfaitement raison, et je l'ai fait.** Les trois listes sont câblées dans
ce commit : `bin/garde-fous.sh` (les deux branches), `hooks/pre-commit` et
`hooks/pre-receive`.

**Pourquoi il refuse quand même.** Le crochet qui s'exécute n'est pas celui du
dépôt : c'est la copie installée dans le répertoire commun, partagée par tous les
worktrees, et `post-receive` la réinstalle depuis `main` (D28). Ma ligne existe
donc dans `hooks/pre-commit` sans exister dans le crochet qui la vérifie. Le filet
est cohérent avec `main`, pas avec mon arbre — et il le sera de nouveau dès
l'intégration.

**Ce n'est pas un défaut du filet.** Il est délibéré, et sa raison tient : trois
garde-fous avaient jadis été écrits, câblés au lanceur, annoncés livrés, et
n'avaient jamais tourné. Un contrôle qui ne tourne pas est pire qu'absent — il
donne le vert.

⚠ **Et je n'ai PAS réinstallé le crochet pour passer.** La réinstallation suivante
l'effacerait, et entre-temps j'aurais changé le contrôle que subissent les huit
autres worktrees sans que personne l'ait décidé. Contourner chez moi ne coûte
qu'à moi ; réinstaller coûte à tout le monde.

**Ce que j'ai lancé à la main avant de passer outre**, et qui couvre exactement ce
que le crochet aurait fait :

    bash bin/garde-fous.sh        ->  ✓ 37 garde-fou(s) OK
    npx vite build                ->  ✓ built
    le n°37 vu ÉCHOUER            ->  un fichier témoin jetable portant une classe
                                      inventée le fait échouer, avec nom et ligne ;
                                      retiré, il repasse

**Quand cette entrée disparaît :** à l'intégration de `front-ecrans` dans `main`.
Le crochet installé se réinstallera alors avec la ligne, et le contournement
n'aura plus d'objet.

---

## 30/08 — garde-fou n°11 : contournement local tenté, puis CORRIGÉ

**Par** allaccess-c2, sur le lot du compostage de billet.

**Ce que le contrôle disait :**

    ✗ Formats : POST /api/acces/controle-billet sans `ld: true`.
      Cette opération est standard : elle désérialise le corps…

**Pourquoi il avait tort.** Il collecte les `uriTemplate` déclarés côté serveur et
traite comme « standard » tout chemin client qui n'en correspond à aucun. La route
n'existait pas encore — elle arrive dans le lot d'allaccess-8e — donc il la
classait standard **par défaut**. Il affirmait une propriété de la route qu'il
déduisait de son absence.

Vérifié auprès de 8e : sa route est en `uriTemplate` sur mesure + `input: false`,
comme les quatre autres `POST` de son module. **Suivre le conseil aurait posé un
`ld: true` que sa route refuse en 415** — le contrôle n'aurait pas signalé un
défaut, il en aurait fait poser un, et serait redevenu vert.

**Ce qui s'est réellement passé, dans l'ordre.**

1. Maxime a autorisé un contournement, une fois, écrit ici.
2. `git commit --no-verify` a passé le crochet **local** — mais le crochet de
   **réception** rejoue les mêmes contrôles sur l'arbre poussé, et l'a refusé. Le
   seul interrupteur qui l'aurait franchi désactive les garde-fous pour les neuf
   sessions : hors de proportion avec le problème.
3. J'ai donc **corrigé le contrôle** plutôt que de le contourner : il honore
   désormais `@route-a-venir:`, le marqueur qui existait déjà et n'était câblé que
   dans le n°15.

**Le contournement n'a donc pas eu lieu au-delà du local.** Cette entrée reste
parce qu'un `--no-verify` figure dans l'historique de ce lot, et qu'un
`--no-verify` sans explication est indiscernable d'une négligence.

**Le blocage qui l'avait rendu nécessaire.** Deux cliquets corrects se
verrouillaient l'un l'autre : le n°15 exige qu'une route ait un écran, le n°11
exige qu'un appel client ait une route. Un lot coupé en deux moitiés qui se
conditionnent ne peut entrer que si l'une passe d'abord.

> Tout contrôle qui juge la relation client↔serveur doit connaître le marqueur,
> sinon il rouvre le blocage que l'autre a résolu.

⚠ **La correction est minimale et provisoire.** allaccess-73 a une version plus
riche sur sa machine — un troisième état « route introuvable », qui distingue une
route annoncée d'une route mal orthographiée. La sienne est meilleure et doit
remplacer celle-ci à la fusion ; la mienne se borne à honorer le marqueur, sans
rien changer ailleurs.

**Éprouvée sur trois cas**, dont celui qu'on oublie en corrigeant un faux positif :

    route annoncée par le marqueur        → silence
    route standard sans `ld: true`        → REFUS   ⟵ le vrai positif, toujours armé
    arbre réel                            → silence
