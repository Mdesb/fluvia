import PrelevementsSepa from '../components/PrelevementsSepa.jsx'
import { useEtatUrl } from '../api/url.js'

// Le prélèvement SEPA a sa porte, et c'est désormais la seule.
//
// ⚠ CE BLOC DISAIT « rendu à deux endroits : ici, et dans l'onglet SEPA de la comptabilité »,
// et défendait l'entrée de menu par là : une seule implémentation, donc deux portes qui ne
// peuvent pas diverger. Mesuré le 14/09 : `<PrelevementsSepa` n'apparaît QU'ICI, et
// `Comptabilite.jsx` porte lui-même la mention « SEPA EST PARTI AUSSI ». L'onglet a été
// retiré ; la justification est restée, et elle s'appuyait sur un fait disparu.
//
// Ce qui reste vrai, et se dit plus simplement : une porte, une pièce. L'objection d'origine
// portait sur la duplication — il n'y en a plus du tout.
// ⚠ L'ONGLET ENTRE DANS L'ADRESSE EN MÊME TEMPS QUE LES ÉCRANS. Il vit dans le composant, pas
// ici : sans le paramètre `tab`, revenir d'un écran retomberait sur « Mandats » quel que soit
// l'onglet d'où l'on venait.
const DEFAUTS_URL = { tab: 'mandats', mandat: '', creancier: '' }

export default function Sepa({ etabActif, droits }) {
  const [params, majParams] = useEtatUrl('sepa', DEFAUTS_URL)
  // Un écran de niveau 2 prend la page : ni titre ni sous-titre au-dessus de lui.
  const ecranOuvert = Boolean(params.mandat || params.creancier)

  return (
    <div className="view">
      {!ecranOuvert && (
      <div className="view-head">
        <div className="ttl">
          <h1>Prélèvements SEPA</h1>
          <p>Mandats signés, remises envoyées à la banque, rejets reçus</p>
        </div>
      </div>
      )}

      <PrelevementsSepa etabActif={etabActif} droits={droits} params={params} majParams={majParams} />
    </div>
  )
}
