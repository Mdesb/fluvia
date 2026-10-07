// GARDE-FOU n°49 — UNE CLE DEFINIE DEUX FOIS DANS LE MEME OBJET.
//
// ⚠ IL PORTAIT LE n°37, DEJA PRIS PAR `bin/garde-fou-classes-fantomes.php`. Les deux ont ete
// crees le MEME JOUR — 31/08 — par deux sessions qui ne se sont pas vues, et les deux se
// declaraient « n°37 » dans leur propre en-tete. `bin/garde-fous.sh` ne labellisait que l'autre,
// donc c'est celui-ci qui bouge. Renumerote le 04/09.
//
// ── LE DEFAUT, ET POURQUOI AUCUN AUTRE CONTROLE NE LE VOIT ─────────────────────────────────────
//
// `{ a: 1, a: 2 }` est du JavaScript **legal**. La derniere definition gagne, la premiere est
// perdue, et rien ne le signale : ni le build, ni le linteur, ni un test qui n'appelle que l'une
// des deux.
//
// Ce n'est pas une faute de frappe : c'est ce que produit une **fusion Git sans conflit**. Deux
// sessions ajoutent chacune leur appel dans le meme objet `api`, a des endroits differents ; Git
// concatene les deux sans rien signaler, et l'un des deux appels cesse silencieusement d'exister.
// Quatre occurrences en deux jours sur `client.js` — `mappingsComptables`, et trois autres —
// rapportees par trois sessions distinctes.
//
// Le symptome est le pire qui soit : l'appel existe dans le code, il se lit, il se relit en revue,
// et il n'est jamais execute.
//
// ── ⚠ CE QUE CE CONTROLE NE FAIT PAS, ET C'EST DELIBERE ────────────────────────────────────────
//
// Il ne parse pas le JavaScript. Une premiere version suivait la profondeur d'accolades pour
// trouver les objets : elle signalait la meme cle « redefinie » sur des lignes consecutives — du
// bruit qui a l'air d'un resultat. **Elle a ete jetee.** Un controle qui signale des doublons qui
// n'en sont pas s'apprend a sauter, et le jour ou il en trouve un vrai, personne ne le lit.
//
// Il s'appuie donc sur le seul fait stable de ces fichiers : dans un `export const X = {` ouvert en
// colonne 0 et ferme par un `}` en colonne 0, une entree de premier niveau est une ligne indentee
// d'exactement DEUX espaces. Etroit et sur, plutot que large et faux.
//
// Corollaire assume : un objet ecrit autrement n'est pas couvert. Le controle annonce donc ce
// qu'il a REELLEMENT lu — fichiers, objets, nombre de cles — pour qu'un perimetre qui retrecit se
// voie, au lieu de rendre un vert qui ne mesure plus rien.
import { readFileSync, existsSync } from 'node:fs'

// Les fichiers ou plusieurs sessions ecrivent le meme objet. C'est la que la fusion frappe.
const FICHIERS = [
  'src/api/client.js',
  'src/public/api/boutiqueClient.js',
  // ⚠ Ajouté le 04/09 : `Icon.jsx` portait QUATRE clés en double que ce contrôle annonçait
  // absentes — `dashboard`, `personal-data`, `social` et `legal`. Une icône définie deux fois ne
  // casse rien : la seconde gagne, et le dessin qu'on croit voir n'est pas celui qui s'affiche.
  'src/components/Icon.jsx',
  // ⚠ Ajouté le 10/09 : `vocabulaire.js` portait TROIS clés en double que ce contrôle annonçait
  // absentes, parce qu'il ne lisait pas ce fichier. `draft` et `cloturee` étaient inoffensives (même
  // mot des deux côtés), mais `cancelled` valait « Annulé » puis « Annulée » : la seconde gagnait, et
  // l'écran des pièces commerciales affichait « Devis — Annulée ».
  //
  // C'est le fichier le plus exposé au défaut que ce contrôle décrit : une carte plate de 140 mots,
  // rangée par module, où chaque session ajoute sa section sans relire les autres. Deux sections
  // peuvent nommer le même code sans jamais se croiser dans un diff.
  'src/api/vocabulaire.js',
]

let objetsLus = 0
let clesLues = 0
const fautes = []
const ignores = []

for (const chemin of FICHIERS) {
  if (!existsSync(chemin)) {
    ignores.push(chemin)
    continue
  }

  const lignes = readFileSync(chemin, 'utf8').split('\n')

  for (let d = 0; d < lignes.length; d++) {
    // `export` facultatif : `Icon.jsx` garde sa table d'icônes privée, et une table privée se
    // fusionne aussi mal qu'une exportée.
    const ouverture = /^(?:export )?const ([A-Za-z_$][A-Za-z0-9_$]*) = \{\s*$/.exec(lignes[d])
    if (!ouverture) continue

    let f = -1
    for (let i = d + 1; i < lignes.length; i++) {
      if (/^\}/.test(lignes[i])) { f = i; break }
    }
    if (f < 0) {
      // Objet non terminé en colonne 0 : on ne devine pas, on le dit.
      ignores.push(`${chemin} · ${ouverture[1]} (fin introuvable)`)
      continue
    }

    const vues = new Map()
    for (let i = d + 1; i < f; i++) {
      // ⚠ LA CLÉ PEUT ÊTRE CITÉE, ET C'EST CE QUI CACHAIT LES QUATRE D'`Icon.jsx`.
      // Le fichier écrivait `'dashboard':` d'un côté et `dashboard:` de l'autre. JavaScript n'y
      // voit qu'une seule clé — l'ancien motif, lui, n'en voyait qu'une SUR DEUX, donc jamais de
      // collision. On lit les deux formes et on compare le NOM, pas son orthographe.
      const m = /^ {2}(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*)):/.exec(lignes[i])
      if (!m) continue
      const cle = m[1] ?? m[2] ?? m[3]
      if (vues.has(cle)) {
        fautes.push(`${chemin} · ${ouverture[1]}.${cle} — lignes ${vues.get(cle)} et ${i + 1}`)
      } else {
        vues.set(cle, i + 1)
        clesLues++
      }
    }
    objetsLus++
  }
}

if (ignores.length > 0) {
  console.log('· non lu(s) : ' + ignores.join(', '))
}

if (fautes.length === 0) {
  console.log(`✓ Clés en double : aucune. ${clesLues} clé(s) lue(s) dans ${objetsLus} objet(s), citées ou non.`)
  process.exit(0)
}

console.log('✗ Clés définies deux fois dans le même objet — la DERNIÈRE gagne, en silence :')
for (const f of fautes) console.log('  - ' + f)
console.log('')
console.log('  C’est ce que produit une fusion Git sans conflit. L’appel perdu existe dans le code,')
console.log('  se lit, se relit en revue — et n’est jamais exécuté. Gardez la bonne définition et')
console.log('  supprimez l’autre ; si les deux diffèrent, c’est qu’il faut les fusionner à la main.')
process.exit(1)
