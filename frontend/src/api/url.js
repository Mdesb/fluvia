import { useCallback, useEffect, useRef, useState } from 'react'

// L'ÉTAT D'UN ÉCRAN VIT DANS L'URL, ET LA TROISIÈME RAISON EST LA VRAIE.
//
// Maxime, en décrivant l'écran Clients : « il faudra un bouton retour pour qu'on retourne sur la
// liste et s'il y a eu des filtres il faudra qu'ils soient encore en place. »
//
// On pouvait le faire en gardant les filtres dans l'état du composant. Trois choses s'obtiennent
// d'un coup en les mettant plutôt dans l'URL, et c'est la troisième qui décide :
//
//   1. le retour restitue l'état — ce qui était demandé ;
//   2. une liste filtrée devient partageable et marquable en favori ;
//   3. ELLE SURVIT À L'EXPIRATION DE SESSION.
//
// Le jeton dure une heure. Une session en revue avec Maxime s'est fait éjecter deux fois en une
// soirée. Sans état dans l'URL, chaque expiration efface le travail de tri de l'exploitant : il se
// reconnecte sur la caisse, écran vierge, et refait ses filtres. Avec, il revient exactement où il
// était. La dette de socle sur la durée du jeton ne nous appartient pas ; cesser d'en souffrir, si.
//
// FORME RETENUE : `#onglet?cle=valeur&autre=valeur`.
//
// L'application ne routait sur RIEN — `onglet` vivait dans l'état de `App`, et l'URL ne bougeait
// jamais. On aurait donc pu mettre les filtres dans l'URL sans y mettre l'onglet : ça n'aurait servi
// à rien, puisqu'au rechargement on serait revenu sur la caisse avec des filtres pointant un écran
// qu'on ne regarde pas. L'onglet est donc dans l'URL lui aussi, et c'est ce qui rend le reste utile.

/** Lit l'onglet et les paramètres portés par le hash courant. */
export function lireHash() {
  const brut = (typeof window === 'undefined' ? '' : window.location.hash || '').replace(/^#/, '')
  const [onglet, requete = ''] = brut.split('?')
  return {
    onglet: decodeURIComponent(onglet || ''),
    params: Object.fromEntries(new URLSearchParams(requete)),
  }
}

/**
 * Écrit l'onglet et ses paramètres dans le hash.
 *
 * `pousser` ajoute une entrée à l'historique du navigateur (ouvrir une fiche) plutôt que de
 * remplacer l'entrée courante (changer un filtre). C'est ce qui fait que le bouton « Précédent » du
 * navigateur ramène à la liste : sans ça il quitterait l'application, ce que personne n'attend en
 * fermant une fiche.
 */
export function ecrireHash(onglet, params = {}, { pousser = false } = {}) {
  if (typeof window === 'undefined' || !onglet) return
  const usp = new URLSearchParams()
  for (const [cle, valeur] of Object.entries(params)) {
    // Un filtre vide, absent ou décoché ne s'écrit pas : l'URL doit rester lisible, et
    // `?statut=&mineur=false` ne dit rien de plus que rien du tout.
    if (valeur === '' || valeur === null || valeur === undefined || valeur === false) continue
    usp.append(cle, String(valeur))
  }
  const q = usp.toString()
  const cible = `#${onglet}${q ? `?${q}` : ''}`
  if (window.location.hash === cible) return
  if (pousser) window.history.pushState(null, '', cible)
  else window.history.replaceState(null, '', cible)
}

/**
 * État d'écran porté par l'URL.
 *
 * `defauts` donne les clés surveillées ET leurs valeurs par défaut : une clé absente du hash prend
 * sa valeur par défaut, et une valeur égale au défaut n'est pas écrite. On ne surveille que ces
 * clés-là — un écran ne doit pas effacer les paramètres d'un autre en écrivant le hash.
 *
 * Rend `[params, majParams]`. `majParams(patch, { pousser })` fusionne et réécrit.
 */
export function useEtatUrl(onglet, defauts) {
  const lire = useCallback(() => {
    const { params } = lireHash()
    const sortie = {}
    for (const [cle, defaut] of Object.entries(defauts)) {
      sortie[cle] = Object.prototype.hasOwnProperty.call(params, cle) ? params[cle] : defaut
    }
    return sortie
  }, [defauts])

  const [params, setParams] = useState(lire)

  // `defauts` est le plus souvent un littéral, donc une nouvelle référence à chaque rendu. Le
  // garder dans une ref évite que `lire` change d'identité en boucle et relance l'effet sans fin.
  const refLire = useRef(lire)
  refLire.current = lire

  // Le bouton « Précédent » du navigateur change le hash sans démonter le composant : sans cet
  // abonnement, l'URL reculerait et l'écran resterait sur la fiche ouverte.
  useEffect(() => {
    function surChangement() {
      setParams(refLire.current())
    }
    window.addEventListener('hashchange', surChangement)
    window.addEventListener('popstate', surChangement)
    return () => {
      window.removeEventListener('hashchange', surChangement)
      window.removeEventListener('popstate', surChangement)
    }
  }, [])

  const majParams = useCallback(
    (patch, options) => {
      setParams((precedent) => {
        const suivant = { ...precedent, ...patch }
        const aEcrire = {}
        for (const [cle, valeur] of Object.entries(suivant)) {
          if (valeur !== defauts[cle]) aEcrire[cle] = valeur
        }
        ecrireHash(onglet, aEcrire, options)
        return suivant
      })
    },
    [onglet, defauts],
  )

  return [params, majParams]
}
