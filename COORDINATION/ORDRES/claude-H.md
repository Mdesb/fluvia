# Ordres pour `claude-H`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais.

---

## 2026-08-24 18:10 · Ta session n'est pas encore lancée — voici ce qui t'attend

Maxime ne t'a pas encore ouverte. Cet ordre t'attend pour ton premier démarrage.

**Ton chantier : la publication sociale.** Publier une fois, diffuser partout, mesurer. Deux usages, un
seul module — celui des clients de Maxime, et le sien.

**Commence par SOC-1, et surtout par le coffre à jetons.** Le modèle de publication est la partie
facile ; le coffre est la partie qui compte, parce que tu vas stocker de quoi publier **au nom de
quelqu'un d'autre**.

Trois exigences non négociables, tirées de ce que ce dépôt a appris :

1. **Chiffré au repos, clé depuis l'environnement, sans valeur par défaut.** Quatre occurrences du même
   défaut ont déjà été corrigées ici. Le garde-fou des secrets refusera ton commit si tu poses une clé
   en dur.
2. **Cloisonné par établissement**, contrôlé sur l'entité résolue et non sur le fichier. Seize IDOR ont
   été trouvés ici en cinq jours, tous de la même forme.
3. **Un jeton ne sort jamais** d'une réponse d'API, d'un événement ou d'un journal.

**Puis SOC-2, uniquement des réseaux ouverts** — Mastodon, Bluesky. Ne touche pas aux adaptateurs Meta :
SOC-4 est en statut `EXTERNE`, elle attend l'immatriculation de la société (registre E-1).

---

## Rappel de cadence — Maxime a constaté le silence

Quatre sessions sur sept sont restées muettes **cinq à six heures** aujourd'hui, alors que D29 impose
un battement toutes les quinze à vingt minutes. Maxime l'a vu, et il avait raison de le relever.

**Une part de la faute est la mienne** : j'ai constaté ce silence dans trois points horaires successifs
en écrivant « à surveiller au prochain battement », sans jamais agir. Et j'ai demandé un battement de
quinze minutes à des sessions qui, lancées depuis le bureau, s'arrêtent dès qu'elles ont fini de
répondre — je leur ai demandé quelque chose que leur fonctionnement ne permet pas.

**Ce que tu peux faire, toi, sans rien attendre de moi** : ne termine pas ton tour sur une attente.
Tant que ton périmètre contient une tâche ouverte, enchaîne. Si tu n'as vraiment plus rien, écris-le
dans ton rapport — « périmètre vide, disponible » est une information exploitable ; le silence n'en est
pas une.

---

## 2026-08-24 20:30 · CORRECTION — l'alias SSH n'est pas `billetterie` chez toi

**C'est mon erreur, et elle vous a peut-être coûté du temps à plusieurs.** J'ai écrit « alias SSH
`billetterie` » dans les ordres, dans FLOTTE.md et dans la consigne du superviseur, en supposant que
c'était le nom du serveur. **Ce n'est pas le nom du serveur : c'est un raccourci écrit dans le fichier
de configuration SSH de MON poste.** Vous travaillez depuis trois bureaux différents, et l'alias n'y est
pas le même — sur deux d'entre eux c'est `vps-preprod`.

**Ne cherche donc pas un alias que je t'aurais donné. Trouve le tien :**

    grep -i '^Host ' ~/.ssh/config

Prends celui qui désigne le serveur du projet — `billetterie` ou `vps-preprod` selon le poste. S'il n'y
en a aucun, dis-le dans ton rapport : c'est un vrai blocage, et il est pour Maxime, pas pour toi.

Partout où tu lis « alias `billetterie` » dans un document de coordination, comprends « l'alias SSH de
ton poste ». Je corrige les documents au fur et à mesure, mais certains le porteront encore.

---

## 2026-08-24 20:50 · D34 — présente-toi AVANT de lire tes ordres, et ne coupe jamais Remote Control

Consigne de Maxime, et elle prime sur la routine de démarrage que je t'avais donnée.

**Ta première obligation en démarrant n'est pas de lire cette boîte. C'est de te présenter.** Liste tes
pairs, trouve la session `claude-A` — elle s'affiche sous un nom générique, écris-lui quand même — et
envoie-lui : « <ton identité> en ligne ». Ensuite seulement, va chercher tes ordres.

**Pourquoi cet ordre-là.** Une session qui ne s'est pas présentée est **invisible** pour moi. Je ne peux
ni la relancer, ni lui transmettre un arbitrage, ni la débloquer. Elle ne peut que s'arrêter et attendre
qu'un humain la rouvre. C'est ce qui est arrivé aujourd'hui : sept sessions sur neuf se sont arrêtées
après avoir livré, et je n'avais aucun moyen de les rappeler. La seule qui a tenu trois heures sans
interruption est la seule qui s'était présentée.

**Et Remote Control reste actif, toujours.** On ne le coupe pas, on ne le déplace pas vers une autre
session, on ne ferme pas la session qui le porte. Si tu constates qu'il est inactif chez toi, dis-le
immédiatement — c'est plus urgent que ton lot en cours.

Ce n'est pas une formalité de politesse : c'est ce qui fait la différence entre une flotte et neuf
sessions qui s'ignorent.

---

## 2026-08-24 21:00 · D35 — écris-moi toutes les heures, même pour ne rien dire

Consigne de Maxime, donnée à vous neuf.

**Toutes les heures, quoi que tu fasses, tu m'écris un message.** Pas une ligne dans ton rapport : un
message. Deux cas :

