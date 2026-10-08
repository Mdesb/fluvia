// LA FICHE GUIDÉE (lot des garde-fous, 08/10) : ce qui manque pour publier, et où le régler.
//
// La liste elle-même vient du serveur (`GET /produits/{id}/readiness`, la garde de publication) :
// l'écran ne recalcule aucune règle, il ne fait que ranger chaque manque sous son onglet.

// Où se règle chaque code de `PublicationGuard` et des modules qui l'alimentent.
const ONGLET_DU_PREREQUIS = {
  libelle: 'vitrine',
  site: 'vente',
  canal: 'vente',
  prix: 'vente',
  categorie_comptable: 'vente',
  carte: 'vente',
  formule: 'vente',
  zone_acces: 'acces',
  creneau: 'agenda',
}

// Chaque manque avec son onglet. Un onglet absent ici (capacité non active sur l'établissement
// courant) ne donne pas de lien : on ne propose pas d'aller là où rien ne s'affiche.
export function lignesManquantes(manquants, onglets) {
  return (manquants || []).map((m) => {
    const onglet = onglets.find(([cle]) => cle === ONGLET_DU_PREREQUIS[m.code])
    return { ...m, onglet: onglet ? onglet[0] : null, libelleOnglet: onglet ? onglet[1] : null }
  })
}

// La fiche s'ouvre sur le premier onglet incomplet, dans l'ordre où ils s'affichent ; sinon sur Vente,
// là où l'on vend (et non plus sur « Présentation »).
export function ongletInitial(manquants, onglets) {
  const cibles = new Set(lignesManquantes(manquants, onglets).map((l) => l.onglet))
  const premier = onglets.find(([cle]) => cibles.has(cle))
  return premier ? premier[0] : 'vente'
}

// Ce que le type exige de créer avec le produit : sa carte (facette « carnet ») ou sa formule.
export function complementDuType(type) {
  const facettes = Array.isArray(type?.facettes) ? type.facettes : []
  if (facettes.includes('carnet')) return 'carte'
  if (facettes.includes('formule')) return 'formule'
  return null
}

export const CARTE_PAR_DEFAUT = { nbPaye: '10', nbCredite: '10' }
export const FORMULE_PAR_DEFAUT = { periodicite: 'mensuel', sepaActif: false, jourPrelevement: '' }

const entier = (v) => (v === '' || v == null ? null : Number(v))
const entierEntre = (v, min, max) => v !== '' && v != null && Number.isInteger(Number(v)) && Number(v) >= min && Number(v) <= max

// Ce que la création vérifie avant d'envoyer : « 2,5 entrées » partirait en décimal vers une colonne
// entière et reviendrait en erreur technique ; un prélèvement au 30 sauterait février.
export function complementValide(type, { carte, formule }) {
  const complement = complementDuType(type)
  if (complement === 'carte') return entierEntre(carte?.nbPaye, 1, Infinity) && entierEntre(carte?.nbCredite, 1, Infinity)
  if (complement === 'formule') return !formule?.sepaActif || formule.jourPrelevement === '' || entierEntre(formule.jourPrelevement, 1, 28)
  return true
}

// Le corps de `POST /produits` : la carte ou la formule partent DANS LE MÊME APPEL. Créées après
// coup, elles dépendraient d'un second geste — celui qu'on oubliait, et le produit se publiait sans.
export function corpsCreation({ libelle, typeId, canaux, type, carte, formule }) {
  const corps = { libelle: { fr: libelle.trim() }, type: `/api/type_produits/${typeId}`, canaux }
  const complement = complementDuType(type)
  if (complement === 'carte' && carte) {
    corps.carte = { nbPaye: entier(carte.nbPaye), nbCredite: entier(carte.nbCredite) }
  }
  if (complement === 'formule' && formule) {
    corps.formule = {
      periodicite: formule.periodicite,
      sepaActif: !!formule.sepaActif,
      jourPrelevement: formule.sepaActif ? entier(formule.jourPrelevement) : null,
    }
  }
  return corps
}
