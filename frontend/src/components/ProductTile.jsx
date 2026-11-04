// LA TUILE PRODUIT DE LA GRILLE DU GUICHET — rendue par la caisse ET par l'aperçu de la fiche produit.
//
// ⚠ UNE SEULE PRÉSENTATION, DEUX APPELANTS. Un aperçu qui réécrirait ce rendu divergerait au premier
// correctif d'un seul des deux, et montrerait à l'exploitant une tuile que le vendeur ne voit pas.
//
// La couleur de caisse est un LISERÉ, jamais un fond : le texte garde l'encre du thème, donc son
// contraste, quelle que soit la couleur choisie — et en sombre comme en clair. Même geste que la
// tuile active (`.prodtile.actif`).
//
// Sans `onClick`, la tuile n'est pas un bouton : un aperçu ne vend rien et ne doit rien promettre au
// clic ni au clavier.
import { euros } from '../api/produit.js'

export default function ProductTile({ libelle, code, prix, raison, couleur, onClick, disabled, title }) {
  const style = couleur ? { boxShadow: `inset var(--esp-serre) 0 0 0 ${couleur}` } : undefined
  const contenu = (
    <>
      <span className="pn">{libelle}</span>
      {code && <span className="pc">{code}</span>}
      {raison ? <span className="pw">{raison}</span> : <span className="pp">{euros(prix)}</span>}
    </>
  )

  if (!onClick) {
    return <div className="prodtile apercu" style={style}>{contenu}</div>
  }

  return (
    <button className="prodtile" style={style} disabled={disabled} onClick={onClick} title={title}>
      {contenu}
    </button>
  )
}
