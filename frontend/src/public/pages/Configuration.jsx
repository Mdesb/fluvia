import { useState } from 'react'

// Écran d'amorçage : le back n'expose PAS de liste publique des vitrines
// (GET /boutique/vitrines exige la permission staff « boutique.lire »).
// La boutique publique a donc besoin qu'on lui indique l'identifiant de la vitrine à afficher,
// normalement transmis par l'URL (?vitrine=<uuid>). En son absence, on le demande ici plutôt
// que d'afficher un écran vide.
export default function Configuration({ onValider }) {
  const [id, setId] = useState('')

  function soumettre(e) {
    e.preventDefault()
    const v = id.trim()
    if (v) onValider(v)
  }

  return (
    <div className="pub-narrow">
      <form className="card" onSubmit={soumettre} style={{ maxWidth: 520, margin: '40px auto' }}>
        <div className="card-h">
          <h3>Ouvrir une boutique</h3>
        </div>
        <div className="card-b">
          <p className="empty" style={{ padding: '0 0 14px', textAlign: 'left' }}>
            Indiquez l'identifiant de la vitrine à afficher. En temps normal, ce lien vous est fourni
            par l'organisme (par exemple <code>/vitrine?vitrine=…</code>).
          </p>
          <div className="field">
            <label htmlFor="vitrine-id">Identifiant de la vitrine</label>
            <input
              id="vitrine-id"
              className="input"
              type="text"
              value={id}
              onChange={(e) => setId(e.target.value)}
              placeholder="00000000-0000-0000-0000-000000000000"
              autoComplete="off"
              required
            />
          </div>
          <button type="submit" className="btn primary lg" disabled={!id.trim()}>
            Afficher la boutique
          </button>
        </div>
      </form>
    </div>
  )
}
