import PrelevementsSepa from '../components/PrelevementsSepa.jsx'

// Le prélèvement SEPA a désormais sa porte, et la comptabilité garde la sienne.
//
// L'écran est un composant partagé (`components/PrelevementsSepa.jsx`) rendu à deux endroits : ici,
// pour qui vient chercher « les prélèvements », et dans l'onglet SEPA de la comptabilité, pour qui
// les cherche au milieu de ses comptes. Une seule implémentation, donc deux portes qui ne peuvent
// pas diverger — c'était la seule objection sérieuse à leur ouvrir une entrée de menu.
export default function Sepa({ etabActif, droits }) {
  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Prélèvements SEPA</h1>
          <p>Mandats signés, remises envoyées à la banque, rejets reçus</p>
        </div>
      </div>

      <PrelevementsSepa etabActif={etabActif} droits={droits} />
    </div>
  )
}
