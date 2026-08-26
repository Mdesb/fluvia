import { useEffect, useState, useCallback } from 'react'
import { membres } from '../api/client.js'

// Carte de consultation générique : charge une collection (fn -> promesse), gère les états
// chargement / erreur / vide, et rend un tableau à partir d'une spec de colonnes.
// Réutilise les classes existantes (.card, .tbl, .badge, .spinner, .empty, .banner).
//
// props:
//  - titre, sous (sous-titre optionnel)
//  - charger: () => Promise (collection Hydra ou tableau)
//  - colonnes: [{ cle, entete, num?, rendu?(row) }]
//  - vide: message quand 0 ligne
//  - cle: fonction d'extraction de clé de ligne (def: row.id)
//  - deps: dépendances de rechargement (def: [])
//  - actions: noeud optionnel dans l'en-tête
export default function Liste({
  titre,
  sous,
  charger,
  colonnes,
  vide = 'Aucun élément.',
  cle = (r) => r.id,
  deps = [],
  actions = null,
  transforme,
}) {
  const [rows, setRows] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [statut, setStatut] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    setStatut(null)
    try {
      const res = await charger()
      let liste = membres(res)
      if (transforme) liste = transforme(liste, res)
      setRows(liste)
    } catch (e) {
      setErreur(e.message || 'Chargement impossible.')
      setStatut(e.status || null)
      setRows([])
    } finally {
      setChargement(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)

  useEffect(() => {
    recharger()
  }, [recharger])

  return (
    <section className="card">
      <div className="card-h">
        <h3>{titre}</h3>
        {sous && <span className="sub">{sous}</span>}
        <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
          {actions}
          <button className="btn ghost sm" onClick={recharger} disabled={chargement}>↻</button>
        </div>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : erreur ? (
          <div
            className="banner"
            style={
              statut === 403
                ? { background: 'var(--warn-bg)', color: 'var(--warn)', margin: 0 }
                : undefined
            }
          >
            {statut === 403
              ? "Accès non autorisé pour ce compte sur cet établissement (droits insuffisants). Cette vue reste vide."
              : erreur}
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                {colonnes.map((c) => (
                  <th key={c.cle} className={c.num ? 'num' : undefined}>{c.entete}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={cle(r, i)}>
                  {colonnes.map((c) => (
                    <td key={c.cle} className={c.num ? 'num' : undefined}>
                      {c.rendu ? c.rendu(r) : afficher(r[c.cle])}
                    </td>
                  ))}
                </tr>
              ))}
              {rows.length === 0 && (
                <tr><td colSpan={colonnes.length} className="empty">{vide}</td></tr>
              )}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}

function afficher(v) {
  if (v == null || v === '') return '—'
  if (typeof v === 'boolean') return v ? 'oui' : 'non'
  if (typeof v === 'object') return v.libelle || v.nom || v.code || v.id || '—'
  return String(v)
}

// Helpers partagés par les écrans de consultation.
export function euroCentimes(c) {
  if (c == null || c === '') return '—'
  const n = typeof c === 'number' ? c : parseInt(c, 10)
  if (Number.isNaN(n)) return '—'
  return (n / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

export function dateFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

export function dateHeureFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR')
}

// Libellé multilingue { fr: ... } ou chaîne simple.
// La date d'un instant, dans le fuseau de celui qui regarde.
//
// POURQUOI CE N'EST PAS `toISOString().slice(0, 10)`.
//
// `toISOString()` rend de l'UTC. Un créneau à **00 h 30 heure de Paris en été** devient `22:30Z` la
// veille : il est rangé au mauvais jour. Trois endroits du front le faisaient — le groupement par
// journée des réservations, la comparaison d'échéance des options de groupe scolaire, et la date
// envoyée au serveur pour une réception d'achat.
//
// Le dernier est le plus coûteux : entre minuit et deux heures du matin, une réception était datée
// de la veille. Sur un mouvement de stock, c'est une date qui compte.
//
// `sv-SE` est utilisé parce que c'est la seule locale courante dont le format court est déjà
// `AAAA-MM-JJ` — on obtient la date locale sans reconstruire la chaîne à la main.
export function jourLocal(v) {
  const d = v ? new Date(v) : new Date()
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString('sv-SE')
}

export function texte(v, repli = '—') {
  if (!v) return repli
  if (typeof v === 'string') return v
  if (typeof v === 'object') return v.fr || Object.values(v)[0] || repli
  return String(v)
}
