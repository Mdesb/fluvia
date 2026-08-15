import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { libelleProduit, prixIndicatif, euros } from '../api/produit.js'

export default function Catalogue({ etabActif }) {
  const [produits, setProduits] = useState([])
  const [types, setTypes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [libelle, setLibelle] = useState('')
  const [code, setCode] = useState('')
  const [typeId, setTypeId] = useState('')
  const [enCours, setEnCours] = useState(false)

  async function recharger() {
    setChargement(true)
    setErreur(null)
    try {
      const [pc, tc] = await Promise.all([api.produits(), api.typeProduits()])
      setProduits(membres(pc))
      const t = membres(tc)
      setTypes(t)
      if (!typeId && t.length) setTypeId(t[0].id)
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }

  useEffect(() => {
    recharger()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [etabActif])

  async function creer(e) {
    e.preventDefault()
    setErreur(null)
    setSucces(null)
    if (!libelle.trim() || !typeId) return
    setEnCours(true)
    try {
      await api.creerProduit({
        libelle: { fr: libelle.trim() },
        type: `/api/type_produits/${typeId}`,
        code: code.trim() || undefined,
        canaux: ['guichet'],
        etablissements: etabActif ? [`/api/etablissements/${etabActif}`] : [],
      })
      setSucces(`Produit « ${libelle.trim()} » créé.`)
      setLibelle('')
      setCode('')
      await recharger()
    } catch (err) {
      setErreur(err.message || 'Échec de la création du produit.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div className="page">
      <div className="page-head">
        <div>
          <h2>Catalogue</h2>
          <div className="sub">{produits.length} produit(s)</div>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <form className="form-card" onSubmit={creer}>
        <h3>Nouveau produit</h3>
        <div className="form-row">
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="lib">Libellé *</label>
            <input
              id="lib"
              value={libelle}
              onChange={(e) => setLibelle(e.target.value)}
              placeholder="Ex. Entrée adulte"
              required
            />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="code">Code</label>
            <input
              id="code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="Ex. ENT-ADU"
            />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="type">Type *</label>
            <select id="type" value={typeId} onChange={(e) => setTypeId(e.target.value)}>
              {types.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.libelle}
                </option>
              ))}
            </select>
          </div>
          <button className="btn" type="submit" disabled={enCours}>
            {enCours ? 'Création…' : 'Créer'}
          </button>
        </div>
      </form>

      <div className="table-card">
        {chargement ? (
          <div className="center" style={{ minHeight: 160 }}>
            <div className="spinner" />
          </div>
        ) : (
          <table>
            <thead>
              <tr>
                <th>Libellé</th>
                <th>Code</th>
                <th>Type</th>
                <th>Statut</th>
                <th style={{ textAlign: 'right' }}>Tarif</th>
              </tr>
            </thead>
            <tbody>
              {produits.map((p) => (
                <tr key={p.id}>
                  <td>{libelleProduit(p)}</td>
                  <td>{p.code || '—'}</td>
                  <td>{p.typeCode || '—'}</td>
                  <td>
                    <span className={`tag ${p.statut === 'publie' ? '' : 'tag-muted'}`}>
                      {p.statut || '—'}
                    </span>
                  </td>
                  <td style={{ textAlign: 'right' }}>{euros(prixIndicatif(p))}</td>
                </tr>
              ))}
              {produits.length === 0 && (
                <tr>
                  <td colSpan={5} style={{ textAlign: 'center', color: 'var(--muted)' }}>
                    Aucun produit.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
