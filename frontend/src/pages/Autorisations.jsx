import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'

/**
 * AUTORISATIONS — les demandes qui bloquent quelqu'un, et les plafonds qui les déclenchent.
 *
 * **Pourquoi cet écran manquait, et ce que ça coûtait.** Le module expose treize opérations : plafonds
 * par rôle ou par personne, périmètre, cumul journalier, et une file de demandes d'escalade avec
 * approbation et rejet motivé. L'entrée de menu portait `absent: true`.
 *
 * Un module d'autorisation sans écran n'est pas une fonctionnalité en attente : c'est **un mécanisme
 * qui refuse et que personne ne peut débloquer**. Un caissier au-dessus de son plafond voit sa
 * demande partir, et elle n'arrive nulle part. Elle expire.
 *
 * **C'est pourquoi l'expiration est la première chose affichée.** `DemandeEscalade` porte une
 * `dateExpiration` : une demande n'est pas seulement en attente, elle a un compte à rebours. Trier par
 * date de demande sans montrer ce qui reste ferait manquer exactement celles qu'il fallait traiter —
 * et le refus qui en résulte ne ressemble pas à un oubli, il ressemble à une décision.
 *
 * > **Une file d'attente sans échéance visible se traite dans le désordre, et le désordre choisit
 * > toujours les plus vieilles pour les laisser mourir.**
 */

const STATUTS = {
  en_attente: { libelle: 'En attente', cls: 'warn' },
  approuvee: { libelle: 'Approuvée', cls: 'good' },
  rejetee: { libelle: 'Rejetée', cls: 'crit' },
  expiree: { libelle: 'Expirée', cls: 'mut' },
}

const PERIMETRES = {
  propre_session: 'Sa session',
  propre_etablissement: 'Son établissement',
  global: 'Tous les établissements',
}

