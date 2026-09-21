# Notes de conception — Contrôle d'accès : passage au tour, FMI, mode offline

**Statut :** PRÉ-SPEC (aucun code, rien poussé). Décisions au fil de l'eau avec Maxime.
**Base :** origin/main `700ca8b3` (21/09). Branche `feature/acces-passage-fmi` (worktree `/home/debian/wt/acces-passage-fmi`) — support de consignation, PAS encore de build.
**Bloqueur CP-1 :** la sémantique de comptage (un franchissement compte 1 ou N personnes ?) attend l'exemple concret adultes/enfants de Maxime.

## 1. Périmètre du chantier (demande Maxime)

Faire évoluer le guide d'intégration + le modèle `App\Acces` pour :
1. **Valider le passage au TOUR MÉCANIQUE du tourniquet**, pas seulement au badgeage. Aujourd'hui le passage est acté dès le badge → on veut l'info « un tour a bien été fait ».
2. **Types d'équipement liés aux lecteurs** : un lecteur (badge/QR) et l'organe mécanique (tourniquet/tripode) qui le franchit doivent être reliés explicitement.
3. **Capturer les SORTIES** : aujourd'hui on ne remonte pas (ou mal) l'info de sortie.
4. **Méthode FMI présente aux deux endroits** : côté serveur (« chez nous ») ET en local sur l'ITBox.
5. **Bidirectionnel** : la FMI peut venir de **caméras de comptage** (pas d'accès physique, pas de badge) → le canal doit communiquer dans les deux sens (la box pousse ses comptages, le serveur pousse la jauge/seuil).
6. **Offline « pense bête »** : borner la taille de la DB locale de l'ITBox en retirant les billets sans compostage (voir §4).

## 2. Décisions déjà prises (Maxime)

- **Q2 — Relation lecteur ↔ tourniquet : relation explicite sur `Equipement`.** (Recommandé retenu.) Un `Equipement` lecteur et un `Equipement` organe mécanique sont reliés par une relation portée sur l'entité `Equipement`, pas déduite d'une convention de nommage.
- **Q3 — FMI locale sur l'ITBox : OUI, jauge + seuil locaux, synchronisés.** La box tient une copie locale de la jauge (`JaugeFmi`) et du seuil, réconciliée avec le serveur. Le serveur reste source de vérité ; la box décide en < 1 s y compris hors-ligne (cf. `DroitAcces`, US-L3-03).
- **Caméras de comptage = source FMI enfichable** via le point d'entrée existant `App\Acces\Service\ComptageNonNominatifHandler::compter(Equipement, SensPassage, motif, horodatage)` (résultat `ResultatPassage::Compte`, incrémente/décrémente `JaugeFmi`). Une caméra n'a pas de support/badge → c'est exactement ce que ce handler modélise. Bidirectionnalité calquée sur le patron `PiloteAcces` (recevoir des événements + pousser l'état).

## 3. En attente de Maxime (bloque CP-1)

- **Sémantique de comptage.** Un franchissement = 1 passage aujourd'hui. Maxime doit fournir l'exemple concret **produits adultes/enfants** pour trancher si un passage peut compter un nombre variable de personnes (billet famille, groupe) et comment la FMI l'agrège. Verbatim Maxime : « je vais te faire une explication concrète avec les produits adultes/enfants comme ça on verra et on pourra approfondir ». → NE PAS spécifier le comptage avant cet exemple.

## 4. Mode offline — purge de la DB locale ITBox (note Maxime « pense bête »)

**Intention (verbatim) :** « il faudra qu'on enlève les billets qui n'ont pas de compostages comme ça la db en local ne grossit pas trop. »

**Traduction dans le modèle :**
- *billet* = `vente_billet_support` (colonne `nb_compostages`), projeté sur la box en `DroitAcces` (`sourceType = TypeDroitAcces::Billet`, fenêtre `fenetreDebut`/`fenetreFin`, `creditRestant`).
- *compostage* = un `Passage`/validation. « Billet sans compostage » = `nb_compostages = 0` → jamais franchi.
- Objectif : la box journalise passages + projette les droits vendus → la DB locale enfle. On veut la borner.

