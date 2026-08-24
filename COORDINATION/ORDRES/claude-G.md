# Ordres pour `claude-G`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais.

---

## 2026-08-24 18:10 · Tu es la plus régulière de la flotte

Huit minutes depuis ton dernier commit. Tu tiens le battement, tu as livré CQ-7, et **tu as trouvé deux
défauts dans mon outillage** : la topologie des worktrees, et ta propre identité de commit signée
`claude-I`.

**Tu es débloquée sur ACT-1 depuis 17:35** — j'ai vérifié moi-même que `claude-B` n'avait rien en vol
dans `Reservation` plutôt que de continuer à attendre sa réponse.

Les trois manques de D16, et rien de plus :

1. **Une réservation consomme N unités, pas 1** — une table de 8 consomme huit couverts sur soixante.
2. **On réserve un type, l'instance est affectée plus tard** — personne ne réserve « la chambre 214 ».
3. **Deux niveaux de capacité imbriqués** — une table libre ne suffit pas si le service est complet.

Prudence sur les huit fichiers que CQ-5 a touchés : l'issue du no-show sur le crédit est **orthogonale**
à la facturation, ne la reprends pas par inadvertance.

Cela débloque CQ-3 et CQ-6 derrière toi.

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

## 2026-08-24 18:25 · Ton worktree contourne encore les garde-fous — ne t'arrête pas, mais lis ceci

Tu avais raison sur la topologie, et **la réparation ne t'a pas couverte**. Vérifié à 18:18 :

    /home/debian/wt/claude-G  →  worktree du dépôt NU, aucun remote

Conséquence concrète : quand tu commites, la référence `claude-G` du dépôt canonique est mise à jour
**directement**. Il n'y a pas de push, donc **pas de `pre-receive`, donc aucun garde-fou**. Tes trois
commits fusionnés sont entrés sans contrôle. Ils sont bons — je les ai relus — mais ils auraient pu ne
pas l'être, et rien ne l'aurait dit.

**Ce que je fais :** je passe les garde-fous à la main sur tes commits avant chaque fusion. Tu es
couverte, mais par moi et non par la mécanique, ce qui est exactement l'inverse de ce qu'on veut.

**Ce que je te demande :** tu as dix fichiers modifiés dans `Reservation`. **Ne t'arrête pas pour
autant** — finis ton point d'étape ACT-1, commite, et écris dans ton rapport « arbre propre, prête pour
la réparation de topologie ». Je recrée alors ton worktree depuis le clone. Je ne veux pas te
l'arracher pendant que tu écris dedans : c'est comme ça qu'on perd du travail, et je l'ai déjà fait une
fois sur mon propre correctif.

**Et merci pour les piles de test orphelines.** Tu as signalé le début exact de l'incident des
vingt-six piles du 24/08, sur un périmètre qui n'est pas le tien, sans y toucher. C'est la bonne
conduite : le voir, le dire, ne pas déborder.

---

## 2026-08-24 19:10 · AVANT D'ÉCRIRE UNE MIGRATION — lis ceci, il y a un piège destructeur

`doctrine:migrations:diff` compare les métadonnées Doctrine à la base **entière**. Il ramasse donc
toute la dérive laissée par les autres sessions et te la présente comme si c'était ton travail.

`claude-H` a généré la sienne ce soir depuis un worktree à jour de `main`. Le fichier contenait, en
plus de sa propre table :

    DROP TABLE messenger_messages                      ← la file asynchrone
    DROP INDEX support_ft_article_recherche            ← l'index FULLTEXT du module Support
    CREATE TABLE subscription_provisioning_request     ← la table de claude-D
    + une quinzaine de renommages d'index Finance / DMS / Compta / Stay

**Committé sans relecture, ce fichier fait tomber la file de messages et la recherche d'aide en
préprod** — dans un lot dont le message annonce la création d'une seule table.

**Ce n'est pas un accident isolé.** Quatre migrations portent déjà l'avertissement dans leur en-tête,
des 20, 21 et 22/08. Le piège a été rencontré quatre fois, documenté quatre fois à l'endroit où
personne ne le lit, et jamais arrêté. Trois causes le rendent permanent : `messenger_messages` n'est
mappée par aucune entité, un index `FULLTEXT` n'est pas exprimable en mapping ORM, et une entité écrite
sans sa migration apparaît dans le diff de tout le monde.

**Ce que tu fais, désormais (D32) :**

1. **Le fichier généré est un brouillon.** Tu le relis ligne à ligne, tu gardes ce que **ton** lot a
   provoqué, tu jettes le reste. Au moindre doute, tu écris la migration à la main : `claude-H` l'a
   fait ce soir et cela lui a pris moins de temps que de trier.
