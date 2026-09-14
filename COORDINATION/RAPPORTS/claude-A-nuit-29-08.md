> ⛔ **ARCHIVE — canal retiré.** L'ancienne coordination artisanale (`ORDRES/`, `RAPPORTS/`, `TASKS.md`, `MESSAGES.md`, battement de 15 min) est **remplacée par branches + PR + Issues** — voir `CLAUDE.md`, section « Ce qui a été retiré ». Fichier conservé pour mémoire : **ne rien y écrire, ne pas le lire comme un canal actif.**

# Nuit du 28 au 29 août — claude-A (intégrateur, moteur)

## Ce qu'il faut lire en premier

**Je dois corriger une annonce que j'ai faite hier et qui était fausse.** J'ai écrit « la mise en
service est débloquée » après avoir posé le profil exploitant et les taux de TVA. C'était vrai du
verrou levé et faux de la chaîne : sur un établissement réellement créé, il n'existait aucun compte
comptable et aucun journal, et rien dans l'application ne permettait d'en saisir. L'écran de
comptabilité disait « aucun profil d'exploitant » ; le profil une fois posé, il aurait dit « aucun
journal », puis « aucune période ».

J'avais mesuré ce que j'avais réparé et conclu sur ce que je n'avais pas mesuré. C'est allaccess-34
qui l'a vu en vérifiant avant d'écrire un écran, et allaccess-b8 qui l'a confirmé dans le code.
C'est corrigé (voir plus bas), mais l'erreur de méthode compte plus que le correctif.

---

## Livré cette nuit

### La zone d'accès, de bout en bout

Maxime voulait pouvoir dire « ce produit ouvre telle ou telle zone ». La règle de refus existait
déjà et **personne ne pouvait la déclarer** : `DroitAcces::$authorisedSpaces` n'avait aucun écrivain
dans tout le dépôt. Toute collection restait vide, donc « ouvre tout », donc la règle ne refusait
jamais rien. Un mécanisme juste, un test vert, et un exploitant qui ne peut pas s'en servir.

- La déclaration vit sur le **produit**, pas sur le droit : un droit naît d'une vente, et porter la
  règle sur lui obligerait à la poser billet par billet après chaque vente.
- `ProductAccessZoneResolver` en dépose une copie sur le droit à la projection. La copie n'est pas
  une commodité : c'est le droit que les terminaux embarquent pour décider **hors ligne**.
- C'est une **synchronisation** : retirer une zone du produit la retire du droit à la re-projection.
  Sans cela, le retrait n'aurait aucun effet sur les titres émis — et n'en aurait aucun sans rien
  dire.
- **Un lecteur peut desservir plusieurs zones** : un tourniquet entre la piscine et la salle de sport
  laisse passer les deux. Ajout et non changement de cardinalité — les sept appelants de
  `Controleur::getEspace()` ont tous besoin d'un espace unique, parce qu'un passage a lieu à un seul
  endroit physique.

⚠ **La limite, et elle est voulue** : la jauge décrémentée, l'anti-passback et l'espace inscrit sur
le passage restent ceux de la zone principale, même quand le titre est accepté au titre d'une zone
desservie. Le porteur a franchi *cette* porte. Deux jauges distinctes demandent deux lecteurs.

⚠ **Deuxième limite** : déclarer une zone ne restreint pas les titres déjà vendus. Leur copie date de
leur émission et ne bouge qu'à la prochaine re-projection. C'est le comportement sûr : recalculer
tous les droits existants refuserait du jour au lendemain des porteurs qui ont payé. Si tu veux la
reprise, elle doit être un **geste explicite avec son décompte affiché avant exécution**.

L'écran est livré par allaccess-8e et vérifié à l'écran : déclaration, retrait, et la phrase qui
compte — « aucune restriction : ce produit ouvre toutes les zones » sur une liste vide.

### Le code comptable des moyens de paiement

Tu demandais un code comptable sur les moyens de paiement, « qu'on devra utiliser lors de la
génération de certains journaux comptables ». Ce qui manquait n'était pas un champ : une vente
produisait **une seule ligne de débit**, sur un compte unique, espèces et carte confondues.
`RegimeComptableInterface` le disait lui-même — « frontière simple, hors ventilation par moyen ».

Un rapprochement bancaire ne peut rien en faire : les espèces vivent en 53, les chèques à
l'encaissement en 5112, la banque en 512.

