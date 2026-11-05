# Pont de caisse Fluvia — terminal Caisse-AP et tiroir-caisse

Un petit service qui tourne **sur le poste de caisse** (Windows 11, ou Linux) :

- il parle **Caisse-AP sur IP** au terminal de paiement (TPE) du guichet ;
- il ouvre le **tiroir-caisse** par l'imprimante ticket (impulsion ESC/POS) ;
- il expose au navigateur de la caisse une **interface locale** sur `http://127.0.0.1:8765`.

Le serveur Fluvia ne joint pas le terminal : celui-ci est sur le réseau du site, derrière la box. C'est
le navigateur de la caisse qui demande le débit au pont, puis relaie au serveur le résultat **signé**
par le pont. Le navigateur transporte le résultat, il ne peut pas le modifier.

Aucune dépendance : Node.js 20 ou plus, rien d'autre (`npm install` n'est pas nécessaire).

```
navigateur (caisse Fluvia) ──HTTP 127.0.0.1:8765──▶ pont ──TCP Caisse-AP──▶ terminal (IP du site, port 8888)
        │                                            └──TCP 9100 / partage──▶ imprimante ticket ──▶ tiroir
        └──HTTPS──▶ serveur Fluvia : POST /api/ventes/{id}/issue-pont (résultat signé, vérifié)
```

## Installation sur le poste de caisse (Windows 11)

Prérequis : le terminal est **en mode Caisse-AP sur IP** (réglage fait par l'installateur du
terminal ou la banque) et a une **adresse IP fixe** sur le réseau du site. Notez son adresse et son
port (souvent `8888`).

1. Installez **Node.js 20 LTS** ou plus récent depuis nodejs.org (installateur Windows, options par
   défaut).
2. Copiez le dossier `bridge/caisse-ap` du dépôt dans `C:\FluviaPont`.
3. Ouvrez un terminal (touche Windows, tapez `cmd`) et lancez :
   ```
   cd C:\FluviaPont
   node bin\fluvia-bridge.js init
   ```
   Le pont crée `config.json` et sa clé de signature `bridge-key.pem`, puis **affiche sa clé publique**
   (une ligne d'environ 44 caractères). Gardez cette fenêtre ouverte.
4. Ouvrez `C:\FluviaPont\config.json` avec le Bloc-notes et renseignez :
   - `allowedOrigins` : l'adresse de votre Fluvia, **exactement** comme dans la barre d'adresse, sans
     barre finale (ex. `["https://piscines.exemple.fr"]`) ;
   - `terminal.host` et `terminal.port` : l'adresse IP et le port du terminal ;
   - `terminal.id` : un nom pour ce terminal (ex. `TPE-ACCUEIL`), repris sur les tickets ;
   - `drawer` : voir « Le tiroir-caisse » ci-dessous.
5. Dans Fluvia, **Paramètres › Points de vente**, ouvrez le point de vente de ce poste :
   - Terminal de paiement : **Relié (Caisse-AP)** ;
   - Clé du pont de caisse : collez la clé affichée à l'étape 3 (`node bin\fluvia-bridge.js key` la
     réaffiche) ;
   - cochez « Ouvrir le tiroir-caisse… » si un tiroir est branché.
6. Démarrage automatique, dans un terminal **administrateur** :
   ```
   schtasks /Create /TN "Pont de caisse Fluvia" /SC ONLOGON /RL LIMITED /TR "\"C:\Program Files\nodejs\node.exe\" C:\FluviaPont\bin\fluvia-bridge.js start"
   ```
   puis fermez la session Windows et rouvrez-la (ou lancez `node bin\fluvia-bridge.js start` à la main
   pour un essai).
7. Vérifiez : dans la caisse, vendez un article à 1,00 € par **Carte**. Le montant doit apparaître sur
   le terminal ; annulez sur le terminal (touche rouge) : la caisse affiche « Paiement carte annulé ».

Le navigateur doit être **Chrome ou Edge** récent : la page Fluvia (en HTTPS) appelle le poste
lui-même, et le pont l'y autorise par l'en-tête *Private Network Access*.

### Le tiroir-caisse

