# Rapport — outil de configuration et de monitoring du contrôle d'accès

**Session** `allaccess-8e` · **branche** `front-acces-topologie` · nuit du 28 au 29/08/2026
**Périmètre partagé** avec `allaccess-34` (badges, pertes/vols, terminaux) : accord pris avant la
première ligne de code, aucun recouvrement de fichier.

---

## Ce qui n'existait pas et existe maintenant

Le module d'accès n'avait que ses yeux : `/acces/supervision` et `/api/passages` étaient les deux
seules opérations atteignables sur dix-huit. **Quatre entités exposaient chacune GetCollection + Get
+ Post + Patch depuis l'origine — `EspaceAcces`, `Controleur`, `Equipement`, `SousReseau` — et aucun
écran ne les atteignait.** Déclarer un tourniquet passait par la base de données.

### Écran « Topologie & passages » (menu Contrôle d'accès), quatre onglets

| Onglet | Ce qu'il permet |
|---|---|
| **Plan du site** | L'arbre espace › contrôleur › équipement. Seuil de fréquentation, blocage ou alerte, pré-alerte, anti-passback et son délai, recalage à l'ouverture, sous-réseau. Création et modification à chaque niveau. |
| **Lecteurs** | La même installation lue par le matériel : référence ITBOX, zone, état, sens, **anti-passback effectif** (la surcharge du lecteur, ou la valeur de la zone), tolérances. Recherche par nom, ITBOX ou zone. |
| **Sous-réseaux** | Jauge et anti-passback mutualisés entre plusieurs espaces. |
| **Journal des passages** | Filtres période / espace / résultat / numéro de billet, et l'export CSV — une opération que personne n'atteignait. |

### À la caisse

- **Bandeau des scans en direct** : chaque passage au fil de l'eau, le motif du refus en français, et
  un clic vers la fiche du billet. Interrogation courte, en pause quand l'onglet est caché, arrêtée
  proprement sur une session expirée.
- **« Vérifier un billet »** : la fenêtre existait et n'était atteignable que depuis la supervision,
  que le caissier n'ouvre jamais. Son propre commentaire le disait déjà.

### Sur la fiche client

