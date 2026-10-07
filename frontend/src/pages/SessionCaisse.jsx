import { useEffect, useMemo, useState } from 'react'
import { aLeDroit } from '../api/droits.js'
import { api, membres } from '../api/client.js'
import { mot } from '../api/vocabulaire.js'
import { euros } from '../api/produit.js'

// Gestion de la session de caisse (M2) : ouverture (point de vente, caisse, fond, régisseur)
// et clôture Z (comptages, écart de régie, versement, fond reporté).
// Rendu en modale depuis l'écran Caisse (`modale`) ou en page autonome (par défaut).
export default function SessionCaisse({ me, etabActif, session, droits = [], onRefresh, modale = false, onClose }) {
  // ⚠ CET ECRAN NE RECEVAIT PAS `droits` DU TOUT, ET IL PORTE LES DEUX GESTES QUI ENCADRENT UNE
  // JOURNEE DE GUICHET. Le serveur exige `caisse.ouvrir` sur l'ouverture et `caisse.cloturer` sur
  // la cloture (`Caisse/Entity/SessionCaisse.php`) — deux droits que des roles reels separent :
  // « Role Caissier = caisse.cloturer uniquement », dit `CaisseClotureRoleFixtures`.
  //
  // On ne cache pas les boutons : savoir que le geste existe fait partie du travail, et un guichet
  // qui n'ouvre pas doit pouvoir dire quoi demander.
  const peutOuvrir = aLeDroit(droits, 'caisse.ouvrir')
  const peutCloturer = aLeDroit(droits, 'caisse.cloturer')
  const [pdvs, setPdvs] = useState([])
  const [caisses, setCaisses] = useState([])
  const [moyens, setMoyens] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  // Ouverture
  const [pdvId, setPdvId] = useState('')
  const [caisseId, setCaisseId] = useState('')
  const [fond, setFond] = useState('50.00')
  const [ouverture, setOuverture] = useState(false)

  // Clôture
  const [comptages, setComptages] = useState([{ moyen: 'especes', compte: '' }])
  const [versement, setVersement] = useState('')
  const [fondReporte, setFondReporte] = useState('')
  const [cloture, setCloture] = useState(false)
  const [recapZ, setRecapZ] = useState(null)

  useEffect(() => {
    let annule = false
    setChargement(true)
    setErreur(null)
    setRecapZ(null)
    Promise.all([api.pointDeVentes(), api.caisses(), api.moyensPaiement()])
      .then(([p, c, m]) => {
        if (annule) return
        const lp = membres(p)
        const lc = membres(c)
        setPdvs(lp)
        setCaisses(lc)
        setMoyens(membres(m).filter((x) => x.actif !== false))
        if (lp[0]) setPdvId(lp[0].id)
      })
      .catch((e) => !annule && setErreur(e.message))
      .finally(() => !annule && setChargement(false))
    return () => {
      annule = true
    }
  }, [etabActif])

  const caissesDuPdv = useMemo(
    () => caisses.filter((c) => (c.pointDeVente?.id || c.pointDeVente) === pdvId),
    [caisses, pdvId],
  )

  useEffect(() => {
    // Sélection auto de la 1re caisse du point de vente choisi.
    if (caissesDuPdv[0]) setCaisseId(caissesDuPdv[0].id)
    else setCaisseId('')
  }, [pdvId, caissesDuPdv])

  async function ouvrir(e) {
    e.preventDefault()
    setErreur(null)
    // IL REPROCHAIT DEUX CHAMPS QUAND UN SEUL MANQUAIT, ET CELUI-LA ETAIT IMPOSSIBLE A REMPLIR.
    //
    // << Point de vente et caisse requis >> s'affichait alors que le point de vente etait bien
    // choisi : l'exploitant cherchait ce qu'il avait mal fait sur un champ correct, pendant que le
    // vrai manque -- aucune caisse n'existe -- n'etait dit nulle part. Et il ne pouvait pas la
    // creer : `POST /api/caisses` n'etait appele d'aucun ecran.
    //
    // Un refus nomme CE QUI MANQUE, un seul champ a la fois. Et il arrive maintenant AVANT le clic,
    // en desactivant le bouton : voir `manque` plus bas.
    if (!pdvId) {
      setErreur('Choisissez un point de vente.')
      return
    }
    if (!caisseId) {
      setErreur('Aucune caisse n’est rattachée à ce point de vente : créez-en une dans Paramètres › Caisse & moyens de paiement.')
      return
    }
    setOuverture(true)
    try {
      await api.ouvrirSession({
        pointDeVente: pdvId,
        caisse: caisseId,
        fondDeCaisse: Number(fond || 0).toFixed(2),
        // Pas de code régisseur à l'ouverture : l'opérateur connecté est le régisseur par défaut.
      })
      await onRefresh()
      // En modale : une fois la session ouverte, on rend la main à l'écran Caisse.
      if (modale) onClose?.()
    } catch (err) {
      setErreur(err.message || "Échec de l'ouverture de session.")
    } finally {
      setOuverture(false)
    }
  }

  function majComptage(i, champ, val) {
    setComptages((c) => c.map((l, idx) => (idx === i ? { ...l, [champ]: val } : l)))
  }
  function ajouterComptage() {
    const restants = moyens.map((m) => m.code).filter((code) => !comptages.some((c) => c.moyen === code))
    setComptages((c) => [...c, { moyen: restants[0] || 'especes', compte: '' }])
  }
  function retirerComptage(i) {
    setComptages((c) => c.filter((_, idx) => idx !== i))
  }

  async function cloturerZ(e) {
    e.preventDefault()
    if (!session) return
    setErreur(null)
    setCloture(true)
    try {
      const corps = {
        comptages: comptages
          .filter((c) => c.compte !== '')
          .map((c) => ({ moyen: c.moyen, compte: Number(c.compte || 0).toFixed(2) })),
        versement: Number(versement || 0).toFixed(2),
      }
      if (fondReporte !== '') corps.fondReporte = Number(fondReporte).toFixed(2)
      const res = await api.cloturerSession(session.id, corps)
      setRecapZ(res)
      setComptages([{ moyen: 'especes', compte: '' }])
      setVersement('')
      setFondReporte('')
      await onRefresh()
    } catch (err) {
      setErreur(err.message || 'Échec de la clôture Z.')
    } finally {
      setCloture(false)
    }
  }

  const libMoyen = (code) => moyens.find((m) => m.code === code)?.libelle || code

  // --- Champs partagés (identiques en modale et en page) ---
  const champsOuverture = (
    <>
      <div className="field">
        <label htmlFor="pdv">Point de vente</label>
        <select id="pdv" className="select" value={pdvId} onChange={(e) => setPdvId(e.target.value)}>
          {pdvs.length === 0 && <option value="">Aucun point de vente</option>}
          {pdvs.map((p) => (
            <option key={p.id} value={p.id}>{p.libelle}</option>
          ))}
        </select>
      </div>
      <div className="field">
        <label htmlFor="cai">Caisse</label>
        {/* << Aucune caisse >> ETAIT UNE OPTION DU MENU, SANS VALEUR : un texte de remplissage
            deguise en choix. On le lit comme une caisse qu'on aurait selectionnee, et le bouton
            restait actif par-dessus. Une liste vide se dit AU-DESSUS de la liste, jamais dedans. */}
        {caissesDuPdv.length === 0 ? (
          <div className="banner banner-warn" style={{ marginTop: 4 }}>
            Aucune caisse n’est rattachée à ce point de vente. Une caisse, c’est le tiroir et le poste
            depuis lesquels on encaisse : sans elle, aucune session ne peut s’ouvrir et rien ne peut
            être vendu. Elle se crée dans <b>Paramètres › Caisse &amp; moyens de paiement</b>.
          </div>
        ) : (
          <select id="cai" className="select" value={caisseId} onChange={(e) => setCaisseId(e.target.value)}>
            {caissesDuPdv.map((c) => (
              <option key={c.id} value={c.id}>
                {c.libelle}{c.etat ? ` — ${mot(c.etat)}` : ''}
              </option>
            ))}
          </select>
        )}
      </div>
      <div className="field">
        <label htmlFor="fond">Fond de caisse</label>
        <input id="fond" className="input" type="number" step="0.01" min="0"
          value={fond} onChange={(e) => setFond(e.target.value)} required />
      </div>
      <div className="hint" style={{ marginBottom: 14 }}>
        Régisseur : <b>{me?.nom || me?.email}</b>. Aucun code n'est requis à l'ouverture.
      </div>
      <button
        className="btn primary lg"
        type="submit"
        disabled={ouverture || !pdvId || !caisseId || !peutOuvrir}
        title={peutOuvrir
          ? undefined
          : 'Ce compte n’a pas le droit d’ouvrir une caisse (caisse.ouvrir). Ce n’est pas une panne : demandez-le à un administrateur.'}
      >
        {ouverture ? 'Ouverture…' : 'Ouvrir la caisse'}
      </button>
    </>
  )

  const champsCloture = session && (
    <>
      <label className="field-lbl">Comptages (montants comptés)</label>
      {comptages.map((c, i) => (
        <div className="cz-row" key={i}>
          <select
            className="select"
            value={c.moyen}
            onChange={(e) => majComptage(i, 'moyen', e.target.value)}
          >
            {moyens.map((m) => (
              <option key={m.code} value={m.code}>{m.libelle}</option>
            ))}
          </select>
          <input
            className="input num"
            type="number"
            step="0.01"
            min="0"
            placeholder="0.00"
            value={c.compte}
            onChange={(e) => majComptage(i, 'compte', e.target.value)}
          />
          <button type="button" className="rm" title="Retirer" onClick={() => retirerComptage(i)}>×</button>
        </div>
      ))}
      <button type="button" className="btn ghost sm" onClick={ajouterComptage}>＋ Ajouter un moyen</button>

      <div className="grid g2" style={{ marginTop: 14 }}>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="vers">Versement</label>
          <input id="vers" className="input" type="number" step="0.01" min="0" placeholder="0.00"
            value={versement} onChange={(e) => setVersement(e.target.value)} />
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="fr">Fond reporté</label>
          <input id="fr" className="input" type="number" step="0.01" min="0"
            placeholder={session.fondDeCaisse || '0.00'}
            value={fondReporte} onChange={(e) => setFondReporte(e.target.value)} />
        </div>
      </div>

      <button
        className="btn primary lg"
        type="submit"
        disabled={cloture || !peutCloturer}
        title={peutCloturer
          ? undefined
          : 'Ce compte n’a pas le droit de clôturer une caisse (caisse.cloturer). Ce n’est pas une panne : demandez-le à un administrateur.'}
        style={{ marginTop: 14 }}
      >
        {cloture ? 'Clôture…' : 'Clôturer la journée (Z)'}
      </button>
      <div className="hint">
        Le « Z » est le comptage de fin de journée (terme de caisse NF525). Sans comptage saisi, chaque
        moyen est réputé conforme. La clôture scelle la session (NF525) : elle est irréversible.
      </div>
    </>
  )

  const infoSession = session && (
    <dl className="deflist">
      <div><dt>N° session</dt><dd className="mono">{session.numero || '—'}</dd></div>
      <div><dt>Point de vente</dt><dd>{session.pointDeVente?.libelle || '—'}</dd></div>
      <div><dt>Caisse</dt><dd>{session.caisse?.libelle || '—'}</dd></div>
      <div><dt>Fond de caisse</dt><dd className="num">{euros(session.fondDeCaisse)}</dd></div>
      <div>
        <dt>Ouverte le</dt>
        <dd>{session.ouvertureLe ? new Date(session.ouvertureLe).toLocaleString('fr-FR') : '—'}</dd>
      </div>
    </dl>
  )

  const afficherOuverture = !session && !(modale && recapZ)

  // --- Corps : agencé sobrement en modale, en cartes en page autonome ---
  let corps
  if (chargement) {
    corps = (
      <div className="center" style={{ minHeight: modale ? 120 : 200 }}>
        <div className="spinner" />
      </div>
    )
  } else if (session) {
    const formCloture = (
      <form onSubmit={cloturerZ} className={modale ? undefined : 'card'}>
        {!modale && <div className="card-h"><h3>Clôture Z (comptage de fin de journée)</h3><span className="sub">irréversible</span></div>}
        {modale ? champsCloture : <div className="card-b">{champsCloture}</div>}
      </form>
    )
    corps = modale ? (
      <>
        <div style={{ marginBottom: 16 }}>{infoSession}</div>
        {formCloture}
      </>
    ) : (
      <div className="grid g2">
        <div className="card">
          <div className="card-h">
            <h3>Session ouverte</h3>
            <span className="badge good" style={{ marginLeft: 'auto' }}>● {session.etat}</span>
          </div>
          <div className="card-b">{infoSession}</div>
        </div>
        {formCloture}
      </div>
    )
  } else if (afficherOuverture) {
    corps = (
      <form onSubmit={ouvrir} className={modale ? undefined : 'card'} style={modale ? undefined : { maxWidth: 620 }}>
        {!modale && <div className="card-h"><h3>Ouvrir une session</h3></div>}
        {modale ? champsOuverture : <div className="card-b">{champsOuverture}</div>}
      </form>
    )
  }

  const recapBloc = recapZ && (
    <div className="card" style={{ marginTop: 16, borderColor: 'var(--accent)' }}>
      <div className="card-h">
        <span className="ticket-ok">✓</span>
        <h3>Clôture Z — {recapZ.session}</h3>
        <span className="badge mut" style={{ marginLeft: 'auto' }}>{recapZ.etatSession}</span>
      </div>
      <div className="card-b">
        <div className="grid g4" style={{ marginBottom: 16 }}>
          <div className="kpi"><div className="lbl">Total ventes</div><div className="val">{euros(recapZ.totalVentes)}</div></div>
          <div className="kpi"><div className="lbl">Remboursements</div><div className="val">{euros(recapZ.totalRemboursements)}</div></div>
          <div className="kpi"><div className="lbl">Versement</div><div className="val">{euros(recapZ.versement)}</div></div>
          <div className="kpi">
            <div className="lbl">Écart total</div>
            <div className="val" style={{ color: parseFloat(recapZ.ecartTotal) === 0 ? 'var(--good)' : 'var(--crit)' }}>
              {euros(recapZ.ecartTotal)}
            </div>
          </div>
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr><th>Moyen</th><th className="num">Théorique</th><th className="num">Compté</th><th className="num">Écart</th></tr>
            </thead>
            <tbody>
              {(recapZ.comptages || []).map((c, i) => (
                <tr key={i}>
                  <td><span className="nm">{libMoyen(c.moyen)}</span></td>
                  <td className="num">{euros(c.theorique)}</td>
                  <td className="num">{euros(c.compte)}</td>
                  <td className="num" style={{ color: parseFloat(c.ecart) === 0 ? 'var(--ink-soft)' : 'var(--crit)' }}>
                    {euros(c.ecart)}
                  </td>
                </tr>
              ))}
              {(recapZ.comptages || []).length === 0 && (
                <tr><td colSpan={4} className="empty">Aucun mouvement.</td></tr>
              )}
            </tbody>
          </table>
        </div>
        <div className="hint">Fond reporté : {euros(recapZ.fondReporte)} · état de régie archivé et ré-imprimable.</div>
      </div>
    </div>
  )

  // Rendu en modale : pas d'enrobage « view », le Modal fournit titre et cadre.
  if (modale) {
    return (
      <>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {corps}
        {recapBloc}
      </>
    )
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Caisse : session / Z</h1>
          <p>Ouverture, suivi et clôture Z (comptage de fin de journée) de la session de caisse</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {corps}
      {recapBloc}
    </div>
  )
}
