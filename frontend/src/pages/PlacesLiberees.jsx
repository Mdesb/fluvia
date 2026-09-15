import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { dateHeureFr } from '../components/Liste.jsx'
import { confirmer } from '../components/Confirmation.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri.js'
import { useVocabulaireVerticales } from '../api/vocabulaire-verticales.js'
import { useEtatUrl } from '../api/url.js'
import { activiteDuCreneau, ressourceDuCreneau } from '../api/slot-label.js'

// PLACES LIBÉRÉES — sept routes servies, aucun écran, et un mécanisme qui a tourné dans le vide.
//
// ── CE QUI SE PASSE SANS QUE PERSONNE PUISSE Y TOUCHER ──────────────────────────────────────────
//
// Quand une réservation est annulée ou passe en no-show, `App\SmartFlow` relit la disponibilité
// réelle du créneau, écrit une trace, puis publie `slot.released`. Un second abonné promeut alors
// la plus ancienne inscription en attente sur cette ressource et lui crée une PROPOSITION de report.
//
// Toute la chaîne est écrite et branchée. Il lui manquait ses deux bouts humains : personne ne
// pouvait INSCRIRE quelqu'un en liste d'attente, et personne ne pouvait ACCEPTER ou DÉCLINER une
// proposition.
//
// ⚠ MESURE DU 05/09, ET ELLE DIT EXACTEMENT LE COÛT : six libérations de créneau tracées
// (`smart_flow_slot_release_trace`), zéro inscription, zéro proposition. Témoin positif du
// comptage : 24 lignes dans `acces_passage`. Le mécanisme a donc cherché six fois à qui offrir une
// place, et n'a trouvé personne — faute de la seule porte qui remplit la liste.
//
// ── CETTE LISTE D'ATTENTE N'EST PAS CELLE DU CRÉNEAU, NI CELLE DE LA PATINOIRE ───────────────────
//
// Il en existe trois dans le produit, et les confondre ferait chercher les gens au mauvais endroit :
//
//   · `App\Reservation`  — par CRÉNEAU précis (« je veux celui de mardi 18 h »), promue de façon
//                          synchrone à l'annulation, avant même que l'événement n'atteigne le bus.
//   · `App\Patinoire`    — la file des patins, avec son propre écran.
//   · celle-ci           — par RESSOURCE et fenêtre de recherche (« n'importe quel créneau sur le
//                          court 2 »), servie quand une place s'y libère.
//
// ── CE QUE L'ÉCRAN NE PEUT PAS RÉPARER, ET QU'IL MONTRE PLUTÔT QUE DE LE TAIRE ───────────────────
//
// 1. UNE PROPOSITION EXPIRE EN 15 MINUTES — SUR LE PAPIER. C'est `slotWaitlistPromotionExpiration
//    Minutes` du manifeste, et la tâche `smart-flow:waitlist:expirer` est bien au catalogue
//    (toutes les 5 min). Mais la LISTE BLANCHE du lanceur (`infra/ordonnanceur.sh`) en autorise
//    dix, et celle-ci n'y est pas : mesuré aussi en base, `platform_scheduled_task_run` n'a aucune
//    ligne pour elle — jamais exécutée. Une proposition non traitée reste donc « proposée » au-delà
//    de son échéance, indéfiniment, et le suivant n'est jamais servi.
//
// 2. DÉCLINER PASSE LA MAIN DEPUIS LE 05/09 — et ne le faisait pas avant. La route se contentait
//    de clore la proposition : l'inscription restait « promue » et personne d'autre n'était servi,
//    donc un « non merci » bloquait la place pour toute la file. Elle consomme désormais
//    l'inscription (en « refusée », pas en « expirée » : ce n'est pas le même fait) et tente la
//    promotion suivante, exactement comme le fait l'expiration planifiée.
//
// ⚠ AUCUN DE CES DEUX CONSTATS N'EST ÉCRIT EN DUR DANS UN BANDEAU. Une phrase qui décrit un défaut
// devient un mensonge le jour où on le corrige, et rien ne relie les deux. L'écran COMPTE les
// propositions dont l'échéance est passée alors qu'elles sont encore « proposées » : le jour où la
// tâche entrera dans la liste blanche, le compte retombera à zéro et l'alerte disparaîtra seule.
//
// ── ET LA FENÊTRE DE RECHERCHE NE FILTRE RIEN ───────────────────────────────────────────────────
//
// `searchWindowStart`/`searchWindowEnd` sont obligatoires à l'inscription (422 sans, et fin > début
// exigé), stockées — puis JAMAIS LUES. Mesuré sur `src/` et `tests/` : leurs accesseurs n'ont aucun
// appelant, et `SlotWaitlistPromotionService` ordonne par rang sans jamais les consulter. La
// promotion peut donc proposer un créneau hors de la fenêtre demandée.
//
// Le formulaire les demande donc — le serveur les exige — mais ne promet pas ce qu'elles ne font
// pas. Un écran qui annoncerait « nous ne proposerons que dans cette plage » mentirait.

