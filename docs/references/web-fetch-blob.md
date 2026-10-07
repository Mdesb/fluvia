# API Web fetch / Blob / fenêtres / stockage — fiche de référence

- Version du projet : sans objet (API du navigateur, hors verrou) ; repère de compatibilité = statut « Baseline » de MDN
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources (toutes lues le 2026-10-07) :
  - [officielle] https://developer.mozilla.org/en-US/docs/Web/API/Window/fetch
  - [officielle] https://developer.mozilla.org/en-US/docs/Web/API/AbortController et …/AbortController/abort et …/AbortSignal
  - [officielle] https://developer.mozilla.org/en-US/docs/Web/API/Response/blob
  - [officielle] https://developer.mozilla.org/en-US/docs/Web/API/URL/createObjectURL_static
  - [officielle] https://developer.mozilla.org/en-US/docs/Web/API/Window/open
  - [officielle] https://developer.mozilla.org/en-US/docs/Glossary/Transient_activation et …/Web/Security/Defenses/User_activation
  - [officielle] https://developer.mozilla.org/en-US/docs/Web/API/Window/sessionStorage
  - [officielle] https://developer.mozilla.org/en-US/docs/Web/API/Crypto/randomUUID et …/Web/Security/Secure_Contexts
- Questions couvertes : `fetch` + `AbortController` ; `Response.blob()` ; `URL.createObjectURL`/`revokeObjectURL` ; `window.open` après un `await` ; `sessionStorage` ; `crypto.randomUUID()` et contexte sécurisé.

## À utiliser

### `fetch` + `AbortController`
- Passer `{ signal: controller.signal }` à `fetch` ; `controller.abort(reason?)` annule la requête ET la lecture du corps (`blob()`, `json()`…) et les flux. [officielle] AbortController
- Rejet : par défaut `DOMException` nommée `AbortError` ; si une `reason` est fournie, les API à promesses rejettent avec cette raison. Tester `err.name === 'AbortError'` suppose donc d'appeler `abort()` SANS raison. [officielle] abort + AbortSignal
- `AbortSignal.timeout(ms)` : rejet `TimeoutError` (distinguable d'une annulation utilisateur) ; avec `AbortSignal.any()` on ne peut plus savoir si la cause est le délai. [officielle] AbortSignal
- Une réponse HTTP 4xx/5xx NE rejette PAS : vérifier `response.ok` / `response.status`. Rejet `TypeError` sur erreur réseau, URL invalide ou contenant des identifiants. [officielle] Window/fetch
- `AbortController` : Baseline « largement disponible » (depuis mars 2019), aussi dans les Web Workers. [officielle] AbortController

### `Response.blob()`
- Lit le flux jusqu'au bout ; promesse résolue avec un `Blob` dont le type MIME vient de l'en-tête `Content-Type`. [officielle] Response/blob
- Rejets : `AbortError` si la requête est annulée ; `TypeError` si le corps est déjà consommé/verrouillé ou indécodable (mauvais `Content-Encoding`). [officielle] Response/blob
- Réponse `opaque` (requête `no-cors`) → `Blob` vide (taille 0, type ""). [officielle] Response/blob

### `URL.createObjectURL(blob)` / `URL.revokeObjectURL(url)`
- URL `blob:` liée au document créateur ; la ressource reste en mémoire jusqu'à `revokeObjectURL()` → révoquer après usage (fuite mémoire sinon). [officielle] createObjectURL
- Indisponible dans les Service Workers ; disponible dans les Web Workers. Baseline depuis juillet 2015. [officielle] createObjectURL

### `window.open` et activation utilisateur transitoire
- Les bloqueurs exigent un appel en réponse DIRECTE à une action de l'utilisateur, un geste par appel. Fenêtre bloquée → `window.open` renvoie `null`. [officielle] Window/open
- `window.open()` exige l'activation transitoire ET la CONSOMME. L'activation expire après un délai (non chiffré par MDN, propre au navigateur) si aucune nouvelle interaction. [officielle] User_activation + Transient_activation
- Après un `await` (requête réseau), l'activation peut avoir expiré → ouverture bloquée possible. MDN ne traite pas explicitement le cas « après une opération asynchrone ». [officielle] User_activation
- `noopener`/`noreferrer` : la nouvelle fenêtre n'a pas accès à `opener` / pas de `Referer`. [officielle] Window/open

### `sessionStorage`
- Cloisonné par ORIGINE et par ONGLET ; survit au rechargement et à la restauration de la page ; effacé à la fermeture de l'onglet. [officielle] sessionStorage
- Nouvel onglet = nouvelle session ; ouvert par `window.open()` avec `opener` : copie initiale du stockage de l'ouvreur, puis évolutions séparées. [officielle] sessionStorage
- `SecurityError` possible (schémas `file:`/`data:`, politique du navigateur, ex. cookies bloqués) → entourer d'un `try/catch`. Baseline depuis juillet 2015. [officielle] sessionStorage

### `crypto.randomUUID()`
- **Contexte sécurisé EXIGÉ** (HTTPS) ; renvoie une chaîne UUID **v4** de 36 caractères ; disponible en Web Workers ; Baseline depuis mars 2022. [officielle] Crypto/randomUUID
- Contextes sécurisés : `https:`, `wss:`, `file:`, `http://localhost`, `*.localhost`, `127.0.0.0/8`, `::1`. En contexte NON sécurisé (ex. `http://` sur un nom de domaine), l'API n'est PAS EXPOSÉE (pas d'erreur explicite) ; tester `window.isSecureContext`. [officielle] Secure_Contexts

## Pièges connus
- Ouvrir un onglet après `await fetch(...)` : risque de `null` (bloqueur) ; toujours tester le retour de `window.open`. [officielle] Window/open
- Oublier `revokeObjectURL` après un téléchargement : mémoire retenue jusqu'à la fermeture du document. [officielle] createObjectURL
- `crypto.randomUUID` absent sur une préproduction servie en `http://` hors `localhost`. [officielle] Secure_Contexts
- Lire deux fois le corps (`blob()` puis `json()`) → `TypeError`. [officielle] Response/blob

## À confirmer par le plan
- Durée exacte de l'activation transitoire : non fixée par MDN (dépend du navigateur) → ne pas s'appuyer sur un délai ; ouvrir la fenêtre dans le gestionnaire de clic AVANT tout `await`, ou recourir à un téléchargement sans fenêtre — choix de conception à trancher et à tester sur les navigateurs cibles (non documenté tel quel par MDN).
- Ouvrir une URL `blob:` dans un nouvel onglet : non traité par la page `window.open` → À VÉRIFIER.

## Écarts entre sources
- `Window/fetch` cite seulement `AbortError` pour une annulation ; `AbortSignal` précise que la promesse est rejetée avec la `reason` du signal (par défaut `AbortError`). La page la plus précise (AbortSignal) est retenue.
