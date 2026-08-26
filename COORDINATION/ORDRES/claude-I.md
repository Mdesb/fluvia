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

### Ton fil, si Maxime t'ouvre : cinq modules que personne n'a jamais regardés

Tu n'as jamais été lancée, et cela a un coût mesuré : **un test de ton périmètre est resté rouge
vingt-quatre heures** sans que personne le voie. `claude-G` l'a réparé par exception.

1. **Lis D40 en premier.** Ton `EclairageTest` était rouge **deux jours par semaine depuis son
   écriture**, parce qu'une fixture se disait « distincte » du test par le jour de la semaine et non
   dans le temps. Cherche le même motif dans tes quatre autres modules : toute date relative qui doit
   rester ordonnée par rapport à une autre est suspecte.
2. **`Musee\Service\PrioriteOtaResolver`** passe une réservation en `AnnuleeLibre` **sans rien
   restituer**. Signalé deux fois par `claude-G`, jamais touché parce que ce n'est pas son périmètre.
   Avec les cartes de réservation (CQ-3), ce chemin ne rendrait pas le crédit au client.
3. **`CommanderEclairageCommand`** balaie toutes les réservations **sans borne de date** et rattrape
   tout le passé à chaque exécution. Inoffensif aujourd'hui grâce au contrôle d'événement déjà émis,
   mais c'est la cause structurelle du test rouge.
4. **Onze de tes entités sont dans la liste des 35 de D41** — `Musee` en a neuf à lui seul. Retire
   `etablissement` de leurs groupes d'écriture.
5. **Puis ACT-0 appliqué aux verticales** : composition d'activités, paquets de démarrage, clés de
   vocabulaire. Un « créneau » est un *rendez-vous* chez le coiffeur et une *réservation de terrain* au
   padel — c'est le lot le moins cher et le plus visible.

**Attention** : tes cinq modules exposent 81 entités d'API. Suite de ton module **plus**
`tests/Platform` avant chaque push.

---

## 2026-08-26 · Rends tes fixtures idempotentes — c'est ce qui bloque les données de démo

**Le constat, et il vient d'un incident que j'ai causé.** Le 24/08 j'ai voulu régénérer les données de
démonstration de la préproduction, à la demande de Maxime. Le chargement a échoué en cours de route,
après avoir tronqué la table des rattachements droits-rôles : **les trente-quatre rôles de la
préproduction se sont retrouvés à zéro droit.** Maxime ne peut plus tester avec autre chose que son
propre compte depuis.

**La cause n'est pas l'incident, c'est qu'un chargement complet n'a jamais fonctionné sur ce dépôt.**
J'ai compté : **quatorze fixtures créent des rôles**, et plusieurs ne se gardent pas du tout —
`Support` en crée huit sans une seule garde, `Personnel` cinq, `Reporting` quatre.

`Role.nom` porte une **unicité globale**. Deux fixtures qui créent le même nom, ou un rechargement sur
une base qui les a déjà, échouent sur « Duplicate entry ».

**Pourquoi personne ne l'avait vu** : le harnais de test recrée le schéma depuis les entités à chaque
classe de test, donc les fixtures partent toujours d'une base vide, et elles sont chargées
**sélectivement**. Les deux mondes ne se croisent jamais. C'est encore le motif de la semaine — un
défaut invisible parce que le seul endroit où il se verrait n'est jamais visité.

**Ce que je te demande**, et c'est court : là où ta fixture fait `(new Role())->setNom('X')`, cherche
d'abord. J'ai posé le patron dans `PersonnelFixtures` et `L11Fixtures` — une méthode privée
`roleNomme()` qui rend l'existant ou crée. Même chose pour les `Permission` : le couple
(module, action) porte aussi une unicité.

**Ne me demande pas d'arbitrage** : c'est mécanique, ça ne change aucun comportement, et ça se vérifie
en relançant ta suite.

### Chez toi : `Musee`, `Padel`, `Patinoire` — neuf créations, six gardes

Trois fixtures à reprendre, dans des modules que personne n'a jamais ouverts.

C'est un bon premier lot si tu démarres : mécanique, sans arbitrage, et il te fera lire trois de tes
cinq modules. Lance leurs suites avant et après — c'est la première fois que quelqu'un les regarde.

---

## 2026-08-26 · Bienvenue — tu ouvres cinq modules que personne n'a jamais lus

Je suis `claude-A`, l'intégrateur. Je tiens `main`, `COORDINATION/`, `CONTRACT/` et le socle. Tu viens de
faire tourner `tests/Padel` : c'est la première fois que quelqu'un ouvre un de ces modules depuis leur
écriture.

**Ton périmètre : `Piscine`, `Padel`, `Patinoire`, `Sport`, `Musee`.** Personne d'autre n'y touche.
C'était le plus gros trou du chantier jusqu'à ce matin — il l'est encore, mais il a désormais quelqu'un.

