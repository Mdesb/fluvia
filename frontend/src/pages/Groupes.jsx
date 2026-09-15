import { useCallback, useEffect, useRef, useState } from 'react'
import { confirmer } from '../components/Confirmation.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri.js'
import { useEtatUrl } from '../api/url.js'

// GROUPES — module transverse `App\Group`. Un groupe de participants (classe scolaire, comité
// d'entreprise, tour-opérateur, association) qu'un établissement reçoit, quel que soit son métier :
// piscine, patinoire, musée, séjours, restauration — et tout métier à venir (l'accrobranche est un
// `Activite` de plus, cet écran n'en sait rien et n'a pas à le savoir).
//
// ⚠ RIEN À VOIR avec le « Groupe » CRM (`App\Organisation\Entity\Groupe`), qui est le locataire.

const TYPES = {
  school: 'Scolaire',
  tour: 'Tour-opérateur',
  works_council: "Comité d'entreprise",
  association: 'Association',
  other: 'Autre',
}

const CATEGORIES = {
  child: 'Enfant',
  adult: 'Adulte',
  accompanist: 'Accompagnateur',
  other: 'Autre',
}

const STATUTS = {
  option: ['Option', 'warn'],
  confirmed: ['Confirmée', 'good'],
  cancelled: ['Annulée', 'mut'],
}

const PAIEMENTS = {
  pending: 'En attente',
  purchase_order: 'Bon de commande',
  invoiced: 'Facturé',
  paid: 'Réglé',
}

function badgeStatut(code) {
  const [libelle, classe] = STATUTS[code] || [code || '—', 'mut']
  return <span className={`badge ${classe}`}>{libelle}</span>
}

function creneauLabel(c) {
  if (!c) return '—'
  const debut = c.debut ? new Date(c.debut) : null
  const quand = debut && !Number.isNaN(debut.getTime())
    ? debut.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
    : idDe(c)
  // L'activité est embarquée dans le créneau lu (7 créneaux sur 223 n'en ont pas à Piscine A le
  // 15/09/2026). ⚠ UN CHAMP NUL N'EST PAS ÉCRIT PAR L'API : la clé disparaît, et `=== null` ne voyait
  // aucun de ces sept. Une activité réduite à son IRI, jamais libellée ici, laisse la date seule.
  if (typeof c !== 'object') return quand
  if (c.activite === undefined || c.activite === null) return `${quand} · sans activité`
  return c.activite?.libelle ? `${quand} · ${c.activite.libelle}` : quand
}

// `groupe` : le groupe sélectionné ; `panier` : la réservation dont on compose le panier.
// `ecran` : un formulaire de la page ; `membre` et `reservation` désignent ce qu'il édite.
const DEFAUTS_URL = { groupe: '', panier: '', ecran: '', reservation: '', membre: '' }
const ECRANS_PAGE = ['nouveau-groupe', 'forfaits', 'contingents']
const ECRANS_GROUPE = ['modifier-groupe', 'participant', 'nouvelle-reservation']
const ECRANS_RESERVATION = ['detail', 'affecter', 'paiement', 'facturer', 'gratuites']

