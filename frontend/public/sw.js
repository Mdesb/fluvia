/* eslint-disable no-restricted-globals */
/*
 * LE SERVICE WORKER DE FLUVIA — et tout ce qu'il refuse de faire.
 *
 * ── CE QU'IL EST LÀ POUR PERMETTRE ──────────────────────────────────────────────────────────────
 *
 * Une seule chose : que l'application s'installe sur un téléphone. Chrome n'affiche l'invite
 * « Installer » que si le site déclare un manifeste ET un service worker qui répond quand le réseau
 * manque. Sans ce fichier, la bannière n'apparaît jamais.
 *
 * ── CE QU'IL NE FAIT SURTOUT PAS ────────────────────────────────────────────────────────────────
 *
 * **Il ne met AUCUNE réponse d'API en cache.** Ni `/api`, ni `/auth`, ni `/me`. Un caissier qui
 * verrait un solde de carte, une jauge de bassin ou une liste de passages vieux de dix minutes
 * prendrait une décision sur une donnée fausse — et rien à l'écran ne lui dirait qu'elle est
 * vieille. Un logiciel de caisse hors ligne qui ment est pire qu'un logiciel de caisse indisponible.
 *
 * > **On met en cache ce qui ne change pas entre deux versions, jamais ce qui change entre deux
 * > minutes.**
 *
 * ── CE QU'IL MET EN CACHE, ET POURQUOI C'EST SANS RISQUE ────────────────────────────────────────
 *
 * Uniquement les fichiers de `/assets/`, que Vite nomme avec une empreinte de leur contenu
 * (`App-JfSaL8rM.js`). Une URL d'asset désigne donc UN contenu, pour toujours : la servir depuis le
 * cache ne peut pas rendre une version périmée. Un déploiement produit de nouveaux noms, et
 * l'ancien cache est purgé au changement de version ci-dessous — ce qui n'a été VRAI qu'à partir du
 * 30/08 : `VERSION` valait une constante, donc cette phrase décrivait une intention et non le code.
 *
 * La navigation, elle, part TOUJOURS au réseau d'abord. Le cache ne sert de secours que si le
 * réseau échoue — sinon un déploiement resterait invisible jusqu'à ce que quelqu'un vide son
 * navigateur, ce qui est exactement le défaut qu'on veut éviter.
 */

// ⚠ CE JETON EST REMPLACE PAR LE COMMIT AU DEPLOIEMENT, ET LES DEUX GARDES DE CE FICHIER EN
// DEPENDENT. Tant qu'il valait une constante (`fluvia-v1`), la purge de `activate` ne pouvait rien
// supprimer -- aucun autre nom n'existait -- et `install` ne se rejouait jamais, donc la coquille
// mise en cache continuait de nommer des assets que `rsync --delete` avait fait disparaitre :
// hors ligne, page blanche, au premier deploiement suivant l'installation.
//
// La substitution est faite par `infra/deploy-preprod.sh`, qui VERIFIE ensuite que le jeton a
// disparu et que le fichier servi porte bien le commit courant. Sans cette verification, une
// substitution sautee rendrait la constante -- et le defaut -- sans que rien ne le dise.
const VERSION = 'fluvia-__COMMIT__'
const COQUILLE = '/index.html'

self.addEventListener('install', (evenement) => {
  evenement.waitUntil(
    caches.open(VERSION).then((cache) => cache.addAll([COQUILLE])).then(() => self.skipWaiting()),
  )
})

// ── COMMENT PROUVER QUE LA PURGE CI-DESSOUS PURGE ──────────────────────────────────────────────
//
// Elle ne se prouve pas en la lisant : trois lignes manifestement justes ont passé deux jours à ne
// rien supprimer, parce que `VERSION` était constant et qu'aucun autre nom n'existait jamais.
//
// ⚠ ET ELLE NE SE PROUVE PAS NON PLUS EN FABRIQUANT UN FAUX CACHE. Essayé le 30/08 : un cache posé à
// la main survivait à une désinscription suivie d'une réinscription. Cause — `unregister()` est
// DIFFÉRÉ tant qu'un client est contrôlé, donc la réinscription retrouve le worker existant : ni
// `installing`, ni `waiting`, aucun `activate`. On mesure un silence, et on le lit comme un refus.
//
// Le seul montage qui exerce le cycle : DEUX DÉPLOIEMENTS SUCCESSIFS et un navigateur qui traverse
// les deux. Lire `caches.keys()` avant, déployer, recharger, relire. Un seul nom, portant le
// NOUVEAU commit, prouve les deux choses à la fois : `install` s'est rejoué, et `activate` a
// supprimé le précédent. Deux noms signifieraient une purge inerte.
self.addEventListener('activate', (evenement) => {
  // Purge des versions précédentes : sans elle, chaque déploiement laisserait derrière lui un
  // cache complet, et le stockage du téléphone finirait par être refusé.
  evenement.waitUntil(
    caches
      .keys()
      .then((noms) => Promise.all(noms.filter((n) => n !== VERSION).map((n) => caches.delete(n))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (evenement) => {
  const requete = evenement.request
  if (requete.method !== 'GET') return

  const url = new URL(requete.url)
  if (url.origin !== self.location.origin) return

  // ⚠ LA LISTE QUI PROTÈGE CONTRE LA DONNÉE PÉRIMÉE. Tout ce qui parle au serveur métier passe au
  // réseau, sans interception et sans repli. Si le réseau manque, la requête échoue — et l'écran
  // affiche SON message d'erreur, ce qui est la vérité.
  const versLeServeur = ['/api', '/auth', '/me', '/reporting', '/media', '/dms', '/sepa', '/agenda', '/calendar']
  if (versLeServeur.some((prefixe) => url.pathname === prefixe || url.pathname.startsWith(prefixe + '/'))) {
    return
  }

  // Assets à empreinte : une URL = un contenu, pour toujours. Cache d'abord, réseau ensuite.
  if (url.pathname.startsWith('/assets/')) {
    evenement.respondWith(
      caches.match(requete).then(
        (enCache) =>
          enCache
          || fetch(requete).then((reponse) => {
            if (reponse && reponse.ok) {
              const copie = reponse.clone()
              caches.open(VERSION).then((cache) => cache.put(requete, copie))
            }
            return reponse
          }),
      ),
    )
    return
  }

  // Navigation : réseau d'abord, coquille en secours. Un déploiement doit être visible tout de
  // suite ; le cache n'est là que pour l'avion et l'ascenseur.
  if (requete.mode === 'navigate') {
    evenement.respondWith(
      fetch(requete).catch(() => caches.match(COQUILLE).then((r) => r || Response.error())),
    )
  }
})
