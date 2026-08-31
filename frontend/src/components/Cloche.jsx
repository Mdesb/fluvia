import { useCallback, useEffect, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import { allerA } from '../api/url.js'

// LA CLOCHE — « une petite cloche en haut à droite », demandée par Maxime.
//
// CE QU'ELLE REFUSE D'ÊTRE : UNE PASTILLE QUI DÉCORE.
//
// Une cloche est le seul élément d'interface qu'on regarde sans qu'on nous le demande. Elle a donc
// deux façons de mentir, et les deux coûtent cher :
//
//   — annoncer un nombre qui ne correspond pas à ce que la liste montre. Le compteur est donc pris
//     sur `totalItems` de la MÊME requête que la liste (`?lue=false`), jamais sur un point d'entrée
//     séparé : deux sources qui comptent la même chose finissent par diverger, et c'est la pastille
//     qu'on croira ;
//   — proposer une ligne qui ne mène nulle part. Une notification porte son écran de destination et
//     ses paramètres ; sans eux elle informe d'un problème qu'on ne peut pas aller traiter.
//
// TROIS ÉTATS QUI NE SE DISENT PAS PAREIL, comme partout ailleurs dans cette application :
// rien à signaler (la cloche est calme), pas encore branché (le serveur n'expose pas la ressource),
// et la lecture a échoué (on ne sait pas). Une cloche vide et une cloche aveugle se ressemblent
// beaucoup et n'appellent pas le même geste.
//
// LE BATTEMENT EST LENT, ET S'ARRÊTE QUAND PERSONNE NE REGARDE. Soixante secondes suffisent pour ce
// qui, par construction, est rare : la règle d'admission ne retient que les événements qui appellent
// le geste d'une personne. En pause onglet caché, et arrêté sur 401 — le jeton finit par expirer,
// et une cloche qui frappe dans le vide toutes les minutes ne signale plus rien.

const GRAVITE_CLS = { info: 'mut', attention: 'warn', critique: 'crit' }
const PERIODE_MS = 60000

function depuis(v) {
  if (!v) return ''
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return ''
  const s = Math.max(0, Math.round((Date.now() - d.getTime()) / 1000))
  if (s < 60) return `il y a ${s} s`
  if (s < 3600) return `il y a ${Math.round(s / 60)} min`
  if (s < 86400) return `il y a ${Math.round(s / 3600)} h`
  return `il y a ${Math.round(s / 86400)} j`
}

export default function Cloche({ etabActif }) {
  const [ouverte, setOuverte] = useState(false)
  const [lignes, setLignes] = useState([])
  const [total, setTotal] = useState(0)
  const [erreur, setErreur] = useState(null)
  const [nonBranchee, setNonBranchee] = useState(false)
  const [arretee, setArretee] = useState(false)
  const [enCours, setEnCours] = useState(false)
  const boite = useRef(null)

  const charger = useCallback(async () => {
    try {
      const reponse = await api.notifications({ lue: false })
      setLignes(membres(reponse))
      // LA MÊME REQUÊTE PORTE LA LISTE ET LE COMPTE. Voir l'en-tête : c'est ce qui interdit à la
      // pastille d'annoncer un nombre que la liste ne montre pas.
      setTotal(reponse?.totalItems ?? reponse?.['hydra:totalItems'] ?? membres(reponse).length)
      setErreur(null)
      setNonBranchee(false)
    } catch (e) {
      if (e.status === 404) setNonBranchee(true)
      else if (e.status === 401) setArretee(true)
      else setErreur(e.message || 'Notifications indisponibles.')
    }
  }, [])

  useEffect(() => {
    charger()
  }, [etabActif, charger])

  useEffect(() => {
    if (arretee || nonBranchee) return undefined
    const battre = () => {
      if (document.visibilityState === 'visible') charger()
    }
    const timer = setInterval(battre, PERIODE_MS)
    document.addEventListener('visibilitychange', battre)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', battre)
    }
  }, [arretee, nonBranchee, charger])

  // Fermer au clic extérieur : un panneau qui reste ouvert derrière l'écran suivant donne
  // l'impression que la cloche s'est bloquée.
  useEffect(() => {
    if (!ouverte) return undefined
    const dehors = (e) => {
      if (boite.current && !boite.current.contains(e.target)) setOuverte(false)
    }
    const echap = (e) => {
      if (e.key === 'Escape') setOuverte(false)
    }
    document.addEventListener('mousedown', dehors)
    document.addEventListener('keydown', echap)
    return () => {
      document.removeEventListener('mousedown', dehors)
      document.removeEventListener('keydown', echap)
    }
  }, [ouverte])

  async function ouvrirNotification(n) {
    setOuverte(false)
    try {
      await api.marquerNotificationLue(n.id)
    } catch {
      // Le marquage qui échoue ne doit pas retenir la navigation : ce que la personne veut, c'est
      // aller voir. La notification restera non lue, ce qui est le bon défaut — mieux vaut la
      // revoir une fois de trop que la perdre.
    }
    // `ecran` peut manquer sur une notification purement informative : on ne navigue alors nulle
    // part plutôt que d'emmener au hasard.
    if (n.ecran) allerA(n.ecran, n.params || {})
    charger()
  }

  // OUVRIR, C'EST DEMANDER — DONC C'EST RELIRE.
  //
  // Le battement se met en pause onglet caché, et c'est voulu : une cloche qui frappe dans le vide
  // toutes les minutes ne signale plus rien. Mais l'effet de bord se paie au moment le plus
  // trompeur — le panneau s'ouvrait sur la DERNIÈRE lecture visible. Sur soixante secondes c'est
  // sans conséquence ; sur un onglet resté caché deux heures, la cloche s'ouvrait sur deux heures de
  // retard SANS LE DIRE.
  //
  // Trouvé en me mesurant moi-même : le panneau montrait deux lignes et une pastille à 2 quand le
  // serveur en rendait trois, et j'ai failli conclure qu'une notification n'était pas arrivée. Un
  // composant qui économise le réseau devient un instrument périmé dès qu'on le consulte sans le
  // remonter — y compris pour celui qui l'a écrit.
  //
  // Pas de verrou de concurrence ici, à la différence du suivi des scans : cette lecture REMPLACE la
  // liste au lieu d'y ajouter, donc deux réponses dans le désordre affichent au pire une liste d'une
  // seconde trop vieille, que le battement suivant corrige. Ce n'est pas un oubli.
  function basculer() {
    if (!ouverte) charger()
    setOuverte((v) => !v)
  }

  async function toutMarquer() {
    setEnCours(true)
    try {
      await api.marquerToutesNotificationsLues()
      await charger()
    } catch (e) {
      setErreur(e.message || 'Le marquage n’a pas abouti.')
    } finally {
      setEnCours(false)
    }
  }

  // Non branchée : la cloche disparaît plutôt que d'afficher une panne permanente à côté de l'heure.
  // C'est le seul cas où se taire vaut mieux que dire — il n'y a rien à faire pour l'utilisateur, et
  // le message serait là à chaque écran, toute la journée.
  if (nonBranchee) return null

  return (
    <div ref={boite} style={{ position: 'relative' }}>
      <button
        type="button"
        className="btn ghost sm"
        onClick={basculer}
        aria-label={total > 0 ? `Notifications : ${total} non lue(s)` : 'Notifications'}
        title={arretee ? 'Session expirée : les notifications ne sont plus rafraîchies' : 'Notifications'}
      >
        ◔
        {total > 0 && (
          <span className="badge crit" style={{ marginLeft: 6 }}>{total > 99 ? '99+' : total}</span>
        )}
      </button>

      {ouverte && (
        <div
          className="card"
          style={{
            position: 'absolute',
            right: 0,
            top: 'calc(100% + 8px)',
            width: 360,
            maxWidth: 'calc(100vw - 24px)',
            maxHeight: '70vh',
            overflowY: 'auto',
            zIndex: 50,
            boxShadow: '0 8px 28px rgba(0,0,0,.28)',
          }}
        >
          <div className="card-h">
            <h3 style={{ fontSize: 14 }}>Notifications</h3>
            {lignes.length > 0 && (
              <div className="r" style={{ marginLeft: 'auto' }}>
                <button className="btn ghost sm" type="button" onClick={toutMarquer} disabled={enCours}>
                  Tout marquer comme lu
                </button>
              </div>
            )}
          </div>

          <div className="card-b" style={{ padding: 12 }}>
            {arretee && (
              <div className="banner banner-error" style={{ margin: '0 0 8px' }}>
                Votre session a expiré : cette liste n’est plus rafraîchie.
              </div>
            )}
            {erreur && <div className="banner banner-error" style={{ margin: '0 0 8px' }}>{erreur}</div>}

            {lignes.length === 0 && !erreur ? (
              <div className="hint" style={{ margin: 0 }}>
                Rien à signaler. Les notifications marquent ce qui appelle un geste de votre part —
                une facture échue, un prélèvement rejeté — et restent rares par construction.
              </div>
            ) : (
              lignes.map((n) => (
                <button
                  key={n.id}
                  type="button"
                  className="row"
                  onClick={() => ouvrirNotification(n)}
                  style={{
                    display: 'block',
                    width: '100%',
                    textAlign: 'left',
                    border: 'none',
                    background: 'none',
                    borderTop: '1px solid var(--line)',
                    padding: '8px 0',
                    cursor: n.ecran ? 'pointer' : 'default',
                    font: 'inherit',
                    color: 'inherit',
                  }}
                >
                  {/* L'HEURE NE SE LAISSE PAS COMPRIMER PAR LE TITRE.
                      Mesuré : sans `flexShrink: 0`, un titre de 53 caractères réduisait « il y a
                      12 min » de 59 px à 35, et un de 69 caractères à 27 — l'heure devenait illisible
                      au moment précis où la ligne devenait intéressante. Les titres s'allongent :
                      ceux du recouvrement portent désormais leur référence d'échéance. C'est au titre
                      de passer à la ligne, jamais à l'horodatage de disparaître. */}
                  <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                    <span className={`badge ${GRAVITE_CLS[n.gravite] || 'mut'}`} style={{ flexShrink: 0 }}>
                      {n.gravite}
                    </span>
                    <span className="nm" style={{ minWidth: 0 }}>{n.titre}</span>
                    <span
                      className="mut"
                      style={{ marginLeft: 'auto', flexShrink: 0, whiteSpace: 'nowrap' }}
                    >
                      {depuis(n.horodatage)}
                    </span>
                  </div>
                  {n.texte && <div className="mut">{n.texte}</div>}
                  {/* Une notification sans destination le dit, plutôt que de faire cliquer dans le
                      vide : le clic la marque lue et ne mène nulle part, c'est tout ce qu'elle peut. */}
                  {!n.ecran && <div className="hint" style={{ margin: 0 }}>Pour information — rien à ouvrir.</div>}
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}
