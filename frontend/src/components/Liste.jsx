import { useEffect, useState, useCallback } from 'react'
import { membres } from '../api/client.js'

// Carte de consultation générique : charge une collection (fn -> promesse), gère les états
// chargement / erreur / vide, et rend un tableau à partir d'une spec de colonnes.
// Réutilise les classes existantes (.card, .tbl, .badge, .spinner, .empty, .banner).
//
// props:
//  - titre, sous (sous-titre optionnel)
//  - charger: () => Promise (collection Hydra ou tableau)
//  - colonnes: [{ cle, entete, num?, rendu?(row) }]
//  - vide: message quand 0 ligne
//  - cle: fonction d'extraction de clé de ligne (def: row.id)
//  - deps: dépendances de rechargement (def: [])
//  - actions: noeud optionnel dans l'en-tête
export default function Liste({
  titre,
  sous,
  charger,
  colonnes,
  vide = 'Aucun élément.',
  cle = (r) => r.id,
  deps = [],
  actions = null,
  transforme,
}) {
  const [rows, setRows] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [statut, setStatut] = useState(null)
  // Combien de lignes existent RÉELLEMENT côté serveur quand il en a rendu moins. `null` = tout est
  // là (ou le serveur ne le dit pas). Voir le bloc ci-dessous.
  const [totalReel, setTotalReel] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    setStatut(null)
    try {
      const res = await charger()
      let liste = membres(res)

      // TOUTES LES COLLECTIONS DE CETTE APPLICATION SONT COUPÉES À 30 LIGNES, ET RIEN NE LE DIT.
      //
      // Le serveur pagine : 100 lignes par défaut, jusqu'à 500 si l'écran en demande davantage
      // (`pagination_client_items_per_page` est actif depuis le 29/08). Une collection plus longue
      // arrive donc COUPÉE, avec le même code 200 et la même forme de réponse.
      //
      // ⚠ CE COMPTEUR NE DOIT PAS APPRENDRE CES NOMBRES PAR CŒUR. Ils ont déjà changé une fois :
      // du 28 au 29/08, le plafond est passé de 30 imposé à 100 négociable, et treize endroits du
      // frontal ont continué d'annoncer 30 — dont six bandeaux qui promettaient à l'exploitant des
      // données manquantes qui ne manquaient plus. La comparaison ci-dessous, elle, est restée
      // juste tout du long : elle CONSTATE au lieu de savoir.
      //
      // Conséquence : les ~350 `itemsPerPage: 100|200|500` de `api/client.js` sont DÉCORATIFS. Un
      // catalogue de 40 produits en affiche 30, un annuaire de 200 clients en affiche 30 — même
      // code 200, même forme de réponse, juste moins de lignes. C'est invisible tant que le jeu de
      // données de démonstration tient sous 30, ce qui est le cas de la préprod aujourd'hui.
      //
      // On ne peut pas corriger ça d'ici — c'est une décision de configuration serveur. Ce qu'on
      // peut faire, c'est refuser de rendre un tableau incomplet qui a l'air complet : la carte
      // affiche désormais « 30 sur 47 ». Une liste tronquée qui l'annonce reste utilisable ; une
      // liste tronquée qui se tait fait prendre une décision sur des données absentes.
      const total = res?.totalItems ?? res?.['hydra:totalItems']
      setTotalReel(typeof total === 'number' && membres(res).length < total ? total : null)

      if (transforme) liste = transforme(liste, res)
      setRows(liste)
    } catch (e) {
      setErreur(e.message || 'Chargement impossible.')
      setStatut(e.status || null)
      setRows([])
      // Sans cette remise à zéro, un rechargement en échec garderait le « 30 sur 47 » du chargement
      // précédent au-dessus d'un tableau vide.
      setTotalReel(null)
    } finally {
      setChargement(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)

  useEffect(() => {
    recharger()
  }, [recharger])

  return (
    <section className="card">
      <div className="card-h">
        <h3>{titre}</h3>
        {/* Le compte partiel est posé À CÔTÉ DU TITRE, pas en pied de tableau : on lit le titre
            avant de lire les lignes, et c'est avant de les lire qu'il faut savoir qu'il en manque. */}
        {totalReel !== null && (
          <span className="badge warn" title="Cette liste est plus longue que ce qui est affiché.">
            {rows.length} sur {totalReel}
          </span>
        )}
        {sous && <span className="sub">{sous}</span>}
        <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
          {actions}
          <button title="Actualiser" className="btn ghost sm" onClick={recharger} disabled={chargement}>↻</button>
        </div>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : erreur ? (
          <div
            className="banner"
            style={
              statut === 403
                ? { background: 'var(--warn-bg)', color: 'var(--warn)', margin: 0 }
                : undefined
            }
          >
            {statut === 403
              ? "Accès non autorisé pour ce compte sur cet établissement (droits insuffisants). Cette vue reste vide."
              : erreur}
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                {colonnes.map((c) => (
                  <th key={c.cle} className={c.num ? 'num' : undefined}>{c.entete}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={cle(r, i)}>
                  {colonnes.map((c) => (
                    <td key={c.cle} className={c.num ? 'num' : undefined}>
                      {c.rendu ? c.rendu(r) : afficher(r[c.cle])}
                    </td>
                  ))}
                </tr>
              ))}
              {rows.length === 0 && (
                <tr><td colSpan={colonnes.length} className="empty">{vide}</td></tr>
              )}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}

function afficher(v) {
  if (v == null || v === '') return '—'
  if (typeof v === 'boolean') return v ? 'oui' : 'non'
  if (typeof v === 'object') return v.libelle || v.nom || v.code || v.id || '—'
  return String(v)
}

// LA RELATION RENDUE EN IRI, RÉSOLUE CONTRE UNE LISTE DÉJÀ CHARGÉE.
//
// API Platform n'embarque une relation que si l'entité cible expose au moins une propriété dans le
// groupe de sérialisation courant ; sinon elle rend une IRI nue. Côté écran, `x.relation.propriete`
// vaut alors `undefined` — sans erreur, sans avertissement, avec une colonne vide qui se lit comme
// une donnée manquante.
//
// **Treize défauts de cette seule cause ont été confirmés le 28/08**, dans dix modules : le mandat
// d'une ligne de remise SEPA, le barème d'une retenue de caution, l'impayé d'une représentation,
// l'agent d'un ticket, l'assigné d'une tâche, le déposant d'un document, l'auteur d'une activité,
// le rôle d'un plafond, le fournisseur d'une commande et d'une facture, l'article d'une ligne de
// commande et d'un mouvement de stock, l'espace d'une alerte SOS.
//
// Chacun avait sa petite fonction de résolution recopiée. Celle-ci les remplace : on lui passe la
// relation telle que le serveur l'a rendue et la liste qu'on a déjà en main.
//
// Elle accepte les DEUX formes — objet embarqué ou IRI — pour que l'écran continue de marcher le
// jour où quelqu'un ajoute un `#[Groups]` côté serveur, sans qu'on ait à y revenir.
// ⚠ UN OBJET N'EST PAS FORCÉMENT UNE DONNÉE : IL PEUT ÊTRE UNE IRI DÉGUISÉE.
//
// La première version rendait l'objet tel quel dès que ce n'était pas une chaîne. Ça paraissait
// sûr, et c'était faux. API Platform embarque parfois un **talon** : `{ '@id', '@type', id }` et
// rien d'autre — quand la classe cible n'expose que son `id` dans le groupe courant. C'est un
// pointeur, pas un contenu.
//
// Constaté à l'écran le 28/08 : `PadelReservation.terrain` arrive ainsi. La résolution
// court-circuitait sur le talon, et le tableau des parties ouvertes continuait d'afficher un
// fragment d'UUID alors que la table voisine, elle, affichait le bon nom. **Une correction qui ne
// marche qu'à moitié est pire qu'une correction absente : elle donne l'impression d'être faite.**
//
// On considère donc qu'un objet ne portant que des champs d'identité doit être résolu comme une
// IRI — en retombant sur le talon si la liste ne contient pas mieux.
const CHAMPS_IDENTITE = new Set(['@id', '@type', 'id', 'code'])

export function resoudre(relation, liste) {
  if (!relation) return null

  if (typeof relation === 'object') {
    const porteAutreChose = Object.keys(relation).some((k) => !CHAMPS_IDENTITE.has(k))
    if (porteAutreChose) return relation
    // Talon : on tente la liste, et à défaut on rend le talon (il porte au moins l'identifiant).
    return trouver(relation.id || relation['@id'], liste) || relation
  }

  return trouver(relation, liste)
}

function trouver(reference, liste) {
  if (!reference) return null
  const id = String(reference).split('/').pop()
  return (liste || []).find((x) => x.id === id || x.code === id) || null
}

// « JE NE SAIS PAS » N'EST PAS « IL N'Y EN A PAS », ET L'ÉCRAN NE DOIT PAS CONFONDRE LES DEUX.
//
// `Utilisateur` n'expose aucune propriété aux groupes de sérialisation de cinq modules (CRM,
// Projets, DMS, Assistance, Cautions) : l'agent d'un ticket, l'assigné d'une tâche, le déposant d'un
// document reviennent en **IRI nue**, sans nom. Les écrans lisaient `u?.nom` et repliaient sur une
// phrase — et la phrase choisie AFFIRMAIT :
//
//     {nomUtilisateur(t.affecteA) || 'non affecté'}     ← la file d'assistance
//     {t.assignee?.nom || 'non assignée'}               ← les tâches d'un projet
//
// Un ticket bel et bien pris en charge s'annonçait donc « non affecté ». Deux agents le prennent, ou
// personne ne le prend en croyant qu'un autre s'en occupe. Ce n'est plus une colonne vide, c'est une
// information fausse — et c'est pire, parce qu'une colonne vide se remarque.
//
// La réponse de l'API permet pourtant de trancher sans rien deviner : **absent** (`null`) veut dire
// qu'il n'y a personne, **une chaîne** veut dire qu'il y a quelqu'un dont on ne peut pas lire le
// nom. Deux états différents, deux phrases différentes. Le jour où les `#[Groups]` manquants seront
// posés côté serveur, la branche « objet » prendra le dessus toute seule.
//
// `vide` est ce qu'on affiche quand il n'y a réellement personne — il change selon le contexte
// (« non affecté », « non assignée »), d'où le paramètre.
export function nomOuAbsence(utilisateur, vide = 'personne') {
  if (utilisateur === null || utilisateur === undefined || utilisateur === '') return vide
  if (typeof utilisateur === 'string') return 'affecté — nom non transmis'
  const complet = [utilisateur.prenom, utilisateur.nom].filter(Boolean).join(' ').trim()
  return complet || utilisateur.email || 'affecté — nom non transmis'
}

// Helpers partagés par les écrans de consultation.
export function euroCentimes(c) {
  if (c == null || c === '') return '—'
  const n = typeof c === 'number' ? c : parseInt(c, 10)
  if (Number.isNaN(n)) return '—'
  return (n / 100).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

export function dateFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

export function dateHeureFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR')
}

// Libellé multilingue { fr: ... } ou chaîne simple.
// La date d'un instant, dans le fuseau de celui qui regarde.
//
// POURQUOI CE N'EST PAS `toISOString().slice(0, 10)`.
//
// `toISOString()` rend de l'UTC. Un créneau à **00 h 30 heure de Paris en été** devient `22:30Z` la
// veille : il est rangé au mauvais jour. Trois endroits du front le faisaient — le groupement par
// journée des réservations, la comparaison d'échéance des options de groupe scolaire, et la date
// envoyée au serveur pour une réception d'achat.
//
// Le dernier est le plus coûteux : entre minuit et deux heures du matin, une réception était datée
// de la veille. Sur un mouvement de stock, c'est une date qui compte.
//
// `sv-SE` est utilisé parce que c'est la seule locale courante dont le format court est déjà
// `AAAA-MM-JJ` — on obtient la date locale sans reconstruire la chaîne à la main.
export function jourLocal(v) {
  const d = v ? new Date(v) : new Date()
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString('sv-SE')
}

export function texte(v, repli = '—') {
  if (!v) return repli
  if (typeof v === 'string') return v
  if (typeof v === 'object') return v.fr || Object.values(v)[0] || repli
  return String(v)
}
