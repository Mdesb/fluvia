import { useEffect, useState, useCallback } from 'react'
import {
  api,
  membres,
  tokenStore,
  etablissementStore,
  setUnauthorizedHandler,
} from './api/client.js'
import Login from './pages/Login.jsx'
import AppShell from './components/AppShell.jsx'
import Caisse from './pages/Caisse.jsx'
import Catalogue from './pages/Catalogue.jsx'
import SessionCaisse from './pages/SessionCaisse.jsx'
import Clients from './pages/Clients.jsx'
import Reservation from './pages/Reservation.jsx'
import Supervision from './pages/Supervision.jsx'
import Pilotage from './pages/Pilotage.jsx'
import Boutique from './pages/Boutique.jsx'
import Comptabilite from './pages/Comptabilite.jsx'
import Personnel from './pages/Personnel.jsx'
import Parametres from './pages/Parametres.jsx'
import Piscine from './pages/Piscine.jsx'
import Patinoire from './pages/Patinoire.jsx'
import Padel from './pages/Padel.jsx'
import Musee from './pages/Musee.jsx'

export default function App() {
  const [booting, setBooting] = useState(true)
  const [authed, setAuthed] = useState(!!tokenStore.get())
  const [me, setMe] = useState(null)
  const [etablissements, setEtablissements] = useState([])
  const [etabActif, setEtabActif] = useState(etablissementStore.get() || '')
  const [onglet, setOnglet] = useState('caisse')
  const [session, setSession] = useState(null) // session de caisse ouverte (partagée)

  const deconnexion = useCallback(() => {
    tokenStore.clear()
    etablissementStore.clear()
    setAuthed(false)
    setMe(null)
    setEtablissements([])
    setEtabActif('')
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

  // Si l'onglet courant dépend d'une capacité ou d'une permission désormais absente, retour Caisse.
  useEffect(() => {
    const caps = me?.capacitesActives || []
    const droits = me?.droits || []
    const capRequise = { reservation: 'reservation', supervision: 'controle_acces', boutique: 'boutique_en_ligne' }
    const permRequise = {
      piscine: 'piscine.lire', patinoire: 'patinoire.lire', padel: 'padel.lire',
      musee: 'musee.lire', comptabilite: 'compta.lire', personnel: 'personnel.lire',
    }
    if (capRequise[onglet] && !caps.includes(capRequise[onglet])) setOnglet('caisse')
    else if (permRequise[onglet] && !droits.includes(permRequise[onglet])) setOnglet('caisse')
  }, [me, onglet])

  function changerEtablissement(id) {
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

  return (
    <AppShell
      me={me}
      etablissements={etablissements}
      etabActif={etabActif}
      onChangeEtab={changerEtablissement}
      onglet={onglet}
      onNav={setOnglet}
      onLogout={deconnexion}
      capacites={capacites}
      droits={droits}
    >
      {onglet === 'caisse' && (
        <Caisse
          me={me}
          etabActif={etabActif}
          etablissements={etablissements}
          session={session}
          capacites={me?.capacitesActives || []}
          onNav={setOnglet}
        />
      )}
      {onglet === 'session' && (
        <SessionCaisse me={me} etabActif={etabActif} session={session} onRefresh={rechargerSession} />
      )}
      {onglet === 'catalogue' && <Catalogue etabActif={etabActif} />}
      {onglet === 'reservation' && <Reservation etabActif={etabActif} />}
      {onglet === 'supervision' && <Supervision etabActif={etabActif} />}
      {onglet === 'pilotage' && (
        <Pilotage etabActif={etabActif} etablissements={etablissements} />
      )}
      {onglet === 'comptabilite' && <Comptabilite etabActif={etabActif} />}
      {onglet === 'clients' && <Clients etabActif={etabActif} />}
      {onglet === 'boutique' && <Boutique etabActif={etabActif} />}
      {onglet === 'piscine' && <Piscine etabActif={etabActif} />}
      {onglet === 'patinoire' && <Patinoire etabActif={etabActif} />}
      {onglet === 'padel' && <Padel etabActif={etabActif} />}
      {onglet === 'musee' && <Musee etabActif={etabActif} />}
      {onglet === 'personnel' && <Personnel etabActif={etabActif} />}
      {onglet === 'parametres' && (
        <Parametres etabActif={etabActif} etablissements={etablissements} />
      )}
    </AppShell>
  )
}