Le tiroir est branché sur la prise « DK » de l'imprimante ticket. Le pont envoie à l'imprimante
l'impulsion ESC/POS `ESC p 0 25 250` (broche 2, 50 ms). Trois réglages possibles de `drawer` :

| Imprimante | Réglage `drawer` |
|---|---|
| Imprimante réseau (Ethernet) | `{ "mode": "tcp", "host": "192.168.1.50", "port": 9100 }` |
| Imprimante USB sous Windows | partagez-la (Propriétés › Partage, nom `TICKET`), puis `{ "mode": "file", "path": "\\\\localhost\\TICKET" }` |
| Pas de tiroir | `{ "mode": "none" }` |

Tiroir sur la broche 5 : ajoutez `"pin": 5`. Essai : `node bin\fluvia-bridge.js drawer` ouvre le tiroir
une fois.

### Démonstration sans terminal

`node bin\fluvia-bridge.js fake-terminal accept 8888` lance un terminal **simulé** sur le poste
(`refuse`, `abort`, `partial:3000`, `silent`, `hangup` pour les autres issues). Réglez alors
`terminal.host` sur `127.0.0.1`. Aucune carte réelle n'est débitée.

## Interface locale (pour la caisse et pour la borne)

Toutes les réponses sont en JSON. Erreurs : `{ "code", "message" }`, le message est lisible par
l'agent d'accueil.

### Règles de sécurité (toutes appliquées par le pont)

1. Écoute sur `127.0.0.1` uniquement : rien sur le réseau du site ne peut l'appeler.
2. En-tête `Host` = `127.0.0.1:<port>` ou `localhost:<port>`, sinon 403 (contre le *DNS rebinding*).
3. En-tête `Origin` présent et listé dans `allowedOrigins`, sinon 403 — y compris pour la requête
   préalable `OPTIONS`, qui ne reçoit alors aucune autorisation CORS.
4. `POST` en `Content-Type: application/json` uniquement (415 sinon), corps de 4 Ko au plus.
5. Le résultat d'un paiement est **signé** (Ed25519) par la clé privée du pont, qui ne quitte jamais le
   poste. Fluvia le vérifie avec la clé publique du point de vente.
6. **Une tentative = un débit.** La même tentative redemandée (page rechargée) rend le **même**
   résultat signé, sans second débit ; un autre paiement pendant qu'un débit est en cours reçoit 409.
   Les résultats sont gardés dans `journal.jsonl` et survivent à un redémarrage du pont.
7. Le numéro de carte rendu par le terminal n'est **jamais** conservé ni renvoyé.

### `GET /v1/status`

```json
{ "bridge": "fluvia-caisse-ap", "version": "1.0.0", "publicKey": "<base64, 32 octets>",
  "terminal": { "configured": true, "busy": false }, "drawer": { "configured": true } }
```

### `POST /v1/payments` — demander un débit

Corps : `{ "attempt": "<UUID de la tentative Fluvia>", "amount": "45.00", "currency": "EUR" }`.

