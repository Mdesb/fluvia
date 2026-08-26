import { useCallback, useEffect, useState } from 'react'
import { api, tokenStore, etablissementStore, setUnauthorizedHandler } from '../api/client.js'
import Login from '../pages/Login.jsx'
import Abonnements from './pages/Abonnements.jsx'
import Offres from './pages/Offres.jsx'
import Clients from './pages/Clients.jsx'
import Facturation from './pages/Facturation.jsx'
import Reglements from './pages/Reglements.jsx'

// Administration de l'éditeur (ED-6) — l'outil avec lequel l'éditeur pilote ses clients, ses offres
// et ses abonnements.
//
// TROISIÈME BRANCHE, ET PAS UN ONGLET DU BACK-OFFICE. `Root.jsx` choisit entre l'application des
// exploitants, la boutique publique et celle-ci, une fois, au démarrage. Ce sont trois produits pour
// trois publics : un exploitant de piscine n'a rien à faire dans la gestion des abonnements de
// l'éditeur, et il n'a surtout pas à en télécharger le code.
//
// LE CONTRÔLE D'ACCÈS EST CÔTÉ SERVEUR, PAS ICI. `/editor/subscriptions` répond 404 si
// l'établissement actif n'est pas l'éditeur. Cet écran ne rejoue donc AUCUNE règle d'autorisation :
// il affiche ce que le serveur consent à rendre, et le dit quand il ne rend rien. C'est D39 appliqué
// dans sa forme la plus sûre — ne pas filtrer du tout plutôt que filtrer à moitié.

export default function EditeurApp() {
  const [authed, setAuthed] = useState(!!tokenStore.get())
  const [me, setMe] = useState(null)
  const [onglet, setOnglet] = useState('abonnements')
  const [refuse, setRefuse] = useState(false)

  const deconnexion = useCallback(() => {
    tokenStore.clear()
    etablissementStore.clear()
    setAuthed(false)
    setMe(null)
    setRefuse(false)
  }, [])

  useEffect(() => {
    setUnauthorizedHandler(deconnexion)
  }, [deconnexion])

  useEffect(() => {
    if (!authed) return
    let vivant = true
    api
      .me()
      .then((profil) => {
        if (vivant) setMe(profil)
      })
      .catch(() => {
        if (vivant) deconnexion()
      })
    return () => {
      vivant = false
    }
  }, [authed, deconnexion])

  if (!authed) {
    return <Login onConnecte={() => setAuthed(true)} />
  }

  const onglets = [
    { id: 'abonnements', ic: '≡', label: 'Abonnements' },
    { id: 'offres', ic: '▥', label: 'Offres' },
    { id: 'clients', ic: '●', label: 'Clients' },
    { id: 'facturation', ic: '€', label: 'Facturation' },
    { id: 'reglements', ic: '⇄', label: 'Règlements' },
  ]

  return (
    <div className="editeur">
      <header className="editeur-barre">
        <div className="editeur-marque">
          <span className="editeur-point" aria-hidden="true" />
          <strong>Administration</strong>
        </div>

        <nav aria-label="Sections">
          {onglets.map((o) => (
            <button
              key={o.id}
              type="button"
              className={`btn ${onglet === o.id ? 'primary' : 'ghost'} sm`}
              aria-current={onglet === o.id ? 'page' : undefined}
              onClick={() => setOnglet(o.id)}
            >
              <span aria-hidden="true">{o.ic}</span> {o.label}
            </button>
          ))}
        </nav>

        <div className="editeur-compte">
          <span className="mut">{me?.email || ''}</span>
          <button type="button" className="btn ghost sm" onClick={deconnexion}>
            Se déconnecter
          </button>
        </div>
      </header>

      <main className="view">
        {/*
          Un refus vient du serveur et signifie une seule chose : cette session n'est pas celle de
          l'éditeur. On l'explique au lieu d'afficher un tableau vide — un écran vide laisse croire
          qu'il n'y a rien à voir, alors qu'il n'y a rien à voir POUR CE COMPTE.
        */}
        {refuse ? (
          <div className="empty">
            <p>
              Cet écran est réservé à l'établissement éditeur. Le compte connecté n'y est pas
              rattaché, ou l'établissement actif n'est pas le bon.
            </p>
          </div>
        ) : (
          <>
            {onglet === 'abonnements' && <Abonnements onRefus={() => setRefuse(true)} />}
            {onglet === 'offres' && <Offres />}
            {onglet === 'clients' && <Clients onRefus={() => setRefuse(true)} />}
            {onglet === 'facturation' && <Facturation onRefus={() => setRefuse(true)} />}
            {onglet === 'reglements' && <Reglements onRefus={() => setRefuse(true)} />}
          </>
        )}
      </main>
    </div>
  )
}
