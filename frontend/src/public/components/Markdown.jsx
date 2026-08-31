/**
 * Rendu Markdown minimal — **en éléments React, jamais en HTML injecté.**
 *
 * **Pourquoi pas `dangerouslySetInnerHTML`.** Le texte vient d'un champ libre du back-office. Il est
 * saisi par l'exploitant, donc « de confiance » — sauf que la confiance porte sur *la personne*, pas
 * sur *le contenu* : un texte collé depuis un traitement de texte, un site d'exemple ou une IA peut
 * transporter du balisage sans que personne l'ait voulu. Produire des éléments React rend l'injection
 * **structurellement impossible**, au lieu de la rendre improbable.
 *
 * > **Assainir, c'est se demander à chaque fois si on a pensé à tout. Ne pas interpréter, c'est ne pas
 * > avoir la question.**
 *
 * **Le sous-ensemble couvert est exactement celui qu'écrit `LegalDocumentGenerator`** : titres, listes,
 * tableaux, citations, gras, italique, code. Rien d'autre — pas de liens, pas d'images. Un document
 * légal n'a pas besoin d'images, et un lien inséré dans des CGV mérite d'être visible en clair plutôt
 * que masqué derrière un libellé.
 */

function enLigne(texte, cle) {
  // Gras, italique et code, dans cet ordre : `**` avant `*`, sinon le second mange le premier.
  const morceaux = []
  const motif = /(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`)/g
  let dernier = 0
  let m
  let i = 0

  while ((m = motif.exec(texte)) !== null) {
    if (m.index > dernier) morceaux.push(texte.slice(dernier, m.index))
    const jeton = m[0]
    if (jeton.startsWith('**')) morceaux.push(<b key={`${cle}-b${i}`}>{jeton.slice(2, -2)}</b>)
    else if (jeton.startsWith('`')) morceaux.push(<code key={`${cle}-c${i}`}>{jeton.slice(1, -1)}</code>)
    else morceaux.push(<i key={`${cle}-i${i}`}>{jeton.slice(1, -1)}</i>)
    dernier = m.index + jeton.length
    i += 1
  }
  if (dernier < texte.length) morceaux.push(texte.slice(dernier))

  return morceaux
}

export default function Markdown({ texte }) {
  const lignes = String(texte || '').split('\n')
  const blocs = []
  let i = 0
  let cle = 0

  while (i < lignes.length) {
    const ligne = lignes[i]

    if (ligne.trim() === '') {
      i += 1
      continue
    }

    // Titres
    const titre = /^(#{1,4})\s+(.*)$/.exec(ligne)
    if (titre) {
      const niveau = titre[1].length
      const Balise = `h${Math.min(niveau + 1, 6)}`
      blocs.push(<Balise key={cle++} style={{ marginTop: niveau === 1 ? 0 : 22 }}>{enLigne(titre[2], cle)}</Balise>)
      i += 1
      continue
    }

    // Tableaux : ligne d'en-tête, ligne de séparation, puis les lignes.
    if (ligne.trim().startsWith('|') && (lignes[i + 1] || '').trim().startsWith('|--')) {
      const cellules = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim())
      const entetes = cellules(ligne)
      const corps = []
      let j = i + 2
      while (j < lignes.length && lignes[j].trim().startsWith('|')) {
        corps.push(cellules(lignes[j]))
        j += 1
      }
      blocs.push(
        // Un tableau large ne fait pas défiler la page : il défile dans son propre conteneur.
        <div key={cle++} style={{ overflowX: 'auto', margin: '14px 0' }}>
          <table className="tbl">
            <thead>
              <tr>{entetes.map((h, k) => <th key={k}>{enLigne(h, `${cle}-h${k}`)}</th>)}</tr>
            </thead>
            <tbody>
              {corps.map((r, k) => (
                <tr key={k}>{r.map((c, l) => <td key={l}>{enLigne(c, `${cle}-${k}-${l}`)}</td>)}</tr>
              ))}
            </tbody>
          </table>
        </div>,
      )
      i = j
      continue
    }

    // Citations
    if (ligne.trim().startsWith('>')) {
      const contenu = []
      while (i < lignes.length && lignes[i].trim().startsWith('>')) {
        contenu.push(lignes[i].trim().replace(/^>\s?/, ''))
        i += 1
      }
      blocs.push(
        <blockquote
          key={cle++}
          style={{ borderLeft: '3px solid var(--line)', margin: '14px 0', padding: '4px 0 4px 14px', color: 'var(--ink-soft)' }}
        >
          {enLigne(contenu.join(' '), cle)}
        </blockquote>,
      )
      continue
    }

    // Listes
    if (/^\s*[-*]\s+/.test(ligne)) {
      const items = []
      while (i < lignes.length && /^\s*[-*]\s+/.test(lignes[i])) {
        items.push(lignes[i].replace(/^\s*[-*]\s+/, ''))
        i += 1
      }
      blocs.push(
        <ul key={cle++} style={{ margin: '10px 0', paddingLeft: 22 }}>
          {items.map((it, k) => <li key={k} style={{ marginBottom: 4 }}>{enLigne(it, `${cle}-${k}`)}</li>)}
        </ul>,
      )
      continue
    }

    // Paragraphe : les lignes consécutives se recollent, comme en Markdown.
    const para = []
    while (
      i < lignes.length
      && lignes[i].trim() !== ''
      && !/^(#{1,4})\s/.test(lignes[i])
      && !lignes[i].trim().startsWith('>')
      && !lignes[i].trim().startsWith('|')
      && !/^\s*[-*]\s+/.test(lignes[i])
    ) {
      para.push(lignes[i].trim())
      i += 1
    }
    blocs.push(<p key={cle++} style={{ margin: '10px 0', lineHeight: 1.65 }}>{enLigne(para.join(' '), cle)}</p>)
  }

  return <div>{blocs}</div>
}
