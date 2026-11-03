// LA CRÉATION GUIDÉE D'UN PRODUIT (refonte de la création, lot 1 — CP-1 du 06/10) : une modale à
// trois étapes et un récapitulatif. Ce fichier porte tout ce qui se décide sans écran — ce qu'une
// étape exige, les valeurs proposées d'office, le récapitulatif, les deux appels — pour que la
// modale ne fasse qu'afficher. Lancer : `cd frontend && node --test src/api/`.
//
// ⚠ LA CARTE ET LA FORMULE NE SONT PAS REDÉCIDÉES ICI. Leur validation et leur corps viennent de
// `publication.js` (lot des garde-fous, #310) : deux écrans de création ne doivent pas avoir deux
// idées de ce qu'est une carte valide.
import { CARTE_PAR_DEFAUT, FORMULE_PAR_DEFAUT, complementDuType, complementValide, corpsCreation } from './publication.js'

export const ETAPES = [
  { cle: 'quoi', titre: 'Ce que vous vendez' },
  { cle: 'prix', titre: 'Son prix' },
  { cle: 'ou', titre: 'Où le vendre' },
  { cle: 'recap', titre: 'Vérifier et créer' },
]

// Une phrase et un exemple par type, en mots de guichet. Un type inconnu ici (ajouté plus tard en
// base) s'affiche quand même, avec son seul libellé : mieux vaut une carte sobre qu'un type absent.
const DESCRIPTIONS = {
  entree_unitaire: { phrase: 'Un passage, utilisé le jour même.', exemple: 'Entrée adulte' },
  carte: { phrase: 'Plusieurs entrées payées d’un coup, décomptées à chaque passage.', exemple: 'Carte 10 entrées' },
  abonnement: { phrase: 'Un accès sur une durée, payé en une fois ou chaque mois.', exemple: 'Abonnement annuel' },
  prestation: { phrase: 'Un service réservé sur un créneau, sans billet d’entrée.', exemple: 'Cours particulier' },
  boutique_stock: { phrase: 'Un objet vendu au comptoir, suivi en stock.', exemple: 'Bonnet de bain' },
}

export function descriptionDuType(type) {
  return DESCRIPTIONS[type?.code] || null
}

// Le tarif proposé d'office : « Plein tarif » s'il existe, sinon le premier dans l'ordre d'affichage.
// Les autres (enfant, réduit…) s'ajoutent sur la fiche : ce sont des tarifs du même produit.
export function tarifParDefaut(typesTarif) {
  const actifs = (typesTarif || []).filter((t) => t.actif !== false)
  const plein = actifs.find((t) => /plein/i.test(t.nom || ''))
  if (plein) return plein
  return [...actifs].sort((a, b) => (a.ordreAffichage ?? 0) - (b.ordreAffichage ?? 0))[0] || null
}

// La catégorie de recette proposée d'office : la seule de l'axe comptable s'il n'y en a qu'une.
// Au-delà, on ne devine pas — une recette imputée au mauvais compte ne se voit qu'à la clôture.
export function categorieParDefaut(categories) {
  const comptables = (categories || []).filter((c) => c.axe === 'comptable' && c.actif !== false)
  return comptables.length === 1 ? comptables[0] : null
}

export function saisieInitiale({ etabActif = null, typesTarif = [], categories = [], venteEnLigne = false } = {}) {
  return {
    libelle: '',
    typeId: '',
    carte: { ...CARTE_PAR_DEFAUT },
    formule: { ...FORMULE_PAR_DEFAUT },
    prix: '',
    typeTarifId: tarifParDefaut(typesTarif)?.id || '',
    categorieId: categorieParDefaut(categories)?.id || '',
    // Le guichet est coché d'office, visible et décochable (lot 0) ; le site où l'on travaille aussi.
    // « En ligne » aussi quand l'établissement vend en ligne (capacité `boutique_en_ligne`) :
    // arbitrage de Maxime du 03/11, pour revenir à six clics.
    canaux: venteEnLigne ? ['guichet', 'en_ligne'] : ['guichet'],
    etablissements: etabActif ? [String(etabActif)] : [],
  }
}

// « 12,50 », « 12.5 », « 12 » : un prix au guichet s'écrit avec une virgule. Rien d'autre ne passe —
// ni négatif, ni trois décimales, ni lettres.
export function prixLu(texte) {
  const t = String(texte ?? '').trim().replace(',', '.')
  if (!/^\d+(\.\d{1,2})?$/.test(t)) return null
  return Number(t)
}

