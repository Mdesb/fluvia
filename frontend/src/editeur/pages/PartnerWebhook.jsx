import { useState } from 'react'
import { api } from '../../api/client.js'
import Modal from '../../components/Modal.jsx'
import { confirmer } from '../../components/Confirmation.jsx'

// LES WEBHOOKS D'UNE APPLICATION PARTENAIRE (spec API partenaire v1, §3.3).
//
// ⚠ L'URL ET LE SECRET NE SE RELISENT PAS. Ils sont chiffrés au repos ; l'écran ne montre que l'hôte.
// Le secret de signature n'apparaît qu'une fois, à la création ou à la régénération, et vit ici dans
// un état local qu'on referme.
//
// ⚠ UN ABONNEMENT NE SUFFIT PAS : seuls les établissements qui ont accordé « events:subscribe » à
// l'application reçoivent ses événements, et l'accord est revérifié à chaque tentative.
export default function PartnerWebhook({ application, onChange }) {
  const w = application.webhook
  const [edition, setEdition] = useState(false)
  const [secret, setSecret] = useState(null)
  const [erreur, setErreur] = useState(null)

  async function appel(fn) {
    setErreur(null)
    try {
      const fiche = await fn()
      if (fiche?.issuedWebhookSecret) setSecret(fiche.issuedWebhookSecret)
      onChange()
    } catch (e) {
      setErreur(e.message || 'Le geste n’a pas abouti.')
    }
  }

  async function regenerer() {
    if (!(await confirmer({
      titre: `Régénérer le secret des webhooks de « ${application.name} » ?`,
      consequence: 'L’ancien secret cesse de signer immédiatement : le partenaire refusera nos envois tant qu’il n’aura pas le nouveau.',
      libelleOk: 'Régénérer',
      danger: true,
    }))) return
    await appel(() => api.regenererSecretWebhookPartenaire(application.id))
  }

  async function couper() {
    if (!(await confirmer({
      titre: `Couper les webhooks de « ${application.name} » ?`,
      consequence: 'Plus aucun événement ne lui sera envoyé. Les livraisons déjà tracées restent lisibles.',
      libelleOk: 'Couper',
      danger: true,
    }))) return
    await appel(() => api.couperWebhookPartenaire(application.id))
  }

  return (
    <div className="card">
      <div className="card-h">
        Webhooks
        {application.active && (
          <button type="button" className="btn sm" onClick={() => setEdition(true)}>{w ? 'Modifier' : 'Configurer'}</button>
        )}
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {secret && (
          <div className="banner banner-warn" role="status">
            <p>Secret de signature : copiez-le maintenant, il ne sera <b>plus jamais affiché</b>.</p>
            <p className="mono">{secret}</p>
            <button type="button" className="btn sm" onClick={() => navigator.clipboard?.writeText(secret)}>Copier</button>
            <button type="button" className="btn ghost sm" onClick={() => setSecret(null)}>J’ai copié le secret</button>
          </div>
        )}
        {!w && <div className="hint">Aucun webhook : l’application ne reçoit aucun événement.</div>}
        {w && (
          <>
            <div>
              <span className={`badge ${w.active ? 'good' : 'mut'}`}>{w.active ? 'actif' : 'coupé'}</span>{' '}
              vers <span className="mono">{w.host}</span>
            </div>
            <div className="sub">Événements : {w.events.join(', ')}</div>
            {w.pendingOver15Minutes > 0 && (
              <div className="banner banner-warn">{w.pendingOver15Minutes} livraison(s) en attente depuis plus de 15 minutes.</div>
            )}
            {w.failedDeliveries.length > 0 && (
              <div className="banner banner-error">
                <b>Échecs définitifs récents</b>
                {w.failedDeliveries.map((d) => (
                  <div key={d.eventId} className="sub">{d.eventType} — {d.attempts} tentative(s) — {d.lastError || 'erreur inconnue'}</div>
                ))}
              </div>
            )}
            <button type="button" className="btn ghost sm" onClick={regenerer}>Régénérer le secret</button>
            {w.active && <button type="button" className="btn ghost sm" onClick={couper}>Couper</button>}
          </>
        )}
      </div>
      <ConfigurationWebhook
        ouvert={edition}
        application={application}
        onFermer={() => setEdition(false)}
        onEnvoyer={(corps) => { setEdition(false); return appel(() => api.configurerWebhookPartenaire(application.id, corps)) }}
      />
    </div>
  )
}

function ConfigurationWebhook({ ouvert, application, onFermer, onEnvoyer }) {
  const [url, setUrl] = useState('')
  const [evenements, setEvenements] = useState(application.webhook?.events ?? [])

  function cocher(nom, coche) {
    setEvenements((p) => (coche ? [...p, nom] : p.filter((e) => e !== nom)))
  }

  return (
    <Modal open={ouvert} onClose={onFermer} titre={`Webhooks de « ${application.name} »`} taille="sm">
      <form onSubmit={(e) => { e.preventDefault(); onEnvoyer({ url, events: evenements }) }}>
        <div className="field">
          <label htmlFor={`hook-url-${application.id}`}>Adresse https du partenaire</label>
          <input id={`hook-url-${application.id}`} className="input" type="url" required value={url}
            placeholder="https://…" onChange={(e) => setUrl(e.target.value)} />
          <span className="hint">Elle n’est jamais réaffichée : pour la changer, on la ressaisit.</span>
        </div>
        <span className="field-lbl">Événements envoyés</span>
        {application.webhookEvents.map((nom) => (
          <label key={nom} className="field-lbl">
            <input type="checkbox" checked={evenements.includes(nom)} onChange={(e) => cocher(nom, e.target.checked)} />{' '}
            <span className="mono">{nom}</span>
          </label>
        ))}
        <button type="submit" className="btn primary" disabled={evenements.length === 0}>Enregistrer</button>
      </form>
    </Modal>
  )
}
