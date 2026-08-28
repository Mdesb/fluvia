#!/usr/bin/env node
/*
 * LE SERVICE WORKER NE MET AUCUNE RÉPONSE MÉTIER EN CACHE — et ce contrôle l'exécute pour le
 * vérifier, au lieu de lire son texte.
 *
 * ── POURQUOI CE CONTRÔLE EXISTE ─────────────────────────────────────────────────────────────────
 *
 * Un service worker qui sert une réponse d'API périmée est le pire défaut que ce dépôt puisse
 * produire, parce qu'il ne ressemble pas à une panne. Un caissier voit un solde de carte, une jauge
 * de bassin, une liste de passages — vieux de dix minutes — et rien à l'écran ne lui dit qu'ils sont
 * vieux. Il encaisse, il laisse entrer, il refuse une entrée. Aucune erreur, aucune alerte, aucune
 * trace. **Un logiciel de caisse hors ligne qui ment est pire qu'un logiciel de caisse
 * indisponible.**
 *
 * Rien n'empêchait ce défaut : `sw.js` n'est chargé par aucun test, ne passe par aucun build et ne
 * lève jamais. Le jour où quelqu'un ajoutera « le mode hors ligne » de bonne foi, il touchera ce
 * fichier — et il n'aura rien pour lui dire ce qu'il vient de casser.
 *
 * ── POURQUOI ON L'EXÉCUTE AU LIEU DE LE LIRE ────────────────────────────────────────────────────
 *
 * Un contrôle par expression régulière sur le texte du fichier serait plus court et faux : il
 * dirait « la liste des préfixes est présente » alors que la question est « la requête est-elle
 * interceptée ». On charge donc `sw.js` dans un `vm` avec un faux `self`, on lui envoie de vraies
 * requêtes synthétiques, et on regarde ce qu'il en fait. C'est la différence entre vérifier qu'un
 * extincteur est accroché au mur et l'ouvrir.
 *
 * ── CE QUI EST VÉRIFIÉ ──────────────────────────────────────────────────────────────────────────
 *
 *   1. aucune route métier n'est interceptée — ni le préfixe nu, ni ce qui suit ;
 *   2. les assets à empreinte le sont, sinon le fichier ne sert plus à rien ;
 *   3. la navigation part au RÉSEAU d'abord — sinon un déploiement resterait invisible jusqu'à ce
 *      que l'utilisateur vide son navigateur, et personne ne penserait à le lui demander ;
 *   4. l'installation ne met en cache que la coquille.
 *
 * Sortie non nulle si l'une tombe.
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import vm from 'node:vm'

const ICI = dirname(fileURLToPath(import.meta.url))
const CHEMIN = resolve(ICI, '..', 'public', 'sw.js')
const ORIGINE = 'https://fluvia.test'

/*
 * ⚠ CETTE LISTE EST ÉCRITE ICI, PAS LUE DANS `sw.js`. La lire dans le fichier contrôlé ferait un
 * contrôle qui se compare à lui-même — la même erreur que de vérifier un calcul de dates avec la
 * formule qu'il vérifie.
 *
 * ⚠ ET CE QUE CE CONTRÔLE MESURE, C'EST L'INTERCEPTION, PAS LA PRÉSENCE DE LA LISTE. Vérifié en
 * cassant : retirer `/api` de `sw.js` ne rend rien périmé aujourd'hui, parce que le comportement
 * par défaut du gestionnaire est de laisser passer. La liste est une ceinture en plus des
 * bretelles. Le vrai défaut est une branche de cache attrape-tout — et celui-là est refusé.
 */
const ROUTES_METIER = ['/api', '/auth', '/me', '/reporting', '/media', '/dms', '/sepa', '/agenda', '/calendar']

const echecs = []

function verifier(condition, message) {
  if (!condition) echecs.push(message)
}

/** Charge `sw.js` et rend ses écouteurs, sans rien exécuter d'autre. */
function chargerServiceWorker() {
  const source = readFileSync(CHEMIN, 'utf8')
  const ecouteurs = {}
  const journal = []

  const faireCache = () => ({
    addAll: (urls) => {
      journal.push({ geste: 'addAll', urls })
      return Promise.resolve()
    },
    put: (requete) => {
      journal.push({ geste: 'put', url: requete?.url })
      return Promise.resolve()
    },
  })

  const self = {
    addEventListener: (nom, gestionnaire) => {
      ecouteurs[nom] = gestionnaire
    },
    location: { origin: ORIGINE },
    skipWaiting: () => Promise.resolve(),
    clients: { claim: () => Promise.resolve() },
  }

  const contexte = {
    self,
    URL,
    Response: { error: () => ({ erreur: true }) },
    Promise,
    caches: {
      open: () => Promise.resolve(faireCache()),
      keys: () => Promise.resolve([]),
      delete: () => Promise.resolve(true),
      match: () => {
        journal.push({ geste: 'match' })
        return Promise.resolve(null)
      },
    },
    fetch: (requete) => {
      journal.push({ geste: 'fetch', url: requete?.url })
      return Promise.resolve({ ok: true, clone: () => ({}) })
    },
    console,
  }
  contexte.globalThis = contexte

  vm.createContext(contexte)
  vm.runInContext(source, contexte, { filename: 'sw.js' })

  return { ecouteurs, journal, contexte }
}

