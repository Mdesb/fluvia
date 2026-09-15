import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { dateHeureFr, jourLocal } from './Liste.jsx'
import { TYPES_QUALIFICATION } from '../api/qualifications.js'

/**
 * LE PLANNING DE TRAVAIL — créer un créneau, l'annuler.
 *
 * L'écran Personnel savait déclarer un employé, ses absences et émettre ses badges. Il ne savait
 * pas le PLANIFIER. `POST /personnel/creneaux-travail` et son annulation étaient servies et
 * appelées par personne : un exploitant pouvait constater qu'un poste n'était pas couvert sans
 * jamais pouvoir dire qu'il devait l'être.
 *
 * ── LE CRÉNEAU EST UN BESOIN, PAS UNE AFFECTATION ───────────────────────────────────────────────
 *
 * Il dit « ce poste demande N personnes de telle heure à telle heure », pas « c'est Untel qui le
 * fait ». L'affectation d'un salarié est un second geste, servi par une autre route. L'écran le
 * dit, sinon on cherche longtemps où choisir la personne.
 */
export default function PlanningTravail({ etabActif, droits = [], params = {}, majParams }) {
  const [creneaux, setCreneaux] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [busy, setBusy] = useState(false)
  // ⚠ `null` = PAS LU, comme pour les creneaux. Un planning qui affiche « personne » alors qu'il
  // n'a pas su lire fait chercher des remplacants pour des postes deja pourvus.
  const [affectations, setAffectations] = useState(null)
  const [employes, setEmployes] = useState(null)
  const [aAffecter, setAAffecter] = useState(null)

  const peutGerer = aLeDroit(droits, 'personnel.gerer_planning')

  const recharger = useCallback(async () => {
    setErreur(null)
    try {
      setCreneaux(membres(await api.creneauxTravail()))
      // Lecture separee : une affectation illisible ne doit pas faire passer le planning entier
      // pour illisible, et inversement. Les deux echecs ne se disent pas de la meme facon.
      try {
        setAffectations(membres(await api.affectationsTravail()))
      } catch {
        setAffectations(null)
      }
    } catch (e) {
      // ⚠ `null` = PAS LU. « Aucun créneau planifié » sur un planning est une affirmation qui
      // fait conclure que personne n'est attendu — et on ne remplace pas quelqu'un qu'on ne
      // sait pas manquant.
      setCreneaux(null)
      setErreur(e.message || 'Le planning n’a pas pu être lu.')
    }
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

  // ⚠ LE CRÉNEAU CORRIGÉ SE LIT PAR SON IDENTIFIANT, PAS DANS LA LISTE : celle-ci est bornée à
  // 200, et un lien ne doit pas en dépendre. Seul un 404 dit « il n'existe pas » ; tout le reste
  // est une lecture qui a échoué. `nouveau` ne lit rien : c'est une création.
  // ⚠ « PAS ENCORE LU » SE DÉDUIT, IL NE SE POSE PAS DANS L'EFFET (même défaut que le rejet
  // bancaire de Sport, #172). Un drapeau levé par l'effet ne l'est qu'APRÈS le premier rendu avec
  // l'adresse : ce rendu-là n'avait ni objet ni chargement, et affirmait « n'existe pas » le temps
  // d'une trame. La lecture garde la clé qu'elle a lue ; tant qu'elle ne correspond pas, on charge.
  // ⚠ LA CLÉ PORTE L'ÉTABLISSEMENT. L'effet relisait déjà à la bascule, mais la clé ne changeait
  // pas : le créneau lu depuis l'ancien établissement restait affiché sous le nouveau jusqu'au
  // retour de la lecture. Avec lui, la lecture rangée ne correspond plus : l'écran charge.
  // Refermer l'écran oublie la lecture : rouvrir relit au lieu de montrer l'état d'avant l'action.
  const [lectureCreneau, setLectureCreneau] = useState(null)
  const cleCreneau = params.creneau && params.creneau !== 'nouveau' ? `${params.creneau}|${etabActif}` : null
  useEffect(() => {
    if (!(params.creneau && params.creneau !== 'nouveau')) { setLectureCreneau(null); return undefined }
    const cle = `${params.creneau}|${etabActif}`
    let vivant = true
    api.creneauTravail(params.creneau)
      .then((v) => { if (vivant) setLectureCreneau({ cle, valeur: v, echouee: false }) })
      .catch((e) => { if (vivant) setLectureCreneau({ cle, valeur: null, echouee: e?.status !== 404 }) })
    return () => { vivant = false }
  }, [params.creneau, etabActif])
  const lectureCreneauCourante = lectureCreneau?.cle === cleCreneau ? lectureCreneau : null
  const chargementCreneau = cleCreneau !== null && lectureCreneauCourante === null
  const creneauOuvert = lectureCreneauCourante?.valeur ?? null
  const lectureCreneauEchouee = lectureCreneauCourante?.echouee ?? false

  useEffect(() => {
    api.employes()
      .then((r) => setEmployes(membres(r)))
      .catch(() => setEmployes(undefined))
  }, [etabActif])

  // Les affectations VIVANTES par creneau. Une affectation annulee ne compte plus dans l'effectif :
  // la compter ferait croire un poste pourvu alors qu'il ne l'est plus.
  const affectesPar = {}
  for (const a of affectations || []) {
    if (a.statut === 'annulee') continue
    const ref = a.creneauTravail
    const cid = typeof ref === 'string' ? String(ref).split('/').pop() : String(ref?.id || '')
    if (!cid) continue
    if (!affectesPar[cid]) affectesPar[cid] = []
    affectesPar[cid].push(a)
  }

  function nomDe(ref) {
    const id = typeof ref === 'string' ? String(ref).split('/').pop() : String(ref?.id || '')
    if (!Array.isArray(employes)) return null
    const e = employes.find((x) => String(x.id) === id)
    return e ? ([e.prenom, e.nom].filter(Boolean).join(' ') || e.matricule || 'employé') : null
  }

  async function retirer(a) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.annulerAffectationTravail(a.id)
      setSucces('Affectation retirée.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Le retrait a échoué.')
    } finally {
      setBusy(false)
    }
  }

  async function annuler(c) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.annulerCreneauTravail(c.id)
      setSucces('Créneau annulé.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’annulation a échoué.')
    } finally {
      setBusy(false)
    }
  }

  // ── PLANIFIER OU CORRIGER UN CRÉNEAU, EN ÉCRAN ──────────────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LES CONDITIONS DES BOUTONS ET L'ÉCRAN LES REPREND : le droit de gérer
  // le planning pour les deux gestes, et un créneau non annulé pour la correction.
  //
  // ⚠ DEUX MONTAGES, PAS UNE INSTANCE PARTAGÉE. Le formulaire se remet à zéro sur `open` ; une
  // instance partagée entre créer et corriger ferait porter à la création les valeurs du dernier
  // créneau ouvert. `key` sépare `nouveau` de chaque identifiant.
  if (params.creneau) {
    const fermerCreneau = () => majParams({ creneau: '' }, { pousser: true })
    const c = creneauOuvert
    let contenu
    if (!peutGerer) {
      contenu = (
        <div className="banner banner-warn">
          Planifier ou corriger un créneau demande le droit de gérer le planning, que ce compte n’a pas.
        </div>
      )
    } else if (params.creneau === 'nouveau') {
      contenu = (
        <CreneauModal
          key="nouveau"
          open
          etabActif={etabActif}
          onClose={fermerCreneau}
          onFait={() => { fermerCreneau(); setSucces('Créneau planifié.'); recharger() }}
        />
      )
    } else if (chargementCreneau) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (!c) {
      contenu = (
        <div className="banner banner-warn">
          {lectureCreneauEchouee
            ? 'Ce créneau n’a pas pu être lu. Ce n’est pas la même chose que « il n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
            : 'Ce créneau n’existe pas, ou n’est pas visible depuis cet établissement.'}
        </div>
      )
    } else if (c.statut === 'annule') {
      contenu = (
        <div className="banner banner-warn">
          Ce créneau est annulé : il ne se corrige plus. Planifiez-en un nouveau.
        </div>
      )
    } else {
      contenu = (
        <CreneauModal
          key={params.creneau}
          open
          etabActif={etabActif}
          creneau={c}
          onClose={fermerCreneau}
          onFait={() => { fermerCreneau(); setSucces('Créneau corrigé — les affectations sont conservées.'); recharger() }}
        />
      )
    }
    return (
      <section className="card">
        <div className="card-b">
          <button className="btn ghost sm" type="button" onClick={fermerCreneau}
            style={{ marginBottom: 'var(--esp-large)' }}>
            ← Retour au planning
          </button>
          {contenu}
        </div>
      </section>
    )
  }

  const actifs = (creneaux || []).filter((c) => c.statut !== 'annule')

  return (
    <section className="card">
      <div className="card-h">
        <h3>Créneaux de travail</h3>
        <span className="sub">
          {creneaux === null
            ? 'état inconnu — la lecture n’a pas abouti'
            : `${actifs.length} créneau${actifs.length > 1 ? 'x' : ''} planifié${actifs.length > 1 ? 's' : ''}`}
        </span>
        {peutGerer && (
          <div className="r">
            <button className="btn sm" type="button" onClick={() => majParams({ creneau: 'nouveau' }, { pousser: true })}>
              ＋ Planifier un créneau
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {creneaux === null ? (
          <div className="banner banner-error">
            Le planning n’a pas pu être lu. <b>N’en concluez pas qu’aucun poste n’est à
            couvrir</b> : cette liste n’a pas été obtenue.
          </div>
        ) : actifs.length === 0 ? (
          <div className="empty">
            Aucun créneau planifié. Un créneau dit qu’un poste demande du monde à telle heure —
            c’est lui qui rend un manque visible, et qui permet au roster de dire « non couvert ».
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Poste</th>
                  <th>Début</th>
                  <th>Fin</th>
                  <th className="num">Effectif</th>
                  <th>Qui le tient</th>
                  <th>Statut</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {actifs.map((c) => (
                  <tr key={c.id}>
                    <td><span className="nm">{c.libellePoste || '—'}</span></td>
                    <td>{dateHeureFr(c.debut)}</td>
                    <td>{dateHeureFr(c.fin)}</td>
                    <td className="num">{c.effectifRequis ?? '—'}</td>
                    <td>
                      {/* ⚠ LE CHIFFRE QUI COMPTE EST LE MANQUE, pas l'effectif. « 1 sur 2 » oblige
                          a soustraire ; « il manque 1 » se lit d'un coup, et c'est ce qu'on cherche
                          en parcourant un planning a la recherche d'un trou. */}
                      {affectations === null ? (
                        <span className="sub">affectations non lues</span>
                      ) : (affectesPar[String(c.id)] || []).length === 0 ? (
                        <span className="badge warn">personne</span>
                      ) : (
                        <>
                          {(affectesPar[String(c.id)] || []).map((a) => (
                            <div key={a.id}>
                              {nomDe(a.employe) || <span className="sub">employé non lu</span>}
                              {peutGerer && (
                                <button
                                  className="btn ghost sm"
                                  type="button"
                                  style={{ marginLeft: 'var(--esp-normal)' }}
                                  disabled={busy}
                                  title="L'affectation est retirée ; le créneau reste au planning."
                                  onClick={() => retirer(a)}
                                >
                                  Retirer
                                </button>
                              )}
                            </div>
                          ))}
                          {(c.effectifRequis || 1) > (affectesPar[String(c.id)] || []).length && (
                            <span className="sub">
                              il manque {(c.effectifRequis || 1) - (affectesPar[String(c.id)] || []).length}
                            </span>
                          )}
                        </>
                      )}
                    </td>
                    <td><span className="badge mut">{c.statut || '—'}</span></td>
                    {peutGerer && (
                      <td className="num">
                        {/* CORRIGER PLUTOT QU'ANNULER-ET-REFAIRE : annuler perd les affectations
                            deja posees sur ce creneau, et il faut toutes les reprendre. */}
                        <button
                          className="btn ghost sm"
                          type="button"
                          disabled={busy}
                          title="Corrige l'horaire, le poste, l'effectif ou la qualification exigée, sans toucher aux affectations."
                          onClick={() => { setErreur(null); setSucces(null); majParams({ creneau: String(c.id) }, { pousser: true }) }}
                        >
                          Modifier
                        </button>
                        <button
                          className="btn sm"
                          type="button"
                          disabled={busy}
                          onClick={() => { setAAffecter(c); setErreur(null); setSucces(null) }}
                        >
                          Affecter
                        </button>
                        <button
                          className="btn ghost sm"
                          type="button"
                          disabled={busy}
                          title="Le créneau reste au planning, marqué annulé."
                          onClick={() => annuler(c)}
                        >
                          Annuler
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="hint">
          Un créneau exprime un <b>besoin</b> — « ce poste demande deux personnes de 9 h à 17 h » —
          et non une affectation. Rattacher un salarié reste un geste distinct — il se fait
          maintenant ici, colonne « qui le tient ».
        </div>
      </div>

      <AffectationEmploye
        creneau={aAffecter}
        employes={employes}
        onFermer={() => setAAffecter(null)}
        onFait={async (m) => { setAAffecter(null); setSucces(m); await recharger() }}
        onErreur={setErreur}
      />

    </section>
  )
}

function CreneauModal({ open, etabActif, creneau = null, onClose, onFait }) {
  const edite = Boolean(creneau && creneau.id)
  const [poste, setPoste] = useState('')
  const [jour, setJour] = useState('')
  const [heureDebut, setHeureDebut] = useState('09:00')
  const [heureFin, setHeureFin] = useState('17:00')
  const [effectif, setEffectif] = useState('1')
  const [qualif, setQualif] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  // ⚠ UN CRENEAU SAISI « 9H » ETAIT ENREGISTRE A 9H UTC, DONC RELU A 11H.
  //
  // Le formulaire envoyait `${jour}T${heure}:00`, SANS fuseau. Le serveur tourne en UTC (mesure du
  // 09/09 : `date_default_timezone_get()` rend « UTC », et aucun `Europe/Paris` n'est configure), il
  // interpretait donc « 09:00 » comme 9h UTC. `dateHeureFr` reaffiche en heure locale : 11h l'ete.
  //
  // ⚠ ET L'EDITION AURAIT AGGRAVE LE DEFAUT A CHAQUE PASSAGE. Le champ montre l'heure LOCALE (11h) ;
  // la renvoyer sans fuseau l'aurait enregistree comme 11h UTC, donc relue a 13h, puis 15h… Un
  // aller-retour sans modification aurait deplace le creneau de deux heures.
  //
  // `new Date(a, m-1, j, hh, mm)` construit l'instant dans le fuseau du NAVIGATEUR, et
  // `toISOString()` l'exprime en UTC. Ce qui est enregistre est donc l'instant que l'exploitant a
  // voulu, et non les chiffres qu'il a tapes.
  //
  // ⚠ Les creneaux ENREGISTRES AVANT ce correctif gardent leur decalage : leur instant en base est
  // faux, et seule une reprise de donnees le corrigerait. Ce correctif empeche d'en creer de
  // nouveaux, il ne repare pas les anciens.
  const enInstant = (j, heure) => {
    const [a, m, jj] = String(j).split('-').map(Number)
    const [hh, mm] = String(heure).split(':').map(Number)
    return new Date(a, m - 1, jj, hh, mm, 0).toISOString()
  }

  // Les heures se lisent en LOCAL : `debut` arrive en ISO avec fuseau, et `slice(11, 16)` prendrait
  // l'heure UTC — un creneau de 9h s'afficherait a 7h ou 11h selon la saison.
  const heureLocale = (iso) => {
    if (!iso) return ''
    const d = new Date(iso)
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  }
  const jourLocalDe = (iso) => {
    if (!iso) return ''
    const d = new Date(iso)
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  }

  useEffect(() => {
    if (!open) return
    if (creneau && creneau.id) {
      setPoste(creneau.libellePoste || '')
      setJour(jourLocalDe(creneau.debut) || jourLocal())
      setHeureDebut(heureLocale(creneau.debut) || '09:00')
      setHeureFin(heureLocale(creneau.fin) || '17:00')
      setEffectif(String(creneau.effectifRequis ?? 1))
      setQualif(creneau.qualificationRequise || '')
    } else {
      setPoste('')
      setJour(jourLocal())
      setHeureDebut('09:00')
      setHeureFin('17:00')
      setEffectif('1')
      setQualif('')
    }
    setErreur(null)
  }, [open, creneau])

  // Les gardes locales disent EXACTEMENT ce que le serveur exige, pour que le refus arrive avant
  // l'aller-retour : `libellePoste` non vide, `fin > debut`, `effectifRequis >= 1`. La troisième
  // vient du validateur de classe `TopologieTravailCoherente`, pas d'une contrainte de champ.
  const n = Number(effectif)
  const finApresDebut = Boolean(jour) && heureFin > heureDebut
  const pret = poste.trim() !== '' && finApresDebut && Number.isFinite(n) && n >= 1

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      const commun = {
        libellePoste: poste.trim(),
        debut: enInstant(jour, heureDebut),
        fin: enInstant(jour, heureFin),
        effectifRequis: n,
        // `null` retire l'exigence ; une chaine vide serait refusee par l'enum.
        qualificationRequise: qualif || null,
      }
      if (edite) {
        // ⚠ On n'envoie PAS `etablissement` : il est dans le groupe d'ecriture (dette D41, rattrapee
        // par le decorateur global), mais deplacer un creneau d'un site a l'autre n'est pas une
        // correction d'horaire — ce serait un autre geste, avec d'autres consequences sur les
        // affectations deja posees.
        await api.majCreneauTravail(creneau.id, commun)
      } else {
        await api.creerCreneauTravail({ etablissement: etabActif, ...commun })
      }
      onFait()
    } catch (err) {
      setErreur(err.message || (edite
        ? 'Le créneau n’a pas pu être corrigé.'
        : 'Le créneau n’a pas pu être planifié.'))
    } finally {
      setEnvoi(false)
    }
  }

  if (!open) return null

  return (
    <>
      <h2>{edite ? 'Corriger le créneau' : 'Planifier un créneau de travail'}</h2>
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="field">
          <label htmlFor="cr-poste">Poste *</label>
          <input
            id="cr-poste"
            className="input"
            value={poste}
            maxLength={120}
            placeholder="Surveillance bassin, caisse, accueil…"
            onChange={(e) => setPoste(e.target.value)}
          />
          <span className="hint">Ce qui doit être tenu, pas le nom de la personne.</span>
        </div>

        <div className="field">
          <label htmlFor="cr-jour">Jour *</label>
          <input id="cr-jour" className="input" type="date" value={jour} onChange={(e) => setJour(e.target.value)} />
        </div>

        <div className="row row-champs">
          <div className="field">
            <label htmlFor="cr-debut">Début *</label>
            <input id="cr-debut" className="input" type="time" value={heureDebut} onChange={(e) => setHeureDebut(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="cr-fin">Fin *</label>
            <input id="cr-fin" className="input" type="time" value={heureFin} onChange={(e) => setHeureFin(e.target.value)} />
            {jour && !finApresDebut && (
              <span className="hint">
                La fin doit être après le début. Un créneau de nuit se saisit en deux fois&nbsp;:
                le serveur refuse un intervalle qui traverse minuit.
              </span>
            )}
          </div>
        </div>

        <div className="field">
          <label htmlFor="cr-effectif">Effectif requis *</label>
          <input
            id="cr-effectif"
            className="input"
            type="number"
            min="1"
            step="1"
            value={effectif}
            onChange={(e) => setEffectif(e.target.value)}
          />
          <span className="hint">
            Au moins une personne. C’est ce nombre que le roster compare aux affectations pour
            dire si le poste est couvert.
          </span>
        </div>

        {/* LA QUALIFICATION EXIGEE — le champ qui DECIDE des refus d'affectation, et qui n'etait
            propose nulle part.
            `AffecterEmployeProcessor` refuse en 422 tout employe qui ne detient pas une
            qualification valide de ce type le jour du creneau (CA-5), et le roster affiche le badge
            rouge « manquante ou perimee ». Le creneau pouvait donc exiger un brevet sans que
            personne n'ait choisi lequel — ou n'en exiger aucun sans qu'on puisse le decider. */}
        <div className="field">
          <label htmlFor="cr-qualif">Qualification exigée</label>
          <select id="cr-qualif" className="input" value={qualif} onChange={(e) => setQualif(e.target.value)}>
            <option value="">Aucune — n’importe qui peut tenir ce poste</option>
            {TYPES_QUALIFICATION.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          <span className="hint">
            Le serveur refusera d’affecter quelqu’un qui ne la détient pas, valide le jour du
            créneau. Se saisit dans l’onglet « Qualifications ».
          </span>
        </div>

        <div className="r">
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn primary" disabled={envoi || !pret}>
            {envoi ? 'Enregistrement…' : (edite ? 'Corriger' : 'Planifier')}
          </button>
        </div>
      </form>
    </>
  )
}


/**
 * AFFECTER UN EMPLOYÉ — et ne pas prétendre savoir qui est disponible.
 *
 * ⚠ Le serveur refuse pour cinq raisons, dont DEUX qu'aucun écran ne peut anticiper : un conflit de
 * planning **sur un autre établissement** (RG-PERSO-04) — que le cloisonnement cache par
 * construction — et une absence validée sur la période (RG-PERSO-05). S'y ajoutent la qualification
 * manquante (CA-5), le créneau annulé, et le créneau sans fenêtre horaire.
 *
 * Griser des employés « indisponibles » à partir de ce que cet écran voit produirait deux mensonges
 * en sens inverse : des gens écartés à tort, et des gens proposés qui seront refusés. On propose
 * donc tout le monde, et **le refus du serveur s'affiche tel quel** — il nomme la règle, ce qui est
 * exactement ce dont le planificateur a besoin pour corriger.
 */
function AffectationEmploye({ creneau, employes, onFermer, onFait, onErreur }) {
  const [employe, setEmploye] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => { setEmploye('') }, [creneau])

  async function affecter() {
    setBusy(true)
    onErreur(null)
    try {
      await api.affecterEmploye({
        creneauTravail: `/api/creneau_travails/${creneau.id}`,
        employe: `/api/employes/${employe}`,
      })
      await onFait('Employé affecté.')
    } catch (e) {
      onErreur(e.message || 'L’affectation n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={!!creneau} onClose={onFermer} titre="Affecter quelqu’un" taille="sm">
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div className="sub">
          <b>{creneau?.libellePoste || 'poste'}</b> — {dateHeureFr(creneau?.debut)} → {dateHeureFr(creneau?.fin)}
          {creneau?.qualificationRequise && <> · qualification exigée : <b>{creneau.qualificationRequise}</b></>}
        </div>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Employé</span>
          {employes === undefined ? (
            <span className="sub">La liste des employés n’a pas pu être lue.</span>
          ) : (
            <select className="select" value={employe} onChange={(e) => setEmploye(e.target.value)}>
              <option value="">— choisir —</option>
              {(employes || []).map((e) => (
                <option key={e.id} value={e.id}>
                  {[e.prenom, e.nom].filter(Boolean).join(' ') || e.matricule || e.id}
                </option>
              ))}
            </select>
          )}
          <span className="sub">
            Tous les employés sont proposés. Le serveur refusera si la personne est déjà prise sur un
            créneau qui chevauche — <b>y compris dans un autre établissement</b> —, si elle est en
            absence validée, ou si elle n’a pas la qualification exigée. Cet écran ne peut pas le
            savoir avant de demander.
          </span>
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="button" disabled={busy || employe === ''} onClick={affecter}>
            {busy ? 'Affectation…' : 'Affecter'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
