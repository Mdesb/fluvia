# Plan — stripe-signature-webhook

**Spec :** `features/stripe-signature-webhook/specs/spec-stripe-signature-webhook.md` (validée CP-1, 2026-11-21)
**Branche :** `feature/stripe-signature-webhook` — lot D5-B de Mdesb/fluvia#365
**Zone sensible :** oui (paiements, route publique) → `relecteur` + `security-reviewer` en mode adversarial avant le commit.

En clair : aujourd'hui n'importe qui peut appeler la route de notification Stripe. Le plan ajoute une porte
à l'entrée : la notification n'est lue que si elle porte une signature Stripe valide et récente. Sans elle,
réponse 400 identique, avant toute lecture en base et tout appel à Stripe. PayFiP ne change pas.

## 1. Décisions

**D-1 — Où vérifier : interface optionnelle `SignedNotificationInterface`.**
Nouvelle interface `App\Boutique\Paiement\SignedNotificationInterface` avec une seule méthode
`verifyNotification(Request $request): void`, qui lève `InvalidNotificationSignatureException` (motif porté
par l'enum `NotificationRejection` : `Missing`, `Malformed`, `Invalid`, `OutOfWindow`, `NotConfigured`).
`StripeCheckoutAdapter` l'implémente ; `PayFipOnlinePaymentAdapter` non. Le contrôleur fait :
`if ($adaptateur instanceof SignedNotificationInterface) { try { verify } catch → 400 {statut:'refuse'} }`
**avant** `referenceFromNotification()` et avant tout `$this->em`. Il ne connaît ni Stripe ni HMAC.
Pourquoi pas dans `referenceFromNotification` : sa signature `?string` et son rôle (extraire une référence)
resteraient mêlés à une décision de sécurité, et « référence absente » (404 inconnu) se confondrait avec
« signature refusée » (400) ; une interface séparée suit le patron existant `ConfigurableOnlinePaymentInterface`
(`SelecteurPaiementEnLigne.php:50`, `:73-75`) : capacité optionnelle testée par `instanceof`. PayFiP est
inchangé par construction (G-6).

**D-2 — Journalisation.** Le contrôleur journalise un `warning` « Notification de paiement refusée (signature) »
avec `prestataire` et `motif` (valeur de l'enum) seulement : ni corps, ni en-tête (G-3). Pour `NotConfigured`,
message distinct « secret de webhook Stripe non configuré » (G-4). L'adaptateur ne journalise rien de
secret ; l'exception ne contient que l'enum (G-8). Réponse identique dans tous les cas : 400 `{statut:'refuse'}`.

**D-3 — Horloge : pas de dépendance, un vérificateur pur (CORRIGÉ en CP-2).** Pas d'horloge `symfony/clock`.
Aucune horloge n'est utilisée dans le code (mesuré). `symfony/clock` n'y est présente que comme dépendance
transitive, et la déclarer toucherait `composer.json` et `composer.lock` pour un seul usage. À la place, une
classe pure `App\Boutique\Paiement\Stripe\StripeSignatureVerifier` (finale, sans état, sans E/S) :
`verify(string $header, string $body, string $secret, int $now, int $tolerance = 300): void`.
Elle lève l'exception du D-2. L'adaptateur l'appelle avec `\time()`. La fenêtre G-2 se teste à l'unité sur
le vérificateur, avec un `$now` explicite, sans aucun mock.

**D-4 — Lecture de l'env : même mécanisme que la clé.** `#[Autowire(env: 'default::STRIPE_WEBHOOK_SECRET')] ?string $webhookSecret = null`
(`StripeCheckoutAdapter.php:54`). Le préfixe `default::` rend la variable facultative : **aucun marqueur
requis dans `app/.env`**. `STRIPE_SECRET_KEY` et `STRIPE_API_BASE` n'apparaissent pas dans les fichiers suivis
hors `infra/README.md` (grep du dépôt, fichiers non cachés) ; `app/.env` m'est illisible (refus d'accès) : à
confirmer à l'étape 2 par le développeur ; si les autres variables Stripe y ont un marqueur vide, ajouter
`STRIPE_WEBHOOK_SECRET=` de la même façon, sinon ne rien ajouter. Nouveaux paramètres ajoutés **en fin** de
constructeur (`?string $webhookSecret = null` seulement) :
les appels existants (`StripeCheckoutAdapterTest.php:35`, `:117`) compilent sans changement.

**D-5 — Algorithme.** Comparaison par `hash_equals` (G-8), HMAC-SHA256, plusieurs `v1` acceptées (G-1),
fenêtre ±300 s en valeur absolue (G-2), corps brut via `$request->getContent()`.
**[DÉPEND DE LA FICHE `docs/references/stripe-webhooks.md`]** : format exact de l'en-tête (séparateurs, clés
`t=`/`v1=`, tolérance aux `v0` et aux clés inconnues), construction exacte du message signé, encodage de la
clé (le `whsec_…` entier, ou une partie décodée ?), comportement attendu si plusieurs `t`. Rien de cela n'est
fixé ici ; les étapes 3-4 ne démarrent pas sans la fiche. Pas de SDK (hors périmètre).

