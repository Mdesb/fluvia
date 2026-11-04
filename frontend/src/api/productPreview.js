// LES APERÇUS DE LA FICHE PRODUIT, REFONTE LOT 3 (04/11) : ce que le guichet et la boutique en ligne
// montreront, à jour pendant la saisie. Ce module ne porte que des règles pures, testées sans écran.
//
// Lancer : `cd frontend && node --test src/api/*.test.js`.
//
// ⚠ L'APERÇU SUIT LE BROUILLON, PAS LE PRODUIT ENREGISTRÉ. Le nom, la couleur et les canaux viennent de
// `edition` : un aperçu qui attendrait l'enregistrement montrerait l'avant-dernière frappe, et on
// croirait que la saisie n'a pas pris. Ce qui ne se saisit pas sur la fiche (code, tarifs, photos)
// vient du produit lu.
import { grillesVendables, prixIndicatif, raisonNonVendable } from './produit.js'

const SANS_NOM = 'Sans nom'

// La tuile de la grille du guichet : les mêmes champs que `Caisse.jsx` lui passe.
export function tilePreview(edition, produit) {
  const brouillon = { ...produit, libelle: edition?.libelle }
  return {
    libelle: String(edition?.libelle || '').trim() || SANS_NOM,
    code: produit?.code || '',
    prix: prixIndicatif(produit),
    raison: raisonNonVendable(brouillon),
    couleur: edition?.couleurCaisse || null,
  }
}

// La carte de la boutique en ligne, dans la forme que rend le catalogue public
// (`CatalogueVitrineProvider`) : c'est ce que `ShopCard` sait afficher.
//
// ⚠ LE PRIX EST UNE FOURCHETTE INDICATIVE. La boutique ne retient que les tarifs ouverts à la vente en
// ligne pour la saison du jour, une règle que le serveur tient seul. L'aperçu prend les tarifs du
// produit ; l'écran le dit sous la carte plutôt que de laisser croire au prix exact.
export function shopCardPreview(edition, produit, photos) {
  const prix = grillesVendables(produit).map((g) => Number(g.prix)).filter((n) => !Number.isNaN(n))
  return {
    enLigne: (edition?.canaux || []).includes('en_ligne'),
    carte: {
      produit: produit?.id || '',
      code: produit?.code || '',
      libelle: String(edition?.libelle || '').trim() || SANS_NOM,
      timedEntry: false,
      disponibilite: null,
      visuel: photos?.[0]?.url || null,
      prix: prix.length ? { min: Math.min(...prix), max: Math.max(...prix) } : null,
    },
  }
}
