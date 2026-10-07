import { Suspense, lazy, useCallback, useEffect, useState } from 'react'
import { setVocabulaireLocal } from './api/vocabulaire.js'
import {
  api,
  membres,
  tokenStore,
  etablissementStore,
  supportStore,
  setUnauthorizedHandler,
} from './api/client.js'
import { aLeDroit } from './api/droits.js'
import Login from './pages/Login.jsx'
import AccesCompte, { lireDemandeDeCompte } from './pages/AccesCompte.jsx'
import AppShell, { ongletsConnus } from './components/AppShell.jsx'
import FrontiereErreur from './components/FrontiereErreur.jsx'
import Dashboard from './pages/Dashboard.jsx'
import Modules from './pages/Modules.jsx'
import BandeauSupport from './components/BandeauSupport.jsx'
import ControleBillet from './components/ControleBillet.jsx'
import Caisse from './pages/Caisse.jsx'
import Catalogue from './pages/Catalogue.jsx'
import Clients from './pages/Clients.jsx'
import Reservation from './pages/Reservation.jsx'
import Supervision from './pages/Supervision.jsx'
import Support from './pages/Support.jsx'
import Autorisations from './pages/Autorisations.jsx'
import MentionsLegales from './pages/MentionsLegales.jsx'
import Campagnes from './pages/Campagnes.jsx'
import Pipeline from './pages/Pipeline.jsx'
import Projets from './pages/Projets.jsx'
import Documents from './pages/Documents.jsx'
import Social from './pages/Social.jsx'
import Sport from './pages/Sport.jsx'
import Abonnements from './pages/Abonnements.jsx'

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
// Différé : l'agenda embarque une grille horaire et trois vues. Un caissier qui reste à sa caisse
// ne le télécharge jamais.
const Agenda = lazy(() => import('./pages/Agenda.jsx'))
// Différés comme la comptabilité, dont ils partagent les composants : trois écrans de gestion
// financière qu'un caissier n'ouvrira jamais n'ont pas à peser sur le premier chargement.
const Sepa = lazy(() => import('./pages/Sepa.jsx'))
const Recouvrement = lazy(() => import('./pages/Recouvrement.jsx'))
const RelanceRecettes = lazy(() => import('./pages/RelanceRecettes.jsx'))
const PlacesLiberees = lazy(() => import('./pages/PlacesLiberees.jsx'))
const Sejours = lazy(() => import('./pages/Sejours.jsx'))
const Groupes = lazy(() => import('./pages/Groupes.jsx'))
const JournalPassages = lazy(() => import('./pages/JournalPassages.jsx'))
const Casiers = lazy(() => import('./pages/Casiers.jsx'))

// Les ecrans qui ont change d'adresse, et ou ils sont partis. Une entree ici vaut mieux qu'un
// « cet ecran n'existe pas » servi a quelqu'un dont le favori designait quelque chose de reel.
const DEMENAGES = {
  topologie_acces: {
    libelle: 'Topologie & lecteurs',
    ou: 'Paramètres › Contrôle d’accès',
    vers: 'parametres',
    params: { sousOnglet: 'acces' },
  },
}
const Cautions = lazy(() => import('./pages/Cautions.jsx'))
// Differe pour la meme raison : un caissier n'enrole pas de terminal et ne bloque pas de badge.
const Acces = lazy(() => import('./pages/Acces.jsx'))
// Differe : une demande d'effacement se traite quelques fois par an. L'ecran ne doit peser sur
// le premier chargement de personne -- mais il doit exister, ce qui n'etait pas le cas.
const DonneesPersonnelles = lazy(() => import('./pages/DonneesPersonnelles.jsx'))
// Differe : la documentation de l'API ne s'ouvre qu'a l'occasion d'une integration, jamais en
// exploitation courante. Elle n'a pas a peser sur le premier chargement.
const DocumentationApi = lazy(() => import('./pages/DocumentationApi.jsx'))
// Differe : une reprise initiale se fait a la mise en route d'un site, pas tous les jours. L'ecran
// ne doit peser sur aucun premier chargement -- mais il doit exister, sans quoi ses quatre routes
// serveur restent injoignables.
const Imports = lazy(() => import('./pages/Imports.jsx'))

