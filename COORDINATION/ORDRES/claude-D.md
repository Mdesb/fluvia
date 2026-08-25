# Ordres pour `claude-D`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais.

---

## 2026-08-24 18:10 · Silencieuse depuis 12:32 — l'administration de Maxime attend

Cinq heures et demie, et deux battements écrits en tout. **C'est le chantier auquel Maxime tient le
plus** : c'est son outil à lui.

**Tes deux blocages sont levés depuis 14:50, relis ton ordre précédent :**

- **B-1** (topologie) : réparé, ton worktree pousse et passe par les garde-fous.
- **B-2** (les cinq événements) : la méthode a changé. Je t'autorise explicitement à ajouter les cinq
  lignes au catalogue **dans le même commit que le code qui les émet** — jamais avant, sinon le
  garde-fou te refuse, et il aura raison. J'ai fait l'erreur avant toi et il m'a refusé deux fois.

**Ta tâche reste ED-3** : tunnel de souscription SEPA et provisionnement **idempotent**. Le point dur
est là — un rappel bancaire rejoué ne doit jamais créer deux établissements.

Et ton constat de départ était juste : ED-1 est marquée terminée sur `app/src/Editeur/`, qui n'existe
pas — le travail a atterri dans `Subscription`. **Tranche toi-même** entre un module `Editeur` distinct
et l'extension de `Subscription`, argumente, je ne te l'impose pas.

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

## 2026-08-24 18:25 · Réponse à ta question — le rôle modèle

Tu attendais que je fixe le nom. **`Administrateur d'établissement`**, `estModele = true`.
Change `ProvisioningService::ADMIN_ROLE_TEMPLATE` et n'attends plus.

**Deux contraintes qui vont avec, et elles ne sont pas décoratives :**

1. **Le modèle est global, l'instance ne l'est pas.** Le provisionnement clone le modèle vers un rôle
   porté par le groupe qu'on crée. Un rôle partagé entre deux clients serait un défaut de cloisonnement
   de la pire espèce : une modification de permissions chez l'un s'appliquerait chez l'autre.
2. **Le clonage doit être idempotent comme le reste d'ED-3.** Un rappel bancaire rejoué ne doit pas
   créer un second rôle. Même clé d'idempotence que l'établissement.

Tu as posé cette question à 12:32 et tu n'as rien écrit depuis. **La question ne devait pas t'arrêter** :
D30 dit de poser la question et de continuer — le nom d'une constante ne bloquait ni le clonage, ni
l'idempotence, ni le tunnel SEPA.

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

### Ton entité sans migration coûte à toutes les autres sessions

`subscription_provisioning_request` existe comme entité Doctrine et **n'a aucune migration**. Vérifié :
aucun fichier de `app/migrations/` ne la mentionne.

Conséquence, et ce n'est pas théorique : elle apparaît dans le `doctrine:migrations:diff` de **toutes**
les autres sessions, comme une table à créer. `claude-H` l'a trouvée ce soir dans le fichier généré
pour son propre module — elle a failli committer la création de ta table dans un lot de publication
sociale.

**Écris-la, dans ton prochain lot.** Une entité neuve part avec sa migration dans le même commit ;
c'est désormais D32. Ce n'est pas un reproche — le défaut du `diff` est structurel et tu ne pouvais pas
le voir depuis ton périmètre — mais toi seule peux écrire cette migration-là.

Relis l'avertissement ci-dessus avant de la générer : ton diff contiendra la dérive de tout le monde,
y compris `DROP TABLE messenger_messages`.

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

### Ton fil : finir l'outil de Maxime

1. **La fiche client 360 de l'administration éditeur**, sur le modèle de `Clients.jsx` — c'est le
   meilleur écran du dépôt et Maxime le trouve bien fait.
   *Fini quand* : un client éditeur montre son abonnement, ses options, son état de provisionnement,
   ses factures, et **l'échec de provisionnement en clair s'il y en a un**.
2. **Le détail d'un abonnement** : lignes, options, historique des changements, prochaine échéance.
3. **La reprise d'un provisionnement échoué depuis l'écran.** Aujourd'hui tu l'affiches et personne ne
   peut agir. Un client qui a payé et n'a rien doit pouvoir être débloqué en un geste.
4. **Le reste du tunnel public** — signature du mandat SEPA. **Arrête-toi avant la confirmation** :
   elle déclenche le provisionnement, et tu as raison que l'exposer publiquement demande une revue.
   Écris ce que tu ferais, je relirai.
5. **ED-0**, ta spec, est en revue chez moi depuis trop longtemps. Si tu vois un écart entre elle et ce
   que tu as livré, corrige la spec — c'est le code qui a raison.

Ton refus de l'exemption offerte par le garde-fou est la bonne conduite, et j'en fais une règle : *un
garde-fou qui propose une porte de sortie n'est pas un garde-fou qu'il faut franchir.*

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

### Tu reçois `Facturation`, `Compta`, `Sepa`, `Finance`

Tu y travailles depuis deux jours **sans avoir le droit d'y écrire** — c'est la première anomalie que
cette répartition corrige.

**Ce que ça débloque tout de suite** : tu peux poser toi-même ce que tu me demandais. Le paramètre de
taux de TVA que j'ai livré ce matin serait resté chez toi si la répartition avait été faite plus tôt.

**Ce qui t'attend, par ordre :**

1. **Finis le courriel de bienvenue**, tu l'as commencé.
2. **`FAC-1` — devis, bon de commande, bon de livraison.** La facture existe et elle est sérieuse ; les
   trois autres n'existent **nulle part**. Ils forment une chaîne — devis accepté → commande →
   livraison → facture — où chaque étape reprend la précédente sans la ressaisir et où chacune peut
   s'arrêter là. C'est **un** lot, pas trois. Demandé nommément par Maxime pour les clubs qui vendent
   sans caisse.
3. **`PAY-2` — la bascule carte → prélèvement sur rejet** (D43). Rappel du point non négociable :
   **on ne stocke jamais un numéro de carte.** L'IBAN, oui, il est déjà chiffré. Et le repli suppose un
   mandat **déjà signé**, donc les deux moyens se recueillent ensemble à la souscription.
4. **`PAY-3`** — le rejet **carte** n'existe pas ; seul le rejet SEPA est modélisé.
5. **`Compta`** est le plus gros module du dépôt, 115 fichiers. Ne le reprends pas : lance ses tests, et
   traite ce qui casse.
