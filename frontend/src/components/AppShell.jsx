import { useEffect, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import RechercheGlobale from './RechercheGlobale.jsx'
import { aLeDroit, aUnDesDroits } from '../api/droits.js'
import { profondeurHistorique } from '../api/url.js'
import Cloche from './Cloche.jsx'
import InstallerSurLeTelephone from './InstallerSurLeTelephone.jsx'

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
// LES ONGLETS QUE L'APPLICATION CONNAIT, DERIVES DU MENU ET NON RECOPIES.
//
// `App.jsx` en a besoin pour distinguer une adresse valide d'une adresse inventee. Recopier la
// liste la-bas aurait cree deux listes a maintenir — le defaut qu'on repare toute la semaine.
// Mesure du 29/08 : les 34 identifiants du menu et les 34 aiguillages de `App.jsx` etaient
// EXACTEMENT les memes, ce qui rend cette derivation sure.
//
// ⚠ Un ecran aiguille sans entree de menu serait ici tenu pour inconnu. C'est volontaire : tout
// ecran a besoin d'un chemin, et un ecran sans entree de menu est deja un defaut. Si le cas
// devenait legitime, il s'ajouterait ICI, une fois, et pas dans une seconde liste.
export function ongletsConnus() {
  return new Set(NAV.flatMap((section) => section.items.map((item) => item.id)))
}

const NAV = [
  {
    section: 'Exploitation',
    items: [
      { id: 'dashboard', ic: '⌂', label: 'Tableau de bord', admin: true },
      // La caisse sert le caissier comme le responsable : encaisser, ouvrir une session, consulter.
      // Exiger le seul `caisse.lire` retirerait l'écran à un caissier qui n'a que les droits de vente.
      { id: 'caisse', ic: '▤', label: 'Caisse', perms: ['caisse.lire', 'caisse.ouvrir', 'vente.creer', 'vente.encaisser'] },
      // ⚠ SON PROPRE DROIT, ET C'EST TOUT L'INTERET DE CETTE ENTREE. Composter un billet n'est pas
      // un geste de caisse : un guide qui controle l'entree d'une visite n'a aucune raison d'avoir
      // acces au tiroir-caisse. Le mettre dans « Caisse » obligeait a donner ce droit pour une
      // raison qui n'est pas la sienne — et personne ne pense a retirer un droit donne de biais.
      { id: 'composter', ic: '✓', label: 'Composter', perm: 'acces.controler' },
      { id: 'catalogue', ic: '▥', label: 'Catalogue', perms: ['offre.lire', 'offre.gerer', 'offre.creer', 'offre.modifier'] },
      { id: 'reservation', ic: '◷', label: 'Réservation', cap: 'reservation' },
      // Écran métier de l'établissement (une seule entrée visible selon le type de site).
      { id: 'piscine', ic: '≈', label: 'Piscine', perm: 'piscine.lire' },
      { id: 'patinoire', ic: '❆', label: 'Patinoire', perm: 'patinoire.lire' },
      { id: 'padel', ic: '◍', label: 'Padel', perm: 'padel.lire' },
      { id: 'musee', ic: '⛫', label: 'Musée', perm: 'musee.lire' },
      // Ouvert le 27/08, et pas pour les abonnements : `EvenementSOS` portait un statut
      // << ouverte >> et une operation << traiter >> SANS AUCUN ECRAN. Une alarme qu'aucune
      // interface ne montre cree la croyance qu'on serait prevenu.
      { id: 'sport', ic: '⬤', label: 'Sport & fitness', perms: ['sport.lire', 'sport.gerer', 'sport.superviser_nocturne'] },
    ],
  },
  {
    section: 'Contrôle d’accès',
    items: [
      { id: 'supervision', ic: '◉', label: 'Supervision', cap: 'controle_acces' },
      // Regarder ne suffisait pas : dix-huit operations exposees, deux atteignables. Bloquer un
      // badge perdu et appairer une carte sont les deux gestes les plus frequents d'un exploitant,
      // et aucun des deux n'etait possible depuis l'application.
      { id: 'acces', ic: '▭', label: 'Badges & terminaux', cap: 'controle_acces', perms: ['acces.lire', 'acces.appairer', 'acces.bloquer_support', 'acces.gerer'] },
      // L'installation du contrôle d'accès : le plan du site, les lecteurs, et le journal complet.
      // Même garde que ses deux voisines — la capacité DIT ce que le site a acheté, les permissions
      // disent ce que ce compte a le droit d'en faire.
      //
      // Cette entrée a d'abord été posée sans `cap`, parce que la capacité était inactive sur tous
      // les tenants et que l'écran des modules ne permettait pas de l'activer : la garder aurait
      // caché l'écran à celui-là même qui vient d'installer ses tourniquets. Ce n'est plus vrai
      // depuis que Paramètres › Modules en service permet la mise en service — l'entrée rejoint donc
      // ses voisines, et une topologie invisible se corrige là où elle doit l'être.
      { id: 'topologie_acces', ic: '⛬', label: 'Topologie & passages', cap: 'controle_acces', perms: ['acces.lire', 'acces.gerer', 'acces.superviser'] },
    ],
  },
  {
    section: 'Gestion',
    items: [
      { id: 'clients', ic: '☺', label: 'Clients', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
      // Juste sous Clients, parce qu'une demande d'effacement porte sur une fiche client et se
      // traite en la relisant. Pas dans Parametres : ce n'est pas un reglage, c'est une file
      // d'attente avec un delai legal d'un mois.
      { id: 'rgpd', ic: '⛊', label: 'Données personnelles', perms: ['crm.rgpd_gerer', 'crm.rgpd_demander'] },
      // Une entree propre plutot qu'un onglet dans Clients : un commercial cherche << ses
      // affaires >>, pas un onglet dans un annuaire. Et le pipeline se lit tous les jours,
      // alors qu'une fiche client s'ouvre a l'occasion.
      { id: 'affaires', ic: '◨', label: 'Affaires', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
      // Sous Gestion, juste apres les affaires : une campagne se decide comme une affaire, et
      // s'adresse aux memes gens. Pas sous Pilotage -- on ne l'observe pas, on la lance.
      //
      // Le droit `campagne.*` est distinct de `crm.*` a dessein : construire une audience n'est pas
      // modifier un client, et le droit de contacter mille personnes ne doit pas emporter celui d'en
      // corriger une.
      { id: 'campagnes', ic: '◈', label: 'Campagnes', perms: ['campagne.lire', 'campagne.gerer'] },
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
      // LES TROIS DERNIERES PORTES CONDAMNEES SONT OUVERTES (28/08), ET L'OBJECTION QUI LES
      // FERMAIT A ETE TRAITEE PLUTOT QU'IGNOREE.
      //
      // Elle disait ceci, et elle etait juste : SEPA, recouvrement et cautions vivaient dans les
      // onglets de `Comptabilite` ; leur ouvrir une entree propre creerait DEUX CHEMINS vers la
      // meme liste, deux endroits a corriger, et personne pour savoir lequel fait foi.
      //
      // Ce qui a change : les trois ecrans sont desormais des composants partages
      // (`PrelevementsSepa`, `ImpayesRecouvrement`, `CautionsGestion`) rendus AUX DEUX ENDROITS.
      // Il y a bien deux portes, mais une seule piece derriere -- une seule implementation, qui ne
      // peut pas diverger d'elle-meme. L'objection portait sur la duplication, pas sur les portes.
      //
      // Et elles ont chacune une raison d'exister a part :
      //  - SEPA porte quatre ecritures (mandat, remise, rejet, creancier) : c'est un poste de
      //    travail, pas une consultation qu'on ouvre au detour d'un journal comptable.
      //  - Le recouvrement porte une FILE DE TRAVAIL derriere laquelle des gens sont bloques a
      //    l'entree. Une file rangee au quatrieme onglet ne se regarde que quand on y pense.
      //  - Les cautions sont transversales : elles naissent a la piscine, au padel et a la
      //    patinoire, et le solde consigne est unique. On ne pose pas la question trois fois.
      { id: 'sepa', ic: '⇄', label: 'Prélèvements SEPA', perms: ['sepa.lire', 'compta.lire'] },
      { id: 'recouvrement', ic: '⚠', label: 'Recouvrement', perms: ['recouvrement.lire', 'recouvrement.piloter', 'compta.lire'] },
      { id: 'caution', ic: '⛨', label: 'Cautions', perms: ['caution.lire', 'caution.piloter'] },
      // Ouvert le 27/08 : quinze operations, aucun ecran. Un contrat depose par l'API existait,
      // et personne ne pouvait le relire.
      { id: 'documents', ic: '🗎', label: 'Documents', perms: ['dms.read', 'dms.write'] },
      // Reprise initiale (App\Import) : deposer un CSV de clients, le VALIDER, puis l'APPLIQUER en
      // un second geste. L'entree est gardee par `import.read` -- la lecture ; l'ecran ne montre les
      // gestes d'ecriture (valider, appliquer, annuler) qu'a qui porte import.create/apply/revert.
      { id: 'imports', ic: '⇪', label: 'Reprise initiale', perm: 'import.read' },
      // Ouvert le 27/08. L'ecran existe pour un etat precis : `partially_failed` -- un message
      // parti sur deux comptes, passe sur l'un, echoue sur l'autre. Sans le detail par compte,
      // on republie partout pour rattraper un seul echec.
      { id: 'social', ic: '◎', label: 'Publication sociale', perms: ['social.read_post', 'social.publish', 'social.read_account'] },
      // AUCUNE PERMISSION EXIGÉE, ET C'EST DÉLIBÉRÉ.
      //
      // « Moi » n'est pas une fonctionnalité qu'on achète : tout compte rattaché à un établissement
      // a un agenda, comme il a une boîte d'assistance. Le CONTENU, lui, reste borné — l'onglet
      // « Le site » ne montre que ce que le cloisonnement laisse passer, l'onglet « Ouverture »
      // n'expose ses gestes qu'à qui porte `organisation.gerer` ou `acces.gerer`, et l'agenda
      // personnel d'un tiers n'est lisible par personne, pas même par un administrateur.
      //
      // Ouvrir la porte n'ouvre aucun droit : c'est la même règle que pour « Assistance ».
      { id: 'agenda', ic: '▤', label: 'Agenda' },
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
      // « Autorisations » faisait chercher les droits ici, et on y tombait sur un journal vide :
      // qui-a-le-droit-de-quoi vit dans Paramètres › Utilisateurs & droits. Cet écran porte les
      // demandes d'escalade et les plafonds de montant — ce n'est pas la même question.
      // Arbitré par Maxime à la revue : on renomme, on ne déplace pas les droits.
      { id: 'autorisations', ic: '⚿', label: 'Escalades & plafonds', perms: ['autorisation.lire', 'autorisation.approuver', 'autorisation.gerer'] },
      // Les mentions obligatoires d'un site marchand. Sous Administration et non sous Boutique :
      // elles engagent l'exploitant, pas la vitrine, et un exploitant qui n'a pas encore ouvert
      // sa boutique doit pouvoir les preparer.
      { id: 'legal', ic: '§', label: 'Mentions legales', perms: ['organisation.gerer', 'boutique.gerer_vitrine'] },
    ],
  },
]

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

  // Filtre les entrées selon les capacités actives, les droits effectifs de l'établissement courant
  // et le statut administrateur.
  const navSource = navFournie ?? NAV
  const nav = navSource
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
    : navSource
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

  const nomEtab = etablissements.find((e) => e.id === etabActif)?.nom || 'Établissement'

  return (
    <div className={`app${navOpen ? ' nav-open' : ''}`}>
      {/* @clic-souris-seul: nav-backdrop  ferme le menu au clic a cote ; au clavier, le bouton ☰
          le referme deja. Le rendre focusable ajouterait un arret muet dans l ordre de tabulation,
          a franchir a chaque passage, pour un geste qui a deja son equivalent. */}
      <div className="nav-backdrop" onClick={() => setNavOpen(false)} />

      <aside className="sidebar">
        <div className="side-brand"><span className="logo">◈</span> Fluvia</div>
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
        {/* ⚠ LE LIEN D'EVITEMENT, PREMIER ELEMENT FOCUSABLE DE LA PAGE.
            Invisible tant qu'on ne l'atteint pas au clavier, il apparait au focus. Sans lui, la
            premiere tabulation d'un chargement entre dans une colonne de trente entrees qu'il faut
            traverser en entier pour lire le contenu. La boutique publique en a un depuis toujours
            (`PublicHeader.jsx`) ; le back-office n'en avait pas. */}
        <a className="lien-evitement" href="#contenu-principal">Aller au contenu</a>
        {/* Au-dessus de la barre, donc au-dessus de tout : un bandeau qu'on peut faire defiler hors
            de l'ecran n'est pas un bandeau permanent. */}
        {bandeau}
        <div className="topbar">
          <button className="burger" aria-label="Menu" onClick={() => setNavOpen((v) => !v)}>☰</button>
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
              aria-label="Revenir à l’écran précédent"
              title="Revenir à l’écran précédent"
            >
              ← Retour
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
                aria-label="Établissement actif"
                title="Établissement sur lequel vous travaillez"
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
const SOUS_TITRES = [
  ['controle_acces', "Contrôle d'accès"],
  ['reservation', 'Réservation'],
  ['boutique_en_ligne', 'Boutique en ligne'],
]

function sousTitreDe(capacites = []) {
  const parts = ['Billetterie']
  for (const [code, libelle] of SOUS_TITRES) {
    if (capacites.includes(code)) parts.push(libelle)
  }
  return parts.join(' · ')
}
