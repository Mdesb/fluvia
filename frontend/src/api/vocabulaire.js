// Vocabulaire — traduire les codes techniques du serveur en mots que l'on dirait à voix haute.
//
// POURQUOI CE FICHIER. Les captures de production montrent des codes bruts partout : `boutique_stock`
// dans le catalogue, `salle_exposition` dans les espaces, `en_ligne`, `entree`, `blocage` dans la
// supervision, et « HEARTBEAT » en anglais au milieu d'une interface française. Ce ne sont pas des
// oublis isolés : c'est la même cause répétée trente fois — une valeur d'énumération affichée telle
// qu'elle est stockée.
//
// Un régisseur de piscine n'a aucune raison de savoir ce que `salle_exposition` veut dire. Il le
// devine, souvent, et c'est justement le problème : deviner coûte une seconde à chaque lecture, et
// entretient l'idée que le logiciel parle une langue qui n'est pas la sienne.
//
// UN SEUL ENDROIT, et c'est délibéré. Traduire à l'endroit de l'affichage aurait dispersé trente
// petites décisions dans quinze fichiers, avec des mots différents pour le même code selon l'écran.
// Ici, `entree` se dit « Entrée » partout, et le jour où l'on change d'avis on le change une fois.
//
// LE REPLI NE MENT JAMAIS. Un code inconnu n'est pas masqué ni remplacé par un mot inventé : il est
// simplement rendu lisible (soulignés retirés, première lettre en majuscule). Mieux vaut afficher
// « Boutique stock » que d'effacer une information qu'on n'a pas su traduire.

