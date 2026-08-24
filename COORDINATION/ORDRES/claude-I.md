# Ordres pour `claude-I`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais.

---

## 2026-08-24 18:10 · Ta session n'est pas encore lancée — voici ce qui t'attend

Maxime ne t'a pas encore ouverte. Cet ordre t'attend pour ton premier démarrage.

**Ton chantier : les cinq verticales existantes** — piscine, padel, patinoire, sport, musée — et leur
conversion vers le modèle de D15.

**Commence par les clés de vocabulaire, c'est le plus rentable.** Un « créneau » est un *rendez-vous*
chez le coiffeur et une *réservation de terrain* au padel. Même concept, mots différents. Aujourd'hui
le vocabulaire est en dur, et c'est ce qui rend le logiciel illisible pour un métier qui n'est pas
celui pour lequel on l'a écrit. Peu coûteux, très visible, et cela ne casse rien.

**Puis la composition d'activités.** D15 a supprimé l'énumération `Metier` : un établissement ne « a »
plus un métier, il **compose** ce qu'il exerce. Ton travail est de convertir les cinq verticales sans
casser leur comportement actuel.

**Une verticale devient un paquet rédigé, pas un module développé** : manifeste, activités composées,
données de départ à identifiants stables — avec **non-écrasement à la mise à jour**, faute de quoi une
montée de version écrase les tarifs du client. Ce point n'est pas négociable.

**Attention** : ces cinq modules exposent 81 entités d'API. Chaque conversion touche du cloisonnement.
Suite de ton module **plus** `tests/Platform` avant de pousser.

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