export default function Groupes({ etabActif, droits }) {
  const peutGerer = aLeDroit(droits, 'group.manage')
  // ⚠ LA SÉLECTION ENTRE DANS L'ADRESSE : sans elle, un panier n'avait pas de groupe où revenir.
  const [params, majParams] = useEtatUrl('groupes', DEFAUTS_URL)

  const [groupes, setGroupes] = useState([])
  // ⚠ `false` = LECTURE ÉCHOUÉE : « Aucun groupe pour l'instant » s'affichait sur un refus.
  const [groupesLus, setGroupesLus] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [selection, setSelection] = useState(null) // groupe détaillé
  const [membresSel, setMembresSel] = useState([])
  const [reservations, setReservations] = useState([])
  const [creneaux, setCreneaux] = useState([])
  const [taux, setTaux] = useState([])
  const [forfaits, setForfaits] = useState([])
  const [produits, setProduits] = useState([])
  // ⚠ Drapeaux de lecture des listes du panier : `false` = LECTURE ÉCHOUÉE, pas « aucun ».
  const [tauxLus, setTauxLus] = useState(null)
  const [produitsLus, setProduitsLus] = useState(null)
  const [forfaitsLus, setForfaitsLus] = useState(null)
  const [contingents, setContingents] = useState([])
  // ⚠ `false` = LECTURE ÉCHOUÉE : un refus rendait une liste vide, donc « rien à choisir ».
  const [creneauxLus, setCreneauxLus] = useState(null)
  const [contingentsLus, setContingentsLus] = useState(null)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setGroupes(membres(await api.groupesParticipants()))
      setGroupesLus(true)
    } catch (e) {
      setGroupesLus(false)
      setErreur(e?.message || 'Impossible de lire les groupes.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    charger()
    setSelection(null)
  }, [charger, etabActif])

  // Créneaux de l'établissement, chargés une fois : ils servent à affecter une réservation.
  useEffect(() => {
    let vivant = true
    api.reservationCreneaux()
      .then((r) => { if (vivant) { setCreneaux(membres(r)); setCreneauxLus(true) } })
      .catch(() => { if (vivant) { setCreneaux([]); setCreneauxLus(false) } })
    return () => { vivant = false }
  }, [etabActif])

  // Taux de TVA de l'exploitant, pour la génération de devis.
  useEffect(() => {
    let vivant = true
    api.tauxTvas()
      .then((r) => { if (vivant) { setTaux(membres(r)); setTauxLus(true) } })
      .catch(() => { if (vivant) { setTaux([]); setTauxLus(false) } })
    return () => { vivant = false }
  }, [etabActif])

  // Produits du catalogue, pour composer forfaits et paniers.
  useEffect(() => {
    let vivant = true
    api.produits({ itemsPerPage: 200 })
      .then((r) => { if (vivant) { setProduits(membres(r)); setProduitsLus(true) } })
      .catch(() => { if (vivant) { setProduits([]); setProduitsLus(false) } })
    return () => { vivant = false }
  }, [etabActif])

  // Forfaits groupe, rechargeables après édition.
  const chargerForfaits = useCallback(async () => {
    try {
      setForfaits(membres(await api.groupProducts()))
      setForfaitsLus(true)
    } catch {
      setForfaits([])
      setForfaitsLus(false)
    }
  }, [])
  useEffect(() => { chargerForfaits() }, [chargerForfaits, etabActif])

  // Contingents de gratuité, rechargeables après édition ou octroi.
  const chargerContingents = useCallback(async () => {
    try {
      setContingents(membres(await api.gratuiteContingents()))
      setContingentsLus(true)
    } catch {
      setContingents([])
      setContingentsLus(false)
    }
  }, [])
  useEffect(() => { chargerContingents() }, [chargerContingents, etabActif])

  // ⚠ LA LECTURE DU GROUPE A TROIS ÉTATS, ET UNE RÉPONSE TARDIVE EST IGNORÉE. Sur un échec, l'ancienne
  // sélection restait affichée : on lisait le détail d'un autre groupe que celui demandé.
  // `null` = aucun groupe demandé · 'chargement' · 'lu' · 'introuvable' (404) · 'echec'.
  const [lectureGroupe, setLectureGroupe] = useState(null)
  const demandeCourante = useRef('')
  const ouvrir = useCallback(async (id, silencieux = false) => {
    demandeCourante.current = id
    if (!silencieux) { setErreur(null); setLectureGroupe('chargement') }
    try {
      // GET item : on relit le groupe frais plutôt que de se fier à la ligne de liste.
      const detail = await api.groupeParticipant(id)
      const lesMembres = membres(await api.membresGroupe(id))
      const toutes = membres(await api.reservationsGroupe())
      if (demandeCourante.current !== id) return
      setSelection(detail)
      setMembresSel(lesMembres)
      setReservations(toutes.filter((b) => idDe(b.group) === id))
      setLectureGroupe('lu')
    } catch (e) {
      if (demandeCourante.current !== id) return
      setSelection(null)
      setLectureGroupe(e?.status === 404 ? 'introuvable' : 'echec')
    }
  }, [])

  // L'adresse décide du groupe ouvert : un lien, un F5 et « précédent » le rouvrent.
  useEffect(() => {
    if (!params.groupe) { demandeCourante.current = ''; setSelection(null); setLectureGroupe(null); return }
    ouvrir(params.groupe)
  }, [params.groupe, etabActif, ouvrir])

  // ⚠ LE PANIER SE LIT PAR SON IDENTIFIANT : la liste des réservations est bornée à 200, et l'écran
  // doit vérifier que la réservation appartient bien au groupe de l'adresse.
  const [reservationEcran, setReservationEcran] = useState(null)
  const [lectureReservation, setLectureReservation] = useState(null)
  // Le panier et les écrans d'une réservation lisent la même fiche, par son identifiant.
  const idReservationEcran = params.panier || (ECRANS_RESERVATION.includes(params.ecran) ? params.reservation : '')
  useEffect(() => {
    const id = idReservationEcran
    if (!id) { setReservationEcran(null); setLectureReservation(null); return undefined }
    let vivant = true
    setLectureReservation('chargement')
    setReservationEcran(null)
    api.reservationGroupe(id)
      .then((b) => { if (vivant) { setReservationEcran(b); setLectureReservation('lu') } })
      .catch((e) => { if (vivant) setLectureReservation(e?.status === 404 ? 'introuvable' : 'echec') })
    return () => { vivant = false }
  }, [idReservationEcran, etabActif])

  async function geste(fn, message) {
    setErreur(null)
    setSucces(null)
    try {
      await fn()
      setSucces(message)
      await charger()
      if (params.groupe) await ouvrir(params.groupe, true)
    } catch (e) {
      setErreur(e?.message || "L'opération a échoué.")
    }
  }

  // ── LES FORMULAIRES EN ÉCRANS : OUVRIR, FERMER, ENVOYER ───────────────────────────────────────
  // ⚠ UN ÉCHEC GARDE L'ÉCRAN OUVERT, SAISIE COMPRISE : la modale se fermait quel que soit le résultat
  // et l'erreur s'affichait derrière, sur la page — la saisie était perdue. Le succès ferme l'écran,
  // et son message s'affiche sur la page, qui reste montée.
  const [erreurEcran, setErreurEcran] = useState(null)
  useEffect(() => { setErreurEcran(null) }, [params.ecran, params.membre, params.reservation])
  const ouvrirEcran = (patch) => majParams({ ecran: '', membre: '', reservation: '', panier: '', ...patch }, { pousser: true })
  const fermerEcran = () => majParams({ ecran: '', membre: '', reservation: '' }, { pousser: true })
  async function gesteEcran(fn, message) {
    setErreurEcran(null)
    setSucces(null)
    try {
      await fn()
    } catch (e) {
      setErreurEcran(e?.message || "L'opération a échoué.")
      return
    }
    setSucces(message)
    fermerEcran()
    await charger()
    if (params.groupe) await ouvrir(params.groupe, true)
  }

  if (!aLeDroit(droits, 'group.read')) {
    return <p className="empty">Vous n’avez pas accès aux groupes.</p>
  }

  // ── LE PANIER D'UNE RÉSERVATION, EN ÉCRAN ────────────────────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LES CONDITIONS DU BOUTON ET L'ÉCRAN LES REPREND : le droit de gérer les
  // groupes, une réservation non annulée — et qui appartient au groupe de l'adresse.
  if (params.groupe && params.panier) {
    const fermerPanier = () => majParams({ panier: '' }, { pousser: true })
    const r = reservationEcran
    let contenu
    if (!peutGerer) {
      contenu = <div className="banner banner-warn">Composer le panier d’une réservation demande le droit de gérer les groupes, que ce compte n’a pas.</div>
    } else if (lectureReservation === 'chargement' || lectureReservation === null) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (lectureReservation !== 'lu' || !r) {
      contenu = (
        <div className="banner banner-warn">
          {lectureReservation === 'echec'
            ? 'Cette réservation n’a pas pu être lue. Ce n’est pas la même chose que « elle n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
            : 'Cette réservation n’existe pas, ou n’est pas visible depuis cet établissement.'}
        </div>
      )
    } else if (idDe(r.group) !== params.groupe) {
      contenu = <div className="banner banner-warn">Cette réservation appartient à un autre groupe que celui de l’adresse.</div>
    } else if (r.status === 'cancelled') {
      contenu = <div className="banner banner-warn">Cette réservation est annulée : son panier ne se modifie plus.</div>
    } else {
      contenu = <FormPanier key={params.panier} reservationId={r.id} forfaits={forfaits} produits={produits} taux={taux} onFermer={fermerPanier}
        listesIllisibles={[forfaitsLus === false && 'les forfaits', produitsLus === false && 'les produits', tauxLus === false && 'les taux de TVA'].filter(Boolean)} />
    }
    return (
      <div className="view">
        <button className="btn ghost sm" type="button" onClick={fermerPanier}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour au groupe{selection?.label ? ` « ${selection.label} »` : ''}
        </button>
        {contenu}
      </div>
    )
  }

  // ── LES FORMULAIRES DE LA PAGE, EN ÉCRANS ────────────────────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LES CONDITIONS DES BOUTONS ET L'ÉCRAN LES REPREND : le droit de gérer les
  // groupes (sauf pour lire une fiche), un groupe lu, une réservation lue qui appartient au groupe de
  // l'adresse — non annulée pour la modifier, sans devis pour la facturer.
  if (params.ecran) {
    const nomEcran = params.ecran
    const r = reservationEcran
    const attente = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    const connu = ECRANS_PAGE.includes(nomEcran) || ECRANS_GROUPE.includes(nomEcran) || ECRANS_RESERVATION.includes(nomEcran)
    let retour = 'aux groupes'
    let contenu
    if (!connu) {
      contenu = <div className="banner banner-warn">Cet écran n’existe pas sur la page Groupes.</div>
    } else if (nomEcran !== 'detail' && !peutGerer) {
      contenu = <div className="banner banner-warn">Ce formulaire demande le droit de gérer les groupes, que ce compte n’a pas.</div>
    } else if (nomEcran === 'nouveau-groupe') {
      contenu = <FormGroupe onFermer={fermerEcran} onValider={(corps) => gesteEcran(() => api.creerGroupeParticipant(corps), 'Groupe créé.')} />
    } else if (nomEcran === 'forfaits') {
      contenu = (
        <>
          {(produitsLus === false || tauxLus === false) && (
            <div className="banner banner-warn">
              Lecture impossible : {[produitsLus === false && 'les produits', tauxLus === false && 'les taux de TVA'].filter(Boolean).join(', ')}. Les listes de choix d’un nouveau forfait sont vides pour cette raison, pas faute d’éléments.
            </div>
          )}
          <FormForfaits produits={produits} taux={taux} onFermer={fermerEcran} onChange={chargerForfaits} />
        </>
      )
    } else if (nomEcran === 'contingents') {
      contenu = <FormContingents onFermer={fermerEcran} onChange={chargerContingents} />
    } else {
      retour = `au groupe${selection?.label ? ` « ${selection.label} »` : ''}`
      if (!params.groupe) {
        contenu = <div className="banner banner-warn">Aucun groupe n’est désigné dans l’adresse.</div>
      } else if (lectureGroupe === 'introuvable') {
        contenu = <div className="banner banner-warn">Ce groupe n’existe pas, ou n’est pas visible depuis cet établissement.</div>
      } else if (lectureGroupe === 'echec') {
        contenu = <div className="banner banner-error">Ce groupe n’a pas pu être lu. Ce n’est pas la même chose que « il n’existe pas ».</div>
      } else if (lectureGroupe !== 'lu' || !selection) {
        contenu = attente
      } else if (nomEcran === 'modifier-groupe') {
        contenu = (
          <FormGroupe key={idDe(selection)} initial={selection} onFermer={fermerEcran}
            onValider={(corps) => gesteEcran(() => api.modifierGroupeParticipant(idDe(selection), corps), 'Groupe modifié.')} />
        )
      } else if (nomEcran === 'participant') {
        contenu = (
          <FormMembre key={params.membre || 'nouveau'} groupe={selection} membreId={params.membre || null} onFermer={fermerEcran}
            onValider={(corps, membreId) => gesteEcran(
              () => membreId ? api.modifierMembre(membreId, { category: corps.category }) : api.ajouterMembre(corps),
              membreId ? 'Participant modifié.' : 'Participant ajouté.',
            )} />
        )
      } else if (nomEcran === 'nouvelle-reservation') {
        contenu = (
          <FormReservation key={idDe(selection)} groupe={selection} onFermer={fermerEcran}
            onValider={(corps) => gesteEcran(() => api.creerReservationGroupe(corps), 'Réservation créée.')} />
        )
      } else if (!params.reservation) {
        contenu = <div className="banner banner-warn">Aucune réservation n’est désignée dans l’adresse.</div>
      } else if (lectureReservation === 'chargement' || lectureReservation === null) {
        contenu = attente
      } else if (lectureReservation !== 'lu' || !r) {
        contenu = (
          <div className="banner banner-warn">
            {lectureReservation === 'echec'
              ? 'Cette réservation n’a pas pu être lue. Ce n’est pas la même chose que « elle n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
              : 'Cette réservation n’existe pas, ou n’est pas visible depuis cet établissement.'}
          </div>
        )
      } else if (idDe(r.group) !== params.groupe) {
        contenu = <div className="banner banner-warn">Cette réservation appartient à un autre groupe que celui de l’adresse.</div>
      } else if (nomEcran === 'detail') {
        contenu = <DetailReservation resa={r} creneaux={creneaux} />
      } else if (r.status === 'cancelled') {
        contenu = <div className="banner banner-warn">Cette réservation est annulée : elle ne se modifie plus.</div>
      } else if (nomEcran === 'affecter') {
        if (creneauxLus === null) {
          contenu = attente
        } else if (creneauxLus === false) {
          contenu = <div className="banner banner-error">La liste des créneaux n’a pas pu être lue : il n’y a rien à choisir. Ce n’est pas qu’il n’y en a aucun.</div>
        } else if (creneaux.length === 0) {
          contenu = <div className="banner banner-warn">Aucun créneau n’est ouvert sur cet établissement : il n’y a rien à affecter.</div>
        } else {
          contenu = (
            <FormAffecter key={r.id} creneaux={creneaux} onFermer={fermerEcran}
              onValider={(creneauId) => gesteEcran(() => api.affecterReservationGroupe(r.id, { creneau: creneauId }), 'Réservation affectée.')} />
          )
        }
      } else if (nomEcran === 'paiement') {
        contenu = (
          <FormPaiement key={r.id} valeur={r.paymentStatus} onFermer={fermerEcran}
            onValider={(paymentStatus) => gesteEcran(() => api.modifierReservationGroupe(r.id, { paymentStatus }), 'Paiement mis à jour.')} />
        )
      } else if (nomEcran === 'facturer') {
        contenu = r.commercialDocument
          ? <div className="banner banner-warn">Cette réservation a déjà un devis : elle ne se facture pas deux fois.</div>
          : (
            <>
              {tauxLus === false && (
                <div className="banner banner-warn">Les taux de TVA n’ont pas pu être lus : leur liste est vide pour cette raison. Un devis tiré d’un panier n’en a pas besoin.</div>
              )}
              <FormFacture key={r.id} taux={taux} onFermer={fermerEcran}
                onValider={(corps) => gesteEcran(() => api.facturerReservationGroupe(r.id, corps), 'Devis créé.')} />
            </>
          )
      } else {
        contenu = (
          <>
            {contingentsLus === false && (
              <div className="banner banner-warn">La liste des contingents n’a pas pu être lue : rien n’est proposé à l’octroi pour cette raison, pas faute de contingent.</div>
            )}
            <FormGratuites key={r.id} reservationId={r.id} contingents={contingents} onFermer={fermerEcran} onChange={chargerContingents} />
          </>
        )
      }
    }
    return (
      <div className="view">
        <button className="btn ghost sm" type="button" onClick={fermerEcran}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour {retour}
        </button>
        {erreurEcran && <div className="banner banner-error">{erreurEcran}</div>}
        {contenu}
      </div>
    )
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h2>Groupes</h2>
          <p className="sub">Groupes de participants, transverses à tous les métiers de l’établissement.</p>
        </div>
        {peutGerer && (
          <div className="actions">
            <button className="btn ghost" type="button" onClick={() => ouvrirEcran({ ecran: 'contingents' })}>
              Gratuités
            </button>
            <button className="btn ghost" type="button" onClick={() => ouvrirEcran({ ecran: 'forfaits' })}>
              Forfaits groupe
            </button>
            <button className="btn primary" type="button" onClick={() => ouvrirEcran({ ecran: 'nouveau-groupe' })}>
              Nouveau groupe
            </button>
          </div>
        )}
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : groupesLus === false ? (
        <div className="banner banner-error">
          La liste des groupes n’a pas pu être lue : ce n’est pas la même chose que « aucun groupe ».
        </div>
      ) : groupes.length === 0 ? (
        <p className="empty">Aucun groupe pour l’instant.</p>
      ) : (
        <div className="grid grid-2" style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', alignItems: 'start' }}>
          <div className="card">
            <div className="card-h"><h3>Les groupes</h3></div>
            <div className="card-b" style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr><th>Nom</th><th>Type</th><th className="num">Effectif</th></tr>
                </thead>
                <tbody>
                  {groupes.map((g) => (
                    <tr key={g.id} style={{ fontWeight: params.groupe === String(g.id) ? 600 : 400 }}>
                      <td>
                        <button type="button" className="btn ghost sm" onClick={() => majParams({ groupe: String(g.id), panier: '' }, { pousser: true })}>{g.label}</button>
                      </td>
                      <td className="sub">{TYPES[g.type] || g.type}</td>
                      <td className="num">{g.headcount}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {params.groupe && (selection && lectureGroupe === 'lu' ? (
            <DetailGroupe
              groupe={selection}
              membres={membresSel}
              reservations={reservations}
              creneaux={creneaux}
              peutGerer={peutGerer}
              onGeste={geste}
              onEcran={ouvrirEcran}
              onPanier={(id) => majParams({ panier: String(id) }, { pousser: true })}
            />
          ) : (
            <div className="card">
              <div className="card-b">
                {lectureGroupe === 'chargement' ? (
                  <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
                ) : lectureGroupe === 'introuvable' ? (
                  <div className="banner banner-warn">Ce groupe n’existe pas, ou n’est pas visible depuis cet établissement.</div>
                ) : (
                  <div className="banner banner-error">Ce groupe n’a pas pu être lu. Ce n’est pas la même chose que « il n’existe pas ».</div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function DetailGroupe({ groupe, membres: liste, reservations, creneaux, peutGerer, onGeste, onEcran, onPanier }) {
  return (
    <div className="card">
      <div className="card-h">
        <h3>{groupe.label}</h3>
        {peutGerer && (
          <button className="btn ghost sm" type="button" onClick={() => onEcran({ ecran: 'modifier-groupe' })}>
            Modifier
          </button>
        )}
      </div>
      <div className="card-b">
        <div className="deflist">
          <div><span>Type</span><span>{TYPES[groupe.type] || groupe.type}</span></div>
          <div><span>Organisateur</span><span>{groupe.organizerName || '—'}</span></div>
          <div><span>Courriel</span><span>{groupe.organizerEmail || '—'}</span></div>
          <div><span>Téléphone</span><span>{groupe.organizerPhone || '—'}</span></div>
          <div><span>Effectif prévu</span><span>{groupe.headcount}</span></div>
          {groupe.notes && <div><span>Notes</span><span>{groupe.notes}</span></div>}
        </div>

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h4>Participants ({liste.length})</h4>
          {peutGerer && (
            <button className="btn sm" type="button" onClick={() => onEcran({ ecran: 'participant' })}>Ajouter</button>
          )}
        </div>
        {liste.length === 0 ? (
          <p className="empty">Effectif renseigné sans liste nominative.</p>
        ) : (
          <table className="tbl">
            <thead><tr><th>Nom</th><th>Catégorie</th>{peutGerer && <th />}</tr></thead>
            <tbody>
              {liste.map((m) => (
                <tr key={m.id}>
                  <td>{m.firstName} {m.lastName}</td>
                  <td className="sub">{CATEGORIES[m.category] || m.category}</td>
                  {peutGerer && (
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => onEcran({ ecran: 'participant', membre: String(m.id) })}>Modifier</button>
                      {' '}
                      <button
                        className="btn danger sm"
                        type="button"
                        onClick={async () => {
                          if (!await confirmer(`Retirer ${m.firstName} ${m.lastName} du groupe ?`)) return
                          await onGeste(() => api.supprimerMembre(m.id), 'Participant retiré.')
                        }}
                      >
                        Retirer
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h4>Réservations ({reservations.length})</h4>
          {peutGerer && (
            <button className="btn sm" type="button" onClick={() => onEcran({ ecran: 'nouvelle-reservation' })}>Nouvelle réservation</button>
          )}
        </div>
        {reservations.length === 0 ? (
          <p className="empty">Aucune réservation pour ce groupe.</p>
        ) : (
          <table className="tbl">
            <thead><tr><th>Statut</th><th className="num">Effectif</th><th>Créneau</th><th>Paiement</th><th /></tr></thead>
            <tbody>
              {reservations.map((r) => (
                <tr key={r.id}>
                  <td>{badgeStatut(r.status)}</td>
                  <td className="num">{r.effectif}{r.accompagnateurs ? ` (+${r.accompagnateurs})` : ''}</td>
                  <td className="sub">{creneauLabel(creneaux.find((c) => idDe(c) === idDe(r.creneau)) || r.creneau)}</td>
                  <td className="sub">{PAIEMENTS[r.paymentStatus] || r.paymentStatus}</td>
                  <td className="num">
                    <button className="btn ghost sm" type="button" onClick={() => onEcran({ ecran: 'detail', reservation: String(r.id) })}>Détail</button>
                    {peutGerer && r.status !== 'cancelled' && (
                      <>
                        {' '}
                        <button className="btn ghost sm" type="button" onClick={() => onPanier(r.id)}>Panier</button>
                        {' '}
                        <button className="btn ghost sm" type="button" onClick={() => onEcran({ ecran: 'gratuites', reservation: String(r.id) })}>Gratuités</button>
                        {' '}
                        <button className="btn ghost sm" type="button" onClick={() => onEcran({ ecran: 'affecter', reservation: String(r.id) })}>Affecter</button>
                        {' '}
                        <button className="btn ghost sm" type="button" onClick={() => onEcran({ ecran: 'paiement', reservation: String(r.id) })}>Paiement</button>
                        {' '}
                        {r.commercialDocument
                          ? <span className="badge good">Devis</span>
                          : <button className="btn primary sm" type="button" onClick={() => onEcran({ ecran: 'facturer', reservation: String(r.id) })}>Facturer</button>}
                        {r.status === 'option' && (
                          <>
                            {' '}
                            <button className="btn primary sm" type="button" onClick={() => onGeste(() => api.confirmerReservationGroupe(r.id), 'Réservation confirmée.')}>Confirmer</button>
                          </>
                        )}
                        {' '}
                        <button
                          className="btn danger sm"
                          type="button"
                          onClick={async () => {
                            if (!await confirmer('Annuler cette réservation ? C’est définitif.')) return
                            await onGeste(() => api.annulerReservationGroupe(r.id), 'Réservation annulée.')
                          }}
                        >
                          Annuler
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

function FormGroupe({ initial, onFermer, onValider }) {
  const [label, setLabel] = useState(initial?.label || '')
  const [type, setType] = useState(initial?.type || 'school')
  const [organizerName, setOrganizerName] = useState(initial?.organizerName || '')
  const [organizerEmail, setOrganizerEmail] = useState(initial?.organizerEmail || '')
  const [organizerPhone, setOrganizerPhone] = useState(initial?.organizerPhone || '')
  const [headcount, setHeadcount] = useState(String(initial?.headcount ?? ''))
  const [notes, setNotes] = useState(initial?.notes || '')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      await onValider({
        label: label.trim(),
        type,
        organizerName: organizerName.trim(),
        organizerEmail: organizerEmail.trim() || null,
        organizerPhone: organizerPhone.trim() || null,
        headcount: headcount === '' ? 0 : Math.max(0, parseInt(headcount, 10) || 0),
        notes: notes.trim() || null,
      })
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <>
      <h2>{initial ? 'Modifier le groupe' : 'Nouveau groupe'}</h2>
      <form onSubmit={soumettre}>
        <div className="field">
          <label htmlFor="g-label">Nom du groupe *</label>
          <input id="g-label" className="input" value={label} onChange={(e) => setLabel(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="g-type">Type</label>
          <select id="g-type" className="input" value={type} onChange={(e) => setType(e.target.value)}>
            {Object.entries(TYPES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="g-org">Organisateur *</label>
          <input id="g-org" className="input" value={organizerName} onChange={(e) => setOrganizerName(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="g-mail">Courriel</label>
          <input id="g-mail" type="email" className="input" value={organizerEmail} onChange={(e) => setOrganizerEmail(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="g-tel">Téléphone</label>
          <input id="g-tel" className="input" value={organizerPhone} onChange={(e) => setOrganizerPhone(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="g-eff">Effectif prévu</label>
          <input id="g-eff" type="number" min="0" className="input num" value={headcount} onChange={(e) => setHeadcount(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="g-notes">Notes</label>
          <textarea id="g-notes" className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>{initial ? 'Enregistrer' : 'Créer'}</button>
        </div>
      </form>
    </>
  )
}

function FormMembre({ groupe, membreId, onFermer, onValider }) {
  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [category, setCategory] = useState('adult')
  const [envoi, setEnvoi] = useState(false)
  // ⚠ LA LECTURE A QUATRE ISSUES. Sur un échec, le formulaire s'ouvrait vide, catégorie « Adulte » —
  // et l'enregistrer écrasait la vraie catégorie. L'adresse peut aussi désigner le participant d'un
  // autre groupe. 'chargement' · 'lu' · 'introuvable' (404) · 'echec' · 'autre'.
  const [lecture, setLecture] = useState(membreId ? 'chargement' : 'lu')
  const idGroupe = idDe(groupe)

  useEffect(() => {
    if (!membreId) return
    let vivant = true
    // GET item : relire le participant avant de l'éditer.
    api.membreGroupe(membreId)
      .then((m) => {
        if (!vivant) return
        if (idDe(m.group) !== idGroupe) { setLecture('autre'); return }
        setFirstName(m.firstName || '')
        setLastName(m.lastName || '')
        setCategory(m.category || 'adult')
        setLecture('lu')
      })
      .catch((e) => { if (vivant) setLecture(e?.status === 404 ? 'introuvable' : 'echec') })
    return () => { vivant = false }
  }, [membreId, idGroupe])

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      await onValider({
        group: `/api/participant_groups/${idDe(groupe)}`,
        firstName: firstName.trim(),
        lastName: lastName.trim(),
        category,
      }, membreId)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <>
      <h2>{membreId ? 'Modifier le participant' : 'Ajouter un participant'}</h2>
      {lecture === 'chargement' ? (
        <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
      ) : lecture === 'echec' ? (
        <div className="banner banner-error">Ce participant n’a pas pu être lu : le modifier maintenant écraserait ce qu’on n’a pas vu.</div>
      ) : lecture !== 'lu' ? (
        <div className="banner banner-warn">
          {lecture === 'autre'
            ? 'Ce participant appartient à un autre groupe que celui de l’adresse.'
            : 'Ce participant n’existe pas, ou n’est pas visible depuis cet établissement.'}
        </div>
      ) : (
        <form onSubmit={soumettre}>
          {!membreId && (
            <>
              <div className="field">
                <label htmlFor="m-prenom">Prénom *</label>
                <input id="m-prenom" className="input" value={firstName} onChange={(e) => setFirstName(e.target.value)} required />
              </div>
              <div className="field">
                <label htmlFor="m-nom">Nom *</label>
                <input id="m-nom" className="input" value={lastName} onChange={(e) => setLastName(e.target.value)} required />
              </div>
            </>
          )}
          <div className="field">
            <label htmlFor="m-cat">Catégorie</label>
            <select id="m-cat" className="input" value={category} onChange={(e) => setCategory(e.target.value)}>
              {Object.entries(CATEGORIES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div className="modal-actions">
            <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
            <button className="btn primary" type="submit" disabled={envoi}>{membreId ? 'Enregistrer' : 'Ajouter'}</button>
          </div>
        </form>
      )}
    </>
  )
}

function FormReservation({ groupe, onFermer, onValider }) {
  const [effectif, setEffectif] = useState(String(groupe?.headcount ?? ''))
  const [accompagnateurs, setAccompagnateurs] = useState('0')
  const [grain, setGrain] = useState('per_group')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      await onValider({
        group: `/api/participant_groups/${idDe(groupe)}`,
        effectif: effectif === '' ? 0 : Math.max(0, parseInt(effectif, 10) || 0),
        accompagnateurs: accompagnateurs === '' ? 0 : Math.max(0, parseInt(accompagnateurs, 10) || 0),
        grain,
      })
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <>
      <h2>Nouvelle réservation de groupe</h2>
      <form onSubmit={soumettre}>
        <p className="sub">Pour le groupe « {groupe?.label} ». La réservation part en option ; on l’affecte à un créneau ensuite.</p>
        <div className="field">
          <label htmlFor="r-eff">Effectif</label>
          <input id="r-eff" type="number" min="0" className="input num" value={effectif} onChange={(e) => setEffectif(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="r-acc">Accompagnateurs</label>
          <input id="r-acc" type="number" min="0" className="input num" value={accompagnateurs} onChange={(e) => setAccompagnateurs(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="r-grain">Décompte de la jauge</label>
          <select id="r-grain" className="input" value={grain} onChange={(e) => setGrain(e.target.value)}>
            <option value="per_group">Par groupe (un bloc)</option>
            <option value="per_person">Par personne (un billet par visiteur)</option>
          </select>
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>Créer</button>
        </div>
      </form>
    </>
  )
}

function FormAffecter({ creneaux, onFermer, onValider }) {
  // ⚠ CRÉNEAUX À VENIR, DANS L'ORDRE. Lus en entier (#187), ils arrivaient dans le désordre et passés
  // compris : 223 à Piscine A le 15/09/2026, dont 50 passés, et 117 inversions de date dans la liste.
  // Un créneau sans date lisible reste proposé : l'écarter serait décider à la place de l'exploitant.
  // ⚠ `new Date(null)` vaut le 1er janvier 1970, pas une date invalide : une valeur absente se lit NaN.
  const lireDate = (v) => (v ? new Date(v).getTime() : Number.NaN)
  const instant = (c) => lireDate(c?.debut)
  const maintenant = Date.now()
  const passe = (c) => { const fin = lireDate(c?.fin || c?.debut); return !Number.isNaN(fin) && fin < maintenant }
  // Les créneaux sans date lisible viennent en dernier.
  const cle = (c) => (Number.isNaN(instant(c)) ? Number.POSITIVE_INFINITY : instant(c))
  const aVenir = creneaux.filter((c) => !passe(c)).sort((a, b) => (cle(a) === cle(b) ? 0 : cle(a) < cle(b) ? -1 : 1))
  const passes = creneaux.length - aVenir.length
  const [creneau, setCreneau] = useState('')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    if (!creneau) return
    setEnvoi(true)
    try {
      await onValider(creneau)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <>
      <h2>Affecter à un créneau</h2>
      <form onSubmit={soumettre}>
        <div className="field">
          <label htmlFor="a-creneau">Créneau *</label>
          <select id="a-creneau" className="input" value={creneau} onChange={(e) => setCreneau(e.target.value)} required>
            <option value="">— choisir —</option>
            {aVenir.map((c) => (
              <option key={idDe(c)} value={idDe(c)}>{creneauLabel(c)}</option>
            ))}
          </select>
          <p className="sub">
            {aVenir.length === 0
              ? `Aucun créneau à venir${passes ? ` : les ${passes} créneaux de l’établissement sont passés` : ''}.`
              : `${aVenir.length} créneau${aVenir.length > 1 ? 'x' : ''} à venir${passes ? `, ${passes} passé${passes > 1 ? 's' : ''} non proposé${passes > 1 ? 's' : ''}` : ''}.`}
          </p>
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi || !creneau}>Affecter</button>
        </div>
      </form>
    </>
  )
}

function FormPaiement({ valeur, onFermer, onValider }) {
  const [paymentStatus, setPaymentStatus] = useState(valeur || 'pending')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      await onValider(paymentStatus)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <>
      <h2>État de paiement</h2>
      <form onSubmit={soumettre}>
        <div className="field">
          <label htmlFor="p-statut">Paiement</label>
          <select id="p-statut" className="input" value={paymentStatus} onChange={(e) => setPaymentStatus(e.target.value)}>
            {Object.entries(PAIEMENTS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>Enregistrer</button>
        </div>
      </form>
    </>
  )
}

function FormFacture({ taux, onFermer, onValider }) {
  const [tauxTva, setTauxTva] = useState('')
  const [prix, setPrix] = useState('')
  const [envoi, setEnvoi] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    try {
      // Champs optionnels : s'il y a un panier, le devis le reprend et ces valeurs sont ignorées.
      // Sinon (facturation « à la tête »), le taux est requis côté serveur (422 explicite).
      const corps = {}
      if (tauxTva) corps.tauxTva = tauxTva
      if (prix.trim() !== '') corps.prixUnitaireHT = prix.trim()
      await onValider(corps)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <>
      <h2>Facturer — générer un devis</h2>
      <form onSubmit={soumettre}>
        <p className="sub">Le devis part au payeur du groupe et entre dans la chaîne devis → bon de commande → facture (NF525, comptabilité incluse).</p>
        <p className="sub">Si un panier est composé, laissez ces champs vides : le devis reprend le panier, chaque ligne avec sa TVA. Sinon, choisissez un taux (le prix par défaut est le tarif de l’activité).</p>
        <div className="field">
          <label htmlFor="f-tva">Taux de TVA</label>
          <select id="f-tva" className="input" value={tauxTva} onChange={(e) => setTauxTva(e.target.value)}>
            <option value="">— aucun (panier) —</option>
            {taux.map((t) => <option key={idDe(t)} value={idDe(t)}>{t.libelle} ({t.taux} %)</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="f-prix">Prix unitaire HT (facturation à la tête)</label>
          <input id="f-prix" className="input num" value={prix} onChange={(e) => setPrix(e.target.value)} placeholder="ex. 12.00" />
        </div>
        <div className="modal-actions">
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>Générer le devis</button>
        </div>
      </form>
    </>
  )
}

// La fiche d'une réservation : l'écran la lit et vérifie son groupe, ce composant l'affiche.
function DetailReservation({ resa, creneaux }) {
  return (
    <>
      <h2>Réservation de groupe</h2>
      <div className="deflist">
        <div><span>Statut</span><span>{badgeStatut(resa.status)}</span></div>
        <div><span>Effectif</span><span>{resa.effectif}</span></div>
        <div><span>Accompagnateurs</span><span>{resa.accompagnateurs}</span></div>
        <div><span>Paiement</span><span>{PAIEMENTS[resa.paymentStatus] || resa.paymentStatus}</span></div>
        <div><span>Créneau</span><span>{resa.creneau ? creneauLabel(creneaux.find((c) => idDe(c) === idDe(resa.creneau)) || resa.creneau) : '—'}</span></div>
        <div><span>Option jusqu’au</span><span>{resa.optionExpiresAt ? new Date(resa.optionExpiresAt).toLocaleDateString('fr-FR') : '—'}</span></div>
      </div>
    </>
  )
}

// ── Forfaits groupe : gestion des produits composites réutilisables ─────────────────────────────
function FormForfaits({ produits, taux, onFermer, onChange }) {
  const [liste, setListe] = useState([])
  const [chargement, setChargement] = useState(true)
  const [creation, setCreation] = useState(false)
  const [erreur, setErreur] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    try { setListe(membres(await api.groupProducts())) } catch (e) { setListe(null); setErreur(e?.message || 'Lecture impossible.') } finally { setChargement(false) }
  }, [])
  useEffect(() => { recharger() }, [recharger])

  async function supprimer(id) {
    if (!await confirmer('Supprimer ce forfait ?')) return
    setErreur(null)
    try { await api.supprimerGroupProduct(id); await recharger(); onChange?.() } catch (e) { setErreur(e?.message || 'Suppression impossible.') }
  }
  async function basculer(f) {
    setErreur(null)
    try { await api.modifierGroupProduct(idDe(f), { actif: !f.actif }); await recharger(); onChange?.() } catch (e) { setErreur(e?.message || 'Modification impossible.') }
  }

  return (
    <>
      <h2>Forfaits groupe</h2>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {creation ? (
        <FormForfait
          produits={produits}
          taux={taux}
          onAnnuler={() => setCreation(false)}
          onEnregistre={async () => { setCreation(false); await recharger(); onChange?.() }}
        />
      ) : (
        <>
          <p className="sub">Un forfait est un mix de produits réutilisable (p. ex. 3 entrées + 5 audioguides + 5 visites) qu’on applique à une réservation.</p>
          <div className="actions">
            <button className="btn primary sm" type="button" onClick={() => setCreation(true)}>Nouveau forfait</button>
          </div>
          {chargement ? (
            <div className="center"><div className="spinner" /></div>
          ) : liste === null ? (
            <div className="banner banner-error">Les forfaits n’ont pas pu être lus : ce n’est pas la même chose que « aucun forfait ».</div>
          ) : liste.length === 0 ? (
            <p className="empty">Aucun forfait pour l’instant.</p>
          ) : (
            <table className="tbl">
              <thead><tr><th>Forfait</th><th className="num">Lignes</th><th>État</th><th /></tr></thead>
              <tbody>
                {liste.map((f) => (
                  <tr key={f.id}>
                    <td>{f.label}</td>
                    <td className="num">{(f.lines || []).length}</td>
                    <td>{f.actif ? <span className="badge good">Actif</span> : <span className="badge mut">Inactif</span>}</td>
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => basculer(f)}>{f.actif ? 'Désactiver' : 'Activer'}</button>
                      {' '}
                      <button className="btn danger sm" type="button" onClick={() => supprimer(idDe(f))}>Supprimer</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </>
      )}
    </>
  )
}

function FormForfait({ produits, taux, onAnnuler, onEnregistre }) {
  const ligneVide = { produit: '', quantite: '1', prixUnitaireHT: '', tauxTva: '' }
  const [label, setLabel] = useState('')
  const [lignes, setLignes] = useState([{ ...ligneVide }])
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)

  const majLigne = (i, champ, val) => setLignes((ls) => ls.map((l, k) => (k === i ? { ...l, [champ]: val } : l)))

  async function soumettre(e) {
    e.preventDefault()
    const valides = lignes.filter((l) => l.produit && l.tauxTva)
    if (label.trim() === '' || valides.length === 0) { setErreur('Un nom et au moins une ligne (produit + TVA) sont requis.'); return }
    setEnvoi(true); setErreur(null)
    try {
      await api.creerGroupProduct({
        label: label.trim(),
        lines: valides.map((l) => ({
          produit: `/api/produits/${l.produit}`,
          quantite: Math.max(1, parseInt(l.quantite, 10) || 1),
          prixUnitaireHT: l.prixUnitaireHT.trim() === '' ? '0.00' : l.prixUnitaireHT.trim(),
          tauxTva: `/api/taux_tvas/${l.tauxTva}`,
        })),
      })
      await onEnregistre()
    } catch (e) { setErreur(e?.message || 'Création impossible.') } finally { setEnvoi(false) }
  }

  return (
    <form onSubmit={soumettre}>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <div className="field">
        <label htmlFor="ff-label">Nom du forfait *</label>
        <input id="ff-label" className="input" value={label} onChange={(e) => setLabel(e.target.value)} required />
      </div>
      <h4>Lignes du forfait</h4>
      <div style={{ overflowX: 'auto' }}>
        <table className="tbl">
          <thead><tr><th>Produit</th><th className="num">Qté</th><th className="num">PU HT</th><th>TVA</th><th /></tr></thead>
          <tbody>
            {lignes.map((l, i) => (
              <tr key={i}>
                <td>
                  <select className="input" value={l.produit} onChange={(e) => majLigne(i, 'produit', e.target.value)}>
                    <option value="">— produit —</option>
                    {produits.map((p) => <option key={idDe(p)} value={idDe(p)}>{p.libelleRecherche || idDe(p)}</option>)}
                  </select>
                </td>
                <td className="num"><input className="input num" type="number" min="1" value={l.quantite} onChange={(e) => majLigne(i, 'quantite', e.target.value)} /></td>
                <td className="num"><input className="input num" value={l.prixUnitaireHT} onChange={(e) => majLigne(i, 'prixUnitaireHT', e.target.value)} placeholder="0.00" /></td>
                <td>
                  <select className="input" value={l.tauxTva} onChange={(e) => majLigne(i, 'tauxTva', e.target.value)}>
                    <option value="">— TVA —</option>
                    {taux.map((t) => <option key={idDe(t)} value={idDe(t)}>{t.taux} %</option>)}
                  </select>
                </td>
                <td className="num"><button className="btn danger sm" type="button" onClick={() => setLignes((ls) => ls.filter((_, k) => k !== i))}>×</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="actions">
        <button className="btn ghost sm" type="button" onClick={() => setLignes((ls) => [...ls, { ...ligneVide }])}>Ajouter une ligne</button>
      </div>
      <div className="modal-actions">
        <button className="btn ghost" type="button" onClick={onAnnuler}>Annuler</button>
        <button className="btn primary" type="submit" disabled={envoi}>Créer le forfait</button>
      </div>
    </form>
  )
}

// ── Panier d'une réservation : forfait appliqué et/ou lignes à la carte ─────────────────────────
function FormPanier({ reservationId, forfaits, produits, taux, onFermer, listesIllisibles = [] }) {
  const vide = { produit: '', quantite: '1', prixUnitaireHT: '', tauxTva: '' }
  const [items, setItems] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [forfaitChoisi, setForfaitChoisi] = useState('')
  const [nouveau, setNouveau] = useState({ ...vide })

  const recharger = useCallback(async () => {
    setChargement(true)
    // ⚠ Un échec de lecture n'est pas un panier vide : `items` passe à null, et l'écran le dit.
    try { setItems(membres(await api.articlesReservation(reservationId))) } catch (e) { setItems(null); setErreur(e?.message || 'Lecture impossible.') } finally { setChargement(false) }
  }, [reservationId])
  useEffect(() => { recharger() }, [recharger])

  const nomProduit = (iri) => produits.find((p) => idDe(p) === idDe(iri))?.libelleRecherche || '—'
  const libTaux = (iri) => { const t = taux.find((x) => idDe(x) === idDe(iri)); return t ? `${t.taux} %` : '—' }

  async function appliquer() {
    if (!forfaitChoisi) return
    setErreur(null)
    try { await api.appliquerForfait(reservationId, { groupProduct: `/api/group_products/${forfaitChoisi}` }); setForfaitChoisi(''); await recharger() } catch (e) { setErreur(e?.message || 'Application impossible.') }
  }
  async function ajouter() {
    if (!nouveau.produit || !nouveau.tauxTva) { setErreur('Produit et TVA requis.'); return }
    setErreur(null)
    try {
      await api.ajouterArticle({
        booking: `/api/group_bookings/${reservationId}`,
        produit: `/api/produits/${nouveau.produit}`,
        quantite: Math.max(1, parseInt(nouveau.quantite, 10) || 1),
        prixUnitaireHT: nouveau.prixUnitaireHT.trim() === '' ? '0.00' : nouveau.prixUnitaireHT.trim(),
        tauxTva: `/api/taux_tvas/${nouveau.tauxTva}`,
      })
      setNouveau({ ...vide })
      await recharger()
    } catch (e) { setErreur(e?.message || 'Ajout impossible.') }
  }

  return (
    <>
      <h2>Panier de la réservation</h2>
      {listesIllisibles.length > 0 && (
        <div className="banner banner-warn">
          Lecture impossible : {listesIllisibles.join(', ')}. Les listes de choix correspondantes sont vides pour cette raison, pas faute d’éléments.
        </div>
      )}
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <div className="field">
        <label htmlFor="pa-forfait">Appliquer un forfait</label>
        <div className="actions">
          <select id="pa-forfait" className="input" value={forfaitChoisi} onChange={(e) => setForfaitChoisi(e.target.value)}>
            <option value="">— choisir un forfait —</option>
            {forfaits.filter((f) => f.actif).map((f) => <option key={idDe(f)} value={idDe(f)}>{f.label}</option>)}
          </select>
          <button className="btn sm" type="button" onClick={appliquer} disabled={!forfaitChoisi}>Appliquer</button>
        </div>
      </div>

      <h4>{items ? `Articles (${items.length})` : 'Articles'}</h4>
      {chargement ? (
        <div className="center"><div className="spinner" /></div>
      ) : items === null ? (
        <div className="banner banner-error">
          Les articles de cette réservation n’ont pas pu être lus : ce n’est pas un panier vide.{' '}
          <button className="btn ghost sm" type="button" onClick={recharger}>Réessayer</button>
        </div>
      ) : items.length === 0 ? (
        <p className="empty">Panier vide. Appliquez un forfait ou ajoutez des produits ci-dessous.</p>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead><tr><th>Produit</th><th className="num">Qté</th><th className="num">PU HT</th><th>TVA</th><th>Origine</th><th /></tr></thead>
            <tbody>
              {items.map((it) => (
                <tr key={it.id}>
                  <td>{nomProduit(it.produit)}</td>
                  <td className="num">
                    <input
                      className="input num" type="number" min="1" defaultValue={it.quantite} style={{ width: '4.5rem' }}
                      onBlur={async (e) => { try { await api.modifierArticle(it.id, { quantite: Math.max(1, parseInt(e.target.value, 10) || 1) }); await recharger() } catch (err) { setErreur(err?.message || 'Modification impossible.') } }}
                    />
                  </td>
                  <td className="num">{it.prixUnitaireHT}</td>
                  <td>{libTaux(it.tauxTva)}</td>
                  <td className="sub">{it.source ? 'forfait' : 'à la carte'}</td>
                  <td className="num"><button className="btn danger sm" type="button" onClick={async () => { try { await api.supprimerArticle(it.id); await recharger() } catch (err) { setErreur(err?.message || 'Suppression impossible.') } }}>Retirer</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <h4>Ajouter un produit</h4>
      <div style={{ overflowX: 'auto' }}>
        <table className="tbl">
          <tbody>
            <tr>
              <td>
                <select className="input" value={nouveau.produit} onChange={(e) => setNouveau((n) => ({ ...n, produit: e.target.value }))}>
                  <option value="">— produit —</option>
                  {produits.map((p) => <option key={idDe(p)} value={idDe(p)}>{p.libelleRecherche || idDe(p)}</option>)}
                </select>
              </td>
              <td className="num"><input className="input num" type="number" min="1" value={nouveau.quantite} onChange={(e) => setNouveau((n) => ({ ...n, quantite: e.target.value }))} /></td>
              <td className="num"><input className="input num" value={nouveau.prixUnitaireHT} onChange={(e) => setNouveau((n) => ({ ...n, prixUnitaireHT: e.target.value }))} placeholder="0.00" /></td>
              <td>
                <select className="input" value={nouveau.tauxTva} onChange={(e) => setNouveau((n) => ({ ...n, tauxTva: e.target.value }))}>
                  <option value="">— TVA —</option>
                  {taux.map((t) => <option key={idDe(t)} value={idDe(t)}>{t.taux} %</option>)}
                </select>
              </td>
              <td className="num"><button className="btn sm" type="button" onClick={ajouter}>Ajouter</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="modal-actions">
        <button className="btn ghost" type="button" onClick={onFermer}>Retour au groupe</button>
      </div>
    </>
  )
}

// ── Gratuités transverses : contingents (enveloppes réutilisables) ──────────────────────────────
function FormContingents({ onFermer, onChange }) {
  const [liste, setListe] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [label, setLabel] = useState('')
  const [quota, setQuota] = useState('')

  const recharger = useCallback(async () => {
    setChargement(true)
    try { setListe(membres(await api.gratuiteContingents())) } catch (e) { setListe(null); setErreur(e?.message || 'Lecture impossible.') } finally { setChargement(false) }
  }, [])
  useEffect(() => { recharger() }, [recharger])

  async function creer(e) {
    e.preventDefault()
    if (label.trim() === '') return
    setErreur(null)
    try {
      await api.creerGratuiteContingent({ label: label.trim(), quota: quota.trim() === '' ? 0 : Math.max(0, parseInt(quota, 10) || 0) })
      setLabel(''); setQuota(''); await recharger(); onChange?.()
    } catch (e) { setErreur(e?.message || 'Création impossible.') }
  }
  async function supprimer(id) {
    if (!await confirmer('Supprimer ce contingent ?')) return
    try { await api.supprimerGratuiteContingent(id); await recharger(); onChange?.() } catch (e) { setErreur(e?.message || 'Suppression impossible.') }
  }
  async function basculer(c) {
    try { await api.modifierGratuiteContingent(idDe(c), { actif: !c.actif }); await recharger(); onChange?.() } catch (e) { setErreur(e?.message || 'Modification impossible.') }
  }

  return (
    <>
      <h2>Contingents de gratuité</h2>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <p className="sub">Des enveloppes de gratuités réutilisables (scolaires, partenaires…) qu'on accorde ensuite à une réservation depuis sa fiche « Gratuités ».</p>
      <form onSubmit={creer}>
        <div className="field">
          <label htmlFor="ct-label">Nouveau contingent</label>
          <input id="ct-label" className="input" value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Nom (ex. Scolaires ville)" />
        </div>
        <div className="field">
          <label htmlFor="ct-quota">Quota</label>
          <input id="ct-quota" className="input num" type="number" min="0" value={quota} onChange={(e) => setQuota(e.target.value)} placeholder="0" />
        </div>
        <div className="actions">
          <button className="btn primary sm" type="submit">Créer le contingent</button>
        </div>
      </form>
      {chargement ? (
        <div className="center"><div className="spinner" /></div>
      ) : liste === null ? (
        <div className="banner banner-error">Les contingents n’ont pas pu être lus : ce n’est pas la même chose que « aucun contingent ».</div>
      ) : liste.length === 0 ? (
        <p className="empty">Aucun contingent.</p>
      ) : (
        <table className="tbl">
          <thead><tr><th>Contingent</th><th className="num">Quota</th><th className="num">Restant</th><th>État</th><th /></tr></thead>
          <tbody>
            {liste.map((c) => (
              <tr key={c.id}>
                <td>{c.label}</td>
                <td className="num">{c.quota}</td>
                <td className="num">{c.placesRestantes}</td>
                <td>{c.actif ? <span className="badge good">Actif</span> : <span className="badge mut">Inactif</span>}</td>
                <td className="num">
                  <button className="btn ghost sm" type="button" onClick={() => basculer(c)}>{c.actif ? 'Désactiver' : 'Activer'}</button>
                  {' '}
                  <button className="btn danger sm" type="button" onClick={() => supprimer(idDe(c))}>Supprimer</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <div className="modal-actions">
        <button className="btn ghost" type="button" onClick={onFermer}>Retour aux groupes</button>
      </div>
    </>
  )
}

// ── Gratuités d'une réservation : octroi depuis un contingent, révocation ───────────────────────
function FormGratuites({ reservationId, contingents, onFermer, onChange }) {
  const [liste, setListe] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [contingent, setContingent] = useState('')
  const [quantite, setQuantite] = useState('1')
  const [motif, setMotif] = useState('')

  const recharger = useCallback(async () => {
    setChargement(true)
    try { setListe(membres(await api.gratuitesReservation(reservationId))) } catch (e) { setListe(null); setErreur(e?.message || 'Lecture impossible.') } finally { setChargement(false) }
  }, [reservationId])
  useEffect(() => { recharger() }, [recharger])

  const nomContingent = (ref) => contingents.find((c) => idDe(c) === idDe(ref))?.label || '—'

  async function accorder(e) {
    e.preventDefault()
    if (!contingent) return
    setErreur(null)
    try {
      await api.accorderGratuite(reservationId, {
        contingent: `/api/group_gratuite_contingents/${contingent}`,
        quantite: Math.max(1, parseInt(quantite, 10) || 1),
        motif: motif.trim() || null,
      })
      setMotif(''); setQuantite('1'); await recharger(); onChange?.()
    } catch (e) { setErreur(e?.message || 'Octroi impossible.') }
  }
  async function revoquer(id) {
    try { await api.revoquerGratuite(id); await recharger(); onChange?.() } catch (e) { setErreur(e?.message || 'Révocation impossible.') }
  }

  return (
    <>
      <h2>Gratuités de la réservation</h2>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <p className="sub">Les entrées gratuites sortent du décompte payant du devis.</p>
      <form onSubmit={accorder}>
        <div className="field">
          <label htmlFor="gr-cont">Accorder depuis un contingent</label>
          <select id="gr-cont" className="input" value={contingent} onChange={(e) => setContingent(e.target.value)}>
            <option value="">— contingent —</option>
            {contingents.filter((c) => c.actif && c.placesRestantes > 0).map((c) => (
              <option key={idDe(c)} value={idDe(c)}>{c.label} ({c.placesRestantes} restantes)</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="gr-q">Nombre de gratuités</label>
          <input id="gr-q" className="input num" type="number" min="1" value={quantite} onChange={(e) => setQuantite(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="gr-motif">Motif (optionnel)</label>
          <input id="gr-motif" className="input" value={motif} onChange={(e) => setMotif(e.target.value)} placeholder="ex. élèves" />
        </div>
        <div className="actions">
          <button className="btn primary sm" type="submit" disabled={!contingent}>Accorder</button>
        </div>
      </form>
      <h4>Gratuités accordées</h4>
      {chargement ? (
        <div className="center"><div className="spinner" /></div>
      ) : liste === null ? (
        <div className="banner banner-error">Les gratuités de cette réservation n’ont pas pu être lues : ce n’est pas la même chose que « aucune gratuité ».</div>
      ) : liste.length === 0 ? (
        <p className="empty">Aucune gratuité accordée.</p>
      ) : (
        <table className="tbl">
          <thead><tr><th>Contingent</th><th className="num">Nombre</th><th>Motif</th><th /></tr></thead>
          <tbody>
            {liste.map((g) => (
              <tr key={g.id}>
                <td>{g.contingent?.label ?? nomContingent(g.contingent)}</td>
                <td className="num">{g.quantite}</td>
                <td className="sub">{g.motif || '—'}</td>
                <td className="num"><button className="btn danger sm" type="button" onClick={() => revoquer(g.id)}>Révoquer</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <div className="modal-actions">
        <button className="btn ghost" type="button" onClick={onFermer}>Retour au groupe</button>
      </div>
    </>
  )
}
