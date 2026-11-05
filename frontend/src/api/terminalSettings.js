// LE RÉGLAGE DU TERMINAL DE PAIEMENT D'UN POINT DE VENTE (spec TPE lot 1 §6).
//
// Le serveur attend `PointDeVente.tpe = { connecteur, marque?, ref? }` et refuse toute autre forme ;
// sans réglage, le point de vente encaisse par le terminal EXTERNE (le caissier déclare le résultat).
// L'écran Paramètres en fait trois champs, repliés ici en un seul réglage à l'envoi.

// Les connecteurs disponibles ; ceux des lots suivants (Nepting, Stripe) s'ajouteront ici.
export const CONNECTEURS_TPE = [
  { valeur: 'externe', libelle: 'Externe — le caissier déclare le résultat' },
  { valeur: 'caisse_ap_pont', libelle: 'Relié (Caisse-AP) — le montant part au terminal par le pont de caisse' },
  { valeur: 'simule', libelle: 'Simulé — démonstration seulement' },
]

// `existant` : le réglage actuel du point de vente. Ce que le formulaire ne montre pas — la trace de
// la migration (`terminaux`, `ancien`), les paramètres d'un connecteur — est GARDÉ : le reconstruire
// à partir des trois champs l'effaçait à chaque modification (relecture D3). Un réglage d'avant ce
// lot (liste, booléen) n'est pas un objet de paramètres : il n'est pas recopié.
export function avecTerminal({ tpe, tpeMarque, tpeRef, tpeClePont, tpePont, tpeTiroir, ...corps }, existant = null) {
  const garde = existant && typeof existant === 'object' && !Array.isArray(existant) ? existant : {}
  const reglage = { ...garde, connecteur: tpe || 'externe' }
  delete reglage.marque
  delete reglage.ref
  if (typeof tpeMarque === 'string' && tpeMarque.trim()) reglage.marque = tpeMarque.trim()
  if (typeof tpeRef === 'string' && tpeRef.trim()) reglage.ref = tpeRef.trim()
  // Le pont de caisse (#340) : sa clé PUBLIQUE, son adresse sur le poste, et le tiroir qu'il ouvre.
  // Un champ montré et vidé efface le réglage ; un champ absent du formulaire le garde.
  if (typeof tpeClePont === 'string') {
    if (tpeClePont.trim()) reglage.clePont = tpeClePont.trim()
    else delete reglage.clePont
  }
  if (typeof tpePont === 'string') {
    if (tpePont.trim()) reglage.pont = tpePont.trim()
    else delete reglage.pont
  }
  if (typeof tpeTiroir === 'boolean') {
    if (tpeTiroir) reglage.tiroir = true
    else delete reglage.tiroir
  }
  return { ...corps, tpe: reglage }
}