`attempt` est l'identifiant rendu par `POST /api/ventes/{id}/paiements` (202, `tentative.id`, connecteur
`caisse_ap_pont`) ; `amount` est `tentative.montant`, deux décimales. La réponse arrive **quand le
client a fini** (jusqu'à `terminal.timeoutMs`, 3 minutes par défaut) :

```json
{ "result": "<texte JSON signé>", "signature": "<base64, 64 octets>" }
```

`result`, une fois lu :

```json
{ "v": 1, "bridge": "fluvia-caisse-ap", "attempt": "…", "requestedAmount": "45.00", "currency": "EUR",
  "status": "accepte | refuse | annule | timeout", "debitedAmount": "45.00", "authorization": "123456",
  "network": "CB sans contact", "contactless": true, "ae": "10", "af": null, "reason": null,
  "terminal": "TPE-ACCUEIL", "at": "2026-11-05T10:00:00.000Z" }
```

| `status` | Sens | Ce que fait Fluvia |
|---|---|---|
| `accepte` | AE=10 : débit fait, de `debitedAmount` | écrit le règlement (le montant débité, qui peut être partiel) |
| `refuse` | AE=01 (refus de la carte, opération interdite…) | aucun règlement ; la vente reste à payer |
| `annule` | AE=01 avec AF=11 (abandon) ou AF=08 (délai de saisie), ou terminal injoignable avant tout envoi | aucun règlement |
| `timeout` | rien de lisible après l'envoi : la carte a **peut-être** été débitée | la vente reste tenue, l'agent déclare ce qu'affiche le terminal |

Puis la caisse relaie **sans y toucher** : `POST /api/ventes/{id}/issue-pont`, corps
`{ "tentative": "<attempt>", "resultat": "<result>", "signature": "<signature>" }`, avec le jeton de
l'utilisateur (droit `vente.encaisser`). Le serveur refuse (422, rien ne change) un résultat mal signé,
signé par le pont d'un autre point de vente, ou signé pour une autre tentative ou un autre montant.
Relayé deux fois, il n'écrit qu'un règlement. Voir `frontend/src/api/localBridge.js`
(`relayBridgePayment`) pour un client complet, retentatives comprises.

Autres réponses : 422 (`invalid_attempt`, `invalid_amount`, `invalid_currency`), 409 `terminal_busy`
(un autre paiement est en cours), 409 `attempt_amount_mismatch` (cette tentative a déjà été envoyée avec
un autre montant).

### `GET /v1/payments/{attempt}`

Le résultat signé d'une tentative déjà traitée (404 `unknown_attempt` sinon). Utile à un écran qui a
perdu la réponse.

### `POST /v1/drawer/open` — ouvrir le tiroir

Corps `{}`. 200 `{ "opened": true }` ; `{ "opened": false, "reason": "already_opening" }` si une
impulsion est partie il y a moins de 2 s (l'électro-aimant du tiroir chauffe) ; 409
`drawer_not_configured` ; 502 `drawer_unreachable`.

## Le protocole Caisse-AP

Source : Association du paiement, page « Protocole Caisse »,
<https://www.associationdupaiement.fr/protocoles/protocole-caisse/>, consultée le 05/11/2026 : le
protocole est publié par l'AP, **version 3.30 disponible depuis juillet 2026** ; le transport IP existe
depuis la version 3.x. **Le document de spécification n'est pas en téléchargement public** (espace
membres, ou par le constructeur du terminal).

Le sous-ensemble utilisé ici (paiement par débit, lecture de l'issue) est celui que décrivent deux
implémentations publiques concordantes, utilisées en production avec des terminaux Ingenico et
Verifone : `akretion/caisse-ap-ip` et le module Odoo `l10n_fr_pos_caisse_ap_ip` (OCA). Aucun code n'en
est repris. Format : ASCII, champs `ÉTIQUETTE (2) + LONGUEUR (3 chiffres) + VALEUR`, sans séparateur,
`CZ` en premier, une requête par connexion TCP. Requête : `CZ` version `0300`, `CJ`, `CA` n° de caisse,
`CE` devise (`978`), `BA=0` (réponse en fin de transaction), `CD=0` (débit), `CB` montant en centimes.
Réponse : `AE` (10 fait, 01 non fait, 11 simple accusé de réception), `AF` (complément d'un échec),
`CB` montant traité, `AC` n° d'autorisation, `CC` mode de paiement, `CI` mode de lecture.

**À vérifier sur un vrai terminal avant la mise en service** : la version annoncée (`CZ`) attendue par
le terminal, et les étiquettes propres à la version 3.30 (abandon de transaction, identification de la
transaction), non publiques. Le pont ne s'appuie que sur `AE`, `AF`, `CB`, `CD`, `CE`, `AC`, `CC`, `CI`.

## Tests

```
cd bridge/caisse-ap
node --test
```

Les tests montent un **terminal simulé** (`src/fake-terminal.js`) et une imprimante simulée, puis
vérifient : acceptation signée, **un refus rendu comme un refus**, abandon, débit partiel, accusé de
réception ignoré, terminal muet ou qui raccroche = issue inconnue, terminal injoignable = rien envoyé,
une tentative = un débit, terminal occupé, origine/Host/type refusés, autorisation CORS et *Private
Network Access*, octets exacts de l'impulsion du tiroir.
