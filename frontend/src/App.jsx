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

  // Charge le contexte (profil + établissements) après connexion.
  const chargerContexte = useCallback(async () => {
    const profil = await api.me()
    setMe(profil)
    const liste = membres(await api.etablissements())
    setEtablissements(liste)
    // Établissement actif : celui mémorisé s'il existe encore, sinon celui du profil, sinon le 1er.
    const memorise = etablissementStore.get()
    const valide = liste.find((e) => e.id === memorise)
    const choisi = valide?.id || profil?.etablissementActif || liste[0]?.id || ''
    setEtabActif(choisi)
    if (choisi) etablissementStore.set(choisi)
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

  // Si l'onglet courant dépend d'une capacité désormais absente, on retombe sur la Caisse.
  useEffect(() => {
    const caps = me?.capacitesActives || []
    const requis = { reservation: 'reservation', supervision: 'controle_acces' }
    if (requis[onglet] && !caps.includes(requis[onglet])) setOnglet('caisse')
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
      {onglet === 'clients' && <Clients etabActif={etabActif} />}
    </AppShell>
  )
}
