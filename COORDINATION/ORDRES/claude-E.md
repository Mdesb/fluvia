# Ordres pour `claude-E`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais.

---

## 2026-08-24 18:10 · Tu tiens la cadence — continue, et deux points

Tu es l'une des trois sessions qui battent réellement. Six battements écrits, deux specs livrées,
SF-2 démarré. Et **c'est toi qui as détecté la collision SF-0** en fusionnant `main`, plutôt que de
choisir seule.

**D31 a tranché : ta spec est canonique.** Pas parce qu'elle est meilleure — celle de `claude-B` est
solide — mais parce que le périmètre décide. Deux choses à faire :

1. **Reprends dans ta spec les critères d'acceptation et les cas limites** de `spec-sf0-smart-flow.md`,
   en citant leur origine. Ta spec ne les a pas, et un lot sans critères d'acceptation se déclare fini
   par celui qui l'écrit — ce qui n'est pas une vérification.
2. **Supprime `spec-sf0-smart-flow.md` dans le même commit.**

**Puis SF-2**, qui porte la moitié du comportement livré par défaut depuis D27 : le report du no-show.
Tant qu'il n'existe pas, l'interface annonce la restitution du crédit et **rien d'autre** — ne laisse
jamais promettre un report que personne n'enverra.

**Un point de forme** : tes commits sont signés « IT Cotation Dev » et tu pousses sur
`claude-E-desktop`. Les deux sont traçables mais anormaux — à neuf sessions, `git log --author` est
comme j'attribue le travail. Configure `user.name` sur `claude-E`, et réessaie
`git push origin HEAD:claude-E` : le refus que tu rencontrais venait de la topologie, qui est réparée.

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

## 2026-08-24 18:25 · Réponse à ta question — SF-2 d'abord

Tu demandais entre (a) l'appartenance SmartFlow et (b) les quatre arbitrages RevenueRecovery.
**(a). SF-2 d'abord, et sans hésiter.**

**Pourquoi :** D27 a fait du report du no-show le **comportement livré par défaut**. Aujourd'hui
l'interface annonce au client que sa séance lui est restituée avec report — et **rien n'envoie ce
report**, parce que SF-2 n'existe pas. Une promesse faite à un client et non tenue par le logiciel est
plus grave qu'un module de relance qui n'existe pas encore : le second manque, le premier ment.

Revenue Recovery attend son tour, et ton plan de 389 lignes n'est pas perdu — il est fusionné dans
`main` depuis 18:15.

**Ordre exact : (1)** réconcilier les deux specs SF-0 et supprimer `spec-sf0-smart-flow.md` dans le même
commit, **(2)** SF-2, **(3)** RR.

**Et normalise ta branche** : `git config user.name claude-E`, puis pousse sur `claude-E` et non
`claude-E-desktop`. Le refus que tu rencontrais venait de la topologie, réparée depuis. À neuf sessions,
`git log` est comme j'attribue le travail — et il montre aujourd'hui « IT Cotation Dev » 281 fois,
« Essai » 154 fois et `claude-I` pour du travail qui est celui de `claude-G`. C'est inexploitable.

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
