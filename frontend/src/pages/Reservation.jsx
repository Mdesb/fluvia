import { useEffect, useMemo, useState, useCallback } from 'react'
import { jourLocal } from '../components/Liste.jsx'
import { api, membres } from '../api/client.js'
import PlanningSemaine from '../components/PlanningSemaine.jsx'
import Disponibilites from '../components/Disponibilites.jsx'
import PriseRendezVous from '../components/PriseRendezVous.jsx'
import Tabs from '../components/Tabs.jsx'
import { euros } from '../api/produit.js'
import { aLeDroit } from '../api/droits.js'
import NoShowSection from '../components/NoShowSection.jsx'
import { idDe as idDepuisIri } from '../api/iri'

// --- Helpers de lecture (structures API Platform / module Réservation) ---

function court(id) {
  return id ? String(id).slice(0, 8) : '—'
}

function jourCle(v) {
  if (!v) return ''
  // Fuseau local, pas UTC : un créneau de 00 h 30 en été était rangé la veille.
  return jourLocal(v)
}

function jourLabel(cle) {
  const d = new Date(cle + 'T00:00:00')
  return d.toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' })
}

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function labelBeneficiaire(b) {
  const role = b.role ? b.role.charAt(0).toUpperCase() + b.role.slice(1) : 'Bénéficiaire'
  return `${role} · client ${court(idDepuisIri(b.client))}`
}

// Écran Réservation / Planning (M5) : ressources, créneaux (capacité vs réservations) et
// réservation d'un créneau (unique écriture de cet écran).
// ⚠ CE QUI OCCUPE UNE PLACE, ET NON CE QUI N'EST PAS ANNULÉ.
//
// L'occupation excluait `r.statut === 'annulee'`. `StatutReservation` ne contient pas `'annulee'` :
// ses valeurs sont `confirmee`, `liste_attente`, `annulee_libre`, `annulee_tardive_facturee`,
// `no_show_facture`, `honoree`. Ce filtre n'a donc jamais rien exclu.
//
// C'était invisible tant que RIEN ne pouvait annuler une réservation — précisément le défaut qu'on
// corrige dans le même lot. Le bouton posé, une réservation annulée aurait continué de compter
// contre la jauge : créneau affiché complet avec une place libre, et personne pour relier le
// comptage faux au bouton neuf.
//
// On n'a donc pas corrigé la chaîne, on a inversé la règle. Le serveur dit déjà ce qui occupe —
// `StatutReservation::occupePlace()` rend vrai pour `confirmee` et `honoree`, et rien d'autre.
// Compter positivement reste juste quels que soient les statuts à venir ; une liste d'exclusions
// redevient fausse au premier statut ajouté.
// ⚠ `a_confirmer` OCCUPE AUSSI. Une place réservée est prise tant qu'elle n'a pas expiré :
// « non confirmée » ne veut pas dire « libre », ça veut dire « pas encore payée ». L'oublier
// afficherait un créneau libre qui ne l'est pas, et le ferait vendre deux fois.
const STATUTS_QUI_OCCUPENT = new Set(['a_confirmer', 'confirmee', 'honoree'])

