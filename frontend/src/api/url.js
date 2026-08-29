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

// COMBIEN DE PAS EN ARRIÈRE RESTENT DANS L'APPLICATION — PORTÉ PAR L'ENTRÉE, PAS PAR UN COMPTEUR.
//
// Le bouton « Précédent » de la barre du haut ne doit s'afficher que s'il y a où revenir. Un
// compteur en mémoire s'en approcherait, mais il se désynchronise à la première subtilité — un
// « Suivant », un rechargement, une entrée posée par un autre écran — et un bouton de retour qui
// SORT de l'application est pire que pas de bouton du tout : c'est précisément ce qu'on répare.
//
// L'historique, lui, sait déjà. Chaque entrée qu'on empile porte sa profondeur ; le navigateur la
// restitue telle quelle en reculant, en avançant et après un rechargement. On lit, on ne compte pas.
export function profondeurHistorique() {
  if (typeof window === 'undefined') return 0
  const n = window.history.state?.fluviaProfondeur
  return typeof n === 'number' && n > 0 ? n : 0
}

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
  // Empiler AJOUTE un pas ; remplacer garde celui de l'entrée qu'on réécrit — sinon changer un
  // filtre effacerait la profondeur et ferait disparaître le bouton « Précédent » de la barre.
  const profondeur = profondeurHistorique()
  if (pousser) window.history.pushState({ fluviaProfondeur: profondeur + 1 }, '', cible)
  else window.history.replaceState({ fluviaProfondeur: profondeur }, '', cible)
  // ⚠ `pushState` ET `replaceState` N'ÉMETTENT RIEN — c'est écrit plus haut pour `allerA`, et ça
  // vaut aussi pour ce qui OBSERVE la navigation. La barre du haut n'apprenait donc jamais qu'un
  // pas venait d'être empilé : son bouton « Précédent » restait caché après trois changements
  // d'écran, alors que la profondeur montait bien. Constaté à l'écran, pas déduit.
  window.dispatchEvent(new Event('fluvia:navigation'))
}

/**
 * Va sur un AUTRE écran, en lui passant des paramètres.
 *
 * POURQUOI CE N'EST PAS `ecrireHash`, ET COMMENT LE DÉFAUT S'EST MONTRÉ.
 *
 * `ecrireHash` écrit par `pushState` / `replaceState` — et NI L'UN NI L'AUTRE N'ÉMET
 * `hashchange`. C'est sans conséquence quand un écran décrit son propre état : il vient de mettre
 * à jour son état React, l'URL ne fait que le refléter, et personne n'a besoin d'être prévenu.
 *
 * Pour changer d'écran, c'est l'inverse : `App` n'apprend l'existence d'un nouvel onglet que par
 * `hashchange` ou `popstate`. Le 29/08, le bouton « Données personnelles » posé sur la fiche
 * client appelait `ecrireHash('rgpd', …)` : l'adresse changeait dans la barre, et l'écran ne
 * bougeait pas. Un bouton parfaitement inerte, dont rien ne signalait l'inertie — ni erreur, ni
 * console, ni test rouge. Il a fallu cliquer dessus pour le voir.
 *
 * Affecter `location.hash` émet `hashchange` ET empile une entrée d'historique, ce qui est le
 * comportement voulu : le « Précédent » du navigateur ramène d'où l'on vient.
 */
export function allerA(onglet, params = {}) {
  if (typeof window === 'undefined' || !onglet) return
  const usp = new URLSearchParams()
  for (const [cle, valeur] of Object.entries(params)) {
    if (valeur === '' || valeur === null || valeur === undefined || valeur === false) continue
    usp.append(cle, String(valeur))
  }
  const q = usp.toString()
  // La profondeur se lit AVANT l'affectation : celle-ci empile une entrée neuve dont l'état est
  // nul, et la relire ensuite rendrait zéro — le bouton « Précédent » disparaîtrait au moment
  // précis où il devient utile.
  const profondeur = profondeurHistorique()
  window.location.hash = onglet + (q ? '?' + q : '')
  window.history.replaceState({ fluviaProfondeur: profondeur + 1 }, '')
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
