# Stripe — clés d'API (secrètes `sk_`, restreintes `rk_`) pour Checkout côté serveur

- **Version :** sans objet. Pas de SDK : Fluvia appelle l'API REST par HttpClient avec `auth_bearer` (`StripeCheckoutAdapter`).
- **Sources :** https://docs.stripe.com/keys.md, https://docs.stripe.com/keys/restricted-api-keys.md, https://docs.stripe.com/keys-best-practices.md (versions texte de docs.stripe.com). Les pages ne portent pas de date.
- **Lue le :** 2026-11-21 · **Revoir avant le :** 2027-02-19
- **Usage :** lot D3-B de #365 (`features/stripe-cle-par-exploitant`).

## Types de clés (verbatim, `keys.md`)

- « Sandbox keys start with `pk_test_` for publishable keys, `rk_test_` for restricted keys, and `sk_test_` for secret keys. »
- Clé restreinte `rk_...` : « API key with permissions you control. »
- Clé secrète `sk_...` : « API key that has unrestricted permissions on all Stripe APIs. […] we don't recommend using secret keys for new use cases, and for existing integrations, we recommend migrating secret key usage to RAKs. »
- Clé d'organisation `sk_org_...` : « API key that works at the organization level. […] to manage multiple Stripe accounts at once. »
  - **Conséquence pour Fluvia :** une telle clé sert plusieurs comptes. C'est contraire au principe « un exploitant, un compte » (G-1/G-2 de D3-B). Elle est **refusée** à la saisie. La regex `^(sk|rk)_(test|live)_` l'exclut déjà, puisque `sk_org_` ne commence ni par `sk_test_` ni par `sk_live_`.

## Clé restreinte : suffit-elle pour Checkout ? (verbatim, `restricted-api-keys.md`)

- « A RAK starts with `rk_live_` or `rk_test_`. […] you select which Stripe resources the key can access and the permissions for each resource: **Read**, **Write**, or **None**. All Stripe APIs support restricted API keys. »
- « In your server environment, restricted API keys are drop-in replacements for secret API keys. To use a restricted API key, provide it to your code in the same way you provide your secret key »
- Correspondance des méthodes : « GET → read », « POST → write », « DELETE → write ». « Write permissions imply read permissions: any key that can write an API resource can also read that resource. »
- « If a restricted API key doesn't have the correct permissions to complete an API request, Stripe returns an invalid request error ».

**Conséquence pour Fluvia :** Fluvia fait deux appels, `POST /v1/checkout/sessions` (création) et `GET /v1/checkout/sessions/{id}` (vérification). Une `rk_` doit donc avoir la permission **Write** sur la ressource *Checkout Sessions*. La lecture est alors comprise. Une `rk_` sans ce droit fait échouer le paiement par une erreur Stripe, pas une fuite. Cette consigne va dans `infra/README.md`. L'intitulé exact de la ressource dans le tableau de bord n'est pas cité par la page.

## Garde des clés (verbatim, `keys.md`)

- « Only publishable keys are safe to expose outside your application's back end. You're responsible for protecting other Stripe API keys, including restricted API keys. »
- « When you create a secret key in live mode, Stripe displays it one time before you save it. […] We can't recover keys that you've forgotten or lost access to. »
- « If you expire a secret API key or a restricted API key, you must create a new one and update any code that uses the expired key. »

**Conséquence pour Fluvia :** Fluvia ne réaffiche jamais la clé (G-6). Pour la changer, l'exploitant en saisit une nouvelle, qui remplace l'ancienne.

## Mode test / live

Le préfixe (`_test_` / `_live_`) indique le mode. Fluvia le mémorise à la saisie pour l'afficher (G-6). Le secret de webhook (`whsec_`) ne porte pas de mode (voir `stripe-webhooks.md`).
