// LA FICHE PRODUIT, REFONTE LOT 2 (03/11) : les onglets suivent le TYPE autant que les capacités, et
// la fiche s'enregistre seule (R9). Ce module ne porte que des règles pures, testées sans écran.
//
// Lancer : `cd frontend && node --test src/api/`.

// ── LES ONGLETS ──────────────────────────────────────────────────────────────────────────────────
//
// Les capacités de l'établissement décident des modules (accès, stock, comptabilité…) ; le TYPE du
// produit décide de ce qu'il porte en plus (CA-3). Un abonnement a sa formule, une carte ses entrées :
// rangées jusqu'ici en bas de « Vente », sous la diffusion, on ne les trouvait qu'en descendant.
//
// ⚠ ABSENT, JAMAIS GRISÉ — même règle que pour les capacités. Un produit de boutique n'a pas d'onglet
// « Abonnement ».
//
// `complement` : ce que le type exige (`complementDuType`), ou ce que le produit porte déjà — une
// formule créée avant que le type ne change de facettes reste réglable, sinon elle deviendrait
// invisible sans cesser d'exister.
export function sheetTabs(capacites, complement, libelleCarte = 'Carte') {
  const liste = [['vitrine', 'Présentation'], ['vente', 'Vente']]
  if (complement === 'formule') liste.push(['formule', 'Abonnement'])
  if (complement === 'carte') liste.push(['carte', libelleCarte])
  liste.push(['caisse', 'Caisse'])
  if (capacites.includes('agenda') || capacites.includes('reservation')) liste.push(['agenda', 'Agenda'])
  if (capacites.includes('controle_acces')) liste.push(['acces', 'Accès'])
  if (capacites.includes('stock')) liste.push(['stock', 'Stock'])
  if (capacites.includes('comptabilite')) liste.push(['compta', 'Comptabilité'])
  return liste
}

export function sheetComplement(typeComplement, edition) {
  if (typeComplement) return typeComplement
  if (edition?.formule) return 'formule'
  if (edition?.carte) return 'carte'
  return null
}

// ── LA SAUVEGARDE AUTOMATIQUE ────────────────────────────────────────────────────────────────────
//
// Comparer des brouillons sans se tromper : `canaux` et `categories` sont des tableaux recréés à
// chaque frappe, `formule` et `carte` des objets recomposés dans un autre ordre. Une comparaison par
// identité verrait une modification à chaque rendu — et une sauvegarde automatique enregistrerait
// en boucle.
export function stableForm(v) {
  if (v === null || v === undefined) return ''
  if (Array.isArray(v)) return JSON.stringify([...v].sort())
  if (typeof v === 'object') return JSON.stringify(Object.keys(v).sort().map((k) => [k, stableForm(v[k])]))
  return String(v)
}

export function changedFields(edition, reference, champs) {
  if (!edition || !reference) return []
  return champs.filter((c) => stableForm(edition[c]) !== stableForm(reference[c]))
}

// CE QUI SUSPEND LA SAUVEGARDE AUTOMATIQUE. Deux brouillons ne partent pas seuls :
//
// - un nom vide : on est en train de le retaper, et l'enregistrer effacerait le libellé ;
// - un produit qui sortirait du périmètre de qui l'édite. Avec un bouton « Enregistrer », l'avertissement
//   avait le temps d'être lu. Enregistré au clic sur la case, le produit disparaîtrait de l'écran sous
//   les yeux de l'exploitant, sans qu'il ait rien confirmé : on attend son « oui ».
//
// Rend `null` quand rien ne retient, sinon le code de la raison.
export function autosaveHold(edition, etabActif) {
  if (!edition) return null
  if (!String(edition.libelle || '').trim()) return 'libelle'
  const sites = edition.etablissements || []
  if (etabActif && sites.length > 0 && !sites.includes(etabActif)) return 'perimetre'
  return null
}

// `ton` est une classe de badge (`good`, `warn`, `crit`, `mut`). Ce que l'indicateur dit, dans l'ordre où ça compte : une écriture en cours, un échec (qui ne se
// répète pas tout seul), une attente, puis « Enregistré ». `null` tant qu'on n'a rien touché : un
// « Enregistré » affiché à l'ouverture affirmerait une écriture qui n'a pas eu lieu.
export function saveIndicator({ enCours, echec, enAttente, retenue, dejaEnregistre }) {
  if (enCours) return { ton: 'mut', texte: 'Enregistrement…' }
  if (echec) return { ton: 'crit', texte: 'Non enregistré' }
  if (retenue === 'libelle') return { ton: 'warn', texte: 'Non enregistré : donnez-lui un nom' }
  if (retenue === 'perimetre') return { ton: 'warn', texte: 'En attente de votre confirmation' }
  if (enAttente) return { ton: 'mut', texte: 'Modifications en cours…' }
  if (dejaEnregistre) return { ton: 'good', texte: 'Enregistré' }
  return null
}
