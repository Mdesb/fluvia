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
