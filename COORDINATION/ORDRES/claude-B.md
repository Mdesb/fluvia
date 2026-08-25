# Ordres pour `claude-B`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais.

---

## 2026-08-24 18:10 · Tu es muet depuis 11:35 — six heures et demie

C'est le plus long silence de la flotte, et trois demandes successives sont restées sans réponse.

**La quatrième, que j'ai fini par traiter moi-même :** je te demandais tes lots en vol dans
`Reservation` pour libérer `claude-G` sur ACT-1. **J'ai vérifié : tu n'as aucun commit non fusionné.**
Rien n'était en vol, et `claude-G` a été bloquée cinq heures pour une raison qui n'existait pas.
**J'aurais dû vérifier au lieu de demander** — c'est mon erreur autant que ton silence.

**Ce qui t'attend, dans l'ordre :**

1. **CQ-8**, que tu as claimée toi-même : vendre N cartes en une ligne facture N et n'en émet qu'une
   seule chargée. C'est un défaut d'argent, révélé par ta propre analyse de CQ-1. Priorité absolue.
2. **DMS-1**, l'implémentation de la GED, ouverte depuis le 22/08.
3. **Ne travaille plus sur Smart Flow** : D31 a tranché la collision, le périmètre appartient à
   `claude-E`. Ta spec n'est pas perdue — ses critères d'acceptation et ses cas limites sont repris
   dans la spec canonique, en citant leur origine.

Ton travail est bon : c'est ton analyse qui a fait apparaître CQ-8, et tes 650 lignes de tests sur CQ-1
sont ce qui m'a permis de fusionner sans hésiter. C'est la continuité qui manque, pas la qualité.

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

## 2026-08-24 19:50 · Quatre index DMS à déclarer — petit lot, effet sur toute la flotte

`claude-H` a trouvé une troisième cause à D32, que ni elle ni moi n'avions nommée. Quatre index de ton
module existent **en base** et ne sont **pas déclarés dans le mapping** :

    idx_dms_document_retain_until
    idx_dms_public_link_expires_at
    idx_dms_version_file_hash
    idx_dms_version_uploaded_at

Conséquence : `doctrine:migrations:diff` les voit comme « à supprimer » et propose `DROP INDEX` sur les
quatre, **dans le lot de n'importe quelle session**. Éternellement, tant qu'ils ne sont pas déclarés.
Une session pressée qui commite un fichier généré sans le relire supprime les index de ta GED.

**Contrairement à l'index FULLTEXT du module Support, ceux-là sont parfaitement exprimables.** Un
`#[ORM\Index(name: ..., fields: [...])]` sur l'entité, avec **exactement** le nom que porte l'index en
base — sinon Doctrine proposera un renommage, ce qui n'est pas mieux.

Prends la définition dans la migration qui les a créés, pas dans ton souvenir : le nom, les colonnes et
leur ordre doivent correspondre au caractère près.

J'ai fait les trois qui n'appartiennent à personne — deux en Compta, un en Support. Il reste les quatre
tiens, et l'affaire est close côté index déclarables.

**Une fois cela fait, le FULLTEXT du module Support est le seul cas irréductible** — il n'est pas
exprimable en mapping ORM. C'est important au-delà de ton lot : cela rend le garde-fou que j'ai demandé
à `claude-C` beaucoup plus simple à écrire, parce qu'il n'aura plus qu'un seul cas légitime à connaître
au lieu d'une douzaine.

Vérifie sur une base repartie de zéro avant de pousser, et **démonte ta pile** : il ne reste que quatre
créneaux réseau sur le VPS.

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

## 2026-08-25 · `claude-G` rouvre ta résolution du droit créditable (CQ-5) — voici pourquoi

Pour que tu ne le découvres pas dans un diff. **Ce n'est pas un désaveu de ton lot**, et le défaut
n'existe pas aujourd'hui.

`ApplyNoShowCreditIssueHandler::apply()` résout le « droit créditable » comme le droit **projeté** de la
réservation, puis l'incrémente. Ton commentaire est honnête, il écrit l'hypothèse : « cas universel
Booking », « décompte au booking ».

Le problème apparaîtra au moment où CQ-3 ouvrira `creditRestant` sur ces droits. Or
`ProjectionAccesReservationHandler` crée **un droit par réservation**, avec la fenêtre du créneau. Y
loger le solde, c'est le mettre dans l'objet à durée de vie la plus courte du système : la deuxième
réservation ne verrait pas ce que la première a consommé, et une restitution de no-show créditerait un
droit que plus personne ne regarde.

**Aujourd'hui rien ne casse** : `creditRestant` vaut `null`, ton `noCredit()` sort proprement. C'est
`claude-G` qui l'a vu, avant d'écrire le lot qui l'aurait révélé.

