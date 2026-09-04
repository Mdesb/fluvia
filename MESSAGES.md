# Contournements de garde-fou, justifiés

Ce fichier existe parce que `hooks/pre-commit` le demande : *« Si tu dois vraiment
passer outre : `git commit --no-verify`, et dis pourquoi dans MESSAGES.md. »*

Un contournement non écrit est indiscernable d'un oubli. Écrit, il se relit, se
conteste, et se retire quand sa raison disparaît.

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

---

## `verifier-formats.mjs` couvre enfin les routes sur mesure — sept écritures étaient cassées

*(claude-C0, 04/09, commit `8b8fdc55`, déjà sur `main` et en préprod)*

Le contrôle n°11 sautait **en bloc** les routes à `uriTemplate` sur mesure, au motif écrit dans son
propre message d'aide qu'« elles portent `input: false` ». C'est vrai de 222 d'entre elles, faux des
autres. Cette famille était donc le seul endroit du client que rien ne surveillait, et **sept
écritures y partaient en `application/json` sur des opérations qui n'acceptent que `ld+json`** :
elles rendaient 415, toujours, depuis leur écriture.

**Trois de ces sept sont dans vos modules — vos écrans de création n'ont jamais rien créé :**

| module | appel | écran concerné |
|---|---|---|
| stock | `stockRattacherProduit` | rattacher un article à un produit vendu |
| padel | `creerTerrainPadel`, `padelLouerMateriel` | créer un terrain, louer du matériel |
| musée | `museeCreerVisite`, `museeCreerDossierGroupe` | créer une visite guidée, un dossier de groupe |

Pour le padel et le musée, un commentaire au-dessus des appels affirmait « `input: false`, pas de
`ld: true` » : vrai des *confirmations* qu'il surplombait aussi, faux des créations. Une règle écrite
pour un groupe d'appels et appliquée au voisin.

Les deux dernières bloquaient la création d'une formule d'abonnement, donc le tunnel de souscription
du site vitrine — le symptôme que Maxime a signalé, trois écrans plus loin.

### Ce que le contrôle vaut maintenant, mesuré et non supposé

En interrogeant le registre d'API Platform (noyau démarré) plutôt qu'en réimplémentant sa règle par
un grep : **540 opérations d'écriture sur mesure, dont 296 exigent `ld+json` et 244 ne contrôlent pas
le type**. Confronté à ce verdict sur un client privé de *tous* ses `ld: true`, le contrôle voit
désormais tout ce que l'autorité signale. Les deux écarts qu'a révélés cette confrontation étaient
deux défauts du contrôle, corrigés ici : il ne connaissait qu'un des deux idiomes de « ne désérialise
pas » (`deserialize: false` existe aussi, 4 fichiers), et il refusait de trancher quand un chemin
correspondait à plusieurs déclarations — `/marketing/fidelite/{id}` masquait ainsi
`/marketing/fidelite/mouvements`. La plus spécifique l'emporte désormais, comme dans le routeur.

> ⚠ **Une affirmation fausse dans un garde-fou se recopie.** Le fichier soutenait en quatre endroits
> qu'une opération sans corps *refuse* `ld+json` en 415. Elle ne refuse rien : elle ne contrôle pas
> le type du tout (vérifié par requête — `/calendar/ics-subscription/regenerate` rend 404 pour les
> deux types). Deux de ces quatre phrases venaient d'être recopiées des deux autres, dont une par
> moi une heure plus tôt. Les quatre sont corrigées, ainsi que la même croyance au cœur de
> `request()` dans `client.js`, qui annonçait que « les autres écritures acceptent JSON simple ».

**Ce que ça vous demande :** rien à corriger, c'est fait. Mais si l'un de vous a un jour contourné un
échec de création en concluant à un défaut serveur, la cause était ici.
