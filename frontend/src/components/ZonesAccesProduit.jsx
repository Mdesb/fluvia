import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

// « CE PRODUIT OUVRE TELLE ET TELLE ZONE » — LA RÈGLE QUI MANQUAIT AU CONTRÔLE D'ACCÈS.
//
// Jusqu'au 29/08, aucune des douze étapes de décision ne demandait si un droit ouvre CETTE zone :
// un billet de piscine ouvrait la porte de la salle de sport du même site. Le moteur sait le refuser
// depuis cette nuit (motif `zone_non_autorisee`) ; il fallait encore pouvoir le DÉCLARER, et c'est
// ici que ça se passe — sur le produit, pas sur le billet.
//
// POURQUOI SUR LE PRODUIT ET PAS SUR LE DROIT. Un droit d'accès naît d'une vente. Porter la règle
// sur lui obligerait à la poser billet par billet, après coup, à chaque vente — personne ne le
// ferait. Déclarée sur le produit, elle est recopiée sur chaque droit à sa projection : c'est cette
// copie que les lecteurs embarquent pour décider même hors ligne, c'est-à-dire précisément quand la
// règle compte.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// DEUX PHRASES QUE CET ÉCRAN DOIT DIRE, ET QU'UN TABLEAU VIDE NE DIRAIT PAS
//
// 1. AUCUNE LIGNE = OUVRE TOUT. C'est l'inverse de ce qu'un tableau vide suggère. Un exploitant qui
//    voit « aucune zone » conclut « ce produit n'ouvre rien » et se croit protégé, alors qu'il vient
//    de tout ouvrir. La liste vide porte donc sa phrase, en toutes lettres.
//
// 2. DÉCLARER UNE ZONE NE FERME RIEN AUX BILLETS DÉJÀ VENDUS. Leur copie date de leur émission et ne
//    bouge qu'à la re-projection. C'est délibéré côté serveur : recalculer les droits existants
//    refuserait du jour au lendemain des porteurs qui ont payé. Mais quelqu'un qui restreint un
//    produit croit avoir fermé la porte tout de suite — l'écran le détrompe au moment où il le fait,
//    pas trois semaines plus tard devant un client mécontent.
//
// TANT QUE LES OPÉRATIONS N'EXISTENT PAS, L'ÉCRAN LE DIT AU LIEU DE MONTRER UNE PANNE. Le serveur
// n'ouvre `/api/product_access_zones` que dans le même lot que cet écran ; entre les deux, un 404
// n'est pas une erreur d'exploitation, c'est un mécanisme pas encore branché — et ça ne se dit pas
// avec la même phrase.

