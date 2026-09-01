// GARDE-FOU n°40 — UN IDENTIFIANT UTILISÉ HORS DE SA PORTÉE.
//
// ── LE DÉFAUT, ET POURQUOI RIEN NE LE VOYAIT ────────────────────────────────────────────────────
//
// Le 01/09, j'ai déclaré deux variables dans le composant `Catalogue` alors qu'elles sont **lues**
// dans `OngletProduits` — deux composants du même fichier. Deux déploiements, deux écrans cassés
// en production :
//
//     ReferenceError: capacites is not defined
//     ReferenceError: peutCreer is not defined
//
// **Le build passe.** Vite ne fait pas d'analyse de portée sur le JSX : un identifiant inconnu
// n'existe qu'au rendu. **Les garde-fous passaient** : le n°10 contrôle les IMPORTS manquants, pas
// les identifiants hors portée — son propre docblock annonce ce trou. J'avais même vérifié que le
// nom était dans le bundle servi : il y était, au mauvais endroit.
//
// La cause n'était pas l'inattention mais la méthode : lire un fichier par ses numéros de ligne ne
// dit pas quelle fonction les englobe, et un fichier de page en porte souvent quatre.
//
// ── POURQUOI CE CONTRÔLE-CI EST FIABLE, LÀ OÙ UNE EXPRESSION RÉGULIÈRE NE LE SERAIT PAS ────────
//
// Il ne devine rien : il PARSE. `@babel/parser` construit l'arbre, `@babel/traverse` résout les
// portées, et `scope.globals` rend exactement les identifiants qu'aucune déclaration ni aucun
// import ne couvre. C'est le même calcul que fait le moteur JavaScript à l'exécution — en avance.
//
// Une approximation textuelle aurait crié faux sur les noms de propriétés, les clés d'objet, les
// paramètres déstructurés et les fermetures. Un contrôle qui crie faux s'apprend à sauter, et le
// jour où il a raison plus personne ne le lit.
//
// ── ⚠ ET S'IL NE PEUT PAS TOURNER, IL LE DIT ───────────────────────────────────────────────────
//
// `@babel/parser` est une dépendance de développement : `npm ci --omit=dev` la retire. Un contrôle
// qui rendrait « OK » sans avoir lu une ligne serait pire que pas de contrôle — il transformerait
// une absence de mesure en assurance. Il annonce donc « NON EXÉCUTÉ » et dit quoi lancer.
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const RACINE = 'src'

// Ce que le navigateur et le langage fournissent. Tout le reste doit être déclaré ou importé.
const FOURNIS = new Set([
  // langage
  'globalThis', 'Object', 'Array', 'String', 'Number', 'Boolean', 'Symbol', 'BigInt', 'Math',
  'JSON', 'Date', 'RegExp', 'Promise', 'Map', 'Set', 'WeakMap', 'WeakSet', 'Proxy', 'Reflect',
  'Error', 'TypeError', 'RangeError', 'SyntaxError', 'Intl', 'parseInt', 'parseFloat', 'isNaN',
  'isFinite', 'encodeURIComponent', 'decodeURIComponent', 'encodeURI', 'decodeURI', 'structuredClone',
  // ⚠ `undefined`, `NaN` et `Infinity` sont des PROPRIÉTÉS de l'objet global, pas des mots-clés :
  // le parseur les rend donc comme des identifiants non résolus. Les oublier ici produisait
  // trente-cinq fausses accusations au premier passage — de quoi rendre le contrôle illisible.
  'undefined', 'NaN', 'Infinity',
  // navigateur
  'window', 'document', 'console', 'navigator', 'location', 'history', 'screen', 'performance',
  'localStorage', 'sessionStorage', 'caches', 'crypto', 'fetch', 'Headers', 'Request', 'Response',
  'URL', 'URLSearchParams', 'FormData', 'Blob', 'File', 'FileReader', 'Image', 'Audio',
  'AbortController', 'AbortSignal', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval',
  'requestAnimationFrame', 'cancelAnimationFrame', 'queueMicrotask', 'matchMedia', 'getComputedStyle',
  'alert', 'confirm', 'prompt', 'btoa', 'atob', 'TextEncoder', 'TextDecoder', 'Notification',
  'Event', 'CustomEvent', 'EventSource', 'WebSocket', 'MessageChannel', 'BroadcastChannel',
  'IntersectionObserver', 'ResizeObserver', 'MutationObserver', 'DOMParser', 'XMLHttpRequest',
  'Node', 'Element', 'HTMLElement', 'HTMLCanvasElement', 'CanvasRenderingContext2D', 'SVGElement',
  'self', 'top', 'parent', 'frames', 'origin', 'scrollTo', 'scrollBy', 'open', 'close', 'print',
  // service worker
  'clients', 'skipWaiting', 'registration',
])

let parser
let traverse
try {
  parser = await import('@babel/parser')
  // ⚠ Le paquet est publié en CommonJS : sous ESM, `default` peut être l'objet du module, la
  // fonction se trouvant alors sous `default.default`. On accepte les deux formes plutôt que de
  // supposer laquelle, parce que la différence dépend de la version installée — et qu'un
  // `traverse is not a function` au premier passage ne dit pas d'emblée que c'est ça.
  const mod = await import('@babel/traverse')
  traverse = typeof mod.default === 'function' ? mod.default : mod.default.default
} catch {
  console.log('· NON EXÉCUTÉ — `@babel/parser` absent (retiré par `npm ci --omit=dev`).')
  console.log('  Le contrôle n\'a PAS tourné : ne pas lire ce passage comme un « OK ».')
  console.log('  Pour le relancer :  cd frontend && npm install')
  process.exit(0)
}

