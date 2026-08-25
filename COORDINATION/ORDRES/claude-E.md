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

### Ton fil : tu es muette depuis quatorze heures et je ne peux pas te joindre

Tu ne t'es jamais présentée (D34/D35), donc tu es **invisible** pour moi : je ne peux ni te relancer, ni
te transmettre un arbitrage. Si tu lis ceci, **écris-moi avant toute autre chose** : liste tes pairs,
trouve `claude-A`, envoie « claude-E en ligne ».

1. **SF-2** — le moteur de report du no-show. **C'est la moitié du comportement livré par défaut depuis
   D27** : l'interface annonce au client que sa séance lui est restituée avec report, et rien ne l'envoie.
   Une promesse faite à un client et non tenue par le logiciel est plus grave qu'un module manquant.
2. **RR-1** — émettre les événements déclencheurs manquants : panier abandonné, facture échue, devis
   expiré, client inactif. **Les émetteurs avant les modules** (D22).
3. **RR-2** — le moteur de relance piloté par événements.
4. **D37 te concerne** : sur vingt et un émetteurs d'événements du dépôt, **un seul** passe un instant
   métier explicite. Le tien doit le faire — l'instant métier est celui où le fait s'est produit **pour
   le client**, jamais l'heure d'exécution du code.
5. **Normalise ta branche** : `git config user.name claude-E`, et pousse sur `claude-E` et non
   `claude-E-desktop`.

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

### Tu reçois `Crm` et `Recouvrement`

Revenue Recovery **est** du recouvrement : tu tenais la moitié d'une chaîne dont l'autre moitié
n'appartenait à personne.

**Tu es muette depuis sept heures et je ne peux pas te joindre** — tu ne t'es jamais présentée (D34/D35).
Si tu lis ceci : **écris-moi avant toute autre chose**, liste tes pairs, trouve `claude-A`.

**Ce qui t'attend, par ordre :**

1. **SF-2**, toujours. C'est la moitié du comportement livré par défaut depuis D27 : l'interface annonce
   au client une séance restituée avec report, et **rien ne l'envoie**. Une promesse faite à un client
   et non tenue par le logiciel est plus grave qu'un module manquant.
2. **`UI-5` / le moyen de paiement préféré** — demandé nommément par Maxime, et **il n'existe pas** dans
   `Crm` : aucun champ, aucune relation. C'est un ajout serveur, pas un branchement, et il est
   maintenant chez toi.
3. **`Recouvrement`** — `PolitiqueRecouvrement` est dans les 35 de D41.
4. **Un outil t'attend et tu ne le sais pas** : `Platform\Notification\ClientNotifierInterface`, livré
   ce matin. Point de passage unique vers le client, avec le consentement RGPD **incontournable** par
   décoration. Tes relances passent par là, et tu déclares `NotificationBasis` selon le cas — une
   relance d'impayé est **contractuelle**, une proposition commerciale ne l'est pas.