const MOTS = {
  // --- Pièces commerciales (FAC-1) : natures et statuts ---
  quote: 'Devis',
  sales_order: 'Bon de commande',
  delivery_note: 'Bon de livraison',
  draft: 'Brouillon',
  issued: 'Émis',
  accepted: 'Accepté',
  rejected: 'Refusé',
  expired: 'Périmé',
  converted: 'Transformé',
  cancelled: 'Annulé',

  // --- Espaces (types physiques) ---
  guichet: 'Guichet',
  salle_exposition: "Salle d'exposition",
  zone: 'Zone',
  glace: 'Piste de glace',
  bassin: 'Bassin',
  gradins: 'Gradins',

  // --- Canaux de vente ---
  en_ligne: 'En ligne',
  borne: 'Borne',

  // --- Passages de contrôle d'accès ---
  entree: 'Entrée',
  sortie: 'Sortie',
  bidirectionnel: 'Entrée et sortie',
  valide: 'Accepté',
  refuse: 'Refusé',
  compte: 'Compté sans contrôle',

  // --- État d'un contrôleur ---
  hors_ligne: 'Hors ligne',
  hors_service: 'Hors service',

  // --- Comportement au seuil d'une jauge ---
  blocage: 'Bloque au seuil',
  alerte: 'Alerte au seuil',
  normal: 'Normal',

  // --- Nature d'un droit d'accès ---
  billet: 'Billet',
  abonnement: 'Abonnement',
  carte_quota: 'Carte à quota',
  personnel: 'Personnel',
  booking: 'Réservation',

  // --- Équipements ---
  tourniquet: 'Tourniquet',
  tripode: 'Tripode',
  lecteur: 'Lecteur',

  // --- Statuts courants ---
  actif: 'Actif',
  inactif: 'Inactif',
  bloque: 'Bloqué',
  revoque: 'Révoqué',
  en_attente: 'En attente',
  suspendu: 'Suspendu',
  invite: 'Invité',

  // --- Patinoire ---
  // « non_rendu » et « non_rendue » diffèrent d'un caractère et ne disent pas la même chose : le
  // premier qualifie l'état des patins au retour, le second l'état de la location. Traduire les deux
  // évite qu'un agent lise « non rendu » sur une ligne dont la paire est revenue.
  en_cours: 'En cours',
  retournee: 'Rendue',
  non_rendue: 'Jamais rendue',
  bon: 'Bon état',
  casse: 'Cassés',
  non_rendu: 'Non rendus',
  prestation_client: 'Affûtage client',
  maintenance_parc: 'Entretien du parc',
  termine: 'Terminé',
  proposee: 'Pointure proposée',
  honoree: 'Servie',
  expiree: 'Expirée',
  perte: 'Perte',
  restitution_partielle: 'Restitution partielle',

  // --- Stock ---
  entree_achat: 'Entrée (achat)',
  sortie_vente: 'Sortie (vente)',
  retour_fournisseur: 'Retour fournisseur',
  ajustement_positif: 'Correction en plus',
  ajustement_negatif: 'Correction en moins',
  perte_casse: 'Perte ou casse',
  sortie_transfert: 'Départ en transfert',
  entree_transfert: 'Arrivée de transfert',
  regularisation_inventaire: 'Régularisation d’inventaire',
  piece: 'pièce',
  kg: 'kg',
  litre: 'litre',
  paquet: 'paquet',
  autre: 'autre',
  tous: 'Tous les articles',
  rayon: 'Un rayon',
  selection: 'Une sélection',
  cloture: 'Clôturé',
  en_validation: 'En validation',
  fifo: 'Premier entré, premier sorti (FIFO)',
  cmup: 'Coût moyen pondéré',

  // --- Achats & tresorerie ---
  // Ce module est nomme en anglais cote serveur ; les mots restent francais a l'ecran.
  draft: 'Brouillon',
  to_pay: 'À payer',
  partially_paid: 'Partiellement payée',
  disputed: 'En litige',
  cancelled: 'Annulée',

  // --- Padel ---
  indoor: 'Couvert',
  outdoor: 'Extérieur',
  complete: 'Complète',
  rendu: 'Rendu',

  // --- Piscine ---
  libre: 'Libre',
  occupe: 'Occupé',
  // `non_rendu` existe deja pour les patins et dit la meme chose : parti avec, pas revenu.

  // --- Musee ---
  planifiee: 'Planifiée',
  en_option: 'En option',
  bon_commande_emis: 'Bon de commande émis',
  mandat_emis: 'Mandat émis',
  paye: 'Payé',

  // --- Couverture d'un créneau du roster ---
  // Trois valeurs, et le mot compte : « conflit » sonne comme un chevauchement d'horaires alors
  // qu'il désigne une qualification manquante ou périmée. On le dit.
  sous_couvert: 'Sous-couvert',
  conflit: 'Qualification en défaut',

  // --- Recouvrement ---
  representation: 'Représentation bancaire',
  recouvrement: 'En recouvrement',
  resolu: 'Réglé',
  virement: 'Virement',
  caisse: 'Espèces au guichet',
  // Le résultat d'une représentation bancaire. Sans ces deux lignes, le repli affichait « Reussie »
  // et « Echouee » — sans accent, parce que le repli désoulignise un code, il ne parle pas français.
  reussie: 'Réussie',
  echouee: 'Échouée',
  app_1_clic: "Réglé par le client dans l'application",

  // --- SEPA ---
  // Le cycle d'une remise : composée en brouillon, figée en XML, puis remise à la banque.
  generee: 'Générée',
  transmise: 'Transmise à la banque',
  // `actif` / `revoque` (statut d'un mandat) sont déjà dans « Statuts courants » plus haut : les
  // redéclarer ici serait une clé en double, que la dernière écrase silencieusement.

  // LES QUATRE SÉQUENCES SEPA RESTENT DES SIGLES DANS LES FICHIERS, PAS SUR L'ÉCRAN.
  //
  // `FRST`, `RCUR`, `FNAL`, `OOFF` sont écrits tels quels dans le pain.008 et dans les retours de la
  // banque : on ne les renomme pas *dans le fichier*. Mais l'écran s'adresse à un régisseur, pas à
  // un analyste bancaire, et « FRST » ne veut rien dire pour lui. On affiche donc le mot, et le
  // sigle en dessous en `mono` là où il sert à parler à la banque.
  FRST: 'Première',
  RCUR: 'Suivante',
  FNAL: 'Dernière',
  OOFF: 'Ponctuelle',

  // --- Cautions ---
  // Statuts d'une caution. « Retenue partielle » et « retenue totale » ne sont pas deux nuances du
  // même mot : la première rend de l'argent au client, la seconde non — c'est la question qu'il pose.
  consignee: 'Consignée',
  restituee: 'Restituée',
  retenue_partielle: 'Retenue partielle',
  retenue_totale: 'Retenue totale',
  // Mouvements portés au journal d'une caution.
  consignation: 'Consignation',
  restitution: 'Restitution',
  retenue: 'Retenue',
  relance: 'Relance',
  forcage: 'Forçage',
  // Mode de calcul d'une ligne de barème.
  forfait: 'Forfait',
  valeur_remplacement: 'Valeur de remplacement',
  // LE SUPPORT SUR LEQUEL PORTE UNE CAUTION.
  //
  // Ces quatre codes ne viennent pas d'une énumération PHP mais de constantes `TYPE_CIBLE` posées
  // par chaque verticale (`SortirPatinsProcessor`, `AttribuerCasierHandler`,
  // `LouerMaterielProcessor`) : `App\Caution` ne dépend d'aucune verticale, la cible est un couple
  // opaque `typeCible`/`referenceCible`. D'où des valeurs pointées, que le repli rendait
  // « Patinoire.patins » — lisible, mais ce n'est pas ce qu'on dit à voix haute au guichet.
  //
  // Vérifié contre la préprod le 28/08 : `demo.cible` vient des fixtures et apparaît réellement
  // dans la liste, donc il est traduit lui aussi plutôt que laissé au repli.
  'patinoire.patins': 'Patins',
  'piscine.casier': 'Casier',
  'padel.materiel': 'Matériel de padel',
  'demo.cible': 'Support de démonstration',

  // --- Comptabilite ---
  provisoire: 'Provisoire',
  controlee: 'Contrôlée',
  exportee: 'Exportée',
  ouverte: 'Ouverte',
  cloturee: 'Clôturée',

  // --- Achats ---
  // « brouillon » et « validee » servent aussi ailleurs : ce sont des etats de document, pas des
  // etats propres au stock.
  brouillon: 'Brouillon',
  envoyee: 'Envoyée',
  confirmee: 'Confirmée',
  partiellement_recue: 'Partiellement reçue',
  cloturee: 'Clôturée',
  annulee: 'Annulée',
  validee: 'Validée',

  // --- Remboursements ---
  recue: 'Reçue',
  acceptee: 'Acceptée',
  refusee: 'Refusée',
}

