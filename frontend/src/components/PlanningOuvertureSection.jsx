import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'

/**
 * LE PLANNING D'OUVERTURE — les heures du site, et la case qui les rend opposables.
 *
 * **La case d'abord, et en haut.** « Faire appliquer au contrôle d'accès » n'est pas un réglage
 * parmi d'autres : c'est la seule ligne de cet écran qui peut refuser quelqu'un à la porte. La
 * placer en tête, avec ce qu'elle fait écrit en toutes lettres, est la différence entre un
 * exploitant qui décide et un exploitant qui découvre.
 *
 * **Décochée par défaut, et l'écran le dit.** Arbitrage de Maxime le 28/08 : un site peut saisir ses
 * horaires pour les afficher, sans que sa porte se mette à refuser du monde. Tant que la case est
 * décochée, l'écran annonce que le planning est *informatif* — sinon on lirait ces heures comme une
 * règle en vigueur, ce qu'elles ne sont pas.
 */

const JOURS = [
  [1, 'Lundi'], [2, 'Mardi'], [3, 'Mercredi'], [4, 'Jeudi'],
  [5, 'Vendredi'], [6, 'Samedi'], [7, 'Dimanche'],
]

function hhmm(v) {
  if (!v) return ''
  // L'API rend une heure au format `HH:MM:SS` ou une date ISO selon les sérialiseurs : on garde
  // les cinq premiers caractères utiles, sans supposer laquelle des deux formes arrive.
  const s = String(v)
  const m = s.match(/(\d{2}:\d{2})/)
  return m ? m[1] : s.slice(0, 5)
}

