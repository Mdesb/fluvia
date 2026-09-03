import { useEffect, useState } from 'react'
import JournalPassages from '../components/JournalPassages.jsx'
import { lireHash } from '../api/url.js'

// Journal des passages (A-05) — ce que le controle d'acces a fait, et pourquoi.
//
// ⚠ CET ECRAN VIVAIT DANS UN ONGLET DE « TOPOLOGIE & PASSAGES », ET C'EST LE CRITERE DE R21 QUI L'A
// SORTI DE LA. La topologie s'installe une fois : le plan du site, les lecteurs, les sous-reseaux.
// Le journal se relit chaque fois qu'un client dit « mon billet ne passe pas ». Deux rythmes, deux
// endroits — et le second n'a pas a traverser un ecran de parametrage pour etre atteint.
//
// Arbitrage de Maxime a la revue : « sa propre entree de menu pour le moment, on pourra toujours
// le bouger ».
//
// Page mince par-dessus le composant, comme `Recouvrement.jsx` par-dessus `ImpayesRecouvrement`.
export default function JournalPassagesPage({ etabActif }) {
  // ⚠ LA CIBLE VIENT DE L'URL, PARCE QUE LES BOUTONS « PASSAGES » DE LA TOPOLOGIE SONT DESORMAIS
  // DANS UN AUTRE ECRAN. Ils appellent `allerA('journal_passages', { espace })` ; sans cette
  // lecture, on arriverait ici sans filtre et on verrait TOUS les passages du site — ce qui
  // ressemble a une reponse alors que c'est la question qu'on vient de perdre.
  //
  // On se reabonne au hash : revenir en arriere depuis un lecteur vers un autre change les
  // parametres sans remonter le composant.
  const [cible, setCible] = useState(() => cibleDuHash())

  useEffect(() => {
    const relire = () => setCible(cibleDuHash())
    window.addEventListener('hashchange', relire)
    window.addEventListener('fluvia:navigation', relire)
    return () => {
      window.removeEventListener('hashchange', relire)
      window.removeEventListener('fluvia:navigation', relire)
    }
  }, [])

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Journal des passages</h1>
          <p>Chaque passage a la porte, avec son resultat et sa raison</p>
        </div>
      </div>
      <JournalPassages etabActif={etabActif} cible={cible} />
    </div>
  )
}

// `null` plutot qu'un objet vide quand il n'y a rien : le composant teste `if (!cible) return`
// avant de poser un filtre, et un objet vide passerait ce test en effacant les filtres saisis.
function cibleDuHash() {
  const { params } = lireHash()
  if (!params.espace && !params.equipement) return null
  return { espace: params.espace || '', equipement: params.equipement || '' }
}
