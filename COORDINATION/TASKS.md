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
| **allaccess-89** | continuation directe de `34` — même arbre, même branche, seul le nom d'adresse a changé ; écrans, facture rendue, destinataires | `frontend/src/pages/**` qu'il ouvre |
| **allaccess-b8** | mesure et diagnostic, relais vers Maxime | — (il ne pose pas de code) |

**Pour prendre un lot :** ajoute ton nom dans la colonne « tenu par » du tableau §3, commite ce seul
changement, et pousse-le **avant** de commencer. Un lot pris sans être poussé n'est pas pris.

### Ce qui peut être ajouté ici, et par qui

⚠ **Tout constat MESURÉ se pose par celui qui l'a mesuré**, sans passer par personne — avec ses
chiffres **et la commande qui les produit**, pour qu'un autre puisse les refaire.

    un lot manquant que tu as mesuré        pose-le, avec la mesure
    une ligne de ce fichier qui est fausse  corrige-la, en disant ce qui l'était
    la structure du fichier                 voie de Jarvis

**Pourquoi cette règle existe :** `allaccess-89` a mesuré quatre modules construits sans écran et
n'a pas posé la ligne, parce que §3 ne l'y autorisait pas. Il a bien fait de respecter la règle —
c'est la règle qui était mauvaise.

Et dans le même échange, il a relevé qu'une de mes lignes était fausse : « 6 fichiers sur 118 »
comptait des **fichiers** et concluait à une **couverture**. Un fichier qui fait autorité et qu'une
seule session peut corriger n'est pas auto-suffisant : c'est un goulot qui se trompe aussi.

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
| **T9** | **Accessibilité — la navigation au clavier** | ⚠ **Ma première mesure était fausse** : « 6 fichiers sur 118 portent un `alt=` » comptait des FICHIERS et concluait à une couverture. Recompté : **7 balises `<img>`, aucune sans alternative**, et `lang="fr"` est bien déclaré dans `index.html`. Ce qui est mince, c'est le clavier — **2 `tabIndex` et 5 `onKeyDown` sur 115 fichiers**, contre 308 `htmlFor` et 72 `aria-label`. Le lot est donc : parcours au clavier, gestion du focus, contrastes | *(libre)* |
| **T10** | **Vingt tâches planifiées à démarrer**, une par une | 2 sur 22 tournent. Chacune demande de vérifier `safeOnFirstRun` et de la voir mordre **et épargner** | *(libre)* |
| **T18** | **La boutique en ligne est en boucle fermée** — remboursement et souscription | ⚠ L'exploitant peut **accepter et refuser** des demandes de remboursement qui **ne peuvent pas naître** : `POST /boutique/demandes-remboursement` n'est appelée par personne, et `grep remboursement frontend/src/public/` ne rend rien. Et l'écran lui affirme « un client qui demande un remboursement apparaît ici ». Second manque du même parcours : `/boutique/abonnements/souscrire` n'est appelée nulle part — **aucun abonnement ne se vend en ligne**, alors que la vente au guichet existe depuis le 29/08. Mesuré par `b8` | **fait** — `c2`, 31/08. Le client demande depuis ses commandes ; l abonnement se souscrit depuis la fiche produit, avec mandat. ⚠ Deux restes : aucun contrôle de délai de rétractation côté serveur, et le client ne peut pas LISTER ses demandes (collection réservée à l exploitant) — donc l écran ne peut pas afficher « demande en cours » après rechargement, et il le dit |

---

## 3 quater. Construit, et sans aucun écran

⚠ **Ces quatre lots existent côté serveur et personne ne peut les atteindre.** Mesuré le 31/08 :
routes présentes au routeur, **zéro appel du frontal**. C'est du travail déjà payé qui ne sert à
rien tant qu'aucun écran ne l'ouvre — la forme la plus coûteuse d'inachèvement, parce qu'elle ne se
voit pas.

