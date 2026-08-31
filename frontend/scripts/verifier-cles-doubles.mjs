// GARDE-FOU n°37 — UNE CLE DEFINIE DEUX FOIS DANS LE MEME OBJET.
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
    const ouverture = /^export const ([A-Za-z_$][A-Za-z0-9_$]*) = \{\s*$/.exec(lignes[d])
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
      const m = /^ {2}([A-Za-z_$][A-Za-z0-9_$]*):/.exec(lignes[i])
      if (!m) continue
      if (vues.has(m[1])) {
        fautes.push(`${chemin} · ${ouverture[1]}.${m[1]} — lignes ${vues.get(m[1])} et ${i + 1}`)
      } else {
        vues.set(m[1], i + 1)
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
  console.log(`✓ Clés en double : aucune. ${clesLues} clé(s) lue(s) dans ${objetsLus} objet(s) exporté(s).`)
  process.exit(0)
}

console.log('✗ Clés définies deux fois dans le même objet — la DERNIÈRE gagne, en silence :')
for (const f of fautes) console.log('  - ' + f)
console.log('')
console.log('  C’est ce que produit une fusion Git sans conflit. L’appel perdu existe dans le code,')
console.log('  se lit, se relit en revue — et n’est jamais exécuté. Gardez la bonne définition et')
console.log('  supprimez l’autre ; si les deux diffèrent, c’est qu’il faut les fusionner à la main.')
process.exit(1)