const STATUTS_PROPOSITION = {
  searching: ['Recherche en cours', 'mut'],
  proposed: ['Place proposée', 'warn'],
  confirmed: ['Acceptée', 'good'],
  expired: ['Close', 'mut'],
}

const STATUTS_INSCRIPTION = {
  waiting: ['En attente', 'warn'],
  promoted: ['Place proposée', 'good'],
  expired: ['Expirée', 'mut'],
  // « Refusée » et non « Annulée » : ce statut n'avait aucun écrivain avant le 05/09, et c'est
  // désormais le refus explicite d'une place proposée — à distinguer de « expirée », qui est
  // l'absence de réponse.
  cancelled: ['Refusée', 'mut'],
}

function badge(table, code) {
  const [libelle, classe] = table[code] || [code || '—', 'mut']
  return <span className={`badge ${classe}`}>{libelle}</span>
}

// Même libellé que l'écran Réservation, volontairement : un `Beneficiaire` ne porte pas de nom
// propre, seulement un rôle et le client dont il dépend. Inventer ici un libellé plus flatteur
// donnerait deux noms pour la même personne selon l'écran ouvert.
function labelBeneficiaire(b) {
  if (!b) return null
  const role = b.role ? b.role.charAt(0).toUpperCase() + b.role.slice(1) : 'Bénéficiaire'
  const client = idDe(b.client)
  return `${role} · client ${client ? client.slice(0, 8) : '?'}`
}

// `inscrire` : l'écran d'inscription en liste d'attente est ouvert.
const DEFAUTS_URL = { onglet: 'propositions', inscrire: '' }

