import { useEffect, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import RechercheGlobale from './RechercheGlobale.jsx'
import { aLeDroit, aUnDesDroits } from '../api/droits.js'
import { profondeurHistorique } from '../api/url.js'
import Cloche from './Cloche.jsx'
import InstallerSurLeTelephone from './InstallerSurLeTelephone.jsx'
import Icon from './Icon.jsx'

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
      { id: 'dashboard', ic: 'dashboard', label: 'Tableau de bord', admin: true },
      // La boutique de modules. `admin: true` parce que Maxime a arbitre le 01/09 : « achat ouvert
      // a qui administre l'etablissement » — pas une permission de module, puisqu'il s'agit
      // justement d'acquerir des modules qu'on n'a pas encore.
      { id: 'modules', ic: 'modules', label: 'Modules', admin: true },
      // La caisse sert le caissier comme le responsable : encaisser, ouvrir une session, consulter.
      // Exiger le seul `caisse.lire` retirerait l'écran à un caissier qui n'a que les droits de vente.
      { id: 'caisse', ic: 'register', label: 'Caisse', perms: ['caisse.lire', 'caisse.ouvrir', 'vente.creer', 'vente.encaisser'] },
      // ⚠ SON PROPRE DROIT, ET C'EST TOUT L'INTERET DE CETTE ENTREE. Composter un billet n'est pas
      // un geste de caisse : un guide qui controle l'entree d'une visite n'a aucune raison d'avoir
      // acces au tiroir-caisse. Le mettre dans « Caisse » obligeait a donner ce droit pour une
      // raison qui n'est pas la sienne — et personne ne pense a retirer un droit donne de biais.
      { id: 'composter', ic: 'validate', label: 'Composter', perm: 'acces.controler' },
      { id: 'catalogue', ic: 'catalog', label: 'Catalogue', perms: ['offre.lire', 'offre.gerer', 'offre.creer', 'offre.modifier'] },
      { id: 'reservation', ic: 'booking', label: 'Réservation', cap: 'reservation' },
      // Écran métier de l'établissement (une seule entrée visible selon le type de site).
      { id: 'piscine', ic: 'pool', label: 'Piscine', perm: 'piscine.lire' },
      { id: 'patinoire', ic: 'rink', label: 'Patinoire', perm: 'patinoire.lire' },
      { id: 'padel', ic: 'padel', label: 'Padel', perm: 'padel.lire' },
      { id: 'musee', ic: 'museum', label: 'Musée', perm: 'musee.lire' },
      // Ouvert le 27/08, et pas pour les abonnements : `EvenementSOS` portait un statut
      // << ouverte >> et une operation << traiter >> SANS AUCUN ECRAN. Une alarme qu'aucune
      // interface ne montre cree la croyance qu'on serait prevenu.
      { id: 'sport', ic: 'fitness', label: 'Sport & fitness', perms: ['sport.lire', 'sport.gerer', 'sport.superviser_nocturne'] },
    ],
  },
  {
    section: 'Contrôle d’accès',
    items: [
      { id: 'supervision', ic: 'supervision', label: 'Supervision', cap: 'controle_acces' },
      // Regarder ne suffisait pas : dix-huit operations exposees, deux atteignables. Bloquer un
      // badge perdu et appairer une carte sont les deux gestes les plus frequents d'un exploitant,
      // et aucun des deux n'etait possible depuis l'application.
      // ⚠ « D'ACCES » N'EST PAS UN ORNEMENT. « Terminal » designe deux objets qui n'ont pas un
      // champ en commun : le controleur ITBOX enrole ici, et le TERMINAL DE PAIEMENT bancaire
      // (`PointDeVente::$tpe`), regle dans Parametres et utilise en caisse. Sans qualificatif,
      // cette entree attire l'exploitant qui cherche son TPE et lui montre des tourniquets.
      // Les deux ecrans qui parlent du TPE se qualifiaient deja ; celui-ci, non (R22).
      { id: 'acces', ic: 'badges', label: 'Badges & terminaux d’accès', cap: 'controle_acces', perms: ['acces.lire', 'acces.appairer', 'acces.bloquer_support', 'acces.gerer'] },
    ],
  },
  {
    section: 'Gestion',
    items: [
      { id: 'clients', ic: 'customers', label: 'Clients', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
      // Une entree propre plutot qu'un onglet dans Clients : un commercial cherche << ses
      // affaires >>, pas un onglet dans un annuaire. Et le pipeline se lit tous les jours,
      // alors qu'une fiche client s'ouvre a l'occasion.
      { id: 'affaires', ic: 'deals', label: 'Affaires', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
      // Sous Gestion, juste apres les affaires : une campagne se decide comme une affaire, et
      // s'adresse aux memes gens. Pas sous Pilotage -- on ne l'observe pas, on la lance.
      //
      // Le droit `campagne.*` est distinct de `crm.*` a dessein : construire une audience n'est pas
      // modifier un client, et le droit de contacter mille personnes ne doit pas emporter celui d'en
      // corriger une.
      { id: 'campagnes', ic: 'campaigns', label: 'Campagnes', perms: ['campagne.lire', 'campagne.gerer'] },
      { id: 'comptabilite', ic: 'accounting', label: 'Comptabilité', perm: 'compta.lire' },
      { id: 'boutique', ic: 'shop', label: 'Boutique en ligne', cap: 'boutique_en_ligne' },
      { id: 'personnel', ic: 'staff', label: 'Personnel', perm: 'personnel.lire' },
      // Sous Gestion et a cote du Personnel : un projet se distribue a des gens, et c'est la
      // qu'on va chercher qui fait quoi. Pas sous Pilotage -- un projet se conduit, il ne
      // s'observe pas.
      { id: 'projets', ic: 'projects', label: 'Projets', perms: ['personnel.lire', 'personnel.gerer', 'organisation.gerer'] },
      // ⚠ SOUS GESTION ET PAS SOUS PISCINE (R10). Un parc de casiers se gere comme un parc de
      // materiel : on attribue, on encaisse une caution, on rend une cle. Surveiller un bassin,
      // a cote, est une exploitation continue. Deux rythmes, deux entrees.
      { id: 'casiers', ic: 'stock', label: 'Casiers', cap: 'casiers', perms: ['piscine.lire', 'piscine.gerer_casier', 'piscine.gerer'] },
      { id: 'stock', ic: 'stock', label: 'Stock', perm: 'stock.lire' },
      { id: 'facturation', ic: 'invoicing', label: 'Facturation', perm: 'facturation.lire' },
      { id: 'finance', ic: 'purchases', label: 'Achats & trésorerie', perm: 'finance.read' },
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
      { id: 'sepa', ic: 'sepa', label: 'Prélèvements SEPA', perms: ['sepa.lire', 'compta.lire'] },
      { id: 'recouvrement', ic: 'collections', label: 'Recouvrement', perms: ['recouvrement.lire', 'recouvrement.piloter', 'compta.lire'] },
      { id: 'caution', ic: 'deposits', label: 'Cautions', perms: ['caution.lire', 'caution.piloter'] },
      // Ouvert le 27/08 : quinze operations, aucun ecran. Un contrat depose par l'API existait,
      // et personne ne pouvait le relire.
      { id: 'documents', ic: 'documents', label: 'Documents', perms: ['dms.read', 'dms.write'] },
      // Reprise initiale (App\Import) : deposer un CSV de clients, le VALIDER, puis l'APPLIQUER en
      // un second geste. L'entree est gardee par `import.read` -- la lecture ; l'ecran ne montre les
      // gestes d'ecriture (valider, appliquer, annuler) qu'a qui porte import.create/apply/revert.
      { id: 'imports', ic: 'import', label: 'Reprise initiale', perm: 'import.read' },
      // Ouvert le 27/08. L'ecran existe pour un etat precis : `partially_failed` -- un message
      // parti sur deux comptes, passe sur l'un, echoue sur l'autre. Sans le detail par compte,
      // on republie partout pour rattraper un seul echec.
      { id: 'social', ic: 'social', label: 'Publication sociale', perms: ['social.read_post', 'social.publish', 'social.read_account'] },
      // AUCUNE PERMISSION EXIGÉE, ET C'EST DÉLIBÉRÉ.
      //
      // « Moi » n'est pas une fonctionnalité qu'on achète : tout compte rattaché à un établissement
      // a un agenda, comme il a une boîte d'assistance. Le CONTENU, lui, reste borné — l'onglet
      // « Le site » ne montre que ce que le cloisonnement laisse passer, l'onglet « Ouverture »
      // n'expose ses gestes qu'à qui porte `organisation.gerer` ou `acces.gerer`, et l'agenda
      // personnel d'un tiers n'est lisible par personne, pas même par un administrateur.
      //
      // Ouvrir la porte n'ouvre aucun droit : c'est la même règle que pour « Assistance ».
      { id: 'agenda', ic: 'agenda', label: 'Agenda' },
    ],
  },
  {
    section: 'Pilotage',
    items: [
      { id: 'pilotage', ic: 'reporting', label: 'Reporting', perms: ['reporting.lire', 'reporting.configurer', 'reporting.planifier'] },
      // `absent: true` a tenu jusqu'au 27/08 sur un module qui expose ONZE operations de tickets
      // et sept d'articles d'aide. La porte etait dessinee et condamnee ; c'est le motif le plus
      // couteux du depot -- 815 operations sans porte pour 234 atteignables.
      //
      // Les droits fins restent ceux du serveur : l'entree exige `support.lire`, et l'ecran ne
      // montre les gestes d'agent qu'a qui les possede. Ouvrir la porte n'ouvre aucun droit.
      { id: 'support', ic: 'support', label: 'Assistance', perms: ['support.lire', 'support.ouvrir_ticket', 'support.lire_ticket_soi', 'support.traiter_ticket_n1', 'support.traiter_ticket_n2', 'support.administrer'] },
    ],
  },
  {
    section: 'Administration',
    items: [
      {
        id: 'parametres',
        ic: 'settings', label: 'Paramètres',
        perms: ['securite.gerer', 'securite.lire', 'organisation.gerer', 'offre.gerer', 'caisse.gerer', 'crm.parametrer'],
      },
      // Ouvert le 27/08. Voir le commentaire de l'entree << Assistance >> : meme motif, meme cout.
      // « Autorisations » faisait chercher les droits ici, et on y tombait sur un journal vide :
      // qui-a-le-droit-de-quoi vit dans Paramètres › Utilisateurs & droits. Cet écran porte les
      // demandes d'escalade et les plafonds de montant — ce n'est pas la même question.
      // Arbitré par Maxime à la revue : on renomme, on ne déplace pas les droits.
      // ⚠ SOUS ADMINISTRATION DEPUIS LE 03/09 (R27), ET LE RAISONNEMENT QUI LA TENAIT SOUS
      // CLIENTS ETAIT JUSTE. Il disait : « une demande d'effacement porte sur une fiche client et
      // se traite en la relisant ; pas dans Parametres, ce n'est pas un reglage, c'est une file
      // d'attente avec un delai legal d'un mois ». C'est exact — et Maxime pose l'autre critere,
      // celui de R21 : « ce n'est pas gere au quotidien ». Elle quitte donc la liste quotidienne
      // SANS entrer dans Parametres : elle reste une file, a un clic, pas un reglage.
      //
      // ⚠ ET LE DELAI LEGAL N'EST RAPPELE PAR RIEN — mais pas pour la raison que j'avais ecrite.
      //
      // J'avais note « aucun conteneur ne porte de cron ». C'est FAUX : `infra/ordonnanceur.sh`
      // tourne en continu et execute sept taches en liste blanche toutes les minutes. Ce qui est
      // vrai, et qui suffit, c'est qu'AUCUNE DES SEPT ne regarde les demandes RGPD : la liste
      // contient les expirations de droits, les paniers, les fenetres de badges, le terme des
      // abonnements, le preavis SEPA et la facturation mensuelle. Rien sur le delai d'un mois.
      //
      // Une file qu'on deplace hors de vue et qu'aucune alarme ne surveille peut donc depasser son
      // mois sans que personne le voie. Le compteur qui manque est signale a Maxime plutot que
      // bricole ici. ⚠ `./infra/ordonnanceur.sh --lister` dit l'etat de cette liste, qui bouge.
      { id: 'rgpd', ic: 'personal-data', label: 'Données personnelles', perms: ['crm.rgpd_gerer', 'crm.rgpd_demander'] },
      { id: 'autorisations', ic: 'escalations', label: 'Escalades & plafonds', perms: ['autorisation.lire', 'autorisation.approuver', 'autorisation.gerer'] },
      // Les mentions obligatoires d'un site marchand. Sous Administration et non sous Boutique :
      // elles engagent l'exploitant, pas la vitrine, et un exploitant qui n'a pas encore ouvert
      // sa boutique doit pouvoir les preparer.
      { id: 'legal', ic: 'legal', label: 'Mentions legales', perms: ['organisation.gerer', 'boutique.gerer_vitrine'] },
      // La documentation vivante de l'API REST (OpenAPI). `admin: true` et pas une permission metier :
      // ce n'est pas un ecran d'exploitation mais une porte d'integration (bornes ITBOX, developpements
      // tiers). Elle ne montre rien de plus que le contrat deja servi a `/api/docs` ; l'onglet le rend
      // seulement visible depuis le menu, au lieu d'une URL a connaitre par coeur.
      //
      // ⚠ `ic: 'api'` ET NON LE GLYPHE '⧉' DE LA BRANCHE. Les icones du menu sont devenues des NOMS
      // resolus par `Icon.jsx` ; la branche de `claude-B` precede cette refonte. Le nom est ajoute
      // a la table dans le meme lot — sans quoi l'entree afficherait un `<svg>` vide, sans erreur.
      { id: 'api', ic: 'api', label: 'API', admin: true },
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

  const navFinale = nav.length > 0
    ? nav
    : navSource
        .map((grp) => ({
          ...grp,
          items: grp.items.filter((it) => !it.cap && !it.admin),
        }))
        .filter((grp) => grp.items.length > 0)

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

  const nomEtab = etablissements.find((e) => e.id === etabActif)?.nom || 'Établissement'

  return (
    <div className={`app${navOpen ? ' nav-open' : ''}`}>
      {/* @clic-souris-seul: nav-backdrop  ferme le menu au clic a cote ; au clavier, le bouton ☰
          le referme deja. Le rendre focusable ajouterait un arret muet dans l ordre de tabulation,
          a franchir a chaque passage, pour un geste qui a deja son equivalent. */}
      <div className="nav-backdrop" onClick={() => setNavOpen(false)} />

      <aside className="sidebar">
        <div className="side-brand"><img className="logo" src="/fluvia-mark-192.png" alt="" width="28" height="28" /> Fluvia</div>
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
            <div key={grp.section}>
              <div className="side-sec">{grp.section}</div>
              {grp.items.map((it) => (
                <button
                  key={it.id}
                  className={`side-link${onglet === it.id ? ' active' : ''}`}
                  onClick={() => !it.disabled && aller(it.id)}
                  disabled={it.disabled}
                  title={it.disabled ? 'Bientôt disponible' : undefined}
                  style={it.disabled ? { opacity: 0.5, cursor: 'not-allowed' } : undefined}
                >
                  <Icon name={it.ic} className="ic" /> {it.label}
                  {/* ⚠ LE BADGE NE S'AFFICHE QUE S'IL Y A QUELQUE CHOSE À MONTRER. `> 0`, jamais
                      `!= null` : un « 0 » permanent sur une entrée de menu s'apprend à ne plus
                      voir, et le jour où il porte un vrai chiffre personne ne le lit. */}
                  {it.id === 'rgpd' && compteurRgpd?.enAttente > 0 && (
                    <span
                      className={`badge ${compteurRgpd.enRetard > 0 ? 'crit' : 'warn'} side-compteur`}
                      title={
                        compteurRgpd.enRetard > 0
                          ? `${compteurRgpd.enAttente} demande(s) en attente, dont ${compteurRgpd.enRetard} au-delà du délai légal d'un mois.`
                          : `${compteurRgpd.enAttente} demande(s) en attente. Le délai légal est d'un mois.`
                      }
                    >
                      {compteurRgpd.enAttente}
                    </span>
                  )}
                  {it.disabled && (
                    <span className="badge mut" style={{ marginLeft: 'auto', fontSize: 10 }}>
                      bientôt
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
