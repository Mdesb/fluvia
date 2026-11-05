import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

// LES BORNES AUTOMATIQUES DE VENTE (#341) — leur réglage, sous Paramètres › Caisse.
//
// Une borne EST un point de vente sans caissier : on lui choisit un point de vente DÉDIÉ (sa chaîne
// NF525, son terminal de paiement, son imprimante), un code de sortie pour le personnel, et ce
// qu'elle propose. Puis on l'appaire : un code court, affiché ici une fois, à saisir sur la borne.
//
// ⚠ LE TERMINAL DOIT ÊTRE PILOTÉ. Le terminal « externe » attend qu'un caissier lise le résultat :
// à une borne, personne ne le ferait. L'écran le dit en face de chaque borne, plutôt que de laisser
// l'exploitant découvrir une borne « hors service » dans le hall.

const DELIVERY = { ticket: 'Ticket imprimé (QR code)', badge: 'Badge encodé (repli : ticket)' }

export default function KioskSettings({ etabActif, droits = [] }) {
  const canRead = aLeDroit(droits, 'kiosk.read')
  const canManage = aLeDroit(droits, 'kiosk.manage')

  // `null` = pas lu · `[]` = lu et vide : « aucune borne » ne se dit que sur une lecture réussie.
  const [kiosks, setKiosks] = useState(null)
  const [pointsOfSale, setPointsOfSale] = useState([])
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [edit, setEdit] = useState(null)
  const [pairing, setPairing] = useState(null) // { kiosk, code, expiresAt }
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    if (!canRead) return
    setError(null)
    const [k, p] = await Promise.allSettled([api.kiosks(), api.pointDeVentes()])
    setKiosks(k.status === 'fulfilled' ? membres(k.value) : null)
    setPointsOfSale(p.status === 'fulfilled' ? membres(p.value) : [])
    if (k.status === 'rejected') setError(k.reason?.message || 'Les bornes n’ont pas pu être lues.')
  }, [canRead])

  useEffect(() => { load() }, [etabActif, load])

  if (!canRead) return null

  const usedPos = new Set((kiosks || []).map((k) => k.pointOfSaleSummary?.id))
  const freePos = pointsOfSale.filter((p) => !usedPos.has(p.id) && p.libelle !== 'Vente directe')

  async function save() {
    setBusy(true)
    setError(null)
    try {
      const body = {
        label: edit.label.trim(),
        active: edit.active,
        rechargeEnabled: edit.rechargeEnabled,
        delivery: edit.delivery,
        idleSeconds: Number(edit.idleSeconds) || 60,
      }
      if (edit.exitCode) body.exitCode = edit.exitCode
      if (edit.id) {
        await api.updateKiosk(edit.id, body)
        setSuccess('Borne mise à jour.')
      } else {
        await api.createKiosk({ ...body, pointOfSale: `/api/point_de_ventes/${edit.pointOfSale}` })
        setSuccess('Borne créée. Appairez-la pour la mettre en service.')
      }
      setEdit(null)
      load()
    } catch (e) {
      setError(e.message || 'L’enregistrement n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  async function pair(kiosk) {
    setError(null)
    setSuccess(null)
    try {
      const res = await api.kioskPairingCode(kiosk.id)
      setPairing({ kiosk: kiosk.label, code: res.code, expiresAt: res.expiresAt })
    } catch (e) {
      setError(e.message)
    }
  }

  async function unpair(kiosk) {
    if (!window.confirm(`Désappairer « ${kiosk.label} » ? L’appareil cessera aussitôt de vendre.`)) return
    try {
      await api.unpairKiosk(kiosk.id)
      setSuccess('Borne désappairée : l’appareil ne vend plus.')
      load()
    } catch (e) {
      setError(e.message)
    }
  }

  const complete = edit && edit.label.trim() !== '' && (edit.id || edit.pointOfSale) && (!edit.exitCode || /^\d{4,8}$/.test(edit.exitCode))

  return (
    <section className="card">
      <div className="card-h">
        <h3>Bornes automatiques de vente</h3>
        <span className="sub">l’usager achète ou recharge seul, et paie par carte</span>
        {canManage && !edit && (
          <div className="r">
            <button
              type="button"
              className="btn primary sm"
              onClick={() => { setSuccess(null); setEdit({ id: null, label: '', pointOfSale: '', active: true, rechargeEnabled: true, delivery: 'ticket', idleSeconds: 60, exitCode: '' }) }}
            >
              ＋ Ajouter une borne
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {error && <div className="banner banner-error">{error}</div>}
        {success && <div className="banner banner-ok">{success}</div>}
        {pairing && (
          <div className="banner banner-ok">
            Code d’appairage de « {pairing.kiosk} » : <b className="mono">{pairing.code}</b>
            <span className="hint">
              Sur la borne, ouvrez l’adresse <span className="mono">/borne</span> et saisissez ce code. Il
              n’est affiché qu’une fois et expire à {new Date(pairing.expiresAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}.
            </span>
            <button type="button" className="btn ghost sm" onClick={() => setPairing(null)}>Masquer</button>
          </div>
        )}

        {kiosks === null ? (
          <div className="banner banner-error">Les bornes n’ont pas pu être lues : n’en concluez pas qu’il n’y en a aucune.</div>
        ) : kiosks.length === 0 && !edit ? (
          <div className="empty">Aucune borne. Une borne a besoin d’un point de vente qui lui est propre : créez-le juste au-dessus.</div>
        ) : (
          kiosks.map((k) => (
            <div key={k.id} className="sup-acces">
              <div className="sup-acces-t">
                <span className="nm">{k.label}</span>
                <span className={`badge${k.active && k.paired ? ' good' : ''}`}>{k.active ? (k.paired ? 'en service' : 'à appairer') : 'désactivée'}</span>
                {canManage && !edit && (
                  <>
                    <button type="button" className="btn ghost sm" onClick={() => setEdit({ ...k, exitCode: '' })}>Modifier</button>
                    {k.active && <button type="button" className="btn ghost sm" onClick={() => pair(k)}>{k.paired ? 'Remplacer l’appareil' : 'Appairer'}</button>}
                    {k.paired && <button type="button" className="btn ghost sm" onClick={() => unpair(k)}>Désappairer</button>}
                  </>
                )}
              </div>
              <div className="hint">
                Point de vente <b>{k.pointOfSaleSummary?.label || '—'}</b> · {DELIVERY[k.delivery] || k.delivery}
                {k.rechargeEnabled ? ' · recharge des cartes' : ''} · retour à l’accueil après {k.idleSeconds} s sans geste
              </div>
              <TerminalHint connector={k.pointOfSaleSummary?.terminalConnector} />
              {!k.hasExitCode && <div className="hint">⚠ Aucun code de sortie : posez-en un avant d’appairer.</div>}
              {k.lastSeenAt && <div className="hint">Dernier signe de vie : {new Date(k.lastSeenAt).toLocaleString('fr-FR')}</div>}
            </div>
          ))
        )}

        {edit && (
          <div className="cpt-form">
            <div className="fiche-sec">{edit.id ? 'Modifier la borne' : 'Ajouter une borne'}</div>
            <label className="field">
              <span className="field-lbl">Nom</span>
              <input className="input" value={edit.label} onChange={(e) => setEdit({ ...edit, label: e.target.value })} placeholder="Borne du hall" />
            </label>
            {!edit.id && (
              <label className="field">
                <span className="field-lbl">Point de vente de la borne</span>
                <select className="select" value={edit.pointOfSale} onChange={(e) => setEdit({ ...edit, pointOfSale: e.target.value })}>
                  <option value="">— choisir —</option>
                  {freePos.map((p) => <option key={p.id} value={p.id}>{p.libelle}</option>)}
                </select>
                <span className="hint">
                  Un point de vente <b>dédié</b>, sans caisse : ses ventes, son terminal de paiement piloté et ses
                  moyens de paiement sont ceux de la borne. Il ne se change plus ensuite.
                </span>
              </label>
            )}
            <label className="field">
              <span className="field-lbl">Code de sortie du personnel</span>
              <input
                className="input num"
                inputMode="numeric"
                type="password"
                autoComplete="new-password"
                value={edit.exitCode}
                onChange={(e) => setEdit({ ...edit, exitCode: e.target.value.replace(/\D/g, '').slice(0, 8) })}
                placeholder={edit.hasExitCode ? 'inchangé' : '4 à 8 chiffres'}
              />
              <span className="hint">Appui long de 3 secondes dans le coin haut gauche de la borne, puis ce code. Il est vérifié par le serveur ; cinq erreurs ferment la porte cinq minutes.</span>
            </label>
            <label className="field">
              <span className="field-lbl">Ce que la borne remet</span>
              <select className="select" value={edit.delivery} onChange={(e) => setEdit({ ...edit, delivery: e.target.value })}>
                {Object.entries(DELIVERY).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </label>
            <label className="field">
              <span className="field-lbl">Retour à l’accueil sans geste (secondes)</span>
              <input className="input num" type="number" min="20" max="600" value={edit.idleSeconds} onChange={(e) => setEdit({ ...edit, idleSeconds: e.target.value })} />
            </label>
            <label className="cpt-mode">
              <input type="checkbox" checked={edit.rechargeEnabled} onChange={(e) => setEdit({ ...edit, rechargeEnabled: e.target.checked })} />
              <span>Recharge des cartes multi-entrées</span>
            </label>
            <label className="cpt-mode">
              <input type="checkbox" checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} />
              <span>Borne active</span>
            </label>
            <p className="hint">
              Les produits proposés sont ceux qui portent le canal « borne » dans leur fiche, au tarif visible à la
              borne. Les produits nominatifs restent au guichet.
            </p>
            <div className="ticket-actions">
              <button type="button" className="btn primary" disabled={busy || !complete} onClick={save}>{busy ? 'Enregistrement…' : 'Enregistrer'}</button>
              <button type="button" className="btn ghost" onClick={() => setEdit(null)}>Annuler</button>
            </div>
          </div>
        )}
      </div>
    </section>
  )
}

function TerminalHint({ connector }) {
  if (connector === 'externe' || connector == null) {
    return (
      <div className="hint">
        ⚠ <b>Terminal de paiement non piloté</b> : la borne restera hors service. Réglez sur son point de vente un
        terminal relié (Caisse-AP, Nepting…) — le terminal « externe » attend un caissier.
      </div>
    )
  }
  if (connector === 'simule') {
    return <div className="hint">Terminal simulé : démonstration seulement, aucun débit réel.</div>
  }
  return null
}