export default function PlacesLiberees({ etabActif, droits }) {
  const peutGerer = aLeDroit(droits, 'smart_flow.reschedule_manage')

  // ⚠ L'ONGLET ENTRE DANS L'ADRESSE AVEC L'ÉCRAN D'INSCRIPTION : sans lui, « précédent » après un F5
  // ramènerait aux propositions, et non à la liste d'attente d'où l'on venait.
  const [params, majParams] = useEtatUrl('places_liberees', DEFAUTS_URL)
  const onglet = params.onglet
  const setOnglet = (v) => majParams({ onglet: v, inscrire: '' })
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [propositions, setPropositions] = useState([])
  const [inscriptions, setInscriptions] = useState([])
  const [ressources, setRessources] = useState([])
  const [creneaux, setCreneaux] = useState([])
  const [beneficiaires, setBeneficiaires] = useState([])

  const [aAccepter, setAAccepter] = useState(null)
  // ⚠ `null` = PAS ENCORE LU, `false` = LECTURE ÉCHOUÉE. Les listes retombent à [] sur un échec :
  // l'écran d'inscription ne propose pas deux sélecteurs vides comme s'il n'y avait rien à choisir.
  const [listesLues, setListesLues] = useState(null)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // Cinq lectures, et un seul refus rend l'écran faux : sans les ressources ou les créneaux, la
      // table n'affiche que des UUID. On échoue donc en bloc plutôt que de rendre une page à moitié
      // lisible qui ne se présente pas comme incomplète.
      const [pp, ii, rr, cc, bb] = await Promise.all([
        api.propositionsReport(),
        api.inscriptionsPlaceLiberee(),
        api.reservationRessources(),
        api.reservationCreneaux(),
        api.beneficiaires(),
      ])
      setPropositions(membres(pp))
      setInscriptions(membres(ii))
      setRessources(membres(rr))
      setCreneaux(membres(cc))
      setBeneficiaires(membres(bb))
      setListesLues(true)
    } catch (e) {
      // ⚠ PAS DE REPLI SUR DES LISTES VIDES. Un 403 rendu en « aucune proposition » ferait dire à
      // l'écran qu'il n'y a personne à servir, ce qu'il n'a pas mesuré.
      setPropositions([])
      setInscriptions([])
      setRessources([])
      setCreneaux([])
      setBeneficiaires([])
      setListesLues(false)
      setErreur(e?.message || 'Impossible de lire les places libérées.')
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  const parRessource = useMemo(() => {
    const m = {}
    for (const r of ressources) m[idDe(r)] = r.libelle || idDe(r)
    return m
  }, [ressources])

  const parCreneau = useMemo(() => {
    const m = {}
    for (const c of creneaux) m[idDe(c)] = c
    return m
  }, [creneaux])

  const parBeneficiaire = useMemo(() => {
    const m = {}
    for (const b of beneficiaires) m[idDe(b)] = b
    return m
  }, [beneficiaires])

  // ⚠ ON N'INVENTE PAS UN LIBELLÉ QUAND LA RÉFÉRENCE EST INTROUVABLE. Les collections de créneaux
  // et de bénéficiaires sont paginées : une proposition peut porter un identifiant hors de la page
  // lue. Afficher l'identifiant brut dit « je ne sais pas le nommer » ; afficher « — » dirait « il
  // n'y en a pas », ce qui est faux.
  function libelleCreneau(id) {
    if (!id) return null
    const c = parCreneau[String(id)]
    if (!c) return `créneau ${String(id).slice(0, 8)}…`
    // Activité et ressource embarquées dans le créneau (règles : api/slot-label.js). Une ressource
    // qu'on ne sait pas nommer se cherche dans la liste lue ; introuvable, elle se dit inconnue.
    const activite = activiteDuCreneau(c)
    const ressource = ressourceDuCreneau(c) ?? (parRessource[idDe(c.ressource)] || 'ressource inconnue')
    return [`${dateHeureFr(c.debut)} → ${dateHeureFr(c.fin)}`, activite, ressource].filter(Boolean).join(' · ')
  }

  function libelleClient(id) {
    if (!id) return '—'
    return labelBeneficiaire(parBeneficiaire[String(id)]) || `client ${String(id).slice(0, 8)}…`
  }

  // LE CONSTAT QUI REMPLACE L'AFFIRMATION « L'EXPIRATION NE TOURNE PAS ».
  //
  // Une proposition encore « proposée » alors que son échéance est passée n'a été traitée par
  // personne — ni par un exploitant, ni par la tâche planifiée. Le symptôme est vrai quelle que
  // soit la cause qu'on lui suppose, et il s'éteint tout seul le jour où la cause disparaît.
  const enSouffrance = useMemo(() => {
    const maintenant = Date.now()
    return propositions.filter(
      (p) => p.status === 'proposed' && p.expiresAt && new Date(p.expiresAt).getTime() < maintenant,
    ).length
  }, [propositions])

  async function decliner(proposition) {
    const ok = await confirmer(
      'Décliner cette proposition ?\n\n'
      + 'Elle sera close, et la place sera aussitôt proposée à la personne suivante sur la liste '
      + "d'attente de cette ressource — s'il y en a une.",
    )
    if (!ok) return
    setErreur(null)
    setSucces(null)
    try {
      await api.declinerPropositionReport(idDe(proposition))
      setSucces('Proposition déclinée.')
      await charger()
    } catch (e) {
      setErreur(e?.message || 'Le refus a échoué.')
    }
  }

  async function accepter(proposition, refReservation) {
    setErreur(null)
    setSucces(null)
    try {
      await api.accepterPropositionReport(idDe(proposition), refReservation)
      setSucces('Proposition acceptée : elle est rattachée à la réservation choisie.')
      setAAccepter(null)
      await charger()
    } catch (e) {
      setErreur(e?.message || "L'acceptation a échoué.")
    }
  }

  async function inscrire(corps) {
    setErreur(null)
    setSucces(null)
    try {
      await api.inscrirePlaceLiberee(corps)
      setSucces('Inscription enregistrée : la personne sera servie à la prochaine place libérée.')
      majParams({ inscrire: '' }, { pousser: true })
      await charger()
    } catch (e) {
      setErreur(e?.message || "L'inscription a échoué.")
    }
  }

  // ── INSCRIRE EN LISTE D'ATTENTE, EN ÉCRAN ───────────────────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LE DROIT DU BOUTON ET L'ÉCRAN LE REPREND. Et le formulaire choisit dans
  // les listes de la page : illisibles, elles rendraient deux sélecteurs vides qui se liraient
  // « il n'y a ni ressource ni personne ».
  if (params.inscrire) {
    const fermerInscription = () => majParams({ inscrire: '' }, { pousser: true })
    let contenu
    if (!peutGerer) {
      contenu = (
        <div className="banner banner-warn">
          Inscrire quelqu’un en liste d’attente demande le droit de gérer les reports, que ce compte n’a pas.
        </div>
      )
    } else if (chargement) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (listesLues === false) {
      contenu = (
        <div className="banner banner-error">
          Les ressources et les personnes n’ont pas pu être lues : on ne choisit pas dans une liste
          qu’on n’a pas. Ce n’est pas la même chose que « il n’y en a pas ».
        </div>
      )
    } else {
      contenu = (
        <>
          {erreur && <div className="banner banner-error">{erreur}</div>}
          <InscrireEnAttente
            ressources={ressources}
            beneficiaires={beneficiaires}
            onFermer={fermerInscription}
            onValider={inscrire}
          />
        </>
      )
    }
    return (
      <div className="view large">
        <button className="btn ghost sm" type="button" onClick={fermerInscription}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour aux places libérées
        </button>
        {contenu}
      </div>
    )
  }

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Places libérées</h1>
          <p>Qui attend une place, et à qui l&rsquo;on propose celles qui se libèrent</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {!chargement && enSouffrance > 0 && (
        <div className="banner banner-warn">
          <strong>
            {enSouffrance} proposition{enSouffrance > 1 ? 's ont' : ' a'} dépassé son échéance sans
            être traitée.
          </strong>{' '}
          Une place proposée qui reste sans réponse devrait revenir automatiquement à la personne
          suivante. Tant que ces lignes s&rsquo;accumulent, la file est bloquée sur elles : traitez-les
          à la main, en acceptant ou en déclinant.
        </div>
      )}

      <Tabs
        onglets={[
          ['propositions', `Propositions${propositions.length ? ` (${propositions.length})` : ''}`],
          ['attente', `Liste d’attente${inscriptions.length ? ` (${inscriptions.length})` : ''}`],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
      ) : onglet === 'attente' ? (
        <OngletAttente
          inscriptions={inscriptions}
          nomRessource={(id) => parRessource[String(id)] || `ressource ${String(id).slice(0, 8)}…`}
          libelleClient={libelleClient}
          peutGerer={peutGerer}
          onInscrire={() => { setErreur(null); setSucces(null); majParams({ inscrire: '1' }, { pousser: true }) }}
        />
      ) : (
        <OngletPropositions
          propositions={propositions}
          libelleCreneau={libelleCreneau}
          libelleClient={libelleClient}
          peutGerer={peutGerer}
          onAccepter={setAAccepter}
          onDecliner={decliner}
        />
      )}

      {aAccepter && (
        <AccepterProposition
          proposition={aAccepter}
          libelleCreneau={libelleCreneau}
          libelleClient={libelleClient}
          onFermer={() => setAAccepter(null)}
          onValider={accepter}
        />
      )}

    </div>
  )
}

