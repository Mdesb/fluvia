# Faits établis — référentiel Métiers

**Date :** 2026-09-07
**Méthode :** cinq lectures parallèles du dépôt (domaine, site, specs, tunnel, tests), puis
revérification directe des points qui portent une décision. Chaque fait porte VERIFIED (j'ai ouvert
le fichier ou exécuté la mesure) ou UNVERIFIED (rapporté, non recoupé).

---

## 1. Où la liste des métiers est figée aujourd'hui

**VERIFIED — neuf points de figement**, dont sept qui échouent en silence.

| # | Fichier | Nature | Ce qui se passe si on l'oublie |
|---|---|---|---|
| 1 | `app/src/Fonctionnalite/Enum/Metier.php` | énumération, 5 cases | `Metier::tryFrom()` rend `null` → `StructureOnboarding::metier()` rend `null` → **`appliquerPreset()` n'est jamais appelé**. La structure s'ouvre avec zéro capacité, sans erreur ni journal. |
| 2 | `app/src/Fonctionnalite/Config/PresetVerticale.php` | table constante | `?? []` avale l'oubli : le client reçoit les 2 capacités communes sur 15. |
| 3 | `app/src/Fonctionnalite/Enum/CapaciteCode.php` | énumération jumelle | Les 5 métiers existent AUSSI comme codes de capacité ; c'est ce doublon qui fait marcher l'exclusion « un métier n'est pas un module vendable ». Sans le jumeau, aucun module ne peut s'adosser au métier. |
| 4 | `app/src/Fonctionnalite/Service/CatalogueCapacites.php` | `match` sans branche par défaut | **Le seul défaut fatal** : `UnhandledMatchError`, tout le catalogue tombe. C'est exactement la panne du 06/09 (`Connecteurs`), corrigée. |
| 5 | `app/src/Website/Service/MetierCatalog.php` (`NOMS`) | table constante | Pas de `??`, à deux endroits — alors que ses voisines `SPECIFICITES` et `ECRANS` en ont un. La page répond 200 avec un `<title>` vide. |
| 6 | `app/src/Website/Service/MetierCatalog.php` (`SPECIFICITES`, `ECRANS`) | tables constantes | Page 200, zéro argument de vente, zéro écran. Indiscernable d'une page saine pour tous les contrôles existants. |
| 7 | `app/src/Organisation/Service/StructureOnboarding.php` (`NAF_VERS_METIER`) | table constante | Le métier n'est jamais suggéré depuis le SIRET. |
| 8 | `frontend/src/components/OuvrirStructure.jsx` | liste recopiée à la main | Le métier reste invisible dans l'écran « Ouvrir une structure ». |
| 9 | `app/tests/Website/WebsiteMetiersTest.php` | test | Ne vérifie que des présences. Reste vert. |

## 2. Deux dérives déjà installées

**VERIFIED — la table NAF ne couvre que deux métiers sur cinq.**
`StructureOnboarding::NAF_VERS_METIER` porte six codes : trois vers `sport`, deux vers `musee`, un
vers `sport`. **Piscine, padel et patinoire n'y figurent pas.** La suggestion depuis le SIRET ne peut
donc aujourd'hui proposer que « salle de sport » ou « musée ».

**VERIFIED — l'écran « Ouvrir une structure » sous-décrit la patinoire.**
`OuvrirStructure.jsx` recopie à la main, dans une colonne `allume`, ce que chaque métier active.

- **Patinoire** — l'écran annonce deux modules (« contrôle d'accès, location de matériel ») ; le
  préréglage en active cinq : il tait **réservation**, **casiers** et **encadrants**.

⚠ **ET J'AI D'ABORD CRU QU'IL MENTAIT AUSSI SUR LE PADEL. C'ÉTAIT FAUX, ET L'ERREUR EST
INSTRUCTIVE.** L'écran annonce « location » pour le padel là où le préréglage n'active pas la
capacité `location_materiel` — j'en ai conclu un mensonge. Vérification faite : la location du padel
n'est **pas** gardée par cette capacité. Elle vit dans le module padel, sous la permission
`padel.lire` (`app/src/Padel/ApiResource/GrilleRetenueMateriel.php:29`). La capacité
`location_materiel` n'est exigée que par `PatinoireModule` (`app/src/Patinoire/PatinoireModule.php:94`).
Un club de padel a donc bien sa location. J'avais comparé une CAPACITÉ à une FONCTIONNALITÉ.

**Et c'est exactement le piège que G-3 doit désamorcer.** Trois descriptions coexistent de ce qu'un
métier « allume » — le préréglage (des capacités), `specs/verticales/composition.md` (des activités),
et l'écran React (une phrase) — et elles ne parlent pas de la même chose. Déduire les modules des
activités n'est donc pas un simple recalcul : c'est choisir laquelle de ces trois vues fait foi, et
mesurer les écarts avec les deux autres au lieu de les faire disparaître.

## 3. Ce que le test promet et ne mesure pas

**VERIFIED.** `WebsiteMetiersTest` porte en en-tête « Les cinq métiers du produit, **et eux seuls** ».
Son corps boucle sur cinq slugs et vérifie leur PRÉSENCE dans le HTML. Il ne mesure ni l'exhaustivité
ni l'unicité. Un sixième métier, ou un métier sans nom, le laisse vert.

Même famille pour `PresetVerticaleTest` : il assure qu'un préréglage est « non vide » — or les deux
capacités communes le garantissent toujours. Un préréglage manquant reste vert.

## 4. Ce qui existe déjà et qu'il ne faut pas réinventer

**VERIFIED.**

- **D15** (`COORDINATION/DECISIONS.md`) pose que neuf types d'activité couvrent tous les métiers, et
  qu'« une verticale devient un paquet rédigé, pas un module développé ». C'est l'arbitrage qui
  fonde ce chantier.
- **`specs/verticales/`** — `claude-I` a déjà appliqué D15 aux cinq verticales : `composition.md`
  donne la composition d'activités de chacune, `paquet.md` donne le format. **Les cinq compositions
  n'ont donc pas à être inventées, elles sont écrites.**
- **`paquet.md` orthographie quatre des neuf types en anglais** : `entry`, `resource_booking`,
  `coaching`, `equipment_rental`. Les cinq autres n'ont pas d'orthographe arrêtée.
- **`StructureOnboarding`** fait déjà SIRET → NAF → métier côté serveur : le pré-remplissage de
  l'étape 1 du tunnel s'appuie dessus, il n'est pas à écrire.
- **`SiteBlocks::blocsDeMetier()`** crée déjà, par métier, un bloc de contenu éditable
  (`metier.<code>.body`) : le corps rédigé d'une page métier est **déjà** en base et éditable.

## 5. Points UNVERIFIED

- **UNVERIFIED** — comportement exact d'un accès `NOMS['inconnu']['nom']` **en environnement de
  test et de développement**. Mesuré dans le conteneur de préproduction (PHP 8.4.25) : deux
  avertissements et `null`, pas d'erreur fatale. Le gestionnaire d'erreurs de Symfony transforme
  habituellement ces avertissements en exception hors production ; **la page n'a pas été exécutée
  pour le vérifier**. Sans incidence sur la spec : le référentiel supprime le cas.
- **UNVERIFIED** — l'orthographe anglaise des cinq types d'activité non fixés par `paquet.md`.
  Ce n'est pas un blocage : la spec propose des noms et signale qu'ils appartiennent au périmètre
  de `specs/verticales/**`, à faire confirmer par son propriétaire.
