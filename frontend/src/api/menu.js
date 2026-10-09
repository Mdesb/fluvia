// LE MENU DU BACK-OFFICE, SANS REACT — extrait d'`AppShell.jsx` pour se tester hors du navigateur
// (`node --test src/api/menu.test.js`). La coquille ne fait plus que le dessiner.
//
// Les libellés ne sont plus ici : `nav.<id>` et `nav.section.<id>` dans `src/i18n/fr.json`. Le test
// de ce fichier vérifie que chaque entrée a sa clé.
import { aLeDroit, aUnDesDroits } from './droits.js'

// `cap` = capacité requise (capacitesActives de /me) ; `perm` = permission requise (droits de /me) ;
// `perms` = liste dont AU MOINS UNE suffit — pour les écrans qui servent plusieurs métiers, où
// exiger un droit unique retirerait l'écran à quelqu'un qui s'en sert légitimement ;
// `admin` = réservé aux profils administrateur (droits d'administration du socle). Sans contrainte,
// l'entrée est toujours visible.
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
// Ces entrées ne livraient aucune fonctionnalité. Elles rendaient le manque VISIBLE et donc
// arbitrable : on voyait ce qui restait à construire, et dans quel ordre le demander. Le
// commentaire d'origine finissait par « chacune disparaîtra de cette liste le jour où son écran
// existera — c'est le seul entretien qu'elles demandent ».
//
// ⚠ C'EST ARRIVÉ, ET C'EST POURQUOI IL N'EN RESTE RIEN DANS LE CODE (03/09). Les treize ont eu leur
// écran. Le drapeau `disabled` n'était plus posé nulle part — lu en cinq endroits, affecté en aucun
// — et le retirer était l'aboutissement de la phrase ci-dessus, pas son abandon. Mesuré avant de
// toucher : la nav n'est construite que dans ce fichier, et aucune entrée n'y porte de drapeau.
//
// ⚠ CE QUI SURVEILLE MAINTENANT N'EST PAS UNE ENTRÉE DE MENU, C'EST UN GARDE-FOU. L'écart
// client/serveur compte les opérations qu'aucun écran n'appelle et refuse de laisser ce nombre
// monter. Il voit ce qu'un menu ne peut pas voir : un module PRÉSENT dont seule une partie des
// gestes est atteignable. C'était précisément l'angle mort du 24/08 — Stock était affiché, donc on
// le croyait traité, pendant que onze de ses opérations n'avaient aucun bouton.
//
// Ne figurent pas ici les services transverses sans usage direct (OCR, Audit) : ils sont consommés
// par d'autres modules et n'ont pas vocation à un écran propre. Une entrée pour eux serait une
// promesse qu'on n'a pas l'intention de tenir.
//
// ── CHAQUE MODULE PORTE SA CAPACITÉ (décision de Maxime du 08/10) ───────────────────────────────
//
// Un établissement ne voit que les modules qu'il a en service (Paramètres › Modules en service) : une
// piscine ne montre que ce qu'elle vend. Un module qu'aucun préréglage de métier n'active
// (`App\Fonctionnalite\Config\PresetVerticale`) naît donc masqué. ⚠ LE SERVEUR NE GARDE AUCUNE DE
// CES ROUTES PAR CAPACITÉ (mesuré le 08/10 : `ModuleAccess` n'est injecté nulle part hors des tests) :
// c'est un masquage d'écran, pas un refus ; les droits restent la seule barrière de l'API.
//
// Sans `cap`, le socle : ce que tout établissement fait (caisse, catalogue, clients, facturation…),
// les services transverses (documents, campagnes, relances, cautions…) et l'administration. Deux y
// restent après relecture, parce qu'ils portent une donnée dont un autre écran a besoin :
//  - « Recouvrement » : la Facturation (socle) y renvoie ses impayés — relances et blocage d'accès
//    ne se pilotent que là. Le masquer laisserait des factures en retard sans geste possible.
//  - « Mentions légales » (module juridique) : le panier en ligne enregistre la version des CGV
//    publiées ici. Le masquer empêcherait de publier ce que la boutique fait accepter.
export const NAV = [
  {
    id: 'operations',
    items: [
      { id: 'dashboard', ic: 'dashboard', admin: true },
      // La caisse sert le caissier comme le responsable : encaisser, ouvrir une session, consulter.
      // Exiger le seul `caisse.lire` retirerait l'écran à un caissier qui n'a que les droits de vente.
      { id: 'caisse', ic: 'register', perms: ['caisse.lire', 'caisse.ouvrir', 'vente.creer', 'vente.encaisser'] },
      // ⚠ SON PROPRE DROIT, ET C'EST TOUT L'INTERET DE CETTE ENTREE. Composter un billet n'est pas
      // un geste de caisse : un guide qui controle l'entree d'une visite n'a aucune raison d'avoir
      // acces au tiroir-caisse. Le mettre dans « Caisse » obligeait a donner ce droit pour une
      // raison qui n'est pas la sienne — et personne ne pense a retirer un droit donne de biais.
      { id: 'composter', ic: 'validate', perm: 'acces.controler' },
      { id: 'catalogue', ic: 'catalog', perms: ['offre.lire', 'offre.gerer', 'offre.creer', 'offre.modifier'] },
      { id: 'reservation', ic: 'booking', cap: 'reservation' },
      // Écran métier de l'établissement (une seule entrée visible selon le type de site).
      { id: 'piscine', ic: 'pool', cap: 'piscine', perm: 'piscine.lire' },
      { id: 'patinoire', ic: 'rink', cap: 'patinoire', perm: 'patinoire.lire' },
      { id: 'padel', ic: 'padel', cap: 'padel', perm: 'padel.lire' },
      { id: 'musee', ic: 'museum', cap: 'musee', perm: 'musee.lire' },
      // Ouvert le 05/09 : sept routes servies, aucun ecran, et DEUX SEJOURS DEJA OUVERTS en base
      // depuis le 24/08 avec une ligne de bar — un module dont l'etat courant n'etait visible de
      // nulle part.
      //
      // Contrairement a `relance_recettes` et `places_liberees` livres le meme jour, les quatre
      // permissions `stay.*` SONT attribuees : « Administrateur groupe » et « Responsable de site »
      // les portent. Cette entree-la se verra tout de suite.
      { id: 'sejours', ic: 'stay', cap: 'stay', perms: ['stay.read', 'stay.write', 'stay.charge', 'stay.settle'] },
      { id: 'groupes', ic: 'groups', perm: 'group.read' },
      // Ouvert le 27/08, et pas pour les abonnements : `EvenementSOS` portait un statut
      // << ouverte >> et une operation << traiter >> SANS AUCUN ECRAN. Une alarme qu'aucune
      // interface ne montre cree la croyance qu'on serait prevenu.
      { id: 'sport', ic: 'fitness', cap: 'sport', perms: ['sport.lire', 'sport.gerer', 'sport.superviser_nocturne'] },
      { id: 'abonnements', ic: 'subscriptions', perms: ['sport.lire', 'sport.gerer_abonnement'] },
    ],
  },
  {
    id: 'access',
    items: [
      // ⚠ LE QUALIFICATIF N'EST PAS UN DOUBLON DE LA SECTION. Cet écran et Pilotage montrent
      // les MÊMES jauges FMI et la même fréquentation ; seul « accès » dit lequel des deux on ouvre.
      // L'écran s'appelait déjà « Supervision accès » ; le menu, lui, disait « Supervision ».
      { id: 'supervision', ic: 'supervision', cap: 'controle_acces' },
      // Regarder ne suffisait pas : dix-huit operations exposees, deux atteignables. Bloquer un
      // badge perdu et appairer une carte sont les deux gestes les plus frequents d'un exploitant,
      // et aucun des deux n'etait possible depuis l'application.
      // ⚠ « D'ACCES » N'EST PAS UN ORNEMENT. « Terminal » designe deux objets qui n'ont pas un
      // champ en commun : le controleur ITBOX enrole ici, et le TERMINAL DE PAIEMENT bancaire
      // (`PointDeVente::$tpe`), regle dans Parametres et utilise en caisse. Sans qualificatif,
      // cette entree attire l'exploitant qui cherche son TPE et lui montre des tourniquets.
      // Les deux ecrans qui parlent du TPE se qualifiaient deja ; celui-ci, non (R22).
      { id: 'acces', ic: 'badges', cap: 'controle_acces', perms: ['acces.lire', 'acces.appairer', 'acces.bloquer_support', 'acces.gerer'] },
    ],
  },
  {
    id: 'management',
    items: [
      { id: 'clients', ic: 'customers', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
      // Une entree propre plutot qu'un onglet dans Clients : un commercial cherche << ses
      // affaires >>, pas un onglet dans un annuaire. Et le pipeline se lit tous les jours,
      // alors qu'une fiche client s'ouvre a l'occasion.
      { id: 'affaires', ic: 'deals', cap: 'affaires', perms: ['crm.lire', 'crm.creer', 'crm.modifier'] },
      // Sous Gestion, juste apres les affaires : une campagne se decide comme une affaire, et
      // s'adresse aux memes gens. Pas sous Pilotage -- on ne l'observe pas, on la lance.
      //
      // Le droit `campagne.*` est distinct de `crm.*` a dessein : construire une audience n'est pas
      // modifier un client, et le droit de contacter mille personnes ne doit pas emporter celui d'en
      // corriger une.
      { id: 'campagnes', ic: 'campaigns', perms: ['campagne.lire', 'campagne.gerer'] },
      // « / Régie » vient du titre de l'écran, et il sert dans le menu : un régisseur cherche son
      // mot, pas « Comptabilité ». Le sous-titre de l'écran l'annonçait, le menu le cachait.
      { id: 'comptabilite', ic: 'accounting', cap: 'comptabilite', perm: 'compta.lire' },
      { id: 'boutique', ic: 'shop', cap: 'boutique_en_ligne' },
      { id: 'personnel', ic: 'staff', perm: 'personnel.lire' },
      // Sous Gestion et a cote du Personnel : un projet se distribue a des gens, et c'est la
      // qu'on va chercher qui fait quoi. Pas sous Pilotage -- un projet se conduit, il ne
      // s'observe pas.
      { id: 'projets', ic: 'projects', cap: 'projets', perms: ['personnel.lire', 'personnel.gerer', 'organisation.gerer'] },
      // ⚠ SOUS GESTION ET PAS SOUS PISCINE (R10). Un parc de casiers se gere comme un parc de
      // materiel : on attribue, on encaisse une caution, on rend une cle. Surveiller un bassin,
      // a cote, est une exploitation continue. Deux rythmes, deux entrees.
      { id: 'casiers', ic: 'stock', cap: 'casiers', perms: ['piscine.lire', 'piscine.gerer_casier', 'piscine.gerer'] },
      { id: 'stock', ic: 'stock', cap: 'stock', perm: 'stock.lire' },
      { id: 'facturation', ic: 'invoicing', perm: 'facturation.lire' },
      { id: 'finance', ic: 'purchases', cap: 'finance', perm: 'finance.read' },
      // LES TROIS DERNIERES PORTES CONDAMNEES SONT OUVERTES (28/08), ET L'OBJECTION QUI LES
      // FERMAIT A ETE TRAITEE PLUTOT QU'IGNOREE.
      //
      // Elle disait ceci, et elle etait juste : SEPA, recouvrement et cautions vivaient dans les
      // onglets de `Comptabilite` ; leur ouvrir une entree propre creerait DEUX CHEMINS vers la
      // meme liste, deux endroits a corriger, et personne pour savoir lequel fait foi.
      //
      // Ce qui a change : les trois ecrans sont devenus des composants a part
      // (`PrelevementsSepa`, `ImpayesRecouvrement`, `CautionsGestion`), et les onglets de
      // `Comptabilite` qui les rendaient ont ete RETIRES depuis.
      //
      // ⚠ CE BLOC A DIT << rendus AUX DEUX ENDROITS >> et << deux portes, une seule piece >>
      // jusqu'au 14/09. Mesure ce jour-la : chacun des trois n'a plus qu'UN SEUL hote
      // (`Sepa.jsx`, `Recouvrement.jsx`, `Cautions.jsx`), et `Comptabilite.jsx` porte la
      // mention << SEPA EST PARTI AUSSI >>. La justification s'appuyait donc sur un fait
      // disparu -- elle survivait au deplacement qu'elle decrivait.
      //
      // Ce qui reste vrai se dit plus simplement : une porte, une piece. L'objection portait
      // sur la duplication, et il n'y en a plus du tout.
      //
      // Et elles ont chacune une raison d'exister a part :
      //  - SEPA porte quatre ecritures (mandat, remise, rejet, creancier) : c'est un poste de
      //    travail, pas une consultation qu'on ouvre au detour d'un journal comptable.
      //  - Le recouvrement porte une FILE DE TRAVAIL derriere laquelle des gens sont bloques a
      //    l'entree. Une file rangee au quatrieme onglet ne se regarde que quand on y pense.
      //  - Les cautions sont transversales : elles naissent a la piscine, au padel et a la
      //    patinoire, et le solde consigne est unique. On ne pose pas la question trois fois.
      { id: 'sepa', ic: 'sepa', cap: 'sepa', perms: ['sepa.lire', 'compta.lire'] },
      { id: 'recouvrement', ic: 'collections', perms: ['recouvrement.lire', 'recouvrement.piloter', 'compta.lire'] },
      { id: 'caution', ic: 'deposits', perms: ['caution.lire', 'caution.piloter'] },
      // Ouvert le 05/09 : huit routes servies, aucun ecran, et un module qui n'avait JAMAIS tourne.
      // `RecoverySequence` naît inactive par choix (RG-RR-02) et le moteur sort sans rien faire
      // sans sequence active : il manquait donc le seul geste qui rend le module vivant.
      // Distinct du recouvrement juste au-dessus : celui-la traite les impayes et les acces
      // bloques, celui-ci relance par courriel apres un no-show, une annulation ou un paiement
      // refuse.
      { id: 'relance_recettes', ic: 'settlements', perms: ['revenue_recovery.read', 'revenue_recovery.configure', 'revenue_recovery.manage'] },
      // Ouvert le 05/09 : sept routes servies, aucun ecran, et un mecanisme qui tournait dans le
      // vide. Six liberations de creneau tracees, zero inscription en liste d'attente — la chaine
      // cherchait a qui offrir une place et ne trouvait personne, faute de la porte qui remplit la
      // liste.
      //
      // ⚠ SA PROPRE PORTE, ET PAS UN ONGLET DE << Reservation >>. C'est une file de travail avec
      // une HORLOGE : une place proposee expire, et le suivant attend. Le meme argument qui a
      // ouvert une porte au recouvrement vaut ici — une file rangee au cinquieme onglet d'un ecran
      // de planning ne se regarde que quand on y pense.
      { id: 'places_liberees', ic: 'subscriptions', cap: 'reservation', perms: ['smart_flow.read', 'smart_flow.reschedule_manage', 'smart_flow.reschedule_read_own'] },
      // Ouvert le 27/08 : quinze operations, aucun ecran. Un contrat depose par l'API existait,
      // et personne ne pouvait le relire.
      { id: 'documents', ic: 'documents', perms: ['dms.read', 'dms.write'] },
      // Reprise initiale (App\Import) : deposer un CSV de clients, le VALIDER, puis l'APPLIQUER en
      // un second geste. L'entree est gardee par `import.read` -- la lecture ; l'ecran ne montre les
      // gestes d'ecriture (valider, appliquer, annuler) qu'a qui porte import.create/apply/revert.
      { id: 'imports', ic: 'import', perm: 'import.read' },
      // Ouvert le 27/08. L'ecran existe pour un etat precis : `partially_failed` -- un message
      // parti sur deux comptes, passe sur l'un, echoue sur l'autre. Sans le detail par compte,
      // on republie partout pour rattraper un seul echec.
      { id: 'social', ic: 'social', cap: 'social', perms: ['social.read_post', 'social.publish', 'social.read_account'] },
      // AUCUNE PERMISSION EXIGÉE, ET C'EST DÉLIBÉRÉ.
      //
      // « Moi » n'est pas une fonctionnalité qu'on achète : tout compte rattaché à un établissement
      // a un agenda, comme il a une boîte d'assistance. Le CONTENU, lui, reste borné — l'onglet
      // « Le site » ne montre que ce que le cloisonnement laisse passer, l'onglet « Ouverture »
      // n'expose ses gestes qu'à qui porte `organisation.gerer` ou `acces.gerer`, et l'agenda
      // personnel d'un tiers n'est lisible par personne, pas même par un administrateur.
      //
      // Ouvrir la porte n'ouvre aucun droit : c'est la même règle que pour « Assistance ».
      { id: 'agenda', ic: 'agenda' },
    ],
  },
  {
    id: 'steering',
    items: [
      { id: 'pilotage', ic: 'reporting', perms: ['reporting.lire', 'reporting.configurer', 'reporting.planifier'] },
      // `absent: true` a tenu jusqu'au 27/08 sur un module qui expose ONZE operations de tickets
      // et sept d'articles d'aide. La porte etait dessinee et condamnee ; c'est le motif le plus
      // couteux du depot -- 815 operations sans porte pour 234 atteignables.
      //
      // Les droits fins restent ceux du serveur : l'entree exige `support.lire`, et l'ecran ne
      // montre les gestes d'agent qu'a qui les possede. Ouvrir la porte n'ouvre aucun droit.
      { id: 'support', ic: 'support', perms: ['support.lire', 'support.ouvrir_ticket', 'support.lire_ticket_soi', 'support.traiter_ticket_n1', 'support.traiter_ticket_n2', 'support.administrer'] },
    ],
  },
  {
    id: 'administration',
    items: [
      { id: 'parametres', ic: 'settings', perms: ['securite.gerer', 'securite.lire', 'organisation.gerer', 'offre.gerer', 'caisse.gerer', 'crm.parametrer'] },
      // La boutique de modules — déplacée d'Exploitation vers Administration le 20/09 : acquérir des
      // modules est un geste d'administration, pas d'exploitation quotidienne. `admin: true`
      // (arbitrage Maxime, 01/09) : achat ouvert à qui administre l'établissement — pas une
      // permission de module, puisqu'il s'agit justement d'acquérir des modules qu'on n'a pas encore.
      { id: 'modules', ic: 'modules', admin: true },
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
      { id: 'rgpd', ic: 'personal-data', perms: ['crm.rgpd_gerer', 'crm.rgpd_demander'] },
      { id: 'autorisations', ic: 'escalations', perms: ['autorisation.lire', 'autorisation.approuver', 'autorisation.gerer'] },
      // Les mentions obligatoires d'un site marchand. Sous Administration et non sous Boutique :
      // elles engagent l'exploitant, pas la vitrine, et un exploitant qui n'a pas encore ouvert
      // sa boutique doit pouvoir les preparer.
      { id: 'legal', ic: 'legal', perms: ['organisation.gerer', 'boutique.gerer_vitrine'] },
      // La documentation vivante de l'API REST (OpenAPI). `admin: true` et pas une permission metier :
      // ce n'est pas un ecran d'exploitation mais une porte d'integration (bornes ITBOX, developpements
      // tiers). Elle ne montre rien de plus que le contrat deja servi a `/api/docs` ; l'onglet le rend
      // seulement visible depuis le menu, au lieu d'une URL a connaitre par coeur.
      //
      // ⚠ `ic: 'api'` ET NON LE GLYPHE '⧉' DE LA BRANCHE. Les icones du menu sont devenues des NOMS
      // resolus par `Icon.jsx` ; la branche de `claude-B` precede cette refonte. Le nom est ajoute
      // a la table dans le meme lot — sans quoi l'entree afficherait un `<svg>` vide, sans erreur.
      { id: 'api', ic: 'api', admin: true },
    ],
  },
]

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
export function ongletsConnus(nav = NAV) {
  return new Set(nav.flatMap((section) => section.items.map((item) => item.id)))
}

const entrees = () => NAV.flatMap((section) => section.items)

// UN LIEN DIRECT VERS UN MODULE HORS SERVICE N'EST NI UNE ERREUR NI UNE REDIRECTION : `App.jsx`
// affiche « module non activé pour cet établissement » à la place de l'écran, qu'il ne monte pas.
// Le socle (sans `cap`) et une adresse inconnue (son propre panneau) ne sont jamais « inactifs ».
export function moduleInactif(id, capacites = []) {
  const cap = entrees().find((it) => it.id === id)?.cap
  return Boolean(cap) && !capacites.includes(cap)
}

// Les entrées qu'une capacité fait apparaître : l'écran des capacités le dit au moment d'activer.
export function entreesDe(code) {
  return entrees().filter((it) => it.cap === code).map((it) => it.id)
}

// Filtre les entrées selon les capacités actives, les droits effectifs de l'établissement courant
// et le statut administrateur.
export function filtrerMenu(nav, { capacites = [], droits = [], estAdmin = false } = {}) {
  const garder = (visible) =>
    nav.map((grp) => ({ ...grp, items: grp.items.filter(visible) })).filter((grp) => grp.items.length > 0)

  const menu = garder(
    (it) =>
      (!it.cap || capacites.includes(it.cap)) &&
      (!it.perm || aLeDroit(droits, it.perm)) &&
      (!it.perms || aUnDesDroits(droits, it.perms)) &&
      (!it.admin || estAdmin),
  )

  // PLANCHER DE SÛRETÉ. Si le filtrage ne laisse RIEN, on retombe sur les entrées sans contrainte de
  // capacité ni de statut administrateur.
  //
  // Ce n'est pas de la timidité : un menu vide enferme quelqu'un hors de son propre logiciel, sans
  // aucun moyen d'en sortir ni de comprendre pourquoi. Un menu trop permissif, lui, se corrige tout
  // seul — l'API refuse, et le refus est lisible. Entre les deux erreurs possibles, celle-ci est la
  // moins coûteuse, et c'est exactement celle que j'ai commise en production ce soir.
  return menu.length > 0 ? menu : garder((it) => !it.cap && !it.admin)
}
