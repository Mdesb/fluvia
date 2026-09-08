# Spec — Abonnement transverse (sortir l'engagement de Sport) + onglet Exploitation

Issues : **#14** (P1, onglet Abonnements) + **#1 / T38** (l'engagement est un module à part entière, pas du sport).
Décision Maxime (CP‑1, 07/09) : **généraliser d'abord**, puis l'onglet.

## 1. Pourquoi

L'abonnement client existe déjà — `App\Sport\Entity\AbonnementFitness` — et il est correct sur le fond : il lie une **Formule du catalogue**, un **Client (payeur)**, un **Établissement**, avec dates d'engagement, statut, périodicité, pauses, résiliation, réengagement, échéancier. **Mais il est enfermé dans le module `Sport`**, mélangé à du vraiment-sportif (SOS, présence isolée, accès nocturne), et **consommé hors de Sport** (Boutique souscription en ligne, Recouvrement, Sepa). C'est le couplage « bancal ».

## 2. Cible

- **Nouveau module `Abonnement`** (transverse), auto‑enregistré (`ModuleManifest`, tag `platform.module`), avec une **capability** ajoutée à `App\Fonctionnalite\Enum\CapaciteCode` (sinon le module est présent mais inaccessible — RG‑PLAT).
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

- **Lot 0 — Socle.** Module `Abonnement` + capability au catalogue + entité `Abonnement` (structure d'`AbonnementFitness`, cloisonnée) + migration. Aucune bascule encore. Suite verte.
- **Lot 1 — Bascule.** Migrer `AbonnementFitness` (+ `PauseAbonnement`, `Resiliation`, `Reengagement`, échéancier) vers le module `Abonnement` ; recâbler les **consommateurs hors‑Sport** (Boutique en ligne, Recouvrement redevable/moteur, Sepa echeance source) via les Ports. `Sport` dépend d'`Abonnement`. Suite verte.
- **Lot 2 — Cycle de vie complet.** Résiliation + **avoirs/proratisation** (si absent), pause/réengagement portés par le module.
- **Lot 3 — Onglet Exploitation.** Liste + création depuis catalogue (prix+TVA) + actions cycle de vie + entrée de nav Exploitation. **C'est ici qu'arrive la valeur visible** (assumé : elle vient après le socle).

## 7. Coordination

- **#15** (facturation prix+TVA) partage `SubscriptionPriceResolver` → aligner avec la session SEPA (même mécanisme, une fois).
- **Recouvrement / Sepa** sont le domaine de la session SEPA → le recâblage du Lot 1 se fait **de concert** avec elle, pas dans son dos.

## 8. À trancher (CP‑1)

1. **Nom du module/capability** : `engagement` (le mot de T38) ou `abonnement` (le mot de l'onglet) ?
2. **Avoirs** : réutilise‑t‑on un mécanisme d'avoir existant (Comptabilité/Finance) ou en crée‑t‑on un dans le module ?
