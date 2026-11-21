# Stripe — vérification de la signature des webhooks (`Stripe-Signature`)

- **Version :** sans objet. Fluvia n'installe pas le SDK stripe-php : ses appels passent par HttpClient (`app/composer.lock` ne contient aucune entrée stripe). Le schéma de signature `v1` ne dépend pas de la version d'API (la page ne donne aucune version).
- **Source :** https://docs.stripe.com/webhooks.md (version texte de https://docs.stripe.com/webhooks), sections « Verify webhook signatures manually », « Preventing replay attacks », « Quickly return a 2xx response », « Automatic retries », « Roll endpoint secrets ». La page ne porte pas de date de mise à jour.
- **Lue le :** 2026-11-21 · **Revoir avant le :** 2027-02-19
- **Usage :** lot D5-B de #365 (`features/stripe-signature-webhook`).

## Format de l'en-tête (verbatim)

> The `Stripe-Signature` header sent with each event contains comma-separated entries, including a timestamp and one or more signatures that you must verify. […] the header you receive is on a single line

Exemple officiel (sur une seule ligne en réalité) : `t=1798765432,v1=5257a869…d8bd,v1=38c9f77a…b2c2,v0=6ffbb59b…9a39,secret=whsec_****…PzVt,destination=we_…,payload=a25aefc6`

- `t` : « The timestamp when this event was signed by Stripe, in seconds since the Unix epoch. […] Use this value only for signature verification. »
- `v1` : « A signature generated with a hash-based message authentication code (HMAC) with SHA-256. […] It might appear multiple times. Verify that at least one `v1` entry matches the expected signature. »
- `v0` : « A signature value included only for events related to a sandbox account. This isn't valid for signature verification. Your code must ignore entries of this type. »
- `secret`, `destination`, `payload` : métadonnées de débogage, « aren't covered by the HMAC. Don't use them to authenticate a request ».
- « Your integration must accept unknown entries and must not depend on a fixed entry count or order. »
- « To prevent downgrade attacks, ignore signature schemes other than `v1`, and use only the `t` and `v1` entries for verification. »

## Algorithme (verbatim, étapes 1 à 4)

1. « Split the header using the `,` character as the separator to get a list of elements. Then split each element using the `=` character as the separator to get a prefix and value pair. […] Keep the timestamp and every `v1` value. Discard all other elements »
2. `signed_payload` = « The timestamp value (as a string) » + « The character `.` » + « The actual JSON payload (that is, the unmodified HTTP request body) ». « Don't format or modify the payload in any way before concatenation. »
3. « Compute an HMAC with the SHA-256 hash function. Use an active endpoint signing secret as the key, and use the `signed_payload` string as the message. »
4. « Use a constant-time string comparison to compare the expected signature to every `v1` signature in the header. If any signature matches, compute the difference between the current timestamp and the received timestamp, and reject the request if the difference is outside your tolerance. If no signatures match, reject the request. »

### Points que la page ne dit pas en toutes lettres (DÉDUITS, à confirmer par le témoin)

- **Encodage de la signature : hexadécimal minuscule.** Ce n'est pas écrit, mais tous les `v1` de l'exemple font 64 caractères `[0-9a-f]`, soit les 32 octets d'un SHA-256 en hexadécimal. En PHP : `hash_hmac('sha256', $signedPayload, $secret)` (sortie hexadécimale par défaut, fiche `php-8.4.md`), comparée par `hash_equals`.
- **Clé : la chaîne entière du secret, préfixe `whsec_` compris**, telle que le tableau de bord la donne (« Click **Reveal secret** and copy the value to use when you create a handler »). Les exemples de handler la passent telle quelle (`endpoint_secret = 'whsec_...'`).
- **Témoin qui lève ces deux déductions :** un événement de test signé, envoyé depuis le tableau de bord Stripe vers la préprod, doit rendre 200. Une erreur d'encodage ou de clé donnerait 400 à coup sûr.

## Tolérance et rejeu (verbatim)

- « Our libraries have a default tolerance of 5 minutes between the timestamp and the current time. »
- « Don't use a tolerance value of `0`. Using a tolerance value of `0` disables the recency check entirely. »
- « Use Network Time Protocol (NTP) to make sure that your server's clock is accurate »
- « If Stripe retries an event […] then we generate a new signature and timestamp for the new delivery attempt. »

## Corps brut (verbatim)

> Stripe requires the raw body of the request to perform signature verification. If you're using a framework, make sure it doesn't manipulate the raw body. Any manipulation to the raw body of the request causes the verification to fail.

Côté Symfony, `Request::getContent()` rend le corps brut. Fluvia l'utilise déjà (`StripeCheckoutAdapter.php:80`).

## Rotation du secret (verbatim)

> You can choose to immediately expire the current secret or delay its expiration for up to 7 days […] During this time, multiple secrets are active for the endpoint. Stripe generates one signature per secret until expiration.

Avec un seul secret configuré chez Fluvia, une `v1` valide parmi plusieurs suffit (étape 4). Pendant la rotation, on pose le nouveau secret puis on laisse l'ancien expirer.

## Réponse et nouvelles tentatives (verbatim)

- « Your endpoint must quickly return a successful status code (`2xx`) before any complex logic that could cause a timeout. »
- « Stripe attempts to deliver events to your destination for up to three days with an exponential back off in live mode. […] We retry event deliveries created in a sandbox three times over the course of a few hours. »

Conséquence pour Fluvia : un refus de signature (400) est retenté par Stripe pendant trois jours au plus. C'est sans danger : chaque tentative est re-signée et la confirmation reste idempotente.
