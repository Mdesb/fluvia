// La page « Mon compte » de la boutique doit savoir de quelle boutique on vient.
// Lancer : `cd frontend && node --test src/public/`
//
// Vécu (13/10/2026) : depuis le 27/08, `PublicApp` rendait `<MonCompte>` sans `vitrineId` ni
// `panierId`. La création de compte refusait donc toujours, avant tout appel au serveur :
// « Ouvrez la boutique avant de créer un compte ». Le serveur était juste, le formulaire aussi ;
// c'est le fil entre les deux qui manquait, et aucun test ne regardait ce fil.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { parse } from '@babel/parser'

const source = readFileSync(fileURLToPath(new URL('./PublicApp.jsx', import.meta.url)), 'utf8')
const ast = parse(source, { sourceType: 'module', plugins: ['jsx'] })

// Tous les éléments JSX d'un nom donné, avec les noms de leurs attributs.
function elements(nom) {
  const trouves = []
  const visiter = (n) => {
    if (!n || typeof n.type !== 'string') return
    if (n.type === 'JSXOpeningElement' && n.name?.name === nom) {
      trouves.push(n.attributes.filter((a) => a.type === 'JSXAttribute').map((a) => a.name.name))
    }
    for (const cle of Object.keys(n)) {
      if (cle === 'loc' || cle === 'start' || cle === 'end') continue
      const v = n[cle]
      if (Array.isArray(v)) v.forEach(visiter)
      else if (v && typeof v === 'object') visiter(v)
    }
  }
  visiter(ast.program)
  return trouves
}

test('PublicApp rend la page compte', () => {
  // Témoin : sans ce rendu, le test suivant passerait sans rien regarder.
  assert.ok(elements('MonCompte').length >= 1, 'aucun <MonCompte> trouvé dans PublicApp.jsx')
})

test('la page compte reçoit la boutique, le panier et le rattachement du panier', () => {
  for (const attributs of elements('MonCompte')) {
    for (const requis of ['vitrineId', 'panierId', 'onPanierRattache']) {
      assert.ok(attributs.includes(requis), `<MonCompte> sans ${requis} : la création de compte est refusée ou perd le panier`)
    }
  }
})
