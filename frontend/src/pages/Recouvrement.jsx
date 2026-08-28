import ImpayesRecouvrement from '../components/ImpayesRecouvrement.jsx'

// LE RECOUVREMENT MÉRITE SA PORTE PARCE QU'IL NE SE CONSULTE PAS : IL SE TRAITE.
//
// Les huit autres vues de la comptabilité répondent à une question qu'on se pose (« combien avons-nous
// encaissé ? »). Celle-ci porte un TRAVAIL À FAIRE, et derrière chaque ligne quelqu'un ne peut plus
// entrer. Une file de travail rangée au quatrième onglet d'un écran de consultation ne se regarde
// que quand on y pense — or l'accès d'un abonné se rouvre au moment où il est réglé, pas au moment
// où l'on ouvre la comptabilité.
//
// Comme pour SEPA et les cautions, le contenu est le composant partagé : la comptabilité continue de
// l'afficher dans son onglet « Impayés », à l'identique et sans copie.
export default function Recouvrement({ etabActif, droits }) {
  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Recouvrement</h1>
          <p>Impayés, représentations bancaires, et les accès qu&rsquo;ils ferment</p>
        </div>
      </div>

      <ImpayesRecouvrement etabActif={etabActif} droits={droits} />
    </div>
  )
}
