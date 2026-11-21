# API Platform 4.3 — fiche de référence (rendu des erreurs)

- Version du projet : v4.3.17 pour `api-platform/{symfony,state,jsonld,hydra,…}` (source : app/composer.lock, lu le 2026-11-18)
- Vérifiée le : 2026-11-18 · Revoir avant le : 2027-02-16
- Sources :
  - [officielle] https://api-platform.com/docs/v4.3/core/errors/ (doc v4.3, lu le 2026-11-18 ; la doc « courante » est la 5.0)
  - [officielle] code source au tag v4.3.17 : `src/State/ErrorProvider.php`, `src/State/ApiResource/Error.php`, `src/JsonLd/Serializer/ErrorNormalizer.php`, `src/Symfony/Bundle/DependencyInjection/Configuration.php` (https://raw.githubusercontent.com/api-platform/core/v4.3.17/…, lus le 2026-11-18)
  - [officielle] code installé (= verrou v4.3.17) : `app/vendor/api-platform/symfony/EventListener/ErrorListener.php` (`getStatusCode()`), `Bundle/Resources/config/api.php` (câblage de `$debug`), `Bundle/Resources/config/jsonld.php`, `Bundle/DependencyInjection/ApiPlatformExtension.php` l. 341 (lus le 2026-11-18)
  - [projet] app/config/packages/api_platform.yaml (lu le 2026-11-18) : ni `exception_to_status`, ni `serializer.hydra_prefix`, ni `use_symfony_listeners` ne sont déclarés → défauts du bundle.
- Questions couvertes : où le message d'une `HttpException` 409 (`ConflictHttpException`) levée dans un processeur/fournisseur apparaît dans la réponse (JSON-LD, problem+json) ; visible ou non hors mode debug ; `exception_to_status`.
- Hors périmètre : ressources, filtres, pagination (non relus ici).

## À utiliser

### Code HTTP retenu (ordre de décision)
- Ordre documenté : 1) `exception_to_status` s'il y a une correspondance ; 2) si l'exception est une `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`, son statut ; 3-4) `ProblemExceptionInterface` / `HttpExceptionInterface` d'API Platform ; 5) défauts (requête, validation) ; 6) statut d'un `ErrorResource` ; « 500 is the fallback ». [officielle] errors (v4.3) + `ErrorListener::getStatusCode()` installé
- `ConflictHttpException` (Symfony) → **409** par l'étape 2 ; aucune configuration requise.
- Défauts de `exception_to_status` au tag v4.3.17 : `SerializerExceptionInterface` → 400, `InvalidArgumentException` → 400, `OptimisticLockException` → 409. [officielle] Configuration.php@v4.3.17. La doc : « as soon as you declare your own mapping, it replaces the built-in one entirely » — en déclarer une impose de recopier ces défauts. Le projet n'en déclare pas.

### Où va le message
- `ErrorProvider` construit l'erreur par `Error::createFromException($exception, $status)` ; pour une exception qui n'est pas un `ProblemExceptionInterface` (cas de `ConflictHttpException`) : `new self('An error occurred', $exception->getMessage(), $status, …, type: "/errors/$status")` → **`title` = « An error occurred », `detail` = le message de l'exception**. [officielle] Error.php@v4.3.17
- `getDetail()` est dans les groupes `jsonld`, `jsonproblem`, `jsonapi` ; `getDescription()` (groupe `jsonld` seulement) renvoie `$this->description ?? $this->detail`, donc **le même message**. La clé `message` est dans `ignored_attributes` de toutes les opérations d'erreur : **jamais de `message` dans la réponse**. [officielle] Error.php@v4.3.17
- Format **JSON-LD** (`application/ld+json`, opération `_api_errors_hydra`) : `title`, `detail`, `description` (+ `status`, `type`, `@type`…). Le normaliseur d'erreur JSON-LD ne renomme `description` → `hydra:description` et `title` → `hydra:title` QUE si le préfixe Hydra est actif. [officielle] JsonLd/Serializer/ErrorNormalizer.php@v4.3.17
- Préfixe Hydra : `api_platform.serializer.hydra_prefix`, **défaut `false`** [officielle] Configuration.php@v4.3.17 ; la valeur est injectée dans le contexte par défaut du normaliseur d'erreur (`ApiPlatformExtension` l. 341 → `%api_platform.serializer.default_context%`, jsonld.php). Le projet ne le déclare pas → **pas de `hydra:description`**, le message est dans **`detail` et `description`**.
- Format **problem+json / json** (opération `_api_errors_problem`, groupe `jsonproblem`) : `title`, `detail`, `status`, `type` ; **pas de `description`** (groupe `jsonld` seulement). [officielle] Error.php@v4.3.17
- Conclusion pour le frontal (`client.js` lit `message || hydra:description || detail || description || title`) : avec la config du projet, c'est **`detail`** qui porte le message, en JSON-LD comme en problem+json. Témoin positif côté tests : plusieurs tests existants lisent `toArray(false)['detail']` sur des refus métier (ex. `tests/Vente/Api/PeriodClosureTest.php`). [projet]

### Hors mode debug (production, `kernel.debug = false`)
- `ErrorProvider` reçoit `$debug = %kernel.debug%` (api.php). Seul masquage : `if (!$this->debug && $status >= 500 && method_exists($error, 'setDetail')) { $error->setDetail('Internal Server Error'); }`. [officielle] ErrorProvider.php@v4.3.17
- Doc : « If your status code is >= 500 and < 600, the exception message will only be displayed in debug mode (dev and test). In production, a generic message matching the status code provided will be shown instead. » [officielle] errors (v4.3)
- Donc un **409 garde son message en production** (`detail`, et `description` en JSON-LD). Un 5xx le perd (`detail` = « Internal Server Error »).
- Point non tranché par le code lu : en JSON-LD 5xx hors debug, `description` vaut `$this->description ?? $this->detail` ; comme `setDetail()` a remplacé `detail` et que `description` n'est pas posé par `createFromException`, il suit `detail` (lecture du code, non mesurée). Sans objet pour un 409.

## Pièges connus
- Le message d'une `HttpException` 4xx part **tel quel au client, en production** : n'y mettre aucun détail technique (identifiant interne, clé, trace) — le masquage ne vaut que pour les 5xx. [officielle] ErrorProvider.php@v4.3.17
- Une exception non HTTP (ex. `\RuntimeException`, `UniqueConstraintViolationException`) non rattrapée → 500 → message masqué en production mais **visible en dev/test** : un test vert sur le texte d'un 500 ne prouve rien pour la production. [officielle] errors (v4.3)
- Activer un jour `serializer.hydra_prefix: true` déplacerait `description`/`title` vers `hydra:description`/`hydra:title` en JSON-LD (`detail` reste). [officielle] ErrorNormalizer.php@v4.3.17
- Déclarer `exception_to_status` remplace les défauts (dont `OptimisticLockException` → 409) au lieu de les compléter. [officielle] errors (v4.3)

## À confirmer par le plan
- Le test d'intégration du plan mesure le corps du 409 en environnement `test` (debug actif). Le comportement hors debug ci-dessus est établi par la doc et le code, pas mesuré en préproduction.
- `Accept` réellement envoyé par le frontal sur la route concernée (JSON-LD ou problem+json) : non vérifié ici ; sans effet sur la conclusion (`detail` présent dans les deux).

## Écarts entre sources
- L'exemple JSON-LD de la doc v4.3 montre `title` et `description` sans `detail` ; le code v4.3.17 sérialise aussi `detail` dans le groupe `jsonld`. Le code de la version installée l'emporte ; les tests du projet lisent `detail` avec succès.