- **tu as une tâche en cours** → dis-moi laquelle et où tu en es, deux lignes suffisent ;
- **tu n'as rien** → **demande-moi une tâche**. Ne t'en invente pas une hors de ton périmètre, et ne
  t'arrête pas pour autant : demande, et prends la suivante de ton périmètre en attendant ma réponse.

**Pourquoi un message et pas le rapport.** Un rapport est un fichier : je dois aller le lire et rien ne
me dit qu'il a changé. Un message me réveille. Et surtout, **la liaison ne s'établit que dans ce
sens-là** : je ne peux pas appeler une session qui ne s'est jamais annoncée. C'est ton message qui crée
la joignabilité, pas ma bonne volonté.

**Mon engagement en retour** : je réponds à chaque présentation, même brièvement. Si tu demandes une
tâche, tu repars avec une tâche. Une session qui écrit dans le vide apprend que c'est inutile, et je ne
veux pas t'apprendre ça.

---

## 2026-08-25 · 01:00 — ORDRE LONG : de quoi tenir sans moi

Maxime dort. Je resterai joignable tant que ma propre session tourne, mais **ne m'attends pour rien** :
tout ce qui suit est tranché, et les critères d'acceptation sont écrits pour que tu puisses juger
seul(e) qu'un lot est fini.

**Ordre de passage imposé.** Prends-les dans l'ordre. Si un lot te bloque plus de vingt minutes,
écris-le dans ton rapport, passe au suivant, et reviens. Ne t'arrête jamais sur une question (D30).

**Rappels qui ont coûté cher cette nuit, tous constatés :**

- **D32** — ne commite jamais un `migrations:diff` sans le relire ligne à ligne. Le brouillon de
  `claude-D` contenait 104 instructions dont 6 à elle. Horodate en **heure locale** : le conteneur
  tourne en UTC, deux heures derrière.
- **D40** — deux jeux de données qui doivent rester ordonnés dans le temps s'ancrent sur la **même**
  référence avec un écart explicite. « next tuesday » ne tombe après « next monday » que cinq jours sur
  sept : un test s'est révélé rouge **deux jours par semaine depuis son écriture**.
- **D39** — si tu rejoues une règle d'autorisation du serveur, rejoue-la **entière**. Utilise
  `api/droits.js`, jamais `droits.includes(...)`. Quatre occurrences trouvées cette nuit, dont une
  écrite pendant le lot qui corrigeait cette classe.
- **D41** — je viens de trouver que **35 entités** laissent écrire leur `etablissement` sans contrôle.
  Si tu exposes une entité qui porte un établissement, ne le mets **pas** dans un groupe d'écriture.
- **Un test qu'on ajuste pour qu'il passe ne teste plus rien.** Vérifie-le **rouge sans la garde** avant
  de le déclarer vert.
- **Refus en 404, jamais 403** : un 403 est un oracle d'énumération.
- **Démonte ta pile de test.** Vingt-deux orphelines ont saturé le VPS hier ; `bin/ramasser-piles-test.sh`
  existe désormais mais ne te dispense pas de `down`.

### Ton fil : les 181 opérations non branchées, dans l'ordre de ce qui fait le plus mal

Ton chiffrage est le meilleur travail d'analyse de la nuit, et il commande la suite. **Ne construis pas
de nouvel écran** : branche ce qui existe. Ordre imposé, du plus douloureux au moins :

1. **Modifier un produit.** On peut le créer, le publier, l'archiver — pas corriger une faute de frappe
   dans son libellé. C'est l'absence la plus absurde du logiciel.
   *Fini quand* : `PATCH` branché depuis la fiche produit, champs cohérents avec le statut (on ne
   change pas le code d'un produit publié sans le dire), et un message serveur affiché tel quel en cas
   de refus.
2. **L'historique des ventes.** `GET /api/ventes` n'est appelé nulle part. Un caissier ne peut pas
   retrouver une vente d'hier.
   *Fini quand* : liste filtrable par date et par client, ligne cliquable vers le détail, et le
   cloisonnement vérifié — un établissement ne voit pas les ventes d'un autre.
3. **Rembourser une vente.** L'opération existe et n'a pas de bouton.
   *Fini quand* : confirmation qui dit ce qui sera remboursé et par quel moyen, et refus serveur
   affiché sans traduction.
4. **Le no-show.** `facturations-no-show/{id}/emettre-vente` et `/exonerer` ne sont appelés nulle part.
   **Maxime a arbitré ce comportement (D27) et ne peut pas le voir.** Il faut aussi montrer la seconde
   dimension : décomptée / restituée / restituée avec report — orthogonale à la facturation.
   *Fini quand* : les deux décisions sont possibles depuis l'écran, et l'issue sur le crédit est
   visible séparément du montant.
5. **Les absences du personnel** : 16 opérations manquantes sur ce seul écran, dont valider et refuser.
6. **Créer et modifier un rôle**, sur l'écran qui s'appelle « Utilisateurs et droits ».
   *Attention* : c'est de la sécurité. Un rôle mal composé ouvre tout. Montre les permissions par
   module, pas une liste plate de 198 lignes.
7. **Créer un établissement.**
8. **La patinoire** : affûtages, locations de patins, liste d'attente — l'essentiel de son métier.

**Relance ton script de mesure après chaque lot** et note le chiffre dans ton rapport. Il descendra, ou
il te dira que non — c'est le seul indicateur honnête qu'on ait sur ce chantier.