**D-6 — Secret absent (G-4).** Vérification `NotConfigured` **avant** de lire l'en-tête : refus, `isConfigured()`
inchangé (dépend de la seule clé secrète, `StripeCheckoutAdapter.php:66-69`), donc la carte reste proposée.

**D-7 — Doublure pour le test d'API.** Même patron que `FakePayFipServer` (`services.yaml:487-494`, bloc
`when@test`) : `App\Tests\Boutique\Support\FakeStripeServer` (callable `MockHttpClient`, état statique,
compte les appels reçus) injectée comme `$httpClient` de `StripeCheckoutAdapter` en `when@test`. Elle est
inerte tant qu'aucun test ne pose `STRIPE_SECRET_KEY`. L'horloge de l'API n'est pas simulée : le test
signe avec l'heure réelle ; la fenêtre G-2 se teste à l'unité sur `StripeSignatureVerifier` (`$now` explicite).

**D-8 — Faux secrets.** Générés à l'exécution (`'whsec_' . bin2hex(random_bytes(16))` ; la clé
`'sk_test_' . 'FAUSSE…'` suit l'usage de `StripeCheckoutAdapterTest.php:24`), jamais littéraux.
Aucune valeur, même fausse, dans `infra/README.md` (placeholders `whsec_…` du style `<secret>`, garde-fou
de secrets à vérifier).

## 2. Étapes (test d'abord)

