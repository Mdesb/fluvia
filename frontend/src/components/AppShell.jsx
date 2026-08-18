import { useEffect, useState } from 'react'

// `cap` = capacité requise (capacitesActives de /me) ; `perm` = permission requise (droits de /me).
// Sans `cap` ni `perm`, l'entrée est toujours visible. `disabled` = présente mais grisée (« bientôt »).
const NAV = [
  {
    section: 'Exploitation',
    items: [
      { id: 'caisse', ic: '▤', label: 'Caisse' },
      { id: 'catalogue', ic: '▥', label: 'Catalogue' },
      { id: 'reservation', ic: '◷', label: 'Réservation', cap: 'reservation' },
    ],
  },
  {
    section: 'Contrôle d’accès',
    items: [
      { id: 'supervision', ic: '◉', label: 'Supervision', cap: 'controle_acces' },
    ],
  },
  {
    section: 'Boutique',
    items: [
      { id: 'boutique', ic: '▦', label: 'Boutique en ligne', cap: 'boutique_en_ligne' },
    ],
  },
  {
    section: 'Verticales',
    items: [
      { id: 'piscine', ic: '≈', label: 'Piscine', perm: 'piscine.lire' },
      { id: 'patinoire', ic: '❆', label: 'Patinoire', perm: 'patinoire.lire' },
      { id: 'padel', ic: '◍', label: 'Padel', perm: 'padel.lire' },
      { id: 'musee', ic: '⛫', label: 'Musée', perm: 'musee.lire' },
    ],
  },
  {
    section: 'Pilotage',
    items: [
      { id: 'pilotage', ic: '◨', label: 'Reporting' },
      { id: 'comptabilite', ic: '▧', label: 'Comptabilité / Régie', perm: 'compta.lire' },
    ],
  },
  {
    section: 'Relation client',
    items: [
      { id: 'clients', ic: '☺', label: 'Clients' },
    ],
  },
  {
    section: 'Ressources',
    items: [
      { id: 'personnel', ic: '☰', label: 'Personnel', perm: 'personnel.lire' },
      { id: 'stock', ic: '▣', label: 'Stock', disabled: true },
    ],
  },
  {
    section: 'Administration',
    items: [
      { id: 'parametres', ic: '⚙', label: 'Paramètres' },
    ],
  },
]

function initiales(me) {
  const src = me?.nom || me?.email || 'Utilisateur'
  const parts = src.replace(/@.*/, '').split(/[\s.]+/).filter(Boolean)
  return (parts[0]?.[0] || 'U').toUpperCase() + (parts[1]?.[0] || '').toUpperCase()
}

export default function AppShell({
  me,
  etablissements,
  etabActif,
  onChangeEtab,
  onglet,
  onNav,
  onLogout,
  capacites = [],
  droits = [],
  children,
}) {
  // Filtre les entrées selon les capacités actives ET les droits effectifs de l'établissement courant.
  const nav = NAV
    .map((grp) => ({
      ...grp,
      items: grp.items.filter(
        (it) => (!it.cap || capacites.includes(it.cap)) && (!it.perm || droits.includes(it.perm)),
      ),
    }))
    .filter((grp) => grp.items.length > 0)
  const [theme, setTheme] = useState(() => document.documentElement.getAttribute('data-theme') || '')
  const [navOpen, setNavOpen] = useState(false)

  useEffect(() => {
    const stored = localStorage.getItem('fluvia-theme')
    if (stored) {
      document.documentElement.setAttribute('data-theme', stored)
      setTheme(stored)
    }
  }, [])

  function toggleTheme() {
    const root = document.documentElement
    const actuel = root.getAttribute('data-theme')
    const suivant = actuel === 'dark' ? 'light' : 'dark'
    root.setAttribute('data-theme', suivant)
    localStorage.setItem('fluvia-theme', suivant)
    setTheme(suivant)
  }

  function aller(id) {
    onNav(id)
    setNavOpen(false)
  }

  const nomEtab = etablissements.find((e) => e.id === etabActif)?.nom || 'Établissement'

  return (
    <div className={`app${navOpen ? ' nav-open' : ''}`}>
      <div className="nav-backdrop" onClick={() => setNavOpen(false)} />

      <aside className="sidebar">
        <div className="side-brand"><span className="logo">◈</span> Fluvia</div>
        <div className="side-tenant"><b>{nomEtab}</b>Billetterie · Contrôle d'accès</div>
        <nav className="side-nav">
          {nav.map((grp) => (
            <div key={grp.section}>
              <div className="side-sec">{grp.section}</div>
              {grp.items.map((it) => (
                <button
                  key={it.id}
                  className={`side-link${onglet === it.id ? ' active' : ''}`}
                  onClick={() => !it.disabled && aller(it.id)}
                  disabled={it.disabled}
                  title={it.disabled ? 'Bientôt disponible' : undefined}
                  style={it.disabled ? { opacity: 0.5, cursor: 'not-allowed' } : undefined}
                >
                  <span className="ic">{it.ic}</span> {it.label}
                  {it.disabled && <span className="badge mut" style={{ marginLeft: 'auto', fontSize: 10 }}>bientôt</span>}
                </button>
              ))}
            </div>
          ))}
        </nav>
        <div className="side-foot">
          <span className="av">{initiales(me)}</span>
          <div>
            {me?.nom || me?.email || 'Utilisateur'}
            <br />
            <span style={{ color: 'var(--side-ink-soft)' }}>{me?.role || 'Régisseur'}</span>
          </div>
          <button className="logout" title="Déconnexion" onClick={onLogout}>⏻</button>
        </div>
      </aside>

      <div className="main">
        <div className="topbar">
          <button className="burger" aria-label="Menu" onClick={() => setNavOpen((v) => !v)}>☰</button>
          <div className="topbar-tenant">
            <span>Établissement</span>
            <select
              className="select"
              value={etabActif}
              onChange={(e) => onChangeEtab(e.target.value)}
            >
              {etablissements.map((e) => (
                <option key={e.id} value={e.id}>{e.nom}</option>
              ))}
            </select>
          </div>
          <div className="topbar-right">
            <button
              className="icon-btn"
              title={theme === 'dark' ? 'Passer en clair' : 'Passer en sombre'}
              onClick={toggleTheme}
            >◐</button>
          </div>
        </div>
        {children}
      </div>
    </div>
  )
}
