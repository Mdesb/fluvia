// L'IDENTIFIANT D'UNE RELATION, QUELLE QUE SOIT LA FORME QUE L'API LUI DONNE.
//
// ── POURQUOI CE MODULE EXISTE ───────────────────────────────────────────────────────────────────
//
// Seize copies de cette fonction vivaient dans seize fichiers, sous trois noms (`idDe`,
// `idDepuisIri`, `idDeRef`). ⚠ ET ELLES N'ÉTAIENT PAS LA MÊME FONCTION : passées sur une batterie
// de quatorze entrées, elles se répartissaient en **neuf classes d'équivalence**.
//
// Ce n'était donc pas une dette de duplication, c'était cinq façons de PERDRE UNE DONNÉE :
//
//     {'@id': '/api/x/1'}   →  null        Social, Acces, Disponibilites
//     {'@id': '/api/x/1'}   →  undefined   Campagnes, CorrespondancesComptables
//     '/api/x/1'            →  ''          Facturation (elle ne lisait que `.id`)
//     {id, '@id'} divergents →  celui de @id  SaisieEcritureManuelle (priorité inversée)
//
// C'est exactement la première famille de défauts de ce dépôt : **une relation rendue en IRI, lue
// comme un objet**. Onze occurrences en avaient déjà été trouvées le 28/08, sur des colonnes qui
// sortaient vides sans que rien ne le signale.
//
// ── LE CONTRAT, ET IL EST DÉLIBÉRÉ ──────────────────────────────────────────────────────────────
//
//     null · undefined · '' · 0        →  null
//     'abc'                            →  'abc'
//     '/api/clients/abc'               →  'abc'
//     { id: 'abc' }                    →  'abc'
//     { '@id': '/api/clients/abc' }    →  'abc'
//     { id: 'abc', '@id': '/api/x/z' } →  'abc'   ⚠ `id` D'ABORD
//     {}                               →  null
//     42                               →  '42'
//
// ⚠ `id` A LA PRIORITÉ SUR `@id`, et une seule des seize faisait l'inverse. Quand les deux sont
// présents ils concordent toujours en pratique ; en cas de désaccord, `id` est la propriété que
// l'entité expose, `@id` une adresse que la sérialisation construit. On croit l'entité.
//
// ⚠ ON REND `null` ET JAMAIS `''`. Trois copies rendaient la chaîne vide. Les deux sont fausses au
// sens du booléen, donc `||` ne les distingue pas — mais `??` si, et une valeur absente qui répond
// `''` traverserait un `?? 'défaut'` sans le déclencher. `null` dit « il n'y en a pas ».
//
// ⚠ ON NE REND JAMAIS `undefined`. Deux copies le faisaient sur un objet sans `id` : `undefined`
// disparaît d'un `JSON.stringify`, ne s'affiche pas en JSX, et échoue à un `=== null`. Trois façons
// de se taire là où l'on voulait dire « rien ».

/**
 * L'identifiant porté par une référence d'API, ou `null`.
 *
 * Accepte une IRI (`/api/clients/abc`), un identifiant nu, un objet embarqué (`{ id }` ou
 * `{ '@id' }`), et tout ce qui est vide.
 *
 * @param {unknown} reference
 * @returns {string|null}
 */
export function idDe(reference) {
  if (!reference) return null

  if (typeof reference === 'object') {
    if (reference.id) return String(reference.id)
    const iri = reference['@id']
    return iri ? dernierSegment(String(iri)) : null
  }

  return dernierSegment(String(reference))
}

/**
 * Le dernier segment non vide d'un chemin.
 *
 * ⚠ Le `.filter(Boolean)` traite la barre oblique finale : `'/api/clients/abc/'` rend `'abc'` et
 * non `''`. Douze des seize copies utilisaient `.split('/').pop()` nu, qui rend la chaîne vide sur
 * une IRI mal formée — et une chaîne vide se lit comme un identifiant valide plus loin.
 */
function dernierSegment(valeur) {
  const segments = valeur.split('/').filter(Boolean)

  return segments.length > 0 ? segments[segments.length - 1] : null
}
