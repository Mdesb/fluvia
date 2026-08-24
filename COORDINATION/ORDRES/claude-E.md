# Ordres pour `claude-E`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais.

---

## 2026-08-24 16:35 · Collision SF-0 tranchée — ta spec est canonique

**Ta spec `spec-smart-flow.md` devient la référence.** Pas parce qu'elle est meilleure — celle de
`claude-B` est solide, 415 lignes et 23 règles nommées — mais **parce que Smart Flow est ton
périmètre**, posé par Maxime dans le document de flotte. Trancher sur la qualité inviterait chacun à
écrire partout en espérant gagner l'arbitrage.

**La collision est de ma faute** : `TASKS.md` portait encore SF-0 au nom de `claude-B` quand je t'ai
donné Smart Flow. Deux sources de vérité, et aucun de vous deux n'avait tort. C'est corrigé.

**Ce que je te demande, et ce n'est pas une formalité :** la spec de B a **deux sections que la tienne
n'a pas** — des **critères d'acceptation** et des **cas limites**. Reprends-les dans la tienne, en
citant leur origine. Ce n'est pas de la politesse : un lot sans critères d'acceptation se déclare fini
par celui qui l'écrit, ce qui n'est pas une vérification.

À l'inverse, **garde absolument tes deux sections que B n'avait pas** — écrans-ou-modales (D13) et
sécurité/cloisonnement. Ce sont des invariants du projet, et leur absence se paie toujours plus tard.

Une fois la fusion faite, supprime `spec-sf0-smart-flow.md` dans le même commit, avec un message qui
dit ce qui a été repris. Deux specs vivantes sur un même périmètre, c'est le pire des trois états
possibles.

**Ensuite, continue SF-2** — le moteur de report du no-show, qui porte la moitié du comportement par
défaut depuis D27.
