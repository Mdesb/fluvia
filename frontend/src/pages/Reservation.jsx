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

// --- Helpers de lecture (structures API Platform / module Réservation) ---

function idDepuisIri(v) {
  if (!v) return null
  if (typeof v === 'object') return v.id || idDepuisIri(v['@id'])
  const parts = String(v).split('/')
  return parts[parts.length - 1] || null
}

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
const STATUTS_QUI_OCCUPENT = new Set(['confirmee', 'honoree'])

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
const LIBELLE_SOURCE = {
  emargement_manuel: 'émargé à la main',
  passage_acces: 'tourniquet',
}

// La valeur brute (`annulee_tardive_facturee`) est un identifiant, pas une phrase : affichée telle
// quelle au comptoir, elle se lit comme un défaut de l'écran.
const LIBELLE_STATUT = {
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

  const [partsPour, setPartsPour] = useState(null) // id de la réservation dont on ouvre les parts
  const [nouvellePersonne, setNouvellePersonne] = useState('')
  const [nouvellePart, setNouvellePart] = useState('')
  const [organisateur, setOrganisateur] = useState('')
  const [enCours, setEnCours] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [rc, cc, rvc, bc] = await Promise.all([
        api.reservationRessources(),
        api.reservationCreneaux(),
        api.reservations(),
        api.beneficiaires(),
      ])
      setRessources(membres(rc))
      setCreneaux(membres(cc))
      setReservations(membres(rvc))
      setBeneficiaires(membres(bc))
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
                            disabled={complet || !annulable}
                            onClick={() => { setReserverPour(c.id); setOrganisateur(''); setSucces(null) }}
                          >
                            {complet ? 'Complet' : !annulable ? 'Indisponible' : '＋ Réserver'}
                          </button>
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
                                    <span className={`badge ${r.statut === 'confirmee' ? 'info' : occupe ? 'good' : 'mut'}`}>
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
                                          <span className={`badge ${p.statutPaiement === 'paye' ? 'good' : 'mut'}`}>
                                            {p.statutPaiement === 'paye' ? 'Payé' : 'En attente'}
                                          </span>
                                          {p.statutPaiement !== 'paye' && (
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
