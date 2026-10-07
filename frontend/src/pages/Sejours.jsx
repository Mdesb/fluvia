import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import ClientPicker, { nomClient } from '../components/ClientPicker.jsx'
import { dateHeureFr } from '../components/Liste.jsx'
import { confirmer } from '../components/Confirmation.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'
import { idDe } from '../api/iri.js'
import { useEtatUrl } from '../api/url.js'

// SÉJOURS — sept routes servies, aucun écran, et deux séjours déjà ouverts que personne ne pouvait
// lire.
//
// ── CE QUI EXISTAIT SANS PORTE ──────────────────────────────────────────────────────────────────
//
// `App\Stay` tient la note d'un séjour : on l'ouvre pour un client, les lignes s'y accumulent — au
// comptoir ou depuis un autre module, chaque ligne portant sa provenance — puis on clôture, puis on
// règle. Le moteur, le cloisonnement, la note calculée et les quatre gestes sont écrits et testés.
//
// ⚠ ET IL Y A DÉJÀ DES DONNÉES : deux séjours OUVERTS depuis le 24/08 (`SEJ-TEST-A`, `SEJ-TEST-B`)
// et une ligne de bar à 9,00 €. Mesuré en base le 05/09. Ce n'est pas un module en attente de son
// premier usage : c'est un module dont l'état courant n'était visible de nulle part.
//
// ── LES TROIS ÉTATS, ET CE QUE CHACUN AUTORISE ──────────────────────────────────────────────────
//
//   ouvert   le client est là — tout s'ajoute ;
//   clos     le client est parti — on peut encore encaisser, et corriger une ligne contestée ;
//   soldé    terminé — plus rien ne bouge.
//
// ⚠ CE N'ÉTAIT PAS LE CAS AVANT LE 05/09, ET ÇA ENFERMAIT LES SÉJOURS. Une ligne ne s'ajoutait
// qu'à un séjour OUVERT ; régler exige un solde NUL ; le solde est la somme des lignes, donc le
// mettre à zéro demande une ligne négative — un règlement. Clôturer avant d'encaisser rendait donc
// le séjour impossible à régler, définitivement : rien ne rouvre un séjour, vérifié sur tout
// `app/src/Stay` (`StayStatus::Open` n'est jamais réassigné).
//
// Le modèle voulait déjà l'inverse et se contredisait : `close()` est documenté « départ du
// client », et son test dit que « partir et payer sont deux faits distincts — facturation différée
// à un comité d'entreprise, litige sur une ligne ». Maxime a tranché dans ce sens.
//
// L'invariant n'a pas disparu, il s'est déplacé d'un cran : c'est `Settled` qui porte le « réglé
// une fois » de D16, parce qu'une ligne postérieure au règlement rendrait faux un encaissement déjà
// passé en comptabilité.
//
// ── LE TITULAIRE, ET POURQUOI IL A FALLU UN LOT SERVEUR POUR L'AFFICHER ─────────────────────────
//
// `Stay::$customer` ne portait AUCUN groupe de sérialisation : on ouvrait un séjour POUR un client
// — le `POST` l'exige — et plus aucune lecture ne disait lequel. Cet écran a donc vécu ses
// premières heures en affichant la seule référence `SEJ-…`, avec une phrase qui l'expliquait.
//
// Le groupe `stay:read` a été posé le 05/09 sur `customer`, et en retour sur les quatre champs
// d'IDENTITÉ de `Client` (id, nom, prénom, raison sociale) — la convention du dépôt, que `Client`
// suivait déjà pour six autres modules. La phrase d'excuse a disparu avec le défaut : c'est la
// seule façon de ne pas la laisser mentir.
//
// ── LA RÉFÉRENCE N'EST PAS SÉQUENTIELLE, ET C'EST VOULU ─────────────────────────────────────────
//
// `SEJ-<10 caractères>` tiré d'un UUID v7 : le module explique qu'un compteur rendrait les
// références devinables, et que l'indiscernabilité du 404 de `StayScopeGuard` y perdrait sa valeur.
// L'écran ne propose donc pas de choisir la référence — le serveur la fabrique.

const STATUTS = {
  open: ['Ouvert', 'warn'],
  closed: ['Clos', 'mut'],
  settled: ['Réglé', 'good'],
}

// La même expression que le serveur (`AddStayChargeProcessor`) : un montant se transmet en CHAÎNE,
// jamais en nombre. Un `40.1` déserialisé en flottant perdrait le centime avant d'arriver au
// processeur — c'est écrit dans son code, et refusé par lui.
const MONTANT = /^-?\d+(\.\d{1,2})?$/

