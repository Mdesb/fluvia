# PHPUnit 13.3 (+ Symfony BrowserKit 7.4) — fiche de référence

- Version du projet : phpunit/phpunit 13.3.1 (source : app/composer.lock, ligne 10652) ; symfony/browser-kit v7.4.14 (app/composer.lock, ligne 11902)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://docs.phpunit.de/en/13.3/attributes.html (lu le 2026-10-07)
  - [officielle] https://docs.phpunit.de/en/13.3/writing-tests-for-phpunit.html (lu le 2026-10-07)
  - [officielle] https://phpunit.de/announcements/phpunit-12.html et https://phpunit.de/announcements/phpunit-13.html (lus le 2026-10-07)
  - [officielle] https://raw.githubusercontent.com/sebastianbergmann/phpunit/13.3.1/DEPRECATIONS.md et ChangeLog-13.3.md (tag 13.3.1, lus le 2026-10-07)
  - [officielle] https://symfony.com/doc/7.4/testing.html et https://symfony.com/doc/7.4/components/browser_kit.html (lus le 2026-10-07)
  - [officielle] code au tag v7.4.14 : `symfony/browser-kit` `AbstractBrowser.php`, `Response.php` ; `symfony/http-kernel` `HttpKernelBrowser.php` (branche 7.4, voir « À confirmer ») (lus le 2026-10-07)
- Questions couvertes : `#[DataProvider]` (fournisseur statique ?), `#[Test]` ; dépréciations/retraits 12 et 13 ; lire corps brut et en-têtes d'une réponse avec le client de test.

## À utiliser

### Attributs (espace `PHPUnit\Framework\Attributes`)
- `#[Test]` : sur une MÉTHODE, sans paramètre ; la méthode n'a pas besoin du préfixe `test`. [officielle] attributes.html
- `#[DataProvider(string $methodName, bool $validateArgumentCount = true, bool $skipWhenEmpty = false)]` : méthode, répétable ; désigne une méthode STATIQUE de la même classe. [officielle] attributes.html
- `#[DataProviderExternal(string $className, string $methodName, …)]` : méthode statique d'une autre classe. [officielle] attributes.html
- `#[TestWith(array $data[, string $name])]` : jeu de données en ligne. `#[TestDox(string $text)]` : classe ou méthode. [officielle] attributes.html
- Fournisseur de données : **`public` ET `static` OBLIGATOIRES**, nom ne commençant pas par `test` ; renvoie un itérable (tableau, `Traversable`/générateur) dont chaque élément est un tableau d'arguments ; clés chaîne = jeux nommés (`test@nom`). [officielle] writing-tests-for-phpunit.html
- `validateArgumentCount` vaut `true` par défaut : le nombre d'éléments de chaque jeu est contrôlé. [officielle] attributes.html

### Lire une réponse avec le client de test Symfony
- `$client->getResponse()` : réponse d'origine (dans `WebTestCase`/`KernelBrowser`, la `Response` HttpFoundation) → `->getContent()` (corps brut), `->headers->get('content-type')`. [officielle] testing.html
- `$client->getInternalResponse()` : `Symfony\Component\BrowserKit\Response` → `getContent(): string`, `getStatusCode(): int`, `getHeaders(): array`, `getHeader(string $header, bool $first = true): string|array|null` (insensible à la casse), `toArray()` (JSON). [officielle] AbstractBrowser.php + Response.php@v7.4.14
- Réponses diffusées (`StreamedResponse`, `BinaryFileResponse`) : le client capture la sortie de `sendContent()` par tampon et la place dans la réponse BrowserKit → lire le corps par `getInternalResponse()->getContent()`. [officielle] HttpKernelBrowser.php (branche 7.4)
- Envoyer un en-tête : 5e argument `server` de `request()`, nom en MAJUSCULES, `-` → `_`, préfixe `HTTP_` (ex. `X-Session-Token` → `HTTP_X_SESSION_TOKEN`). `request(string $method, string $uri, array $parameters = [], array $files = [], array $server = [], ?string $content = null, bool $changeHistory = true)`. [officielle] testing.html + AbstractBrowser.php@v7.4.14
- Assertions : `assertResponseIsSuccessful()`, `assertResponseStatusCodeSame()`, `assertResponseHasHeader()`, `assertResponseHeaderSame()`, `assertResponseRedirects()`… (via `WebTestCase`). [officielle] testing.html