- La ventilation appartient au **profil exploitant**, pas au moyen de paiement — celui-ci est un
  référentiel global, et un même « espèces » n'a pas le même compte chez un exploitant public (M57)
  et un privé (PCG). Se tromper produit des journaux faux, ce qui est pire qu'une fonctionnalité
  absente.
- **Éteint par défaut**, et ce n'est pas de la tiédeur : activer change la *forme* des écritures
  (une vente réglée en trois fois produit trois lignes de débit). C'est ton arbitrage et celui de
  ton expert-comptable, pas une correction à imposer sur des livres déjà tenus.

**Ce que je te demande de trancher** : veux-tu la ventilation active par défaut sur les nouveaux
établissements ? Aujourd'hui elle est à `false` partout.

### Le plan de comptes, qui manquait vraiment

Une structure neuve reçoit désormais ses six journaux (VTE, ENC, REG, PCA, EXT, OD) et ses dix
comptes de base, en M57 ou en PCG selon la forme juridique. C'est de la **nomenclature**, pas un
choix de gestion — le même argument que tu as employé pour les taux de TVA : « c'est de la base
légale, un client n'a pas à le créer lui-même ».

La commande de reprise l'a appliqué aux établissements existants : **87 objets créés**, second
passage à zéro (idempotence vérifiée par la mesure, pas supposée).

Il reste **la période comptable** en lecture seule : c'est le maillon suivant, je ne l'ai pas fait.

---

## Deux mécanismes complets que personne n'appelle

C'est le motif de la nuit, et il revient sous des formes différentes.

### 1. Un rejet SEPA n'ouvre aucun impayé

Trouvé par allaccess-34, vérifié par allaccess-b8, re-vérifié par moi. `DeclarerRejetSepaProcessor`
écrit une ligne au journal des rejets et **s'arrête**. Le seul appelant du moteur de recouvrement
est une *simulation* du module Sport.

**Ce qui rend celui-ci plus grave que les autres : deux écrans affirmaient le contraire.** « Un
impayé est ouvert : il est traité dans l'écran Recouvrement. » On déclare un rejet, on lit que c'est
pris en charge, on ferme l'écran, et personne ne relance jamais. Ce n'est plus de la confusion, c'est
une créance perdue. 34 a retiré les deux promesses en attendant.

**Bonne nouvelle sur la question du blocage d'accès.** Elle est déjà tranchée dans le modèle :
`PolitiqueRecouvrement::momentRefusAcces` est un réglage **par établissement**, et son défaut est
`ApresRepresentationEchouee`. Avec ce défaut, un rejet ouvre l'incident, programme une représentation
à J+5, et **ne ferme aucune porte**. Ton nageur dont la provision manque trois jours entre.

**Ce que je te demande** : le défaut te convient-il, ou veux-tu le blocage dès le premier échec ?

Ce qui reste vraiment à décider est ailleurs : il n'existe qu'**un seul port de redevable** dans tout
le dépôt (`sport.abonnement_fitness`). Un rejet sur un mandat qui n'est pas un abonnement fitness n'a
aucun contrat à désigner. J'ai le chaînage prêt à écrire pour ouvrir l'incident ; la propagation
d'accès pour les autres types demande un port qu'il faut concevoir.

### 2. La configuration d'une démo ne suit pas le prospect

Trouvé cette nuit en cherchant d'autres coutures de la même forme.
`SubscriptionFunnel::openCart(..., ?Etablissement $demo = null)` capture le paramétrage du bac à
sable **au moment de la souscription** — et le code explique pourquoi : « entre les deux, le prospect
peut abandonner et son bac à sable être détruit » (RG-ED-08, D11).

**Aucun appelant ne remplit ce paramètre**, et la ressource publique n'a même pas de champ pour le
dire. Un prospect qui configure une démo puis souscrit perd tout ce qu'il a réglé.

C'est exactement la chaîne que tu décris pour le site vitrine : « vendre avant de basculer sur l'app
pour l'intégration des clients ». Le mécanisme existe et est inatteignable.

⚠ **Je ne l'ai pas branché, et pour une raison de sécurité.** `openCart` est une route **publique**,
non authentifiée. Lui faire accepter un identifiant d'établissement permettrait à n'importe qui de
capturer la configuration d'un client réel dans son propre abonnement. Il faut un **jeton opaque à
usage unique** émis par le bac à sable — c'est une conception, pas un branchement, et je ne
l'improvise pas à quatre heures du matin sur une route ouverte.

