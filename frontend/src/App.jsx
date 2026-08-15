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

export default function App() {
  const [booting, setBooting] = useState(true)
  const [authed, setAuthed] = useState(!!tokenStore.get())
  const [me, setMe] = useState(null)
  const [etablissements, setEtablissements] = useState([])
  const [etabActif, setEtabActif] = useState(etablissementStore.get() || '')
  const [onglet, setOnglet] = useState('caisse')

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

  function changerEtablissement(id) {
    setEtabActif(id)
    etablissementStore.set(id)
  }

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

  return (
    <AppShell
      me={me}
      etablissements={etablissements}
      etabActif={etabActif}
      onChangeEtab={changerEtablissement}
      onglet={onglet}
      onNav={setOnglet}
      onLogout={deconnexion}
    >
      {onglet === 'caisse' ? (
        <Caisse me={me} etabActif={etabActif} etablissements={etablissements} />
      ) : (
        <Catalogue etabActif={etabActif} />
      )}
    </AppShell>
  )
}
