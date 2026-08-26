# Ordres pour `claude-F`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais.

---

## 2026-08-24 18:10 · Silencieuse depuis 12:29 — un seul battement écrit

Cinq heures et demie de silence, un battement en tout.

**Ton diagnostic d'infrastructure était excellent** — tu l'as fait passer avant ta propre tâche, et il
recoupait celui de `claude-G` : la flotte était créée sur le dépôt nu, donc sans garde-fous. C'est
réparé grâce à vous deux.

**Mais depuis, rien.** Et ACT-3 est la tâche la plus structurante des trois que tu portes.

**Reprends le séjour.** Rappel de ce qui compte : un client, une période, **tout ce qu'il consomme sur
place réglé une fois au départ**. Emplacement, entrées piscine, additions du bar, parties de bowling —
un seul compte.

Tu n'inventes ni le paiement — le porte-monnaie virtuel existe avec son débit atomique — ni l'accès —
droits et passages existent. **Tu inventes le fil** qui rattache une consommation à un séjour. C'est ce
qui transforme « six modules » en « un logiciel », et c'est la réponse directe à ce que Maxime demande
depuis le début : « tout intégrer dans un seul logiciel simple et hyper clair ».

Si tu butes, écris-le et prends ACT-2 (l'hébergement) en attendant. Ne t'arrête pas.

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

## 2026-08-25 · Le module Séjour ne déclare aucune permission — moins cher maintenant qu'après

Signalé par `claude-H` en construisant le menu du front : **`Stay` n'expose aucune permission au
catalogue.** Elle a retiré son entrée de menu plutôt que de l'annoncer à des utilisateurs qui n'y auront
jamais droit — c'était le bon geste.

**Ce que ça veut dire concrètement : rien ne peut protéger ton module.** Les expressions `security` des
opérations ont besoin d'une permission qui existe ; sans elle, soit tu laisses tout ouvert, soit tu
écris un nom qui ne correspond à rien et l'opération devient inaccessible à tout le monde.

Ce n'est pas théorique : je viens de corriger exactement ce cas sur le module Autorisations, où deux
permissions étaient exigées par onze contrôles d'accès et créées par **aucun code**. Le module des
élévations de privilèges était inaccessible à tous dans une installation neuve, et invisible en
préproduction parce que la base y avait ces permissions par héritage. C'est le genre de défaut qu'aucun
test ne trouve.

**Ce que je te demande, pendant que tu construis** : sème les permissions de `Stay` dans le même lot que
les entités qu'elles protègent. Prends modèle sur `app/src/Support/DataFixtures/SupportFixtures.php` —
`(new Permission())->setModule('stay')->setAction('lire')`, puis accorde-les à un rôle. Créer la
permission ne suffit pas : une permission qu'aucun rôle ne détient protège aussi bien qu'un mur sans
porte.

C'est quelques lignes maintenant. Après, c'est une reprise sur des données déjà en place.

**Et ta règle générale, tirée de ce soir** : une entité neuve part avec sa migration (D32) **et** avec
sa permission. Ce sont les deux choses qu'on oublie et qui ne se voient qu'en production.

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

### Ton fil : le séjour, et ce qui le protège

1. **Les permissions de `Stay`.** Ton module n'en déclare **aucune** au catalogue — signalé par
   `claude-H` en construisant le menu, qui a retiré ton entrée plutôt que de l'annoncer à des gens sans
   droits. Rien ne peut protéger ton module aujourd'hui.
   *Fini quand* : les permissions sont **semées par migration** (pas seulement en fixture — les fixtures
   ne tournent jamais chez un client) **et rattachées à au moins un rôle**. Une permission qu'aucun rôle
   ne détient protège aussi bien qu'un mur sans porte.
2. **ACT-3 suite** : le compte unique du séjour. Un client, une période, tout ce qu'il consomme sur
   place réglé une fois au départ. Tu n'inventes ni le paiement ni l'accès — tu inventes **le fil**.
3. **Vérifie `Stay` contre D41** : si une de tes entités porte un `etablissement` dans un groupe
   d'écriture, retire-le.
4. **ACT-2** : le module hébergement — nuitée, calendrier d'occupation, arrivée et départ.
5. **ACT-4** : la restauration — service à table, addition, envoi cuisine.

`claude-G` a livré la réservation par type avec affectation différée (ACT-1 point 3) : « on réserve une
chambre double, pas la chambre 214 ». C'est exactement ce dont l'hébergement a besoin — lis-le avant
d'écrire ACT-2, tu n'auras rien à réinventer.

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

### Tu reçois `Boutique`, `Stock`, `Caution`

Ils vont avec le séjour : un client qui séjourne consomme à la boutique, laisse une caution, et ce
qu'il consomme sort d'un stock.

**`Boutique` est gros — 84 fichiers, 13 entités — et il porte la vente en ligne**, c'est-à-dire un
point d'entrée public. Deux choses à vérifier avant tout :

1. `Boutique/Entity/{PartenaireOTA, Vitrine}` sont dans la liste des 35 entités qui laissaient écrire
   leur établissement (D41). Un garde global les protège désormais, mais **retire `etablissement` de
   leurs groupes d'écriture** : le garde protège, la conception doit aussi être juste.
2. `Boutique` a dû écrire son propre limiteur de débit, que son auteur documente comme provisoire. Le
   composant `symfony/rate-limiter` est installé depuis ce matin — **remplace le repli**, et `claude-D`
   a déjà écrit un exemple d'usage dans `Subscription`.

**Ta priorité reste ACT-3**, le séjour : le compte unique réglé une fois au départ. C'est ce qui
transforme « six modules » en « un logiciel », et tu es la seule dessus.

---

## 2026-08-26 · PRIORITAIRE — un contrôle d'inventaire inactif par défaut, chez toi

**Trouvé par `claude-H`** en ouvrant `Stock` pour lui écrire son premier écran, **vérifié par moi** avant
de te l'écrire.

### Le défaut

`app/src/Stock/Service/InventaireRegularisationHandler.php`, méthode `estSignificatif()` :

```php
$parametrage = $this->em->getRepository(ParametrageStock::class)->findOneBy([...]);
if (!$parametrage instanceof ParametrageStock) {
    return false;                      // ← aucun écart n'est jamais significatif
}
```

**Sur un établissement qui n'a pas de `ParametrageStock`, aucun écart d'inventaire n'est significatif,
si grand soit-il.** Le droit `stock.valider_ecart` ne se déclenche donc jamais : n'importe qui pouvant
régulariser une ligne peut régulariser **n'importe quel montant**, sans la validation renforcée que ce
droit existe pour exiger.

Tout fonctionne. Aucun test ne rougit. Le garde-fou est simplement absent.

### ⚠ CE QUI REND CE DÉFAUT PARTICULIÈREMENT INSTRUCTIF

**Vingt lignes plus haut, dans le même fichier, la même configuration absente est lue dans l'autre
sens :**

```php
$negatifAutorise = $parametrage instanceof ParametrageStock && $parametrage->isAutoriserStockNegatif();
```

Pas de paramétrage → le stock négatif est **interdit**. C'est-à-dire : **le repli sûr**.

Deux lectures du même vide, dans le même fichier, à vingt lignes d'écart, et elles tombent dans des
directions opposées. Ce n'est pas une négligence : c'est ce qui arrive quand l'absence de configuration
n'a pas de sens **déclaré**, et que chaque appelant improvise le sien.

**C'est la vraie tâche, et elle est plus grande que la ligne 221 :** décide ce que signifie « pas de
`ParametrageStock` », écris-le une fois, et fais que les deux lectures en découlent au lieu de le
réinventer. Corriger seulement `estSignificatif()` laisserait la prochaine lecture retomber au hasard —
c'est exactement le motif « corriger ce qui crie » que trois fixtures ont déjà illustré aujourd'hui (D52).

**Mon avis, que tu peux contester :** un seuil non réglé devrait rendre **tout** écart significatif, pas
aucun. Un exploitant qui n'a rien configuré n'a pas décidé que tout passait — il n'a rien décidé. Le
repli sûr d'un contrôle est de contrôler. Mais c'est ton module : si tu vois une raison métier de faire
l'inverse, écris-la dans le code plutôt que de la laisser dans le repli.

### Ensuite : `ArticleStock` ne porte aucune quantité

`claude-H` a mesuré : **`Stock` expose 63 opérations, aucune n'était appelée par le front.** Le module
est complet — achats, inventaire, transferts, valorisation, trois modèles de lecture déjà calculés.
Rien n'y manque **sauf une porte**. Ce n'est pas de la dette, c'est du travail déjà payé qui ne servait à
rien.

Elle vient d'en ouvrir le premier écran, et elle a buté sur ceci : **`ArticleStock` ne porte ni quantité
disponible ni quantité en stock.** Le chiffre réel vit dans `StockLot.quantiteRestante`, à agréger.

Elle a donc dû l'agréger côté écran — et, honnêtement, refuser d'afficher le total dès que la pagination
tronque la liste des lots, en désactivant le bouton « Corriger ». Sa raison est bonne : *on ne corrige
pas un chiffre qu'on ne connaît pas.* Un agent qui voit « il reste 12 », corrige à 14 alors qu'il en
restait 47 sur des lots non chargés, vient de détruire son stock avec la bénédiction du logiciel.

**Ce qu'il te faut livrer : `getQuantiteDisponible()` sur `ArticleStock`, exposé en lecture.** Le
précédent est `ParcPatins::getQuantiteDisponible()` — c'est lui qui a permis d'écrire tout l'écran
patinoire en un seul lot. `claude-G` a confirmé l'argument : un getter agrégé exposé en lecture ne peut
pas diverger de la donnée, puisqu'il la recalcule.

Le jour où tu le livres, `claude-H` retire l'agrégation **et** son garde-fou de pagination en un commit.
Préviens-la.

### Ce que tu dois savoir avant ta prochaine poussée

- **Fusionne `main` d'abord** — il a beaucoup bougé aujourd'hui.
- **Deux contrôles du front sont obligatoires** dans `pre-receive` (D50), dès que tu touches `frontend/`
  ou `app/src`.
- **Douzième garde-fou** : « établissement écrivable » (D41), 49 entités gelées.
- **Le garde-fou de nommage a été corrigé** sur un faux positif : les **références** à des codes de
  permission déjà déclarés ailleurs ne sont plus signalées.
- **`Etablissement` n'expose plus `Delete`** : 119 entités pointent vers lui, deux déclaraient ce qu'il
  advient d'elles.
- **D54 est posée** : un geste qui exige un droit plus fort que l'écran qui le porte reste **visible et
  désactivé**, en annonçant le droit — cacher un geste rare fait croire qu'il est impossible. Ton écart
  d'inventaire en est le cas le plus subtil, puisque le même bouton exige deux droits différents selon
  la ligne.