## Obsolète ou retiré dans cette version — ne pas utiliser

| Ancien | Remplacé par | Depuis | Source |
|---|---|---|---|
| annotations docblock (`@test`, `@dataProvider`, `@covers`…) | attributs PHP | RETIRÉES en 12 | [officielle] annonce PHPUnit 12 |
| attentes configurées sur un `createStub()` | `createMock()` + `expects()` | retiré en 12 | [officielle] annonce PHPUnit 12 |
| mocks de classes abstraites / traits (méthodes dédiées) | aucun | retiré en 12 | [officielle] annonce PHPUnit 12 |
| `withConsecutive()` | `withParameterSetsInOrder()` / `withParameterSetsInAnyOrder()` | 13 (nouveaux) | [officielle] annonce PHPUnit 13 |
| `TestCase::any()` | stub (`createStub()`) ou `once()`/`exactly()` | dépréciation dure 12.5.5 | [officielle] DEPRECATIONS.md@13.3.1 |
| `with*()` sans `expects()` | configurer `expects()` ou utiliser un stub | dure, 13.0.2 | idem |
| `atLeast()` avec argument non positif | argument positif | dure, 13.0.2 | idem |
| `expectExceptionMessage()` | `expectExceptionMessageIsOrContains()` | douce, 13.2.0 | idem |
| `id()` / `after()` sur attentes de mock | non précisé | douce, 13.1.0 | idem |
| `--cache-result` / `--do-not-cache-result`, XML `cacheResult` | `--record-test-run-history` / `--do-not-record-test-run-history`, `recordTestRunHistory` | dure, 13.3.0 | idem |
| `--order-by duration` / `size`, XML `executionOrder="duration"`/`"size"` | `duration-ascending` / `size-ascending` | dure, 13.2.0 | idem |
| `BrowserKit AbstractBrowser::useHtml5Parser()` | parseur HTML5 natif inconditionnel en Symfony 8 | 7.4 | [officielle] AbstractBrowser.php@v7.4.14 |

## Pièges connus
- PHPUnit 13 exige **PHP 8.4 ou plus**. [officielle] annonce PHPUnit 13
- Tout ce qui était en dépréciation douce en 12 est dure en 13 ; tout ce qui était dure en 12 est retiré en 13. [officielle] annonce PHPUnit 13
- `expectExceptionMessage()` est d'usage courant : il fonctionne encore en 13.3 mais émet une dépréciation douce. [officielle] DEPRECATIONS.md@13.3.1
- Fournisseur non statique ou non public : non admis (exigence explicite) — le comportement exact en cas d'erreur n'est pas décrit sur la page.
- `getResponse()` est typé `object` dans BrowserKit (gabarit `TResponse`) : son type réel dépend du client ; `getInternalResponse()` a toujours l'API BrowserKit. [officielle] AbstractBrowser.php@v7.4.14
- 13.3.1 : les crochets statiques (`setUpBeforeClass()`…) ne déclenchent plus de dépréciation sous PHP 8.6. [officielle] ChangeLog-13.3.md

## À confirmer par le plan
- Version installée de `symfony/http-kernel` (non fournie) : le comportement de capture des réponses diffusées a été lu sur la branche 7.4, pas sur un tag figé.
- `getResponse()->getContent()` sur une `StreamedResponse` (valeur renvoyée par HttpFoundation) : non lu → À VÉRIFIER ; utiliser `getInternalResponse()->getContent()`.

## Écarts entre sources
- La page browser_kit.html montre `getHeaders()`/`getHeader()`/`toArray()` sur `$browser->getResponse()` avec `HttpBrowser` ; ces méthodes sont celles de `BrowserKit\Response`. Avec `KernelBrowser` (tests Symfony), `getResponse()` renvoie la réponse HttpFoundation (`->headers->get()`). Le code v7.4.14 fait foi : pour l'API BrowserKit, passer par `getInternalResponse()`.
