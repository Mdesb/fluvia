// États transverses : chargement, erreur, vide. Jamais d'écran blanc.

export function Chargement({ texte = 'Chargement…' }) {
  return (
    <div className="center" role="status" aria-live="polite">
      <div className="spinner" />
      <span className="sr-only">{texte}</span>
    </div>
  )
}

// Bannière d'erreur reliée par aria à la zone concernée si `id` fourni.
export function Erreur({ message, onReessayer, id }) {
  if (!message) return null
  return (
    <div className="banner banner-error" role="alert" id={id}>
      <span>{message}</span>
      {onReessayer && (
        <button type="button" className="btn sm" style={{ marginLeft: 12 }} onClick={onReessayer}>
          Réessayer
        </button>
      )}
    </div>
  )
}

export function Vide({ titre = 'Rien à afficher', texte }) {
  return (
    <div className="pub-vide" role="status">
      <p className="pub-vide-t">{titre}</p>
      {texte && <p className="empty" style={{ padding: 0 }}>{texte}</p>}
    </div>
  )
}

// Fil d'étapes du tunnel (accessible : liste ordonnée, étape courante marquée aria-current).
export function Etapes({ etapes, courant }) {
  return (
    <nav aria-label="Étapes de commande" className="pub-steps">
      <ol>
        {etapes.map((e, i) => {
          const etat = i < courant ? 'faite' : i === courant ? 'active' : 'apres'
          return (
            <li key={e} className={`pub-step ${etat}`} aria-current={i === courant ? 'step' : undefined}>
              <span className="pub-step-num" aria-hidden="true">
                {i < courant ? '✓' : i + 1}
              </span>
              <span className="pub-step-lbl">{e}</span>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
