// Couleurs de vitrine : appliquées seulement là où elles sont PROUVÉES lisibles.
//
// ⚠ CE FICHIER EXISTE PARCE QUE LE RÉGLAGE NE FAISAIT RIEN. Chaque exploitant configure une couleur
// primaire et une secondaire (`Vitrine::$couleurs`, écran Boutique → Vitrines). Elles étaient lues,
// stockées, transmises à travers toute la façade — et appliquées nulle part. Un exploitant réglait
// sa couleur et rien ne changeait.
//
// ── POURQUOI ON NE LES APPLIQUE PAS TELLES QUELLES ──────────────────────────────────────────────
//
// Mesuré sur les deux vitrines de démonstration, contraste sur fond clair :
//
//     Piscine A   primaire  #0B6E4F   6,25  ✔        secondaire  #F4A300   2,08  ✘
//     Patinoire B primaire  #1B1F3B  16,08  ✔        secondaire  #E63946   4,17  ✘
//
// Les deux secondaires sont sous le seuil de 4,5:1 exigé pour du texte. Les poser en couleur de
// texte rendrait des prix illisibles — sur un écran où l'on paie.
//
// ⚠ ET L'ENCRE CALCULÉE NE SAUVE QU'UN RÔLE DE FOND. Choisir noir ou blanc par-dessus une couleur
// règle le cas « la couleur est un fond ». Elle ne règle pas « la couleur est un texte » : là, ce
// serait le fond qu'il faudrait changer, ce qui n'est pas à la portée d'un jeton.
//
// D'où deux traitements différents, et non un seul :
//
//   · la PRIMAIRE garde les rôles qu'a déjà `--accent` — texte ET fond — et n'est appliquée que si
//     elle passe le seuil sur les deux fonds de la page, dans le thème courant ;
//   · la SECONDAIRE reçoit un rôle de FOND (la pastille de prix), avec son encre calculée. C'est le
//     seul rôle où sa lisibilité est garantie quelle que soit la couleur choisie.
//
// Le fond est lu à l'exécution (`getComputedStyle`) et non écrit en dur : en thème sombre, `--panel`
// est sombre, et un vert foncé qui passe sur blanc échoue sur lui. Un seuil calculé contre un blanc
// supposé serait faux la moitié du temps, sans que rien ne le signale.

const SEUIL_TEXTE = 4.5

function canal(v) {
  return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
}

/** Luminance relative WCAG. Rend `null` si la couleur n'est pas un hexadécimal lisible. */
export function luminance(hex) {
  const h = String(hex || '').trim().replace('#', '')
  const complet = h.length === 3 ? h.split('').map((c) => c + c).join('') : h
  if (!/^[0-9a-fA-F]{6}$/.test(complet)) return null
  const [r, v, b] = [0, 2, 4].map((i) => canal(parseInt(complet.slice(i, i + 2), 16) / 255))
  return 0.2126 * r + 0.7152 * v + 0.0722 * b
}

/** Rapport de contraste WCAG entre deux couleurs. `null` si l'une des deux est illisible. */
export function contraste(a, b) {
  const la = luminance(a)
  const lb = luminance(b)
  if (la === null || lb === null) return null
  const haut = Math.max(la, lb)
  const bas = Math.min(la, lb)
  return (haut + 0.05) / (bas + 0.05)
}

/**
 * L'encre la plus lisible sur un fond donné, et son contraste.
 *
 * ⚠ On rend AUSSI le contraste obtenu, pas seulement la couleur. Le meilleur des deux peut rester
 * sous le seuil — un gris moyen plafonne vers 3,9:1 — et l'appelant doit pouvoir refuser.
 */
export function encreSur(fond) {
  const surNoir = contraste(fond, '#000000')
  const surBlanc = contraste(fond, '#ffffff')
  if (surNoir === null || surBlanc === null) return null
  return surNoir >= surBlanc
    ? { encre: '#000000', contraste: surNoir }
    : { encre: '#ffffff', contraste: surBlanc }
}

function fondCalcule(nom, repli) {
  if (typeof window === 'undefined' || !document?.documentElement) return repli
  const v = getComputedStyle(document.documentElement).getPropertyValue(nom).trim()
  return luminance(v) === null ? repli : v
}

/**
 * Les variables CSS à poser sur la coque de la boutique, et ce qui a été refusé.
 *
 * Rend `{ variables, verdicts }`. `verdicts` sert à l'écran de configuration : un exploitant dont la
 * couleur est écartée doit l'apprendre là, sinon on remplace « ignoré en silence » par « ignoré en
 * silence une fois sur deux », ce qui est pire.
 */
export function jetonsDeVitrine(couleurs) {
  const variables = {}
  const verdicts = {}

  const bg = fondCalcule('--bg', '#ffffff')
  const panel = fondCalcule('--panel', '#ffffff')

  const primaire = couleurs?.primaire
  if (primaire) {
    const surBg = contraste(primaire, bg)
    const surPanel = contraste(primaire, panel)
    const encre = encreSur(primaire)
    if (surBg === null || surPanel === null || !encre) {
      verdicts.primaire = { applique: false, raison: 'couleur illisible' }
    } else if (surBg < SEUIL_TEXTE || surPanel < SEUIL_TEXTE) {
      // ⚠ REFUS PLUTÔT QU'APPLICATION PARTIELLE. `--accent` est employé neuf fois comme TEXTE dans
      // la boutique : l'appliquer quand même donnerait des liens et des titres illisibles.
      verdicts.primaire = {
        applique: false,
        raison: 'contraste insuffisant comme texte',
        mesure: Math.min(surBg, surPanel),
        seuil: SEUIL_TEXTE,
      }
    } else {
      variables['--accent'] = primaire
      variables['--sur-accent'] = encre.encre
      verdicts.primaire = { applique: true, mesure: Math.min(surBg, surPanel) }
    }
  }

  const secondaire = couleurs?.secondaire
  if (secondaire) {
    const encre = encreSur(secondaire)
    if (!encre) {
      verdicts.secondaire = { applique: false, raison: 'couleur illisible' }
    } else if (encre.contraste < SEUIL_TEXTE) {
      verdicts.secondaire = {
        applique: false,
        raison: 'aucune encre lisible sur cette couleur',
        mesure: encre.contraste,
        seuil: SEUIL_TEXTE,
      }
    } else {
      // Rôle de FOND uniquement (`.bq-carte-prix`), jamais de texte : c'est là que l'encre calculée
      // garantit la lisibilité quelle que soit la couleur choisie.
      variables['--bq-secondaire'] = secondaire
      variables['--bq-sur-secondaire'] = encre.encre
      verdicts.secondaire = { applique: true, mesure: encre.contraste }
    }
  }

  return { variables, verdicts }
}
