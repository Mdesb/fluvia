# Spec — Abonnement transverse (sortir l'engagement de Sport) + onglet Exploitation

Issues : **#14** (P1, onglet Abonnements) + **#1 / T38** (l'engagement est un module à part entière, pas du sport).
Décision Maxime (CP‑1, 07/09) : **généraliser d'abord**, puis l'onglet.

## 1. Pourquoi

L'abonnement client existe déjà — `App\Sport\Entity\AbonnementFitness` — et il est correct sur le fond : il lie une **Formule du catalogue**, un **Client (payeur)**, un **Établissement**, avec dates d'engagement, statut, périodicité, pauses, résiliation, réengagement, échéancier. **Mais il est enfermé dans le module `Sport`**, mélangé à du vraiment-sportif (SOS, présence isolée, accès nocturne), et **consommé hors de Sport** (Boutique souscription en ligne, Recouvrement, Sepa). C'est le couplage « bancal ».

## 2. Cible

- **Nouveau module `Membership`** (transverse), auto‑enregistré (`ModuleManifest`, tag `platform.module`), **sans capability** : `capability()` rend `null`.
  > **Corrigé le 10/09 (arbitrage Maxime, en réponse au plan du lot 0).** Cette ligne demandait une capability au catalogue, en justifiant : « sinon le module est présent mais inaccessible ». **La justification était fausse.** Le docblock de `ModuleManifest` dit l'inverse : `null` désigne un **service transverse** — cas prévu, exempté du garde‑fou n°41. Le cas « présent mais inaccessible » est celui d'un code **inventé, absent du catalogue**. Sur le fond, l'argument de `GroupModule` vaut ici : piscine, padel, patinoire, musée et sport vendent tous des abonnements ; en faire une capacité à cocher créerait une porte fermée là où il n'en faut pas. Poser la capacité plus tard est une ligne plus un descripteur ; retirer une option déjà annoncée en vitrine serait une régression produit.
  > **Et le nom technique est `Membership`, pas `Abonnement`.** Le CP‑1 du 07/09 tranchait le mot **produit**, qui reste « abonnement » à l'écran. Le mot ne peut pas être porté par du code : `abonnement` est au lexique français que le garde‑fou n°2 refuse dans tout fichier ajouté (D5), et `App\Subscription` est déjà pris par la facturation SaaS de l'éditeur — ce que l'établissement achète à Fluvia, l'exact miroir de ce que l'adhérent achète à l'établissement.
- **Entité `Abonnement`** généralisée depuis `AbonnementFitness` : `payeur` (Client), `etablissement`, `formule` (catalogue), engagement (début/fin), statut, périodicité. **Cloisonnée par établissement** (extension Doctrine + assertions sur les écritures par identifiant du corps).
- **Cycle de vie** porté par le module : pause, résiliation, réengagement, **avoirs** (proratisation).
- **Prix + TVA toujours depuis le catalogue** via `App\Offre\Service\SubscriptionPriceResolver` (relation `Produit → Formule`, `Produit.tauxTva`). **Pas de prix libre** (arbitrage Maxime 01/09). — C'est la **racine commune avec #15** (facturation prix+TVA).
- **SEPA / échéancier** : réutiliser le Port existant `App\Sepa\Port\EcheanceSepaSource` (déjà généralisé — `Reservation` l'implémente aussi). L'abonnement fournit sa source d'échéances ; on ne réécrit pas le moteur SEPA.

## 3. Ce qui RESTE dans Sport

Fitness‑accès (`StatutAccesFitness`, `PropagationAccesFitnessHandler`), SOS / présence isolée / accès nocturne (`EvenementSOS`, `DeclencherSos*`, `ConfigAccesNocturne`, `SosRateLimiter`). **`Sport` déclarera une dépendance vers `abonnement`** (`dependencies()`), au lieu de le contenir.

## 4. Onglet Exploitation « Abonnements » (front)

- **Voir/suivre** : liste cloisonnée — statut (actif/suspendu/résilié), échéance, montant (catalogue), produit/formule, payeur.
- **Créer depuis le catalogue** : choisir un produit/formule → **prix + TVA repris** (`SubscriptionPriceResolver`), modifiables selon les règles catalogue.
- **Cycle de vie** : suspendre, résilier (avec avoir/proratisation), réengager.
- **Hors périmètre** (Maxime) : piloter la récurrence SEPA automatique. L'échéancier tourne déjà en fond ; l'onglet l'affiche mais ne le pilote pas.

## 5. Invariants / sécurité

Cloisonnement par établissement (jamais confiance à `X‑Etablissement` sans contrôle) · catalogue = **seule** source prix/TVA · NF525/argent : avoirs traçables, chaîne préservée · **migrations écrites à la main** · **ne rien casser** : Sepa/Recouvrement/Boutique sont branchés dessus → recâblage progressif, **suite verte à chaque lot**.

## 6. Phasage (lots livrables, chacun vert et mergé seul)

- **Lot 0 — Socle.** Module `Membership` (transverse, sans capability) + entité `Membership` (structure d'`AbonnementFitness`, cloisonnée) + migration écrite à la main. **Aucune exposition d'API** : une ressource que rien n'appelle est une bascule à moitié, et elle consommerait la marge du garde‑fou d'écart client/serveur. Aucune bascule encore. Suite verte. — *livré le 10/09, plan : `plans/plan-abonnement-socle-lot0.md`*
- **Lot 1 — Bascule.** Migrer `AbonnementFitness` (+ `PauseAbonnement`, `Resiliation`, `Reengagement`, échéancier) vers le module `Abonnement` ; recâbler les **consommateurs hors‑Sport** (Boutique en ligne, Recouvrement redevable/moteur, Sepa echeance source) via les Ports. `Sport` dépend d'`Abonnement`. Suite verte.
- **Lot 2 — Cycle de vie complet.** Résiliation + **avoirs/proratisation** (si absent), pause/réengagement portés par le module.
- **Lot 3 — Onglet Exploitation.** Liste + création depuis catalogue (prix+TVA) + actions cycle de vie + entrée de nav Exploitation. **C'est ici qu'arrive la valeur visible** (assumé : elle vient après le socle).

## 7. Coordination

- **#15** (facturation prix+TVA) partage `SubscriptionPriceResolver` → aligner avec la session SEPA (même mécanisme, une fois).
- **Recouvrement / Sepa** sont le domaine de la session SEPA → le recâblage du Lot 1 se fait **de concert** avec elle, pas dans son dos.

## 8. À trancher (CP‑1)

1. ~~**Nom du module/capability** : `engagement` ou `abonnement` ?~~ **Tranché.** Produit : « abonnement » (CP‑1, 07/09). Technique : `Membership` (10/09) — voir §2. Pas de capability.
2. **Avoirs** : réutilise‑t‑on un mécanisme d'avoir existant (Comptabilité/Finance) ou en crée‑t‑on un dans le module ?