import { allerA, ecrireHash, lireHash } from './api/url.js'

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

// ⚠ LE MODE SUPPORT SE DECLARE AVANT LE PREMIER RENDU, PAS DANS UN `useEffect`.
//
// `etabActif` est initialise depuis `etablissementStore.get()` a la toute premiere ligne du
// composant. Poser le contexte de support dans un effet l'aurait fait arriver APRES : le premier
// rendu serait parti sur l'etablissement de l'agent, les premieres requetes aussi, et l'onglet
// aurait affiche une fraction de seconde les donnees du mauvais etablissement avant de basculer.
// Sur un ecran de caisse, cette fraction de seconde suffit a cliquer.
//
// D'ou une lecture au chargement du module, une seule fois, avant tout React.
//
// ⚠ ET L'URL EST NETTOYEE DANS LA FOULEE. Sans cela, `/?support=<id>` reste dans la barre : le lien
// se copie, s'envoie, se met en favori — et rouvre un onglet qui se croit en mode support alors
// qu'aucun acces n'a ete ouvert. Le bandeau parlerait dans le vide, et pire, il aurait l'air
// legitime. Ce qui fait foi est l'acces cote serveur ; ce parametre n'est qu'un passage.
function amorcerModeSupport() {
  try {
    const params = new URLSearchParams(window.location.search)
    const cible = params.get('support')
    if (!cible) return

    supportStore.set({ etablissementId: cible })

    params.delete('support')
    const reste = params.toString()
    window.history.replaceState(
      window.history.state,
      '',
      window.location.pathname + (reste ? `?${reste}` : '') + window.location.hash,
    )
  } catch {
    // URL illisible ou stockage refuse : l'onglet se comporte comme un onglet ordinaire. Le repli
    // d'un mecanisme d'acces est de ne pas ouvrir.
  }
}

amorcerModeSupport()

