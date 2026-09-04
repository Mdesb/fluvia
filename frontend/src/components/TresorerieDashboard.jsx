import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client.js'
import { euros } from '../api/produit.js'
import { jourLocal } from './Liste.jsx'

/**
 * TABLEAU DE BORD TRÉSORERIE (T26, suite) — position, prévision, échéancier, écarts.
 *
 * Quatre lectures live (jamais persistées côté serveur), bornées au périmètre de l'utilisateur :
 *  - Position : solde à une date, total et par compte.
 *  - Prévision : solde projeté à 7 / 30 / 90 jours (position ± flux attendus).
 *  - Échéancier : ce qui doit sortir (factures fournisseur) et entrer (factures client, remises SEPA).
 *  - Écarts : les lignes de relevé qui traînent non rapprochées, triées par ancienneté.
 */
const SOURCE = {
  supplier_invoice: 'Facture fournisseur',
  invoice: 'Facture client',
  sepa_remise: 'Remise SEPA',
}
const cents = (c) => euros((c ?? 0) / 100)

export default function TresorerieDashboard() {
  return (
    <>
      <Position />
      <Prevision />
      <Echeancier />
      <Ecarts />
    </>
  )
}

function Position() {
  const aujourdhui = jourLocal()
  const [asOf, setAsOf] = useState(aujourdhui)
  const [data, setData] = useState(null)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(async () => {
    setErreur(null)
    try {
      setData(await api.positionTresorerie({ asOf }))
    } catch (e) {
      setErreur(e.message)
    }
  }, [asOf])

  useEffect(() => { charger() }, [charger])

  return (
    <section className="card">
      <div className="card-h">
        <h3>Position</h3>
        <span className="sub">Solde bancaire à une date</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="field" style={{ maxWidth: '16rem' }}>
          <label htmlFor="pos-asof">Au</label>
          <input id="pos-asof" className="input" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
        </div>
        {data && (
          <>
            <p className="hint" style={{ marginTop: 'var(--esp-large)' }}>
              Solde total au {data.asOfDate} : <b style={{ fontSize: '1.4rem', color: 'var(--ink)' }}>{euros(data.balance)}</b>
            </p>
            {(data.perAccount || []).length > 0 && (
              <table className="tbl">
                <thead><tr><th>Compte</th><th className="num">Solde</th></tr></thead>
                <tbody>
                  {data.perAccount.map((c) => (
                    <tr key={c.bankAccountId}>
                      <td><span className="nm">{c.label}</span></td>
                      <td className="num">{euros(c.balance)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </>
        )}
      </div>
    </section>
  )
}

const HORIZONS = [7, 30, 90]

function Prevision() {
  const [horizon, setHorizon] = useState(30)
  const [data, setData] = useState(null)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(async () => {
    setErreur(null)
    try {
      setData(await api.previsionTresorerie({ horizonDays: horizon }))
    } catch (e) {
      setErreur(e.message)
    }
  }, [horizon])

  useEffect(() => { charger() }, [charger])

  return (
    <section className="card">
      <div className="card-h">
        <h3>Prévision</h3>
        <span className="sub">Solde projeté, position ± flux attendus</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="seg" role="group" aria-label="Horizon de prévision">
          {HORIZONS.map((h) => (
            <button key={h} type="button" className={h === horizon ? 'on' : ''} onClick={() => setHorizon(h)}>{h} jours</button>
          ))}
        </div>
        {data && (
          <p className="hint" style={{ marginTop: 'var(--esp-large)' }}>
            À {data.horizonDays} jours (depuis le {data.asOfDate}), solde projeté :{' '}
            <b style={{ fontSize: '1.4rem', color: 'var(--ink)' }}>{euros(data.projectedBalance)}</b>
          </p>
        )}
      </div>
    </section>
  )
}

function Echeancier() {
  const aujourdhui = jourLocal()
  const dans30 = jourLocal(Date.now() + 30 * 864e5)
  const [from, setFrom] = useState(aujourdhui)
  const [to, setTo] = useState(dans30)
  const [data, setData] = useState(null)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(async () => {
    setErreur(null)
    try {
      setData(await api.echeancierTresorerie({ from, to }))
    } catch (e) {
      setErreur(e.message)
    }
  }, [from, to])

  useEffect(() => { charger() }, [charger])

  const total = (liste) => (liste || []).reduce((s, x) => s + (x.amountCents ?? 0), 0)

  return (
    <section className="card">
      <div className="card-h">
        <h3>Échéancier</h3>
        <span className="sub">Sorties et entrées attendues sur la période</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="row row-champs" style={{ gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: 1, minWidth: '10rem', marginBottom: 0 }}>
            <label htmlFor="ech-from">Du</label>
            <input id="ech-from" className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div className="field" style={{ flex: 1, minWidth: '10rem', marginBottom: 0 }}>
            <label htmlFor="ech-to">Au</label>
            <input id="ech-to" className="input" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
        </div>
        {data && (
          <div className="row" style={{ gap: 'var(--esp-large)', alignItems: 'flex-start', flexWrap: 'wrap', marginTop: 'var(--esp-large)' }}>
            <div style={{ flex: 1, minWidth: '18rem' }}>
              <ListeFlux titre="Sorties" sous={cents(total(data.exits))} lignes={data.exits} signe="−" />
            </div>
            <div style={{ flex: 1, minWidth: '18rem' }}>
              <ListeFlux titre="Entrées" sous={cents(total(data.entries))} lignes={data.entries} signe="+" />
            </div>
          </div>
        )}
      </div>
    </section>
  )
}

function ListeFlux({ titre, sous, lignes, signe }) {
  return (
    <>
      <div className="row" style={{ justifyContent: 'space-between', alignItems: 'baseline' }}>
        <b>{titre}</b><span className="sub">{signe} {sous}</span>
      </div>
      {(lignes || []).length === 0 ? (
        <div className="empty">Rien sur la période.</div>
      ) : (
        <table className="tbl">
          <thead><tr><th>Date</th><th>Origine</th><th className="num">Montant</th></tr></thead>
          <tbody>
            {lignes.map((l) => (
              <tr key={`${l.source}-${l.sourceId}`}>
                <td className="mono">{l.date || '—'}</td>
                <td>{SOURCE[l.source] || l.source}</td>
                <td className="num">{signe} {cents(l.amountCents)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  )
}

function Ecarts() {
  const [lignes, setLignes] = useState(null)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    let vivant = true
    api.ecartsTresorerie()
      .then((r) => { if (vivant) setLignes(Array.isArray(r) ? r : []) })
      .catch((e) => { if (vivant) { setErreur(e.message); setLignes([]) } })
    return () => { vivant = false }
  }, [])

  const triees = (lignes || []).slice().sort((a, b) => (b.unmatchedSinceDays ?? 0) - (a.unmatchedSinceDays ?? 0))

  return (
    <section className="card">
      <div className="card-h">
        <h3>Écarts</h3>
        <span className="sub">Lignes non rapprochées, par ancienneté</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {lignes === null ? (
          <div className="center"><div className="spinner" /></div>
        ) : triees.length === 0 ? (
          <div className="empty">Aucun écart : tout est rapproché ou ignoré.</div>
        ) : (
          <table className="tbl">
            <thead>
              <tr><th>Date</th><th>Libellé</th><th className="num">Montant</th><th className="num">En attente</th></tr>
            </thead>
            <tbody>
              {triees.map((l) => (
                <tr key={l.id}>
                  <td className="mono">{l.operationDate || '—'}</td>
                  <td><span className="nm">{l.label}</span></td>
                  <td className="num">{euros(l.amount)}</td>
                  <td className="num">
                    <span className={`badge ${l.unmatchedSinceDays >= 30 ? 'crit' : l.unmatchedSinceDays >= 7 ? 'warn' : 'mut'}`}>
                      {l.unmatchedSinceDays} j
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}