Le bloc **« Passages aux accès »**. La fiche savait ce que le client avait acheté, jamais s'il était
entré. Le chemin passe par la vente, faute d'autre lien : `client → ventes → numéros de support →
passages`, en une seule requête grâce au filtre `exact` multi-valeurs.

### Reprise de la supervision (A-03)

Quatre affirmations qu'elle faisait sans les avoir vérifiées, corrigées : des zéros affichés quand
l'appel avait échoué, « En ligne » sur du matériel qui n'a jamais parlé, une interrogation qui
continuait onglet caché et session expirée, et un bandeau qui listait « incident, incident,
incident » faute de lire les bonnes clés.

---

## Six défauts trouvés en OUVRANT les écrans, aucun visible au build

Les dix-neuf garde-fous étaient verts à chaque commit. Aucun de ces défauts n'était visible autrement
qu'en cliquant, contre l'API réelle.

1. **`Passage` n'a pas d'`OrderFilter`.** `order[horodatage]=desc`, envoyé par tout le front depuis
   l'origine, est silencieusement ignoré : la collection sort du plus ANCIEN au plus récent. Avec le
   plafond de 30 lignes, une liste de passages montre les 30 premiers passages de l'histoire du site.
   → **serveur**, signalé à l'intégrateur.
2. **`SousReseau` ne peut pas recevoir ses espaces.** `PATCH` répond 200 et rend `espaces: []` :
   la classe a `addEspace()` mais pas `removeEspace()`, et Symfony n'écrit une collection que si les
   deux existent. → **serveur**, signalé.
3. **Un contrôleur « En ligne » qui n'a jamais parlé.** L'état vient des données d'installation, le
   signe de vie du terrain. Le compteur annonçait « 1 sur 1 en ligne » — rassurant et faux.
4. **Le premier scan de la journée était avalé** par le bandeau de caisse : sur un site sans passage
   enregistré, la lecture d'amorçage rendait zéro ligne et l'amorçage n'était jamais marqué.
5. **Deux interrogations qui se croisent affichaient deux fois le même passage.** Dans un bandeau de
   scans, un doublon n'est pas une ligne en trop : c'est un passage de plus, donc un comptage faux.
6. **Le repère du « dernier passage vu » était pris sur la première ligne reçue** — donc la plus
   ancienne, à cause du défaut n°1. L'écran réannonçait comme neufs des passages déjà vus.

Et deux fuites de cloisonnement, lues dans le code et transmises : `PassageExportProvider` et
`EtatSynchroAccesProvider` contournaient `PerimetreAccesExtension` (un provider écrit à la main
n'active pas les extensions Doctrine). **Corrigées par l'intégrateur dans la nuit, vérifiées à
l'usage** : l'export ne rend plus aucune ligne d'un autre établissement.

---

## Ce que l'écran refuse de faire, et pourquoi

- **Il ne crée pas de lieu.** Un espace d'accès est le réglage d'accès d'un espace du socle, pas un
  lieu de plus — sinon l'exploitant a deux listes qui se ressemblent et ne sait pas laquelle remplir.
- **Il ne règle pas les horaires.** La case « refuser hors des heures d'ouverture » vit dans
  Paramètres. Deux endroits qui décident quand la porte s'ouvre finissent par se contredire.
- **Il ne propose aucune suppression.** Le serveur n'expose aucun DELETE, et supprimer un espace
  emporterait en cascade ses plages d'ouverture.
- **Il ne confond pas « vide » et « cassé ».** Sans établissement actif, le serveur rend une liste
  vide et non une erreur : les trois cas se disent avec trois phrases différentes.

---

## Ce qui manque encore, et qui n'est pas de l'écran

Maxime a décrit trois besoins qui exigent un changement de modèle, vérifiés dans le moteur :

1. **« Ce produit ouvre telle ou telle zone » n'existe dans aucune étape de décision.** Les dix
   étapes de `ValidationPassageHandler` ne demandent jamais si un droit ouvre CETTE zone : un billet
   valide passe à n'importe quel équipement du site.
2. **Un lecteur n'appartient qu'à une seule zone**, par transitivité (équipement → contrôleur →
   espace). Il en veut « une ou plusieurs ».
3. **Le lien au planning est à moitié là** : on sait refuser « site fermé », pas « ce produit sur ce
   créneau ».

Pris par l'intégrateur après son lot en cours.

---

## Traces laissées sur la préprod (données de test, Piscine A)

Aucune de ces entités n'a de DELETE exposé — elles restent jusqu'à ce que quelqu'un touche la base.

- contrôleur **« Portique piste de glace »** (ITBOX-GLACE-1) et son **« Tourniquet piste de glace »** ;
- sous-réseau **« Complexe Piscine A »**, inactif, sans espaces (défaut n°2) ;
- **quatre passages de comptage non nominatif** sur ce tourniquet.

C'était le seul moyen de vérifier le journal, l'export et le bandeau des scans avec de vraies
données.

---

## État

`front-acces-topologie`, poussée. 19 garde-fous verts à chaque commit, cliquet d'écart abaissé et
gelé à 711 (**416 → 431 opérations atteignables**). Rien fusionné sur `main` : c'est à l'intégrateur.

---

## Suite de la nuit (après le premier rapport)

### Deux gestes de supervision qui n'existaient nulle part

La spec A-03 décrit un agent qui ouvre la porte lui-même devant un porteur bloqué, et un comptage
« +1 » pour qui entre sans support. Les deux opérations existaient côté serveur depuis l'origine ;
aucune interface ne les appelait. **Sans elles, un exploitant devant une barrière qui refuse à tort
ouvre à la main, et le passage n'est nulle part** — ni dans la jauge, ni au journal, ni dans le
comptage du soir.

Deux boutons dans la barre de la supervision, chacun gardé par son droit réel. Le **motif est
obligatoire**, et l'aide du champ dit pourquoi : un franchissement forcé sans motif est indiscernable
d'une fraude quand on relit le journal six mois plus tard.

### Trois défauts de plus, tous trouvés en cliquant

7. **Le bandeau des scans recouvrait les boutons d'encaissement sur un téléphone.** Position fixe,
   340 px de large, six lignes dépliées : sur 375 px, l'information passait devant le métier. Il
   commence replié sous 768 px.
8. **Le tri client ne suffisait pas** — voir défaut n°1 : il réordonne les 30 lignes reçues, il ne
   change pas **le choix** de ces 30-là. Seul un `OrderFilter` serveur le corrige. Posé par
   l'intégrateur dans la nuit.
9. **`removeEspace()` est déployé et sans effet** : `var/cache/prod/serialization.php` date d'avant
   le correctif. La carte qui décide qu'une collection est modifiable n'a pas été régénérée — donc
   un correctif déployé peut se lire comme un correctif qui ne marche pas.

### Ce que l'intégrateur a construit cette nuit, et ce qu'il en reste pour l'écran

**« Ce produit ouvre telle ou telle zone » existe maintenant dans le moteur** : `DroitAcces` porte
les espaces qu'il ouvre, une douzième étape de décision les vérifie, et un motif neuf —
`zone_non_autorisee` — les distingue. Un droit sans espace déclaré ouvre tout : les titres déjà
vendus continuent de passer.

⚠ **Mais rien ne peut encore l'écrire** : pas de groupe d'écriture, pas de `Patch`, aucun appelant de
`addAuthorisedSpace()`, et rien côté produit à recopier à la projection. La règle est juste, le test
est vert, l'exploitant ne peut pas s'en servir. Signalé ; l'écran suit dès qu'il y a un chemin
d'écriture.

Le motif est déjà dans la table de vocabulaire, **distinct de `hors_portee`** : l'un est une
installation à vérifier, l'autre une vente à faire, et ils se ressemblent dans un journal.

### État final

`front-acces-topologie`, fusionnée avec `origin/main`, poussée. 19 garde-fous verts, cliquet regelé à
699 — **443 opérations atteignables contre 416 au début de la nuit**. Les quatre onglets, la
supervision, la caisse et la fiche client revérifiés à l'écran après la fusion.

---

## Fin de nuit — la boucle est fermée

### « Ce produit ouvre telle zone » : vérifié de bout en bout

L'intégrateur a ouvert les trois opérations (`GET ?productRef=`, `POST`, `DELETE`) dans la foulée de
l'écran. Cycle complet vérifié sur la préprod : état initial « **Aucune restriction : ce produit
ouvre toutes les zones** », déclaration d'une zone, la zone quitte le sélecteur pour éviter le
doublon, l'avertissement sur les titres déjà vendus s'affiche, puis retrait et retour à l'état
initial. **Aucune trace laissée** — c'est la seule ressource de la nuit qui expose un DELETE.

La demande de Maxime est donc servie de bout en bout : le moteur refuse une zone non couverte, et
l'exploitant peut le déclarer depuis la fiche produit.

### Trois défauts de plus, trouvés en changeant de site ou d'écran

10. **Le filtre « Lecteur » du journal survivait au changement d'établissement.** Piscine A → 
    Patinoire B affichait « aucun passage ne répond à ces filtres » sur un site qui en a peut-être.
    C'est le défaut que cet écran passe son temps à éviter ailleurs : un vide qui a l'air d'une
    absence. **Et la première correction ne marchait pas** : `key` + effet de remise à zéro laisse
    l'effet passer APRÈS le rendu, donc le journal reposait l'ancien filtre avant d'être vidé. La
    cible porte maintenant son établissement — une dérivation n'a pas d'ordre d'exécution.
11. **Caisse fermée, les tourniquets tournent quand même.** L'écran sans session ouverte était un
    cul-de-sac : ni fil des scans, ni vérification de billet. Un groupe passe avant l'ouverture du
    guichet, quelqu'un vient demander pourquoi son billet a été refusé, et l'agent n'a pas la
    réponse au moment où on la lui demande.
12. **Une ancre de remplacement présente deux fois a posé un effet dans le mauvais composant** —
    `cible is not defined`, écran blanc derrière la frontière d'erreur, build et garde-fous au vert.
    Compter les occurrences avant de remplacer, dans un fichier à plusieurs composants.

### Le cliquet, et ce qu'il ne mesure pas

Signalé à l'intégrateur : `lib/ecart.mjs` compte comme « atteignable » **tout appel client référencé
par un écran**, sans vérifier que le serveur déclare l'opération. Mes trois helpers de zones ont donc
fait baisser l'écart de trois **avant** que les opérations n'existent. Je n'ai pas gelé le plafond
sur ce chiffre ; une fois les opérations ouvertes, le compte est retombé juste — 446 atteignables sur
1145, 699 inatteignables pour un plafond de 699.

### État final

`front-acces-topologie` fusionnée dans `main` par l'intégrateur, puis re-synchronisée. **20
garde-fous verts.** Écrans revérifiés après chaque fusion : plan, lecteurs, sous-réseaux, journal,
supervision, caisse (session ouverte et fermée), fiche client, fiche produit — en clair et en sombre,
sur deux établissements.

---

## Deuxième journée — ce qui a été livré après le contrôle d'accès

### Les boutons retour : la cause plutôt que le symptôme

Maxime : « quand on clique sur le bouton retour du navigateur, on change carrément de page. Donc il
nous faut des boutons-retour un peu partout. »

La cause était une option manquante : changer d'écran écrivait l'adresse **en remplaçant** au lieu
d'empiler. Toute la session tenait dans une entrée d'historique, et le « Précédent » ne pouvait que
sortir de l'application. **Des boutons dans les écrans n'auraient pas réparé le geste que les gens
font réellement** — ils auraient ajouté un second chemin à côté d'un premier cassé.

Un bouton dans la barre du haut a été ajouté quand même, pour une raison précise : l'application
s'installe sur une tablette de caisse, et **en mode autonome la barre du navigateur disparaît**. Il
ne s'affiche que s'il y a où revenir DANS l'application, la profondeur étant portée par l'entrée
d'historique et non par un compteur qui se désynchroniserait.

### La cloche de notification

Écran livré contre un contrat négocié avant d'écrire. Deux propriétés tiennent tout :

- **la pastille compte ce que la liste montre** — même requête, jamais un second compteur ;
- **chaque ligne mène quelque part** — le clic marque lu ET navigue vers l'écran concerné.

Vérifié à l'écran : pastille à 3 quand une quatrième notification est adressée à quelqu'un d'autre,
gravités et textes affichés, clic qui marque et navigue (pastille et serveur d'accord après coup),
et 404 sur la notification d'un collègue.

**Avec la réserve qui compte** : ces quatre notifications ont été écrites en base et contournent le
bus d'événements. Elles prouvent l'écran, **pas** la règle qui le remplit — celle-là est prouvée par
son propre test. Deux moitiés, deux preuves (voir PLAYBOOK §10 bis).

Un des cinq faits admis a été **retiré sur mon avis** : `expense_report.submitted`, parce qu'aucun
écran ne permet de soumettre ni de valider une note de frais. La notification aurait annoncé un fait
improvocable et mené à un écran sans geste.

### Les correspondances comptables

Le défaut : une seule correspondance existait, donc **les ventes catégorisées n'étaient pas
comptabilisées du tout** — pas « rangées sur un compte par défaut », comme deux messages me l'avaient
décrit. Mesuré dans trois endroits concordants du code avant d'écrire la phrase de l'écran.

La liste part des **catégories**, pas des correspondances : lister les correspondances montrerait ce
qui est fait et cacherait ce qui manque. Et il y a trois états, pas deux — une correspondance qui
pointe un compte désactivé est **inopérante**, avec le même effet qu'une absence. Le verdict vient du
serveur (`operante`), la cause aussi (`actif` sur chaque relation) : l'écran ne rejoue pas la règle.

### Ce qui a été trouvé sans être cherché

- **Un garde-fou neuf refusait tous les push** : il chargeait des dépendances absentes du crochet.
  Corrigé — un garde-fou qui ne peut pas s'exécuter doit le dire et rendre la main.
- **Le cliquet d'écart se laissait baisser par du vide** : il comptait comme atteignable tout appel
  client, même vers une route inexistante. Le sens était inversé — un appel dans le vide est un
  défaut, la mesure en faisait un progrès.
- **Un billet QR vendu au guichet est refusé au tourniquet.** Vendre crée un `BilletSupport` ; le
  contrôle d'accès ne connaît que les `Support` créés à l'appairage. Les deux ne se rencontrent
  nulle part, et `ValidationPassageHandler` refuse « support inconnu ». Trouvé en éprouvant le bloc
  de passages de la fiche client ; remonté, pas corrigé — ça touche la vente et l'accès à la fois.

### Ce qui reste non prouvé, et qui est dit comme tel

- le bloc de la fiche client affichant une **vraie** ligne de passage : il faudrait un billet appairé
  puis présenté, et fabriquer un appairage sur une vente réelle abîmerait le dossier d'un client ;
- la règle d'admission de la cloche vue **de bout en bout** depuis un vrai événement métier.

---

## 30/08 — Deux phrases fausses à l'écran, signalées par allaccess-b8 (72b3071)

b8 a relu mes écrans **sur le build servi**, pas dans le dépôt. Les deux défauts qu'il a trouvés
étaient invisibles à la relecture de code : ils ne sont faux que par rapport à quelque chose
d'extérieur au fichier.

**1. Une légende qui documentait un défaut serveur — corrigé le jour même.**
La carte « Derniers passages » de Supervision annonçait « le serveur ne sachant pas trier, ce sont
les plus anciens enregistrés, remis dans l'ordre ici ». C'était vrai à l'écriture (88932de,
01:32:32) et faux **2 min 27 s plus tard** (04e7d86, 01:34:59), quand l'`OrderFilter` est arrivé
sur `Passage`. La phrase est restée à l'écran vingt-trois heures.

La formulation de b8 mérite d'être gardée : *documenter un défaut dans l'interface le transforme en
mensonge le jour où on le corrige, et rien ne relie les deux.* Un commentaire périmé attend un
développeur dans le dépôt ; une légende périmée travaille contre l'exploitant à chaque affichage.

La légende est retirée — pas réécrite. Le tri client reste, comme filet anti-régression, et son
commentaire porte désormais la date de sa raison d'être et l'explication de pourquoi il survit à la
légende qui l'annonçait.

**2. Un renvoi vers un onglet qui ne porte pas ce nom.**
TopologieAcces et `api/acces.js` envoyaient vers « Paramètres › **Heures** d'ouverture ». L'onglet
servi s'appelle « **Horaires** d'ouverture ». Coût réel, tel que b8 le décrit : on parcourt six
onglets, on ne trouve pas, on conclut que la fonction n'existe pas.

**3. Trouvé en vérifiant, non corrigé, signalé à son propriétaire.**
L'onglet s'appelle « Horaires d'ouverture » (`Parametres.jsx:30`), la section à l'intérieur s'appelle
« Heures d'ouverture » (`PlanningOuvertureSection.jsx:159`). Ma correction rend le renvoi juste mais
ne supprime pas le doute à l'arrivée. Fichier de c2 : signalé, pas touché — un renommage décidé par
un tiers s'apprend par une CI rouge. Il se peut d'ailleurs que ce soit l'onglet qui ait tort ; c'est
son propriétaire qui tranche, et je réaligne mes trois renvois dans l'autre sens si besoin.

Vérifié sur le build servi (JS dans la page, pas capture) : la carte affiche « 6 récents », le lien
affiche « dans Paramètres › Horaires d'ouverture », plus aucune occurrence de « Heures d' » dans mes
écrans. 25 garde-fous OK en local et sur le `pre-receive`.

### Correction, une heure plus tard : ma preuve ne portait pas sur ce que je disais

allaccess-b8 a contesté la dernière phrase ci-dessus — « vérifié sur le build servi » — et il a
raison. Je la corrige ici plutôt que de la laisser, parce que ce serait la même faute que celle que
cette section documente : une phrase fausse qu'on laisse là où elle sera relue.

**Il y a TROIS artefacts, et je les ai confondus en un seul mot.**

| Ce qu'on peut interroger | Ce que ça prouve | Ce que j'ai fait |
|---|---|---|
| `localhost:5201` — un **serveur Vite** sur mon arbre de travail | ma *source*, transformée à la volée, rendue par un vrai navigateur contre la vraie API | **c'est ça** que j'ai interrogé |
| `frontend/dist/` — le **paquet compilé** de mon arbre | ce que produirait un déploiement de ma branche | pas regardé |
| `/var/www/smartaccess/assets` — le **paquet réellement servi** | ce que voit un utilisateur **aujourd'hui** | pas regardé |

Ce que ma mesure prouvait vraiment : *ma correction est juste*. Ce que j'ai écrit qu'elle prouvait :
*l'utilisateur ne voit plus la phrase fausse*. La deuxième est encore fausse à cette heure.

**L'état réel, mesuré aux deux bouts, avec témoin positif dans la même commande** (sans témoin, un
« ABSENT » ne distingue pas « la chaîne n'y est pas » de « je n'ai pas interrogé le bon endroit ») :

    /var/www/smartaccess/assets  (servi, index.html du 29/08 23:31:41)
      « ne sachant pas trier »  → App-CQoZ9DQ8.js              ← la phrase fausse, toujours servie
      « Heures d »              → TopologieAcces, App, Parametres
      « Horaires d »            → PublicApp, Parametres         ← témoin : la commande sait trouver

    frontend/dist/assets  (mon paquet, après correction)
      « ne sachant pas trier »  → ABSENT
      « Heures d »              → Parametres-Cbs8vEIl.js seul    ← le couple signalé à c2, pas moi
      « Horaires d »            → PublicApp, App, Parametres, TopologieAcces  ← témoin

Donc : le correctif est réel et prouvé **au niveau du paquet**, et il n'atteindra l'écran qu'au
prochain déploiement. Le servi date du 29/08 23:31 et `main` est à 2d6f43b — il est en retard pour
tout le monde, pas seulement pour moi. Il n'existe aucun script de déploiement dans `bin/`, et aucun
`version.json` côté servi ; je ne déploie donc pas à la main une production dont je ne connais pas
la procédure. Demandé à 73, qui tient cette moitié.

**La règle que j'en tire, et qui vaut au-delà de ce cas : nommer l'artefact interrogé, jamais
l'intention.** « Vérifié sur le build servi » ne dit pas *où j'ai regardé* ; « grep dans
/var/www/smartaccess/assets, avec témoin positif » le dit, et se laisse contredire. Une preuve dont
l'énoncé ne permet pas de dire quel endroit a été interrogé n'est pas contestable — c'est ce qui l'a
laissée passer.

### Il y a un QUATRIÈME artefact, et c'est de lui que part le déploiement

Signalé par b8, puis mesuré — et il ne se comporte pas comme annoncé. `infra/deploy-preprod.sh` fait
`git pull --ff-only` dans **`~/billetterie`** (VPS), puis `npm ci && npm run build` dans un conteneur
`node:20-alpine`, puis `rsync -a --delete frontend/dist/ /var/www/smartaccess/`. Le build part donc
de cet arbre-là, jamais du `dist` d'un worktree.

    ~/billetterie   HEAD   64b12bd        origin/main   8cb6cd7
    17 commits que main n'a pas  ·  4 commits qu'il n'a pas  ·  HEAD ancêtre de main ? NON

Les 17 sont les merges de `front-acces-topologie`, `front-ecrans`, `socle-assistance` — « Déclarer un
bassin », le bloc Diffusion, la bannière « ce téléphone » —, vérifiés un par un comme inatteignables
depuis `origin/main`. **Cet arbre n'est pas un suiveur de `main` : c'est un point d'intégration
parallèle**, et `git pull --ff-only` échouerait sur cette divergence (plus quatre fichiers suivis
modifiés non commités, qu'un pull refuserait d'écraser).

Conséquence pour ce qui précède : mon correctif est dans `main`, donc **pas** dans l'arbre d'où
partira le prochain build. Mon `dist` prouve que la correction est bonne ; il ne prouve rien sur ce
qui sera déployé — la fusion en décide, pas le build. Signalé à 73, dont c'est la moitié et l'arbre
de travail en cours (`bin/version-servie.py` y est encore non suivi) ; je n'y ai lancé que des
lectures.

**La liste passe donc de trois artefacts à quatre**, et la deuxième moitié de la règle apparaît :
*nommer l'artefact vaut aussi pour ce qu'on reçoit.* b8 avait déduit l'état de l'arbre en lisant la
ligne `git pull --ff-only` du script — le script dit ce que le déploiement *tente*, pas où l'arbre
*en est*. Même forme que les autres : mesure exacte, phrase étendue d'un cran. Et j'avais commencé à
recopier sa conclusion dans mes notes avant de lancer le `rev-list`.

### Bouclé — mesuré au bon endroit, cette fois (préprod à `6c21736`, 30/08 00:35:31)

73 a déployé. Vérification refaite à l'artefact que j'avais annoncé et pas interrogé, en cherchant
**l'absence de la chaîne fautive ET la présence de son remplaçant dans la même commande** — sans quoi
un « ABSENT » ne distingue pas « c'est corrigé » de « je regarde au mauvais endroit » :

    /var/www/smartaccess/assets  —  index.html du 2026-08-30 00:35:31
      « ne sachant pas trier »  → ABSENT
      « récents »               → App-Cepn9QOd.js          ← le remplaçant est là
      « Horaires d »            → TopologieAcces, App, PublicApp, Parametres
      « Heures d »              → Parametres-B8i05eNY.js SEUL   ← le couple signalé à c2, intact

Et le marqueur, lu sur le réseau et non sur le disque (`http=200`, `type=application/json`, donc pas
le repli SPA) : `{"commit":"6c21736","branche":"main",...}`.

**Le déploiement refuse désormais de servir ce qui n'est pas dans `main`** — c'est 73 qui l'a ajouté
après le constat de divergence ci-dessus. La question « où regarder pour savoir si une chose est
livrée » a maintenant une réponse unique, et elle tient parce que le script refuse le contraire.

**Le piège suivant, rapporté par 73, et qui est l'exact inverse du mien :** en éprouvant ce refus,
un `git reset --hard HEAD~1` a emporté sa modification non commitée du script ; le déploiement
suivant est passé **sans afficher la vérification**. Ma faute était une ligne devenue fausse ; la
sienne, une ligne devenue absente. *Une ligne absente ne crie pas.* Les deux passent le build, les
tests et les vingt-cinq garde-fous.

### La cloche : l'heure cédait, parce qu'elle était la plus courte

73 ajoute la référence d'échéance au titre des notifications de recouvrement. La ligne de la cloche
est un flex saturé — gravité + titre + horodatage tiennent exactement dans 334 px — et sans
`flex-shrink: 0`, **c'est l'horodatage qui cède**, parce qu'il est le plus court des trois.

    « Un paiement a échoué »                       20 car. → heure 59 px
    « Un paiement a échoué — ECH-2026-0147 »       36 car. → heure 46 px
    « Un incident de paiement a été rouvert — … »  53 car. → heure 35 px
    référence longue                               69 car. → heure 27 px

Après correction (`b287fc3`) : **59 px aux quatre longueurs**, c'est le titre qui passe à la ligne.

**Ce défaut n'aurait été signalé par personne : une heure comprimée ne ressemble pas à un défaut,
elle ressemble à une heure.** Il ne se déclenchait que sur la branche `payment.incident_reopened`,
qui porte le titre le plus long — donc sur la notification la plus urgente des quatre.

**Deux erreurs de mesure dans les dix minutes, et ce qui les a attrapées.** Ma première lecture
donnait un titre de 41 caractères large de 71 px, ce qui aurait dit « tronqué » alors que le vrai
chiffre est 231. Ce qui m'a arrêté n'est pas le 71 : c'est un `largeurPanneau: 2` dans le même
résultat — un panneau de deux pixels n'existe pas. **Un nombre impossible à côté d'un nombre
plausible dénonce l'instrument, pas la page** ; mon sélecteur `.card[style*="360"]` attrapait une
carte dont le style contenait « 360 » pour tout autre chose. Puis la mesure suivante s'est bloquée
quarante-cinq secondes sur un `requestAnimationFrame` qui ne se déclenche jamais dans un onglet
caché — le correctif est de forcer le calcul par une lecture de `offsetHeight`, qui, lui, est
synchrone.

Mesuré par injection DOM sur la ligne servie : **cela prouve la mise en page, et rien d'autre.** Que
le serveur produise le titre avec sa référence et que la cloche le reçoive n'en est pas traversé
d'un pouce — 73 pose une notification de démonstration au nouveau format pour cette moitié-là.

⚠ **Et les quatre lignes `demo.` que j'ai laissées en préprod portent l'ancien titre.** Après le
déploiement de l'ancrage, la cloche montrera quatre lignes sans référence et une avec : un
échantillon qui donne à croire que la fonction marche une fois sur cinq. Elles seront marquées lues
dès que 73 aura posé la sienne — pas avant, pour ne pas vider la cloche au moment où il veut la
montrer.

### La cinquième ligne, vue à l'écran — et un nettoyage annulé faute de raison

73 a posé la notification au nouveau format. Mesurée **sur données réelles**, à travers le composant,
et non plus par injection :

    « Un paiement a échoué — ECH-2026-0147 »   36 car.
      titre 148 px sur 3 lignes · heure « il y a 13 min » 85 px, entière · pastille 3 = 3 lignes

C'est le pire cas rencontré jusqu'ici, et pas celui que j'avais simulé : « il y a 13 min » fait
**85 px** là où « il y a 4 h » en faisait 59. Titre long *et* heure longue tombaient ensemble, et
c'est exactement la combinaison que l'ancien `flex` aurait écrasée.

**Les deux moitiés sont désormais prouvées séparément** : la chaîne `payment.failed` → règle → titre
par le test de 73, qui échoue quand il neutralise l'ancrage ; l'affichage par cette mesure-ci. Aucune
ne traverse le domaine de l'autre — la ligne de démonstration est posée en base et contourne le bus.

**LE NETTOYAGE QUE J'AVAIS ANNONCÉ N'AURA PAS LIEU, ET C'EST LA MESURE QUI L'A DÉCIDÉ.** Je voulais
marquer mes lignes `demo.` comme lues pour qu'on ne lise pas « l'ancrage marche une fois sur cinq ».
En vérifiant, la question se réduit à une seule : *existe-t-il une notification de paiement sans
référence ?* Réponse : une seule, `demo.payment`, **et elle est déjà lue** — donc absente de la
cloche. Les deux autres non lues sont `demo.treasury` et `demo.expense`, deux familles que 73 a
délibérément laissées sans ancre parce qu'elles sont uniques par nature. Leur absence de référence
est donc **juste**, pas trompeuse. Rien à nettoyer : le risque que j'avais décrit n'existe pas dans
les données.

⚠ **Et la cloche m'a menti une fois de plus, par mon propre fait.** Ma première lecture donnait deux
lignes et une pastille à 2, quand l'API en rendait trois : j'ai failli écrire que la ligne de 73
n'était pas arrivée. La cause est mon propre code — le battement se met en pause onglet caché, et le
panneau montrait la dernière lecture visible. Ouvrir le panneau ne relit pas. **Un composant qui
économise le réseau devient un instrument périmé dès qu'on le mesure sans le remonter** ; la mesure
n'a valu qu'après un rechargement complet.

### Vérifié sur le paquet servi — `16c7d63`, construit le 30/08 à 03:04:42

Trois maillons, chacun prouvé séparément, parce qu'aucun ne vaut pour les autres :

**1. Le code se comporte comme annoncé** — mesuré sur mon arbre : comptes d'appels réseau
(ouverture 0→1, fermeture 1→1, réouverture 1→2) et largeurs en pixels aux quatre longueurs de titre.

**2. Ce code est dans le commit servi** — `git merge-base --is-ancestor` sur les quatre commits, tous
dedans, et `16c7d63` est bien dans `main`. Rien de moi en attente (`16c7d63..origin/main` est vide).

**3. Le paquet servi a bien été construit à partir de là** — `version.json` rend `16c7d63`,
`index.html` date de 03:04:41, et la trace du correctif est dans les octets servis :

    flexShrink:0             → App-C4l8toKu.js        ← le correctif de mise en page
    « ne sachant pas trier » → ABSENT
    « récents »              → App-C4l8toKu.js        ← témoin
    « Heures d »             → ABSENT PARTOUT         ← le renommage de c2 est servi aussi
    « Horaires d »           → PublicApp, TopologieAcces, Parametres, App

⚠ **MON PREMIER TÉMOIN NÉGATIF NE POUVAIT PAS ÉCHOUER, ET JE NE L'AI VU QU'APRÈS L'AVOIR LANCÉ.**
J'avais choisi un commit de rapport « poussé après », pour vérifier que le contrôle savait dire non.
Il répondait « dans le servi » — non pas parce que le contrôle est cassé, mais parce que ce commit
avait été poussé **avant** la construction de 03:04. Un témoin négatif qui ne peut pas échouer est
aussi creux qu'un témoin positif absent : il faut le choisir pour qu'il ÉCHOUE si l'instrument est
faux. Le bon était le sens inverse — `16c7d63` ancêtre d'un de mes vieux commits — qui rend
correctement « non ».

**Et un fait découvert en passant, qui corrige ce que j'avais dit à c2 :** `f0b201e`, sa réévaluation
des impayés, **est dans le commit servi**. Je lui avais écrit qu'elle ne serait pas servie tant
qu'elle ne serait pas fusionnée ; elle l'a été depuis. Le motif `droit_invalide` que j'ai réécrit
décrit donc un comportement serveur désormais réel — et il demande toujours de *vérifier* plutôt
qu'il n'affirme la règle, ce qui reste le bon choix.

### Le service worker : sa purge ne peut pas se déclencher, et son secours hors ligne rote

Trouvé en cherchant, dans mon domaine, l'équivalent de ce que c2 a trouvé dans le sien : un code
déployé sur le disque n'est pas un code qui s'exécute. Côté PHP, c'est `opcache`. Côté frontal, c'est
le service worker et le cache du navigateur.

**LA BONNE NOUVELLE D'ABORD — le chemin en ligne est sain, et mes correctifs s'exécutent bien.**
`frontend/public/sw.js` ne touche ni `/api`, ni `/auth`, ni `/me` ; les assets sont à empreinte de
contenu, donc « cache d'abord » ne peut pas rendre une version périmée ; et la navigation part au
réseau d'abord, le cache ne servant qu'en secours. Un déploiement est donc visible immédiatement.
`index.html` est servi sans `Cache-Control` (revalidation par `ETag`), les assets en
`public, immutable, max-age=31536000` — ce qui est exactement le bon partage.

**LE DÉFAUT : `const VERSION = 'fluvia-v1'` ne change jamais entre deux constructions.** Deux
conséquences, et la seconde est celle qui se voit.

**1. La purge est inerte.** `activate` supprime les caches dont le nom diffère de `VERSION`. Comme
`VERSION` est une constante, il n'existe jamais d'autre nom : la purge ne peut, par construction,
rien supprimer. Or le commentaire au-dessus d'elle dit pourquoi elle existe — « sans elle, chaque
déploiement laisserait derrière lui un cache complet, et le stockage du téléphone finirait par être
refusé ». Le cache d'assets s'accumule donc indéfiniment, exactement ce que la purge dit empêcher.
**Un garde-fou qui documente une intention qu'il ne remplit pas** : c'est la famille qu'on traque
depuis deux jours, et ici il est écrit, relu, et sans effet.

**2. Le secours hors ligne pointe vers des fichiers supprimés.** `install` met `/index.html` en cache
**une seule fois** — il ne se rejoue que si les octets de `sw.js` changent, ce que `VERSION` constant
garantit de ne pas faire. Et la branche de navigation ne réécrit jamais le cache : elle lit au
réseau, et ne retombe sur la coquille qu'en cas d'échec. La coquille en cache reste donc celle du
jour de l'installation, et elle nomme des assets qui n'existent plus. Mesuré :

    assets/App-C4l8toKu.js   http=200   ← la version servie
    assets/App-Cepn9QOd.js   http=404   ← déploiement précédent
    assets/App-CQoZ9DQ8.js   http=404
    assets/App-DZCjmDF0.js   http=404

Le `rsync --delete` du déploiement fait disparaître les anciennes empreintes, et c'est correct. Mais
hors ligne, l'utilisateur reçoit une coquille qui demande `App-Cepn9QOd.js` : page blanche. **La
seule raison d'être déclarée du fichier — « répondre quand le réseau manque » — cesse d'être remplie
au premier déploiement qui suit l'installation.**

**Le correctif tient en un mot, et l'ingrédient existe déjà** : que `VERSION` porte le commit de la
construction, que 73 publie déjà dans `version.json`. Chaque déploiement installerait alors un
nouveau service worker, qui recacherait la coquille et purgerait la précédente — les deux défauts
tombent ensemble.

**Je ne touche pas au fichier** : il n'est pas de moi (`ccc572b`), et un service worker mal remplacé
se répare mal — il survit aux rechargements, et l'ancien continue de servir jusqu'à ce que tous les
onglets soient fermés. C'est typiquement ce qu'on ne veut pas voir décidé par un tiers pendant la
nuit. Signalé, pas corrigé.