export default function ZonesAccesProduit({ produitId, droits = [] }) {
  const [zones, setZones] = useState([])
  const [espaces, setEspaces] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [nonBranche, setNonBranche] = useState(false)
  const [choix, setChoix] = useState('')
  const [enCours, setEnCours] = useState(false)

  const peutLire = aLeDroit(droits, 'acces.lire')
  const peutGerer = aLeDroit(droits, 'acces.gerer')

  const charger = useCallback(async () => {
    if (!produitId || !peutLire) return
    setChargement(true)
    setErreur(null)
    // ⚠ DEUX LECTURES, DEUX DIAGNOSTICS — ET C'EST POUR ÇA QU'ELLES NE SONT PAS DANS LE MÊME `try`.
    //
    // Un `Promise.all` ne dit pas LAQUELLE a échoué. Un 404 venu de la liste des espaces se serait
    // donc affiché « la déclaration des zones n'est pas encore ouverte par le serveur » — une phrase
    // fausse, et rassurante, sur un écran dont tout l'intérêt est de ne pas rassurer à tort.
    // Chaque appel porte son propre échec.
    const [z, e] = await Promise.allSettled([api.zonesProduit(produitId), api.espacesAcces()])

    if (z.status === 'rejected') {
      // 404 = la ressource n'est pas encore exposée. 403 = ce compte n'a pas le droit d'en
      // connaître. Les deux se disent autrement qu'un « chargement impossible ».
      if (z.reason?.status === 404) setNonBranche(true)
      else setErreur(z.reason?.status === 403 ? 'Ce compte n’a pas le droit de lire les zones d’accès.' : z.reason?.message)
      setChargement(false)
      return
    }

    setNonBranche(false)
    setZones(membres(z.value))

    if (e.status === 'fulfilled') {
      setEspaces(membres(e.value))
    } else {
      // Les déclarations sont là, leurs noms non : sans cette phrase, chaque ligne afficherait
      // « zone hors de ce site » — ce qui accuserait la donnée d'un défaut de lecture.
      setEspaces([])
      setErreur('Les zones sont déclarées, mais leur liste n’a pas pu être lue : les noms manquent.')
    }
    setChargement(false)
  }, [produitId, peutLire])

  useEffect(() => {
    charger()
  }, [charger])

  if (!peutLire) return null

  async function ajouter(e) {
    e.preventDefault()
    if (!choix) return
    setEnCours(true)
    setErreur(null)
    try {
      // L'établissement n'est jamais dans le corps : il est estampillé par le serveur depuis
      // l'en-tête actif. L'y mettre serait proposer à un client de choisir son propre périmètre.
      await api.declarerZoneProduit({ productRef: produitId, space: `/api/espace_acces/${choix}` })
      setChoix('')
      await charger()
    } catch (err) {
      setErreur(err.message || "La zone n'a pas pu être déclarée.")
    } finally {
      setEnCours(false)
    }
  }

  async function retirer(zone) {
    setEnCours(true)
    setErreur(null)
    try {
      await api.retirerZoneProduit(zone.id)
      await charger()
    } catch (err) {
      setErreur(err.message || "La zone n'a pas pu être retirée.")
    } finally {
      setEnCours(false)
    }
  }

  // Une zone déjà déclarée ne se propose plus : la déclarer deux fois n'ajoute rien et le serveur
  // n'aurait aucune raison de le refuser.
  const dejaPrises = new Set(zones.map((z) => idDe(z.space)))
  const proposables = espaces.filter((e) => !dejaPrises.has(e.id))

  if (nonBranche) {
    return (
      <div className="hint" style={{ margin: 0 }}>
        La déclaration des zones n’est pas encore ouverte par le serveur : <strong>vous ne pouvez
        pas restreindre ce produit depuis cet écran</strong>. Ce qu’un billet ouvre reste alors décidé
        par le paramétrage d’accès du site.
      </div>
    )
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
      ) : zones.length === 0 ? (
        // ⚠ LA PHRASE LA PLUS IMPORTANTE DE CET ÉCRAN — ET ELLE A UNE DATE DE PÉREMPTION CONNUE.
        //
        // Elle disait : « Aucune restriction : ce produit ouvre toutes les zones. » C'est vrai
        // AUJOURD'HUI — `DroitAcces::ouvre()` rend `true` quand la collection d'espaces est vide.
        // Ça cesse de l'être dès qu'un adaptateur de projection (en cours, hors de `main` au 30/08)
        // ne projette plus que les produits déclarant une zone : un produit sans zone n'ouvrira
        // alors plus RIEN, l'exact contraire.
        //
        // Un exploitant aurait lu « ouvre toutes les zones », conclu qu'il n'avait rien à faire, et
        // son QR n'aurait ouvert aucune porte. C'est mot pour mot la panne signalée.
        //
        // ⚠ D'OÙ LA RÈGLE APPLIQUÉE ICI : **décrire le geste, jamais l'état du serveur.** « Déclarez
        // les zones que ce billet doit ouvrir » est vrai avant la bascule et après ; « ouvre toutes
        // les zones » n'est vrai que d'un côté, et rien ne relierait la phrase à ce qui l'annule.
        // On perd une information exacte ce matin pour ne pas poser un mensonge la semaine
        // prochaine — et c'est le bon échange, parce que personne ne repasse relire une phrase.
        <div className="empty" style={{ padding: 12 }}>
          <strong>Aucune zone déclarée.</strong>
          <div style={{ marginTop: 6 }}>
            Déclarez ci-dessous les zones que ce billet doit ouvrir. Tant qu’aucune n’est déclarée,
            ce que le billet ouvre dépend du paramétrage d’accès du site — ne le supposez pas :
            vendez-en un et présentez-le à un lecteur.
          </div>
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Zone ouverte</th>
                {peutGerer && <th />}
              </tr>
            </thead>
            <tbody>
              {zones.map((z) => {
                const id = idDe(z.space)
                const espace = espaces.find((e) => e.id === id)
                return (
                  <tr key={z.id}>
                    {/* `space` peut arriver en IRI nue : on croise par identifiant plutôt que de
                        lire un libellé qui n'y est peut-être pas. */}
                    <td className="nm">{espace ? espace.libelle : <span className="mut">zone hors de ce site</span>}</td>
                    {peutGerer && (
                      <td className="num">
                        <button className="btn ghost sm" type="button" disabled={enCours} onClick={() => retirer(z)}>
                          Retirer
                        </button>
                      </td>
                    )}
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {peutGerer && (
        <form onSubmit={ajouter} className="row" style={{ gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
          <select
            className="select"
            style={{ maxWidth: 280 }}
            value={choix}
            onChange={(e) => setChoix(e.target.value)}
            aria-label="Zone à ouvrir"
            disabled={proposables.length === 0}
          >
            <option value="">
              {proposables.length === 0 ? 'Toutes les zones sont déjà déclarées' : '— choisir une zone —'}
            </option>
            {proposables.map((e) => (
              <option key={e.id} value={e.id}>{e.libelle}</option>
            ))}
          </select>
          <button className="btn primary sm" type="submit" disabled={!choix || enCours}>
            Ouvrir cette zone
          </button>
        </form>
      )}

      {/* La limite honnête, dite au moment du geste et pas dans une note de bas de page. */}
      {zones.length > 0 && (
        <div className="hint">
          Cette règle s’applique aux titres <strong>émis à partir de maintenant</strong>. Les billets
          et cartes déjà vendus gardent les zones qu’ils avaient à leur émission : les restreindre
          d’un coup refuserait des porteurs qui ont payé.
        </div>
      )}
    </>
  )
}

// L'identifiant d'une relation, qu'elle arrive en objet ou en IRI nue.
function idDe(v) {
  if (!v) return null
  if (typeof v === 'object') return v.id || (v['@id'] ? v['@id'].split('/').pop() : null)
  return String(v).split('/').pop()
}
