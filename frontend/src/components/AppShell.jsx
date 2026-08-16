import { useEffect, useState } from 'react'

const NAV = [
  {
    section: 'Exploitation',
    items: [
      { id: 'caisse', ic: '▤', label: 'Caisse' },
      { id: 'session', ic: '◱', label: 'Caisse : session / Z' },
      { id: 'catalogue', ic: '▥', label: 'Catalogue' },
    ],
  },
  {
    section: 'Relation client',
    items: [
      { id: 'clients', ic: '☺', label: 'Clients' },
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
  children,
}) {
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
          {NAV.map((grp) => (
            <div key={grp.section}>
              <div className="side-sec">{grp.section}</div>
              {grp.items.map((it) => (
                <button
                  key={it.id}
                  className={`side-link${onglet === it.id ? ' active' : ''}`}
                  onClick={() => aller(it.id)}
                >
                  <span className="ic">{it.ic}</span> {it.label}
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