---

## Ce qui demande une décision de ta part

1. **La ventilation comptable par moyen de paiement** : active par défaut, ou sur demande ?
2. **Le moment du refus d'accès sur impayé** : le défaut (après une représentation échouée, J+5) te
   convient-il ?
3. **Le report de configuration démo → abonnement** : c'est la brique d'intégration client du site
   vitrine. Elle demande un jeton de reprise, donc une petite conception.
4. **Le dépôt de travail de la préproduction.** Trois fois en douze heures, un correctif a été lu
   comme « ne marchant pas » alors que c'était le cache compilé qui parlait : je commite dans le
   checkout qui *sert* la préprod, et le code y arrive par un chemin que le déploiement ne connaît
   pas. Ma recommandation : que la préproduction serve un checkout **dédié**, mis à jour uniquement
   par `deploy-preprod.sh`, et que les sessions travaillent ailleurs. C'est une demi-heure de mise en
   place et ça supprime toute une famille de faux diagnostics.

---

## Ce que je n'ai pas fait, et qu'il ne faut pas croire fait

- Le **module CMS** : rien de commencé. Il inverse la chaîne actuelle (vendre avant que le client
  n'existe) et implique une résolution de locataire par nom d'hôte — un second axe de cloisonnement.
  C'est une conception, pas une soirée.
- Le **marketing avancé** (analyses clients, analyses de ventes, promotions) : `Promotion` existe
  côté serveur et n'a aucune référence côté écran. Non commencé.
- La **période comptable** reste en lecture seule.
- Le champ **pays** sur l'établissement, préalable à la TVA européenne.
- Le **RGPD** : `DemandeRGPD` n'est appelé par aucun écran, et `anonymise` est inatteignable. C'est
  une obligation légale sans chemin.

---

## Mesures de la nuit

| | |
|---|---|
| Suites vertes | Acces 124/925 · Compta 85/691 · Organisation 11/51 · Recouvrement 14/94 |
| Garde-fous | 21, tous verts au push |
| Écart client/serveur | 699 inatteignables, plafond 699 |
| Reprise comptable | 87 objets posés, second passage à 0 |

⚠ **Le nombre d'inatteignables va REMONTER prochainement, et ce sera un progrès.** allaccess-8e
corrige le compteur : il classe aujourd'hui comme « atteignable » tout appel du client, même vers une
route que le serveur ne déclare pas. Un chiffre qui empire parce qu'on a cessé de mentir n'est pas
une régression.

---

## Trois erreurs à moi, et ce qu'elles ont appris

**Un test vert pour une raison qui n'a rien à voir.** Mon test du plan de comptes utilisait
`assertStringStartsWith` : il restait vert quand je retirais le compte `511000`, parce que `511200`
(chèques à encaisser) commence aussi par `511`. Le moteur aurait débité les chèques pour tout
encaissement, sans rien signaler. Depuis, il asserte le numéro exact.

**Un autre test vert par accident de collation.** J'envoyais `nom` là où le service lit
`nomCommercial`, et ça passait quand même : `strtoupper` ne touche pas les accents et la collation de
la base est insensible à la casse, si bien que la recherche retrouvait la raison sociale en croyant
trouver le nom commercial.

**Une propriété insérée entre les attributs d'une autre et sa déclaration.** `php -l` était vert — la
syntaxe est parfaitement valide — et Doctrine ne voyait plus le champ. Seul le test l'a dit. Quand on
insère avant une propriété annotée, l'ancre est la ligne vide avant son premier attribut, jamais la
déclaration.

Et une faute de coordination : **j'ai commandé le même écran à trois sessions.** Rien n'a été écrit en
double, uniquement parce que l'une d'elles a demandé avant de commencer. Les demandes d'écran passent
désormais par un point unique.

---

# Addendum — la fin de la nuit, et le point le plus important du rapport

## ⚠ Aucun prélèvement SEPA ne peut partir aujourd'hui

C'est la trouvaille la plus conséquente de la session, et elle change l'ordre de tes priorités.
Trouvée par une autre session, vérifiée par une deuxième, re-vérifiée par moi en base.

**Trois maillons, cassés en série :**

1. **Aucun ordonnanceur ne tourne sur la machine.** Ni cron — la commande `crontab` n'existe même
   pas — ni timer systemd, ni conteneur worker. Vingt-et-une tâches planifiées sont déclarées et
   aucune ne s'est jamais exécutée.
2. `sepa:preavis:annoncer` est le **seul** émetteur de préavis de prélèvement. Sans lui, aucun
   préavis n'existe : **0 en base**, pour 12 échéances.
3. `GenerationRemiseHandler` **exclut de la remise** toute échéance non couverte par un préavis
   délivré. Une échéance sans préavis ne part pas.

Conséquence : la remise sort vide, et personne ne le remarque.

**Le système échoue FERMÉ, et c'est le bon comportement.** Il refuse de débiter quelqu'un qu'il n'a
pas pu prévenir. Il n'y a donc **aucune violation** : personne n'a été débité sans préavis. C'est
exactement ce qu'il faut légalement, et c'est aussi pour ça que le blocage est resté invisible — un
refus prudent ressemble à une liste vide.

⚠ **Et l'ordre des corrections compte.** Démarrer l'ordonnanceur ne suffirait pas : sans prestataire
d'envoi de courriel, le préavis sortirait en « journalisé », donc non délivré, donc l'échéance
resterait exclue. **Le transport d'abord, l'ordonnanceur ensuite.** L'inverse donnerait l'impression
d'avoir réparé.

Le déploiement annonce désormais les deux, à chaque passage.

## Ce que ça t'oblige à décider, dans cet ordre

1. **Le prestataire d'envoi de courriel.** Il commande le préavis SEPA, donc l'encaissement, mais
   aussi les invitations d'utilisateurs, la réinitialisation de mot de passe, les confirmations de
   commande. Six expéditeurs existent et aucun ne parle. Je ne peux ni choisir le prestataire ni
   manipuler ses identifiants.
2. **Le démarrage de l'ordonnanceur.** Je ne l'ai pas lancé et je ne le lancerai pas sans toi :
   vingt-et-une tâches qui rattrapent des semaines d'arriéré d'un seul coup peuvent produire des
   facturations, des purges et des bascules en série. Il faut d'abord un mode « à blanc » qui dise ce
   qu'elles **feraient**. C'est un lot que je peux écrire.

## Vingt-et-une tâches à l'arrêt ne sont pas vingt-et-un défauts

Le tri compte plus que le chiffre, et il n'est pas fini. Certaines tâches ne font que marquer un
enregistrement : une délégation de droits expirée n'accorde déjà plus rien, parce que l'expiration se
vérifie **à la lecture**. La clôture quotidienne et la facturation d'abonnement ont un chemin
d'interface. Restent à vérifier une par une : les no-show, la conservation RGPD, la purge des
documents, les publications sociales programmées, la reprise mensuelle de PCA.

⚠ Ma première mesure automatique de ce tri **ne valait rien** : elle comptait des références de
classe et non des appels, et annonçait six appelants là où il y en avait un. Je l'ai refaite en
lisant les fichiers. Je le signale parce que le chiffre faux était plus rassurant que le vrai.

## Deux autres choses livrées depuis

- **Un rejet SEPA ouvre enfin un impayé.** Le processeur écrivait une ligne au journal et s'arrêtait,
  pendant que deux écrans affirmaient « un impayé est ouvert ». Une créance perdue, pas une
  confusion. ⚠ Limite connue : le blocage d'accès passe par un port par type de contrat, et il n'en
  existe qu'un (abonnement fitness). Un rejet sur un mandat de piscine ouvre un incident qui ne ferme
  aucune porte.
- **Un produit sans tarif n'est plus vendu au public.** Un billet s'ajoutait au panier sans prix,
  sous la phrase « le tarif sera confirmé au paiement » — il n'y avait rien à confirmer. Corrigé au
  niveau du service **et** de la donnée de démonstration ; pas côté écran, où un filtre aurait rendu
  la garde serveur manquante invisible.

## Une faute d'exploitation, à moi

J'ai **supprimé le conteneur de base de test d'une autre session**, en l'identifiant par une
ressemblance de nom au lieu de vérifier à qui il appartenait. Le dégât se limite à une exécution
perdue. Je l'ai signalé immédiatement pour que personne ne cherche la cause ailleurs, et je n'emploie
plus de suppression directe sur un conteneur que je n'ai pas créé dans la même commande.
