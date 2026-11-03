import { useEffect, useState } from 'react'
import { api } from '../api/client.js'
import { downloadCsv, toCsv } from '../api/csv.js'

// BILLETS DÉLIVRÉS ET VENTES PAR ARTICLE — l'écran du régisseur, dans Reporting.
//
// Les chiffres viennent des LIGNES DE VENTE validées (`/reporting/billets-delivres`,
// `/reporting/ventes-par-article`), gratuits compris : pas des passages aux portes. L'établissement
// est l'établissement actif ; on ne choisit ici que la période et le regroupement.

const REGROUPEMENTS = [
  { code: 'jour', libelle: 'Jour' },
  { code: 'semaine', libelle: 'Semaine' },
  { code: 'mois', libelle: 'Mois' },
  { code: 'heure', libelle: 'Tranche horaire' },
  { code: 'produit', libelle: 'Produit' },
  { code: 'poste', libelle: 'Poste de caisse' },
  { code: 'pointDeVente', libelle: 'Point de vente' },
]

const isoLocal = (d) => {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}
const euros = (v) => (v == null ? '—' : `${Number.parseFloat(v).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`)
const entier = (v) => Number(v || 0).toLocaleString('fr-FR')

export default function SalesStatistics({ etabActif }) {
  const aujourdHui = new Date()
  const [du, setDu] = useState(isoLocal(new Date(aujourdHui.getFullYear(), aujourdHui.getMonth(), 1)))
  const [au, setAu] = useState(isoLocal(aujourdHui))
  const [vue, setVue] = useState('billets')
  const [par, setPar] = useState('jour')
  const [resultat, setResultat] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [chargement, setChargement] = useState(false)

  useEffect(() => {
    if (!etabActif || !du || !au) return undefined
    let vivant = true
    setChargement(true)
    setErreur(null)
    setResultat(null)
    const lecture = vue === 'billets' ? api.billetsDelivres({ du, au, par }) : api.ventesParArticle({ du, au })
    lecture
      // La réponse garde la vue qui l'a demandée : au changement d'onglet, l'ancienne (d'une autre
      // forme) ne doit pas être rendue dans le nouveau tableau le temps d'un rendu — l'écran plantait.
      .then((r) => { if (vivant) setResultat({ ...r, vue }) })
      .catch((e) => {
        if (!vivant) return
        setErreur(e?.status === 403
          ? 'Votre compte n’a pas accès aux statistiques de cet établissement (droit « reporting.lire »).'
          : (e?.message || 'Les statistiques n’ont pas pu être lues.'))
      })
      .finally(() => { if (vivant) setChargement(false) })
    return () => { vivant = false }
  }, [etabActif, du, au, vue, par])

  function exporter() {
    if (!resultat) return
    const suffixe = `${resultat.du}_${resultat.au}`
    if (resultat.vue === 'billets') {
      const titre = REGROUPEMENTS.find((r) => r.code === resultat.par)?.libelle || 'Regroupement'
      downloadCsv(`billets-delivres_${resultat.par}_${suffixe}.csv`, toCsv([
        { titre, valeur: (l) => l.libelle },
        { titre: 'Billets délivrés', valeur: (l) => l.billets, nombre: true },
      ], [...resultat.lignes, { libelle: 'Total', billets: resultat.total }]))
    } else {
      downloadCsv(`ventes-par-article_${suffixe}.csv`, toCsv([
        { titre: 'Catégorie', valeur: (l) => l.categorie },
        { titre: 'Article', valeur: (l) => l.article },
        { titre: 'Quantité', valeur: (l) => l.quantite, nombre: true },
        { titre: 'Taux TVA (%)', valeur: (l) => l.tauxTva, nombre: true },
        { titre: 'CA HT (€)', valeur: (l) => l.totalHt, nombre: true },
        { titre: 'CA TTC (€)', valeur: (l) => l.totalTtc, nombre: true },
      ], [...resultat.articles, { categorie: 'Total', article: '', quantite: null, tauxTva: null, totalHt: resultat.totalHt, totalTtc: resultat.totalTtc }]))
    }
  }

  return (
    <section className="card stats-ventes">
      <div className="card-h">
        <h3>Billets et ventes</h3>
        <span className="sub">lignes de vente validées, gratuits compris</span>
      </div>
      <div className="card-b">
        <div className="stats-filtres">
          <div className="field">
            <label htmlFor="stats-du">Du</label>
            <input id="stats-du" className="input" type="date" value={du} max={au} onChange={(e) => setDu(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="stats-au">Au</label>
            <input id="stats-au" className="input" type="date" value={au} min={du} onChange={(e) => setAu(e.target.value)} />
          </div>
          <div className="seg" role="group" aria-label="Statistique">
            <button type="button" className={vue === 'billets' ? 'on' : ''} onClick={() => setVue('billets')}>Billets délivrés</button>
            <button type="button" className={vue === 'articles' ? 'on' : ''} onClick={() => setVue('articles')}>Ventes par article</button>
          </div>
          {vue === 'billets' && (
            <div className="field">
              <label htmlFor="stats-par">Regrouper par</label>
              <select id="stats-par" className="input" value={par} onChange={(e) => setPar(e.target.value)}>
                {REGROUPEMENTS.map((r) => <option key={r.code} value={r.code}>{r.libelle}</option>)}
              </select>
            </div>
          )}
          <button className="btn" type="button" onClick={exporter} disabled={!resultat || resultat.vue !== vue || chargement}>
            Exporter (CSV pour Excel)
          </button>
        </div>

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {chargement && <div className="center stats-attente"><div className="spinner" /></div>}

        {resultat?.vue === 'billets' && vue === 'billets' && (
          <table className="tbl">
            <thead>
              <tr>
                <th>{REGROUPEMENTS.find((r) => r.code === resultat.par)?.libelle}</th>
                <th className="num">Billets délivrés</th>
              </tr>
            </thead>
            <tbody>
              {resultat.lignes.map((l) => (
                <tr key={l.cle || l.libelle}><td>{l.libelle}</td><td className="num">{entier(l.billets)}</td></tr>
              ))}
              {resultat.lignes.length === 0 && (
                <tr><td colSpan={2} className="empty">Aucun billet délivré sur cette période.</td></tr>
              )}
            </tbody>
            <tfoot>
              <tr><th>Total</th><th className="num">{entier(resultat.total)}</th></tr>
            </tfoot>
          </table>
        )}

        {resultat?.vue === 'articles' && vue === 'articles' && (
          <>
            {!resultat.htComplet && (
              <div className="banner banner-warn">
                Le taux de TVA de certains articles n’est pas renseigné : leur CA HT est laissé vide,
                et le total HT ne les compte pas. Il se règle dans Comptabilité, par la catégorie
                comptable du produit.
              </div>
            )}
            <table className="tbl">
              <thead>
                <tr>
                  <th>Catégorie</th><th>Article</th><th className="num">Quantité</th>
                  <th className="num">CA HT</th><th className="num">CA TTC</th>
                </tr>
              </thead>
              <tbody>
                {resultat.articles.map((a) => (
                  <tr key={a.produit || a.article}>
                    <td>{a.categorie}</td><td>{a.article}</td><td className="num">{entier(a.quantite)}</td>
                    <td className="num">{euros(a.totalHt)}</td><td className="num">{euros(a.totalTtc)}</td>
                  </tr>
                ))}
                {resultat.articles.length === 0 && (
                  <tr><td colSpan={5} className="empty">Aucune vente sur cette période.</td></tr>
                )}
              </tbody>
              <tfoot>
                {resultat.categories.map((c) => (
                  <tr key={`c-${c.categorie}`}>
                    <th colSpan={2}>Sous-total {c.categorie}</th><th className="num">{entier(c.quantite)}</th>
                    <th className="num">{euros(c.totalHt)}</th><th className="num">{euros(c.totalTtc)}</th>
                  </tr>
                ))}
                <tr>
                  <th colSpan={3}>Total de la période</th>
                  <th className="num">{euros(resultat.totalHt)}</th><th className="num">{euros(resultat.totalTtc)}</th>
                </tr>
              </tfoot>
            </table>
          </>
        )}
      </div>
    </section>
  )
}