2. **Aucun `DROP` que tu n'as pas voulu.** Si ta migration en contient un, tu dois pouvoir dire quelle
   ligne de ton lot l'a causé. Sinon il n'est pas à toi.
3. **Ton entité neuve part avec sa migration, dans le même lot.** La laisser sans migration fait porter
   le coût à toutes les autres sessions — c'est exactement ce qui se passe en ce moment.
4. **Horodate en heure locale.** Le conteneur PHP tourne en UTC, deux heures derrière : une migration
   générée à 19:00 naît `...164417` et se classe **avant** une migration déjà appliquée. Elle
   s'exécuterait hors séquence sur toute base existante. Renomme.
5. **Vérifie sur une base repartie de zéro** avant de pousser.

Merci à `claude-H`, qui a jeté son fichier, écrit la migration à la main, et pris le temps de nous
prévenir alors que rien ne la bloquait.

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

## 2026-08-24 20:45 · Tes trois points, tranchés

Tu as posé tes questions **et pris CQ-3 en attendant**. C'est exactement la règle zéro, et tu es la
seule de la flotte à l'avoir appliquée sans qu'on te le rappelle. ACT-1 point 1 est fusionné.

### Point 2 — quantité et participants : ton refus était le bon, et voici pourquoi

Tu as refusé d'inventer la contrainte parce qu'elle casserait le cas des couverts. Tu as raison, et la
raison est plus profonde que le contre-exemple : **un cours de trois personnes consomme trois places ;
une piste de bowling réservée par trois personnes nommées consomme une piste.** Même réservation, mêmes
participants, deux comptages justes.

L'ambiguïté n'est donc pas dans la réservation — elle est dans **la ressource**. Une place de cours se
compte par personne, une table se compte par unité. Tant que la ressource ne le déclare pas, toute
valeur par défaut est une devinette, y compris celle d'aujourd'hui qui vaut 1.

**La quantité reste explicite. Ne pose aucune contrainte.** La déclaration par la ressource part dans
ACT-0, où elle est à sa place : c'est la composition d'activités de D15.

### Point 3 — la promotion qui n'incrémente pas : prends-le, mais dans son propre commit

C'est ton périmètre, tu l'as trouvé en lisant, et tu sais déjà que c'est une ligne et un test.

**Mais pas dans le lot ACT-1.** Un changement de comportement doit pouvoir être annulé seul et relu
seul ; noyé dans un lot de modèle, il devient indémêlable le jour où il pose problème. Même session,
même soirée, **commit séparé**, message qui dit que le défaut est préexistant et que la quantité le rend
proportionnel au groupe.

Et vérifie la symétrie que tu as toi-même relevée : si tu incrémentes à la promotion, assure-toi que le
décrément existe sur **tous** les chemins de sortie, pas seulement `AnnuleeLibre`. Un compteur qui
n'était pas alimenté ne fuyait pas ; un compteur alimenté à moitié, si. C'est la seule façon de rendre
les choses pires qu'avant, alors regarde-la avant de pousser.

### ACT-1 point 3 — ta proposition est retenue, avec une distinction qui lève ton objection

Ton modèle est le bon : le « service » est un `Creneau` sur la ressource mère, une seule mécanique de
jauge, aucune entité neuve. C'est la couche mince que D16 demande.

Ton objection sur RG-M5-01 tombe si on sépare deux choses que tu avais fondues en une :

- **le créneau VISÉ** — celui que le client choisit, celui qui s'affiche. Il reste **unique**. C'est de
  lui que parle RG-M5-01, et elle n'a pas à être réinterprétée.
- **les créneaux CONSOMMÉS** — le créneau visé, plus ceux des ressources ancêtres qui le couvrent dans
  le temps. Résolus à la réservation, **stockés**.

**Stocke, ne dérive pas.** On pourrait ne rien stocker et remonter l'arbre à chaque contrôle : c'est
plus léger et c'est faux. Ta jauge est un compteur incrémenté sous verrou, et un contrôle dérivé d'un
comptage se course avec lui-même dès deux réservations simultanées sur la même salle. **Ce qu'on
décrémente doit être ce qu'on a incrémenté.**

C'est D33, écrite dans le journal. Tu peux l'implémenter au battement suivant.

### Une dernière chose

Ton commit `45d6558` est fusionné et présent sur `main`. Et l'alias SSH : si tu travailles depuis un
autre bureau que le mien, l'alias `billetterie` n'existe pas chez toi — c'est un raccourci de mon poste,
mon erreur. `grep -i '^Host ' ~/.ssh/config` te donne le tien.

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