/* --------------------------------------------------------- Propositions (la file avec une horloge) */

function OngletPropositions({ propositions, libelleCreneau, libelleClient, peutGerer, onAccepter, onDecliner }) {
  const maintenant = Date.now()

  return (
    <div className="card">
      <div className="card-h">
        <h3>Propositions de report</h3>
      </div>
      <div className="card-b">
        <p className="hint">
          Une proposition naît toute seule quand une place se libère sur une ressource où quelqu&rsquo;un
          attend. L&rsquo;accepter demande d&rsquo;avoir <strong>déjà créé la réservation</strong> :
          ce module ne réserve jamais à votre place.
        </p>

        {propositions.length === 0 ? (
          <p className="empty">
            Aucune proposition. Il n&rsquo;y en a que lorsqu&rsquo;une place se libère sur une
            ressource où quelqu&rsquo;un est inscrit en liste d&rsquo;attente.
          </p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Place proposée</th>
                  <th>Statut</th>
                  <th>Échéance</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {propositions.map((p) => {
                  const depassee = p.expiresAt && new Date(p.expiresAt).getTime() < maintenant
                  const traitable = p.status === 'proposed'
                  const declinable = p.status === 'proposed' || p.status === 'searching'
                  return (
                    <tr key={idDe(p)}>
                      <td>{libelleClient(p.customerId)}</td>
                      <td>
                        {p.proposedSlotId ? libelleCreneau(p.proposedSlotId) : (
                          <span className="sub">
                            aucune place trouvée pour l&rsquo;instant
                          </span>
                        )}
                      </td>
                      <td>{badge(STATUTS_PROPOSITION, p.status)}</td>
                      <td>
                        {dateHeureFr(p.expiresAt)}
                        {depassee && p.status === 'proposed' && (
                          <div className="sub">⚠ dépassée, et toujours ouverte</div>
                        )}
                      </td>
                      <td className="num">
                        {peutGerer ? (
                          <>
                            {traitable && (
                              <button type="button" className="btn primary" onClick={() => onAccepter(p)}>
                                Accepter
                              </button>
                            )}
                            {declinable && (
                              <button
                                type="button"
                                className="btn"
                                onClick={() => onDecliner(p)}
                                style={{ marginLeft: 'var(--esp-serre)' }}
                              >
                                Décliner
                              </button>
                            )}
                          </>
                        ) : (
                          <span className="sub">lecture seule</span>
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
    </div>
  )
}

function AccepterProposition({ proposition, libelleCreneau, libelleClient, onFermer, onValider }) {
  const [reservations, setReservations] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [choix, setChoix] = useState('')
  const [enCours, setEnCours] = useState(false)

  // Les réservations ne sont chargées QU'ICI : c'est une collection lourde dont la table principale
  // n'a aucun besoin. La payer à chaque ouverture de l'écran pour un geste occasionnel serait un
  // coût imposé à tout le monde pour le confort de quelques-uns.
  useEffect(() => {
    let vivant = true
    api.reservations()
      .then((r) => { if (vivant) setReservations(membres(r)) })
      .catch((e) => {
        if (!vivant) return
        // On distingue « je n'ai pas pu lire » de « il n'y en a pas » : sans ça, un refus de lecture
        // se lirait comme « ce client n'a aucune réservation », et l'exploitant en créerait une
        // seconde.
        setReservations([])
        setErreur(e?.message || 'Impossible de lire les réservations.')
      })
    return () => { vivant = false }
  }, [])

  // Le serveur ne vérifie QUE l'établissement et le client — pas le créneau (relu dans
  // `AcceptRescheduleProposalProcessor`). On propose donc toutes les réservations de ce client, et
  // on signale celles qui portent la place proposée plutôt que de masquer les autres : masquer
  // ferait disparaître un choix que le serveur, lui, accepterait.
  const candidates = useMemo(
    () => (reservations || []).filter((r) => idDe(r.organisateur) === String(proposition.customerId)),
    [reservations, proposition.customerId],
  )

  async function valider() {
    setEnCours(true)
    await onValider(proposition, choix)
    setEnCours(false)
  }

  return (
    <Modal open onClose={onFermer} titre="Accepter la proposition" taille="lg">
      <div className="deflist">
        <div><span>Client</span><span>{libelleClient(proposition.customerId)}</span></div>
        <div><span>Place proposée</span><span>{libelleCreneau(proposition.proposedSlotId)}</span></div>
        <div><span>Échéance</span><span>{dateHeureFr(proposition.expiresAt)}</span></div>
      </div>

      <div className="banner banner-info">
        <strong>Ce module ne crée jamais de réservation.</strong> Réservez la place par le chemin
        habituel, dans l&rsquo;écran Réservation, puis revenez ici désigner la réservation que vous
        venez de créer : c&rsquo;est ce geste qui clôt la proposition.
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      {reservations === null ? (
        <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
      ) : candidates.length === 0 ? (
        <p className="empty">
          Aucune réservation au nom de ce client. Créez-la d&rsquo;abord dans l&rsquo;écran
          Réservation, puis rouvrez cette fenêtre.
        </p>
      ) : (
        <div className="field">
          <label htmlFor="sf-resa">Réservation à rattacher</label>
          <select id="sf-resa" className="select" value={choix} onChange={(e) => setChoix(e.target.value)}>
            <option value="">Choisir…</option>
            {candidates.map((r) => {
              const surLaPlace = idDe(r.creneau) === String(proposition.proposedSlotId)
              return (
                <option key={idDe(r)} value={idDe(r)}>
                  {libelleCreneau(idDe(r.creneau))}{surLaPlace ? '  ✓ la place proposée' : ''}
                </option>
              )
            })}
          </select>
          <div className="hint">
            Le serveur refuse une réservation qui n&rsquo;appartient pas au même client ni au même
            établissement. Il ne vérifie pas, en revanche, qu&rsquo;elle porte bien la place
            proposée — c&rsquo;est à vous de la reconnaître.
          </div>
        </div>
      )}

      <div className="bar">
        <button type="button" className="btn" onClick={onFermer}>Annuler</button>
        <button
          type="button"
          className="btn primary"
          onClick={valider}
          disabled={enCours || !choix}
        >
          {enCours ? 'Validation…' : 'Accepter la proposition'}
        </button>
      </div>
    </Modal>
  )
}

/* ------------------------------------------------------------------ Liste d'attente (la porte d'entrée) */

function OngletAttente({ inscriptions, nomRessource, libelleClient, peutGerer, onInscrire }) {
  const { t } = useVocabulaireVerticales()
  return (
    <div className="card">
      <div className="card-h">
        <h3>Liste d&rsquo;attente par ressource</h3>
        {peutGerer && (
          <button type="button" className="btn primary" onClick={onInscrire}>
            ＋ Inscrire quelqu&rsquo;un
          </button>
        )}
      </div>
      <div className="card-b">
        <p className="hint">
          Cette liste porte sur une <strong>ressource entière</strong> — « n&rsquo;importe quel
          créneau sur ce court » — et non sur un créneau précis. Pour attendre un créneau donné,
          c&rsquo;est la liste d&rsquo;attente de l&rsquo;écran Réservation.
        </p>

        {inscriptions.length === 0 ? (
          <p className="empty">
            Personne n&rsquo;attend. Tant que cette liste est vide, une place qui se libère
            n&rsquo;est proposée à personne.
          </p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th className="num">Rang</th>
                  <th>{t('resource', null, 'Ressource')}</th>
                  <th>Personne</th>
                  <th>Fenêtre demandée</th>
                  <th>Statut</th>
                </tr>
              </thead>
              <tbody>
                {inscriptions.map((i) => (
                  <tr key={idDe(i)}>
                    <td className="num">{i.rank}</td>
                    <td>{nomRessource(i.resourceId)}</td>
                    <td>{libelleClient(i.beneficiaryId)}</td>
                    <td>
                      {dateHeureFr(i.searchWindowStart)} → {dateHeureFr(i.searchWindowEnd)}
                      {/* Dit une fois par ligne serait du bruit ; dit nulle part serait un piège.
                          C'est écrit sous le tableau, une fois. */}
                    </td>
                    <td>{badge(STATUTS_INSCRIPTION, i.status)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {inscriptions.length > 0 && (
          <p className="hint">
            La fenêtre demandée est <strong>enregistrée mais pas appliquée</strong> : la place
            revient à la personne la mieux classée sur la ressource, même si le créneau libéré tombe
            en dehors de sa fenêtre. Vérifiez que la place proposée lui convient avant de
            l&rsquo;accepter.
          </p>
        )}
      </div>
    </div>
  )
}

function InscrireEnAttente({ ressources, beneficiaires, onFermer, onValider }) {
  const { t } = useVocabulaireVerticales()
  // Quatorze jours : c'est ce que le module lui-même appelle une fenêtre de recherche
  // (`compatibleSlotSearchWindowDays` dans son manifeste). Un défaut arbitraire aurait fait
  // saisir deux dates à chaque inscription pour une valeur qui, aujourd'hui, ne sert à rien.
  const dansNJours = (n) => {
    const d = new Date()
    d.setDate(d.getDate() + n)
    d.setSeconds(0, 0)
    // `datetime-local` veut une heure LOCALE sans fuseau. `toISOString()` rendrait de l'UTC, et
    // décalerait la saisie de deux heures en été — le défaut que trois écrans ont déjà payé.
    const p = (v) => String(v).padStart(2, '0')
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
  }

  const [ressource, setRessource] = useState('')
  const [beneficiaire, setBeneficiaire] = useState('')
  const [debut, setDebut] = useState(() => dansNJours(0))
  const [fin, setFin] = useState(() => dansNJours(14))
  const [enCours, setEnCours] = useState(false)

  const finAvantDebut = debut && fin && new Date(fin) <= new Date(debut)

  async function valider() {
    setEnCours(true)
    await onValider({
      resourceId: ressource,
      beneficiaryId: beneficiaire,
      // Envoyées en ISO complet : le serveur les lit par `new \DateTimeImmutable()`, et une chaîne
      // `datetime-local` nue serait interprétée dans le fuseau du SERVEUR, pas dans celui de la
      // personne qui saisit.
      searchWindowStart: new Date(debut).toISOString(),
      searchWindowEnd: new Date(fin).toISOString(),
    })
    setEnCours(false)
  }

  return (
    <>
      <h2>Inscrire en liste d’attente</h2>
      <div className="banner banner-warn">
        <strong>Une inscription ne se retire pas.</strong> Aucune route ne permet de l&rsquo;annuler :
        elle reste en attente jusqu&rsquo;à ce qu&rsquo;une place lui soit proposée. Vérifiez la
        personne et la ressource avant de valider.
      </div>

      <div className="field">
        <label htmlFor="sf-res">{t('resource', null, 'Ressource')}</label>
        <select id="sf-res" className="select" value={ressource} onChange={(e) => setRessource(e.target.value)}>
          <option value="">Choisir…</option>
          {ressources.map((r) => (
            <option key={idDe(r)} value={idDe(r)}>{r.libelle || idDe(r)}</option>
          ))}
        </select>
        <div className="hint">
          La personne sera servie dès qu&rsquo;une place se libère sur cette ressource, quel que soit
          le créneau.
        </div>
      </div>

      <div className="field">
        <label htmlFor="sf-ben">Personne</label>
        <select id="sf-ben" className="select" value={beneficiaire} onChange={(e) => setBeneficiaire(e.target.value)}>
          <option value="">Choisir…</option>
          {beneficiaires.map((b) => (
            <option key={idDe(b)} value={idDe(b)}>{labelBeneficiaire(b)}</option>
          ))}
        </select>
      </div>

      <div className="row" style={{ gap: 'var(--esp-normal)', flexWrap: 'wrap' }}>
        <div className="field" style={{ flex: '1 1 14rem' }}>
          <label htmlFor="sf-deb">Recherche à partir du</label>
          <input
            id="sf-deb"
            className="input"
            type="datetime-local"
            value={debut}
            onChange={(e) => setDebut(e.target.value)}
          />
        </div>
        <div className="field" style={{ flex: '1 1 14rem' }}>
          <label htmlFor="sf-fin">Jusqu&rsquo;au</label>
          <input
            id="sf-fin"
            className="input"
            type="datetime-local"
            value={fin}
            onChange={(e) => setFin(e.target.value)}
          />
        </div>
      </div>

      <p className="hint">
        Ces deux dates sont exigées par le serveur et conservées sur l&rsquo;inscription, mais
        l&rsquo;attribution automatique ne les consulte pas : une place hors de cette plage peut
        être proposée. Elles servent à savoir ce que la personne avait demandé, pas à filtrer.
      </p>

      {finAvantDebut && (
        <div className="banner banner-error">
          La fin doit être postérieure au début — le serveur refuserait l&rsquo;inscription.
        </div>
      )}

      <div className="bar">
        <button type="button" className="btn" onClick={onFermer}>Annuler</button>
        <button
          type="button"
          className="btn primary"
          onClick={valider}
          disabled={enCours || !ressource || !beneficiaire || !debut || !fin || finAvantDebut}
        >
          {enCours ? 'Inscription…' : 'Inscrire'}
        </button>
      </div>
    </>
  )
}