function quand(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

/**
 * Ce qui reste avant expiration, dit en clair.
 *
 * On rend aussi la criticité : au-delà de la lisibilité, c'est elle qui décide de la couleur, donc de
 * ce que l'œil attrape en premier sur une file de vingt lignes.
 */
function restant(dateExpiration) {
  if (!dateExpiration) return { texte: '—', cls: 'mut' }
  const fin = new Date(dateExpiration).getTime()
  if (Number.isNaN(fin)) return { texte: '—', cls: 'mut' }
  const ms = fin - Date.now()
  if (ms <= 0) return { texte: 'expirée', cls: 'crit' }
  const minutes = Math.floor(ms / 60000)
  if (minutes < 60) return { texte: `${minutes} min`, cls: minutes <= 10 ? 'crit' : 'warn' }
  const heures = Math.floor(minutes / 60)
  if (heures < 24) return { texte: `${heures} h`, cls: heures <= 2 ? 'warn' : 'mut' }
  return { texte: `${Math.floor(heures / 24)} j`, cls: 'mut' }
}

function euros(v) {
  const n = typeof v === 'number' ? v : parseFloat(v ?? '')
  return Number.isNaN(n) ? '—' : n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

function nomUtilisateur(u) {
  if (!u || typeof u === 'string') return null
  return [u.prenom, u.nom].filter(Boolean).join(' ').trim() || u.email || null
}

export default function Autorisations({ droits = [] }) {
  const peutApprouver = aLeDroit(droits, 'autorisation.approuver')
  const peutGerer = aLeDroit(droits, 'autorisation.gerer')

  const [onglet, setOnglet] = useState('demandes')

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>Autorisations</h1>
          <div className="sub">Demandes d&rsquo;escalade et plafonds</div>
        </div>
      </div>

      <Tabs
        onglets={[['demandes', 'Demandes'], ['limites', 'Plafonds']]}
        actif={onglet}
        onChange={setOnglet}
      />

      {onglet === 'demandes' ? (
        <FileDemandes peutApprouver={peutApprouver} />
      ) : (
        <Plafonds peutGerer={peutGerer} />
      )}
    </div>
  )
}

function FileDemandes({ peutApprouver }) {
  const [demandes, setDemandes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [aRejeter, setARejeter] = useState(null)
  const [motif, setMotif] = useState('')
  const [busy, setBusy] = useState(false)
  // Un compte à rebours qui ne descend pas est un compte à rebours faux : on redessine chaque minute.
  const [, setTic] = useState(0)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setDemandes(membres(await api.demandesEscalade()))
    } catch (e) {
      setErreur(e.message || 'Les demandes n’ont pas pu être chargées.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger])

  useEffect(() => {
    const t = setInterval(() => setTic((n) => n + 1), 60000)
    return () => clearInterval(t)
  }, [])

  async function agir(action) {
    setBusy(true)
    setErreur(null)
    try {
      await action()
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  // Les demandes en attente d'abord, et parmi elles la plus proche de l'expiration. C'est le seul
  // ordre qui fasse traiter en premier ce qui va être perdu.
  const triees = [...demandes].sort((a, b) => {
    const aEnAttente = a.statut === 'en_attente' ? 0 : 1
    const bEnAttente = b.statut === 'en_attente' ? 0 : 1
    if (aEnAttente !== bEnAttente) return aEnAttente - bEnAttente
    return new Date(a.dateExpiration || 0) - new Date(b.dateExpiration || 0)
  })

  const enAttente = triees.filter((d) => d.statut === 'en_attente').length

  return (
    <div className="panel">
      <div className="panel-h">
        <span>Demandes d&rsquo;escalade</span>
        {enAttente > 0 && <span className="badge warn" style={{ marginLeft: 8 }}>{enAttente} en attente</span>}
        <button className="btn ghost sm" type="button" style={{ marginLeft: 'auto' }} onClick={recharger}>
          Actualiser
        </button>
      </div>

      {erreur && <div className="alert crit">{erreur}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : triees.length === 0 ? (
        <div className="sub" style={{ textAlign: 'center', padding: 24 }}>
          Aucune demande d&rsquo;escalade. Les opérations restent dans les plafonds en vigueur.
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Opération</th>
                <th>Demandeur</th>
                <th className="num">Montant</th>
                <th>Statut</th>
                <th className="num">Expire dans</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {triees.map((d) => {
                const r = restant(d.dateExpiration)
                const attente = d.statut === 'en_attente'
                return (
                  <tr key={d.id}>
                    <td>
                      <span className="nm">{d.operation?.libelle || d.operation?.code || '—'}</span>
                      <div className="sub">{quand(d.dateDemande)}</div>
                    </td>
                    <td>{nomUtilisateur(d.auteur) || '—'}</td>
                    <td className="num">{euros(d.montant)}</td>
                    <td>
                      <span className={`badge ${STATUTS[d.statut]?.cls || 'mut'}`}>
                        {STATUTS[d.statut]?.libelle || d.statut}
                      </span>
                    </td>
                    <td className="num">
                      {attente ? <span className={`badge ${r.cls}`}>{r.texte}</span> : <span className="sub">—</span>}
                    </td>
                    <td>
                      {/* Les boutons n'existent que pour une demande qu'on peut encore trancher :
                          approuver une demande expirée n'a pas de sens, et un bouton grisé ferait
                          chercher un droit manquant là où c'est le temps qui a manqué. */}
                      {peutApprouver && attente && r.texte !== 'expirée' && (
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          <button
                            className="btn primary sm"
                            type="button"
                            disabled={busy}
                            onClick={() => agir(() => api.approuverEscalade(d.id))}
                          >
                            Approuver
                          </button>
                          <button
                            className="btn ghost sm"
                            type="button"
                            disabled={busy}
                            onClick={() => {
                              setARejeter(d)
                              setMotif('')
                            }}
                          >
                            Rejeter
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={!!aRejeter} onClose={() => setARejeter(null)} titre="Rejeter la demande" taille="sm">
        <div style={{ display: 'grid', gap: 12 }}>
          <div className="sub">
            {/* Le motif part au demandeur : c'est la seule chose qu'il recevra. Sans lui, il
                recommence la même demande, et la file se remplit deux fois. */}
            Le motif est transmis au demandeur. C&rsquo;est ce qui lui évitera de refaire la même
            demande.
          </div>
          <textarea
            className="input"
            rows={3}
            placeholder="Motif du rejet…"
            value={motif}
            onChange={(e) => setMotif(e.target.value)}
          />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <button className="btn ghost" type="button" onClick={() => setARejeter(null)}>Annuler</button>
            <button
              className="btn"
              type="button"
              disabled={busy || motif.trim() === ''}
              onClick={() =>
                agir(async () => {
                  await api.rejeterEscalade(aRejeter.id, motif.trim())
                  setARejeter(null)
                })
              }
            >
              Rejeter
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}

function Plafonds({ peutGerer }) {
  const [limites, setLimites] = useState([])
  const [operations, setOperations] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [l, o] = await Promise.all([
        api.limitesAutorisation(),
        api.operationsSensibles().catch(() => null),
      ])
      setLimites(membres(l))
      setOperations(o ? membres(o) : [])
    } catch (e) {
      setErreur(e.message || 'Les plafonds n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger])

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div className="panel">
        <div className="panel-h"><span>Plafonds en vigueur</span></div>
        {erreur && <div className="alert crit">{erreur}</div>}
        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : limites.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 24 }}>
            Aucun plafond défini : les opérations sensibles ne sont limitées que par les permissions.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Opération</th>
                  <th>S&rsquo;applique à</th>
                  <th>Périmètre</th>
                  <th className="num">Plafond</th>
                  <th className="num">Cumul / jour</th>
                  <th>Au-delà</th>
                </tr>
              </thead>
              <tbody>
                {limites.map((l) => (
                  <tr key={l.id}>
                    <td><span className="nm">{l.operation?.libelle || l.operation?.code || '—'}</span></td>
                    <td>
                      {nomUtilisateur(l.utilisateur)
                        || l.role?.libelle
                        || l.role?.code
                        // Ni rôle ni personne : la limite vaut pour tout le monde. Le dire, plutôt
                        // que d'afficher un tiret qui se lit « non renseigné ».
                        || <span className="sub">tout le monde</span>}
                    </td>
                    <td>{PERIMETRES[l.perimetre] || l.perimetre}</td>
                    <td className="num">{l.plafondMontant != null ? euros(l.plafondMontant) : <span className="sub">aucun</span>}</td>
                    <td className="num">{l.cumulJournalierMax != null ? euros(l.cumulJournalierMax) : <span className="sub">aucun</span>}</td>
                    <td>
                      <span className={`badge ${l.escaladeAuDela ? 'warn' : 'mut'}`}>
                        {l.escaladeAuDela ? 'demande une escalade' : 'refus direct'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="panel">
        <div className="panel-h"><span>Opérations sensibles</span></div>
        {operations.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 20 }}>
            Aucune opération déclarée sensible.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Libellé</th>
                  <th>Action contrôlée</th>
                  <th>État</th>
                </tr>
              </thead>
              <tbody>
                {operations.map((o) => (
                  <tr key={o.code}>
                    <td><code>{o.code}</code></td>
                    <td>{o.libelle}</td>
                    <td className="sub">{o.moduleAction}</td>
                    <td>
                      <span className={`badge ${o.active ? 'good' : 'mut'}`}>
                        {o.active ? 'contrôlée' : 'non contrôlée'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {peutGerer && (
          <div className="hint">
            La création et la modification des plafonds passent encore par l&rsquo;API : cet écran les
            montre et traite les demandes, il ne les édite pas.
          </div>
        )}
      </div>
    </div>
  )
}