export default function PlanningOuvertureSection({ droits = [], etabActif = null }) {
  const peutGerer = aLeDroit(droits, 'organisation.gerer') || aLeDroit(droits, 'acces.gerer')

  const [reglage, setReglage] = useState(null)
  const [plages, setPlages] = useState([])
  const [exceptions, setExceptions] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)
  const [ajoutPlage, setAjoutPlage] = useState(false)
  const [ajoutException, setAjoutException] = useState(false)
  // La date que le calendrier a fait choisir. `null` quand on passe par le bouton :
  // la modale demande alors la date, comme avant.
  const [dateChoisie, setDateChoisie] = useState(null)
  const [indices, setIndices] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // L'ANNEE CIVILE EN COURS ET LA SUIVANTE : c'est la periode sur laquelle un exploitant
      // prepare ses fermetures. Au-dela, il ne les saisit pas encore ; en deca, il les a deja.
      const an = new Date().getFullYear()
      const [r, p, e, h] = await Promise.all([
        api.reglageOuverture(),
        api.plagesOuverture(),
        api.exceptionsOuverture(),
        // Les indices echouent en silence : ils sont un CONFORT. Si le calendrier scolaire ne
        // repond pas, les horaires doivent rester saisissables — un service externe en panne ne
        // ferme pas le produit.
        api.indicesOuverture(`${an}-01-01`, `${an + 1}-12-31`).catch(() => null),
      ])
      setReglage(membres(r)[0] || null)
      setPlages(membres(p))
      setExceptions(membres(e))
      setIndices(h)
    } catch (err) {
      setErreur(err.message || 'Le planning d’ouverture n’a pas pu être chargé.')
    } finally {
      setChargement(false)
    }
    // Voir `Agenda` : `etabActif` n'est pas lu ici, il DIT à React que la réponse précédente
    // appartient à un autre site. Sans lui, l'onglet restait vide sur un site qui a des horaires.
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

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

  if (chargement) {
    return <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
  }

  const applique = !!reglage?.enforced

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="card">
        <div className="card-h">
          <h3>Faire appliquer au contrôle d’accès</h3>
          <span className={`badge ${applique ? 'good' : 'mut'}`}>{applique ? 'En vigueur' : 'Informatif'}</span>
        </div>
        <div className="card-b">
          {/* Le bandeau dit la CONSÉQUENCE, pas l'état. « Informatif » se lit vite ; « personne ne
              sera refusé » se comprend. */}
          <div className={applique ? 'banner banner-warn' : 'banner banner-ok'}>
            {applique
              ? 'Un passage présenté en dehors des heures ci-dessous est REFUSÉ à la porte, avec le motif « hors horaires d’ouverture ».'
              : 'Ces horaires ne sont qu’informatifs : personne n’est refusé à la porte tant que cette case est décochée.'}
          </div>
          {peutGerer ? (
            <label className="msgr-note-b" style={{ marginTop: 4 }}>
              <input
                type="checkbox"
                checked={applique}
                disabled={busy || !reglage}
                onChange={(e) => agir(() => api.majReglageOuverture(reglage.id, { enforced: e.target.checked }))}
              />
              Refuser les passages hors des heures d’ouverture
            </label>
          ) : (
            <div className="hint">
              Modifier ce réglage demande un droit d’administration que votre profil n’a pas.
            </div>
          )}
        </div>
      </div>

      <ZoneEtDroitLocal
        reglage={reglage}
        peutGerer={peutGerer}
        busy={busy}
        onChanger={(corps) => agir(() => api.majReglageOuverture(reglage.id, corps))}
      />

      <JoursFeries
        indices={indices}
        exceptions={exceptions}
        peutGerer={peutGerer}
        busy={busy}
        onFermer={(date, libelle) =>
          agir(() => api.creerExceptionOuverture({ date, type: 'closure', reason: libelle }))
        }
        onRouvrir={(id) => agir(() => api.supprimerExceptionOuverture(id))}
      />

      <VacancesScolaires indices={indices} />

      <div className="card">
        <div className="card-h">
          {/* « Horaires » et non « Heures » : c'est le libellé de l'ONGLET, celui que
              l'utilisateur apprend — et celui vers lequel les écrans de contrôle d'accès
              renvoient quand un passage est refusé pour cause d'horaires. Un renvoi juste
              vers un onglet qui se renomme en cours de route fait chercher. */}
          <h3>Horaires d’ouverture</h3>
          {peutGerer && (
            <div className="r">
              <button className="btn sm" type="button" onClick={() => setAjoutPlage(true)}>+ Ajouter une tranche</button>
            </div>
          )}
        </div>
        <div className="card-b">
          {plages.length === 0 ? (
            <div className="empty">
              Aucune heure d’ouverture saisie. Ajoutez une tranche par jour ouvré — plusieurs par
              jour si le site ferme le midi.
            </div>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Jour</th>
                    <th>De</th>
                    <th>À</th>
                    <th>Libellé</th>
                    {peutGerer && <th />}
                  </tr>
                </thead>
                <tbody>
                  {[...plages]
                    .sort((a, b) => a.jour - b.jour || hhmm(a.heureDebut).localeCompare(hhmm(b.heureDebut)))
                    .map((p) => (
                      <tr key={p.id}>
                        <td>{JOURS.find(([n]) => n === p.weekday)?.[1] || p.weekday}</td>
                        <td>{hhmm(p.startTime)}</td>
                        <td>
                          {hhmm(p.endTime)}
                          {/* Une tranche qui traverse minuit finit LE LENDEMAIN. Sans cette
                              mention, « 22:00 → 02:00 » se lit comme une erreur de saisie. */}
                          {p.overnight && <span className="badge info" style={{ marginLeft: 6 }}>le lendemain</span>}
                        </td>
                        <td>{p.label || <span className="sub">—</span>}</td>
                        {peutGerer && (
                          <td className="num">
                            <button
                              className="btn sm"
                              type="button"
                              disabled={busy}
                              onClick={() => agir(() => api.supprimerPlageOuverture(p.id))}
                            >
                              Retirer
                            </button>
                          </td>
                        )}
                      </tr>
                    ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      <CalendrierJoursParticuliers
        exceptions={exceptions}
        indices={indices}
        peutGerer={peutGerer}
        onChoisir={(date) => {
          setDateChoisie(date)
          setAjoutException(true)
        }}
        onRetirer={(id) => agir(() => api.supprimerExceptionOuverture(id))}
      />

      <div className="card">
        <div className="card-h">
          <h3>Jours particuliers</h3>
          {peutGerer && (
            <div className="r">
              <button className="btn sm" type="button" onClick={() => setAjoutException(true)}>+ Ajouter</button>
            </div>
          )}
        </div>
        <div className="card-b">
          {exceptions.length === 0 ? (
            <div className="empty">
              Aucun jour particulier. Les fériés, fermetures techniques et nocturnes se saisissent
              ici — ils l’emportent sur les heures habituelles.
            </div>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Nature</th>
                    <th>Heures</th>
                    <th>Motif</th>
                    {peutGerer && <th />}
                  </tr>
                </thead>
                <tbody>
                  {[...exceptions]
                    .sort((a, b) => String(a.date).localeCompare(String(b.date)))
                    .map((e) => (
                      <tr key={e.id}>
                        <td>{new Date(e.date).toLocaleDateString('fr-FR')}</td>
                        <td>
                          <span className={`badge ${e.type === 'closure' ? 'crit' : 'good'}`}>
                            {e.type === 'closure' ? 'Fermeture' : 'Ouverture exceptionnelle'}
                          </span>
                        </td>
                        <td>
                          {e.allDay ? 'Journée entière' : `${hhmm(e.startTime)} – ${hhmm(e.endTime)}`}
                        </td>
                        <td>{e.reason}</td>
                        {peutGerer && (
                          <td className="num">
                            <button
                              className="btn sm"
                              type="button"
                              disabled={busy}
                              onClick={() => agir(() => api.supprimerExceptionOuverture(e.id))}
                            >
                              Retirer
                            </button>
                          </td>
                        )}
                      </tr>
                    ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      <AjouterPlage
        open={ajoutPlage}
        onFermer={() => setAjoutPlage(false)}
        onCree={() => {
          setAjoutPlage(false)
          recharger()
        }}
      />
      <AjouterException
        open={ajoutException}
        dateInitiale={dateChoisie}
        onFermer={() => {
          setAjoutException(false)
          setDateChoisie(null)
        }}
        onCree={() => {
          setAjoutException(false)
          setDateChoisie(null)
          recharger()
        }}
      />
    </div>
  )
}

/**
 * ZONE SCOLAIRE ET DROIT LOCAL — deux réglages qui ne font pas la même chose, et l'écran le dit.
 *
 * La zone n'ouvre ni ne ferme : elle sert à voir les vacances en fond de calendrier. Le droit local
 * d'Alsace-Moselle, lui, ajoute deux JOURS FÉRIÉS. Les présenter côte à côte sans écrire cette
 * différence laisserait croire qu'une zone peut fermer un site.
 */
function ZoneEtDroitLocal({ reglage, peutGerer, busy, onChanger }) {
  return (
    <div className="card">
      <div className="card-h">
        <h3>Zone scolaire et droit local</h3>
      </div>
      <div className="card-b">
        <div className="field">
          <label htmlFor="op-zone">Zone de vacances scolaires</label>
          <select
            id="op-zone"
            className="select"
            style={{ maxWidth: 260 }}
            value={reglage?.schoolZone || ''}
            disabled={!peutGerer || busy || !reglage}
            onChange={(e) => onChanger({ schoolZone: e.target.value || null })}
          >
            <option value="">Aucune — ne pas afficher les vacances</option>
            <option value="A">Zone A</option>
            <option value="B">Zone B</option>
            <option value="C">Zone C</option>
          </select>
          <div className="hint">
            Sert uniquement à afficher les périodes de vacances en fond d’agenda : elle ne ferme
            rien. Choisissez la zone de votre clientèle, qui n’est pas toujours celle de votre
            adresse.
          </div>
        </div>
        <div className="field">
          <label className="msgr-note-b" htmlFor="op-alsace">
            <input
              id="op-alsace"
              type="checkbox"
              checked={!!reglage?.alsaceMoselle}
              disabled={!peutGerer || busy || !reglage}
              onChange={(e) => onChanger({ alsaceMoselle: e.target.checked })}
            />
            Établissement en Alsace-Moselle
          </label>
          {/*
            ⚠ CE TEXTE REPOND A UNE QUESTION QUI A ETE POSEE. Maxime, en revue le 29/08 :
            « Pourquoi il n'y a que haut rhin bas rhin ou moselle ? »

            L'ecran expliquait la CONSEQUENCE — deux jours feries de plus — et jamais la RAISON.
            Lu vite, trois noms de departements ressemblent a une liste incomplete de lieux pris en
            charge, et la question vient d'elle-meme : et les autres ?

            C'est une liste EXHAUSTIVE d'exceptions, pas une liste partielle d'options. On le dit
            donc, et on dit aussi ce qu'il faut faire ailleurs : rien.
          */}
          <div className="hint">
            <strong>Bas-Rhin, Haut-Rhin et Moselle uniquement.</strong> Ce sont les trois seuls
            départements où un droit local, hérité du Concordat, ajoute <strong>deux jours
            fériés</strong> : le Vendredi saint et le 26 décembre. Ils apparaîtront dans les
            propositions ci-dessous. Partout ailleurs en France, il n’y a rien à cocher — non
            parce que ce n’est pas pris en charge, mais parce qu’il n’y a pas de jour férié
            supplémentaire.
          </div>
        </div>
      </div>
    </div>
  )
}

/**
 * LES JOURS FÉRIÉS SONT PROPOSÉS, JAMAIS IMPOSÉS.
 *
 * Ils sont calculés — huit dates fixes, trois dérivées de Pâques — donc toujours justes et
 * disponibles hors ligne. Mais fermer un jour férié est une DÉCISION : une patinoire fait son année
 * le 25 décembre, une salle de sport ouvre le 1er mai. Chaque ligne est donc une case, et cocher
 * crée une fermeture journée entière — décocher la retire.
 *
 * La case reflète l'état RÉEL (une fermeture existe-t-elle à cette date ?) et non une intention :
 * une case cochée qui ne correspond à rien apprend à ne plus lire les cases.
 */
function JoursFeries({ indices, exceptions, peutGerer, busy, onFermer, onRouvrir }) {
  const feries = indices?.publicHolidays || []
  const aujourdHui = new Date().toISOString().slice(0, 10)
  const aVenir = feries.filter((f) => f.date >= aujourdHui)

  // On retrouve la fermeture correspondante pour pouvoir la retirer. Match sur la date ET sur
  // « journée entière » : une coupure de 14 h à 16 h le 14 juillet n'est pas une fermeture du
  // 14 juillet, et la décocher ne doit pas l'effacer.
  const fermetureDu = (date) =>
    exceptions.find((e) => String(e.date).slice(0, 10) === date && e.type === 'closure' && e.allDay)

  return (
    <div className="card">
      <div className="card-h">
        <h3>Jours fériés</h3>
        <span className="sub">calculés, pas téléchargés</span>
      </div>
      <div className="card-b">
        {aVenir.length === 0 ? (
          <div className="empty">
            Aucun jour férié à venir sur les deux années en cours.
          </div>
        ) : (
          <>
            <p className="sub" style={{ marginTop: 0 }}>
              Cochez ceux où votre site est fermé. Rien n’est coché d’avance : fermer un jour férié
              est une décision, pas une règle.
            </p>
            <div className="cal-avenir">
              {aVenir.map((f) => {
                const fermeture = fermetureDu(f.date)
                return (
                  <label key={f.date} className="msgr-note-b">
                    <input
                      type="checkbox"
                      checked={!!fermeture}
                      disabled={!peutGerer || busy}
                      onChange={(e) =>
                        e.target.checked ? onFermer(f.date, f.label) : onRouvrir(fermeture.id)
                      }
                    />
                    <span className="cal-avenir-q">
                      {/* ⚠ L'ANNÉE EST AFFICHÉE, ET LA LISTE EN COUVRE DEUX. Sans elle, après
                          « ven. 25 décembre » venait « ven. 01 janvier » sans qu'on sache lequel.
                          Ce n'est pas une liste qu'on lit, c'est une liste sur laquelle on CLIQUE
                          pour poser une fermeture : se tromper d'année, c'est fermer un site un
                          jour où il devait ouvrir. Sur CHAQUE ligne — ne la mettre qu'au changement
                          d'année demanderait au lecteur de déduire. Relevé par la revue d'écrans
                          de Maxime, le 29/08. */}
                      {new Date(f.date).toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'long', year: 'numeric' })}
                    </span>
                    <span className="cal-avenir-t">{f.label}</span>
                    {/* Un exploitant du Bas-Rhin qui compare avec un collègue parisien doit
                        comprendre d'où sortent ces deux lignes de plus. */}
                    {f.local && <span className="badge info">droit local</span>}
                  </label>
                )
              })}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

/**
 * LES VACANCES SCOLAIRES SONT EN LECTURE SEULE, ET C'EST LE POINT.
 *
 * Elles viennent d'un arrêté ministériel : personne ne les modifie depuis un logiciel de
 * billetterie. Les rendre cliquables ferait croire le contraire.
 *
 * ⚠ « Aucune vacance » et « le ministère n'a pas répondu » sont deux phrases différentes, et
 * l'écran les écrit différemment. Sans cette distinction, un jour de panne s'afficherait comme une
 * année sans vacances scolaires — un mensonge tranquille, qui ne se découvre jamais.
 */
function VacancesScolaires({ indices }) {
  if (!indices) return null
  const periodes = indices.schoolHolidays || []

  return (
    <div className="card">
      <div className="card-h">
        <h3>Vacances scolaires</h3>
        <span className="sub">source : calendrier officiel du ministère</span>
      </div>
      <div className="card-b">
        {!indices.schoolHolidaysAvailable ? (
          <div className="banner banner-warn">
            {indices.schoolHolidaysReason || 'Le calendrier scolaire officiel n’a pas répondu.'} Les
            horaires restent modifiables ; seul l’affichage des périodes manque.
          </div>
        ) : periodes.length === 0 ? (
          <div className="empty">
            {indices.schoolHolidaysReason
              || 'Aucune période de vacances sur les deux années en cours pour cette zone.'}
          </div>
        ) : (
          <>
            <p className="sub" style={{ marginTop: 0 }}>
              Elles ne ferment rien : elles expliquent une fréquentation. Adaptez vos tranches si
              vous ouvrez différemment pendant ces périodes.
            </p>
            <div className="cal-avenir">
              {periodes.map((p) => (
                <div key={`${p.label}-${p.start}`} className="cal-avenir-l">
                  <span className="cal-pastille" />
                  <span className="cal-avenir-q">
                    {new Date(p.start).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })}
                    {' → '}
                    {new Date(p.end).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })}
                  </span>
                  <span className="cal-avenir-t">{p.label}</span>
                </div>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

const JOURS_COURTS = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']

function ymd(d) {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/**
 * LE CALENDRIER DE SAISIE — cliquer un jour, plutôt que taper une date.
 *
 * Un champ date demande de SAVOIR la date. Le calendrier la MONTRE, avec ce qui la rend
 * particulière : le férié, les vacances, la fermeture déjà posée. « Le 8 mai tombe un vendredi
 * cette année » est une information qu'un champ date ne donne pas, et c'est souvent elle qui décide.
 *
 * ⚠ **Les trois informations d'une case ne se confondent pas.** Une fermeture remplit la case —
 * c'est une décision prise. Un férié met une pastille — c'est une proposition. Une période de
 * vacances teinte le fond — elle ne ferme rien. Les rendre d'une seule couleur ferait lire
 * « fermé » là où il n'y a qu'un jour férié possible.
 */
function CalendrierJoursParticuliers({ exceptions, indices, peutGerer, onChoisir, onRetirer }) {
  const [ancre, setAncre] = useState(() => new Date())

  const premier = new Date(ancre.getFullYear(), ancre.getMonth(), 1)
  const debut = new Date(premier)
  debut.setHours(0, 0, 0, 0)
  // `getDay()` rend 0 pour dimanche : sans ce décalage la semaine commencerait un dimanche.
  debut.setDate(debut.getDate() - ((debut.getDay() + 6) % 7))

  const cases = []
  const curseur = new Date(debut)
  for (let i = 0; i < 42; i += 1) {
    cases.push(new Date(curseur))
    curseur.setDate(curseur.getDate() + 1)
  }

  const feries = {}
  for (const f of indices?.publicHolidays || []) feries[f.date] = f.label
  const vacances = indices?.schoolHolidays || []
  const parDate = {}
  for (const e of exceptions) {
    const d = String(e.date).slice(0, 10)
    if (!parDate[d]) parDate[d] = []
    parDate[d].push(e)
  }

  const aujourdHui = new Date()
  const decaler = (sens) => {
    const d = new Date(ancre)
    d.setMonth(d.getMonth() + sens)
    setAncre(d)
  }

  return (
    <div className="card">
      <div className="card-h">
        <h3>Choisir un jour sur le calendrier</h3>
        <div className="r">
          <button className="btn sm" type="button" onClick={() => decaler(-1)} aria-label="Mois précédent">‹</button>
          <button className="btn sm" type="button" onClick={() => setAncre(new Date())}>Ce mois-ci</button>
          <button className="btn sm" type="button" onClick={() => decaler(1)} aria-label="Mois suivant">›</button>
          <span className="cal-titre">
            {ancre.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}
          </span>
        </div>
      </div>
      <div className="card-b">
        <p className="sub" style={{ marginTop: 0 }}>
          {peutGerer
            ? 'Cliquez un jour pour le déclarer fermé ou ouvert exceptionnellement. Un jour déjà saisi se retire d’un clic.'
            : 'Les jours colorés portent une fermeture ou une ouverture exceptionnelle. Les modifier demande un droit d’administration.'}
        </p>
        <div className="cal-mois">
          {JOURS_COURTS.map((j) => (
            <div key={j} className="cal-mois-h">{j}</div>
          ))}
          {cases.map((jour) => {
            const cle = ymd(jour)
            const posees = parDate[cle] || []
            const ferie = feries[cle]
            const vac = vacances.find((v) => v.start <= cle && cle <= v.end)
            const classes = ['cal-jour']
            if (jour.getMonth() !== ancre.getMonth()) classes.push('hors')
            if (vac) classes.push('vac')
            if (posees.some((e) => e.type === 'closure')) classes.push('ferme')
            else if (posees.length > 0) classes.push('special')
            if (ymd(jour) === ymd(aujourdHui)) classes.push('on')

            const titre = [
              ferie,
              vac?.label,
              ...posees.map((e) => e.reason),
            ].filter(Boolean).join(' · ')

            return (
              <button
                key={cle}
                type="button"
                className={classes.join(' ')}
                title={titre || undefined}
                disabled={!peutGerer}
                onClick={() => (posees.length > 0 ? onRetirer(posees[0].id) : onChoisir(cle))}
              >
                <span className="cal-jour-n">{jour.getDate()}</span>
                {ferie && <span className="cal-jour-f" aria-hidden="true" />}
              </button>
            )
          })}
        </div>
      </div>
    </div>
  )
}

function AjouterPlage({ open, onFermer, onCree }) {
  const [jour, setJour] = useState(1)
  const [debut, setDebut] = useState('09:00')
  const [fin, setFin] = useState('18:00')
  const [libelle, setLibelle] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!open) return
    setJour(1)
    setDebut('09:00')
    setFin('18:00')
    setLibelle('')
    setErreur(null)
  }, [open])

  const traverse = fin <= debut

  async function envoyer(e) {
    e.preventDefault()
    setBusy(true)
    setErreur(null)
    try {
      await api.creerPlageOuverture({
        weekday: Number(jour),
        startTime: `${debut}:00`,
        endTime: `${fin}:00`,
        ...(libelle.trim() ? { label: libelle.trim() } : {}),
      })
      onCree()
    } catch (err) {
      setErreur(err.message || 'La tranche n’a pas pu être ajoutée.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={open} onClose={onFermer} titre="Ajouter une tranche d’ouverture">
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="field">
          <label htmlFor="op-jour">Jour *</label>
          <select id="op-jour" className="select" value={jour} onChange={(e) => setJour(e.target.value)}>
            {JOURS.map(([n, l]) => (
              <option key={n} value={n}>{l}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="op-debut">Ouverture *</label>
          <input id="op-debut" className="input" type="time" required value={debut} onChange={(e) => setDebut(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="op-fin">Fermeture *</label>
          <input id="op-fin" className="input" type="time" required value={fin} onChange={(e) => setFin(e.target.value)} />
          {/* On ANNONCE la traversée de minuit au lieu de la refuser : 22 h → 02 h est une soirée,
              pas une faute de frappe, et le socle porte une capacité « accès nocturne ». */}
          <div className="hint">
            {traverse
              ? 'La fermeture précède l’ouverture : cette tranche se termine le lendemain matin.'
              : 'Plusieurs tranches par jour sont possibles — par exemple pour une fermeture le midi.'}
          </div>
        </div>
        <div className="field">
          <label htmlFor="op-libelle">Libellé</label>
          <input id="op-libelle" className="input" value={libelle} onChange={(e) => setLibelle(e.target.value)} />
          <div className="hint">« Nocturne », « Créneau scolaire »… facultatif, c’est un repère pour vous.</div>
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={busy}>Ajouter</button>
        </div>
      </form>
    </Modal>
  )
}

function AjouterException({ open, dateInitiale = null, onFermer, onCree }) {
  const [date, setDate] = useState('')
  const [type, setType] = useState('closure')
  const [journee, setJournee] = useState(true)
  const [debut, setDebut] = useState('10:00')
  const [fin, setFin] = useState('13:00')
  const [motif, setMotif] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!open) return
    // La date vient du calendrier quand on y a cliqué ; sinon la modale la redemande, comme avant.
    setDate(dateInitiale || '')
    setType('closure')
    setJournee(true)
    setDebut('10:00')
    setFin('13:00')
    setMotif('')
    setErreur(null)
  }, [open, dateInitiale])

  // Une ouverture exceptionnelle SANS heures n'a pas de sens — le serveur la refuse. L'écran ne
  // laisse donc pas arriver jusque-là : la case journée entière disparaît quand on choisit
  // « ouverture ». Refuser après coup ferait ressaisir une date et un motif pour rien.
  const journeeEntiere = type === 'closure' ? journee : false

  async function envoyer(e) {
    e.preventDefault()
    setBusy(true)
    setErreur(null)
    try {
      await api.creerExceptionOuverture({
        date,
        type,
        reason: motif.trim(),
        ...(journeeEntiere ? {} : { startTime: `${debut}:00`, endTime: `${fin}:00` }),
      })
      onCree()
    } catch (err) {
      setErreur(err.message || 'Le jour particulier n’a pas pu être ajouté.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={open} onClose={onFermer} titre="Ajouter un jour particulier">
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="field">
          <label htmlFor="oe-date">Date *</label>
          <input id="oe-date" className="input" type="date" required value={date} onChange={(e) => setDate(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="oe-type">Nature *</label>
          <select id="oe-type" className="select" value={type} onChange={(e) => setType(e.target.value)}>
            <option value="closure">Fermeture</option>
            <option value="special_opening">Ouverture exceptionnelle</option>
          </select>
        </div>
        {type === 'closure' && (
          <div className="field">
            <label className="msgr-note-b" htmlFor="oe-journee">
              <input id="oe-journee" type="checkbox" checked={journee} onChange={(e) => setJournee(e.target.checked)} />
              Journée entière
            </label>
          </div>
        )}
        {!journeeEntiere && (
          <>
            <div className="field">
              <label htmlFor="oe-debut">De *</label>
              <input id="oe-debut" className="input" type="time" required value={debut} onChange={(e) => setDebut(e.target.value)} />
            </div>
            <div className="field">
              <label htmlFor="oe-fin">À *</label>
              <input id="oe-fin" className="input" type="time" required value={fin} onChange={(e) => setFin(e.target.value)} />
            </div>
          </>
        )}
        <div className="field">
          <label htmlFor="oe-motif">Motif *</label>
          <input id="oe-motif" className="input" required value={motif} onChange={(e) => setMotif(e.target.value)} />
          <div className="hint">
            Dans six mois, personne ne saura pourquoi ce jour était différent. Écrivez-le.
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={busy || date === '' || motif.trim() === ''}>Ajouter</button>
        </div>
      </form>
    </Modal>
  )
}
