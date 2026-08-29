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
