import { useCallback, useEffect, useState } from 'react'
import { api, membres, tokenStore, etablissementStore, setUnauthorizedHandler } from '../api/client.js'
import Login from '../pages/Login.jsx'
import Agenda from '../pages/Agenda.jsx'
import Support from '../pages/Support.jsx'
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
  const [etablissements, setEtablissements] = useState([])
  const [etabActif, setEtabActif] = useState(etablissementStore.get() || '')

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

  // ⚠ L'ÉTABLISSEMENT ACTIF SE POSE ICI, ET IL NE SE DEVINE PAS.
  //
  // Cet écran importait `etablissementStore` depuis toujours — pour l'EFFACER à la déconnexion, et
  // jamais pour le poser. Tant qu'il ne servait que `/editor/*`, qui n'est pas cadré sur
  // l'établissement, rien ne le signalait.
  //
  // Dès qu'on y branche un module cadré, deux défauts s'ouvrent, et aucun ne fait de bruit :
  // sans en-tête `X-Etablissement` une collection rend une liste VIDE — pas une erreur — donc un
  // écran normal qui annonce qu'il n'y a rien ; et si le même navigateur a servi l'application
  // client, `localStorage` porte déjà l'identifiant du site d'un exploitant.
  //
  // On CONFRONTE donc l'identifiant mémorisé à la liste renvoyée par le serveur, qui est jointe aux
  // affectations du compte : un identifiant étranger ou périmé n'y figure pas et tombe de lui-même.
  const chargerContexte = useCallback(async () => {
    const liste = membres(await api.etablissements())
    setEtablissements(liste)
    const memorise = etablissementStore.get()
    const choisi = liste.find((e) => e.id === memorise)?.id || liste[0]?.id || ''
    if (choisi) etablissementStore.set(choisi)
    else etablissementStore.clear()
    setEtabActif(choisi)
  }, [])

  useEffect(() => {
    if (!authed) return
    chargerContexte().catch(() => {
      /* Le refus est dit par les écrans, pas deviné ici. */
    })
  }, [authed, chargerContexte])

  // Le profil dépend de l'établissement ACTIF : les droits d'un compte ne sont pas les mêmes d'un
  // site à l'autre. Le recharger au changement est ce qui évite qu'un éditeur passé sur le site
  // d'un client garde à l'écran les droits de l'éditeur.
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
  }, [authed, etabActif, deconnexion])

  if (!authed) {
    return <Login onConnecte={() => setAuthed(true)} />
  }

  const onglets = [
    { id: 'abonnements', ic: '≡', label: 'Abonnements' },
    { id: 'offres', ic: '▥', label: 'Offres' },
    { id: 'clients', ic: '●', label: 'Clients' },
    { id: 'facturation', ic: '€', label: 'Facturation' },
    { id: 'reglements', ic: '⇄', label: 'Règlements' },
    { id: 'agenda', ic: '▦', label: 'Agenda' },
    { id: 'assistance', ic: '☏', label: 'Assistance' },
  ]

  const droits = me?.droits || []
  const nomEtabActif = etablissements.find((e) => e.id === etabActif)?.nom || ''

  return (
    <div>
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
          {/*
            LE SITE SUR LEQUEL ON TRAVAILLE EST TOUJOURS ÉCRIT, MÊME QUAND IL N'Y EN A QU'UN.
            Le jour où un accès d'assistance ouvre le site d'un client, la question « où suis-je ? »
            aura déjà sa réponse à l'écran. L'afficher seulement quand il y a un choix la ferait
            apparaître au moment précis où l'on n'y prête pas attention.
          */}
          {etablissements.length > 1 ? (
            <select
              className="select"
              value={etabActif}
              onChange={(e) => {
                etablissementStore.set(e.target.value)
                setEtabActif(e.target.value)
              }}
              aria-label="Établissement actif"
              title="Site sur lequel vous travaillez"
            >
              {etablissements.map((e) => (
                <option key={e.id} value={e.id}>{e.nom}</option>
              ))}
            </select>
          ) : (
            nomEtabActif && <span className="mut">{nomEtabActif}</span>
          )}
          <span className="mut">{me?.email || ''}</span>
          <button type="button" className="btn ghost sm" onClick={deconnexion}>
            Se déconnecter
          </button>
        </div>
      </header>

      {/*
        ⚠ `=== false` ET NON `!me?.estEditeur`. Tant que le profil n'est pas chargé, la propriété
        est `undefined` — un `!` afficherait « vous êtes chez un client » pendant le chargement, à
        chaque ouverture, y compris chez soi. Une alerte qui crie à tort est une alerte qu'on
        apprend à ignorer, et c'est celle-là qu'on ignorera le jour où elle sera vraie.
      */}
      {me?.estEditeur === false && (
        <div className="banner banner-warn" role="status">
          Vous travaillez sur <strong>{nomEtabActif || 'le site d’un client'}</strong>, pas sur
          l’établissement éditeur. Ce que vous écrivez ici appartient à ce client, et les chiffres
          affichés sont les siens.
        </div>
      )}

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
            {/*
              Les deux écrans de l'application client, tels quels : leur API est cadrée sur
              l'établissement, et l'éditeur en est un. Les recopier en « version éditeur » aurait
              produit deux agendas et deux messageries à corriger séparément — et une seule des deux
              le jour où l'on est pressé.
            */}
            {onglet === 'agenda' && <Agenda droits={droits} etabActif={etabActif} />}
            {onglet === 'assistance' && <Support droits={droits} etabActif={etabActif} me={me} />}
          </>
        )}
      </main>
    </div>
  )
}
