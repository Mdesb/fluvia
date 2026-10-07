import CautionsGestion from '../components/CautionsGestion.jsx'
import { useEtatUrl } from '../api/url.js'

// Les cautions sont transversales, et c'est ce qui leur vaut une entrée à elles.
//
// Une caution naît à la piscine, au padel ou à la patinoire, mais l'argent consigné est un seul
// solde et une seule dette. Le régisseur qui veut savoir combien l'établissement doit rendre ne va
// pas poser la question trois fois, une par verticale : il vient ici. Les gestes, eux, restent dans
// les verticales — voir l'en-tête de `components/CautionsGestion.jsx`.
const DEFAUTS_URL = { bareme: '' }

export default function Cautions({ etabActif, droits }) {
  const [params, majParams] = useEtatUrl('caution', DEFAUTS_URL)
  // Un écran de niveau 2 prend la page : ni titre ni sous-titre au-dessus de lui.
  const ecranOuvert = Boolean(params.bareme)

  return (
    <div className="view">
      {!ecranOuvert && (
      <div className="view-head">
        <div className="ttl">
          <h1>Cautions</h1>
          <p>Ce qui est consigné, ce qui a été retenu, et au nom de quel barème</p>
        </div>
      </div>
      )}

      <CautionsGestion etabActif={etabActif} droits={droits} params={params} majParams={majParams} />
    </div>
  )
}
