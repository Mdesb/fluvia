import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import RechercheGlobale from './RechercheGlobale.jsx'
import { aLeDroit, aUnDesDroits } from '../api/droits.js'

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
      // Une entree propre plutot qu'un onglet dans Clients : un commercial cherche << ses
      // affaires >>, pas un onglet dans un annuaire. Et le pipeline se lit tous les jours,
      // alors qu'une fiche client s'ouvre a l'occasion.
      { id: 'affaires', ic: '◨', label: 'Affaires', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
      { id: 'comptabilite', ic: '▧', label: 'Comptabilité', perm: 'compta.lire' },
      { id: 'boutique', ic: '▦', label: 'Boutique en ligne', cap: 'boutique_en_ligne' },
      { id: 'personnel', ic: '☰', label: 'Personnel', perm: 'personnel.lire' },
      // Sous Gestion et a cote du Personnel : un projet se distribue a des gens, et c'est la
      // qu'on va chercher qui fait quoi. Pas sous Pilotage -- un projet se conduit, il ne
      // s'observe pas.
      { id: 'projets', ic: '◱', label: 'Projets', perms: ['personnel.lire', 'personnel.gerer', 'organisation.gerer'] },
      { id: 'stock', ic: '▣', label: 'Stock', perm: 'stock.lire' },
      { id: 'facturation', ic: '▤', label: 'Facturation', perm: 'facturation.lire' },
      { id: 'finance', ic: '€', label: 'Achats & trésorerie', perm: 'finance.read' },
      { id: 'sepa', ic: '⇄', label: 'Prélèvements SEPA', perm: 'sepa.lire', disabled: true, absent: true },
      // PAS D'ENTREE PROPRE, ET C'EST DELIBERE. `Comptabilite > Impayes` traite deja les incidents,
      // le tableau de bord, la resolution et la reouverture forcee. Une seconde porte vers la meme
      // liste, c'est deux endroits a corriger et un exploitant qui ne sait plus lequel fait foi.
      // Ce qui manquait -- representations et politique -- a ete ajoute LA, pas ailleurs.
      { id: 'recouvrement', ic: '⚠', label: 'Recouvrement', perm: 'recouvrement.lire', disabled: true, absent: true },
      { id: 'caution', ic: '⛨', label: 'Cautions', perm: 'caution.lire', disabled: true, absent: true },
      // Ouvert le 27/08 : quinze operations, aucun ecran. Un contrat depose par l'API existait,
      // et personne ne pouvait le relire.
      { id: 'documents', ic: '🗎', label: 'Documents', perms: ['dms.read', 'dms.write'] },
      { id: 'social', ic: '◎', label: 'Publication sociale', perm: 'social.read_post', disabled: true, absent: true },
    ],
  },
  {
    section: 'Pilotage',
    items: [
      { id: 'pilotage', ic: '◨', label: 'Reporting', perms: ['reporting.lire', 'reporting.configurer', 'reporting.planifier'] },
      // `absent: true` a tenu jusqu'au 27/08 sur un module qui expose ONZE operations de tickets
      // et sept d'articles d'aide. La porte etait dessinee et condamnee ; c'est le motif le plus
      // couteux du depot -- 815 operations sans porte pour 234 atteignables.
      //
      // Les droits fins restent ceux du serveur : l'entree exige `support.lire`, et l'ecran ne
      // montre les gestes d'agent qu'a qui les possede. Ouvrir la porte n'ouvre aucun droit.
      { id: 'support', ic: '?', label: 'Assistance', perms: ['support.lire', 'support.ouvrir_ticket', 'support.lire_ticket_soi', 'support.traiter_ticket_n1', 'support.traiter_ticket_n2', 'support.administrer'] },
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
      // Ouvert le 27/08. Voir le commentaire de l'entree << Assistance >> : meme motif, meme cout.
      { id: 'autorisations', ic: '⚿', label: 'Autorisations', perms: ['autorisation.lire', 'autorisation.approuver', 'autorisation.gerer'] },
      // Les mentions obligatoires d'un site marchand. Sous Administration et non sous Boutique :
      // elles engagent l'exploitant, pas la vitrine, et un exploitant qui n'a pas encore ouvert
      // sa boutique doit pouvoir les preparer.
      { id: 'legal', ic: '§', label: 'Mentions legales', perms: ['organisation.gerer', 'boutique.gerer_vitrine'] },
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
  // État de la session de caisse, affiché en permanence dans la barre du haut.
  //
  // L'appel est silencieux et facultatif : il est gardé par le droit de lecture, et toute erreur est
  // avalée. Une barre de navigation ne doit jamais faire échouer une page — si l'information n'est
  // pas disponible, on n'affiche rien plutôt qu'un état faux ou un message d'erreur permanent.
  const [caisseOuverte, setCaisseOuverte] = useState(null)
  useEffect(() => {
    if (!aLeDroit(droits, 'caisse.lire') || !etabActif) {
      setCaisseOuverte(null)
      return undefined
    }
    let annule = false
    api
      .sessionsCaisse()
      .then((c) => {
        if (annule) return
        setCaisseOuverte(membres(c).some((s) => s.etat === 'ouverte' || s.statut === 'ouverte'))
      })
      .catch(() => {
        if (!annule) setCaisseOuverte(null)
      })
    return () => {
      annule = true
    }
  }, [droits, etabActif, onglet])

  // Filtre les entrées selon les capacités actives, les droits effectifs de l'établissement courant
  // et le statut administrateur.
  const nav = NAV
    .map((grp) => ({
      ...grp,
      items: grp.items.filter(
        (it) =>
          (!it.cap || capacites.includes(it.cap)) &&
          (!it.perm || aLeDroit(droits, it.perm)) &&
          (!it.perms || aUnDesDroits(droits, it.perms)) &&
          (!it.admin || estAdmin),
      ),
    }))
    .filter((grp) => grp.items.length > 0)

  // PLANCHER DE SÛRETÉ. Si le filtrage ne laisse RIEN, on retombe sur les entrées sans contrainte de
  // capacité ni de statut administrateur.
  //
  // Ce n'est pas de la timidité : un menu vide enferme quelqu'un hors de son propre logiciel, sans
  // aucun moyen d'en sortir ni de comprendre pourquoi. Un menu trop permissif, lui, se corrige tout
  // seul — l'API refuse, et le refus est lisible. Entre les deux erreurs possibles, celle-ci est la
  // moins coûteuse, et c'est exactement celle que j'ai commise en production ce soir.
  const [sansEcranOuvert, setSansEcranOuvert] = useState(false)

  const navFinale = nav.length > 0
    ? nav
    : NAV
        .map((grp) => ({
          ...grp,
          items: grp.items.filter((it) => !it.cap && !it.admin),
        }))
        .filter((grp) => grp.items.length > 0)

  // On separe ce qui mene quelque part de ce qui informe : melanges, les onze modules sans ecran
  // allongeaient le menu de moitie et obligeaient a faire defiler pour atteindre Parametres — pour
  // des entrees sur lesquelles on ne peut meme pas cliquer.
  const navAvecEcran = navFinale
    .map((grp) => ({ ...grp, items: grp.items.filter((it) => !it.absent) }))
    .filter((grp) => grp.items.length > 0)
  const sansEcran = navFinale.flatMap((grp) => grp.items.filter((it) => it.absent))

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
          {navAvecEcran.map((grp) => (
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

          {/* Les modules qui existent cote serveur et n'ont pas encore d'ecran. Replies : ils
              informent sans encombrer, et le compte suffit a savoir ou en est le produit. */}
          {sansEcran.length > 0 && (
            <div>
              <button
                className="side-link"
                onClick={() => setSansEcranOuvert((v) => !v)}
                aria-expanded={sansEcranOuvert}
                title="Ces modules fonctionnent deja cote serveur ; leur ecran n'est pas encore construit."
              >
                <span className="ic">{sansEcranOuvert ? '▾' : '▸'}</span> Sans écran
                <span className="badge mut" style={{ marginLeft: 'auto', fontSize: 10 }}>{sansEcran.length}</span>
              </button>
              {sansEcranOuvert &&
                sansEcran.map((it) => (
                  <button
                    key={it.id}
                    className="side-link"
                    disabled
                    title={`${it.label} : le module existe côté serveur, son écran n'est pas encore construit.`}
                    style={{ opacity: 0.5, cursor: 'not-allowed', paddingLeft: 26 }}
                  >
                    <span className="ic">{it.ic}</span> {it.label}
                  </button>
                ))}
            </div>
          )}
        </nav>
        {/* L'identité et la déconnexion sont remontées dans la barre du haut : le bas de la colonne
            de gauche est l'endroit qu'on regarde le moins, pour une information qu'on veut sous les
            yeux en permanence. La colonne se termine donc sur la navigation — l'établissement est
            déjà rappelé en tête du menu, le répéter deux centimètres plus bas n'aiderait personne. */}
      </aside>

      <div className="main">
        <div className="topbar">
          <button className="burger" aria-label="Menu" onClick={() => setNavOpen((v) => !v)}>☰</button>
          {/* Le contexte d'établissement reste, en compact : le libellé « Établissement » disparaît,
              le sélecteur se suffit à lui-même et le nom est déjà rappelé dans la colonne. */}
          <div className="topbar-tenant">
            <select
              className="select"
              value={etabActif}
              onChange={(e) => onChangeEtab(e.target.value)}
              aria-label="Établissement actif"
              title="Établissement sur lequel vous travaillez"
            >
              {etablissements.map((e) => (
                <option key={e.id} value={e.id}>{e.nom}</option>
              ))}
            </select>
          </div>

          <RechercheGlobale droits={droits} onNav={onNav} />

          <div className="topbar-right">
            {caisseOuverte !== null && (
              <button
                type="button"
                className={`badge ${caisseOuverte ? 'good' : 'mut'} topbar-caisse`}
                onClick={() => onNav('caisse')}
                title={
                  caisseOuverte
                    ? 'Une session de caisse est ouverte. Cliquez pour aller à la caisse.'
                    : "Aucune session de caisse ouverte : les encaissements sont impossibles tant qu'une session n'est pas ouverte."
                }
              >
                {caisseOuverte ? 'Caisse ouverte' : 'Caisse fermée'}
              </button>
            )}
            <button
              className="icon-btn"
              title={theme === 'dark' ? 'Passer en clair' : 'Passer en sombre'}
              onClick={toggleTheme}
            >◐</button>
            <div className="topbar-who" title={me?.email || ''}>
              <span className="av">{initiales(me)}</span>
              <span className="tw-txt">
                <b>{me?.nom || me?.email || 'Utilisateur'}</b>
                <span className="sub">{me?.role || 'Régisseur'}</span>
              </span>
            </div>
            <button className="icon-btn" title="Déconnexion" onClick={onLogout}>⏻</button>
          </div>
        </div>
        {children}
      </div>
    </div>
  )
}
