import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri.js'

/**
 * LES CRÉNEAUX D'UN PRODUIT VENDU À L'HORAIRE — et le planning qui les porte.
 *
 * Demande de Maxime, 07/09, la fiche « Visite guidée (créneau) » sous les yeux : « ce produit
 * annonce des créneaux mais il n'y a aucun moyen de les paramétrer dans le catalogue. Il faut que ce
 * soit en ligne avec le planning de l'agenda. »
 *
 * ── CE QUE « EN LIGNE AVEC LE PLANNING » VEUT DIRE ICI, ET CE QUE ÇA INTERDIT ──────────────────
 *
 * Cet écran ne tient AUCUNE liste d'horaires qui lui soit propre. Ce qu'il montre et ce qu'il crée
 * sont des `Creneau` du module Réservation — les mêmes objets que l'agenda affiche, que la caisse
 * réserve et que la boutique vend. Une seconde liste « côté catalogue » aurait dérivé dès la
 * première annulation, et l'exploitant aurait cru le catalogue plutôt que son planning.
 *
 * ── LE RATTACHEMENT PASSE PAR L'ACTIVITÉ (arbitrage de Maxime, 07/09) ──────────────────────────
 *
 * `Activite.produitTarifReference` est la seule relation typée entre ce que le planning programme et
 * ce que le catalogue vend. Une même visite tenue dans deux salles est UNE activité et DEUX
 * ressources : le lien par la salle — la convention `champsPerso['ressourceId']` qui existait avant —
 * n'en aurait vendu qu'une, et c'est exactement ce qui rendait ce produit invendable (mesuré : neuf
 * créneaux à venir sur son activité, zéro sur la salle inscrite dans son champ perso).
 *
 * ── TROIS DROITS DISTINCTS, PARCE QUE CE SONT TROIS GESTES ────────────────────────────────────
 *
 * Lire le planning (`reservation.lire`), rattacher le produit à une activité
 * (`reservation.gerer_ressource` — c'est le planning qu'on modifie, pas le prix) et ouvrir des
 * créneaux (`reservation.gerer_creneau`). Un lecteur voit sans pouvoir agir ; ce qu'il ne peut pas
 * faire est ABSENT, jamais grisé — même règle que le reste de la fiche.
 *
 * ⚠ CE BLOC ÉCRIT IMMÉDIATEMENT, hors du cycle « Enregistrer / Annuler » de la fiche — comme les
 * produits complémentaires, et pour la même raison : une activité et un créneau sont des entités du
 * module Réservation, pas des champs du produit. Les faire passer par le formulaire ferait qu'un
 * créneau ouvert puis « annulé » resterait ouvert dans le planning de tout le monde.
 */

/** `null` = pas lu ; `[]` = lu et vide. La distinction porte tout l'écran. */
const MOTIFS = [
  ['hebdomadaire', 'Chaque semaine'],
  ['quotidien', 'Chaque jour'],
  ['mensuel', 'Chaque mois'],
]

