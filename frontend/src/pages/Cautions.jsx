import CautionsGestion from '../components/CautionsGestion.jsx'

// Les cautions sont transversales, et c'est ce qui leur vaut une entrée à elles.
//
// Une caution naît à la piscine, au padel ou à la patinoire, mais l'argent consigné est un seul
// solde et une seule dette. Le régisseur qui veut savoir combien l'établissement doit rendre ne va
// pas poser la question trois fois, une par verticale : il vient ici. Les gestes, eux, restent dans
// les verticales — voir l'en-tête de `components/CautionsGestion.jsx`.
export default function Cautions({ etabActif, droits }) {
  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Cautions</h1>
          <p>Ce qui est consigné, ce qui a été retenu, et au nom de quel barème</p>
        </div>
      </div>

      <CautionsGestion etabActif={etabActif} droits={droits} />
    </div>
  )
}
