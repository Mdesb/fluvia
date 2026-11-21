# Spec — stripe-signature-webhook

**Statut :** validée (CP-1, Maxime, 2026-11-21) <!-- brouillon → en revue → validée (CP-1) -->
**Auteur :** Claude (session cloud), pour Maxime
**Date :** 2026-11-21
**Issue :** lot D5-B de Mdesb/fluvia#365 (décision D5 → B, commentaire d'intégration de la PR #355)

## Contexte & problème

Aujourd'hui, la notification Stripe n'est pas authentifiée :

- **VERIFIED** — La route `/api/boutique/paiement-en-ligne/notification/{provider}` est publique (`OnlinePaymentNotificationController.php:41`, aucune règle dans `security.yaml`).
- **VERIFIED** — Pour Stripe, la route ne lit que `type` (`checkout.session.*`) et `data.object.id` (`cs_…`) dans le corps JSON (`StripeCheckoutAdapter.php:77-91`), puis redemande la session à l'API Stripe avec la clé secrète (`StripeCheckoutAdapter.php:123-181`).
- **VERIFIED** — Une notification forgée ne peut donc pas confirmer une commande. Elle peut quand même :
  - faire interroger l'API Stripe, au plus une fois toutes les 5 s par commande (throttle de `OnlinePaymentSettlement`) ;
  - servir à tester si une référence `cs_` existe : la réponse est 404 « inconnu » ou 200.
- **VERIFIED** — Aucun secret de webhook (`whsec_`) n'existe dans le dépôt ni dans la documentation d'exploitation (`infra/README.md:318-338`).
- **VERIFIED** — Fluvia n'utilise pas le SDK Stripe : les appels passent par HttpClient. La vérification HMAC sera donc écrite à la main.
- **VERIFIED** — PayFiP passe par le même contrôleur et ses notifications n'ont pas de signature (`PayFipOnlinePaymentTest.php`). PayFiP ne doit pas changer.
- **VERIFIED** — Le retour de l'acheteur sur la boutique confirme aussi le paiement, indépendamment de la notification (`RetourPaiementProcessor.php:58`).

La décision D5-B ajoute une **défense en profondeur** : seule une notification réellement émise par Stripe est traitée.

## Objectifs (Goals)

- **G-1** — Pour Stripe, la notification n'est traitée que si son en-tête `Stripe-Signature` est valide pour le secret configuré (`STRIPE_WEBHOOK_SECRET`). La signature est calculée sur le **corps brut** de la requête, selon le format officiel Stripe : horodatage et une ou plusieurs signatures `v1`. Une seule `v1` valide suffit, ce qui couvre la rotation de secret.
- **G-2** — Une notification dont l'horodatage s'écarte de plus de **300 s** de l'heure du serveur est refusée (fenêtre anti-rejeu, valeur par défaut de Stripe).
- **G-3** — Une signature absente, mal formée, invalide ou hors fenêtre est refusée **avant toute lecture en base et tout appel à Stripe**. La réponse est un **400** au corps générique `{statut: 'refuse'}`, identique dans tous les cas, sans indiquer si la référence existe. Un avertissement est journalisé avec le motif (absente / mal formée / invalide / hors fenêtre), sans le corps ni l'en-tête complet.
- **G-4** — **Secret absent, clé présente** (décision de Maxime) : toute notification Stripe est refusée comme en G-3, et un avertissement explicite « secret de webhook Stripe non configuré » est journalisé. Le paiement par carte reste proposé. La confirmation se fait alors au retour de l'acheteur.
- **G-5** — Une notification correctement signée suit le parcours actuel, sans changement : référence, vérification par l'API, settlement idempotent. La signature n'est **pas** une preuve de paiement : l'issue vient toujours de l'API Stripe.
- **G-6** — PayFiP n'est pas modifié : même route, même comportement, ses tests restent verts sans changement.
- **G-7** — La documentation d'exploitation (`infra/README.md`, section Stripe) explique :
  - comment poser `STRIPE_WEBHOOK_SECRET` dans `app/.env.local` sur le VPS ;
  - où le trouver dans le tableau de bord Stripe ;
  - comment tester (envoi d'un événement de test signé) ;
  - ce qui se passe s'il manque.
  Le dépôt est public : aucune valeur réelle n'y figure, pas même de test.
- **G-8** — Comparaison des signatures en temps constant (`hash_equals`). Le secret n'apparaît dans aucun journal, aucune exception ni aucune trace.

## Hors périmètre

- Un secret par exploitant (lot D3-B, clé Stripe chiffrée par exploitant) : ici, un **seul secret global**, comme la clé actuelle.
- L'ajout du SDK stripe-php.
- Toute signature PayFiP : le protocole PayFiP n'en prévoit pas.
- L'enregistrement du webhook chez Stripe (geste d'exploitation, documenté en G-7).
- Les lots D2-B et D6-A de #365.

## Contraintes & décisions techniques connues

- **Fiche de référence obligatoire avant le code** : `docs/references/stripe-webhooks.md`, tirée de docs.stripe.com. Maxime a ouvert ce domaine le 21/11/2026 (voir `.claude/settings.json`). Le format exact de l'en-tête et le calcul de la signature se codent contre cette fiche, jamais de mémoire.
- La vérification est **propre à l'adaptateur Stripe**. L'emplacement exact (interface optionnelle ou garde dans l'adaptateur) se décide au plan. Le contrôleur ne doit pas connaître Stripe.
- Les tests fabriquent leur faux secret à l'exécution, pour ne pas déclencher le garde-fou de secrets.
- L'heure vient d'une horloge injectable, pour tester la fenêtre sans `sleep`.

## Points UNVERIFIED (bloquants pour CP-1)

- [x] Comportement sans secret → tranché par Maxime : refuser et signaler (G-4).
- [x] Source de la doc Stripe → tranché par Maxime : docs.stripe.com ouvert au documentaliste.
- [ ] *(non bloquant pour CP-1, bloquant pour le plan)* Format exact de `Stripe-Signature` et du message signé → la fiche les établira.
- [ ] *(non bloquant, à mesurer au déploiement)* Que le proxy nginx de la préprod transmet le corps sans le modifier, et que l'horloge du VPS est synchronisée. Témoin : un événement de test signé, envoyé depuis le tableau de bord Stripe, rend **404 « inconnu »** et non 400 : la signature est acceptée, mais la session de test ne correspond à aucune commande Fluvia. Un vrai paiement en mode test rend 200. *(Témoin corrigé le 21/11/2026, voir le journal de rétropropagation.)*

## Critères d'acceptation

- G-1 : un test d'API avec une signature calculée sur le corps rend 200. Le même corps modifié d'un octet rend 400. Un en-tête portant deux `v1`, dont une seule bonne, rend 200.
- G-2 : horodatage à −301 s → 400 ; à −299 s → 200 (horloge figée).
- G-3 : signature invalide sur une référence `cs_` existante comme inexistante → même 400, même corps. Un test prouve qu'aucune requête SQL ni aucun appel HTTP à Stripe n'a eu lieu (MockHttpClient sans réponse consommée).
- G-4 : clé posée sans secret → 400 et avertissement journalisé. `isConfigured()` reste vrai, donc le paiement par carte reste proposé.
- G-5 : les tests existants de `StripeCheckoutAdapterTest` et du settlement restent verts, adaptés seulement pour signer leurs requêtes.
- G-6 : `PayFipOnlinePaymentTest` est vert sans modification.
- G-7 : `infra/README.md` est à jour, et le garde-fou de secrets est vert.
- G-8 : test avec un logger espion : le secret n'apparaît dans aucun message ni contexte.