function hhmm(iso) {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function jourLong(iso) {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
}

/**
 * UNE DATE ET UNE HEURE SAISIES ICI SONT DE L'HEURE LOCALE, ET LE SERVEUR VIT EN UTC.
 *
 * ⚠ MESURÉ, PAS SUPPOSÉ (07/09) : `date_default_timezone_get()` rend `UTC` sur la préprod, l'API
 * sérialise `2026-09-08T10:00:00+00:00`, et le navigateur l'affiche « 12:00 ». Envoyer la chaîne
 * `2026-09-08T10:00:00` telle quelle la ferait lire comme 10 h UTC — l'exploitant aurait saisi
 * 10:00 et relu 12:00 sur la ligne juste créée, sans que rien ne le prévienne.
 *
 * `new Date('…T10:00:00')` sans suffixe est interprété en heure LOCALE par le navigateur ; le
 * `toISOString()` qui suit rend l'instant absolu correspondant. L'aller-retour redonne alors
 * exactement l'heure saisie.
 */
function instant(date, heure) {
  const d = new Date(`${date}T${heure}`)
  return Number.isNaN(d.getTime()) ? null : d.toISOString()
}

/** Le jour d'aujourd'hui au format d'un `<input type="date">`, sans passer par UTC. */
function aujourdHui() {
  const d = new Date()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const j = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${j}`
}

const OUVERTURE_VIDE = {
  ressource: '',
  date: aujourdHui(),
  debut: '10:00',
  fin: '11:00',
  capacite: '',
  repeter: false,
  motif: 'hebdomadaire',
  jusquA: '',
}

export default function CreneauxProduit({ produitId, droits = [], peutModifier = false }) {
  const [activites, setActivites] = useState(null)
  const [ressources, setRessources] = useState(null)
  const [creneaux, setCreneaux] = useState(null)
  // `{}` = lu et rien à dire ; une entrée manquante = occupation INCONNUE pour ce créneau, pas
  // zéro. La distinction porte l'affichage : on n'écrit un nombre de places restantes que si le
  // serveur l'a donné.
  const [occupation, setOccupation] = useState({})
  const [timedEntry, setTimedEntry] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [refus, setRefus] = useState(false)
  const [succes, setSucces] = useState(null)
  const [enCours, setEnCours] = useState(false)
  const [rattachement, setRattachement] = useState('')
  const [ouverture, setOuverture] = useState(OUVERTURE_VIDE)
  const [formulaireOuvert, setFormulaireOuvert] = useState(false)

  const peutLire = aLeDroit(droits, 'reservation.lire')
  const peutRattacher = aLeDroit(droits, 'reservation.gerer_ressource')
  const peutOuvrir = aLeDroit(droits, 'reservation.gerer_creneau')

  // Les activités qui référencent CE produit. Le serveur ne sait pas répondre à « quelles activités
  // pointent vers ce produit » — aucun filtre n'est déclaré sur `produitTarifReference` — donc on
  // lit la liste de l'établissement et on la trie ici. Elle tient en quelques dizaines de lignes.
  const miennes = useMemo(
    () => (activites || []).filter((a) => idDe(a.produitTarifReference) === produitId),
    [activites, produitId],
  )

  const chargerCreneaux = useCallback(async (desActivites) => {
    if (desActivites.length === 0) {
      setCreneaux([])
      return
    }
    // Un appel par activité : le filtre `activite` est une égalité exacte, pas une liste. Deux ou
    // trois activités par produit, c'est le cas courant ; au-delà, le tri reste bon marché.
    const depuis = new Date().toISOString()
    const reponses = await Promise.all(desActivites.map((a) => api.creneauxDeActivite(a.id, depuis)))
    const tout = reponses.flatMap((r) => membres(r))
    tout.sort((x, y) => String(x.debut).localeCompare(String(y.debut)))
    setCreneaux(tout)

    // ── COMBIEN DE PLACES SONT PRISES — ET POURQUOI ÇA VIENT DU SERVEUR ─────────────────────
    //
    // Cet écran affichait la capacité seule (« 30 places ») : un exploitant ne savait pas, depuis
    // son catalogue, si sa visite de mardi était pleine. Le chiffre manquant n'était pas calculable
    // ici — l'occupation compte les réservations qui CONSOMMENT le créneau, pas celles qui le
    // visent (D33), et le champ qui le dit n'est pas sérialisé. Filtrer les réservations par
    // créneau côté écran aurait affiché zéro sur un service complet.
    //
    // ⚠ ET LA ROUTE QUI LE SERT N'EXISTAIT PAS : `OccupancyProvider` était écrit et câblé à rien
    // (#34). Elle l'est depuis ce lot, et c'est `JaugeCreneauGuard` — le service qui décide si une
    // réservation est acceptée — qui produit le nombre. Pas de seconde implémentation de la jauge.
    //
    // Un appel par ressource DISTINCTE, borné à la plage réellement affichée : la route rend les
    // créneaux d'une ressource sur une période, pas d'un créneau isolé, et un appel par créneau
    // ferait des centaines de requêtes sur un planning chargé.
    const parRessource = new Map()
    for (const c of tout) {
      const id = idDe(c.ressource)
      if (id) parRessource.set(id, true)
    }
    if (parRessource.size === 0) { setOccupation({}); return }

    const jour = (iso) => String(iso).slice(0, 10)
    const du = jour(tout[0].debut)
    const au = jour(tout[tout.length - 1].debut)

    // ⚠ `allSettled`, ET LE REFUS N'EST PAS UN ZÉRO. Sans le droit de lire l'occupation, on laisse
    // la case vide plutôt que d'écrire « 0 pris » — une place libre annoncée sur un créneau complet
    // se vend, et le client se présente devant une salle pleine.
    const lots = await Promise.allSettled(
      [...parRessource.keys()].map((id) => api.occupationRessource(id, du, au)),
    )
    const carte = {}
    for (const lot of lots) {
      if (lot.status !== 'fulfilled') continue
      for (const ligne of lot.value?.creneaux || []) {
        if (ligne?.creneau) carte[ligne.creneau] = ligne
      }
    }
    setOccupation(carte)
  }, [])

  const charger = useCallback(async () => {
    if (!produitId || !peutLire) return
    setErreur(null)

    // ⚠ TROIS LECTURES, TROIS DIAGNOSTICS. Un `Promise.all` aurait fait dire « le planning n'a pas
    // pu être lu » à un refus de droit sur les seules ressources — et l'exploitant aurait cherché
    // une panne là où il manquait une permission.
    const [act, res, prod] = await Promise.allSettled([
      api.reservationActivites(),
      api.reservationRessources(),
      api.produit(produitId),
    ])

    if (act.status === 'rejected') {
      if (act.reason?.status === 403) setRefus(true)
      else setErreur(act.reason?.message || 'Le planning n’a pas pu être lu.')
      setActivites([])
      setCreneaux([])
      return
    }
    setRefus(false)
    const listeActivites = membres(act.value)
    setActivites(listeActivites)

    // Les ressources ne servent QU'À ouvrir un créneau. Leur absence n'empêche pas de lire le
    // planning : on le dit au moment d'ouvrir, pas en travers de tout le bloc.
    setRessources(res.status === 'fulfilled' ? membres(res.value) : [])

    if (prod.status === 'fulfilled') {
      setTimedEntry((prod.value?.champsPerso || {}).timedEntry === true)
    }

    try {
      await chargerCreneaux(listeActivites.filter((a) => idDe(a.produitTarifReference) === produitId))
    } catch (e) {
      setCreneaux(null)
      setErreur(e?.message || 'Les horaires n’ont pas pu être lus.')
    }
  }, [produitId, peutLire, chargerCreneaux])

  useEffect(() => {
    charger()
  }, [charger])

  if (!peutLire) return null

  async function basculerTimedEntry(valeur) {
    setEnCours(true)
    setErreur(null)
    setSucces(null)
    try {
      // On relit les champs perso juste avant d'écrire : ils portent aussi `visuelUrl`, et un PATCH
      // qui n'enverrait que `timedEntry` remplacerait l'objet entier — le visuel disparaîtrait sans
      // que personne ne fasse le lien avec cette case à cocher.
      const frais = await api.produit(produitId)
      const champs = { ...(frais?.champsPerso || {}) }
      if (valeur) champs.timedEntry = true
      else delete champs.timedEntry
      await api.majProduit(produitId, { champsPerso: champs })
      setTimedEntry(valeur)
      setSucces(valeur
        ? 'La boutique demandera un horaire avant l’ajout au panier.'
        : 'La boutique vend ce produit sans horaire à choisir.')
    } catch (e) {
      setErreur(e?.message || 'Le réglage n’a pas pu être enregistré.')
    } finally {
      setEnCours(false)
    }
  }

  async function rattacher(e) {
    e.preventDefault()
    if (!rattachement) return
    setEnCours(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.majActiviteReservation(rattachement, { produitTarifReference: `/api/produits/${produitId}` })
      setRattachement('')
      await charger()
      setSucces('Les créneaux de cette activité sont désormais vendus sous ce produit.')
    } catch (err) {
      setErreur(err?.message || 'Le rattachement a échoué.')
    } finally {
      setEnCours(false)
    }
  }

  async function detacher(activite) {
    setEnCours(true)
    setErreur(null)
    setSucces(null)
    try {
      // `null` DÉTACHE — un champ absent laisserait la valeur en place (cf. `client.js`).
      await api.majActiviteReservation(activite.id, { produitTarifReference: null })
      await charger()
      setSucces('Activité détachée : ses créneaux ne se vendent plus sous ce produit.')
    } catch (err) {
      setErreur(err?.message || 'Le détachement a échoué.')
    } finally {
      setEnCours(false)
    }
  }

  async function ouvrir(e) {
    e.preventDefault()
    if (miennes.length === 0 || !ouverture.ressource) return
    setEnCours(true)
    setErreur(null)
    setSucces(null)

    const debut = instant(ouverture.date, `${ouverture.debut}:00`)
    const fin = instant(ouverture.date, `${ouverture.fin}:00`)
    if (debut === null || fin === null) {
      setErreur('La date ou l’heure saisie n’est pas lisible.')
      setEnCours(false)
      return
    }

    try {
      const corps = {
        ressource: `/api/reservation_ressources/${ouverture.ressource}`,
        activite: `/api/reservation_activites/${miennes[0].id}`,
        debut,
        fin,
      }
      if (ouverture.capacite !== '') corps.capacite = Number(ouverture.capacite)
      if (ouverture.repeter && ouverture.jusquA) {
        corps.recurrence = {
          motif: ouverture.motif,
          finRecurrence: instant(ouverture.jusquA, '23:59:59'),
          // Une occurrence qui tombe sur une ressource déjà prise est CRÉÉE et marquée en attente
          // d'arbitrage (décision du 31/08). On demande donc la règle qui laisse un humain trancher
          // plutôt que le report automatique, qui déplacerait une séance sans que personne le sache.
          regleConflit: 'validation_manuelle',
        }
      }
      await api.creerCreneau(corps)
      await charger()
      setFormulaireOuvert(false)
      setOuverture({ ...OUVERTURE_VIDE, ressource: ouverture.ressource })
    } catch (err) {
      // Le serveur formule ses refus (chevauchement RG-M5-03, capacité, dates) : on les affiche tels
      // quels plutôt que d'en écrire un second qui divergerait le jour où la règle bouge.
      setErreur(err?.message || 'Le créneau n’a pas pu être ouvert.')
    } finally {
      setEnCours(false)
    }
  }

  // ⚠ COMPTÉ APRÈS RECHARGEMENT, ET PAS DÉDUIT DE LA RÉPONSE DU SERVEUR. `POST /reservation/creneaux`
  // ne rend QUE la première occurrence : une récurrence de douze séances dont trois tombent en
  // conflit répond 201 sans rien dire des onze autres. Le seul compte juste est celui de la liste
  // relue.
  const enAttente = (creneaux || []).filter((c) => c.enAttenteArbitrage).length

  const libelleRessource = (creneau) => {
    const id = idDe(creneau.ressource)
    return (ressources || []).find((r) => r.id === id)?.libelle || null
  }

  const rattachables = (activites || []).filter((a) => idDe(a.produitTarifReference) !== produitId)

  if (refus) {
    return (
      <div className="hint" style={{ margin: 0 }}>
        Vous n’avez pas le droit de lire le planning de réservation — ce produit peut porter des
        horaires sans que cet écran puisse les montrer. Ce droit est celui du module Réservation, pas
        celui du catalogue.
      </div>
    )
  }

  return (
    <div>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {/* ── L'INTERRUPTEUR ─────────────────────────────────────────────────────────────────────
          Il ne se déduit PAS du rattachement : un produit peut servir de tarif de référence à une
          activité (pour que la ligne de vente porte le bon compte comptable) sans être vendu à
          l'horaire en ligne. Confondre les deux vendrait un horaire là où l'exploitant n'en veut
          pas. */}
      <label
        style={{ display: 'flex', alignItems: 'center', gap: 'var(--esp-serre)', fontWeight: 400 }}
      >
        <input
          type="checkbox"
          checked={timedEntry === true}
          disabled={!peutModifier || enCours || timedEntry === null}
          onChange={(e) => basculerTimedEntry(e.target.checked)}
        />
        Ce produit se vend à l’horaire
      </label>
      <div className="hint">
        {timedEntry === null
          ? 'Réglage en cours de lecture…'
          : timedEntry
            ? 'La boutique en ligne affiche « Horaire à choisir » et refuse l’ajout au panier tant qu’aucun créneau n’est retenu.'
            : 'La boutique vend ce produit sans demander d’horaire.'}
      </div>

      {/* ── CE QUI LE PROGRAMME ─────────────────────────────────────────────────────────────── */}
      <div className="fiche-sec" style={{ marginTop: 'var(--esp-large)' }}>Ce qui le programme</div>

      {activites === null && <div className="hint">Chargement…</div>}

      {activites !== null && miennes.length === 0 && (
        <div className="hint">
          Aucune activité du planning ne renvoie à ce produit :{' '}
          <strong>il ne peut afficher aucun horaire en ligne</strong>. Rattachez-en une — ses
          créneaux deviendront ceux de ce produit.
        </div>
      )}

      {miennes.map((a) => (
        <div
          key={a.id}
          style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 'var(--esp-large)', padding: '4px 0' }}
        >
          <span>
            {a.libelle}
            <span className="sub"> · {a.dureeMinutes} min{a.actif === false ? ' · inactive' : ''}</span>
          </span>
          {peutRattacher && (
            <button type="button" className="btn ghost sm" disabled={enCours} onClick={() => detacher(a)}>
              Détacher
            </button>
          )}
        </div>
      ))}

      {peutRattacher && rattachables.length > 0 && (
        <form onSubmit={rattacher} className="field" style={{ marginTop: 'var(--esp-serre)' }}>
          <label htmlFor="cr-rattacher">Rattacher une activité</label>
          <div style={{ display: 'flex', gap: 'var(--esp-serre)' }}>
            <select
              id="cr-rattacher"
              className="select"
              value={rattachement}
              onChange={(e) => setRattachement(e.target.value)}
            >
              <option value="">—</option>
              {rattachables.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.libelle}
                  {a.produitTarifReference ? ' (déjà rattachée à un autre produit)' : ''}
                </option>
              ))}
            </select>
            <button type="submit" className="btn" disabled={!rattachement || enCours}>Rattacher</button>
          </div>
          <div className="hint">
            Une activité ne renvoie qu’à un produit : la rattacher ici la détache de celui qu’elle
            désignait, et le tarif de référence de ses ventes change avec elle.
          </div>
        </form>
      )}

      {/* ── LES HORAIRES ────────────────────────────────────────────────────────────────────── */}
      <div className="fiche-sec" style={{ marginTop: 'var(--esp-large)' }}>Horaires à venir</div>

      {creneaux === null && <div className="hint">Chargement…</div>}

      {creneaux !== null && creneaux.length === 0 && miennes.length > 0 && (
        <div className="hint">
          Aucun horaire à venir sur ce produit : <strong>la boutique n’a rien à proposer</strong>,
          même si la case ci-dessus est cochée.
        </div>
      )}

      {(creneaux || []).map((c) => (
        <div
          key={c.id}
          style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--esp-large)', padding: '4px 0' }}
        >
          <span>
            {jourLong(c.debut)} · {hhmm(c.debut)} – {hhmm(c.fin)}
            {libelleRessource(c) && <span className="sub"> · {libelleRessource(c)}</span>}
          </span>
          <span className="sub">
            {/* ⚠ « 12 sur 30 » N'EST PAS UN HABILLAGE DE « 30 places ». La capacité seule dit ce
                que le créneau POURRAIT accueillir ; l'exploitant, lui, veut savoir s'il reste de
                la place. Et quand le serveur ne l'a pas dit — droit manquant, lecture échouée —
                on retombe sur la capacité SANS inventer un reste : afficher « 30 restantes » sur
                un créneau complet ferait vendre une place qui n'existe pas. */}
            {occupation[c.id]
              ? `${occupation[c.id].restantes} sur ${c.capacite} place${c.capacite > 1 ? 's' : ''}`
              : `${c.capacite} place${c.capacite > 1 ? 's' : ''}`}
            {occupation[c.id]?.restantes === 0 ? ' · complet' : ''}
            {c.statut && c.statut !== 'planifie' ? ` · ${c.statut}` : ''}
            {c.enAttenteArbitrage ? ' · en attente d’arbitrage' : ''}
          </span>
        </div>
      ))}

      {enAttente > 0 && (
        <div className="hint">
          {enAttente} créneau{enAttente > 1 ? 'x' : ''} en attente d’arbitrage —{' '}
          <strong>ceux-là ne se vendent pas</strong> et n’apparaissent pas en ligne. Leur ressource
          était déjà prise ; un humain tranche depuis l’écran Réservation.
        </div>
      )}

      {/* ── OUVRIR ──────────────────────────────────────────────────────────────────────────── */}
      {peutOuvrir && miennes.length > 0 && !formulaireOuvert && (
        <button
          type="button"
          className="btn"
          style={{ marginTop: 'var(--esp-serre)' }}
          onClick={() => setFormulaireOuvert(true)}
        >
          Ouvrir des créneaux
        </button>
      )}

      {peutOuvrir && miennes.length > 0 && formulaireOuvert && (
        <form onSubmit={ouvrir} style={{ marginTop: 'var(--esp-serre)' }}>
          <div className="field">
            <label htmlFor="cr-ressource">Où</label>
            <select
              id="cr-ressource"
              className="select"
              value={ouverture.ressource}
              onChange={(e) => setOuverture((s) => ({ ...s, ressource: e.target.value }))}
              required
            >
              <option value="">—</option>
              {(ressources || []).filter((r) => r.actif !== false).map((r) => (
                <option key={r.id} value={r.id}>{r.libelle} ({r.capacitePropre} places)</option>
              ))}
            </select>
            {ressources !== null && ressources.length === 0 && (
              <div className="hint">
                Aucune ressource lisible : un créneau se tient dans un lieu ou avec quelqu’un, et il
                n’y a rien à choisir. Elles se créent depuis l’écran Réservation.
              </div>
            )}
          </div>

          <div style={{ display: 'flex', gap: 'var(--esp-serre)', flexWrap: 'wrap' }}>
            <div className="field">
              <label htmlFor="cr-date">Le</label>
              <input
                id="cr-date"
                type="date"
                className="input"
                value={ouverture.date}
                onChange={(e) => setOuverture((s) => ({ ...s, date: e.target.value }))}
                required
              />
            </div>
            <div className="field">
              <label htmlFor="cr-debut">De</label>
              <input
                id="cr-debut"
                type="time"
                className="input"
                value={ouverture.debut}
                onChange={(e) => setOuverture((s) => ({ ...s, debut: e.target.value }))}
                required
              />
            </div>
            <div className="field">
              <label htmlFor="cr-fin">À</label>
              <input
                id="cr-fin"
                type="time"
                className="input"
                value={ouverture.fin}
                onChange={(e) => setOuverture((s) => ({ ...s, fin: e.target.value }))}
                required
              />
            </div>
            <div className="field">
              <label htmlFor="cr-capacite">Places</label>
              <input
                id="cr-capacite"
                type="number"
                min="1"
                className="input"
                placeholder="capacité du lieu"
                value={ouverture.capacite}
                onChange={(e) => setOuverture((s) => ({ ...s, capacite: e.target.value }))}
              />
            </div>
          </div>
          <div className="hint">
            Vide = la capacité propre de la ressource choisie. C’est le nombre de places mises en
            vente pour cet horaire.
          </div>

          <div className="field" style={{ marginTop: 'var(--esp-serre)' }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--esp-serre)', fontWeight: 400 }}>
              <input
                type="checkbox"
                checked={ouverture.repeter}
                onChange={(e) => setOuverture((s) => ({ ...s, repeter: e.target.checked }))}
              />
              Répéter
            </label>
          </div>

          {ouverture.repeter && (
            <div style={{ display: 'flex', gap: 'var(--esp-serre)', flexWrap: 'wrap' }}>
              <div className="field">
                <label htmlFor="cr-motif">Rythme</label>
                <select
                  id="cr-motif"
                  className="select"
                  value={ouverture.motif}
                  onChange={(e) => setOuverture((s) => ({ ...s, motif: e.target.value }))}
                >
                  {MOTIFS.map(([valeur, libelle]) => (
                    <option key={valeur} value={valeur}>{libelle}</option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="cr-jusqua">Jusqu’au</label>
                <input
                  id="cr-jusqua"
                  type="date"
                  className="input"
                  value={ouverture.jusquA}
                  onChange={(e) => setOuverture((s) => ({ ...s, jusquA: e.target.value }))}
                  required
                />
              </div>
            </div>
          )}

          <div style={{ display: 'flex', gap: 'var(--esp-serre)', marginTop: 'var(--esp-serre)' }}>
            <button type="submit" className="btn primary" disabled={enCours || !ouverture.ressource}>
              {enCours ? 'Ouverture…' : 'Ouvrir'}
            </button>
            <button type="button" className="btn ghost sm" onClick={() => setFormulaireOuvert(false)}>
              Annuler
            </button>
          </div>
          {miennes.length > 1 && (
            <div className="hint">
              Les créneaux ouverts ici portent l’activité « {miennes[0].libelle} ». Les autres
              activités rattachées se programment depuis l’écran Réservation.
            </div>
          )}
        </form>
      )}
    </div>
  )
}
