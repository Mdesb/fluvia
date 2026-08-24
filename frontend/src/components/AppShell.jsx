import { useEffect, useState } from 'react'

// `cap` = capacité requise (capacitesActives de /me) ; `perm` = permission requise (droits de /me) ;
// `perms` = liste dont AU MOINS UNE suffit — pour les écrans qui servent plusieurs métiers, où
// exiger un droit unique retirerait l'écran à quelqu'un qui s'en sert légitimement ;
// `admin` = réservé aux profils administrateur (droits d'administration du socle). Sans contrainte,
// l'entrée est toujours visible. `disabled` = présente mais grisée, avec la raison en infobulle.
//
// POURQUOI DES ENTRÉES GRISÉES POUR DES ÉCRANS QUI N'EXISTENT PAS.
//
// Au 24/08/2026, 32 modules exposent une API et ce menu comptait 16 entrées. Treize modules — Stock,
// Finance, Facturation, SEPA, Recouvrement, GED, Support, Cautions, Sport, Autorisations, Séjours,
// Publication sociale — avaient une API complète et AUCUN endroit où aller, soit près de trois
// ressources sur dix.
//
// Le point qui a coûté cher : « Stock — bientôt » était le SEUL manque visible, parce que c'était le
// seul qu'on avait affiché. Les douze autres n'étaient pas grisés, ils étaient absents — donc
// personne ne pouvait constater qu'ils manquaient, pas même en regardant l'écran attentivement. Un
// menu incomplet se lit comme un produit complet.
//
// Ces entrées ne livrent aucune fonctionnalité. Elles rendent le manque VISIBLE et donc arbitrable :
// on voit ce qui reste à construire, et dans quel ordre le demander. Chacune disparaîtra de cette
// liste le jour où son écran existera — c'est le seul entretien qu'elles demandent.
//
// Ne figurent pas ici les services transverses sans usage direct (OCR, Audit) : ils sont consommés
// par d'autres modules et n'ont pas vocation à un écran propre. Une entrée pour eux serait une
// promesse qu'on n'a pas l'intention de tenir.
const NAV = [
  {
    section: 'Exploitation',
    items: [
      { id: 'dashboard', ic: '⌂', label: 'Tableau de bord', admin: true },
      // La caisse sert le caissier comme le responsable : encaisser, ouvrir une session, consulter.
      // Exiger le seul `caisse.lire` retirerait l'écran à un caissier qui n'a que les droits de vente.
      { id: 'caisse', ic: '▤', label: 'Caisse', perms: ['caisse.lire', 'caisse.ouvrir', 'vente.creer', 'vente.encaisser'] },
      { id: 'catalogue', ic: '▥', label: 'Catalogue', perms: ['offre.lire', 'offre.gerer', 'offre.creer', 'offre.modifier'] },
      { id: 'reservation', ic: '◷', label: 'Réservation', cap: 'reservation' },
      // Écran métier de l'établissement (une seule entrée visible selon le type de site).
      { id: 'piscine', ic: '≈', label: 'Piscine', perm: 'piscine.lire' },
      { id: 'patinoire', ic: '❆', label: 'Patinoire', perm: 'patinoire.lire' },
      { id: 'padel', ic: '◍', label: 'Padel', perm: 'padel.lire' },
      { id: 'musee', ic: '⛫', label: 'Musée', perm: 'musee.lire' },
      { id: 'sport', ic: '⬤', label: 'Sport & fitness', perm: 'sport.lire', disabled: true, absent: true },
    ],
  },
  {
    section: 'Contrôle d’accès',
    items: [
      { id: 'supervision', ic: '◉', label: 'Supervision', cap: 'controle_acces' },
    ],
  },
  {
    section: 'Gestion',
    items: [
      { id: 'clients', ic: '☺', label: 'Clients', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
      { id: 'comptabilite', ic: '▧', label: 'Comptabilité', perm: 'compta.lire' },
      { id: 'boutique', ic: '▦', label: 'Boutique en ligne', cap: 'boutique_en_ligne' },
      { id: 'personnel', ic: '☰', label: 'Personnel', perm: 'personnel.lire' },
      { id: 'stock', ic: '▣', label: 'Stock', perm: 'stock.lire', disabled: true, absent: true },
      { id: 'facturation', ic: '▤', label: 'Facturation', perm: 'facturation.lire', disabled: true, absent: true },
      { id: 'finance', ic: '€', label: 'Achats & trésorerie', perm: 'finance.read', disabled: true, absent: true },
      { id: 'sepa', ic: '⇄', label: 'Prélèvements SEPA', perm: 'sepa.lire', disabled: true, absent: true },
      { id: 'recouvrement', ic: '⚠', label: 'Recouvrement', perm: 'recouvrement.lire', disabled: true, absent: true },
      { id: 'caution', ic: '⛨', label: 'Cautions', perm: 'caution.lire', disabled: true, absent: true },
      { id: 'documents', ic: '🗎', label: 'Documents', perm: 'dms.read', disabled: true, absent: true },
      { id: 'social', ic: '◎', label: 'Publication sociale', perm: 'social.read_post', disabled: true, absent: true },
    ],
  },
  {
    section: 'Pilotage',
    items: [
      { id: 'pilotage', ic: '◨', label: 'Reporting', perms: ['reporting.lire', 'reporting.configurer', 'reporting.planifier'] },
      { id: 'support', ic: '?', label: 'Assistance', perm: 'support.lire', disabled: true, absent: true },
    ],
  },
  {
    section: 'Administration',
    items: [
      {
        id: 'parametres',
        ic: '⚙',
        label: 'Paramètres',
        perms: ['securite.gerer', 'securite.lire', 'organisation.gerer', 'offre.gerer', 'caisse.gerer', 'crm.parametrer'],
      },
      { id: 'autorisations', ic: '⚿', label: 'Autorisations', perm: 'autorisation.lire', disabled: true, absent: true },
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
  estAdmin = false,
  children,
}) {
  // Filtre les entrées selon les capacités actives, les droits effectifs de l'établissement courant
  // et le statut administrateur.
  const nav = NAV
    .map((grp) => ({
      ...grp,
      items: grp.items.filter(
        (it) =>
          (!it.cap || capacites.includes(it.cap)) &&
          (!it.perm || droits.includes(it.perm)) &&
          (!it.perms || it.perms.some((p) => droits.includes(p))) &&
          (!it.admin || estAdmin),
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
                  title={
                    it.absent
                      ? `${it.label} : le module existe côté serveur, son écran n'est pas encore construit.`
                      : it.disabled
                        ? 'Bientôt disponible'
                        : undefined
                  }
                  style={it.disabled ? { opacity: 0.5, cursor: 'not-allowed' } : undefined}
                >
                  <span className="ic">{it.ic}</span> {it.label}
                  {it.disabled && (
                    <span className="badge mut" style={{ marginLeft: 'auto', fontSize: 10 }}>
                      {it.absent ? 'sans écran' : 'bientôt'}
                    </span>
                  )}
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
