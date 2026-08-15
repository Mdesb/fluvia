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
  return (
    <>
      <header className="appbar">
        <div className="brand">
          <span className="brand-dot">B</span>
          <span className="brand-name">Billetterie</span>
        </div>

        <nav className="nav">
          <button className={onglet === 'caisse' ? 'active' : ''} onClick={() => onNav('caisse')}>
            Caisse
          </button>
          <button
            className={onglet === 'catalogue' ? 'active' : ''}
            onClick={() => onNav('catalogue')}
          >
            Catalogue
          </button>
        </nav>

        <div className="appbar-right">
          <div className="etab-select">
            <select value={etabActif} onChange={(e) => onChangeEtab(e.target.value)}>
              {etablissements.map((e) => (
                <option key={e.id} value={e.id}>
                  {e.nom}
                </option>
              ))}
            </select>
          </div>
          <span className="user-chip">
            <strong>{me?.nom || me?.email || 'Utilisateur'}</strong>
          </span>
          <button className="link-btn" onClick={onLogout}>
            Déconnexion
          </button>
        </div>
      </header>
      <main>{children}</main>
    </>
  )
}