| # | Étape | Fichiers | Tests à écrire d'abord | Critère de sortie | Goals |
|---|---|---|---|---|---|
| 1 | *(supprimée en CP-2 : voir D-3, aucune dépendance ajoutée)* | — | — | — | — |
| 2 | Contrat : interface, exception, enum de motifs | `Paiement/SignedNotificationInterface.php`, `Paiement/InvalidNotificationSignatureException.php`, `Paiement/NotificationRejection.php` | aucun comportement à tester ; couverts par 3-5 | build + lint verts, garde-fous verts ; vérif de `app/.env` (D-4) | G-3, G-8 |
| 3 | Adaptateur : vérification de signature | `Stripe/StripeCheckoutAdapter.php` (implémente l'interface, 2 params de constructeur) | `StripeCheckoutAdapterTest` : signature valide → pas d'exception ; corps modifié d'1 octet → `Invalid` ; deux `v1` dont une bonne → ok ; en-tête absent → `Missing` ; mal formé → `Malformed` ; `t` à −301 s → `OutOfWindow`, −299 s → ok, +301 s → `OutOfWindow` (`$now` explicite sur `StripeSignatureVerifier`, testé à part dans `StripeSignatureVerifierTest`) ; secret vide → `NotConfigured` même avec en-tête valide ; logger espion : le secret, l'en-tête et le corps n'apparaissent dans aucun message/contexte/message d'exception (G-8) ; `isConfigured()` vrai sans secret de webhook. **Format exact : d'après la fiche** | tests unitaires Stripe verts, `testLaNotificationNeDonneQuUneReference` et autres tests existants inchangés et verts | G-1, G-2, G-4, G-8 |
| 4 | Contrôleur : garde avant tout accès | `Controller/OnlinePaymentNotificationController.php` (insertion avant l.49, import de l'interface, `warning` D-2) | via étape 5 (le contrôleur n'a pas de test unitaire dans le dépôt) | build ok ; `PayFipOnlinePaymentTest` vert **sans modification** | G-3, G-4, G-6 |
| 5 | Test d'API Stripe de bout en bout | `app/tests/Boutique/Api/StripeOnlinePaymentTest.php` (nouveau, modèle `PayFipOnlinePaymentTest.php`), `app/tests/Boutique/Support/FakeStripeServer.php`, `app/config/services.yaml` (bloc `when@test`) | Cas : (a) notification signée + session `paid` → 200 `confirme`, vente validée, 1 règlement (G-5) ; (b) même corps modifié → 400 `{statut:'refuse'}` ; (c) deux `v1` dont une bonne → 200 ; (d) référence `cs_` existante vs inexistante avec signature invalide → **même 400, même corps** ; (e) pour (d) : zéro appel à `FakeStripeServer` ET zéro requête SQL (compteur de requêtes DBAL/Doctrine debug stack du conteneur de test, avec témoin positif : une notification signée valide en compte ≥ 1) ; (f) clé posée sans secret → 400, `isConfigured()` vrai (la carte est proposée à `/payer`) ; (g) le motif journalisé distingue « non configuré » ; (h) signature valide mais corps non-`checkout.session.*` → 404 comme aujourd'hui | suite `Boutique` verte, cas (e) prouvé par témoin positif | G-1, G-3, G-4, G-5 |
| 6 | Adapter les tests Stripe existants pour signer | `StripeCheckoutAdapterTest.php` (seulement si un test appelle l'ancien parcours notification) | — | G-5 : aucun test existant affaibli, seulement signé | G-5 |
| 7 | Documentation d'exploitation | `infra/README.md` l.333-338 (+ une ligne `STRIPE_WEBHOOK_SECRET` dans le tableau) | garde-fou de secrets (`./bin/garde-fous.sh`) | `./bin/garde-fous.sh` vert ; aucune valeur, même factice | G-7 |

Remarques d'ordre : 2 avant 3 (3 implémente l'interface) ; 3 avant 4 (le contrôleur appelle `verifyNotification`) ;
étapes 3 et 4 ne compilent qu'ensemble pour le test d'API, mais chacune compile seule (une interface
non encore appelée est valide). L'étape 6 est fusionnable dans 3 si le diff reste petit. Les tests du
settlement (`OnlinePaymentSettlement`) ne passent pas par la signature : inchangés.

Contenu de l'étape 7 : (1) poser `STRIPE_WEBHOOK_SECRET` dans `app/.env.local` puis redémarrer PHP-FPM ; (2)
où le trouver : tableau de bord Stripe, Développeurs → Webhooks → le point de terminaison →
« Secret de signature » (**intitulé exact à reprendre de la fiche**, pas de mémoire) ; (3) test : envoi d'un
événement de test signé depuis le tableau de bord, attendu 200 (**procédure d'après la fiche**) ; (4) s'il manque :
toute notification est refusée en 400, avertissement « secret de webhook Stripe non configuré », la carte reste
proposée et la confirmation se fait au retour de l'acheteur (`RetourPaiementProcessor.php:58`) ; (5)
mention de la rotation de secret (plusieurs `v1`).

## 3. Tests — synthèse

- Unitaires (`App\Tests\Boutique\Unit`) : vérification pure, horloge figée, logger espion.
- API (`App\Tests\Boutique\Api\StripeOnlinePaymentTest`) : parcours HTTP réel, doublure Stripe, preuve « aucun SQL ni appel HTTP ».
- Non-régression : `PayFipOnlinePaymentTest` et `StripeCheckoutAdapterTest` (hors étape 6) non modifiés.
- À lancer par le développeur : `./infra/test-stack.sh run <jeton> app/tests/Boutique` dans un worktree, puis `./bin/garde-fous.sh` (la CI ne lance pas PHPUnit). `git checkout -- app/config/reference.php` avant commit.

## 4. Couverture

| Goal | Étapes |
|---|---|
| G-1 signature valide / plusieurs `v1` / corps brut | 3, 5 |
| G-2 fenêtre 300 s, horloge injectable | 1, 3 |
| G-3 refus 400 générique avant SQL/HTTP, avertissement | 2, 3, 4, 5 |
| G-4 secret absent, clé présente | 3, 4, 5, 7 |
| G-5 parcours signé inchangé | 5, 6 |
| G-6 PayFiP inchangé | 4 (par construction), 5 (non-régression) |
| G-7 documentation | 7 |
| G-8 `hash_equals`, secret absent des traces | 2, 3 |

Aucune étape ne couvre un objectif hors spec.

## 5. Fiches à produire

| Fiche | Version | Question précise |
|---|---|---|
| `docs/references/stripe-webhooks.md` (**à produire à part**, docs.stripe.com uniquement) | API Stripe sans SDK, version d'API fixée par le compte (à noter dans la fiche) | Format exact de `Stripe-Signature` (clés `t`, `v1`, `v0`, séparateurs, ordre, doublons) ; construction du message signé ; algorithme et encodage de la clé (`whsec_` entier ?) ; tolérance par défaut (300 s ?) et sens de l'écart ; procédure de rotation ; emplacement du secret dans le tableau de bord ; envoi d'un événement de test signé |

Fiches déjà valides et à citer sans les redemander : `php-8.4.md`, `phpunit-13.3.md`, `doctrine-dbal-4.4.md`
(compteur de requêtes de l'étape 5, à confirmer muet ou non : question « comment compter les requêtes dans
un test d'API » ; sinon technique du dépôt). Les points du plan qui dépendent de la fiche Stripe : D-5, étapes
3 et 7 (marqués **[fiche]**).

## 6. Risques

| Risque | Parade |
|---|---|
| Le proxy nginx de la préprod modifie le corps (UNVERIFIED du spec) → toutes les notifications seraient refusées | Le corps brut vient de `getContent()` ; témoin à la mise en service : événement de test signé → 200. Sans cela, G-4/retour acheteur continuent de confirmer. |
| Horloge du VPS désynchronisée → rejets en `OutOfWindow` | Motif journalisé distinct ; documenté en étape 7 |
| Fuite du secret par exception/journal | Exception sans message libre, test espion (étape 3) |
| Détection des requêtes SQL fragile (étape 5e) | Témoin positif obligatoire ; sinon espionner `EntityManager` par un décorateur de test |
| Le contrôleur regarde `Request::getContent()` après lecture par un autre composant | Sans impact : `getContent()` est rejouable |
| Garde-fou de secrets déclenché par un faux `whsec_` | Secrets assemblés à l'exécution (D-8) |

## 7. Estimation (garde-fou 400 lignes par commit)

| Étape | Lignes ajoutées environ |
|---|---|
| 1 | ~5 (+ test jetable) |
| 2 | ~70 |
| 3 | ~90 code + ~190 tests |
| 4 | ~30 |
| 5 | ~250 test + ~60 doublure + ~8 YAML |
| 6 | ~10 |
| 7 | ~25 doc |

Découpage en 3 commits sous 400 lignes de code : **(A)** étapes 1-4 (~195 lignes code + tests 3 : ~190, à
scinder si dépassement ; le garde-fou compte le code), **(B)** étapes 5-6 (~320), **(C)** étape 7. Les revues
`relecteur` et `security-reviewer` (revue consignée) précèdent le commit A.

## 8. Corrections CP-2 (orchestrateur, 2026-11-21)

- La fiche `docs/references/stripe-webhooks.md` est produite. Elle a été lue sur docs.stripe.com en version texte (`webhooks.md`), et les citations y sont verbatim.
- **D-5 tranché par la fiche :**
  - L'en-tête se découpe sur `,` puis sur `=`. On garde `t` et chaque `v1`, et on ignore tout le reste (`v0`, `secret`, `destination`, `payload`, clés inconnues), quels que soient leur ordre et leur nombre.
  - Le message signé est `t . '.' . corps brut`.
  - Le calcul est `hash_hmac('sha256', …, $secret)` en hexadécimal, avec pour clé le secret entier, préfixe `whsec_` compris. Ce point est *déduit* de l'exemple officiel et sera confirmé par le témoin en préprod.
  - Comparaison : `hash_equals` contre chaque `v1`. La fenêtre est vérifiée ensuite, en `abs(now - t) > 300` → refus.
  - En-tête sans `t` numérique ou sans aucun `v1` → `Malformed`. Plusieurs `t` → `Malformed` (choix prudent, la doc ne le prévoit pas).
- **D-3 :** horloge remplacée par le vérificateur pur. L'étape 1 est supprimée et la fiche `symfony-clock` n'a plus lieu d'être.