// Une date ISO vers la valeur d'un `<input type="datetime-local">`, EN HEURE LOCALE.
//
// ⚠ PAS `toISOString().slice(0, 16)`, qui est le réflexe et qui est faux : il rend l'heure UTC, donc
// une séance de 10 h s'afficherait à 8 h en été. Le garde-fou des dates locales refuse d'ailleurs
// cette troncature. On compose à la main depuis les accesseurs locaux.
function pourSaisieLocale(iso) {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

// ⚠ D'OÙ VIENT LA PRÉSENCE — ET NON PAS SEULEMENT QU'ELLE EST ACQUISE.
//
// `SourcePresence` déclare deux valeurs. `emargement_manuel` est écrit par cet écran ;
// `passage_acces` est prévu pour le contrôle d'accès et n'est produit par personne aujourd'hui —
// la chaîne existe pourtant en entier (`Passage → DroitAcces → reservationRef → Reservation`), et
// un droit d'accès de type `booking` porte déjà une réservation en base. Il manque un écouteur,
// pas une structure (relevé par allaccess-b8).
//
// L'écran affiche donc la source dès maintenant, y compris celle que rien ne produit encore. La
// raison n'est pas l'anticipation : c'est que le moment où l'on ouvrira cette liste est celui d'une
// contestation de facture d'absence, et « présent (tourniquet, 14h02) » ne se défend pas comme
// « présent (émargé à la main) ». Une présence sans provenance oblige à croire quelqu'un sur parole.
// LES TROIS ÉTATS DE PAIEMENT D'UNE PART, ET L'ÉCRAN N'EN DISAIT QUE DEUX.
//
// `impute_organisateur` existe depuis toujours — `BasculerNoShowCommand` y bascule les parts non
// réglées d'un no-show — et il tombait dans le « En attente » du `else`. Un joueur qui ne doit
// rien s'affichait donc comme un impayé, avec un bouton pour régler sa part.
//
// ⚠ Depuis l'arbitrage de Maxime sur R15 (b), c'est le cas NORMAL d'une partie de padel :
// l'organisateur est redevable du montant global, les autres ne doivent rien.
const LIBELLE_PART = {
  paye: 'Payé',
  en_attente: 'En attente',
  impute_organisateur: 'À la charge de l’organisateur',
}

const BADGE_PART = {
  paye: 'good',
  en_attente: 'mut',
  impute_organisateur: 'info',
}

const LIBELLE_SOURCE = {
  emargement_manuel: 'émargé à la main',
  passage_acces: 'tourniquet',
}

// La valeur brute (`annulee_tardive_facturee`) est un identifiant, pas une phrase : affichée telle
// quelle au comptoir, elle se lit comme un défaut de l'écran.
// Le statut d'une inscription en liste d'attente. `promue` est le seul qui demande un geste : une
// place a été proposée, et la fenêtre d'acceptation court.
const LIBELLE_ATTENTE = {
  en_attente: 'En attente',
  promue: 'Place proposée',
  expiree: 'Proposition expirée',
  annulee: 'Annulée',
}

const LIBELLE_STATUT = {
  // ⚠ « À confirmer » OCCUPE LE CRÉNEAU (voir `STATUTS_QUI_OCCUPENT`). Le libellé doit donc dire
  // l'attente, pas la disponibilité : une place réservée est prise tant qu'elle n'a pas expiré.
  a_confirmer: 'À confirmer',
  confirmee: 'Confirmée',
  liste_attente: 'Liste d’attente',
  annulee_libre: 'Annulée',
  annulee_tardive_facturee: 'Annulée hors délai, facturée',
  no_show_facture: 'Absence facturée',
  honoree: 'Honorée',
}

export default function Reservation({ etabActif, droits = [], session }) {
  // ⚠ `null` = PAS LU. << 0 ressource(s) · 0 créneau(x) >> se lit << ce site n'a rien de
  // reservable >>, ce qui fait refuser une reservation au telephone.
  const [ressources, setRessources] = useState(null)
  const [creneaux, setCreneaux] = useState(null)
  const [reservations, setReservations] = useState([])
  const [beneficiaires, setBeneficiaires] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [vue, setVue] = useState('semaine')
  const [jour, setJour] = useState('')
  const [reserverPour, setReserverPour] = useState(null) // id du créneau en cours de réservation
  const [inscritsPour, setInscritsPour] = useState(null) // id du créneau dont on déplie les inscrits
  const [gesteEnCours, setGesteEnCours] = useState(null) // id de la réservation en cours de geste

  // ⚠ LES DROITS DE L'API, PAS D'AUTRES. `/emarger` exige `reservation.emarger`, `/annuler` exige
  // `reservation.annuler`. Un geste caché derrière un droit différent produirait soit un bouton qui
  // refuse au clic, soit une fonction cachée à quelqu'un qui y a droit.
  //
  // On n'expose PAS `reservation.annuler_soi` ici : ce droit-là est celui du client qui annule sa
  // propre réservation, et il vient avec un refus explicite hors délai franc. Le confondre avec
  // celui de l'agent — qui, lui, peut qualifier une annulation tardive — écraserait la distinction
  // que le serveur tient dans sa règle de sécurité.
  const peutEmarger = aLeDroit(droits, 'reservation.emarger')
  const peutAnnuler = aLeDroit(droits, 'reservation.annuler')
  // Ajouter un participant et encaisser sa part demandent tous deux `reservation.reserver` côté
  // serveur : c'est un acte de comptoir, pas une administration.
  const peutPartager = aLeDroit(droits, 'reservation.reserver')
  const peutGererCreneau = aLeDroit(droits, 'reservation.gerer_creneau')
  // Droit distinct de `gerer_creneau` : trancher un conflit de récurrence engage le planning de
  // plusieurs personnes, et le serveur le sépare. Le confondre ici offrirait un bouton qui refuse.
  const peutArbitrer = aLeDroit(droits, 'reservation.arbitrer_recurrence')

  const [arbitragePour, setArbitragePour] = useState(null)
  const [arbitrageRessource, setArbitrageRessource] = useState('')

  const [modifierPour, setModifierPour] = useState(null)
  const [modifDebut, setModifDebut] = useState('')
  const [modifFin, setModifFin] = useState('')
  const [modifRessource, setModifRessource] = useState('')

  const [listesAttente, setListesAttente] = useState([])
  const [attentePour, setAttentePour] = useState(null) // id du créneau dont on ouvre la liste d'attente
  const [attenteBeneficiaire, setAttenteBeneficiaire] = useState('')
  const [attenteQuantite, setAttenteQuantite] = useState('1')

  const [partsPour, setPartsPour] = useState(null) // id de la réservation dont on ouvre les parts
  const [nouvellePersonne, setNouvellePersonne] = useState('')
  const [nouvellePart, setNouvellePart] = useState('')
  const [organisateur, setOrganisateur] = useState('')
  const [enCours, setEnCours] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [rc, cc, rvc, bc, lac] = await Promise.all([
        api.reservationRessources(),
        api.reservationCreneaux(),
        api.reservations(),
        api.beneficiaires(),
        api.reservationListesAttente(),
      ])
      setRessources(membres(rc))
      setCreneaux(membres(cc))
      setReservations(membres(rvc))
      setBeneficiaires(membres(bc))
      setListesAttente(membres(lac))
    } catch (e) {
      setErreur(e.message || 'Chargement du planning impossible.')
      setRessources(null)
      setCreneaux(null)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    setSucces(null)
    setReserverPour(null)
    recharger()
  }, [etabActif, recharger])

  // Occupation : voir `STATUTS_QUI_OCCUPENT` ci-dessus — on compte ce qui prend une place, on
  // n'exclut pas ce qui n'en prend plus.
  //
  // ⚠ ET ON COMPTE `quantity`, PAS LES LIGNES. Une réservation peut porter plusieurs places
  // (`AnnulerReservationProcessor` rend `$data->getQuantity()` à la jauge, « on rend exactement ce
  // qui avait été pris, pas une unité »). Compter une ligne pour une réservation de quatre
  // affichait trois places libres de trop, et laissait sur-réserver.
  const occupation = useMemo(() => {
    const m = {}
    for (const r of reservations) {
      if (!STATUTS_QUI_OCCUPENT.has(r.statut)) continue
      const cid = idDepuisIri(r.creneau)
      if (cid) m[cid] = (m[cid] || 0) + (r.quantity ?? 1)
    }
    return m
  }, [reservations])

  // Les instances d'un type de ressource — « la chambre 214 » sous « chambre double ».
  //
  // ⚠ CALCULÉ ICI ET NON DEMANDÉ AU SERVEUR : la liste des ressources est déjà chargée, et
  // `ressourceMere` y figure. Une requête de plus par réservation affichée aurait multiplié les
  // appels sans rien apprendre de neuf.
  const instancesParType = useMemo(() => {
    const m = {}
    // `|| []` comme pour `creneaux` : `ressources` vaut `null` tant que la lecture n'a pas
    // abouti, et un `useMemo` execute son corps des le premier rendu.
    for (const r of ressources || []) {
      const mere = idDepuisIri(r.ressourceMere)
      if (mere) (m[mere] ||= []).push(r)
    }
    return m
  }, [ressources])

  // ⚠ LE LIBELLÉ D'UNE RESSOURCE AFFECTÉE NE VIENT PAS DE LA RÉSERVATION, ET C'EST MESURÉ.
  //
  // `Ressource::$libelle` est dans les groupes `ressource:read` et `creneau:read` — pas dans
  // `reservation:read`. La ressource affectée, sérialisée sous ce groupe-là, ne porte donc que son
  // identifiant : écrire `r.ressourceAffectee.libelle` affichait « Affectée : undefined », sur
  // l'écran qui sert précisément à savoir quelle chambre a été donnée.
  //
  // Aucun garde-fou n'attrape ça : le champ existe, la relation existe, le groupe existe. C'est la
  // combinaison qui manque, et elle ne se voit qu'à l'affichage.
  //
  // On résout donc depuis la liste des ressources, déjà chargée par cette page. L'autre sortie —
  // ajouter `reservation:read` aux groupes de `libelle` — alourdirait la charge utile de TOUTES les
  // lectures de réservation, partout, pour un seul écran.
  const libelleRessource = useCallback(
    (reference) => {
      const id = idDepuisIri(reference)
      if (!id) return null
      // Appele pendant le rendu des lignes : `ressources` peut encore valoir `null`.
      return ressources?.find((r) => r.id === id)?.libelle || null
    },
    [ressources],
  )

  // Les ressources qu'on peut proposer en remplacement : même type, capacité suffisante, et pas
  // celle qui pose problème.
  //
  // ⚠ MÊMES CRITÈRES QUE `RecurrenceReportHandler` CÔTÉ SERVEUR — même type, capacité ≥ — pour que
  // l'écran propose ce que le domaine considère équivalent. Le serveur, lui, ne vérifie que le
  // cloisonnement et la disponibilité : proposer n'importe quoi ici ferait donc passer des
  // remplacements que personne n'a jugés équivalents.
  const ressourcesEquivalentes = useMemo(() => {
    const m = {}
    // Meme garde : `ressources` est `null` avant la premiere lecture. Le `.filter` interne
    // n'en a pas besoin — il ne s'execute que si la boucle tourne.
    for (const source of ressources || []) {
      m[source.id] = ressources.filter(
        (r) =>
          r.id !== source.id
          && r.codeType === source.codeType
          && (r.capacitePropre ?? 0) >= (source.capacitePropre ?? 0)
          && r.actif !== false,
      )
    }
    return m
  }, [ressources])

  // Les inscriptions en attente d'un créneau, dans l'ordre du rang — celui que le SERVEUR a posé.
  //
  // ⚠ ON N'AFFICHE QUE CE QUI ATTEND OU A ÉTÉ PROMU. Une inscription expirée ou annulée reste en
  // base, et c'est bien — c'est l'historique de qui a demandé quoi. Mais la faire figurer dans la
  // file donnerait un rang à quelqu'un qui n'attend plus, et l'exploitant appellerait quelqu'un qui
  // a déjà renoncé.
  const attenteParCreneau = useMemo(() => {
    const m = {}
    for (const l of listesAttente) {
      if (l.statut !== 'en_attente' && l.statut !== 'promue') continue
      const cid = idDepuisIri(l.creneau)
      if (!cid) continue
      ;(m[cid] ||= []).push(l)
    }
    for (const file of Object.values(m)) file.sort((a, b) => a.rang - b.rang)
    return m
  }, [listesAttente])

  // Les réservations d'un créneau, du plus ancien au plus récent — l'ordre d'inscription est celui
  // qu'on suit quand on émarge une liste à l'entrée.
  const inscritsParCreneau = useMemo(() => {
    const m = {}
    for (const r of reservations) {
      const cid = idDepuisIri(r.creneau)
      if (!cid) continue
      ;(m[cid] ||= []).push(r)
    }
    for (const liste of Object.values(m)) {
      liste.sort((a, b) => new Date(a.dateCreation) - new Date(b.dateCreation))
    }
    return m
  }, [reservations])

  // Jours distincts présents dans les créneaux.
  const jours = useMemo(() => {
    const set = new Set((creneaux || []).map((c) => jourCle(c.debut)).filter(Boolean))
    return [...set].sort()
  }, [creneaux])

  useEffect(() => {
    if (jours.length && !jours.includes(jour)) setJour(jours[0])
  }, [jours, jour])

  const creneauxJour = useMemo(
    () =>
      (creneaux || [])
        .filter((c) => jourCle(c.debut) === jour)
        .sort((a, b) => new Date(a.debut) - new Date(b.debut)),
    [creneaux, jour],
  )

  // ── ÉMARGER ────────────────────────────────────────────────────────────────────────────────
  //
  // ⚠ CE GESTE EST LA SEULE ÉCRITURE DE `presenceConfirmee` DANS TOUT LE LOGICIEL. Mesuré :
  // `setPresenceConfirmee` et `confirmerPresence` n'ont qu'un appelant hors de l'entité,
  // `EmargerProcessor`, et aucune écriture SQL directe de la colonne n'existe.
  //
  // Ce que ça implique tant que personne n'émarge : `BasculerNoShowCommand` fait, pour chaque
  // réservation encore confirmée après le créneau, `isPresenceConfirmee() ? Honoree :
  // NoShowFacture`. Le drapeau étant faux pour TOUTE réservation ayant jamais existé, la branche
  // `Honoree` est du code mort — et un créneau de vingt personnes toutes venues produirait vingt
  // factures d'absence le jour où l'ordonnanceur lancerait cette tâche.
  //
  // Émarger n'est donc pas un confort de gestion : c'est la seule chose entre l'ordonnanceur et une
  // facture envoyée à chaque client qui s'est présenté.
  async function emarger(reservation, statut) {
    setGesteEnCours(reservation.id)
    setErreur(null)
    setSucces(null)
    try {
      await api.emargerReservation(reservation.id, statut)
      setSucces(statut === 'present' ? 'Présence enregistrée.' : 'Absence enregistrée.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’émargement n’a pas abouti.')
    } finally {
      setGesteEnCours(null)
    }
  }

  // ── ANNULER ────────────────────────────────────────────────────────────────────────────────
  //
  // ⚠ L'ÉCRAN NE DÉCIDE RIEN, ET C'EST VOULU. `AnnulerReservationProcessor` tient toute la règle :
  // dans le délai franc, l'annulation est libre — place rendue, crédit restitué, avoir émis si la
  // vente était validée, liste d'attente promue. Hors délai, le serveur refuse en libre-service, et
  // seul `reservation.annuler` permet de qualifier l'issue en annulation tardive facturée
  // (RG-M5-09).
  //
  // On n'écrit donc aucune condition ici et on affiche le refus du serveur tel quel : un second
  // énoncé de la règle divergerait du premier le jour où elle bouge, et c'est celui de l'écran
  // qu'on croirait.
  async function annuler(reservation) {
    setGesteEnCours(reservation.id)
    setErreur(null)
    setSucces(null)
    try {
      await api.annulerReservation(reservation.id)
      setSucces('Réservation annulée. La place est rendue au créneau.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’annulation n’a pas abouti.')
    } finally {
      setGesteEnCours(null)
    }
  }

  // ── DÉPLACER UNE SEULE SÉANCE (RG-M5-07, CA-6) ─────────────────────────────────────────────
  //
  // ⚠ LA CONFIRMATION DIT CE QU'ON NE FERA PAS. Déplacer une séance déjà réservée ne prévient
  // personne : `NotificationReservationInterface` ne déclare que la promotion de liste d'attente et
  // l'arbitrage — mesuré, pas supposé. Les clients se présenteraient à l'ancienne heure.
  //
  // On ne fabrique pas la notification manquante ici — ce serait un lot à soi seul, avec un canal,
  // un gabarit et une décision sur qui reçoit quoi. On rend la conséquence visible au moment du
  // geste, ce qui est la seule chose honnête à faire d'un manque qu'on ne comble pas.
  async function modifierSeance(creneau) {
    const inscrits = (inscritsParCreneau[creneau.id] ?? []).filter((r) => STATUTS_QUI_OCCUPENT.has(r.statut))
    if (inscrits.length > 0) {
      const phrase = inscrits.length === 1
        ? 'Déplacer cette séance ? 1 personne y est inscrite et ne sera PAS prévenue : prévenez-la vous-même.'
        : `Déplacer cette séance ? ${inscrits.length} personnes y sont inscrites et ne seront PAS prévenues : prévenez-les vous-même.`
      if (!window.confirm(phrase)) return
    }

    const corps = {}
    if (modifDebut) corps.debut = new Date(modifDebut).toISOString()
    if (modifFin) corps.fin = new Date(modifFin).toISOString()
    if (modifRessource) corps.ressource = `/api/reservation_ressources/${modifRessource}`
    if (Object.keys(corps).length === 0) return

    setGesteEnCours(creneau.id)
    setErreur(null)
    setSucces(null)
    try {
      await api.modifierCreneau(creneau.id, corps)
      setModifierPour(null)
      setSucces('Séance déplacée. Les autres séances de la série n’ont pas bougé.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Le déplacement n’a pas abouti.')
    } finally {
      setGesteEnCours(null)
    }
  }

  // ── ARBITRER (RG-M5-11) ────────────────────────────────────────────────────────────────────
  //
  // ⚠ CE GESTE EST LA SEULE SORTIE D'UN CRÉNEAU EN ATTENTE. Sans lui, la séance existe, se voit,
  // et ne peut ni se réserver ni se débloquer — ce qui serait pire que l'ancien comportement, où
  // elle était au moins absente.
  //
  // `idRessource` vide = « confirmer telle quelle » : l'exploitant assume le chevauchement. C'est
  // un choix légitime — deux activités peuvent partager un gymnase — et c'est à lui de le dire.
  async function arbitrer(creneau, idRessource) {
    setGesteEnCours(creneau.id)
    setErreur(null)
    setSucces(null)
    try {
      await api.arbitrerCreneau(creneau.id, idRessource || null)
      setArbitragePour(null)
      setSucces(idRessource ? 'Séance déplacée et débloquée.' : 'Séance confirmée telle quelle et débloquée.')
      await recharger()
    } catch (e) {
      // Le refus qui compte : « ressource déjà occupée ». Le serveur nomme la ressource, on
      // n'essaie pas de reformuler.
      setErreur(e.message || 'L’arbitrage n’a pas abouti.')
    } finally {
      setGesteEnCours(null)
    }
  }

  // ── ANNULER UN CRÉNEAU ─────────────────────────────────────────────────────────────────────
  //
  // ⚠ LA CONFIRMATION DIT COMBIEN DE PERSONNES SONT CONCERNÉES, et c'est tout son intérêt. Un
  // `confirm()` qui demande « Annuler ce créneau ? » ne dit rien : on répond oui parce qu'on vient
  // de cliquer. Ce qu'il faut savoir avant de trancher, c'est qu'il y a onze réservations derrière.
  //
  // Le geste lui-même est sans frais pour personne : c'est l'exploitant qui annule, donc toutes les
  // réservations passent en `annulee_libre`, crédit restitué. Rien à voir avec l'annulation d'une
  // réservation, qui peut être facturée hors délai franc — même mot, deux gestes.
  async function annulerCreneau(creneau) {
    const concernees = (inscritsParCreneau[creneau.id] ?? []).filter((r) => STATUTS_QUI_OCCUPENT.has(r.statut))
    const phrase = concernees.length === 0
      ? 'Annuler ce créneau ? Aucune réservation n’est concernée.'
      : concernees.length === 1
        ? 'Annuler ce créneau ? 1 réservation sera annulée, sans frais, et le crédit rendu.'
        : `Annuler ce créneau ? ${concernees.length} réservations seront annulées, sans frais, et les crédits rendus.`
    if (!window.confirm(phrase)) return

    setGesteEnCours(creneau.id)
    setErreur(null)
    setSucces(null)
    try {
      await api.annulerCreneau(creneau.id)
      setSucces('Créneau annulé. Les réservations sont annulées sans frais.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’annulation du créneau n’a pas abouti.')
    } finally {
      setGesteEnCours(null)
    }
  }

  // ── AFFECTER UNE INSTANCE ──────────────────────────────────────────────────────────────────
  async function affecter(reservation, idRessource) {
    if (!idRessource) return
    setGesteEnCours(reservation.id)
    setErreur(null)
    setSucces(null)
    try {
      await api.affecterRessource(reservation.id, idRessource)
      setSucces('Instance affectée.')
      await recharger()
    } catch (e) {
      // Le refus le plus utile arrive ici : « déjà affectée à une réservation qui chevauche ». On
      // affiche le message du serveur tel quel — il nomme l'instance et le conflit.
      setErreur(e.message || 'L’affectation n’a pas abouti.')
    } finally {
      setGesteEnCours(null)
    }
  }

  // ── LISTE D'ATTENTE ────────────────────────────────────────────────────────────────────────
  //
  // ⚠ LE RANG VIENT DU SERVEUR, JAMAIS D'ICI. `InscrireListeAttenteProcessor` prend le maximum
  // existant et ajoute un, dans la même transaction que l'insertion. Deux inscriptions faites au
  // même instant depuis deux postes obtiendraient le même rang si le client le calculait — et deux
  // personnes se croiraient premières.
  async function inscrireEnAttente(creneau) {
    if (!attenteBeneficiaire) return
    setGesteEnCours(creneau.id)
    setErreur(null)
    setSucces(null)
    try {
      await api.inscrireListeAttente(creneau.id, {
        beneficiaire: attenteBeneficiaire,
        // ACT-1 : on attend pour N unités. Une table de huit inscrite pour une seule place serait
        // promue sur une place libre et ne pourrait pas s'asseoir.
        quantity: Math.max(1, Number(attenteQuantite) || 1),
      })
      setAttenteBeneficiaire('')
      setAttenteQuantite('1')
      setSucces('Inscription en liste d’attente enregistrée.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’inscription en liste d’attente n’a pas abouti.')
    } finally {
      setGesteEnCours(null)
    }
  }

  // ── PAIEMENT PARTAGÉ (RG-M5-10) ────────────────────────────────────────────────────────────
  //
  // ⚠ LA PART EST FACULTATIVE, ET LA LAISSER VIDE EST LE CAS COURANT. À défaut, le serveur
  // répartit le `montantDu` restant à parts égales entre les participants déjà déclarés et le
  // nouveau — c'est ce qu'on veut quand quatre personnes partagent un terrain. On n'envoie donc le
  // champ que s'il a été saisi : envoyer `0.00` par défaut poserait une part nulle et rendrait
  // l'organisateur solidaire de tout, silencieusement.
  async function ajouterParticipant(reservation) {
    if (!nouvellePersonne) return
    setGesteEnCours(reservation.id)
    setErreur(null)
    setSucces(null)
    try {
      const corps = { personne: nouvellePersonne }
      if (nouvellePart.trim() !== '') corps.partMontant = nouvellePart.trim()
      await api.ajouterParticipant(reservation.id, corps)
      setNouvellePersonne('')
      setNouvellePart('')
      setSucces('Participant ajouté.')
      await recharger()
    } catch (e) {
      // Le serveur rend le même message pour « bénéficiaire inconnu » et « hors périmètre »,
      // délibérément : les distinguer offrirait un oracle d'énumération sur les fiches clients. On
      // l'affiche tel quel plutôt que d'en déduire lequel des deux c'était.
      setErreur(e.message || 'Le participant n’a pas pu être ajouté.')
    } finally {
      setGesteEnCours(null)
    }
  }

  // ⚠ CE GESTE N'ENCAISSE RIEN, ET LE LIBELLÉ DOIT LE DIRE.
  //
  // `PayerPartProcessor` fait trois lignes, et son propre docblock est net : « la référence M2 de
  // l'encaissement effectif de la part est hors périmètre de ce processor — ce processor matérialise
  // la part comme réglée ». Aucune Vente, aucun mouvement de caisse, aucun effet sur le `montantDu`
  // de la réservation.
  //
  // D'où « Marquer réglée » et non « Encaisser ». Dans un logiciel de caisse, un bouton qui dit
  // « encaisser » promet la caisse : l'agent qui clique croit avoir pris l'argent, la somme manque
  // à la clôture, et il la cherchera partout sauf ici — parce que l'écran lui a dit que c'était
  // fait.
  async function payerPart(participant) {
    setGesteEnCours(participant.id)
    setErreur(null)
    setSucces(null)
    try {
      await api.payerPartParticipant(participant.id)
      setSucces('Part notée comme réglée. L’encaissement se fait à la caisse.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’encaissement de la part n’a pas abouti.')
    } finally {
      setGesteEnCours(null)
    }
  }

  async function reserver(creneau) {
    if (!organisateur) return
    setEnCours(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.reserverCreneau({
        creneau: creneau['@id'] || `/api/reservation_creneaus/${creneau.id}`,
        organisateur: `/api/beneficiaires/${organisateur}`,
      })
      setSucces(`Réservation confirmée sur « ${creneau.ressource?.libelle || 'créneau'} » à ${heure(creneau.debut)}.`)
      setReserverPour(null)
      setOrganisateur('')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'La réservation a échoué.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Réservation</h1>
          <p>
            {ressources === null || creneaux === null
              ? 'planning non lu — la lecture n’a pas abouti'
              : `${ressources.length} ressource(s) · ${creneaux.length} créneau(x)`}
          </p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {/* LA VUE SEMAINE EST LE DEFAUT, ET C'EST UN CHOIX.
          La liste par jour repond creneau par creneau ; la question qu'on se pose en ouvrant un
          planning est << ou reste-t-il de la place cette semaine >>. Une liste ne montre pas les
          trous -- un creneau vide n'y a pas de ligne, donc il n'existe pas a l'ecran, alors que
          c'est justement ce qu'on cherche. */}
      {/* LES HORAIRES DES RESSOURCES, QUI N'AVAIENT AUCUN ECRAN.
          `DisponibiliteRessource` et `IndisponibiliteRessource` exposent un CRUD complet depuis le
          debut, et le front ne les mentionnait nulle part. Sans eux, une ressource ne peut rien
          recevoir -- et rien ne le disait.

          LE SECOND MODELE DE PRISE DE RENDEZ-VOUS.
          Le planning reserve un creneau QUI EXISTE DEJA -- juste pour une seance de piscine. Ici
          rien n'existe avant que le client n'appelle : on cherche ou le rendez-vous TIENDRAIT.
          Maxime, le 27/08 : << il faut les deux pour le calendrier >>.

          Les quatre vues passent par `Tabs` depuis le 28/08 : c'etait le meme `.seg` recopie a la
          main, et il ne suivait donc pas le composant partage. */}
      {!chargement && (
        <Tabs
          onglets={[
            ['semaine', 'Semaine'],
            ['liste', 'Liste par jour'],
            ['rdv', 'Prendre un rendez-vous'],
            ['horaires', 'Horaires et absences'],
          ]}
          actif={vue}
          onChange={setVue}
          style={{ marginBottom: 14 }}
        />
      )}

      {chargement ? (
        <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
      ) : vue === 'rdv' ? (
        <PriseRendezVous onReserve={recharger} />
      ) : vue === 'horaires' ? (
        <Disponibilites droits={droits} />
      ) : vue === 'semaine' ? (
        <PlanningSemaine
          creneaux={creneaux || []}
          occupation={occupation}
          ressources={ressources || []}
          onCreneau={(cr) => {
            // Cliquer un bloc bascule sur la liste du jour concerne : la grille sert a TROUVER,
            // la liste a AGIR. Ouvrir un formulaire de reservation dans une case de 40 px produirait
            // un ecran qu'on ne peut ni lire ni remplir.
            setJour(jourCle(cr.debut))
            setVue('liste')
            setReserverPour(cr.id)
          }}
        />
      ) : (
        <div className="resa-grid">
          {/* Agenda / créneaux */}
          <section className="card">
            <div className="card-h">
              <h3>Planning</h3>
              {jours.length > 0 && (
                <div className="r seg" role="tablist">
                  {jours.map((j) => (
                    <button key={j} className={jour === j ? 'on' : ''} onClick={() => { setJour(j); setReserverPour(null) }}>
                      {jourLabel(j)}
                    </button>
                  ))}
                </div>
              )}
            </div>
            <div className="card-b">
              {creneauxJour.length === 0 ? (
                <div className="empty">Aucun créneau {jours.length ? 'ce jour' : 'planifié'}.</div>
              ) : (
                <div className="grid g2">
                  {creneauxJour.map((c) => {
                    const cap = c.capacite ?? 0
                    const pris = occupation[c.id] || 0
                    const reste = Math.max(0, cap - pris)
                    const pct = cap > 0 ? Math.min(100, Math.round((pris / cap) * 100)) : 0
                    const complet = cap > 0 && reste <= 0
                    const annulable = c.statut !== 'annule'
                    return (
                      <div key={c.id} className="creneau">
                        <div className="creneau-h">
                          <div>
                            <div className="nm">{c.ressource?.libelle || 'Ressource'}</div>
                            <div className="creneau-sub">
                              {c.ressource?.codeType || '—'}
                              {c.activite?.libelle ? ` · ${c.activite.libelle}` : ''}
                            </div>
                          </div>
                          <span className={`badge ${c.statut === 'planifie' ? 'info' : c.statut === 'annule' ? 'crit' : 'mut'}`}>
                            {c.statut}
                          </span>
                        </div>
                        <div className="creneau-time">
                          <span>◷ {heure(c.debut)} – {heure(c.fin)}</span>
                          {c.activite?.tarifReferenceMontant != null && (
                            <span className="creneau-tarif">{euros(c.activite.tarifReferenceMontant)}</span>
                          )}
                        </div>
                        <div className="creneau-places">
                          <div className="bar"><i style={{ width: `${pct}%`, background: complet ? 'var(--crit)' : 'var(--accent)' }} /></div>
                          <span className={`places ${complet ? 'full' : ''}`}>{pris}/{cap} · {reste} place(s)</span>
                        </div>

                        {reserverPour === c.id ? (
                          <div className="creneau-resa">
                            <select
                              className="select"
                              value={organisateur}
                              onChange={(e) => setOrganisateur(e.target.value)}
                            >
                              <option value="">Organisateur…</option>
                              {beneficiaires.map((b) => (
                                <option key={b.id} value={b.id}>{labelBeneficiaire(b)}</option>
                              ))}
                            </select>
                            <div className="creneau-resa-act">
                              <button className="btn" onClick={() => { setReserverPour(null); setOrganisateur('') }} disabled={enCours}>Annuler</button>
                              <button className="btn primary" onClick={() => reserver(c)} disabled={enCours || !organisateur}>
                                {enCours ? 'Envoi…' : 'Confirmer'}
                              </button>
                            </div>
                          </div>
                        ) : (
                          <button
                            className="btn primary creneau-btn"
                            disabled={complet || !annulable || c.enAttenteArbitrage}
                            onClick={() => { setReserverPour(c.id); setOrganisateur(''); setSucces(null) }}
                            title={
                              c.enAttenteArbitrage
                                ? 'Cette séance chevauche une autre occupation de la même ressource : elle attend un arbitrage avant d’être réservable.'
                                : undefined
                            }
                          >
                            {c.enAttenteArbitrage
                              ? 'En attente d’arbitrage'
                              : complet ? 'Complet' : !annulable ? 'Indisponible' : '＋ Réserver'}
                          </button>
                        )}

                        {/* ── ARBITRAGE D'UN CONFLIT DE RÉCURRENCE (RG-M5-11) ────────────────────
                            Cette séance existe parce qu'elle chevauche : avant ce lot, elle était
                            simplement absente, et l'exploitant croyait avoir douze séances quand il
                            en avait neuf. Elle est visible, bloquée, et se débloque ici. */}
                        {c.enAttenteArbitrage && peutArbitrer && (
                          <div className="resa-attente">
                            <p className="hint">
                              Cette séance chevauche une autre occupation de{' '}
                              <strong>{c.ressource?.libelle || 'la même ressource'}</strong>. Elle a
                              été créée mais reste bloquée tant que personne n’a tranché.
                            </p>
                            <div className="resa-part-form">
                              <select
                                className="select"
                                value={arbitragePour === c.id ? (arbitrageRessource || '') : ''}
                                onChange={(e) => { setArbitragePour(c.id); setArbitrageRessource(e.target.value) }}
                                aria-label="Ressource de remplacement"
                              >
                                <option value="">Déplacer sur…</option>
                                {(ressourcesEquivalentes[c.ressource?.id] ?? []).map((r) => (
                                  <option key={r.id} value={r.id}>{r.libelle}</option>
                                ))}
                              </select>
                              <button
                                type="button"
                                className="btn"
                                disabled={gesteEnCours === c.id || arbitragePour !== c.id || !arbitrageRessource}
                                onClick={() => arbitrer(c, arbitrageRessource)}
                              >
                                Déplacer
                              </button>
                              <button
                                type="button"
                                className="btn ghost"
                                disabled={gesteEnCours === c.id}
                                onClick={() => arbitrer(c, null)}
                                title="Assume le chevauchement : la séance devient réservable telle quelle. Légitime quand deux activités partagent réellement le lieu."
                              >
                                Confirmer telle quelle
                              </button>
                            </div>
                          </div>
                        )}

                        {/* Annuler le créneau : geste de l'exploitant, distinct de l'annulation
                            d'une réservation. Caché sur un créneau déjà annulé — le serveur
                            l'accepterait, mais proposer d'annuler ce qui l'est se lit comme une
                            incertitude de l'écran sur l'état qu'il affiche juste au-dessus.

                            ⚠ TON `danger` ET NON `ghost`. Ce bouton annule le créneau ET TOUTES SES
                            RÉSERVATIONS — son propre libellé d'aide le dit. En `ghost`, il était
                            identique au pixel près à « Voir les 4 inscrit(s) » : même encre, même
                            taille, ni fond ni bordure. Rien ne distinguait le geste qui détruit de
                            celui qui montre. `danger` n'était employé qu'une fois dans toute
                            l'application, pour l'effacement RGPD : c'est un ton réservé aux gestes
                            lourds, et le placer ici ne le dilue pas. */}
                        {peutGererCreneau && annulable && (
                          <button
                            type="button"
                            className="btn danger sm"
                            disabled={gesteEnCours === c.id}
                            onClick={() => annulerCreneau(c)}
                            title="Annule le créneau et toutes ses réservations, sans frais pour les clients."
                          >
                            Annuler le créneau
                          </button>
                        )}

                        {/* Déplacer CETTE séance, sans toucher à la série — « le cours du 21 passe
                            à 16 h, les autres ne bougent pas ». La marque `occurrenceModifiee` que
                            le serveur pose ensuite est ce qui distingue une exception d'une série. */}
                        {peutGererCreneau && annulable && (
                          <button
                            type="button"
                            className="btn ghost sm"
                            onClick={() => {
                              const ouvrir = modifierPour !== c.id
                              setModifierPour(ouvrir ? c.id : null)
                              setModifDebut(ouvrir ? pourSaisieLocale(c.debut) : '')
                              setModifFin(ouvrir ? pourSaisieLocale(c.fin) : '')
                              setModifRessource('')
                            }}
                          >
                            {modifierPour === c.id ? 'Annuler la modification' : 'Déplacer cette séance'}
                          </button>
                        )}

                        {modifierPour === c.id && (
                          <div className="resa-attente">
                            <div className="resa-part-form">
                              <input
                                className="input"
                                type="datetime-local"
                                value={modifDebut}
                                onChange={(e) => setModifDebut(e.target.value)}
                                aria-label="Nouveau début de la séance"
                              />
                              <input
                                className="input"
                                type="datetime-local"
                                value={modifFin}
                                onChange={(e) => setModifFin(e.target.value)}
                                aria-label="Nouvelle fin de la séance"
                              />
                            </div>
                            <div className="resa-part-form">
                              <select
                                className="select"
                                value={modifRessource}
                                onChange={(e) => setModifRessource(e.target.value)}
                                aria-label="Nouvelle ressource"
                              >
                                <option value="">Garder {c.ressource?.libelle || 'la ressource'}</option>
                                {(ressourcesEquivalentes[c.ressource?.id] ?? []).map((r) => (
                                  <option key={r.id} value={r.id}>{r.libelle}</option>
                                ))}
                              </select>
                              <button
                                type="button"
                                className="btn"
                                disabled={gesteEnCours === c.id}
                                onClick={() => modifierSeance(c)}
                              >
                                Déplacer
                              </button>
                            </div>
                            <p className="hint">
                              Seule cette séance bouge. Les personnes déjà inscrites ne sont pas
                              prévenues automatiquement.
                            </p>
                          </div>
                        )}

                        {/* ⚠ « COMPLET » ÉTAIT UN CUL-DE-SAC, ET C'EST LÀ QUE LA LISTE D'ATTENTE
                            SERT. Le bouton se grisait, et l'exploitant n'avait rien d'autre à
                            proposer — alors que tout le mécanisme existe : rang, quantité attendue,
                            promotion automatique dès qu'une place se libère, fenêtre d'acceptation.

                            Il tournait à vide pour une raison circulaire : la promotion se
                            déclenche à l'annulation, qui n'était pas possible non plus. Créneau
                            plein, quelqu'un annule, la place se libère — et personne à promouvoir,
                            parce que personne n'a jamais pu s'inscrire. */}
                        {peutPartager && annulable && (complet || (attenteParCreneau[c.id]?.length ?? 0) > 0) && (
                          <button
                            type="button"
                            className="btn ghost sm"
                            onClick={() => {
                              setAttentePour(attentePour === c.id ? null : c.id)
                              setAttenteBeneficiaire('')
                              setAttenteQuantite('1')
                            }}
                          >
                            {(attenteParCreneau[c.id]?.length ?? 0) > 0
                              ? `Liste d’attente (${attenteParCreneau[c.id].length})`
                              : 'Liste d’attente'}
                          </button>
                        )}

                        {attentePour === c.id && (
                          <div className="resa-attente">
                            {(attenteParCreneau[c.id] ?? []).map((l) => (
                              <div key={l.id} className="resa-part">
                                <span className="mono">#{l.rang}</span>
                                <span className="nm">{labelBeneficiaire(l.beneficiaire) || court(l.id)}</span>
                                {l.quantity > 1 && <span className="hint">{l.quantity} places</span>}
                                <span className={`badge ${l.statut === 'promue' ? 'good' : 'mut'}`}>
                                  {LIBELLE_ATTENTE[l.statut] || l.statut}
                                </span>
                                {/* La fenêtre d'acceptation est portée par le serveur et refermée
                                    par `smart-flow:waitlist:expirer`. On l'affiche parce que c'est
                                    l'information qui décide s'il faut appeler tout de suite. */}
                                {l.statut === 'promue' && l.dateExpirationPromotion && (
                                  <span className="hint">à confirmer avant {heure(l.dateExpirationPromotion)}</span>
                                )}
                              </div>
                            ))}
                            {(attenteParCreneau[c.id]?.length ?? 0) === 0 && (
                              <p className="hint">Personne n’attend sur ce créneau.</p>
                            )}

                            <div className="resa-part-form">
                              <select
                                className="select"
                                value={attenteBeneficiaire}
                                onChange={(e) => setAttenteBeneficiaire(e.target.value)}
                                aria-label="Personne à inscrire en liste d’attente"
                              >
                                <option value="">Inscrire une personne…</option>
                                {beneficiaires.map((b) => (
                                  <option key={b.id} value={b.id}>{labelBeneficiaire(b)}</option>
                                ))}
                              </select>
                              <input
                                className="input"
                                value={attenteQuantite}
                                onChange={(e) => setAttenteQuantite(e.target.value)}
                                aria-label="Nombre de places attendues"
                                inputMode="numeric"
                              />
                              <button
                                type="button"
                                className="btn"
                                disabled={!attenteBeneficiaire || gesteEnCours === c.id}
                                onClick={() => inscrireEnAttente(c)}
                              >
                                Inscrire
                              </button>
                            </div>
                            <p className="hint">
                              Dès qu’une place se libère, la première inscription est promue
                              automatiquement et une fenêtre d’acceptation s’ouvre.
                            </p>
                          </div>
                        )}

                        {/* LA LISTE DES INSCRITS, DÉPLIABLE.
                            Repliée par défaut : un créneau de vingt personnes rendrait la grille
                            illisible, et on ne l'ouvre qu'au moment d'émarger ou d'annuler.
                            Absente s'il n'y a personne — un bouton « Voir 0 inscrit » se clique une
                            fois et déçoit. */}
                        {(inscritsParCreneau[c.id]?.length ?? 0) > 0 && (peutEmarger || peutAnnuler) && (
                          <button
                            type="button"
                            className="btn ghost sm"
                            onClick={() => setInscritsPour(inscritsPour === c.id ? null : c.id)}
                          >
                            {inscritsPour === c.id
                              ? 'Masquer les inscrits'
                              : `Voir les ${inscritsParCreneau[c.id].length} inscrit(s)`}
                          </button>
                        )}

                        {inscritsPour === c.id && (
                          <div className="resa-inscrits">
                            {inscritsParCreneau[c.id].map((r) => {
                              // La limite est portée par la réservation, posée à la réservation
                              // depuis `RegleAnnulation.delaiFrancMinutes`. On l'affiche pour que
                              // l'agent sache AVANT de cliquer si l'annulation sera libre ou
                              // facturée — mais c'est le serveur qui tranche, pas ce calcul.
                              const limite = r.dateLimiteAnnulation ? new Date(r.dateLimiteAnnulation) : null
                              const horsDelai = limite !== null && new Date() > limite
                              const occupe = STATUTS_QUI_OCCUPENT.has(r.statut)
                              return (
                                <div key={r.id} className="resa-inscrit">
                                  <div className="resa-inscrit-h">
                                    <span className="nm">{labelBeneficiaire(r.organisateur) || court(r.id)}</span>
                                    {/* ⚠ « À confirmer » OCCUPE AUSSI, donc `occupe` est vrai pour lui.
                                        Sans ce cas explicite il porterait le même badge qu'une
                                        réservation honorée — alors que c'est celui-là qu'il faut
                                        regarder avant que l'échéance passe. */}
                                    <span className={`badge ${r.statut === 'a_confirmer' ? 'warn' : r.statut === 'confirmee' ? 'info' : occupe ? 'good' : 'mut'}`}>
                                      {LIBELLE_STATUT[r.statut] || r.statut}
                                    </span>
                                    {r.presenceConfirmee && (
                                      <span className="badge good">
                                        Présent
                                        {r.sourcePresence ? ` · ${LIBELLE_SOURCE[r.sourcePresence] || r.sourcePresence}` : ''}
                                        {r.dateConfirmationPresence ? ` · ${heure(r.dateConfirmationPresence)}` : ''}
                                      </span>
                                    )}
                                  </div>
                                  {r.quantity > 1 && <div className="hint">{r.quantity} places</div>}

                                  {/* ── AFFECTER UNE INSTANCE (ACT-1, D16) ────────────────────
                                      N'apparaît que si le créneau porte un TYPE, c'est-à-dire une
                                      ressource qui a des enfants. Sur un créneau réservé
                                      directement sur l'instance — la ligne d'eau 1, le court 3 —
                                      il n'y a rien à choisir, et un sélecteur vide serait une
                                      question sans réponse possible. */}
                                  {peutPartager && occupe && (instancesParType[c.ressource?.id]?.length ?? 0) > 0 && (
                                    <div className="resa-part-form">
                                      <span className="hint">
                                        {libelleRessource(r.ressourceAffectee)
                                          ? `Affectée : ${libelleRessource(r.ressourceAffectee)}`
                                          : 'Aucune instance affectée'}
                                      </span>
                                      <select
                                        className="select"
                                        value=""
                                        onChange={(e) => affecter(r, e.target.value)}
                                        disabled={gesteEnCours === r.id}
                                        aria-label="Affecter une instance à cette réservation"
                                      >
                                        <option value="">{libelleRessource(r.ressourceAffectee) ? 'Changer…' : 'Affecter…'}</option>
                                        {instancesParType[c.ressource.id].map((i) => (
                                          <option key={i.id} value={i.id}>{i.libelle}</option>
                                        ))}
                                      </select>
                                    </div>
                                  )}

                                  {/* Les gestes ne s'affichent que tant que la réservation occupe
                                      une place : émarger une réservation annulée n'a pas de sens, et
                                      le serveur refuserait — un bouton qui refuse au clic fait
                                      chercher une panne là où il n'y a qu'un état. */}
                                  {/* ── PARTS DE PAIEMENT ───────────────────────────────────
                                      Repliées : la plupart des réservations n'ont qu'un payeur, et
                                      déplier systématiquement noierait l'émargement, qui est le
                                      geste courant.

                                      ⚠ AFFICHÉ MÊME À ZÉRO PARTICIPANT quand il reste quelque chose
                                      à payer. C'est précisément là qu'on veut pouvoir partager, et
                                      un bloc qui n'apparaît qu'une fois le premier participant
                                      ajouté serait invisible tant qu'on n'a rien fait — donc
                                      toujours. */}
                                  {peutPartager && occupe && ((r.participants?.length ?? 0) > 0 || Number(r.montantDu) > 0) && (
                                    <button
                                      type="button"
                                      className="btn ghost sm"
                                      onClick={() => {
                                        setPartsPour(partsPour === r.id ? null : r.id)
                                        setNouvellePersonne('')
                                        setNouvellePart('')
                                      }}
                                    >
                                      {(r.participants?.length ?? 0) > 0
                                        ? `Parts (${r.participants.length})`
                                        : 'Partager le paiement'}
                                    </button>
                                  )}

                                  {partsPour === r.id && (
                                    <div className="resa-parts">
                                      {(r.participants ?? []).map((p) => (
                                        <div key={p.id} className="resa-part">
                                          <span className="nm">{labelBeneficiaire(p.personne) || court(p.id)}</span>
                                          {p.estOrganisateur && <span className="badge">organisateur</span>}
                                          <span className="mono">{euros(p.partMontant)}</span>
                                          {/* ⚠ TROIS ÉTATS, ET L'ÉCRAN N'EN DISAIT QUE DEUX.
                                              `impute_organisateur` tombait dans « En attente » —
                                              un joueur qui ne doit rien s'affichait comme un
                                              impayé, avec un bouton pour régler sa part. */}
                                          <span className={`badge ${BADGE_PART[p.statutPaiement] ?? 'mut'}`}>
                                            {LIBELLE_PART[p.statutPaiement] ?? 'En attente'}
                                          </span>
                                          {p.statutPaiement === 'en_attente' && (
                                            <button
                                              type="button"
                                              className="btn sm"
                                              disabled={gesteEnCours === p.id}
                                              onClick={() => payerPart(p)}
                                              title="Note que cette part a été réglée. L’encaissement lui-même passe par la caisse : ce bouton ne prend pas d’argent. L’organisateur reste solidaire de ce qui n’est pas réglé."
                                            >
                                              Marquer réglée
                                            </button>
                                          )}
                                        </div>
                                      ))}

                                      <div className="resa-part-form">
                                        <select
                                          className="select"
                                          value={nouvellePersonne}
                                          onChange={(e) => setNouvellePersonne(e.target.value)}
                                          aria-label="Personne à ajouter"
                                        >
                                          <option value="">Ajouter une personne…</option>
                                          {beneficiaires.map((b) => (
                                            <option key={b.id} value={b.id}>{labelBeneficiaire(b)}</option>
                                          ))}
                                        </select>
                                        <input
                                          className="input"
                                          value={nouvellePart}
                                          onChange={(e) => setNouvellePart(e.target.value)}
                                          placeholder="Part (à parts égales si vide)"
                                          aria-label="Part de cette personne, en euros"
                                          inputMode="decimal"
                                        />
                                        <button
                                          type="button"
                                          className="btn"
                                          disabled={!nouvellePersonne || gesteEnCours === r.id}
                                          onClick={() => ajouterParticipant(r)}
                                        >
                                          Ajouter
                                        </button>
                                      </div>
                                      <p className="hint">
                                        Part laissée vide : le reste à payer est réparti à parts égales.
                                        Ce qui n’est pas encaissé reste dû par l’organisateur.
                                      </p>
                                    </div>
                                  )}

                                  {occupe && (
                                    <div className="resa-inscrit-act">
                                      {peutEmarger && (
                                        <>
                                          <button
                                            type="button"
                                            className="btn sm"
                                            disabled={gesteEnCours === r.id}
                                            onClick={() => emarger(r, 'present')}
                                            title="Enregistre la présence. Sans cet émargement, la bascule des absences facturerait ce client."
                                          >
                                            Présent
                                          </button>
                                          {/* ⚠ « CORRIGER » ET NON « ABSENT » QUAND LA PRÉSENCE EST
                                              ACQUISE. Deux boutons de même poids à côté d'une
                                              présence déjà enregistrée se lisent comme un choix à
                                              faire, alors que l'un des deux DÉFAIT ce qui vient
                                              d'être constaté — par un tourniquet, le plus souvent,
                                              qui ne se trompe pas souvent. Le nommer « corriger »
                                              dit qu'on revient sur une constatation. */}
                                          <button
                                            type="button"
                                            className="btn ghost sm"
                                            disabled={gesteEnCours === r.id}
                                            onClick={() => emarger(r, 'absent')}
                                            title={
                                              r.presenceConfirmee
                                                ? 'Revient sur la présence enregistrée — par exemple un passage attribué à la mauvaise réservation.'
                                                : 'Enregistre l’absence constatée.'
                                            }
                                          >
                                            {r.presenceConfirmee ? 'Corriger : absent' : 'Absent'}
                                          </button>
                                        </>
                                      )}
                                      {peutAnnuler && (
                                        <button
                                          type="button"
                                          className="btn ghost sm"
                                          disabled={gesteEnCours === r.id}
                                          onClick={() => annuler(r)}
                                          title={
                                            horsDelai
                                              ? 'Hors délai franc : l’annulation sera qualifiée en annulation tardive facturée (RG-M5-09).'
                                              : 'Dans le délai franc : annulation libre, la place et le crédit sont rendus.'
                                          }
                                        >
                                          {gesteEnCours === r.id
                                            ? '…'
                                            : horsDelai
                                              ? 'Annuler (hors délai)'
                                              : 'Annuler'}
                                        </button>
                                      )}
                                    </div>
                                  )}
                                </div>
                              )
                            })}
                          </div>
                        )}
                      </div>
                    )
                  })}
                </div>
              )}
            </div>
          </section>

          {/* Catalogue des ressources */}
          <section className="card">
            <div className="card-h"><h3>Ressources</h3></div>
            <div className="card-b" style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr><th>Ressource</th><th>Type</th><th className="num">Capacité</th><th>Occ.</th><th>Accès</th></tr>
                </thead>
                <tbody>
                  {(ressources || []).map((r) => (
                    <tr key={r.id}>
                      <td>
                        <span className="nm">{r.libelle}</span>
                        {r.partageable && <span className="badge mut" style={{ marginLeft: 6 }}>partageable</span>}
                      </td>
                      <td>{r.codeType || '—'}</td>
                      <td className="num">{r.capacitePropre ?? '—'}</td>
                      <td className="num">{r.occupationCourante ?? 0}</td>
                      <td>{r.ouvreAcces ? <span className="badge good">ouvre</span> : <span className="badge mut">—</span>}</td>
                    </tr>
                  ))}
                  {ressources === null && (
                    <tr>
                      <td colSpan={5} className="empty">
                        Les ressources n’ont pas pu être lues&nbsp;: ce tableau est vide parce que la
                        lecture a échoué.
                      </td>
                    </tr>
                  )}
                  {ressources !== null && ressources.length === 0 && (
                    <tr><td colSpan={5} className="empty">Aucune ressource.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      )}

      {/* Absences non prevenues : facturer ou exonerer, et l'issue sur la seance affichee a part.
          En bas de l'ecran de reservation parce que c'est la consequence d'une reservation, pas une
          activite distincte — et parce qu'un ecran de plus pour deux boutons serait une exception a
          D13 que rien ne justifie. */}
      <NoShowSection etabActif={etabActif} droits={droits} session={session} />
    </div>
  )
}