function fichiers(dossier) {
  const out = []
  for (const nom of readdirSync(dossier)) {
    const chemin = join(dossier, nom)
    if (statSync(chemin).isDirectory()) out.push(...fichiers(chemin))
    else if (/\.(js|jsx)$/.test(nom)) out.push(chemin)
  }
  return out
}

const liste = fichiers(RACINE)
const fautes = []
let illisibles = 0

for (const chemin of liste) {
  const source = readFileSync(chemin, 'utf8')
  let ast
  try {
    ast = parser.parse(source, {
      sourceType: 'module',
      plugins: ['jsx', 'importMeta', 'topLevelAwait', 'classProperties', 'optionalChaining', 'nullishCoalescingOperator'],
    })
  } catch (e) {
    // ⚠ UN FICHIER ILLISIBLE N'EST PAS UN FICHIER SAIN. On le compte et on le nomme, sinon le
    // périmètre rétrécit en silence et le vert ne mesure plus rien.
    illisibles++
    fautes.push({ chemin, nom: '(fichier non analysable)', ligne: 0, detail: String(e.message).slice(0, 90) })
    continue
  }

  let programme = null
  traverse(ast, { Program(path) { programme = path } })
  if (!programme) continue

  for (const [nom, chemins] of Object.entries(programme.scope.globals ? {} : {})) void [nom, chemins]

  // `scope.globals` : les identifiants référencés qu'aucune déclaration ni import ne couvre.
  const globals = programme.scope.globals || {}
  for (const nom of Object.keys(globals)) {
    if (FOURNIS.has(nom)) continue
    const noeud = globals[nom]
    fautes.push({ chemin, nom, ligne: noeud?.loc?.start?.line ?? 0, detail: '' })
  }
}

// ── LA LIGNE DE BASE ───────────────────────────────────────────────────────────────────────────
//
// Sept défauts de cette famille existaient avant ce contrôle, dans six modules qui ne sont pas les
// miens. Les corriger à l'aveugle à deux heures du matin, dans du code que je n'ai pas écrit,
// coûterait plus cher que la dette. On les GÈLE : le contrôle refuse tout NOUVEAU, et le nombre ne
// peut que descendre.
//
// ⚠ La clé est `fichier:identifiant`, SANS le numéro de ligne : sinon la moindre ligne ajoutée
// au-dessus ferait ressortir un défaut gelé comme s'il était neuf, et on prendrait l'habitude de
// régénérer la ligne de base — ce qui la vide de son sens.
const FICHIER_BASE = 'scripts/portee.ligne-de-base.json'
let base = { _lisez_moi: '', gelees: [] }
try {
  base = JSON.parse(readFileSync(FICHIER_BASE, 'utf8'))
} catch {
  // Absente au premier passage : on la propose plutôt que de refuser.
}
const gelees = new Set(base.gelees || [])
const cle = (f) => `${f.chemin}:${f.nom}`

if (process.argv.includes('--geler')) {
  const contenu = {
    _lisez_moi: [
      'Ligne de base GELEE du garde-fou n40 — identifiants utilises hors de leur portee.',
      '',
      'Chaque entree est un ecran qui casse AU RENDU : le build ne le voit pas, Vite ne faisant',
      'pas d analyse de portee sur le JSX. Ce ne sont pas des avertissements, ce sont des defauts.',
      '',
      'Ils sont geles parce qu ils precedent le controle et vivent dans des modules varies. Le',
      'controle refuse tout NOUVEAU ; ce nombre ne doit que descendre.',
      '',
      'Cle = fichier:identifiant, sans numero de ligne : une ligne ajoutee au-dessus ne doit pas',
      'faire ressortir un defaut gele comme neuf.',
      '',
      'Pour retirer ceux qui ont ete corriges :  node scripts/verifier-portee.mjs --geler',
    ],
    gelees: fautes.map(cle).sort(),
  }
  writeFileSync(FICHIER_BASE, `${JSON.stringify(contenu, null, 2)}\n`)
  console.log(`✓ Ligne de base gelée : ${fautes.length} entrée(s).`)
  process.exit(0)
}

const neuves = fautes.filter((f) => !gelees.has(cle(f)))
const resorbees = [...gelees].filter((k) => !fautes.some((f) => cle(f) === k))

console.log(`Portée des identifiants : ${liste.length} fichier(s) analysé(s)${illisibles ? `, ${illisibles} illisible(s)` : ''}.`)

if (resorbees.length > 0) {
  console.log(`\nBonne nouvelle : ${resorbees.length} entrée(s) de la ligne de base sont corrigées.`)
  for (const k of resorbees) console.log(`  - ${k}`)
  console.log('\n  Retirez-les :  node scripts/verifier-portee.mjs --geler')
}

if (neuves.length === 0) {
  console.log(`✓ Portée : aucun identifiant hors portée nouveau. Dette gelée : ${gelees.size}.`)
  process.exit(0)
}

console.log('✗ Identifiants référencés sans déclaration ni import visible :')
for (const f of neuves.slice(0, 40)) {
  console.log(`  - ${f.chemin}:${f.ligne} — ${f.nom}${f.detail ? ` (${f.detail})` : ''}`)
}
if (neuves.length > 40) console.log(`  … et ${neuves.length - 40} autre(s).`)
console.log('')
console.log('  Le build ne voit PAS ce défaut : Vite ne fait pas d\'analyse de portée sur le JSX.')
console.log('  Une variable déclarée dans un composant voisin du même fichier en fait partie —')
console.log('  c\'est ce qui a cassé l\'écran Catalogue deux fois le 01/09.')
process.exit(1)
