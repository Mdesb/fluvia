import { useEffect, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import RechercheGlobale from './RechercheGlobale.jsx'
import { aLeDroit, aUnDesDroits } from '../api/droits.js'
import { profondeurHistorique } from '../api/url.js'
import Cloche from './Cloche.jsx'
import InstallerSurLeTelephone from './InstallerSurLeTelephone.jsx'
import Icon from './Icon.jsx'
import { HoteConfirmation } from './Confirmation.jsx'
import { NAV, filtrerMenu } from '../api/menu.js'
import { t } from '../i18n/index.js'

// Le menu (ses entrées, ses gardes, son plancher de sûreté) vit dans `api/menu.js`, testé hors du
// navigateur ; cette coquille le dessine. Une navigation fournie (l'éditeur) porte ses propres
// libellés, `label` et `section` ; celle du back-office les prend dans le catalogue de traduction.

// La profondeur change sans que rien ne se remonte : on s'abonne aux deux événements qui la font
// bouger, comme le fait déjà `useEtatUrl`.
function useProfondeur() {
  const [profondeur, setProfondeur] = useState(profondeurHistorique)
  useEffect(() => {
    const relire = () => setProfondeur(profondeurHistorique())
    // Trois sources, parce qu'aucune ne couvre les autres : `popstate` pour les boutons du
    // navigateur, `hashchange` pour `allerA`, et notre propre événement pour `pushState`, qui
    // n'en émet aucun.
    window.addEventListener('hashchange', relire)
    window.addEventListener('popstate', relire)
    window.addEventListener('fluvia:navigation', relire)
    return () => {
      window.removeEventListener('hashchange', relire)
      window.removeEventListener('popstate', relire)
      window.removeEventListener('fluvia:navigation', relire)
    }
  }, [])
  return profondeur
}

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
  // ⚠ LA NAVIGATION EST PARAMETRABLE, ET SA VALEUR PAR DEFAUT EST CELLE DU BACK-OFFICE.
  //
  // L'application editeur employait sa propre barre : deux habillages a tenir, et l'un des deux
  // prenait du retard — Maxime l'a vu du premier coup d'oeil. Elle passe desormais SA navigation a
  // cette coquille-ci.
  //
  // Additif : l'application principale ne passe rien et se comporte exactement comme avant.
  nav: navFournie = null,
  // Rendu a droite de la barre du haut, avant la recherche. L'editeur y met « Mode support ».
  actionsBarre = null,
  // ⚠ EPINGLE : l'onglet est verrouille sur son etablissement, et le selecteur disparait.
  //
  // Sert au mode support. Sans cela, l'agent pourrait changer d'etablissement dans un onglet dont
  // le bandeau continue de nommer le client : l'ecran dirait une chose et l'en-tete une autre, et
  // c'est exactement la confusion que le bandeau existe pour empecher.
  epingle = false,
  // Rendu tout en haut de la zone principale, au-dessus de la barre. Null par defaut, donc le
  // back-office ordinaire est inchange.
  bandeau = null,
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

  // LES DEMANDES RGPD EN ATTENTE, ET CELLES QUI ONT DÉPASSÉ LE MOIS.
  //
  // ⚠ CE COMPTEUR EXISTE PARCE QUE J'AI SORTI CET ÉCRAN DU MENU QUOTIDIEN (R27), et qu'une demande
  // d'effacement porte un délai légal d'un mois, opposable. Aucune des sept tâches planifiées ne le
  // surveille : sans badge, l'écran était moins vu ET toujours pas surveillé. Arbitrage de Maxime.
  //
  // ⚠ DEUX COMPTES, PAS UN. « En attente » dit le travail ; « en retard » dit le RISQUE. Un badge
  // unique ferait lire « 3 » de la même façon pour trois demandes arrivées ce matin et pour trois
  // qui dépassent le mois.
  //
  // ⚠ `null` N'EST PAS `0`. « Je n'ai pas pu lire » et « il n'y en a pas » sont deux états, et seul
  // le second s'affiche : un badge « 0 » sur une lecture ratée affirmerait une absence qu'on n'a
  // pas mesurée. L'appel reste silencieux — une barre de navigation ne doit jamais faire échouer
  // une page.
  const [compteurRgpd, setCompteurRgpd] = useState(null)
  useEffect(() => {
    if (!aUnDesDroits(droits, ['crm.rgpd_gerer', 'crm.rgpd_demander']) || !etabActif) {
      setCompteurRgpd(null)
      return undefined
    }
    let annule = false
    // Le délai légal court à partir de la réception : un mois plus tard, la demande est en retard.
    const seuil = new Date(Date.now() - 30 * 24 * 3600 * 1000).toISOString()
    const compte = (r) => r?.totalItems ?? r?.['hydra:totalItems'] ?? null
    Promise.all([
      // ⚠ LA CLÉ S'ÉCRIT SANS CROCHETS : `qs()` les ajoute lui-même pour un tableau. Les écrire
      // ici donnait `statut[][]=recue`, un paramètre qu'aucun filtre ne connaît — donc ignoré en
      // silence, donc un compte égal à TOUTES les demandes. Le badge aurait montré un nombre
      // plausible et faux, plus grand que le vrai.
      api.demandesRgpd({ statut: ['recue', 'en_cours'], itemsPerPage: 1 }),
      api.demandesRgpd({ statut: ['recue', 'en_cours'], 'dateDemande[before]': seuil, itemsPerPage: 1 }),
    ])
      .then(([tout, retard]) => {
        if (annule) return
        const enAttente = compte(tout)
        // ⚠ SI LE SERVEUR NE DIT PAS LE TOTAL, ON N'INVENTE PAS. Sans `totalItems`, on ne sait pas
        // combien il y en a — et surtout pas qu'il n'y en a aucune.
        setCompteurRgpd(enAttente === null ? null : { enAttente, enRetard: compte(retard) ?? 0 })
      })
      .catch(() => {
        if (!annule) setCompteurRgpd(null)
      })
    return () => {
      annule = true
    }
  }, [droits, etabActif, onglet])

  const navFinale = filtrerMenu(navFournie ?? NAV, { capacites, droits, estAdmin })

  // ── CE QUI A DISPARU ICI, ET POURQUOI L'HISTOIRE RESTE ─────────────────────────────────────
  //
  // Le menu se separait en deux : ce qui mene quelque part, et un repli << Sans ecran >> pour les
  // modules qui existaient cote serveur sans avoir d'interface. Melanges, les onze de l'epoque
  // allongeaient le menu de moitie et obligeaient a faire defiler pour atteindre Parametres — pour
  // des entrees sur lesquelles on ne pouvait meme pas cliquer.
  //
  // Le drapeau `absent` qui alimentait cette separation n'est plus pose nulle part : les 38 entrees
  // du menu ont toutes un ecran (38 entrees, 39 identifiants routes, 0 sans route — mesure du
  // 03/09). Le repli etait donc devenu un bloc qui ne pouvait plus s'afficher, et sa presence
  // laissait croire au lecteur que le produit avait encore des modules sans interface.
  //
  // ⚠ LE MECANISME PART, PAS LA RAISON QUI L'A FAIT NAITRE. Ce qui empeche les modules sans ecran
  // de redevenir invisibles n'est pas ce repli — c'est le garde-fou d'ecart client/serveur, qui
  // COMPTE les operations qu'aucun ecran n'appelle et refuse de laisser ce nombre monter. Voir
  // aussi le commentaire de tete sur les douze modules absents, et celui plus bas sur les onze
  // operations de tickets restees derriere un `absent: true` jusqu'au 27/08.

  const [theme, setTheme] = useState(() => document.documentElement.getAttribute('data-theme') || '')
  const [navOpen, setNavOpen] = useState(false)

  // ⚠ LE FOCUS SUIT L'ECRAN, SINON IL RESTE SUR LE MENU.
  //
  // Dans une application d'une seule page, changer d'onglet remplace le contenu et ne bouge pas le
  // focus : il reste sur l'entree de menu qu'on vient d'activer. Au clavier, il faut alors
  // retraverser la trentaine d'entrees pour atteindre ce qu'on a demande — a chaque navigation.
  //
  // ⚠ MAIS PAS AU PREMIER RENDU. Prendre le focus au chargement le volerait a qui n'a rien
  // demande, et ferait sauter la page sous les yeux de tout le monde. `premierRendu` garde donc le
  // tout premier passage.
  const contenuRef = useRef(null)
  const premierRendu = useRef(true)
  useEffect(() => {
    if (premierRendu.current) {
      premierRendu.current = false
      return
    }
    contenuRef.current?.focus?.()
  }, [onglet])
  const profondeur = useProfondeur()

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

  const nomEtab = etablissements.find((e) => e.id === etabActif)?.nom || t('shell.establishment')

  return (
    <div className={`app${navOpen ? ' nav-open' : ''}`}>
      {/* @clic-souris-seul: nav-backdrop  ferme le menu au clic a cote ; au clavier, le bouton ☰
          le referme deja. Le rendre focusable ajouterait un arret muet dans l ordre de tabulation,
          a franchir a chaque passage, pour un geste qui a deja son equivalent. */}
      <div className="nav-backdrop" onClick={() => setNavOpen(false)} />
      {/* Monte une fois pour toute l'application : les appelants importent `confirmer`,
          il n'y a rien a cabler par ecran, donc rien a oublier. */}
      <HoteConfirmation />

      <aside className="sidebar">
        <div className="side-brand">
          <img className="logo" src="/fluvia-mark-192.png" alt="" width="28" height="28" /> Fluvia
        </div>
        {/* LE SOUS-TITRE ANNONÇAIT UN MODULE QUE L'ÉTABLISSEMENT N'A PAS.
            « Billetterie · Contrôle d'accès » était écrit en dur sous le nom du site. Sur un
            établissement dont la capacité `controle_acces` est hors service — le cas de GI-ONE
            FITNESS — l'application affirmait donc dans son en-tête un module que son propre écran
            des modules déclarait absent, et dont aucune entrée n'apparaissait dans le menu.
            Relevé par la session en revue avec Maxime.
            La ligne dit maintenant ce qui est réellement en service. La billetterie ne se négocie
            pas (caisse et catalogue existent partout) ; le reste se lit dans les capacités. */}
        <div className="side-tenant"><b>{nomEtab}</b>{sousTitreDe(capacites)}</div>
        <nav className="side-nav">
          {navFinale.map((grp) => (
            <div key={grp.id ?? grp.section}>
              <div className="side-sec">{grp.section ?? t(`nav.section.${grp.id}`)}</div>
              {grp.items.map((it) => (
                <button
                  key={it.id}
                  className={`side-link${onglet === it.id ? ' active' : ''}`}
                  onClick={() => aller(it.id)}
                >
                  <Icon name={it.ic} className="ic" /> {it.label ?? t(`nav.${it.id}`)}
                  {/* ⚠ LE BADGE NE S'AFFICHE QUE S'IL Y A QUELQUE CHOSE À MONTRER. `> 0`, jamais
                      `!= null` : un « 0 » permanent sur une entrée de menu s'apprend à ne plus
                      voir, et le jour où il porte un vrai chiffre personne ne le lit. */}
                  {it.id === 'rgpd' && compteurRgpd?.enAttente > 0 && (
                    <span
                      className={`badge ${compteurRgpd.enRetard > 0 ? 'crit' : 'warn'} side-compteur`}
                      title={
                        compteurRgpd.enRetard > 0
                          ? t('shell.gdpr_late', { pending: compteurRgpd.enAttente, late: compteurRgpd.enRetard })
                          : t('shell.gdpr_pending', { pending: compteurRgpd.enAttente })
                      }
                    >
                      {compteurRgpd.enAttente}
                    </span>
                  )}
                </button>
              ))}
            </div>
          ))}
        </nav>
        {/* L'identité et la déconnexion sont remontées dans la barre du haut : le bas de la colonne
            de gauche est l'endroit qu'on regarde le moins, pour une information qu'on veut sous les
            yeux en permanence. La colonne se termine donc sur la navigation — l'établissement est
            déjà rappelé en tête du menu, le répéter deux centimètres plus bas n'aiderait personne. */}
      </aside>

      <div className="main">
        {/* ⚠ LE LIEN D'EVITEMENT, PREMIER ELEMENT FOCUSABLE DE LA PAGE.
            Invisible tant qu'on ne l'atteint pas au clavier, il apparait au focus. Sans lui, la
            premiere tabulation d'un chargement entre dans une colonne de trente entrees qu'il faut
            traverser en entier pour lire le contenu. La boutique publique en a un depuis toujours
            (`PublicHeader.jsx`) ; le back-office n'en avait pas. */}
        <a className="lien-evitement" href="#contenu-principal">{t('shell.skip_to_content')}</a>
        {/* Au-dessus de la barre, donc au-dessus de tout : un bandeau qu'on peut faire defiler hors
            de l'ecran n'est pas un bandeau permanent. */}
        {bandeau}
        <div className="topbar">
          <button className="burger" aria-label={t('shell.menu')} onClick={() => setNavOpen((v) => !v)}>☰</button>
          {/* POURQUOI UN BOUTON « PRÉCÉDENT » ALORS QUE CELUI DU NAVIGATEUR MARCHE MAINTENANT.
              Parce qu'il n'y en a pas toujours un. L'application s'installe sur un téléphone ou une
              tablette de caisse (voir `InstallerSurLeTelephone`) : en mode autonome, la barre du
              navigateur DISPARAÎT, et avec elle le geste que tout le monde connaît. Sur ces postes,
              ce bouton est le seul retour possible.
              Il ne s'affiche que s'il y a où revenir DANS l'application : la profondeur est portée
              par l'entrée d'historique elle-même, pas par un compteur qui se désynchronise. Un
              bouton de retour qui sort de l'application serait exactement le défaut qu'on répare. */}
          {profondeur > 0 && (
            <button
              className="btn ghost sm"
              type="button"
              onClick={() => window.history.back()}
              aria-label={t('shell.back_title')}
              title={t('shell.back_title')}
            >
              {t('shell.back')}
            </button>
          )}
          {/* Le contexte d'établissement reste, en compact : le libellé « Établissement » disparaît,
              le sélecteur se suffit à lui-même et le nom est déjà rappelé dans la colonne. */}
          <div className="topbar-tenant">
            {epingle ? (
              /* Epingle : le nom, pas le choix. Voir la prop `epingle` — un onglet de support est
                 verrouille sur son client, et le bandeau juste au-dessus le nomme deja. */
              <span className="badge">{nomEtab}</span>
            ) : (
              <select
                className="select"
                value={etabActif}
                onChange={(e) => onChangeEtab(e.target.value)}
                aria-label={t('shell.establishment_active')}
                title={t('shell.establishment_hint')}
              >
                {etablissements.map((e) => (
                  <option key={e.id} value={e.id}>{e.nom}</option>
                ))}
              </select>
            )}
          </div>

          {/* Rendu avant la recherche : l editeur y met « Mode support ». Null par defaut, donc
              la barre du back-office est inchangee. */}
          {actionsBarre}

          <RechercheGlobale droits={droits} onNav={onNav} />

          <div className="topbar-right">
            {/* En haut à droite, comme demandé — et à gauche du reste, parce que c'est ce qu'on
                regarde en arrivant, avant l'état de la caisse et avant son propre nom. */}
            <Cloche etabActif={etabActif} />
            {caisseOuverte !== null && (
              <button
                type="button"
                className={`badge ${caisseOuverte ? 'good' : 'mut'} topbar-caisse`}
                onClick={() => onNav('caisse')}
                title={
                  caisseOuverte
                    ? t('shell.till_open_hint')
                    : t('shell.till_closed_hint')
                }
              >
                {caisseOuverte ? t('shell.till_open') : t('shell.till_closed')}
              </button>
            )}
            <button
              className="icon-btn"
              title={theme === 'dark' ? t('shell.theme_light') : t('shell.theme_dark')}
              onClick={toggleTheme}
            >◐</button>
            <div className="topbar-who" title={me?.email || ''}>
              <span className="av">{initiales(me)}</span>
              <span className="tw-txt">
                <b>{me?.nom || me?.email || t('shell.user')}</b>
                <span className="sub">{me?.role || t('shell.default_role')}</span>
              </span>
            </div>
            <button className="icon-btn" title={t('shell.logout')} onClick={onLogout}>⏻</button>
          </div>
        </div>
        {/* AU-DESSUS DU CONTENU, SOUS LA BARRE : c'est une invitation, pas une alerte. La poser
            en tete de page la ferait lire comme un avertissement ; la poser en bas, personne ne la
            verrait. Elle ne s'affiche que quand le navigateur dit que l'installation est possible,
            et un refus l'eteint pour de bon. */}
        <InstallerSurLeTelephone />
        {/* ⚠ `tabIndex={-1}` REND CE BLOC FOCUSABLE PAR PROGRAMME SANS L'AJOUTER A L'ORDRE DE
            TABULATION. C'est ce qui permet au lien d'evitement d'y deposer le focus, et a l'effet
            ci-dessus de l'y ramener a chaque changement d'ecran, sans creer un arret muet de plus
            pour ceux qui tabulent normalement.

            Un `<div>` et non un `<main>` : l'application de l'editeur en rend deja un plus bas, et
            deux reperes principaux imbriques ne veulent rien dire pour un lecteur d'ecran. */}
        <div id="contenu-principal" ref={contenuRef} tabIndex={-1}>
          {children}
        </div>
      </div>
    </div>
  )
}

// Ce que l'établissement fait vraiment, d'après ses capacités actives — pas d'après une chaîne
// écrite en dur. On ne cite que ce qui se voit dans le menu : annoncer « Porte-monnaie virtuel »
// sous le nom du site rendrait la ligne illisible sans rien apprendre à personne.
//
// La billetterie n'est pas une capacité : caisse et catalogue existent sur tous les établissements.
// Les libellés sont ceux du menu (même clé) : la ligne nomme ce qu'on y trouve.
const SOUS_TITRES = [
  ['controle_acces', 'nav.section.access'],
  ['reservation', 'nav.reservation'],
  ['boutique_en_ligne', 'nav.boutique'],
]

function sousTitreDe(capacites = []) {
  const parts = [t('shell.ticketing')]
  for (const [code, cle] of SOUS_TITRES) {
    if (capacites.includes(code)) parts.push(t(cle))
  }
  return parts.join(' · ')
}
