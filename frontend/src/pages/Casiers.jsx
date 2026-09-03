import CasiersPiscine from '../components/CasiersPiscine.jsx'

// Casiers — attribution, caution et retour des vestiaires.
//
// ⚠ CET ECRAN VIVAIT DANS UN ONGLET DE « PISCINE », ET C'EST LE CRITERE DE R21 QUI L'EN A SORTI.
// Surveiller un bassin est une exploitation CONTINUE — on regarde la jauge, on compte les
// presents. Gerer un parc de casiers est de la gestion d'equipement : on attribue, on encaisse une
// caution, on rend une cle. Deux metiers, deux rythmes, et Maxime les a separes de lui-meme
// (R10 : « casier doit etre dans un menu a part »).
//
// ⚠ IL RESTAIT UN SEUL ONGLET DANS PISCINE APRES CE DEPART, donc la barre d'onglets a disparu :
// un onglet unique se presente comme un choix alors qu'il n'y en a plus.
//
// Page mince par-dessus le composant, comme `Recouvrement.jsx` par-dessus `ImpayesRecouvrement`.
export default function Casiers({ etabActif, droits }) {
  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Casiers</h1>
          <p>Attribution, caution et retour des vestiaires</p>
        </div>
      </div>
      <CasiersPiscine etabActif={etabActif} droits={droits} />
    </div>
  )
}
