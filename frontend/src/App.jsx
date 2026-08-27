import { Suspense, lazy, useCallback, useEffect, useState } from 'react'
import { setVocabulaireLocal } from './api/vocabulaire.js'
import {
  api,
  membres,
  tokenStore,
  etablissementStore,
  setUnauthorizedHandler,
} from './api/client.js'
import { aLeDroit } from './api/droits.js'
import Login from './pages/Login.jsx'
import AppShell from './components/AppShell.jsx'
import Dashboard from './pages/Dashboard.jsx'
import Caisse from './pages/Caisse.jsx'
import Catalogue from './pages/Catalogue.jsx'
import Clients from './pages/Clients.jsx'
import Reservation from './pages/Reservation.jsx'
import Supervision from './pages/Supervision.jsx'
import Support from './pages/Support.jsx'
import Autorisations from './pages/Autorisations.jsx'
import MentionsLegales from './pages/MentionsLegales.jsx'
import Pipeline from './pages/Pipeline.jsx'
import Projets from './pages/Projets.jsx'
import Documents from './pages/Documents.jsx'
import Social from './pages/Social.jsx'
import Sport from './pages/Sport.jsx'

// Ecrans de back-office charges a la demande : un caissier qui reste a sa caisse ne les
// telecharge jamais. Ceux qu'on ouvre plusieurs fois par jour — caisse, catalogue, clients,
// reservation — restent dans le paquet principal : leur decoupage ferait payer une attente
// repetee pour un gain unique.
const Piscine = lazy(() => import('./pages/Piscine.jsx'))
const Patinoire = lazy(() => import('./pages/Patinoire.jsx'))
const Finance = lazy(() => import('./pages/Finance.jsx'))
const Pilotage = lazy(() => import('./pages/Pilotage.jsx'))
const Boutique = lazy(() => import('./pages/Boutique.jsx'))
const Comptabilite = lazy(() => import('./pages/Comptabilite.jsx'))
const Facturation = lazy(() => import('./pages/Facturation.jsx'))
const Personnel = lazy(() => import('./pages/Personnel.jsx'))
const Parametres = lazy(() => import('./pages/Parametres.jsx'))
const Stock = lazy(() => import('./pages/Stock.jsx'))
const Musee = lazy(() => import('./pages/Musee.jsx'))
const Padel = lazy(() => import('./pages/Padel.jsx'))

// Un compte est « administrateur » s'il porte l'un des droits d'administration du socle sur
// l'établissement actif (matérialisés dans `me.droits`). Gouverne l'atterrissage sur le tableau
// de bord et la visibilité de son entrée de menu.
export function estAdministrateur(me) {
  const droits = me?.droits || []
  // `aLeDroit` et non `includes` : un administrateur porte la permission joker `*.*` et JAMAIS
  // `securite.gerer` en toutes lettres. L'egalite stricte rendait donc faux pour le compte le plus
  // puissant du logiciel — il n'atterrissait pas sur son tableau de bord et n'en voyait pas l'entree.
  return aLeDroit(droits, 'securite.gerer') || aLeDroit(droits, 'organisation.gerer')
}