**⚠ Garde-fou non négociable (à écrire dans la spec) :** ne JAMAIS purger un billet **encore utilisable** — dans sa fenêtre de validité et non consommé — sinon on **verrouille un porteur légitime hors-ligne** (cf. [[absence-dont-la-condition-n-est-pas-atteinte]], [[le-cas-qui-demasque-est-un-franchissement]]). Un billet sans compostage n'est « mort » que lorsqu'il ne peut plus servir.

**Déclencheur de purge = QUESTION OUVERTE (à poser en CP-1, avec reco) :**
- (Reco) Purger un billet sans compostage **seulement quand sa fenêtre de validité est passée** (expiré + jamais franchi) : zéro risque de verrouillage ; la box ne garde que des billets encore utilisables + le tampon récent.
- (Alternative) **Horizon glissant** : ne projeter/garder que les billets valides sur J..J+n, purger le reste chaque nuit → DB plus petite, mais il faut fixer l'horizon ET gérer le porteur qui se présente hors horizon (repli en ligne / re-projection à la demande).
- Symétrique à préciser : les billets **consommés** (compostage remonté au serveur) peuvent aussi être purgés **après** confirmation de remontée (patron journal→upload→purge de `SynchroPassageHandler`), sans perdre la piste d'audit côté serveur.

## 5. Ancrage (fichiers lus sur origin/main, non modifiés)

- `app/src/Acces/Service/ValidationPassageHandler.php` — `valider(EvenementPassageDto): Passage` valide le droit ET incrémente `JaugeFmi` AU BADGEAGE, une seule transaction (Entrée +1, Sortie −1 ; `ModeSeuil::Blocage` → `SeuilFmi`). C'est le point du « comptage au badge, pas au tour ».
- `app/src/Acces/Service/ComptageNonNominatifHandler.php` — comptage non nominatif (caméra), `ResultatPassage::Compte`.
- `app/src/Acces/Entity/Equipement.php` — `controleur` (ManyToOne), `type` (`TypeEquipement`), `sens` (`SensEquipement`), `antiPassback`, `etablissement`. **Aucun lien lecteur↔tourniquet aujourd'hui** (docblock : « tourniquet/tripode iDTRONIC OU lecteur QR/RFID »).
- `app/src/Acces/Entity/JaugeFmi.php` — `acces_jauge_fmi`, OneToOne `EspaceAcces`, `valeurCourante`/`seuil`/`mode`/`cumulJour`.
- `app/src/Acces/Entity/DroitAcces.php` — projection LOCALE du droit vendu (offline), `sourceType`/`creditRestant`/`fenetreDebut`/`fenetreFin`/`billetSupportRef`/`synchroniseLe`/`statutProjection`.
- `app/src/Acces/Dto/EvenementPassageDto.php` — `equipementId`, `identifiantSupport`, `sens (?SensPassage)`, `cleIdempotence`, `origineHorsLigne`, `horodatageBorne`.
- `app/src/Acces/Port/PiloteAcces.php` — contrat intégrateur (recevoirEvenement/ouvrir/heartbeat/pousserListeRevocation).
- `app/src/Acces/ApiResource/SnapshotTerminal.php`, `SynchronisationAcces.php` — ce qui est poussé vers la box (dont `compostagesRestants`).
- `app/docs/aide/acces/{controle-acces.md, bornes-itbox-mode-degrade.md}` — ITBox décide localement hors-ligne, journalise, remonte à la reconnexion ; `JaugeFmi` reste côté serveur aujourd'hui (→ Q3 la fait descendre en local).

## 6. Coordination (branches/issues voisines à ne pas percuter)

- **#181** « Rattacher un droit d'accès à l'appairage » + `fix/acces-ouvert-sans-support-rattache` — touche `DroitAcces`↔support/appairage. Mon offline projette `DroitAcces` : se caler dessus.
- **docs/arbitrage-droit-acces-porteur** — arbitrage porteur ; lire avant de figer la projection.
- **front-acces-topologie** / `#140` (aucun chemin servi par deux portes) — topologie équipements ; la relation lecteur↔tourniquet (Q2) doit s'y accorder.

## 7. Prochaine étape

1. Recevoir l'exemple adultes/enfants de Maxime → figer la sémantique de comptage.
2. Écrire la spec (CP-1) via le kit : `chercheur` → spec → `contradicteur`/perspectives → QCM (dont : déclencheur de purge offline §4, relation Q2, descente FMI Q3, capture sorties, validation au tour mécanique).
3. Rien de code avant CP-1 validée.
