# i18n & agent de traduction — v1 (08/10/2026)

Service transverse du noyau. **Le code est en anglais (D5)** ; l'utilisateur voit sa langue via des
**clés de traduction**. L'app ne fait **aucun appel IA en requête** : elle lit des catalogues statiques,
versionnés et relus (performance, et invariant « dégradation propre »).

> **Corrigé le 08/10/2026 (décision de Maxime, QCM)** : la langue **source est le français**, plus
> l'anglais. La v0 (19/08) posait `en` comme source ; personne n'écrit l'interface en anglais, et une
> source qu'on n'écrit pas est une traduction de plus à tenir. Le français est écrit à la main ;
> l'espagnol est la première cible, générée ; les surfaces publiques sont relues par un humain.
> Aucune dépendance externe (ni i18next, ni react-intl, ni `symfony/translation`).

## Principe
1. **Clés stables, jamais de chaîne en dur dans un écran converti.** Un écran écrit
   `t('login.title')`, jamais « Connexion ». Clés en anglais (D5), groupées par écran (`login.*`)
   ou par usage (`error.*` pour les erreurs d'API).
2. **Un catalogue JSON plat par langue** : `frontend/src/i18n/fr.json` (source), `es.json` (cible).
   `{nom}` pour les paramètres.
3. **Repli** : une clé absente de la langue cible se rend **en français**, jamais en clé brute.
   L'absence est notée (`missingKeys()`) et signalée dans la console en développement.
4. **Langues** : `fr` et `es` ouvertes ; `ca`, `eu`, `gl` entreront chacune avec son catalogue.
   La liste fait foi à deux endroits qui doivent rester égaux (le garde-fou le vérifie) :
   `App\I18n\Locales::SUPPORTED` et les catalogues présents.

## Où vit la langue
- **Établissement** : `Etablissement.locale` (colonne `org_etablissement.locale`, défaut `fr`, non
  nulle). Langue des écrans de ses agents et des **documents** qu'il émet. Réglable dans
  Paramètres › Entités › Établissements (champ « Langue »).
- **Utilisateur** : `Utilisateur.locale` (`sec_utilisateur.locale`, **nullable** ; `null` = suit
  l'établissement). Exposée dans `/me`, écrite par `PATCH /api/utilisateurs/{id}` (droit
  `securite.gerer`). Pas encore d'écran de choix personnel (lot « paramètres »).
- **Avant la connexion** : la dernière langue de l'appareil (`localStorage` `fluvia.langue`),
  sinon celle du navigateur si on la parle, sinon `fr`.
- **Langue active du frontal** = préférence de l'utilisateur, sinon langue de l'établissement actif
  (`applyContextLanguage` dans `App.jsx`, avant le premier rendu connecté et à chaque changement
  d'établissement). Le pays et la devise de l'établissement règlent le formatage.

## Frontal (`frontend/src/i18n/`)
- `core.js` : `t(clé, paramètres)`, `setLanguage`, `pickLanguage`, `translateApiError`,
  `missingKeys`. Sans import JSON (chargeable par `node --test`).
- `format.js` : **le seul endroit qui choisit une locale `Intl`** — `formatDate`, `formatDateTime`,
  `formatTime`, `formatNumber`, `formatMoney`, `formatMoneyCents` (locale `<langue>-<PAYS>`, devise de
  l'établissement). Remplace `'fr-FR'` en dur.
- `index.js` : point d'entrée des écrans ; enregistre les catalogues, pose la langue de l'appareil,
  `applyContextLanguage`, `LANGUAGE_NAMES` (chaque langue dans sa langue).
- `api/client.js` envoie `Accept-Language: <langue affichée>` à chaque appel, et traduit les erreurs
  codées avant de construire `ApiError.message`.

## Serveur (`app/src/I18n/`)
- `RequestLocale::current()` — langue de la requête pour les processeurs : `Accept-Language` s'il
  nomme une langue parlée (le frontal y met la langue affichée), sinon l'établissement actif, sinon `fr`.
- `Locales::ofEstablishment()` — langue d'un **document** : celle de l'établissement émetteur, pas
  celle de l'écran qui le déclenche. Passée aux gabarits Twig comme `locale` (billet PDF, facture,
  confirmation de commande, relance de panier, rappel de rendez-vous ; le courriel de vérification
  prend la langue du compte). Les gabarits ne sont **pas encore traduits** : ils posent seulement
  `<html lang="{{ locale|default('fr') }}">`.
- **Erreurs d'API codées** : `CodedHttpException(statut, code, message français, paramètres)` — levée
  par un processeur (`CodedHttpExceptionListener` la met en forme) ou rendue par un contrôleur
  (`toResponse()`). Corps : `{status, detail, message, code, params}`. Le frontal cherche
  `error.<code>` dans le catalogue actif et garde `message` (français) si la clé lui est inconnue.
- ⚠ Aucune réponse ne dépend encore de la langue. Le jour où une le fera, ajouter `Accept-Language`
  à `cache_headers.vary` (`app/config/packages/api_platform.yaml`), sinon un cache servira la
  réponse d'une langue à l'autre.

### Pourquoi pas de catalogue côté serveur
Les messages d'erreur d'API sont **affichés par le frontal**, qui a déjà les catalogues et connaît la
langue affichée : un second catalogue serveur dupliquerait chaque texte, divergerait au premier
correctif, et exigerait `symfony/translation` (absent : seul `translation-contracts` est installé).
Un code stable vaut aussi mieux qu'un texte traduit pour les clients machine (API partenaire).
L'exception légitime : ce que le serveur rend **lui-même** (PDF, courriels). Ce lot leur passe la
`locale` ; leur traduction (et alors un catalogue serveur, ou des gabarits par langue) est un lot
à part.

## Relecture
- **UI interne** (back-office, caisse, accès) : traduction générée **active dès le commit**.
- **Surfaces publiques** (boutique `src/public/`, courriels et documents clients, site) : **relecture
  humaine avant publication**. Une traduction non relue va dans `<langue>.a-relire.json` (non chargé :
  l'écran reste en français) ; un humain la déplace dans `<langue>.json` une fois relue.
- Une entrée relue n'est jamais réécrite par l'agent sans instruction.

## Garde-fous
- `frontend/scripts/verifier-chaines-traduites.mjs` (lanceur, `pre-commit`, `pre-receive`) :
  1. aucun texte en dur dans les fichiers de `frontend/src/i18n/converted-files.json` — la liste
     **grandit** d'un lot à l'autre, un fichier non converti n'est pas bloqué ;
  2. toute clé `t('…')` existe dans `fr.json` ;
  3. les catalogues cibles : clés ⊆ source, non vides, mêmes jetons `{nom}` ;
  4. catalogues présents = `Locales::SUPPORTED`.
  Échappatoire visible : `// i18n-ignore` sur la ligne.
- `node --test src/i18n/*.test.js` : `t()`, repli, formatage, détecteur.
- **Aucun secret / PII** dans un catalogue : seulement des chaînes d'interface.

## Statut
◆ **Socle livré le 08/10/2026** (branche `feature/i18n-socle`) : langue établissement + utilisateur,
`t()` et repli, formatage `Intl`, `Accept-Language` et `RequestLocale`, erreurs codées, locale passée
aux gabarits, garde-fou, écran témoin **Connexion** en `fr` et `es`.

◇ **À venir** (périmètre piscine, dans l'ordre ; tailles mesurées le 08/10 avec le détecteur du
garde-fou — borne basse, les mots seuls passés en argument lui échappent) :

| Lot | Fichiers | Chaînes | `'fr-FR'` | Erreurs serveur à coder |
|---|---:|---:|---:|---|
| Caisse (`Caisse`, `SessionCaisse` + composants) | 10 | ≈ 500 | 8 | `Caisse` 22, `Vente` 100 |
| Accès (`Acces`, `Supervision`, `JournalPassages`, `TopologieAcces`, `ControleBillet`) | 7 | ≈ 500 | 12 | `Acces` 53 |
| Abonnement (`Abonnements`) | 1 | ≈ 35 | 1 | `Membership` 45, `Subscription` 39 |
| Boutique publique (`src/public/`, relecture humaine) | 16 | ≈ 270 | 8 | `Boutique` 104 |
| Clients (`Clients` + composants) | 9 | ≈ 420 | 10 | `Crm` 40 |
| Catalogue (`Catalogue`, `ProduitFiche` + composants) | 5 | ≈ 410 | 3 | `Offre` 45 |
| Paramètres (`Parametres` + composants, dont le choix de langue personnel) | 14 | ≈ 850 | 8 | `Organisation` 11, `Securite` 17 |

Transverses : `components/Liste.jsx` (`euroCentimes`, `dateFr`, `dateHeureFr` → `format.js` :
3 lignes, 188 appels convertis d'un coup) ; `api/vocabulaire.js` (codes → français, 363 lignes) à
porter en clés `vocab.*` ; libellés produits `{locale: valeur}` lus en `fr` en dur par
`api/produit.js` et `public/lib/format.js` ; gabarits Twig et sujets/textes des courriels.
Tout `src/` : ≈ 9 500 chaînes dans 195 fichiers ; `'fr-FR'` : 132 appels dans 71 fichiers.
