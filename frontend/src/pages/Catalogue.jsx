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
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Catalogue</h1>
          <p>{produits.length} produit(s)</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <form className="card" onSubmit={creer} style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>Nouveau produit</h3></div>
        <div className="card-b">
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: '2fr 1fr 1fr auto',
              gap: 12,
              alignItems: 'end',
            }}
            className="cat-form-row"
          >
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="lib">Libellé *</label>
              <input
                id="lib"
                className="input"
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
                className="input"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                placeholder="Ex. ENT-ADU"
              />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="type">Type *</label>
              <select id="type" className="select" value={typeId} onChange={(e) => setTypeId(e.target.value)}>
                {types.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.libelle}
                  </option>
                ))}
              </select>
            </div>
            <button className="btn primary" type="submit" disabled={enCours}>
              {enCours ? 'Création…' : '＋ Créer'}
            </button>
          </div>
        </div>
      </form>

      <div className="card">
        {chargement ? (
          <div className="center" style={{ minHeight: 160 }}>
            <div className="spinner" />
          </div>
        ) : (
          <div className="card-b" style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Libellé</th>
                  <th>Code</th>
                  <th>Type</th>
                  <th>État</th>
                  <th className="num">Tarif</th>
                </tr>
              </thead>
              <tbody>
                {produits.map((p) => (
                  <tr key={p.id}>
                    <td><span className="nm">{libelleProduit(p)}</span></td>
                    <td>{p.code || '—'}</td>
                    <td>{p.typeCode || '—'}</td>
                    <td>
                      <span className={`badge ${p.statut === 'publie' ? 'good' : 'mut'}`}>
                        {p.statut || '—'}
                      </span>
                    </td>
                    <td className="num">{euros(prixIndicatif(p))}</td>
                  </tr>
                ))}
                {produits.length === 0 && (
                  <tr>
                    <td colSpan={5} className="empty">Aucun produit.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
