import { useEffect, useState } from 'react'
import { api, membres } from './client.js'

/**
 * Vocabulaire des VERTICALES (#100, option B) — à ne pas confondre avec `vocabulaire.js`, qui humanise
 * les codes techniques du serveur (statuts…). Ici, le mot affiché dépend de la verticale de la
 * RESSOURCE : un « créneau » sur un terrain de padel se dit « Réservation de terrain », sur un bassin
 * « Créneau public ». On charge `/vocabulary` une fois ; `t(cle, verticale, repli)` rend le libellé
 * résolu, avec repli sur la verticale de l'établissement (marquée « courant » par l'API) puis sur `repli`.
 *
 * ⚠ AUCUN MOT N'EST ÉCRIT EN DUR ICI : ils viennent tous de l'API (les manifestes sont la source de
 * vérité — une liste en dur côté front aurait divergé). `repli` n'est qu'un libellé d'attente, affiché
 * le temps que `/vocabulary` réponde, puis remplacé.
 */
export function useVocabulaireVerticales() {
  // `null` = pas encore chargé ; un objet = chargé. { default: { cle: mot }, piscine: {...}, ... }
  const [tables, setTables] = useState(null)
  const [courant, setCourant] = useState(null) // id de la verticale de l'établissement, ou null

  useEffect(() => {
    let vivant = true
    api.vocabulary()
      .then((r) => {
        if (!vivant) return
        const parId = {}
        let cour = null
        for (const e of membres(r)) {
          parId[e.id] = e.labels || {}
          if (e.courant && e.id !== 'default') cour = e.id
        }
        setTables(parId)
        setCourant(cour)
      })
      .catch(() => {
        // Illisible : on laisse les replis s'afficher plutôt que de bloquer l'écran.
        if (vivant) setTables({})
      })
    return () => {
      vivant = false
    }
  }, [])

  function t(cle, verticale, repli = '') {
    if (tables === null) return repli // pas encore chargé : le libellé d'attente
    const v = verticale || courant
    const table = (v && tables[v]) || tables.default || {}
    return table[cle] ?? tables.default?.[cle] ?? repli ?? cle
  }

  return { t }
}
