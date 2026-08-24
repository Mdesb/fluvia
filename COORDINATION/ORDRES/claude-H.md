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
