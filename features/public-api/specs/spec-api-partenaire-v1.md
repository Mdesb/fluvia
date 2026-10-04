# Spec — API partenaire `/v1`, lot 1 : clés réelles, accès en lecture, webhooks signés (#94), 04/10/2026

> Décisions de Maxime du 04/10 : la « spec pour IT Cotation » couvre **les deux** contrats (API partenaire
> `/v1` ET contrat terminal `/api/terminal`) ; premier lot = **ressources d'accès** ; la doc de l'API interne
> (`/api/docs`) reste ouverte. **CP-1 du 04/10 : lot complet** (clés + ressources + webhooks + docs), contre
> la recommandation de réduire au lot (a) ; les protections exigées par le contradicteur et la perspective
> exploitation sont intégrées ci-dessous (§3.5, §3.6). Cap D101/D102 (« l'API d'abord »), confirmé par D111 (14/09, branche `maxime`).

## 1. Point de départ (vérifié dans `origin/main` 578ae2c6)

- `app/src/PublicApi` existe depuis le 06/09 : `PartnerApplication` (sans établissement), `ApiCredential`
  (secret `flv_` + 64 hex, seule l'empreinte sha256 est stockée, préfixe 12 car., expiration, statut),
  `ApiGrant` (consentement d'UN établissement à UNE application, portées en JSON, révocation absorbante),
  `ApiScope` (6 portées, aucune ressource derrière). Pare-feu `public_api` sur `^/v1`, `ROLE_PARTNER`,
  `PublicApiAuthenticator` (même 401 pour tous les échecs). Un seul point d'entrée : `GET /v1/me`.
- **Aucun geste** ne crée d'application, n'émet ou ne révoque de clé, n'accorde ou ne retire de consentement :
  tout se fait en base. `lastUsedAt`, `revokedAt`, `revokedBy` ne sont jamais écrits ; rien n'est audité.
- **Risque de cloisonnement** : ~38 des 46 extensions Doctrine de périmètre laissent passer toute identité
  qui n'est pas un `Utilisateur`. Exposer une ressource API Platform existante sous `/v1` à un `PartnerUser`
  rendrait les données de TOUS les établissements.
- Webhooks (`app/src/Integrations`) : outil d'exploitant (Slack/Teams/Discord/générique), synchrone, sans
  réessai ni signature, sans lien avec les applications partenaires. Aucun worker Messenger en préprod (à
  confirmer). ~32 événements réellement émis ; `access.recorded` et `sale.completed` ne sont émis par personne.
- Aucune limite de débit sur `/v1`.

## 2. Principe de sécurité, non négociable

**Rien d'interne n'est exposé tel quel.** Chaque ressource `/v1` a son DTO (contrat versionné, pas les
groupes de sérialisation internes) et son provider dédié, qui filtre **explicitement** par
`PartnerUser::establishmentsFor(portée)` et **refuse par défaut** (aucun grant actif portant la portée →
liste vide ; identifiant hors périmètre → 404, jamais 403, pour ne pas confirmer son existence).
Un test croisé par ressource : partenaire autorisé sur A, données posées sur A et B → il ne voit que A ;
le même partenaire sans grant → rien ; témoin : avec grant sur B, il voit B.

## 3. Périmètre du lot 1

### 3.1 Rendre la clé réelle (côté éditeur et côté exploitant)
- **Éditeur** (écran de l'administration éditeur, `frontend/src/editeur`) : créer une application
  partenaire (nom, e-mail de contact) ; émettre une clé (secret affiché **une seule fois**, copier) ; révoquer
  une clé ; désactiver une application.
- **Exploitant** (paramètres de l'établissement, permission dédiée `api.gerer`) : voir les applications qui
  demandent l'accès, **accorder** des portées sur son établissement, **retirer** l'accord.
- **Traçabilité** : `JournalAudit` sur émission, révocation, accord, retrait (rattachés à l'établissement
  concerné ; ceux de l'éditeur à l'éditeur). `lastUsedAt` mis à jour au plus une fois par minute.
- **Limite de débit par clé** (`symfony/rate-limiter`, déjà installé) : 120 requêtes/minute par défaut ;
  429 + en-têtes `RateLimit-*`. **Stockage partagé et atomique** : pool de cache sur la base (adaptateur DBAL)
  et verrou `DoctrineDbalStore`, jamais APCu ni fichier (comptés par processus php-fpm, donc faux).

### 3.2 Ressources en lecture (portée `access:read`)
- `GET /v1/me` (existe).
- `GET /v1/access-rights` : droits d'accès valides — **référence opaque du support** (HMAC du support propre
  à chaque application, jamais le code du QR ou de la carte : une clé `access:read` fuitée ne doit pas
  permettre de cloner les badges ; le code réel n'est servi qu'au terminal lié à son site), zones ouvertes
  (chaîne réelle D87 : zone vide = aucune porte), début/fin de validité, statut (actif, suspendu, révoqué,
  expiré), établissement. Aucune donnée nominative.
- `GET /v1/access-events` : passages enregistrés — référence opaque du support, zone/point d'accès,
  horodatage, résultat (accordé/refusé + motif codé), établissement. **Donnée personnelle pseudonyme**
  (traces de lieu horodatées) : fenêtre servie limitée à 90 jours, mention dans le document d'intégration
  (l'établissement, responsable de traitement, consent l'accès par portée).
- Filtres : `establishment`, `updatedSince` (synchronisation incrémentale) ; pagination par curseur.
- `bookings:write` est retiré de `ApiScope` tant qu'aucune ressource ne le porte (règle écrite dans l'enum).

### 3.3 Webhooks partenaire (portée `events:subscribe`)
- Une application déclare une URL https et la liste des événements voulus ; seuls sont livrés les événements
  des établissements qui lui ont accordé `events:subscribe`.
- Lot 1 : **événements déjà émis** et utiles à un fournisseur d'accès — `access.card_recharged`,
  `booking.cancelled`, `booking.no_show`, `payment.failed`, `payment.succeeded` ; plus **`access.right_granted`,
  `access.right_revoked` et `access.right_expired`** à émettre (l'expiration par le temps n'émet rien
  aujourd'hui : une commande quotidienne la détecte, **inscrite à la liste blanche de l'ordonnanceur**).
- Enveloppe versionnée `{id, type, version, occurredAt, establishmentId, data}` ; signature HMAC-SHA256 de
  `t.corps` avec un secret par abonnement (`Fluvia-Signature: t=…,v1=…`, rejet conseillé au-delà de 5 min),
  identifiant d'idempotence. **Le secret est chiffré au repos** (libsodium, clé dédiée
  `PARTNER_WEBHOOK_KEY`, comme l'URL des connecteurs), affiché une fois, régénérable.
- **Le consentement est revérifié à chaque livraison et à chaque réessai** : un grant retiré arrête la livraison.
- Livraison en file (Messenger, transport `async` existant, 5 réessais exponentiels) ; **un worker à
  déployer** en préprod. Échec définitif visible côté éditeur.
- Destination : https obligatoire ; résolution DNS, refus des adresses privées, de bouclage, de lien local et
  réservées, puis **connexion épinglée sur l'IP vérifiée** (pas de seconde résolution : anti-rebinding) ;
  **aucune redirection suivie** ; délais courts (DNS 2 s, connexion 3 s, réponse 5 s).

### 3.4 Documentation pour IT Cotation
- OpenAPI dédiée à `/v1` (séparée de `/api/docs`), servie sur `/v1/docs`.
- Un **document d'intégration** lisible (Markdown) : authentification, portées, consentement par
  établissement, ressources avec exemples `curl`, pagination, limites, format et vérification des webhooks —
  et, dans le même document, le **contrat terminal** (`/api/terminal`, spec `specs/acces-terminal`) avec la
  liste fermée des points à confirmer par IT Cotation (format du jeton, granularité porte/ITBOX, taille du
  snapshot, seuil d'horloge, delta, messages borne…).

### 3.5 Exploitation (perspective exploitation, CP-1)
- **Worker** : conteneur dédié `messenger:consume async --time-limit=3600 --memory-limit=256M`,
  `restart: always`, healthcheck ; arrêt propre (SIGTERM, le message en cours finit) ; 2 consommateurs.
- **Interrupteur** `PARTNER_WEBHOOKS_ENABLED` (fermé par défaut) : coupe la livraison sans retirer le code ;
  les messages non consommés restent en base et repartent à la réouverture (vérifié par test).
- **Alerte minimale** : commande planifiée (liste blanche) qui signale les messages en attente depuis plus de
  15 minutes et les échecs définitifs ; visible côté éditeur.
- **Conservation** : `lastUsedAt` n'est pas audité ; journal d'audit inchangé ; passages servis sur 90 jours.

### 3.6 Sécurité (contradicteur, CP-1)
- Référence opaque des supports (§3.2) ; secret HMAC chiffré ; anti-SSRF épinglé ; consentement revérifié à la
  livraison ; événement d'expiration (§3.3).

## 4. Hors lot

OAuth, SDK, portail développeur, écriture (`bookings:write`), clients (`customers:read`, après avis RGPD),
ventes, hôte dédié `api.fluvia-app.com` (D103), raccordement réel ITBOX (bloqueur E-4), fermeture de
`/api/docs` (décision du 04/10 : reste ouverte).

## 5. Critères d'acceptation

1. Une clé s'émet, s'utilise, se révoque sans toucher à la base ; le secret n'est affiché qu'une fois et
   n'est jamais relisible ; chaque geste est audité.
2. Un exploitant accorde puis retire `access:read` : la clé passe de « voit A » à « ne voit rien », immédiatement.
3. Test croisé A/B/sans grant/témoin pour chaque ressource `/v1` (refus par défaut prouvé par sabotage :
   retirer le filtre rend le test rouge en nommant la ressource).
4. 121ᵉ requête de la minute → 429, y compris réparties sur plusieurs processus php-fpm.
4b. Aucune réponse `/v1` ne contient le code d'un QR ou d'une carte (test qui cherche le code réel dans le corps).
5. Un webhook arrive signé ; une signature altérée est détectable ; un abonné en panne reçoit les réessais ; une
   URL vers `10.0.0.1`, `127.0.0.1`, `169.254.169.254` ou un nom qui y résout est refusée ; une redirection
   n'est pas suivie ; un grant retiré entre deux réessais arrête la livraison ; un droit expiré émet
   `access.right_expired`.
6. `/v1/docs` ne décrit que `/v1` ; le document d'intégration contient un exemple qui fonctionne contre la préprod.

## 6. Taille annoncée

Quatre PR successives, chacune sous le budget : (a) gestion des clés + audit + limite (~300 lignes),
(b) deux ressources + tests croisés (~350), (c) webhooks signés en file + anti-SSRF + worker + alerte (~400),
(d) OpenAPI `/v1` + document d'intégration IT Cotation (texte). Relecture adversariale sur (a), (b), (c).
