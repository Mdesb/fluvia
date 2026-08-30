import { useCallback, useEffect, useState } from 'react'
import { api, membres, tokenStore, etablissementStore, setUnauthorizedHandler } from '../api/client.js'
import Login from '../pages/Login.jsx'
import { aUnDesDroits } from '../api/droits.js'
import AppShell from '../components/AppShell.jsx'
import InstallerSurLeTelephone from '../components/InstallerSurLeTelephone.jsx'
import Agenda from '../pages/Agenda.jsx'
import Documents from '../pages/Documents.jsx'
import Finance from '../pages/Finance.jsx'
import MentionsLegales from '../pages/MentionsLegales.jsx'
import Parametres from '../pages/Parametres.jsx'
import Pilotage from '../pages/Pilotage.jsx'
import Pipeline from '../pages/Pipeline.jsx'
import Projets from '../pages/Projets.jsx'
import Sepa from '../pages/Sepa.jsx'
import Social from '../pages/Social.jsx'
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

  // ⚠ TOUT CE QUI SUIT EST CALCULÉ AVANT LE RETOUR ANTICIPÉ DE L'ÉCRAN DE CONNEXION.
  //
  // Une première version plaçait le `useEffect` de recentrage APRÈS `if (!authed) return <Login/>`.
  // React compte les hooks à chaque rendu et exige le même nombre : au moment précis où l'on se
  // connecte, le composant passait de trois hooks à quatre, et levait « Rendered more hooks than
  // during the previous render ». L'application ne s'ouvrait plus.
  //
  // Vite compilait sans rien dire — ce n'est pas une faute de syntaxe, c'est une règle d'exécution.
  // Un build vert sur du code qui plante à la première action.
  //
  // Ces valeurs ne dépendent que de l'état : les calculer pendant qu'on affiche la connexion ne
  // coûte que quelques tableaux filtrés sur un profil vide.
  const droits = me?.droits || []

  // LES ÉCRANS PROPRES À L'ÉDITEUR — sociétés abonnées, abonnements Fluvia, créances d'abonnement.
  //
  // ⚠ Ils suivent les droits depuis que le SERVEUR les applique (`EditorOnly::assertEditor(...)`).
  // Tant qu'il ne le faisait pas, les garder ici aurait donné l'illusion d'une protection — et une
  // illusion est pire qu'une absence, parce qu'on cesse de chercher.
  //
  // Cacher n'est pas protéger : ce filtre évite seulement à un agent d'assistance de cliquer sur
  // « Facturation » pour recevoir un refus. Si la garde serveur disparaissait, il ne rattraperait
  // rien — et c'est voulu : un filtre d'affichage qui rattrape une garde manquante la fait oublier.
  // Les cinq ecrans qui n'existent que pour l'editeur. Le reste — agenda, assistance, documents… —
  // sont les outils communs du produit, employes ici comme partout ailleurs.
  const EDITEUR = new Set(['abonnements', 'offres', 'clients', 'facturation', 'reglements'])

  const onglets = [
    { id: 'abonnements', ic: '≡', label: 'Abonnements', perms: ['editor.read_subscription'] },
    { id: 'offres', ic: '▥', label: 'Offres', perms: ['editor.manage_offer'] },
    { id: 'clients', ic: '●', label: 'Clients', perms: ['editor.read_customer'] },
    { id: 'facturation', ic: '€', label: 'Facturation', perms: ['editor.read_billing'] },
    { id: 'reglements', ic: '⇄', label: 'Règlements', perms: ['editor.read_billing'] },
  ]
    // ⚠ HORS DE L'ÉDITEUR, CES ÉCRANS RENDENT 404. Quand un accès d'assistance ouvre le site d'un
    // client, l'établissement actif n'est plus l'éditeur et `EditorOnly` refuse les sept
    // ressources. Les laisser visibles ferait cliquer un agent sur « Facturation » pour recevoir
    // une page introuvable — et lui ferait se demander si l'écran est cassé plutôt que s'il est au
    // bon endroit.
    //
    // `=== true` et non `!== false` : tant que le profil charge, la propriété est `undefined`. Avec
    // `!==`, les cinq onglets apparaîtraient puis disparaîtraient — un écran qui clignote et un
    // utilisateur qui clique sur ce qui s'en va.
    .filter(() => me?.estEditeur === true)
    .filter((o) => aUnDesDroits(droits, o.perms))

  // LES ÉCRANS DU MÉTIER DE L'ÉDITEUR — ceux de l'application client, tels quels.
  //
  // Leurs permissions sont calculées sur l'ÉTABLISSEMENT ACTIF : un employé qui porte `crm.lire`
  // chez l'éditeur voit ses affaires, un autre ne voit pas l'onglet. Aucune permission neuve n'est
  // nécessaire — c'est ce que l'éditeur gagne à être un établissement comme un autre.
  const ongletsMetier = [
    { id: 'affaires', ic: '◨', label: 'Affaires', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
    { id: 'projets', ic: '◱', label: 'Projets', perms: ['personnel.lire', 'personnel.gerer', 'organisation.gerer'] },
    { id: 'finance', ic: '€', label: 'Achats & trésorerie', perms: ['finance.read'] },
    { id: 'sepa', ic: '⇄', label: 'Prélèvements SEPA', perms: ['sepa.lire', 'compta.lire'] },
    { id: 'documents', ic: '🗎', label: 'Documents', perms: ['dms.read', 'dms.write'] },
    { id: 'social', ic: '◎', label: 'Publication sociale', perms: ['social.read_post', 'social.publish', 'social.read_account'] },
    { id: 'agenda', ic: '▤', label: 'Agenda' },
    { id: 'pilotage', ic: '◨', label: 'Reporting', perms: ['reporting.lire', 'reporting.configurer', 'reporting.planifier'] },
    { id: 'assistance', ic: '?', label: 'Assistance', perms: ['support.lire', 'support.ouvrir_ticket', 'support.lire_ticket_soi', 'support.traiter_ticket_n1', 'support.traiter_ticket_n2', 'support.administrer'] },
    { id: 'parametres', ic: '⚙', label: 'Paramètres', perms: ['securite.gerer', 'securite.lire', 'organisation.gerer', 'offre.gerer', 'caisse.gerer', 'crm.parametrer'] },
    { id: 'legal', ic: '§', label: 'Mentions légales', perms: ['organisation.gerer', 'boutique.gerer_vitrine'] },
  ].filter((o) => !o.perms || aUnDesDroits(droits, o.perms))
  const nomEtabActif = etablissements.find((e) => e.id === etabActif)?.nom || ''
  const visibles = [...onglets, ...ongletsMetier]

  // ⚠ L'ARRIVÉE SUIT CE QUI EST OUVERT. Le rendu ne consulte pas la liste des onglets : un onglet
  // retiré de la navigation continue de s'afficher si `onglet` vaut encore son identifiant. Sans
  // ce recentrage, un agent d'assistance atterrissait à chaque connexion sur « Abonnements » — un
  // écran absent de son menu, et refusé par le serveur.
  //
  // On attend que la liste soit connue : tant que le profil charge, elle est vide, et basculer à ce
  // moment-là ferait choisir puis rechoisir sous les yeux de l'utilisateur.
  // ⚠ `me` D'ABORD : tant que le profil n'est pas là, `droits` est vide, donc tous les onglets qui
  // exigent un droit ont disparu et `visibles` ne contient que ceux qui n'en exigent aucun. Agir
  // sur cette liste-là, c'est prendre un état de CHARGEMENT pour un état de fait — et poser
  // l'arrivée sur le seul onglet libre, pendant l'écran de connexion. Une fois connecté, cet onglet
  // reste valide, donc plus aucun recentrage n'a lieu : l'éditeur atterrit là pour toujours.
  //
  // Même motif que la bannière en `=== false`. On ne sait pas qu'il n'y a qu'un onglet ; on sait
  // qu'on ne sait pas encore.
  //
  // La dépendance porte sur les identifiants et non sur le tableau : `visibles` est reconstruit à
  // chaque rendu, et l'effet rejouerait à chaque fois pour ne rien faire.
  const idsVisibles = visibles.map((o) => o.id).join('|')
  useEffect(() => {
    if (!me || idsVisibles === '') return
    if (!idsVisibles.split('|').includes(onglet)) setOnglet(idsVisibles.split('|')[0])
  }, [me, idsVisibles, onglet])

  if (!authed) {
    // Le sous-titre nomme CE produit : la connexion est partagée avec le back-office, dont la
    // phrase par défaut parle de caisse et de catalogue — ce qui n'est pas ce qu'on administre ici.
    return <Login onConnecte={() => setAuthed(true)} sousTitre="Administration de Fluvia : clients, formules et assistance." />
  }

  // ⚠ LES SECTIONS SONT CONSTRUITES ICI, PAS DANS LA COQUILLE. `AppShell` filtre sur les droits et
  // les capacites ; il ne connait pas l'identite de tenant, et `visibles` porte deja le filtre
  // `estEditeur`. Lui apprendre cette notion pour un seul appelant la disperserait.
  const sections = [
    {
      section: 'Éditeur',
      items: visibles.filter((o) => EDITEUR.has(o.id)),
    },
    {
      section: 'Outils',
      items: visibles.filter((o) => !EDITEUR.has(o.id)),
    },
  ].filter((s) => s.items.length > 0)

  return (
    <AppShell
      me={me}
      etablissements={etablissements}
      etabActif={etabActif}
      onChangeEtab={(id) => { etablissementStore.set(id); setEtabActif(id) }}
      onglet={onglet}
      onNav={setOnglet}
      onLogout={deconnexion}
      droits={droits}
      nav={sections}
    >

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
            {onglet === 'affaires' && <Pipeline droits={droits} etabActif={etabActif} onNaviguer={setOnglet} />}
            {onglet === 'projets' && <Projets droits={droits} etabActif={etabActif} />}
            {onglet === 'finance' && <Finance droits={droits} etabActif={etabActif} />}
            {onglet === 'sepa' && <Sepa droits={droits} etabActif={etabActif} />}
            {onglet === 'documents' && <Documents droits={droits} etabActif={etabActif} />}
            {onglet === 'social' && <Social droits={droits} etabActif={etabActif} />}
            {onglet === 'agenda' && <Agenda droits={droits} etabActif={etabActif} />}
            {onglet === 'pilotage' && <Pilotage droits={droits} etabActif={etabActif} etablissements={etablissements} />}
            {onglet === 'assistance' && <Support droits={droits} etabActif={etabActif} me={me} />}
            {onglet === 'parametres' && <Parametres droits={droits} etabActif={etabActif} etablissements={etablissements} estEditeur={me?.estEditeur === true} />}
            {onglet === 'legal' && <MentionsLegales droits={droits} etabActif={etabActif} />}
          </>
        )}
      </main>

      {/*
        LA BANNIÈRE D'INSTALLATION, ABSENTE JUSQU'ICI DE CETTE COQUILLE.
        Elle n'était montée que dans celle de l'application client. Or ce sont les employés de
        l'éditeur qui en ont le plus besoin : un agent qui prend un ticket à 7 h du matin le fait
        depuis son téléphone, pas depuis un poste de guichet.

        Elle ne s'affiche que si le navigateur émet `beforeinstallprompt` — donc seulement quand
        l'installation est réellement possible — et jamais deux fois après un refus.
      */}
      <InstallerSurLeTelephone />
    </AppShell>
  )
}
