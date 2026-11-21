import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { useEtatUrl } from '../api/url.js'
import { filtrerAlertes, trierAlertes, libelleCause, libelleStatut, dateResolution } from '../api/nf525Alerts.js'
import Tabs from '../components/Tabs.jsx'

/**
 * ALERTES NF525 — la chaîne fiscale d'une caisse est bloquée : ce que l'exploitant a le droit de savoir.
 *
 * LECTURE SEULE, PAR CONSTRUCTION. Le serveur n'expose aucune écriture sur cette ressource (405) et
 * l'écran n'en propose aucune : ce qui bloque une chaîne scellée ne se répare pas depuis l'établissement,
 * l'éditeur est prévenu en même temps que vous et s'en charge. Le détail technique n'est pas servi
 * à cet écran (il n'est pas dans la réponse), seuls des libellés en clair le sont.
 *
 * TOUT S'AFFICHE EN TEXTE. `chainLabel` vient d'un nom saisi par le client : un nœud React texte
 * l'échappe, `dangerouslySetInnerHTML` ne l'aurait pas fait.
 *
 * TROIS ÉTATS QUI NE SE DISENT PAS PAREIL, comme la cloche : rien à signaler, pas encore branché
 * (404 : le serveur n'expose pas la ressource), lecture échouée (on ne sait pas). Une liste vide n'est
 * affichée comme « rien à signaler » que si la lecture a réussi.
 */

const DEFAUTS_URL = { filtre: 'open', alerte: '' }
const FILTRES = [['open', 'Ouvertes'], ['resolved', 'Résolues'], ['all', 'Toutes']]

function quand(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// Le serveur rend `causeLabel` en clair ; le libellé local ne sert que de repli.
const cause = (a) => (typeof a?.causeLabel === 'string' && a.causeLabel !== '' ? a.causeLabel : libelleCause(a?.cause))

// L'alerte venue de la cloche : le paramètre porte l'identifiant ou la référence.
const estVisee = (a, visee) => visee !== '' && (String(a?.id) === visee || a?.reference === visee)

export default function AlertesNf525({ etabActif }) {
  const [params, majParams] = useEtatUrl('alertes-nf525', DEFAUTS_URL)
  // `null` = PAS LU · `[]` = LU ET VIDE.
  const [alertes, setAlertes] = useState(null)
  const [total, setTotal] = useState(0)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [nonBranche, setNonBranche] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const reponse = await api.nf525Alertes()
      const liste = membres(reponse)
      setAlertes(liste)
      setTotal(reponse?.totalItems ?? reponse?.['hydra:totalItems'] ?? liste.length)
      setNonBranche(false)
    } catch (e) {
      setAlertes(null)
      if (e.status === 404) setNonBranche(true)
      else setErreur(e.message || 'Les alertes n’ont pas pu être chargées.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  const visee = params.alerte
  // Une alerte pointée par la cloche doit se voir : si le filtre la cache, on élargit à « Toutes ».
  useEffect(() => {
    if (!alertes || visee === '' || params.filtre === 'all') return
    const a = alertes.find((x) => estVisee(x, visee))
    if (a && filtrerAlertes([a], params.filtre).length === 0) majParams({ filtre: 'all' })
  }, [alertes, visee, params.filtre, majParams])

  const affichees = trierAlertes(filtrerAlertes(alertes, params.filtre))
  const partielle = total > (alertes?.length ?? 0)
  const ouvertes = (alertes ?? []).filter((a) => a?.status === 'open').length

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Alertes NF525</h1>
          <div className="sub">Chaîne fiscale bloquée : ce que l’éditeur traite pour vous</div>
        </div>
      </div>

      <div className="banner banner-info">
        L’éditeur est prévenu en même temps que vous : il n’y a rien à réparer de votre côté. Cet écran
        sert à suivre l’alerte, jusqu’à ce que la chaîne accepte de nouveau les ventes.
      </div>

      <Tabs
        onglets={FILTRES}
        actif={params.filtre}
        onChange={(v) => majParams({ filtre: v })}
      />

      <div className="card">
        <div className="card-h">
          <span>Alertes de scellement</span>
          <div className="r">
            {ouvertes > 0 && <span className="badge crit">{ouvertes === 1 ? '1 ouverte' : `${ouvertes} ouvertes`}</span>}
            <button className="btn ghost sm" type="button" onClick={recharger}>
              Actualiser
            </button>
          </div>
        </div>

        {chargement ? (
          <div className="center"><div className="spinner" /></div>
        ) : nonBranche ? (
          <div className="empty">
            Ce suivi n’est pas encore disponible sur ce serveur. Il n’y a rien à faire de votre côté.
          </div>
        ) : alertes === null ? (
          <div className="banner banner-error">
            {erreur ? `${erreur} ` : 'Les alertes n’ont pas pu être lues. '}
            <b>N’en concluez pas qu’aucune chaîne n’est bloquée</b>&nbsp;: cette liste n’a pas été obtenue.
          </div>
        ) : (
          <>
            {partielle && (
              <div className="banner banner-warn">
                Liste partielle : {alertes.length} alertes affichées sur {total}.
              </div>
            )}
            {affichees.length === 0 ? (
              <div className="empty">
                {partielle
                  ? 'Aucune alerte de ce type dans la partie lue de la liste : n’en concluez pas qu’il n’y en a pas.'
                  : params.filtre === 'resolved'
                    ? 'Aucune alerte résolue.'
                    : params.filtre === 'open'
                      ? 'Aucune alerte ouverte : toutes vos chaînes fiscales acceptent les ventes.'
                      : 'Aucune alerte de scellement.'}
              </div>
            ) : (
          <div className="table-scroll">
            <table className="tbl">
              <thead>
                <tr>
                  <th>Référence</th>
                  <th>Chaîne</th>
                  <th>Cause</th>
                  <th>Statut</th>
                  <th>Premier événement</th>
                  <th>Dernier événement</th>
                  <th>Résolue le</th>
                  <th className="num">Refus</th>
                </tr>
              </thead>
              <tbody>
                {affichees.map((a) => {
                  const surlignee = estVisee(a, visee)
                  return (
                    <tr
                      key={a.id ?? a.reference}
                      aria-current={surlignee ? 'true' : undefined}
                      className={surlignee ? 'row-visee' : undefined}
                    >
                      <td><span className="nm mono">{a.reference || '—'}</span></td>
                      <td>{a.chainLabel || '—'}</td>
                      <td>{cause(a)}</td>
                      <td>
                        <span className={`badge ${a.status === 'open' ? 'crit' : a.status === 'resolved' ? 'good' : 'mut'}`}>
                          {libelleStatut(a.status)}
                        </span>
                      </td>
                      <td>{quand(a.firstEventAt)}</td>
                      <td>{quand(a.lastEventAt)}</td>
                      <td>{quand(dateResolution(a))}</td>
                      <td className="num">{a.refusalCount ?? '—'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