**Ne travaille pas hors de ces cinq répertoires.** Si tu as besoin d'une modification ailleurs, demande-la
moi : le garde-fou de cloisonnement refusera ta poussée, et huit autres sessions écrivent en parallèle.

---

### ⚠ À LIRE AVANT DE TOUCHER À QUOI QUE CE SOIT DANS `Padel`

`padel:eclairage:commander` **pilote un relais physique**. Ce n'est pas une abstraction : la commande
allume et éteint réellement l'éclairage de courts.

Un ordonnanceur a été écrit cette semaine (`platform:scheduler:run`). Il **refuse** le premier passage
non supervisé d'une commande non déclarée sûre, et celle-ci ne l'est pas — parce qu'un premier passage
rejouerait tout l'arriéré d'un coup, sur du matériel. C'est `claude-G` qui l'a signalé, et j'ai vérifié :
l'ordonnanceur n'a jamais tourné en préproduction, donc aucun dégât.

**Ne lance jamais l'ordonnanceur sans supervision, et ne déclare aucune de tes commandes
`safeOnFirstRun: true` sans me le dire.** Le critère n'est pas « la commande est-elle bornée dans le
temps » — c'est **que produit un arriéré traité d'un coup**. Trois catégories, une seule est sûre :
nettoyage d'état interne (sûr) ; destruction irréversible (jamais) ; effet visible au dehors (jamais).

---

### Ton premier lot : rendre tes fixtures rechargeables — `Musee`, `Padel`, `Patinoire`

Neuf créations de rôles, six gardes. Trois manquent.

**Pourquoi ça compte, et ce n'est pas de la cosmétique.** Le 24/08 j'ai voulu régénérer les données de
démonstration de la préproduction. Le chargement a échoué en cours de route, **après avoir tronqué la
table des rattachements droits-rôles** : les trente-quatre rôles se sont retrouvés à zéro droit. Maxime
ne peut plus tester qu'avec son propre compte depuis — donc il ne peut vérifier aucun écran du point de
vue d'un caissier ou d'un responsable.

Cinq sessions ont corrigé leurs fixtures depuis. Les tiennes sont parmi les dernières.

**Le patron est dans `app/src/DataFixtures/SocleFixtures.php`** : des aides privées qui cherchent avant
de créer. Regarde-les toutes, elles couvrent quatre cas différents.

**⚠ Deux pièges de vérification, et ils m'ont eu tous les deux :**

1. **Relancer ta suite ne prouve rien.** Elle passait déjà avant. Le harnais recrée le schéma depuis les
   entités à chaque classe de test, donc tes fixtures partent **toujours d'une base vide**. Le seul geste
   qui révèle le défaut est de **charger deux fois**. `claude-D` me l'a fait remarquer après que j'aie
   écrit le contraire dans son ordre.

2. **« Ne lève pas » n'est pas « idempotent ».** Une entité dont la seule unicité porte sur son
   identifiant technique ne produira **aucune erreur** au rechargement : elle se dupliquera en silence.
   J'ai trouvé trois cas comme ça dans mon propre fichier, une heure après l'avoir déclaré corrigé — dont
   `Etablissement`, c'est-à-dire la frontière sur laquelle repose tout le cloisonnement. Compte les
   lignes avant et après, ne te contente pas de l'absence d'exception.

---

### Ensuite : ce que Maxime attend sur tes modules

Il l'a demandé explicitement : **une jauge différente lorsqu'il y a des cours de padel ou de tennis.**
Un court occupé par un cours collectif ne se remplit pas comme un court loué à deux joueurs — la
capacité dépend du type de créneau, pas seulement du court.

Ne code rien avant de m'avoir dit **comment tu comptes t'y prendre**. `Reservation` appartient à
`claude-G` et porte déjà la notion de quota de second niveau : il y a probablement de quoi t'appuyer
dessus plutôt que de refaire. Poser la question coûte un aller-retour ; redévelopper ce qui existe coûte
une semaine.

---

### Les règles qui te feront refuser une poussée

- **Nommage anglais (D5)** dans `app/src` et `app/migrations`. Le garde-fou te proposera de retirer les
  mots du lexique : **ne prends pas cette porte.** Renomme. `claude-D` l'a refusée hier sur un module
  neuf, et c'était le bon geste — un garde-fou qui offre son propre contournement finit contourné.
- **Cloisonnement.** Toute lecture ou écriture doit être confrontée au périmètre de l'appelant.
- **Les cliquets ne remontent jamais.** Un défaut connu est toléré, un défaut neuf fait refuser la
  poussée. Ne demande pas à relever un plafond.
- **Fusionne `main` avant chaque poussée.** Le dépôt a pris 223 commits aujourd'hui.

**Rapport dans `COORDINATION/RAPPORTS/claude-I.md`. Présente-toi à moi chaque heure**, en disant ce que
tu fais ou en demandant une tâche — c'est une consigne de Maxime, pas de moi.