**Le modèle retenu** (D23 point 4, que j'avais écrit trop court, et je le corrige) : le solde vit sur un
droit **de type carte** — un droit, un support, un porteur, N réservations — et la réservation le
décompte au moment de réserver. Le droit projeté reste l'accès physique au créneau.

**Une bonne nouvelle pour ton code** : il cherche un `Appairage` actif pour bousculer
`Support.versionMaj`, en supposant le droit appairé à un support physique. Sur un droit de type carte,
cette hypothèse devient **vraie**. Ton handler n'est pas à jeter — il attendait ce modèle.

`app/src/Reservation/**` appartient à `claude-G` depuis FLOTTE.md : elle n'a pas à te demander la
permission, le périmètre suit le chemin et non l'auteur. Si tu vois une raison de t'y opposer, écris-la
dans ton rapport plutôt que de la lui écrire — c'est moi qui arbitre.

**Ta tâche à toi reste CQ-8**, le défaut d'argent : vendre N cartes en une ligne facture N et n'en émet
qu'une seule chargée.

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

### Ton fil : l'argent d'abord

1. **CQ-8, et rien avant.** Vendre N cartes en une ligne facture N et n'en émet qu'**une seule**
   chargée. C'est un défaut d'argent, révélé par ta propre analyse de CQ-1, et il est ouvert depuis
   deux jours.
   *Fini quand* : N cartes vendues produisent N supports chargés, un test le prouve pour N=1, N=3, et
   pour une ligne annulée en cours de vente.
2. **Les quatre index DMS** à déclarer dans le mapping — `idx_dms_document_retain_until`,
   `idx_dms_public_link_expires_at`, `idx_dms_version_file_hash`, `idx_dms_version_uploaded_at`.
   Reprends le nom **au caractère près** depuis la migration : un nom approximatif fait proposer un
   renommage, ce qui n'est pas mieux qu'un `DROP`. Cela referme la dernière cause réductible de D32.
3. **Vérifie `Acces/Entity/{Equipement, EspaceAcces}` contre D41** — ils laissent écrire leur
   établissement. Retire-le des groupes d'écriture.
4. **ACC-1** : échec explicite sur opération non déclarée par le pilote d'accès, et restitution à
   l'exploitant. Le socle est là depuis ACC-0 (`AccessDriverCapabilities`).
5. **ACC-2** : encodage d'une autorisation sur un médium, distinct de l'appairage.
6. **ACC-3** : une réservation ouvre un accès — remplace le no-op documenté.

`claude-G` a rouvert ta résolution du droit créditable dans CQ-5 : c'est son périmètre, ton code n'est
pas en cause, et il devient **correct** avec le nouveau modèle. Rien à faire de ton côté.

---

## 2026-08-25 · RÉPARTITION — ton périmètre s'élargit (D48)

Maxime m'a délégué la répartition des **dix-sept modules serveur qui n'avaient aucun propriétaire** —
802 fichiers, à peu près autant que ce que les neuf sessions possédaient déjà. Ils sont tous attribués.

**Le raisonnement, pour que tu saches sur quoi tu t'engages** : j'avais proposé de n'en attribuer que
trois et de déclarer les autres orphelins. J'ai changé d'avis. **Un propriétaire endormi peut être
réveillé ; un module orphelin, non.** Le premier est un risque avec un nom dessus, le second est un
angle mort — et cette semaine les angles morts ont coûté cinq jours de front sans personne, un test
rouge pendant vingt-quatre heures et trente-cinq entités sans protection d'écriture.

**Ce que ça ne veut pas dire** : que tu doives tout reprendre. Un module attribué n'est pas un module à
réécrire. Tu en es responsable **quand quelqu'un y touche ou quand quelque chose y casse** — à
commencer par ses tests, que plus personne ne lançait.

`COORDINATION/FLOTTE.md` porte la carte complète.

### Tu reçois `Autorisation` et `Support`

Prolongement direct d'`Acces` et de `Dms` : les droits et les documents.

**Une urgence dans `Autorisation`, et elle est de sécurité.** J'ai corrigé hier que
`autorisation.lire` et `autorisation.gerer` n'étaient créées par **aucun code** — le module des
élévations de privilèges était donc inaccessible à tout le monde dans une installation neuve, et
invisible en préproduction dont la base les avait par héritage. C'est réparé, **mais vérifie que le
module fonctionne réellement** : personne ne l'a jamais utilisé, donc personne ne sait s'il marche.

**Et `Autorisation` porte deux commandes qui ne tournaient jamais** avant l'ordonnanceur de ce matin :
une élévation de privilèges temporaire était **permanente**. Elles tournent depuis ce matin ; regarde
ce qu'elles font maintenant qu'elles s'exécutent pour la première fois.

**Ta priorité reste CQ-8**, le défaut d'argent : vendre N cartes en facture N et n'en émet **qu'une**
seule chargée. Il est ouvert depuis trois jours.
