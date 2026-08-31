# TASKS — ce qui reste, et qui le tient

> **Refait le 31/08 par Jarvis (intégrateur).** Le tableau précédent datait du 22–25/08 et ne disait
> plus la vérité : 55 lignes sur 85 étaient en `CLAIM` sans nom. Un `CLAIM` sans instance n'est pas
> une réservation — c'est une intention que personne n'a reprise.
>
> ⚠ **Ce fichier est le SEUL canal entre les sessions qui ne partagent pas la même machine.** Écris
> ici ce qu'une session neuve doit savoir sans pouvoir te le demander.

---

## 1. Avant de toucher quoi que ce soit

**Lis dans cet ordre, ça prend dix minutes et évite une journée refaite :**

1. `COORDINATION/DECISIONS.md` — ce que Maxime a tranché, avec la raison ET la contrepartie.
   108 décisions. Les huit dernières (D101→D108) portent la feuille de route.
2. `COORDINATION/BLOQUEURS-EXTERNES.md` — sept blocages qui ne dépendent pas de nous.
3. `git log origin/main` — ce qui est **réellement** parti.

⚠ **`main` local n'est pas `origin/main`.** « J'ai poussé » désigne un geste ; « `origin/main`
contient X » désigne un état. Et « poussé » n'est pas « servi » : la préproduction n'a que ce que
`./infra/deploy-preprod.sh` y a mis. Vérifie avec `curl .../version.json`.

**Puis, avant ta première ligne de code :**

```
git fetch origin && git merge --no-edit origin/main
./bin/garde-fous.sh
```

Le second doit être vert AVANT que tu commences, sinon tu hériteras d'un rouge qui n'est pas le tien.

---

## 2. Qui tient quoi au 31/08

| session | voie | ne pas toucher |
|---|---|---|
| **Jarvis** (intégrateur) | `main`, `bin/**`, `hooks/**`, `infra/**`, `COORDINATION/**`, NF525 | les garde-fous, les crochets, la pile |
| **allaccess-8e** | référentiel de TVA légale, `App\Compta\**` | `app/src/Compta/**` tant que son lot n'est pas poussé |
| **allaccess-c2** | module Réservation, écrans et contrôles frontaux | `app/src/Reservation/**`, `frontend/scripts/**` |
| **allaccess-89** | écrans repris de `34`, facture rendue, destinataires | `frontend/src/pages/**` qu'il ouvre |
| **allaccess-b8** | mesure et diagnostic, relais vers Maxime | — (il ne pose pas de code) |

**Pour prendre un lot :** ajoute ton nom dans la colonne « tenu par » du tableau §3, commite ce seul
changement, et pousse-le **avant** de commencer. Un lot pris sans être poussé n'est pas pris.

---

## 3. Prêt à prendre

Ordonné par ce que ça débloque, pas par difficulté.

| # | lot | pourquoi maintenant | tenu par |
|---|---|---|---|
| **T1** | **L'API publique pour les tiers** — clés délivrables, webhooks sortants, versions | ⚠ **Commande trois des quatre autres axes** (D102). Sans elle, l'appli mobile, les agrégateurs et les machines connectées produisent trois couplages privés au lieu d'une surface | *(libre)* |
| **T2** | **Reprise initiale d'un client** — `ImportBatch`, deux temps, `externalRef` | Bloque une signature : sans elle un client ressaisit son fichier d'abonnés et les crédits de ses cartes. Spec écrite : `COORDINATION/specs/import/` | *(libre)* |
| **T3** | **Résoudre la boutique depuis l'HÔTE** et non le slug d'URL | Petit maintenant, gros plus tard (D104). Condition de la marque blanche | *(libre)* |
| **T4** | **Noms de sous-domaines réservés** (`pro`, `api`, `www`…) | Une constante, un refus à la création de vitrine (D106). Empêche une collision qu'on ne verra qu'en production | *(libre)* |
| **T5** | **Catégorie comptable sur les 7 produits publiés par les semis** | Les fixtures publient dans un état que l'API refuse (RG-M1-05). ⚠ Le choix du compte est une décision comptable — demander à Maxime avant | *(libre)* |
| **T6** | **Fixtures rejouables en préproduction** | `doctrine:fixtures:load` est absente (`--no-dev`). 38 classes décrivent la démo et ne peuvent pas être rejouées : la démonstration dérive | *(libre)* |
| **T7** | **Format de facture électronique** — Factur-X / EN 16931 | Aucun format n'existe. Chorus Pro et l'e-reporting REFUSENT désormais au lieu de mentir (D94), mais ne transmettent toujours rien | *(libre)* |
| **T8** | **Notion de pays** — champ, devise configurable, TVA par pays | Aujourd'hui : aucun champ pays, `EUR` en dur, e-reporting indexé sur le SIREN. Vendre hors de France demande ça d'abord | *(libre)* |
| **T9** | **Accessibilité** — `alt`, `lang`, rôles | 6 fichiers sur 118 portent un `alt=`, un seul un `lang=`. L'European Accessibility Act vise le commerce en ligne aux consommateurs | *(libre)* |
| **T10** | **Vingt tâches planifiées à démarrer**, une par une | 2 sur 22 tournent. Chacune demande de vérifier `safeOnFirstRun` et de la voir mordre **et épargner** | *(libre)* |

---

## 3 bis. Après l'API — les quatre axes de la feuille de route