export default function App() {
  const [booting, setBooting] = useState(true)
  // Lue une seule fois, au premier rendu : la fonction nettoie l'URL de son jeton au
  // passage, donc la rappeler rendrait `null` et perdrait la demande.
  const [demandeCompte, setDemandeCompte] = useState(lireDemandeDeCompte)
  const [authed, setAuthed] = useState(!!tokenStore.get())
  const [me, setMe] = useState(null)
  const [etablissements, setEtablissements] = useState([])
  const [etabActif, setEtabActif] = useState(etablissementStore.get() || '')
  // L'ONGLET VIT DANS L'URL, ET C'EST CE QUI REND LE RESTE UTILE.
  //
  // L'application ne routait sur rien : `onglet` était un état de composant, l'URL ne bougeait
  // jamais, et un rechargement ramenait toujours à la caisse. Mettre les filtres d'un écran dans
  // l'URL sans y mettre l'écran lui-même n'aurait servi à rien -- on serait revenu sur la caisse
  // avec des filtres pointant une liste qu'on ne regarde pas.
  //
  // Le jeton dure une heure. Ce que ça change concrètement : après une expiration de session, on
  // se reconnecte et on retombe sur l'écran qu'on avait sous les yeux, filtres compris, au lieu de
  // tout refaire. Voir `api/url.js`.
  const [onglet, setOngletBrut] = useState(() => lireHash().onglet || 'caisse')

  // ⚠ CE QUE L'ADRESSE DEMANDAIT AVANT QUE NOUS N'Y TOUCHIONS, ET POURQUOI IL FAUT LE CAPTURER ICI.
  //
  // L'atterrissage sur le tableau de bord (plus bas) teste `!lireHash().onglet` pour ne pas ecraser
  // un lien profond. Mais l'effet de montage qui pose la premiere entree d'historique ecrit
  // `#onglet=caisse` AVANT que `/me` n'ait repondu : quand l'atterrissage s'execute, l'adresse dit
  // toujours `caisse`, et la bascule ne se declenche JAMAIS. Pour personne, depuis toujours.
  //
  // Maxime, en revue : « avec le compte administrateur socle regisseur j'arrive directement sur la
  // caisse ». Le code faisait deja ce qu'il voulait ; il se faisait devancer par lui-meme.
  //
  // Un initialiseur de `useState` s'execute pendant le PREMIER RENDU, donc avant tout effet. C'est
  // le seul endroit ou l'adresse est encore celle du navigateur et pas la notre.
  const [ongletDemandeAuChargement] = useState(() => lireHash().onglet || null)
  // CHANGER D’ÉCRAN EMPILE UNE ENTRÉE D’HISTORIQUE, ET C’EST TOUT LE SUJET.
  //
  // Maxime : « quand on clique sur le bouton retour du navigateur, on change carrément de page ».
  // La cause était ici : `ecrireHash` sans `pousser` écrit par `replaceState`, donc naviguer de la
  // caisse au catalogue puis aux clients laissait UNE seule entrée dans l'historique. Le
  // « Précédent » du navigateur ne pouvait alors que sortir de l'application — il faisait
  // exactement ce qu'on lui demandait, il n'y avait rien d'autre où aller.
  //
  // Un bouton « retour » posé dans chaque écran n'aurait pas réparé ça : il aurait ajouté un
  // second geste à côté de celui que les gens font déjà, en laissant le premier casser. Le
  // navigateur porte déjà le geste ; il fallait lui donner de quoi reculer.
  //
  // Les paramètres du précédent sont abandonnés au changement d'écran : ils ne veulent rien dire
  // ailleurs. L'entrée empilée porte donc l'onglet seul, et le retour restitue l'écran — les
  // filtres de la liste qu'on quitte, eux, sont dans SON entrée d'historique à elle.
  const setOnglet = useCallback((id) => {
    setOngletBrut((precedent) => {
      if (id !== precedent) ecrireHash(id, {}, { pousser: true })
      return id
    })
  }, [])

  // La première entrée doit exister avant qu'on empile dessus : sans elle, l'écran d'arrivée n'a
  // pas d'adresse, et le premier retour sort de l'application au lieu d'y revenir. On la pose en
  // REMPLAÇANT (pas en empilant) : elle décrit où l'on est déjà.
  useEffect(() => {
    if (!lireHash().onglet) ecrireHash(onglet, {})
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Le bouton « Précédent » du navigateur change le hash sans rien démonter.
  useEffect(() => {
    function surChangement() {
      const cible = lireHash().onglet
      if (cible) setOngletBrut(cible)
    }
    window.addEventListener('hashchange', surChangement)
    window.addEventListener('popstate', surChangement)
    return () => {
      window.removeEventListener('hashchange', surChangement)
      window.removeEventListener('popstate', surChangement)
    }
  }, [])

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
      // Une URL qui nomme un écran l'emporte sur l'atterrissage par défaut : sans cette garde, on
      // ouvre un lien vers une fiche client et on arrive sur le tableau de bord.
      //
      // ⚠ ON LIT LA CAPTURE, PAS L'ADRESSE COURANTE. `lireHash()` ici rendait toujours un onglet —
      // celui que l'effet de montage venait d'ecrire — donc la condition etait toujours fausse.
      if (estAdministrateur(me) && !ongletDemandeAuChargement) setOnglet('dashboard')
      setLandingApplique(true)
    }
  }, [me, landingApplique, ongletDemandeAuChargement])

  // Si l'onglet courant dépend d'une capacité ou d'une permission désormais absente, retour Caisse.
  useEffect(() => {
    // TANT QUE LE PROFIL N'EST PAS CHARGÉ, ON NE SAIT RIEN — ET NE RIEN SAVOIR N'EST PAS UN REFUS.
    //
    // Défaut introduit en mettant l'onglet dans l'URL, et trouvé en ouvrant `#facturation` : `me`
    // vaut `null` pendant le premier rendu, donc `droits` vaut `[]`, donc cette garde concluait
    // « permission absente » et renvoyait à la caisse AVANT que le serveur ait répondu. Le lien
    // profond ne marchait que pour les écrans sans permission requise.
    //
    // Invisible avant, parce que l'onglet de départ était déjà la caisse : le renvoi ne changeait
    // rien. C'est exactement pourquoi un défaut dormant se réveille au premier usage nouveau.
    if (!me) return
    const caps = me.capacitesActives || []
    const droits = me.droits || []
    const capRequise = { reservation: 'reservation', supervision: 'controle_acces', acces: 'controle_acces', boutique: 'boutique_en_ligne' }
    const permRequise = {
      piscine: 'piscine.lire', patinoire: 'patinoire.lire', padel: 'padel.lire',
      musee: 'musee.lire', comptabilite: 'compta.lire', personnel: 'personnel.lire',
      facturation: 'facturation.lire',
      // PAS D'ENTREE ICI POUR `sepa`, `recouvrement` ET `caution`, ET C'EST VOLONTAIRE.
      // Cette garde ne sait tester QU'UNE permission, or ces trois ecrans s'ouvrent a plusieurs
      // (`sepa.lire` OU `compta.lire`, `caution.lire` OU `caution.piloter`, ...) exactement comme le
      // fait le serveur. Y mettre une seule permission renverrait a la caisse un comptable qui a le
      // droit de les lire -- le piege decrit deux lignes plus bas, et paye une fois deja.
      // Le filtrage se fait donc la ou il sait exprimer un OU : les `perms` du menu (`AppShell`).
    }
    if ((onglet === 'dashboard' || onglet === 'api') && me && !estAdministrateur(me)) setOnglet('caisse')
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

  // METTRE UN MODULE EN SERVICE CHANGE LE MENU, ET LE MENU VIENT DE `/me`.
  //
  // La colonne de gauche est construite sur `me.capacitesActives`. Sans ce rappel, on met
  // « Réservation » en service depuis les paramètres, le serveur enregistre, et l'entrée
  // n'apparaît qu'au prochain rechargement complet de la page — l'action a l'air de n'avoir rien
  // fait, ce qui est exactement la conclusion qu'on veut éviter sur un écran d'activation.
  const rechargerMe = useCallback(async () => {
    setMe(await api.me())
  }, [])

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

  // ⚠ AVANT `booting`, ET AVANT LA CONNEXION. Un lien d'activation ou de reinitialisation
  // arrive sans session : attendre `/me` ferait clignoter l'ecran de connexion, et la
  // deconnexion qui suit un `/me` refuse effacerait l'etat en cours de saisie.
  if (demandeCompte) {
    return (
      <AccesCompte
        demande={demandeCompte}
        onTermine={() => { setDemandeCompte(null); window.location.replace('/') }}
      />
    )
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

  const capacites = me?.capacitesActives || []
  const droits = me?.droits || []
  const estAdmin = estAdministrateur(me)
  // Lu a chaque rendu et non mis en etat : `BandeauSupport` efface le contexte quand le serveur a
  // referme l'acces, et cette lecture-ci doit suivre. Un etat local aurait garde « en mode
  // support » apres la fin, donc un selecteur toujours cache dans un onglet redevenu ordinaire.
  const enModeSupport = Boolean(supportStore.get())

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
      epingle={enModeSupport}
      bandeau={
        enModeSupport ? (
          <BandeauSupport
            etablissements={etablissements}
            onFin={() => setEtabActif(etablissementStore.get() || '')}
          />
        ) : null
      }
    >
      {/* Une seule frontiere de suspension pour tout le contenu : les ecrans differes s'y
          rattachent, et un ecran deja charge ne la declenche pas. */}
      {/* ET UNE FRONTIÈRE D'ERREUR AUTOUR DU CONTENU, PAS AUTOUR DE LA COQUILLE.
          Le 28/08, un identifiant oublié dans l'écran de reporting a fait passer TOUTE
          l'application à l'écran blanc — plus de menu, plus de barre du haut, plus rien.
          Ici, un écran qui plante affiche son erreur et laisse le menu debout : on part
          ailleurs. La clé `onglet` remet l'ardoise propre au changement d'écran. */}
      <FrontiereErreur cle={onglet}>
      {/* UNE ADRESSE INCONNUE RENDAIT UNE PAGE BLANCHE, ET C'EST MOI QUI L'AI RENDUE ATTEIGNABLE.
          Tant que l'onglet vivait dans l'état du composant, on ne pouvait pas en demander un qui
          n'existe pas. Depuis qu'il est dans l'URL, un favori d'avant la refonte, un lien tronqué
          dans un message ou un écran renommé suffisent : `#topologie` au lieu de
          `#topologie_acces` rendait la coquille — menu, en-tête, bannière — et RIEN dans la zone
          de contenu. Pas de message, pas d'erreur en console, un écran qui a l'air de charger
          pour toujours.
          ON NE REDIRIGE PAS EN SILENCE : ça donnerait l'impression que le lien a marché, et la
          personne chercherait ailleurs pourquoi elle n'a pas ce qu'elle demandait. */}
      {/* ⚠ UN ECRAN QUI A DEMENAGE N'EST PAS UN ECRAN QUI N'EXISTE PLUS, et le message generique
          ci-dessous dirait « cet écran n'existe pas » d'un écran qui existe toujours. On le nomme,
          on dit où il est parti, et on propose d'y aller — SANS rediriger : voir la note du bloc
          suivant, qui explique pourquoi une redirection muette est pire que le panneau. */}
      {!ongletsConnus().has(onglet) && DEMENAGES[onglet] && (
        <div className="view">
          <div className="empty">
            <b>« {DEMENAGES[onglet].libelle} » a déménagé.</b>
            <p className="hint">
              Cet écran est devenu un onglet de <b>{DEMENAGES[onglet].ou}</b>. Votre lien n’est pas
              cassé — il pointe l’ancienne adresse, <code>#{onglet}</code>.
            </p>
            <button
              className="btn primary"
              type="button"
              onClick={() => allerA(DEMENAGES[onglet].vers, DEMENAGES[onglet].params)}
            >
              Aller à {DEMENAGES[onglet].ou}
            </button>
          </div>
        </div>
      )}

      {!ongletsConnus().has(onglet) && !DEMENAGES[onglet] && (
        <div className="view">
          <div className="empty">
            <b>Cet écran n’existe pas, ou il a été renommé.</b>
            <p className="hint">
              L’adresse demandée est <code>#{onglet}</code>. Si elle vient d’un favori ou d’un lien
              reçu, elle a pu vieillir : le reste de l’application fonctionne, choisissez un écran
              dans le menu.
            </p>
            <button className="btn" type="button" onClick={() => naviguer(estAdmin ? 'dashboard' : 'caisse')}>
              {estAdmin ? 'Aller au tableau de bord' : 'Aller à la caisse'}
            </button>
          </div>
        </div>
      )}
      <Suspense fallback={<div className="center" style={{ minHeight: 240 }}><div className="spinner" /></div>}>
      {onglet === 'dashboard' && estAdmin && (
        <Dashboard etabActif={etabActif} etablissements={etablissements} droits={droits} onNav={naviguer} />
      )}
      {/* La boutique de modules. Meme garde `estAdmin` que l'entree de menu : Maxime a arbitre
          « achat ouvert a qui administre l'etablissement ». */}
      {onglet === 'modules' && estAdmin && <Modules capacites={capacites} me={me} />}
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
      {/* Composter : son propre ecran, garde par son propre droit. Un guide qui controle
          l entree d une visite n a aucune raison d avoir acces a la caisse. */}
      {onglet === 'composter' && <ControleBillet ecranEntier />}
      {onglet === 'catalogue' && <Catalogue etabActif={etabActif} cible={cible} onCibleConsommee={() => setCible(null)} droits={droits} capacites={capacites} />}
      {onglet === 'reservation' && <Reservation etabActif={etabActif} droits={droits} session={session} />}
      {/* Même raison qu'à la caisse : on remonte l'écran plutôt que de le remettre à zéro. */}
      {onglet === 'supervision' && <Supervision key={etabActif} etabActif={etabActif} droits={droits} />}
      {onglet === 'acces' && <Acces etabActif={etabActif} droits={droits} />}
      {onglet === 'agenda' && <Agenda droits={droits} etabActif={etabActif} />}
      {/* `me` porte l'identifiant du lecteur, et c'est ce qui donne un CÔTÉ aux bulles : sans lui
          la messagerie ne sait pas lesquelles sont les siennes et les aligne toutes à gauche. */}
      {onglet === 'support' && <Support droits={droits} etabActif={etabActif} me={me} />}
      {onglet === 'autorisations' && <Autorisations droits={droits} etabActif={etabActif} />}
      {onglet === 'legal' && <MentionsLegales etabActif={etabActif} droits={droits} />}
      {onglet === 'api' && <DocumentationApi />}
      {onglet === 'affaires' && <Pipeline etabActif={etabActif} droits={droits} onNaviguer={naviguer} />}
      {onglet === 'campagnes' && <Campagnes etabActif={etabActif} droits={droits} />}
      {onglet === 'projets' && <Projets etabActif={etabActif} droits={droits} />}
      {onglet === 'documents' && <Documents etabActif={etabActif} droits={droits} />}
      {onglet === 'imports' && <Imports etabActif={etabActif} droits={droits} />}
      {onglet === 'social' && <Social etabActif={etabActif} droits={droits} />}
      {onglet === 'sport' && <Sport etabActif={etabActif} droits={droits} />}
      {onglet === 'abonnements' && <Abonnements droits={droits} session={session} />}
      {onglet === 'pilotage' && (
        <Pilotage etabActif={etabActif} etablissements={etablissements} droits={droits} />
      )}
      {onglet === 'comptabilite' && <Comptabilite etabActif={etabActif} droits={droits} />}
      {onglet === 'sepa' && <Sepa etabActif={etabActif} droits={droits} />}
      {onglet === 'recouvrement' && <Recouvrement etabActif={etabActif} droits={droits} />}
      {onglet === 'relance_recettes' && <RelanceRecettes etabActif={etabActif} droits={droits} />}
      {onglet === 'places_liberees' && <PlacesLiberees etabActif={etabActif} droits={droits} />}
      {onglet === 'sejours' && <Sejours etabActif={etabActif} droits={droits} />}
      {onglet === 'groupes' && <Groupes etabActif={etabActif} droits={droits} />}
      {onglet === 'journal_passages' && <JournalPassages etabActif={etabActif} />}
      {onglet === 'casiers' && <Casiers etabActif={etabActif} droits={droits} />}
      {onglet === 'caution' && <Cautions etabActif={etabActif} droits={droits} />}
      {onglet === 'facturation' && <Facturation etabActif={etabActif} droits={droits} onNaviguer={naviguer} />}
      {onglet === 'clients' && <Clients etabActif={etabActif} cible={cible} onCibleConsommee={() => setCible(null)} droits={droits} />}
      {onglet === 'rgpd' && <DonneesPersonnelles etabActif={etabActif} droits={droits} />}
      {onglet === 'boutique' && <Boutique etabActif={etabActif} droits={droits} />}
      {onglet === 'piscine' && <Piscine etabActif={etabActif} droits={droits} />}
      {onglet === 'patinoire' && <Patinoire etabActif={etabActif} droits={droits} envoiCourriel={me?.envoiCourrielBranche === true} />}
      {onglet === 'padel' && <Padel etabActif={etabActif} droits={droits} />}
      {onglet === 'musee' && <Musee etabActif={etabActif} droits={droits} />}
      {onglet === 'personnel' && <Personnel etabActif={etabActif} droits={droits} />}
      {onglet === 'stock' && <Stock etabActif={etabActif} droits={droits} />}
      {onglet === 'finance' && <Finance etabActif={etabActif} droits={droits} />}
      {onglet === 'parametres' && (
        <Parametres etabActif={etabActif} etablissements={etablissements} droits={droits} onCapacitesChangees={rechargerMe} estEditeur={me?.estEditeur === true} me={me} envoiCourriel={me?.envoiCourrielBranche === true} />
      )}
      </Suspense>
      </FrontiereErreur>
    </AppShell>
  )
}