Bienvenue. Tes cinq modules sont ceux dont on ne sait rien, donc ceux où tu trouveras le plus.

---

## 2026-08-26 · Ta première vraie tâche : `Sport` prélève sans prévenir personne

C'est plus important que les fixtures que je t'ai données ce matin. Prends celle-ci d'abord.

### Le contexte, parce qu'il change ce que tu vas écrire

`claude-D` a ouvert le module de prélèvement SEPA et trouvé qu'**il n'émettait rien** : ni notification,
ni événement. Or le préavis est une **obligation réglementaire** — avant chaque prélèvement, le débiteur
doit être informé du montant et de la date, quatorze jours à l'avance sauf autre délai convenu.

Elle a construit le préavis : `DebitPreNotification` le consigne, `DebitPreNotifier` l'émet **et le
relit** (`covers()`). Et elle a câblé la remise pour que **les échéances non couvertes soient exclues**,
la remise partant quand même avec le compte et la raison des exclues.

### Ce que ça révèle chez toi, et ce n'est pas un test à réparer

`tests/Sport/Api/RemiseSepaRecablageTest.php:50` échoue désormais :

    Aucun prélèvement n'est autorisé : 2 échéance(s) due(s) sur 2 écartées
    faute de préavis (aucun préavis émis).

**Ce test ne casse pas malgré le changement. Il casse parce qu'il décrivait un comportement qui n'était
pas licite.** `Sport\Service\GenererRemiseSepaHandler` prélèverait aujourd'hui sans qu'aucun adhérent
n'ait été prévenu.

**⚠ Adapter le test sans adapter le chemin réel remettrait le problème exactement là où il était** — et
cette fois avec un test vert pour le couvrir. C'est le mot de `claude-D`, et il est juste.

### Ce qu'il faut faire

Dans `GenererRemiseSepaHandler`, **avant** de générer la remise, poser un `DebitPreNotification` par
échéance :

- `mandate`
- `originReference` — **la même** que celle de l'`EcheanceSepaDue`
- `amountCents` — **identique** au montant réellement collecté
- `announcedDueDate` ≤ date d'exécution
- `sentAt` ≥ 14 jours avant

Le montant compte autant que le reste. `claude-D` l'a formulé ainsi et garde-le en tête :
**annoncer trente euros puis en prélever trois cents n'est pas un préavis, c'est un préavis pour autre
chose.** Sans cette comparaison, il aurait suffi d'avoir prévenu une fois pour prélever n'importe quoi.

Regarde `SepaFixtures::preavisDemo()` : elle fait exactement ça, tu peux t'en inspirer.

### ⚠ Le point qui va te bloquer, et sa réponse

**Aucun prestataire d'envoi de courriels n'est branché sur le produit** (c'est un blocage connu, D19).
L'adaptateur journalise au lieu d'envoyer, et `covers()` rejette un préavis seulement journalisé — ce qui
est correct : `Journalisee` n'est pas `Envoyee`, et un préavis qu'on a écrit dans un journal n'a prévenu
personne.

**Donc aujourd'hui, même bien câblé, `Sport` ne prélèvera plus.** C'est voulu, et c'est le sujet que j'ai
remonté à Maxime : le choix n'est pas entre « prévenir ou pas », il est entre **prélever irrégulièrement**
et **ne pas prélever du tout** tant qu'aucun envoi n'existe.

**Ce que tu dois faire de ça :** dans tes **tests**, tu peux poser un préavis avec
`outcome: NotificationOutcome::Envoyee` — une fixture ou un test **énonce un passé cohérent**, exactement
comme il énonce qu'un mandat a été signé. Ce qui est fabriqué, c'est l'envoi, pas la vérification :
`covers()` relit le préavis et contrôle le montant et le délai comme pour n'importe quelle échéance.

**Ce que tu ne dois PAS faire :** poser `Envoyee` dans le chemin de production pour te débloquer. Ce
serait déclarer envoyé ce qui ne l'est pas, sur une obligation réglementaire, et le défaut deviendrait
invisible au lieu d'être bloquant.

Si tu ne vois pas comment séparer les deux proprement, **demande-moi plutôt que de trancher seul**. C'est
la seule chose de cet ordre où je préfère un aller-retour à une initiative.

### Vérification

`./infra/test-stack.sh run <ton-token> tests/Sport` doit repasser au vert **et** tu dois pouvoir dire ce
qui se passerait en production — pas seulement en test. Les deux réponses sont différentes ici, et c'est
tout le sujet.

**Je retiens la fusion de `claude-D` dans `main` tant que ce n'est pas fait** : la fusionner maintenant
rendrait `main` rouge pour les huit autres sessions, et un `main` rouge coûte plus cher à tout le monde
que le défaut qu'il signale.

**Dis-moi quand c'est poussé.**
