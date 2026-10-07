import ImpayesRecouvrement from '../components/ImpayesRecouvrement.jsx'
import { useEtatUrl } from '../api/url.js'

// LE RECOUVREMENT MÉRITE SA PORTE PARCE QU'IL NE SE CONSULTE PAS : IL SE TRAITE.
//
// Les huit autres vues de la comptabilité répondent à une question qu'on se pose (« combien avons-nous
// encaissé ? »). Celle-ci porte un TRAVAIL À FAIRE, et derrière chaque ligne quelqu'un ne peut plus
// entrer. Une file de travail rangée au quatrième onglet d'un écran de consultation ne se regarde
// que quand on y pense — or l'accès d'un abonné se rouvre au moment où il est réglé, pas au moment
// où l'on ouvre la comptabilité.
//
// ⚠ CE BLOC DISAIT « la comptabilité continue de l'afficher dans son onglet Impayés, à
// l'identique et sans copie ». Mesuré le 14/09, deux fois : `<ImpayesRecouvrement` n'apparaît
// QUE dans ce fichier, et les onglets de `Comptabilite` sont clôture / écritures / saisie /
// lettrage / régie. L'onglet « Impayés » a été retiré ; la phrase lui a survécu.
//
// Ce qui reste vrai se dit plus simplement : une porte, une pièce. Même correction que sur
// `Sepa.jsx` et `AppShell.jsx`, qui portaient la phrase jumelle.
const DEFAUTS_URL = { regle: '', reglement: '' }

export default function Recouvrement({ etabActif, droits }) {
  const [params, majParams] = useEtatUrl('recouvrement', DEFAUTS_URL)
  // Un écran de niveau 2 prend la page : ni titre ni sous-titre au-dessus de lui.
  const ecranOuvert = Boolean(params.regle || params.reglement)

  return (
    <div className="view">
      {!ecranOuvert && (
      <div className="view-head">
        <div className="ttl">
          <h1>Recouvrement</h1>
          <p>Impayés, représentations bancaires, et les accès qu&rsquo;ils ferment</p>
        </div>
      </div>
      )}

      <ImpayesRecouvrement etabActif={etabActif} droits={droits} params={params} majParams={majParams} />
    </div>
  )
}