// Rend un code lisible sans prétendre le traduire : `boutique_stock` → « Boutique stock ».
export function humaniser(code) {
  if (code === null || code === undefined || code === '') return '—'
  const t = String(code).replace(/_/g, ' ').trim()
  return t.charAt(0).toUpperCase() + t.slice(1)
}

// LES MOTS DU METIER, PAR ETABLISSEMENT -- poses PAR-DESSUS la table statique, jamais a la place.
//
// << Ressource >> veut dire *praticien* dans un salon de coiffure, *ligne d'eau* dans une piscine,
// *court* au padel. Traduire une fois pour tout le monde rendrait le logiciel faux partout sauf a un
// endroit.
//
// Un code absent des remplacements garde sa traduction par defaut : un etablissement qui ne renseigne
// rien continue de voir exactement ce qu'il voyait. C'est la seule facon d'ajouter cette souplesse
// sans risquer de vider un mot quelque part.
//
// Etat de module et non contexte React, deliberement : `mot()` est appelee depuis des fonctions pures,
// des colonnes de tableau, des titres -- la faire dependre d'un contexte obligerait a la transformer
// en hook et a toucher trente appels pour une fonctionnalite que la plupart des etablissements
// n'utiliseront jamais.
let vocabulaireLocal = {}

/** @param {Record<string,string>|null|undefined} mots */
export function setVocabulaireLocal(mots) {
  vocabulaireLocal = mots && typeof mots === 'object' ? mots : {}
}

export function mot(code) {
  if (code === null || code === undefined || code === '') return '—'
  const cle = String(code)
  return vocabulaireLocal[cle] || MOTS[cle] || humaniser(cle)
}

// Glossaire des sigles qu'on ne peut pas remplacer — ils figurent sur les équipements, dans les
// contrats et dans la réglementation, donc les renommer désorienterait ceux qui les connaissent.
// On les explique en infobulle plutôt que de les faire disparaître.
export const GLOSSAIRE = {
  fmi: "Fréquentation maximale instantanée : le nombre de personnes présentes en même temps qu'un espace peut accueillir.",
  jauge: "Le compteur de personnes présentes dans un espace, comparé à sa capacité maximale.",
  cumulJour: "Nombre total d'entrées depuis l'ouverture, sans retirer les sorties.",
  heartbeat: "Dernier signe de vie reçu de l'équipement. Un équipement silencieux depuis trop longtemps est peut-être débranché.",
  fondDeCaisse: "La somme en espèces présente dans la caisse au moment de l'ouverture.",
  indisponible: "Cette information n'est pas disponible pour cet établissement.",
}