⚠ **Ces quatre lots consomment l'API. Les commencer avant T1 produirait quatre couplages privés au
lieu d'une surface publique** (D102) — et rendrait la place de marché impossible à ouvrir sans tout
reprendre.

| # | lot | ce qu'il exige d'abord |
|---|---|---|
| **T11** | **Appli mobile adhérent, en marque blanche** — une appli commune qui prend les couleurs du club, plus une publication dédiée vendue en option (D105) | T1, et T15 pour l'identité visuelle. ⚠ Les deux versions doivent rester **identiques fonctionnellement** : le jour où la commune devient la parente pauvre, on maintient autant d'applis qu'on a de clients |
| **T12** | **Agrégateurs — et pas seulement fitness** | T1. C'est là que le multi-activités devient un avantage : une plateforme qui agrège piscines, patinoires et musées n'a pas d'équivalent |
| **T13** | **Balances et machines connectées** | T1, et le même port que le contrôle d'accès : un pilote qui **déclare ce qu'il sait faire** et échoue explicitement sur le reste (D17) |
| **T14** | **Assistant IA** | T1 et les trois autres. Il a besoin de données à lire et d'actions à déclencher — le construire en premier n'aurait rien à quoi se brancher |

---

## 3 ter. Le produit vu du dehors

| # | lot | pourquoi |
|---|---|---|
| **T15** | **Refonte graphique aux couleurs de Fluvia** | Les écrans portent aujourd'hui une identité par défaut. ⚠ À faire **avant** T11 : une appli en marque blanche décline une identité — s'il n'y en a pas, elle décline le vide |
| **T16** | **Site vitrine** sur `fluvia-app.com` | Aucune vitrine n'existe. Hôte séparé du back-office (D103) : elle porte des traceurs, il porte des sessions |
| **T17** | **Accueil d'un nouveau client (onboarding)** | ⚠ **Ce n'est PAS T2.** T2 reprend les données d'un client ; T17 est tout le chemin de la signature à une installation qui marche : créer le locataire, semer les référentiels, poser les types de produits et leurs comptes, le premier utilisateur, la formation. **T2 en est une étape.** Les confondre les ferait faire deux fois |

---

## 4. Bloqué dehors — ne l'attends pas, prends autre chose

Sept blocages ne dépendent pas de nous (`BLOQUEURS-EXTERNES.md`). Les plus lourds :

    E-2  SEPA réel          contrat bancaire et ICS          Maxime
    E-3  encaissement carte  choix d'un prestataire           Maxime
    E-4  matériel d'accès    spécification IT Cotation        fournisseur
    E-7  rappels PayFiP      schéma de signature DGFiP        DGFiP

⚠ **Consigne, et elle a coûté cher :** un blocage externe se consigne et **on change de module**.
Aucune session n'attend. Une attente non écrite se transforme en travail refait par quelqu'un
d'autre.

---

## 5. Interdictions en vigueur

⚠ **`reservation:no-show:basculer` NE DÉMARRE PAS** (D95). Mesuré : 6 réservations, 0 présence
confirmée, aucun écran n'écrit le drapeau. La lancer facturerait une absence à des gens venus.
**Condition de levée :** un écran appelle `/emarger` et une présence confirmée existe en base.

⚠ **Ne jamais ajouter une tâche à `infra/ordonnanceur.sh` sans vérifier `safeOnFirstRun`.** L'option
`--only` **contourne** cette garde : elle considère qu'un appel nommé est supervisé.

⚠ **`vente:cloture:journee` scelle.** Un premier passage sur trois semaines d'arriéré produirait
vingt et un arrêtés irréversibles.

---

## 6. Les six règles qui coûtent le plus quand on les oublie

1. **Un zéro se soupçonne.** Avant de conclure d'une absence, exige un témoin positif : montre que
   ta mesure sait trouver quelque chose. Quatre zéros faux en une heure le 31/08, dont un parce que
   le répertoire cherché n'existait pas.
2. **Un contrôle de syntaxe n'est pas un contrôle de justesse.** `php -l` a dit « OK » sur deux
   fichiers dont un script avait supprimé la ligne essentielle.
3. **Écris le cas qui doit PASSER.** Un contrôle trop large est invisible à ses propres tests de
   refus : il les fait passer *mieux*. C'est le cas légitime qui distingue une garde d'un blocage.
4. **Casse ton filet une minute.** Un test qui n'a jamais échoué rend un vert qui ressemble à tous
   les autres.
5. **Avant de chercher POURQUOI un test échoue, établis À QUI il appartient.** `git stash`, relance :
   trente secondes contre une nuit.
6. **Aucun antislash, aucun `$`, aucun accent grave ne passe par le shell.** Outil d'édition puis
   `scp`. Dix-sept occurrences pour moi, dont trois le 31/08.

---

## 7. Ce qui tourne désormais tout seul

    sauvegarde de la base   quotidienne, rétention 14 j, vérifiée par témoin
                            restauration : infra/verifier-restauration.sh
    ordonnanceur            2 tâches sur 22, sous profil « ordonnanceur »
    33 garde-fous           bin/garde-fous.sh · hooks/pre-commit · hooks/pre-receive

⚠ **Un garde-fou neuf doit être câblé dans les TROIS listes**, sinon le commit qui l'ajoute est
refusé — c'est voulu. Idem pour les contrôles frontaux (`frontend/scripts/verifier-*.mjs`).
