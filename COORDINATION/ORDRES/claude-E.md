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