function badgeStatut(code) {
  const [libelle, classe] = STATUTS[code] || [code || '—', 'mut']
  return <span className={`badge ${classe}`}>{libelle}</span>
}

function dateFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

// Aujourd'hui au format d'un `<input type="date">`, en heure LOCALE.
// `toISOString().slice(0, 10)` rendrait la veille pour toute soirée d'été après 22 h — le défaut
// que le garde-fou n°31 traque, et que trois écrans ont déjà payé.
function aujourdhui() {
  const d = new Date()
  const p = (v) => String(v).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

// `sejour` : le séjour dont la note est ouverte.
const DEFAUTS_URL = { sejour: '' }

export default function Sejours({ etabActif, droits }) {
  const [params, majParams] = useEtatUrl('sejours', DEFAUTS_URL)
  const peutOuvrir = aLeDroit(droits, 'stay.write')
  const peutFacturer = aLeDroit(droits, 'stay.charge')
  const peutRegler = aLeDroit(droits, 'stay.settle')

  const [sejours, setSejours] = useState([])
  const [tronque, setTronque] = useState(false)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [filtre, setFiltre] = useState('open')
  const [creation, setCreation] = useState(null) // null | 'client' | { client }

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const res = await api.sejours()
      const recus = membres(res)
      setSejours(recus)
      // `Stay` ne déclare AUCUN filtre d'API : le tri par statut se fait donc ici, sur ce qui a été
      // reçu. Une collection tronquée rendrait ce tri menteur — on le signale au lieu de laisser
      // croire à une liste complète.
      const total = res?.totalItems ?? res?.['hydra:totalItems']
      setTronque(typeof total === 'number' && total > recus.length)
    } catch (e) {
      // ⚠ PAS DE REPLI SUR UNE LISTE VIDE : un refus rendu en « aucun séjour » ferait dire à l'écran
      // qu'il n'y a personne au comptoir, ce qu'il n'a pas mesuré.
      setSejours([])
      setErreur(e?.message || 'Impossible de lire les séjours.')
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  // ── LA NOTE D'UN SÉJOUR : LE SÉJOUR SE LIT PAR SON IDENTIFIANT ─────────────────────────────
  //
  // La liste est bornée à 200 et un lien ne doit pas en dépendre. Seul un 404 dit « n'existe
  // pas » (le serveur rend aussi 404 pour le séjour d'un autre établissement) ; tout le reste est
  // une lecture qui a échoué. « Pas encore lu » se déduit de la clé, qui porte l'établissement
  // (#172) ; une adresse vidée oublie la lecture, pour qu'un écran rouvert relise.
  const [lectureSejour, setLectureSejour] = useState(null)
  const cleSejour = params.sejour ? `${params.sejour}|${etabActif}` : null
  useEffect(() => {
    if (!params.sejour) { setLectureSejour(null); return undefined }
    const cle = `${params.sejour}|${etabActif}`
    let vivant = true
    api.sejour(params.sejour)
      .then((v) => { if (vivant) setLectureSejour({ cle, valeur: v, echouee: false }) })
      .catch((e) => { if (vivant) setLectureSejour({ cle, valeur: null, echouee: e?.status !== 404 }) })
    return () => { vivant = false }
  }, [params.sejour, etabActif])
  const lectureSejourCourante = lectureSejour?.cle === cleSejour ? lectureSejour : null
  // ⚠ L'erreur d'un geste de la note a sa bannière dans l'écran : celle de la page n'y est pas rendue.
  const [erreurNote, setErreurNote] = useState(null)
  useEffect(() => { setErreurNote(null) }, [params.sejour, etabActif])

  const affiches = useMemo(
    () => (filtre === 'tous' ? sejours : sejours.filter((s) => s.status === filtre)),
    [sejours, filtre],
  )

  async function ouvrirSejour({ client, arrivalDate, expectedDepartureDate }) {
    setErreur(null)
    setSucces(null)
    try {
      const cree = await api.ouvrirSejour({
        customer: idDe(client),
        arrivalDate,
        ...(expectedDepartureDate ? { expectedDepartureDate } : {}),
      })
      // La référence est fabriquée par le serveur : on la rend tout de suite, c'est elle qu'on
      // annonce au client au comptoir.
      setSucces(`Séjour ${cree?.reference || ''} ouvert pour ${nomClient(client)}.`.replace('  ', ' '))
      setCreation(null)
      await charger()
    } catch (e) {
      setErreur(e?.message || "L'ouverture du séjour a échoué.")
    }
  }

  // ── LA NOTE DU SÉJOUR, EN ÉCRAN ─────────────────────────────────────────────────────────────
  //
  // Le bouton « Voir la note » n'a pas de condition : l'écran n'en reprend aucune. Chaque geste de
  // la note porte déjà son droit (facturer, clôturer, marquer réglé).
  if (params.sejour) {
    const fermerNote = () => majParams({ sejour: '' }, { pousser: true })
    const s = lectureSejourCourante?.valeur ?? null
    let contenu
    if (lectureSejourCourante === null) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (!s) {
      contenu = (
        <div className="banner banner-warn">
          {lectureSejourCourante.echouee
            ? 'Ce séjour n’a pas pu être lu. Ce n’est pas la même chose que « il n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
            : 'Ce séjour n’existe pas, ou n’est pas visible depuis cet établissement.'}
        </div>
      )
    } else {
      contenu = (
        <>
          {erreurNote && <div className="banner banner-error">{erreurNote}</div>}
          {succes && <div className="banner banner-ok">{succes}</div>}
          <NoteSejour
            key={cleSejour}
            sejour={s}
            peutFacturer={peutFacturer}
            peutRegler={peutRegler}
            peutCloturer={peutOuvrir}
            onFermer={fermerNote}
            onErreur={setErreurNote}
            onSucces={(m) => { setSucces(m); setErreurNote(null) }}
            onRecharger={charger}
          />
        </>
      )
    }
    return (
      <div className="view large">
        <button className="btn ghost sm" type="button" onClick={fermerNote}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour aux séjours
        </button>
        {contenu}
      </div>
    )
  }

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Séjours</h1>
          <p>La note d&rsquo;un séjour : ce qui s&rsquo;y ajoute, ce qui reste dû, et la clôture</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <div className="card">
        <div className="card-h">
          <h3>
            Séjours{sejours.length > 0 ? ` — ${affiches.length} sur ${sejours.length}` : ''}
          </h3>
          <div className="row" style={{ gap: 'var(--esp-serre)' }}>
            <select
              className="select"
              value={filtre}
              onChange={(e) => setFiltre(e.target.value)}
              aria-label="Filtrer par statut"
            >
              <option value="open">Ouverts</option>
              <option value="closed">Clos</option>
              <option value="settled">Réglés</option>
              <option value="tous">Tous</option>
            </select>
            {peutOuvrir && (
              <button type="button" className="btn primary" onClick={() => setCreation('client')}>
                ＋ Nouveau séjour
              </button>
            )}
          </div>
        </div>
        <div className="card-b">
          {tronque && (
            <div className="banner banner-warn">
              Le serveur détient plus de séjours que cette page n&rsquo;en a lu. Le tri par statut
              ci-dessus ne porte que sur ceux qui sont affichés.
            </div>
          )}

          {chargement ? (
            <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
          ) : affiches.length === 0 ? (
            <p className="empty">
              {sejours.length === 0
                ? 'Aucun séjour.'
                : 'Aucun séjour dans ce statut — d’autres existent sous un autre filtre.'}
            </p>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Référence</th>
                    <th>Titulaire</th>
                    <th>Arrivée</th>
                    <th>Départ prévu</th>
                    <th>Statut</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {affiches.map((s) => (
                    <tr key={idDe(s)}>
                      <td className="mono">{s.reference}</td>
                      {/* `customer` arrive imbriqué avec son identité depuis le lot du 05/09 ;
                          `nomClient` sait déjà rendre une personne comme une société. */}
                      <td>{s.customer ? nomClient(s.customer) : <span className="sub">—</span>}</td>
                      <td>{dateFr(s.arrivalDate)}</td>
                      <td>{dateFr(s.expectedDepartureDate)}</td>
                      <td>{badgeStatut(s.status)}</td>
                      <td className="num">
                        <button type="button" className="btn" onClick={() => majParams({ sejour: String(idDe(s)) }, { pousser: true })}>
                          Voir la note
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <p className="hint">
            La référence <span className="mono">SEJ-…</span> reste l&rsquo;identifiant qu&rsquo;on
            annonce au comptoir : elle est courte, lisible, et volontairement non séquentielle.
          </p>
        </div>
      </div>

      {/* Deux temps plutôt qu'une modale dans une modale : `ClientPicker` EST une modale, et les
          imbriquer donnerait deux boîtes superposées dont on ne saurait plus laquelle a le focus. */}
      {creation === 'client' && (
        <ClientPicker
          open
          onClose={() => setCreation(null)}
          onSelect={(client) => setCreation({ client })}
          titre="Pour quel client ?"
        />
      )}

      {creation && creation !== 'client' && (
        <OuvrirSejour
          client={creation.client}
          onFermer={() => setCreation(null)}
          onValider={ouvrirSejour}
        />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------------------ La note du séjour */

function NoteSejour({ sejour, peutFacturer, peutRegler, peutCloturer, onFermer, onErreur, onSucces, onRecharger }) {
  const [note, setNote] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [ajout, setAjout] = useState(null) // null | 'ligne' | 'reglement'
  const [enCours, setEnCours] = useState(false)

  const relire = useCallback(async () => {
    setChargement(true)
    try {
      setNote(await api.noteSejour(idDe(sejour)))
    } catch (e) {
      setNote(null)
      onErreur(e?.message || 'Impossible de lire la note.')
    } finally {
      setChargement(false)
    }
  }, [sejour, onErreur])

  useEffect(() => {
    relire()
  }, [relire])

  const solde = note ? Number(note.balance) : null
  // `lines` est déclaré `public array $lines = []` côté serveur, donc toujours présent — mais s'il
  // manquait, `note.lines.length` viderait la fenêtre SANS lever d'erreur. Et une note vide se lit
  // comme « rien à payer », ce qui est exactement le contresens à ne pas laisser passer.
  const lignes = Array.isArray(note?.lines) ? note.lines : []
  const soldeNul = solde === 0
  const statut = note?.status || sejour.status

  async function geste(fn, message) {
    setEnCours(true)
    onErreur(null)
    try {
      await fn()
      onSucces(message)
      await relire()
      await onRecharger()
      return true
    } catch (e) {
      onErreur(e?.message || "L'opération a échoué.")
      return false
    } finally {
      setEnCours(false)
    }
  }

  async function cloturer() {
    // La confirmation dit ce qui change, et ce qui ne change pas. Clôturer n'enferme plus rien
    // depuis le 05/09 : on peut encore encaisser après le départ. Ce qui devient irréversible, c'est
    // « marquer réglé ».
    const message = soldeNul
      ? 'Clôturer ce séjour ? Il restera modifiable jusqu’à ce que vous le marquiez réglé.'
      : `Clôturer ce séjour alors qu’il reste ${euros(solde)} à régler ?\n\n`
        + 'Le départ sera enregistré, et vous pourrez toujours encaisser ensuite — un séjour clos '
        + 'accepte encore des lignes. Seul « marquer réglé » ferme la note pour de bon.'
    if (!await confirmer(message)) return
    await geste(() => api.cloturerSejour(idDe(sejour)), 'Séjour clôturé.')
  }

  async function regler() {
    await geste(() => api.reglerSejour(idDe(sejour)), 'Séjour réglé.')
  }

  return (
    <>
      <h2>Note du séjour {sejour.reference}</h2>
      {chargement ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : !note ? (
        <p className="empty">La note n&rsquo;a pas pu être lue.</p>
      ) : (
        <>
          <div className="deflist">
            <div><span>Titulaire</span><span>{sejour.customer ? nomClient(sejour.customer) : '—'}</span></div>
            <div><span>Statut</span><span>{badgeStatut(statut)}</span></div>
            <div>
              <span>Reste dû</span>
              <span><strong>{euros(solde)}</strong></span>
            </div>
            <div>
              <span>Lignes</span>
              <span>
                {note.lineCount}
                {/* Le serveur expose `lineCount` À PART précisément pour ça : un solde à zéro ne dit
                    pas si rien n'a été consommé ou si tout a été compensé. */}
                {soldeNul && note.lineCount > 0 && (
                  <span className="sub"> — solde nul, mais des lignes existent (tout est compensé)</span>
                )}
              </span>
            </div>
          </div>

          <h4>Détail</h4>
          {lignes.length === 0 ? (
            <p className="empty">Aucune ligne sur cette note.</p>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Libellé</th>
                    <th className="num">Montant</th>
                    <th>Le</th>
                    <th>Provenance</th>
                  </tr>
                </thead>
                <tbody>
                  {lignes.map((l, i) => (
                    <tr key={`${l.occurredAt}-${i}`}>
                      <td>{l.label}</td>
                      <td className="num">{euros(Number(l.amount))}</td>
                      <td>{dateHeureFr(l.occurredAt)}</td>
                      <td className="sub">{l.sourceModule === 'manual' ? 'saisie au comptoir' : l.sourceModule}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* ⚠ EN LIGNE, PAS DANS UNE SECONDE FENETRE. Une modale dans une modale superpose deux
              pieges a focus, et l'on ne sait plus laquelle repond a Echap. Ce formulaire ajoute une
              ligne a ce qu'on regarde deja : sa place est ici. */}
          {/* `!== 'settled'` et non `=== 'open'` : encaisser après le départ est justement le
              geste que le correctif du 05/09 a rendu possible. */}
          {ajout && note && statut !== 'settled' && (
            <AjouterLigne
              reglement={ajout === 'reglement'}
              solde={solde}
              onFermer={() => setAjout(null)}
              onValider={async (corps) => {
                const etaitReglement = ajout === 'reglement'
                // ⚠ LE FORMULAIRE SE FERME SUR UN SUCCÈS SEULEMENT. Il se fermait avant l'envoi : un
                // refus du serveur effaçait le libellé et le montant qu'on venait de saisir.
                const ok = await geste(
                  () => api.ajouterLigneSejour(idDe(sejour), corps),
                  etaitReglement ? 'Règlement enregistré.' : 'Ligne ajoutée.',
                )
                if (ok) setAjout(null)
              }}
            />
          )}

          {/* ⚠ UN SEUL BLOC POUR « OUVERT » ET « CLOS ». Les deux offrent désormais les mêmes
              gestes — ajouter une ligne, encaisser — et ne diffèrent que par leur bouton de
              transition. Les garder séparés aurait dupliqué la barre, donc garanti qu'elles
              divergent au prochain changement. */}
          {statut !== 'settled' && !ajout && (
            <>
              {!soldeNul && (
                <div className="banner banner-info">
                  Il reste <strong>{euros(solde)}</strong> à encaisser. Un règlement s&rsquo;enregistre
                  comme une ligne négative sur la note — il n&rsquo;a pas de geste à lui.
                </div>
              )}
              {soldeNul && statut === 'closed' && (
                <p className="hint">Le solde est nul : le séjour peut être marqué réglé.</p>
              )}
              <div className="bar">
                <button type="button" className="btn" onClick={onFermer}>Retour aux séjours</button>
                {peutFacturer && (
                  <button type="button" className="btn" onClick={() => setAjout('ligne')} disabled={enCours}>
                    Ajouter une ligne
                  </button>
                )}
                {peutFacturer && !soldeNul && (
                  <button type="button" className="btn primary" onClick={() => setAjout('reglement')} disabled={enCours}>
                    Enregistrer un règlement
                  </button>
                )}
                {statut === 'open' && peutCloturer && (
                  <button
                    type="button"
                    className={soldeNul ? 'btn primary' : 'btn'}
                    onClick={cloturer}
                    disabled={enCours}
                  >
                    Clôturer
                  </button>
                )}
                {statut === 'closed' && peutRegler && (
                  <button
                    type="button"
                    className="btn primary"
                    onClick={regler}
                    disabled={enCours || !soldeNul}
                    title={soldeNul ? undefined : 'Le serveur exige un solde nul'}
                  >
                    Marquer réglé
                  </button>
                )}
              </div>
            </>
          )}

          {statut === 'settled' && (
            <div className="bar">
              <button type="button" className="btn" onClick={onFermer}>Retour aux séjours</button>
            </div>
          )}
        </>
      )}

    </>
  )
}

function AjouterLigne({ reglement, solde, onFermer, onValider }) {
  // Un règlement pré-rempli AU CENTIME : c'est ce qui rend le séjour réglable, et le saisir à la
  // main est exactement l'endroit où l'on se trompe d'un centime — après quoi le solde n'est plus
  // nul et le règlement devient impossible.
  const [libelle, setLibelle] = useState(reglement ? 'Règlement' : '')
  const [montant, setMontant] = useState(
    reglement ? (-solde).toFixed(2) : '',
  )
  const [enCours, setEnCours] = useState(false)

  const montantValide = MONTANT.test(montant.trim())
  const restant = reglement ? solde + Number(montant || 0) : null

  async function valider() {
    setEnCours(true)
    // ⚠ LE MONTANT PART EN CHAÎNE. Le serveur refuse explicitement un flottant JSON : `40.1`
    // déserialisé en nombre perdrait le centime avant d'arriver au processeur.
    await onValider({ label: libelle.trim(), amount: montant.trim() })
    setEnCours(false)
  }

  return (
    <div className="card card-espacee">
      <div className="card-h">
        <h4>{reglement ? 'Enregistrer un règlement' : 'Ajouter une ligne'}</h4>
      </div>
      {reglement && (
        <p className="hint">
          Un règlement n&rsquo;a pas de geste à lui : il s&rsquo;enregistre comme une ligne
          <strong> négative</strong>, qui ramène le solde à zéro. Le montant est pré-rempli avec ce
          qui reste dû.
        </p>
      )}

      <div className="field">
        <label htmlFor="sj-lib">Libellé</label>
        <input
          id="sj-lib"
          className="input"
          value={libelle}
          onChange={(e) => setLibelle(e.target.value)}
          placeholder={reglement ? 'Règlement carte bancaire' : 'Bar — 2 demis'}
        />
      </div>

      <div className="field">
        <label htmlFor="sj-mnt">Montant</label>
        <input
          id="sj-mnt"
          className="input num"
          value={montant}
          onChange={(e) => setMontant(e.target.value)}
          placeholder="9.00"
          inputMode="decimal"
        />
        <div className="hint">
          Deux décimales au plus, point décimal. Un montant négatif est accepté : c&rsquo;est ainsi
          que s&rsquo;enregistrent les règlements et les gestes commerciaux.
        </div>
      </div>

      {montant.trim() !== '' && !montantValide && (
        <div className="banner banner-error">
          Le serveur n&rsquo;accepte que des montants comme <span className="mono">9</span>,{' '}
          <span className="mono">9.00</span> ou <span className="mono">-9.00</span>.
        </div>
      )}

      {reglement && montantValide && (
        <div className={restant === 0 ? 'banner banner-ok' : 'banner banner-warn'}>
          {restant === 0
            ? 'Après cette ligne, le solde sera nul : le séjour pourra être clôturé puis réglé.'
            : `Après cette ligne, il restera ${euros(restant)} — et un séjour ne peut être réglé qu’à solde nul.`}
        </div>
      )}

      <div className="bar">
        <button type="button" className="btn" onClick={onFermer}>Annuler</button>
        <button
          type="button"
          className="btn primary"
          onClick={valider}
          disabled={enCours || !libelle.trim() || !montantValide}
        >
          {enCours ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </div>
  )
}

/* ---------------------------------------------------------------------------- Ouvrir un séjour */

function OuvrirSejour({ client, onFermer, onValider }) {
  const [arrivee, setArrivee] = useState(() => aujourdhui())
  const [depart, setDepart] = useState('')
  const [enCours, setEnCours] = useState(false)

  const departAvantArrivee = depart && arrivee && depart < arrivee

  async function valider() {
    setEnCours(true)
    await onValider({ client, arrivalDate: arrivee, expectedDepartureDate: depart || null })
    setEnCours(false)
  }

  return (
    <Modal open onClose={onFermer} titre="Nouveau séjour" taille="md">
      <div className="deflist">
        <div><span>Client</span><span>{nomClient(client)}</span></div>
      </div>

      <div className="field">
        <label htmlFor="sj-arr">Arrivée</label>
        <input
          id="sj-arr"
          className="input"
          type="date"
          value={arrivee}
          onChange={(e) => setArrivee(e.target.value)}
        />
      </div>

      <div className="field">
        <label htmlFor="sj-dep">Départ prévu (facultatif)</label>
        <input
          id="sj-dep"
          className="input"
          type="date"
          value={depart}
          onChange={(e) => setDepart(e.target.value)}
        />
        <div className="hint">
          Laissez vide pour un séjour sans terme annoncé — le départ réel est la date de clôture.
        </div>
      </div>

      {departAvantArrivee && (
        <div className="banner banner-error">
          Le départ ne peut pas précéder l&rsquo;arrivée — le serveur refuserait le séjour.
        </div>
      )}

      <p className="hint">
        La référence du séjour est fabriquée par le serveur, volontairement non séquentielle : une
        référence devinable permettrait de sonder l&rsquo;existence des séjours d&rsquo;un autre
        établissement.
      </p>

      <div className="bar">
        <button type="button" className="btn" onClick={onFermer}>Annuler</button>
        <button
          type="button"
          className="btn primary"
          onClick={valider}
          disabled={enCours || !arrivee || departAvantArrivee}
        >
          {enCours ? 'Ouverture…' : 'Ouvrir le séjour'}
        </button>
      </div>
    </Modal>
  )
}
