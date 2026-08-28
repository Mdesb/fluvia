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