/**
 * Envoie une requête synthétique au gestionnaire `fetch` et dit si elle a été INTERCEPTÉE.
 *
 * Ne pas intercepter, c'est laisser partir au réseau : c'est le comportement voulu pour tout ce qui
 * parle au serveur métier.
 */
function intercepte(sw, chemin, { methode = 'GET', mode = 'cors' } = {}) {
  let interceptee = false
  const evenement = {
    request: { method: methode, url: ORIGINE + chemin, mode },
    respondWith: () => {
      interceptee = true
    },
  }
  sw.ecouteurs.fetch(evenement)

  return interceptee
}

async function main() {
  const sw = chargerServiceWorker()

  verifier(typeof sw.ecouteurs.fetch === 'function', 'Aucun écouteur « fetch » : le service worker n’intercepte plus rien.')
  verifier(typeof sw.ecouteurs.install === 'function', 'Aucun écouteur « install ».')
  if (echecs.length > 0) {
    rendre()
  }

  // 1. Les routes métier partent au réseau, préfixe nu comme sous-chemin.
  for (const route of ROUTES_METIER) {
    verifier(
      !intercepte(sw, route),
      `« ${route} » est intercepté par le service worker : une réponse métier peut être servie depuis le cache.`,
    )
    verifier(
      !intercepte(sw, `${route}/quelque-chose?x=1`),
      `« ${route}/… » est intercepté par le service worker : une réponse métier peut être servie depuis le cache.`,
    )
  }

  // 2. Les assets à empreinte, eux, DOIVENT l'être — sinon ce fichier ne sert plus à rien et la
  //    bannière d'installation disparaît avec lui.
  verifier(
    intercepte(sw, '/assets/App-JfSaL8rM.js'),
    'Les assets ne sont plus mis en cache : le service worker ne sert plus à rien, et l’invite d’installation disparaît avec lui.',
  )

  // 3. La navigation part au RÉSEAU d'abord. Le cache d'abord rendrait tout déploiement invisible
  //    jusqu'à ce que l'utilisateur vide son navigateur — et personne ne penserait à le lui demander.
  const avant = sw.journal.length
  const navigation = intercepte(sw, '/support', { mode: 'navigate' })
  verifier(navigation, 'La navigation n’est plus servie : l’application ne s’ouvre plus hors réseau.')
  const gestes = sw.journal.slice(avant).map((e) => e.geste)
  verifier(
    gestes[0] === 'fetch',
    `La navigation ne part plus au réseau en premier (premier geste : « ${gestes[0] ?? 'aucun'} »). Un déploiement resterait invisible.`,
  )

  // 4. L'installation ne met en cache que la coquille.
  //
  // ⚠ ON ATTEND LA PROMESSE. Première version : le journal était lu dans la foulée de l'appel, donc
  // AVANT que la chaîne `caches.open().then(addAll)` n'ait eu son tour de boucle. Le contrôle
  // regardait un journal vide et concluait « rien de suspect » — vert quoi qu'on mette dans
  // `addAll`. Vu en cassant : ajouter `/api/etablissements` à l'installation ne le faisait pas
  // broncher.
  let promesseInstall = null
  sw.ecouteurs.install({ waitUntil: (p) => { promesseInstall = p } })
  await promesseInstall
  const misEnCache = sw.journal.filter((e) => e.geste === 'addAll').flatMap((e) => e.urls ?? [])
  verifier(misEnCache.length > 0, 'L’installation ne met rien en cache : le contrôle n’a rien pu observer.')
  for (const url of misEnCache) {
    verifier(
      !ROUTES_METIER.some((route) => url === route || url.startsWith(`${route}/`)),
      `L’installation met « ${url} » en cache : une réponse métier serait figée dès la première visite.`,
    )
  }

  rendre()
}

function rendre() {
  if (echecs.length === 0) {
    console.log('✓ Cache : aucune route métier n’est mise en cache, la navigation part au réseau.')
    process.exit(0)
  }
  console.error('✗ Service worker : le cache peut servir une donnée périmée.')
  for (const echec of echecs) console.error(`  · ${echec}`)
  console.error('\n  On met en cache ce qui ne change pas entre deux versions, jamais ce qui change')
  console.error('  entre deux minutes. Un logiciel de caisse hors ligne qui ment est pire qu’un')
  console.error('  logiciel de caisse indisponible.')
  process.exit(1)
}

await main()