// Ce qui empêche de passer à l'étape suivante, en phrases. Une liste vide = l'étape est complète.
export function manquesEtape(cle, saisie, type) {
  const m = []
  if (cle === 'quoi') {
    if (saisie.libelle.trim() === '') m.push('Donnez-lui un nom.')
    if (saisie.typeId === '') m.push('Choisissez ce que c’est.')
    else if (!complementValide(type, saisie)) {
      m.push(complementDuType(type) === 'carte'
        ? 'Indiquez des nombres d’entrées entiers, d’au moins 1.'
        : 'Le jour du prélèvement va de 1 à 28.')
    }
  }
  if (cle === 'prix') {
    if (prixLu(saisie.prix) === null) m.push('Indiquez un prix, par exemple 12,50.')
    if (saisie.typeTarifId === '') m.push('Choisissez le tarif.')
  }
  if (cle === 'ou') {
    if (saisie.canaux.length === 0) m.push('Cochez au moins un endroit où le vendre.')
    if (saisie.etablissements.length === 0) m.push('Cochez au moins un site.')
  }
  return m
}

export function toutEstPret(saisie, type) {
  return ['quoi', 'prix', 'ou'].every((cle) => manquesEtape(cle, saisie, type).length === 0)
}

// Le récapitulatif : ce qui sera créé, ligne à ligne, dans les mots de l'écran.
export function recapitulatif(saisie, { type, typesTarif = [], categories = [], etablissements = [], canaux = [] }) {
  const lignes = [
    ['Nom', saisie.libelle.trim()],
    ['Type', type?.libelle || '—'],
  ]
  const complement = complementDuType(type)
  if (complement === 'carte') {
    lignes.push(['Entrées', `${saisie.carte.nbPaye} payées, ${saisie.carte.nbCredite} reçues`])
  }
  if (complement === 'formule') {
    const per = saisie.formule.periodicite === 'annuel' ? 'Annuelle' : 'Mensuelle'
    const sepa = saisie.formule.sepaActif
      ? `prélevée automatiquement${saisie.formule.jourPrelevement ? ` le ${saisie.formule.jourPrelevement}` : ''}`
      : 'payée sans prélèvement'
    lignes.push(['Formule', `${per}, ${sepa}`])
  }
  const tarif = typesTarif.find((t) => String(t.id) === String(saisie.typeTarifId))
  const p = prixLu(saisie.prix)
  lignes.push(['Prix', `${p === null ? '—' : p.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })} · ${tarif?.nom || '—'} · toute l’année`])
  const cat = categories.find((c) => String(c.id) === String(saisie.categorieId))
  lignes.push(['Catégorie de recette', cat?.nom || cat?.libelle || 'à choisir sur la fiche'])
  lignes.push(['Vendu', canaux.filter((c) => saisie.canaux.includes(c.valeur)).map((c) => c.libelle).join(', ') || '—'])
  lignes.push(['Sites', etablissements.filter((e) => saisie.etablissements.includes(String(e.id))).map((e) => e.nom).join(', ') || '—'])
  return lignes
}

// `POST /produits` : le corps de #310 (libellé, type, canaux, carte ou formule), plus les sites et la
// catégorie de recette, qui sont des champs du produit et partent donc dans le même appel.
export function corpsProduit(saisie, type) {
  const corps = corpsCreation({ ...saisie, type })
  corps.etablissements = saisie.etablissements.map((id) => `/api/etablissements/${id}`)
  if (saisie.categorieId) corps.categories = [`/api/categories/${saisie.categorieId}`]
  return corps
}

// `POST /grille_tarifaires` : SANS saison = toute l'année (lot 0, #270 : la saison est facultative
// côté serveur, et un tarif de saison l'emporte sur lui pendant sa saison).
export function corpsPrix(produitId, saisie) {
  return {
    produit: `/api/produits/${produitId}`,
    typeTarif: `/api/type_tarifs/${saisie.typeTarifId}`,
    prix: prixLu(saisie.prix).toFixed(2),
  }
}

// ── LE BOUTON DU RÉCAPITULATIF (arbitrage de Maxime, 03/11) ─────────────────────────────────────
//
// `POST /produits/readiness-preview` juge le corps de création avec la garde de publication
// elle-même (celle de `/readiness`) : l'écran ne recalcule aucune règle. `prixPrevu` dit au serveur
// que le prix suivra, puisqu'il naît après le produit.
export function corpsApercu(saisie, type) {
  return { ...corpsProduit(saisie, type), prixPrevu: prixLu(saisie.prix)?.toFixed(2) ?? null }
}

// Ce que le récapitulatif propose. `missing` : la réponse de l'aperçu, `null` tant qu'elle n'est pas
// lue (ou illisible). Sans réponse, on ne promet pas la mise en vente.
export function issueDeCreation(missing) {
  if (!Array.isArray(missing)) return { vendre: false, resteAFaire: null }
  if (missing.length === 0) return { vendre: true, resteAFaire: null }
  return {
    vendre: false,
    resteAFaire: `Il restera à faire sur la fiche avant de le mettre en vente : ${missing.map((m) => m.message).join(' ')}`,
  }
}
