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

export default function Autorisations({ droits = [], etabActif }) {
  const peutApprouver = aLeDroit(droits, 'autorisation.approuver')
  const peutGerer = aLeDroit(droits, 'autorisation.gerer')

  const [onglet, setOnglet] = useState('demandes')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Escalades &amp; plafonds</h1>
          <div className="sub">Ce qui dépasse un plafond, et qui peut le débloquer</div>
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
        <Plafonds peutGerer={peutGerer} etabActif={etabActif} />
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
    <div className="card">
      <div className="card-h">
        <span>Demandes d&rsquo;escalade</span>
        {enAttente > 0 && <span className="badge warn" style={{ marginLeft: 8 }}>{enAttente} en attente</span>}
        <button className="btn ghost sm" type="button" style={{ marginLeft: 'auto' }} onClick={recharger}>
          Actualiser
        </button>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

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

// LE RÔLE S’APPELLE `nom`, PAS `libelle` NI `code` — vérifié contre la réponse, pas supposé.
// Le code existant lisait `role.libelle || role.code` : les deux valent `undefined` sur l’entité
// `Role`, qui n’expose que `nom` et `permissions`. La colonne « s’applique à » repliait donc sur
// « tout le monde » pour un plafond qui vise pourtant un rôle précis — la même famille de défaut
// que « non affecté » dans l’assistance : un repli qui affirme au lieu de constater.
function nomRole(role) {
  if (!role || typeof role === 'string') return null
  return role.nom || role.libelle || role.code || null
}

// Qui pourra faire l'opération après la levée, verbe accordé compris.
function qui(limite, roles) {
  const nom = nomRole(resoudreRole(limite.role, roles))
  if (nom) return `les titulaires du rôle « ${nom} » pourront`
  if (limite.role) return 'les titulaires du rôle visé par ce plafond pourront'
  return 'tout le personnel pourra'
}

// Le rôle d'un plafond revient en IRI : on le retrouve dans la liste des rôles déjà chargée. Même
// geste que pour le mandat d'une ligne de remise SEPA ou le barème d'une retenue de caution — trois
// écrans, trois relations, une seule cause.
function resoudreRole(role, roles) {
  if (!role) return null
  if (typeof role === 'object') return role
  const id = String(role).split('/').pop()
  return roles.find((r) => r.id === id) || null
}

function Plafonds({ peutGerer, etabActif }) {
  const [limites, setLimites] = useState([])
  const [roles, setRoles] = useState([])
  const [editee, setEditee] = useState(null)
  const [suppression, setSuppression] = useState(null)
  const [operations, setOperations] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  // Distinguer << il n'y en a pas >> de << je n'ai pas pu lire >>.
  const [operationsIllisibles, setOperationsIllisibles] = useState(false)
  const [rolesIllisibles, setRolesIllisibles] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // AVALER UN ECHEC ET AFFICHER << AUCUN >> SONT DEUX CHOSES DIFFERENTES.
      //
      // Les trois lectures etaient rattrapees en `null`, puis rendues en liste vide. Sur l'ecran qui
      // repond a << qui peut depasser quoi >>, une panne de lecture s'affichait donc comme
      // << aucune operation declaree sensible >> -- c'est-a-dire comme une reponse rassurante.
      //
      // La distinction n'est pas << avaler ou pas >>, c'est CONFORT ou SUBSTANCE. Un fond de
      // calendrier qui manque, on s'en passe. Un catalogue d'operations sensibles qui manque, on ne
      // le presente pas comme vide.
      const [l, o, r] = await Promise.allSettled([
        api.limitesAutorisation(),
        api.operationsSensibles(),
        api.roles(),
      ])
      if (l.status === 'rejected') throw l.reason
      setLimites(membres(l.value))
      setOperations(o.status === 'fulfilled' ? membres(o.value) : [])
      setRoles(r.status === 'fulfilled' ? membres(r.value) : [])
      setOperationsIllisibles(o.status === 'rejected')
      setRolesIllisibles(r.status === 'rejected')
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
      <div className="card">
        <div className="card-h">
          <span>Plafonds en vigueur</span>
          {peutGerer && (
            <div className="r">
              <button
                className="btn primary sm"
                type="button"
                onClick={() => setEditee({})}
              >
                + Nouveau plafond
              </button>
            </div>
          )}
        </div>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {/* Sans les rôles, la colonne « s'applique à » ne peut pas nommer sa cible : elle affiche
            « un rôle précis (nom non transmis) », ce qui se lit comme un défaut de données alors
            que c'est une lecture qui a échoué. */}
        {rolesIllisibles && (
          <div className="banner banner-warn">
            La liste des rôles n’a pas pu être lue : les plafonds ci-dessous ne pourront pas nommer le
            rôle qu’ils visent, et un nouveau plafond ne pourra pas en désigner un.
          </div>
        )}
        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : limites.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 24 }}>
            Aucun plafond défini : les opérations sensibles ne sont limitées que par les permissions.
            {peutGerer && ' Un plafond ajoute une limite de montant par-dessus le droit — et permet de demander l’accord d’un responsable au lieu de refuser.'}
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
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {limites.map((l) => (
                  <tr key={l.id}>
                    <td><span className="nm">{l.operation?.libelle || l.operation?.code || '—'}</span></td>
                    <td>
                      {/* « TOUT LE MONDE » EST UNE AFFIRMATION, ET ELLE ÉTAIT FAUSSE.
                          Constaté en créant un plafond depuis cet écran : le rôle est bien
                          enregistré côté serveur, mais `Role` n'expose rien dans le groupe
                          `limite:read` — il revient en IRI nue. Le repli annonçait donc « tout le
                          monde » pour un plafond qui ne visait qu'un rôle.
                          Sur une règle qui REFUSE des opérations, c'est le pire des malentendus :
                          on lève un plafond en croyant débloquer tout le personnel. On résout donc
                          l'IRI contre la liste des rôles déjà chargée, et on ne dit « tout le
                          monde » que si le champ est réellement vide. */}
                      {/* Et « tout le monde » ne peut pas exister non plus DANS CE TABLEAU : le
                          serveur refuse une limite sans cible (RG-AUTZ-02). Une ligne sans rôle ni
                          utilisateur ne veut donc pas dire « tout le personnel », elle veut dire
                          que la cible n'est pas arrivée jusqu'ici. On dit ça, pas l'inverse. */}
                      {nomUtilisateur(l.utilisateur)
                        || nomRole(resoudreRole(l.role, roles))
                        || (l.role
                          ? <span className="sub">un rôle précis (nom non transmis)</span>
                          : <span className="sub">cible non transmise — à signaler</span>)}
                    </td>
                    <td>{PERIMETRES[l.perimetre] || l.perimetre}</td>
                    <td className="num">{l.plafondMontant != null ? euros(l.plafondMontant) : <span className="sub">aucun</span>}</td>
                    <td className="num">{l.cumulJournalierMax != null ? euros(l.cumulJournalierMax) : <span className="sub">aucun</span>}</td>
                    <td>
                      <span className={`badge ${l.escaladeAuDela ? 'warn' : 'mut'}`}>
                        {l.escaladeAuDela ? 'demande une escalade' : 'refus direct'}
                      </span>
                    </td>
                    {peutGerer && (
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          <button className="btn ghost sm" type="button" onClick={() => setEditee(l)}>
                            Modifier
                          </button>
                          {/* SUPPRIMER UN PLAFOND N'EST PAS RANGER UNE LIGNE : c'est lever une
                              limite. On le confirme par une modale qui dit ce que ça libère, plutôt
                              que par un `confirm()` du navigateur qui ne dit rien. */}
                          <button className="btn ghost sm" type="button" onClick={() => setSuppression(l)}>
                            Lever
                          </button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* « CONTRÔLÉE » ÉTAIT FAUX, ET L'ÉCRAN SE CONTREDISAIT À DEUX BLOCS D'INTERVALLE.
          Le bloc au-dessus disait « aucun plafond défini : les opérations sensibles ne sont limitées
          que par les permissions », et celui-ci badgeait les sept opérations « contrôlée ».
          Les deux phrases ne peuvent pas être vraies ensemble. Relevé par la session en revue avec
          Maxime, constaté à l'écran.

          C'est la seconde qui mentait. `OperationSensible.active` ne veut pas dire « contrôlée » : il
          veut dire « inscrite au catalogue des opérations sensibles ». `ServiceAutorisation::evaluer`
          est explicite — si aucune `LimiteAutorisation` ne la vise, retour IMMÉDIAT « autorisé »,
          sans écriture, « comportement binaire inchangé ». Une opération au catalogue et sans
          plafond n'est donc contrôlée par rien de plus qu'avant.
          L'état se lit maintenant en croisant les deux listes, ce qui est la seule façon de le dire
          juste. */}
      <div className="card">
        <div className="card-h">
          <span>Opérations sensibles</span>
          <span className="sub">ce qui peut recevoir un plafond</span>
        </div>
        {operationsIllisibles ? (
          <div className="banner banner-error">
            Le catalogue des opérations sensibles n’a pas pu être lu. Ce tableau est vide parce que la
            lecture a échoué, <b>pas</b> parce qu’aucune opération n’est surveillée : n’en concluez
            rien sur ce qui est plafonné.
          </div>
        ) : operations.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 20 }}>
            Aucune opération déclarée sensible.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Opération</th>
                  <th>Droit qui la protège déjà</th>
                  <th>Limitée par un plafond&nbsp;?</th>
                </tr>
              </thead>
              <tbody>
                {operations.map((o) => {
                  const nb = limites.filter((l) => (l.operation?.code || l.operation) === o.code).length
                  return (
                    <tr key={o.code}>
                      <td>
                        <span className="nm">{o.libelle}</span>
                        <div className="sub mono">{o.code}</div>
                      </td>
                      {/* Les deux codes se ressemblent et ne disent pas la même chose :
                          `vente.remise_exceptionnelle` est la clé de l'opération sensible,
                          `vente.forcer_prix` est la permission qui la garde déjà. Côte à côte sans
                          en-tête explicite, on cherche en vain la correspondance — il n'y en a pas
                          à trouver, ce sont deux référentiels distincts. */}
                      <td className="sub mono">{o.moduleAction}</td>
                      <td>
                        {!o.active ? (
                          <span className="badge mut" title="Retirée du catalogue : aucun plafond ne peut la viser.">
                            hors catalogue
                          </span>
                        ) : nb > 0 ? (
                          <span className="badge good" title="Un plafond au moins la vise : au-delà, le serveur refuse ou demande une escalade.">
                            {nb} plafond{nb > 1 ? 's' : ''}
                          </span>
                        ) : (
                          <span className="badge mut" title="Aucun plafond ne la vise : le serveur autorise dès que la permission est accordée, quel que soit le montant.">
                            aucun plafond
                          </span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

      </div>

      <PlafondModal
        limite={editee}
        etabActif={etabActif}
        operations={operations}
        roles={roles}
        onClose={() => setEditee(null)}
        onFait={() => { setEditee(null); recharger() }}
        onErreur={setErreur}
      />

      <LeverPlafondModal
        limite={suppression}
        roles={roles}
        onClose={() => setSuppression(null)}
        onFait={() => { setSuppression(null); recharger() }}
        onErreur={setErreur}
      />
    </div>
  )
}

// LE PLAFOND SE RÈGLE ICI, ET CHAQUE CHAMP DIT CE QU'IL COÛTE À CELUI QUI EST AU GUICHET.
//
// Un plafond n'est pas un paramètre : c'est ce qui refuse une remise, un remboursement ou une
// annulation à un caissier devant son client. Le formulaire est donc écrit du point de vue de la
// personne bloquée, pas de celui qui règle.
function PlafondModal({ limite, etabActif, operations, roles, onClose, onFait, onErreur }) {
  const edition = limite && limite.id
  const [operation, setOperation] = useState('')
  const [role, setRole] = useState('')
  const [perimetre, setPerimetre] = useState('propre_etablissement')
  const [plafond, setPlafond] = useState('')
  const [cumul, setCumul] = useState('')
  const [escalade, setEscalade] = useState(false)
  const [enCours, setEnCours] = useState(false)
  const [erreurModale, setErreurModale] = useState(null)

  useEffect(() => {
    if (!limite) return
    setErreurModale(null)
    setOperation(iriOuVide(limite.operation, operations))
    setRole(iriOuVide(limite.role, roles))
    setPerimetre(limite.perimetre || 'propre_etablissement')
    setPlafond(limite.plafondMontant != null ? String(limite.plafondMontant) : '')
    setCumul(limite.cumulJournalierMax != null ? String(limite.cumulJournalierMax) : '')
    setEscalade(Boolean(limite.escaladeAuDela))
  }, [limite, operations, roles])

  async function envoyer(e) {
    e.preventDefault()
    setErreurModale(null)
    setEnCours(true)
    try {
      const corps = {
        operation: operation || null,
        role: role || null,
        perimetre,
        // Un plafond vide n'est pas zéro : c'est « pas de limite sur ce montant ». Envoyer `0`
        // interdirait toute opération, ce qui est l'inverse de ce que l'utilisateur a laissé vide.
        plafondMontant: plafond.trim() === '' ? null : plafond.trim(),
        cumulJournalierMax: cumul.trim() === '' ? null : cumul.trim(),
        escaladeAuDela: escalade,
      }
      if (edition) {
        await api.majLimiteAutorisation(limite.id, corps)
      } else {
        // L ETABLISSEMENT EST OBLIGATOIRE, ET RIEN NE LE DISAIT AVANT L ENVOI.
        // Sans lui, le serveur refuse en 422 ("etablissement: This value should not be null")
        // -- constate en creant un plafond depuis l ecran, pas en lisant l entite. Un plafond
        // vaut pour UN site : on pose donc l etablissement actif, celui que la barre du haut
        // affiche au moment ou l on regle.
        await api.creerLimiteAutorisation({ ...corps, etablissement: `/api/etablissements/${etabActif}` })
      }
      onFait()
    } catch (err) {
      // Le message du serveur atterrissait dans le bandeau de la CARTE, sous la fenêtre restée
      // ouverte. Constaté en enregistrant un plafond sans cible : le serveur refuse en 422 avec
      // exactement la phrase qui débloque, et elle s'écrivait derrière la modale.
      setErreurModale(err.message || "Le plafond n'a pas pu être enregistré.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal
      open={!!limite}
      onClose={onClose}
      titre={edition ? 'Modifier un plafond' : 'Nouveau plafond'}
    >
      <form onSubmit={envoyer}>
        {erreurModale && <div className="banner banner-error">{erreurModale}</div>}

        <div className="field">
          <label htmlFor="pl-op">Opération concernée *</label>
          <select id="pl-op" className="input" required value={operation} onChange={(e) => setOperation(e.target.value)}>
            <option value="">Choisir…</option>
            {operations.map((o) => (
              <option key={o.code} value={o['@id'] || o.code}>{o.libelle || o.code}</option>
            ))}
          </select>
          <div className="hint">
            Seules les opérations déclarées sensibles peuvent être plafonnées — la liste est celle du
            tableau du dessous.
          </div>
        </div>

        <div className="field">
          {/* « TOUT LE MONDE » ÉTAIT L'OPTION PAR DÉFAUT, ET LE SERVEUR LA REFUSE TOUJOURS.
              `LimiteAutorisation` porte une contrainte d'expression : une limite vise un rôle OU un
              utilisateur, jamais les deux ni AUCUN (RG-AUTZ-02). Enregistrer sans cible répond donc
              en 422, systématiquement.
              Le formulaire proposait pourtant ce choix en premier, et son aide décrivait un
              comportement qui n'existe pas — « plafonne l'opération pour l'ensemble du personnel, y
              compris vous ». Constaté en enregistrant : c'est le seul chemin par défaut du
              formulaire, et il ne mène nulle part.
              Une option qu'aucun enregistrement ne peut accepter n'est pas une option. */}
          <label htmlFor="pl-role">Rôle plafonné *</label>
          <select
            id="pl-role"
            className="input"
            required
            value={role}
            onChange={(e) => setRole(e.target.value)}
          >
            <option value="">— choisir un rôle —</option>
            {roles.map((r) => (
              <option key={r.id} value={r['@id'] || `/api/roles/${r.id}`}>{nomRole(r)}</option>
            ))}
          </select>
          <div className="hint">
            Un plafond vise toujours quelqu&rsquo;un : un rôle, ou une personne précise. Pour
            plafonner l&rsquo;ensemble du personnel, posez la limite sur chacun des rôles concernés —
            c&rsquo;est ce que le serveur exige, et ça évite de bloquer un rôle qu&rsquo;on avait
            oublié dans le lot.
          </div>
        </div>

        <div className="field">
          <label htmlFor="pl-montant">Plafond par opération (€)</label>
          <input
            id="pl-montant"
            className="input"
            inputMode="decimal"
            placeholder="aucun"
            value={plafond}
            onChange={(e) => setPlafond(e.target.value)}
          />
          <div className="hint">Laisser vide pour ne pas limiter le montant d&rsquo;une opération isolée.</div>
        </div>

        <div className="field">
          <label htmlFor="pl-cumul">Cumul maximum par jour (€)</label>
          <input
            id="pl-cumul"
            className="input"
            inputMode="decimal"
            placeholder="aucun"
            value={cumul}
            onChange={(e) => setCumul(e.target.value)}
          />
          <div className="hint">
            Ce qui empêche de contourner le plafond en découpant une opération en plusieurs petites.
          </div>
        </div>

        <div className="field">
          <label htmlFor="pl-perimetre">Portée du cumul</label>
          <select id="pl-perimetre" className="input" value={perimetre} onChange={(e) => setPerimetre(e.target.value)}>
            <option value="propre_session">La session de caisse en cours</option>
            <option value="propre_etablissement">L&rsquo;établissement</option>
            <option value="global">Tous les établissements</option>
          </select>
        </div>

        <div className="field">
          <label>
            <input type="checkbox" checked={escalade} onChange={(e) => setEscalade(e.target.checked)} />{' '}
            Au-delà, demander une escalade plutôt que refuser
          </label>
          {/* LA DIFFÉRENCE ENTRE LES DEUX EST CELLE QUE LE CLIENT VOIT.
              Coché, le caissier peut demander l'accord d'un responsable et l'opération se fait dans
              la minute. Décoché, elle est refusée, point — et le client repart. */}
          <div className="hint">
            Coché : le caissier demande l&rsquo;accord d&rsquo;un responsable et l&rsquo;opération
            passe s&rsquo;il l&rsquo;accorde. Décoché : elle est refusée sur place, sans recours.
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours || !operation}>
            {enCours ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// Lever un plafond ne se confirme pas par un `window.confirm` : il faut dire CE QUI DEVIENT POSSIBLE.
function LeverPlafondModal({ limite, roles, onClose, onFait, onErreur }) {
  const [enCours, setEnCours] = useState(false)

  async function supprimer() {
    setEnCours(true)
    try {
      await api.supprimerLimiteAutorisation(limite.id)
      onFait()
    } catch (err) {
      onErreur(err.message || "Le plafond n'a pas pu être levé.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!limite} onClose={onClose} titre="Lever ce plafond" taille="sm">
      {limite && (
        <>
          <div className="banner banner-warn">
            {/* Le rôle est résolu ici aussi : annoncer « tout le personnel » avant de lever un
                plafond qui ne vise qu'un rôle ferait décider sur une portée fausse.
                Le verbe est porté par chaque branche — un sujet singulier et un sujet pluriel ne
                s'accordent pas pareil, et une phrase fautive sur un avertissement le décrédibilise. */}
            <b>L&rsquo;opération ne sera plus limitée.</b> Après cette levée,{' '}
            {qui(limite, roles)} effectuer{' '}
            « {limite.operation?.libelle || limite.operation?.code || 'cette opération'} » sans
            montant maximum et sans demander d&rsquo;accord.
          </div>
          <p className="hint" style={{ marginTop: 0 }}>
            Pour restreindre sans supprimer, modifiez le plafond plutôt que de le lever.
          </p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="button" disabled={enCours} onClick={supprimer}>
              {enCours ? 'Levée…' : 'Lever le plafond'}
            </button>
          </div>
        </>
      )}
    </Modal>
  )
}

// Une relation rendue en IRI ou embarquée : on rend l'IRI attendue par l'API dans les deux cas.
function iriOuVide(relation, liste) {
  if (!relation) return ''
  if (typeof relation === 'string') return relation
  if (relation['@id']) return relation['@id']
  const trouve = liste.find((x) => x.id === relation.id || x.code === relation.code)
  return trouve?.['@id'] || ''
}