export default function App() {
  const [booting, setBooting] = useState(true)
  const [authed, setAuthed] = useState(!!tokenStore.get())
  const [me, setMe] = useState(null)
  const [etablissements, setEtablissements] = useState([])
  const [etabActif, setEtabActif] = useState(etablissementStore.get() || '')
  const [onglet, setOnglet] = useState('caisse')
  // Enregistrement à ouvrir en arrivant sur l'écran, quand la navigation vient d'une recherche.
  // Consommé puis oublié par l'écran destinataire : le garder ferait rouvrir la même fiche à chaque
  // retour sur l'onglet, ce qui est déroutant et impossible à annuler.
  const [cible, setCible] = useState(null)
  const naviguer = (id, c = null) => {
    setOnglet(id)
    setCible(c)
  }
  const [landingApplique, setLandingApplique] = useState(false)
  const [session, setSession] = useState(null) // session de caisse ouverte (partagée)

  const deconnexion = useCallback(() => {
    tokenStore.clear()
    etablissementStore.clear()
    setAuthed(false)
    setMe(null)
    setEtablissements([])
    setEtabActif('')
    setOnglet('caisse')
    setLandingApplique(false)
  }, [])

  // 401 côté client => retour login.
  useEffect(() => {
    setUnauthorizedHandler(() => setAuthed(false))
  }, [])

  // Charge le contexte (établissements + profil) après connexion.
  // On récupère d'abord la liste réelle des établissements pour VALIDER l'établissement mémorisé
  // en localStorage : un identifiant périmé (établissement supprimé, accès retiré) est réécrit sur
  // le 1er établissement valide AVANT tout autre appel. Ainsi le X-Etablissement envoyé à /me (et
  // ensuite au CRM / reporting) est toujours sain — plus de 403 ni de capacités vides sur un état sale.
  const chargerContexte = useCallback(async () => {
    const liste = membres(await api.etablissements())
    setEtablissements(liste)
    const memorise = etablissementStore.get()
    const valide = liste.find((e) => e.id === memorise)
    const choisi = valide?.id || liste[0]?.id || ''
    if (choisi) etablissementStore.set(choisi)
    else etablissementStore.clear()
    setEtabActif(choisi)

    // LES MOTS DU METIER SONT POSES AVANT LE PREMIER RENDU, ET C'EST LE POINT.
    //
    // `mot()` est appelee depuis des colonnes de tableau et des titres, pendant le rendu. Charger le
    // vocabulaire APRES afficherait un ecran en langue par defaut, puis le meme ecran en langue du
    // metier -- un clignotement qui donne l'impression que le logiciel hesite sur ses propres termes.
    setVocabulaireLocal((valide || liste.find((e) => e.id === choisi))?.vocabulaire)

    // /me est désormais appelé avec l'établissement corrigé : capacitesActives correctes dès le 1er rendu.
    setMe(await api.me())
  }, [])

  useEffect(() => {
    let annule = false
    async function init() {
      if (!authed) {
        setBooting(false)
        return
      }
      try {
        await chargerContexte()
      } catch {
        if (!annule) deconnexion()
      } finally {
        if (!annule) setBooting(false)
      }
    }
    init()
    return () => {
      annule = true
    }
  }, [authed, chargerContexte, deconnexion])

  function apresConnexion() {
    setBooting(true)
    setAuthed(true)
  }

  // `capacitesActives` de /me dépend de l'établissement actif (en-tête X-Etablissement) : on
  // rafraîchit le profil dès qu'un établissement est choisi ou changé, sinon les capacités
  // (donc les entrées de menu Réservation / Supervision) restent vides.
  const rafraichirProfil = useCallback(async () => {
    try {
      setMe(await api.me())
    } catch {
      /* le profil de base reste en place */
    }
  }, [])

  useEffect(() => {
    if (authed && etabActif) rafraichirProfil()
  }, [authed, etabActif, rafraichirProfil])

  // Onglet d'arrivée : un profil administrateur atterrit sur le tableau de bord (au lieu de la
  // caisse). Appliqué une seule fois par session, après le 1er chargement du profil.
  useEffect(() => {
    if (me && !landingApplique) {
      if (estAdministrateur(me)) setOnglet('dashboard')
      setLandingApplique(true)
    }
  }, [me, landingApplique])

  // Si l'onglet courant dépend d'une capacité ou d'une permission désormais absente, retour Caisse.
  useEffect(() => {
    const caps = me?.capacitesActives || []
    const droits = me?.droits || []
    const capRequise = { reservation: 'reservation', supervision: 'controle_acces', boutique: 'boutique_en_ligne' }
    const permRequise = {
      piscine: 'piscine.lire', patinoire: 'patinoire.lire', padel: 'padel.lire',
      musee: 'musee.lire', comptabilite: 'compta.lire', personnel: 'personnel.lire',
      facturation: 'facturation.lire',
    }
    if (onglet === 'dashboard' && me && !estAdministrateur(me)) setOnglet('caisse')
    else if (capRequise[onglet] && !caps.includes(capRequise[onglet])) setOnglet('caisse')
    // Meme piege, consequence differente et plus penible : un porteur de joker etait RENVOYE a la
    // caisse depuis n'importe quel ecran protege, sans explication et sans moyen d'y rester.
    else if (permRequise[onglet] && !aLeDroit(droits, permRequise[onglet])) setOnglet('caisse')
  }, [me, onglet])

  function changerEtablissement(id) {
    // Changer d'etablissement change les mots : sans ca, on garderait le vocabulaire du salon
    // en arrivant sur la piscine, et << praticien >> designerait une ligne d'eau.
    setVocabulaireLocal(etablissements.find((e) => e.id === id)?.vocabulaire)
    setEtabActif(id)
    etablissementStore.set(id)
  }

  // Session de caisse ouverte sur le périmètre courant (partagée entre Caisse et l'écran Session/Z).
  const rechargerSession = useCallback(async () => {
    try {
      const liste = membres(await api.sessionsCaisse())
      setSession(liste.find((s) => s.etat === 'ouverte') || null)
    } catch {
      setSession(null)
    }
  }, [])

  useEffect(() => {
    if (authed && etabActif) rechargerSession()
    else setSession(null)
  }, [authed, etabActif, rechargerSession])

  if (booting) {
    return (
      <div className="center">
        <div className="spinner" />
      </div>
    )
  }

  if (!authed) {
    return <Login onConnecte={apresConnexion} />
  }

  const capacites = me?.capacitesActives || []
  const droits = me?.droits || []
  const estAdmin = estAdministrateur(me)

  return (
    <AppShell
      me={me}
      etablissements={etablissements}
      etabActif={etabActif}
      onChangeEtab={changerEtablissement}
      onglet={onglet}
      onNav={naviguer}
      onLogout={deconnexion}
      capacites={capacites}
      droits={droits}
      estAdmin={estAdmin}
    >
      {/* Une seule frontiere de suspension pour tout le contenu : les ecrans differes s'y
          rattachent, et un ecran deja charge ne la declenche pas. */}
      <Suspense fallback={<div className="center" style={{ minHeight: 240 }}><div className="spinner" /></div>}>
      {onglet === 'dashboard' && estAdmin && (
        <Dashboard etabActif={etabActif} etablissements={etablissements} droits={droits} onNav={naviguer} />
      )}
      {onglet === 'caisse' && (
        <Caisse
          me={me}
          etabActif={etabActif}
          etablissements={etablissements}
          session={session}
          capacites={me?.capacitesActives || []}
          droits={droits}
          onSessionRefresh={rechargerSession}
        />
      )}
      {onglet === 'catalogue' && <Catalogue etabActif={etabActif} cible={cible} onCibleConsommee={() => setCible(null)} droits={droits} />}
      {onglet === 'reservation' && <Reservation etabActif={etabActif} droits={droits} session={session} />}
      {onglet === 'supervision' && <Supervision etabActif={etabActif} />}
      {onglet === 'support' && <Support droits={droits} />}
      {onglet === 'autorisations' && <Autorisations droits={droits} />}
      {onglet === 'legal' && <MentionsLegales etabActif={etabActif} droits={droits} />}
      {onglet === 'affaires' && <Pipeline etabActif={etabActif} droits={droits} />}
      {onglet === 'projets' && <Projets etabActif={etabActif} droits={droits} />}
      {onglet === 'documents' && <Documents etabActif={etabActif} droits={droits} />}
      {onglet === 'social' && <Social etabActif={etabActif} droits={droits} />}
      {onglet === 'sport' && <Sport etabActif={etabActif} droits={droits} />}
      {onglet === 'pilotage' && (
        <Pilotage etabActif={etabActif} etablissements={etablissements} droits={droits} />
      )}
      {onglet === 'comptabilite' && <Comptabilite etabActif={etabActif} droits={droits} />}
      {onglet === 'facturation' && <Facturation etabActif={etabActif} droits={droits} />}
      {onglet === 'clients' && <Clients etabActif={etabActif} cible={cible} onCibleConsommee={() => setCible(null)} droits={droits} />}
      {onglet === 'boutique' && <Boutique etabActif={etabActif} droits={droits} />}
      {onglet === 'piscine' && <Piscine etabActif={etabActif} droits={droits} />}
      {onglet === 'patinoire' && <Patinoire etabActif={etabActif} droits={droits} />}
      {onglet === 'padel' && <Padel etabActif={etabActif} droits={droits} />}
      {onglet === 'musee' && <Musee etabActif={etabActif} droits={droits} />}
      {onglet === 'personnel' && <Personnel etabActif={etabActif} droits={droits} />}
      {onglet === 'stock' && <Stock etabActif={etabActif} droits={droits} />}
      {onglet === 'finance' && <Finance etabActif={etabActif} droits={droits} />}
      {onglet === 'parametres' && (
        <Parametres etabActif={etabActif} etablissements={etablissements} droits={droits} />
      )}
      </Suspense>
    </AppShell>
  )
}
