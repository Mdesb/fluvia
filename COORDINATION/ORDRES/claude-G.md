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
