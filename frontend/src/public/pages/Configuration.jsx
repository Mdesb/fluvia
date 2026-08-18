import { useEffect, useState } from 'react'
import { boutique } from '../api/boutiqueClient.js'
import { Chargement, Erreur, Vide } from '../components/Etats.jsx'

// Écran d'amorçage : quand aucune vitrine n'est passée dans l'URL (?vitrine=<id>), on interroge
// la liste PUBLIQUE des vitrines ouvertes (GET /boutique/vitrines-publiques, sans auth) et on
// propose à l'internaute de choisir. La saisie manuelle d'un identifiant reste disponible en
// repli (lien direct fourni par l'organisme, ou liste indisponible).
export default function Configuration({ onValider }) {
  const [vitrines, setVitrines] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [manuel, setManuel] = useState('')

  function charger() {
    setChargement(true)
    setErreur(null)
    boutique
      .vitrinesPubliques()
      .then((r) => setVitrines(r?.vitrines || []))
      .catch((e) => setErreur(e?.message || 'Impossible de charger la liste des boutiques.'))
      .finally(() => setChargement(false))
  }

  useEffect(() => {
    charger()
  }, [])

  function soumettreManuel(e) {
    e.preventDefault()
    const v = manuel.trim()
    if (v) onValider(v)
  }

  return (
    <section aria-labelledby="pub-choix-titre" className="pub-narrow" style={{ maxWidth: 720, margin: '24px auto' }}>
      <div className="pub-view-head">
        <div>
          <h1 id="pub-choix-titre">Choisissez votre boutique</h1>
          <p className="pub-sub">Sélectionnez l'organisme dont vous souhaitez réserver des billets.</p>
        </div>
      </div>

      {chargement ? (
        <Chargement texte="Chargement des boutiques…" />
      ) : erreur ? (
        <Erreur message={erreur} onReessayer={charger} />
      ) : (vitrines || []).length === 0 ? (
        <Vide
          titre="Aucune boutique publique"
          texte="Aucune boutique n'est ouverte au public pour le moment. Si vous disposez d'un lien direct, utilisez le champ ci-dessous."
        />
      ) : (
        <ul className="pub-vitrines" aria-label="Boutiques disponibles">
          {vitrines.map((v) => (
            <li key={v.id}>
              <button
                type="button"
                className="pub-vitrine-carte"
                onClick={() => onValider(v.id)}
              >
                <span className="pub-vitrine-logo" aria-hidden="true">
                  {v.logo ? (
                    <img src={v.logo} alt="" loading="lazy" />
                  ) : (
                    <span>{(v.nom || '?').slice(0, 1).toUpperCase()}</span>
                  )}
                </span>
                <span className="pub-vitrine-nom">{v.nom || 'Boutique'}</span>
                <span className="pub-vitrine-go" aria-hidden="true">→</span>
              </button>
            </li>
          ))}
        </ul>
      )}

      <details className="pub-manuel">
        <summary>J'ai un lien ou un identifiant de boutique</summary>
        <form className="card" onSubmit={soumettreManuel} style={{ marginTop: 12 }}>
          <div className="card-b">
            <p className="empty" style={{ padding: '0 0 12px', textAlign: 'left' }}>
              Indiquez l'identifiant de la vitrine fourni par l'organisme (par exemple
              <code> /vitrine?vitrine=…</code>).
            </p>
            <div className="field">
              <label htmlFor="vitrine-id">Identifiant de la vitrine</label>
              <input
                id="vitrine-id"
                className="input"
                type="text"
                value={manuel}
                onChange={(e) => setManuel(e.target.value)}
                placeholder="00000000-0000-0000-0000-000000000000"
                autoComplete="off"
              />
            </div>
            <button type="submit" className="btn primary" disabled={!manuel.trim()}>
              Afficher la boutique
            </button>
          </div>
        </form>
      </details>
    </section>
  )
}