⚠ **DEUX LOTS PORTENT LE NUMÉRO T18** — celui-ci et « la boutique en ligne est en boucle fermée »
(§3 bis, tenu par `allaccess-c2`). Un numéro qui désigne deux choses est un nom qui ment, et il sera
cité dans des messages de commit pendant des semaines. Je ne renumérote pas moi-même : les deux
sont déjà référencés ailleurs, et l'arbitrage revient à l'intégrateur. Signalé le 31/08 par
`allaccess-89`, qui avait exactement la même collision sur ses garde-fous il y a deux jours.

| # | lot | routes servies, appels du frontal | tenu par |
|---|---|---|---|
| **T18** | **Fusion de clients** — fusionner, prévisualiser, défusionner | `/api/crm/fusions` ×3 · **0 appel** | **allaccess-89** |
| **T19** | **Trésorerie** — comptes bancaires, import de relevés, rapprochement | `/api/bank_accounts`, `/api/bank_statement_imports`, `/api/bank_statement_lines` · **0 appel** | *(libre)* |
| **T20** | **Personnel** — créneaux de travail, badges | `/api/creneau_travails` · **0 appel** ; `/api/badge_staffs` · 1 appel seulement | *(libre)* |
| **T21** | **Comptabilité** — lettrage groupé, écriture manuelle | `/api/compta/lettrages/groupe` · **0 appel** | *(libre)* ⚠ `8e` tient `App\Compta` |

Relevés par `allaccess-89`, qui les tenait de `34`. Deux autres de la même liste sont **faits et
poussés depuis** : le porte-monnaie virtuel et les notes de frais.

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
**Condition de levée : la première moitié est remplie depuis le 31/08, la seconde ne l'est pas.**

    un écran appelle `/emarger`          ✓ fait — la liste des inscrits d'un créneau, dans Réservation
    une présence confirmée existe en base  ⚠ À MESURER, ce n'est pas un écran mais un fait

⚠ **Ne pas lire la première coche comme une levée.** Tant que personne n'a réellement émargé, tout
créneau passé bascule encore en absence facturée — l'écran ne change rien tant qu'on ne s'en sert
pas. Et le second chemin prévu, `SourcePresence::PassageAcces`, n'est produit par personne : la
chaîne `Passage → DroitAcces → reservationRef → Reservation` existe en entier, il manque un écouteur.
Un contrôle d'accès qui remonterait la présence rendrait la condition vraie toute seule.

⚠ **Ne jamais ajouter une tâche à `infra/ordonnanceur.sh` sans vérifier `safeOnFirstRun`.** L'option
`--only` **contourne** cette garde : elle considère qu'un appel nommé est supervisé.

⚠ **`vente:cloture:journee` scelle.** Un premier passage sur trois semaines d'arriéré produirait
vingt et un arrêtés irréversibles.

⚠ **LA GARDE MFA SUR L'AFFECTATION EST SUSPENDUE, ET SON PARCOURS EXISTE DÉSORMAIS.**
`AffectationProcessor::MFA_EXIGE_POUR_ROLE_A_PRIVILEGES = false` depuis le 31/08 — décision de
Maxime, « lever maintenant, construire ensuite ». Sans elle, on ne pouvait nommer **aucun**
administrateur, chez aucun client : 6 rôles à privilèges, 0 compte avec MFA actif, et aucun écran
pour l'activer.

Le « ensuite » est fait : activation avec QR, secret et codes de récupération ; confirmation ;
second facteur à la connexion ; désactivation ; réinitialisation par un administrateur.

**Ce qui reste, et ce n'est pas à une session de le décider seule :** remettre la constante à `true`
rebloque la nomination d'administrateurs tant que chacun n'a pas activé son MFA — et **aucun humain
n'a encore parcouru ces écrans**. La question est posée à Maxime ; ne pas rétablir sans sa réponse,
et ne pas reconstruire les écrans, qui existent.

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
