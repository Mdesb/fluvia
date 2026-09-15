import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

/**
 * LES CONSENTEMENTS D'UN CLIENT — on pouvait les écrire, jamais les relire ni les poser.
 *
 * `POST /clients/{id}/consentements` existe depuis l'origine ; aucune fonction cliente ne
 * l'appelait, donc aucun bouton. Et `GET /consentements` n'était pas davantage lu : la fiche ne
 * disait pas si ce client avait accepté d'être contacté, ni par quel canal, ni depuis quand.
 *
 * ⚠ ÉCRIRE SANS POUVOIR RELIRE EST UNE DEMI-FONCTION. On pose donc les deux : l'historique
 * d'abord, le geste ensuite. Un exploitant qui enregistre un consentement sans voir les précédents
 * en crée un doublon contradictoire sans le savoir.
 */

const CANAUX = [['email', 'Courriel'], ['sms', 'SMS'], ['courrier', 'Courrier postal']]
const ETATS = [
  ['accorde', 'Accordé'],
  ['refuse', 'Refusé'],
  ['a_renouveler', 'À renouveler'],
  ['expire', 'Expiré'],
]

const LIB_CANAL = Object.fromEntries(CANAUX)
const LIB_ETAT = Object.fromEntries(ETATS)

function jour(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

export default function ConsentementsClient({ client, droits = [], onOuvrir, enEcran = false, onFermer, onEnregistre }) {
  // ⚠ TROIS ÉTATS. `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  // Une liste vide affichée sur un refus dirait « ce client n'a jamais rien accepté », ce qui est
  // exactement la conclusion inverse de « je n'en sais rien » — et sur du consentement, la
  // différence est juridique avant d'être ergonomique.
  const [liste, setListe] = useState(null)
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  const peutLire = aLeDroit(droits, 'crm.lire')

  function charger() {
    if (!client?.id || !peutLire) { setListe(undefined); return }
    setListe(null)
    api
      .consentementsClient(client.id)
      .then((r) => setListe(membres(r)))
      .catch(() => setListe(undefined))
  }

  useEffect(charger, [client?.id, peutLire])

  // ── EN ÉCRAN : LE FORMULAIRE SEUL ─────────────────────────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LA CONDITION DU BOUTON ET L'ÉCRAN LA REPREND (la lecture CRM, et elle seule :
  // le bouton n'en exigeait pas d'autre). L'échec d'envoi a sa bannière ici, celle de la carte n'y
  // étant pas rendue.
  if (enEcran) {
    if (!peutLire) {
      return (
        <div className="banner banner-warn">
          Enregistrer un consentement demande l’accès au fichier client, que ce compte n’a pas.
        </div>
      )
    }
    return (
      <>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <EnregistrerConsentement
          client={client}
          open
          onClose={onFermer}
          onFait={(message) => onEnregistre(message)}
          onErreur={setErreur}
        />
      </>
    )
  }

  if (!peutLire) return null

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <span>Consentements</span>
        <button
          className="btn ghost sm"
          type="button"
          style={{ marginLeft: 'auto' }}
          onClick={() => { setErreur(null); setSucces(null); onOuvrir() }}
        >
          Enregistrer un consentement
        </button>
      </div>

      {erreur && <div className="banner banner-error" style={{ margin: 'var(--esp-large)' }}>{erreur}</div>}
      {succes && <div className="banner" style={{ margin: 'var(--esp-large)' }}>{succes}</div>}

      {liste === null && <div className="empty">Lecture des consentements…</div>}

      {liste === undefined && (
        <div className="banner banner-warn" style={{ margin: 'var(--esp-large)' }}>
          Les consentements n’ont pas pu être lus. Cet écran ne sait donc pas ce que ce client a
          accepté — ce n’est pas la même chose que « il n’a rien accepté ».
        </div>
      )}

      {Array.isArray(liste) && liste.length === 0 && (
        <div className="empty">Aucun consentement enregistré pour ce client.</div>
      )}

      {Array.isArray(liste) && liste.length > 0 && (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Canal</th>
                <th>État</th>
                <th>Recueilli le</th>
                <th>Expire le</th>
                <th>Source</th>
              </tr>
            </thead>
            <tbody>
              {liste.map((c) => (
                <tr key={c.id}>
                  <td>{LIB_CANAL[c.canal] || c.canal}</td>
                  <td>
                    <span className={`badge ${c.etat === 'accorde' ? 'good' : c.etat === 'refuse' ? 'crit' : 'warn'}`}>
                      {LIB_ETAT[c.etat] || c.etat}
                    </span>
                    {c.recueilliParRepresentant && (
                      <span className="sub" style={{ marginLeft: 6 }}>par le représentant légal</span>
                    )}
                  </td>
                  <td>{jour(c.dateRecueil)}</td>
                  <td>{jour(c.dateExpiration)}</td>
                  <td className="sub">{c.source || 'inconnue'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

/**
 * ⚠ LA RÈGLE DES MINEURS EST PORTÉE PAR L'ÉCRAN, PAS DÉCOUVERTE PAR UNE ERREUR.
 *
 * RG-M4-10 : un consentement ACCORDÉ pour un client MINEUR exige d'avoir été recueilli auprès du
 * représentant légal. Le serveur rend 422 sinon — et un 422 arrive APRÈS qu'on a tout saisi, sans
 * dire qu'il fallait cocher une case qu'on n'avait pas remarquée.
 *
 * La fiche sait déjà qui est mineur : `estMineur` est exposé et affiché en pastille sur la liste.
 * L'exigence apparaît donc au moment où elle naît — quand on choisit « Accordé » — et le bouton
 * reste inerte tant qu'elle n'est pas satisfaite, avec la raison écrite à côté.
 */
function EnregistrerConsentement({ client, open, onClose, onFait, onErreur }) {
  const [canal, setCanal] = useState('')
  const [etat, setEtat] = useState('')
  const [source, setSource] = useState('')
  const [expiration, setExpiration] = useState('')
  const [representant, setRepresentant] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    setCanal(''); setEtat(''); setSource(''); setExpiration(''); setRepresentant(false)
  }, [open])

  const mineur = !!client?.estMineur
  const representantExige = mineur && etat === 'accorde'
  const pret = canal !== '' && etat !== '' && (!representantExige || representant)

  async function envoyer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = { canal, etat, recueilliParRepresentant: representant }
      // `source` a une valeur par defaut cote serveur (« inconnue ») : on n'envoie le champ que
      // lorsqu'il porte quelque chose, plutot que d'ecraser ce defaut par une chaine vide.
      if (source.trim() !== '') corps.source = source.trim()
      if (expiration !== '') corps.dateExpiration = expiration
      await api.enregistrerConsentement(client.id, corps)
      onFait('Consentement enregistré.')
    } catch (e) {
      onErreur(e.message || 'Le consentement n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  if (!open) return null

  return (
    <>
      <h2>Enregistrer un consentement</h2>
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div className="sub">
          Ce que ce client accepte de recevoir, par quel canal, et depuis quand. C’est cette trace
          qu’on produit si le consentement est contesté.
        </div>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Canal</span>
          <select className="select" value={canal} onChange={(e) => setCanal(e.target.value)}>
            <option value="">— choisir —</option>
            {CANAUX.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">État</span>
          <select className="select" value={etat} onChange={(e) => setEtat(e.target.value)}>
            <option value="">— choisir —</option>
            {ETATS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </label>

        {mineur && (
          <div className={representantExige && !representant ? 'banner banner-warn' : 'banner'}>
            <b>Ce client est mineur.</b>{' '}
            {representantExige
              ? 'Un consentement accordé doit avoir été recueilli auprès du représentant légal (RG-M4-10). Cochez la case ci-dessous, ou le serveur refusera l’enregistrement.'
              : 'Un consentement accordé exigera le recueil auprès du représentant légal.'}
          </div>
        )}

        <label style={{ display: 'flex', gap: 'var(--esp-normal)', alignItems: 'baseline' }}>
          <input
            type="checkbox"
            checked={representant}
            onChange={(e) => setRepresentant(e.target.checked)}
          />
          <span>Recueilli auprès du représentant légal</span>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Source — d’où vient ce consentement</span>
          <input
            className="input"
            value={source}
            onChange={(e) => setSource(e.target.value)}
            placeholder="guichet, formulaire papier, inscription en ligne…"
            maxLength={120}
          />
          <span className="sub">Laissé vide, le serveur enregistre « inconnue ».</span>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Expire le — facultatif</span>
          <input
            className="input"
            type="date"
            value={expiration}
            onChange={(e) => setExpiration(e.target.value)}
          />
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="button" disabled={busy || !pret} onClick={envoyer}>
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </>
  )
}
