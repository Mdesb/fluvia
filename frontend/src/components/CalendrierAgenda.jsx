import { useMemo } from 'react'

/**
 * LE CALENDRIER — mois, semaine, jour, et ce que chaque vue sert à voir.
 *
 * **Trois vues, trois questions.** Le mois répond à « quand suis-je pris ce mois-ci » ; la semaine
 * à « où reste-t-il de la place » ; le jour à « qu'est-ce qui s'enchaîne aujourd'hui ». Ce ne sont
 * pas trois tailles de la même image : la vue mois n'a pas d'axe horaire, et lui en donner un la
 * rendrait illisible pour la seule question qu'on lui pose.
 *
 * **Les blocs sont posés à l'heure réelle en semaine et en jour, jamais empilés.** Deux créneaux
 * qui se chevauchent se chevauchent à l'écran — et un chevauchement est une information : sur une
 * ressource unique, c'est une erreur de planification qu'aucune liste triée ne fait apparaître.
 * C'est la règle déjà appliquée par `PlanningSemaine`, reprise ici pour que les deux écrans se
 * lisent pareil.
 *
 * **L'ouverture est un ARRIÈRE-PLAN, pas un rendez-vous.** Une plage d'ouverture n'est pas quelque
 * chose qui a lieu : c'est le cadre dans lequel le reste a lieu. Dessinée comme un bloc ordinaire,
 * elle recouvrirait les créneaux qu'elle est censée encadrer. Dessinée derrière, elle rend visible
 * exactement ce qu'on cherchait : le créneau posé en dehors des heures d'ouverture.
 */

const JOURS_COURTS = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']
const HAUTEUR_HEURE = 44

const COULEURS = {
  opening: 'ouverture',
  reservation: 'reservation',
  shift: 'travail',
  meeting: 'reunion',
  maintenance: 'intervention',
  training: 'formation',
  unavailability: 'indispo',
  other: 'autre',
}

function lundiDe(date) {
  const d = new Date(date)
  d.setHours(0, 0, 0, 0)
  // `getDay()` rend 0 pour dimanche : sans ce décalage, la semaine commencerait un dimanche et
  // l'exploitant verrait son week-end coupé en deux.
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  return d
}

function memeJour(a, b) {
  return (
    a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
  )
}

/**
 * LE LIBELLÉ DE LA PÉRIODE DE VACANCES QUI COUVRE CE JOUR, OU `null`.
 *
 * Les bornes sont INCLUSIVES des deux côtés : le calendrier du ministère publie un premier et un
 * dernier jour de vacances, pas un intervalle semi-ouvert. Traiter la fin comme exclusive
 * rendrait le dernier jour ouvré — visiblement faux un lundi de rentrée.
 */
function vacancesDu(vacances, jour) {
  const j = ymd(jour)
  const periode = vacances.find((v) => v.start <= j && j <= v.end)
  return periode ? periode.label : null
}

/** Vrai si ce jour est le premier de sa période — c'est là qu'on écrit le libellé, et là seulement. */
function premierJourDeVacances(vacances, jour) {
  const j = ymd(jour)
  return vacances.some((v) => v.start === j)
}

function ymd(d) {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

function hhmm(d) {
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

/**
 * Les bornes de la période affichée, en dates locales.
 *
 * Rendues ici et pas dans l'écran : c'est le calendrier qui sait ce qu'une « vue mois » contient —
 * six semaines complètes, débordements des mois voisins compris. L'écran qui devinerait « du 1er au
 * 31 » demanderait au serveur moins que ce qu'il dessine, et les cases de débordement seraient
 * vides sans raison visible.
 */
export function bornes(vue, ancre) {
  const d = new Date(ancre)
  if (vue === 'jour') {
    const j = new Date(d)
    j.setHours(0, 0, 0, 0)
    return [j, j]
  }
  if (vue === 'semaine') {
    const debut = lundiDe(d)
    const fin = new Date(debut)
    fin.setDate(fin.getDate() + 6)
    return [debut, fin]
  }
  const premier = new Date(d.getFullYear(), d.getMonth(), 1)
  const debut = lundiDe(premier)
  const fin = new Date(debut)
  fin.setDate(fin.getDate() + 41)
  return [debut, fin]
}

export function iso(d) {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

export default function CalendrierAgenda({ vue, ancre, evenements = [], vacances = [], onOuvrir }) {
  const [debut, fin] = useMemo(() => bornes(vue, ancre), [vue, ancre])

  const blocs = useMemo(
    () =>
      evenements
        .map((e) => ({ ...e, d1: new Date(e.start), d2: new Date(e.end) }))
        .filter((e) => !Number.isNaN(e.d1.getTime()) && !Number.isNaN(e.d2.getTime()))
        .sort((a, b) => a.d1 - b.d1),
    [evenements],
  )

  if (vue === 'mois') return <VueMois debut={debut} ancre={ancre} blocs={blocs} vacances={vacances} onOuvrir={onOuvrir} />

  const jours = []
  const curseur = new Date(debut)
  while (curseur <= fin) {
    jours.push(new Date(curseur))
    curseur.setDate(curseur.getDate() + 1)
  }
  return <VueGrille jours={jours} blocs={blocs} vacances={vacances} onOuvrir={onOuvrir} />
}

function VueMois({ debut, ancre, blocs, vacances, onOuvrir }) {
  const cases = []
  const curseur = new Date(debut)
  for (let i = 0; i < 42; i += 1) {
    cases.push(new Date(curseur))
    curseur.setDate(curseur.getDate() + 1)
  }
  const aujourdHui = new Date()
  const moisAffiche = ancre.getMonth()

  return (
    <div className="cal-mois">
      {JOURS_COURTS.map((j) => (
        <div key={j} className="cal-mois-h">{j}</div>
      ))}
      {cases.map((jour) => {
        // L'OUVERTURE N'EST PAS LISTÉE EN VUE MOIS, et c'est un choix. Elle vaut presque tous les
        // jours : la lister remplirait chaque case d'une ligne « Ouvert » qui n'apprend rien et
        // repousserait hors de vue ce qu'on cherche vraiment. La vue semaine la montre, en fond,
        // là où elle sert à situer les créneaux.
        const duJour = blocs.filter((b) => memeJour(b.d1, jour) && b.source !== 'opening')
        const hors = jour.getMonth() !== moisAffiche
        const classes = ['cal-mois-c']
        if (hors) classes.push('hors')
        if (memeJour(jour, aujourdHui)) classes.push('on')
        // Le fond de vacances est une TEINTE, jamais un bloc : voir `vacancesDu`.
        const vac = vacancesDu(vacances, jour)
        if (vac) classes.push('vac')
        return (
          <div key={jour.toISOString()} className={classes.join(' ')} title={vac || undefined}>
            <div className="cal-mois-n">{jour.getDate()}</div>
            {duJour.slice(0, 3).map((b) => (
              <button
                key={b.id}
                type="button"
                className={`cal-puce ${COULEURS[b.type] || 'autre'}`}
                onClick={() => onOuvrir?.(b)}
                title={b.title}
              >
                {b.allDay ? '' : `${hhmm(b.d1)} `}
                {b.title}
              </button>
            ))}
            {duJour.length > 3 && <div className="cal-mois-plus">+{duJour.length - 3}</div>}
          </div>
        )
      })}
    </div>
  )
}

function VueGrille({ jours, blocs, vacances, onOuvrir }) {
  // L'AMPLITUDE VIENT DES DONNÉES, PAS D'UNE CONSTANTE. Un plafond fixe à 8 h - 22 h masquerait
  // sans un mot un créneau de 6 h 30 sur un bassin qui ouvre tôt, et une nocturne qui finit à 2 h.
  const [hDebut, hFin] = useMemo(() => {
    let min = 8
    let max = 20
    for (const b of blocs) {
      min = Math.min(min, b.d1.getHours())
      max = Math.max(max, b.d2.getHours() + (b.d2.getMinutes() > 0 ? 1 : 0))
    }
    return [Math.max(0, min), Math.min(24, Math.max(max, min + 4))]
  }, [blocs])

  const heures = []
  for (let h = hDebut; h <= hFin; h += 1) heures.push(h)
  const aujourdHui = new Date()

  return (
    <div className="cal-grille" style={{ gridTemplateColumns: `56px repeat(${jours.length}, 1fr)` }}>
      <div className="cal-grille-coin" />
      {jours.map((j) => (
        <div
          key={j.toISOString()}
          className={memeJour(j, aujourdHui) ? 'cal-grille-h on' : 'cal-grille-h'}
          title={vacancesDu(vacances, j) || undefined}
        >
          <span className="cal-grille-j">{JOURS_COURTS[(j.getDay() + 6) % 7]}</span>
          <span className="cal-grille-d">{j.getDate()}</span>
          {/* Le libellé n'apparaît qu'une fois par période, sur son premier jour visible : le
              répéter sept fois sur une semaine de vacances remplirait l'en-tête sans rien
              ajouter. */}
          {premierJourDeVacances(vacances, j) && (
            <span className="cal-grille-v">{vacancesDu(vacances, j)}</span>
          )}
        </div>
      ))}

      <div className="cal-heures">
        {heures.map((h) => (
          <div key={h} className="cal-heure" style={{ height: HAUTEUR_HEURE }}>
            {String(h).padStart(2, '0')}:00
          </div>
        ))}
      </div>

      {jours.map((jour) => {
        const duJour = blocs.filter((b) => memeJour(b.d1, jour))
        return (
          <div
            key={jour.toISOString()}
            className={vacancesDu(vacances, jour) ? 'cal-colonne vac' : 'cal-colonne'}
            style={{ height: heures.length * HAUTEUR_HEURE }}
          >
            {heures.map((h) => (
              <div key={h} className="cal-ligne" style={{ height: HAUTEUR_HEURE }} />
            ))}
            {duJour.map((b) => {
              const minutesDebut = b.d1.getHours() * 60 + b.d1.getMinutes() - hDebut * 60
              const minutesFin = b.d2.getHours() * 60 + b.d2.getMinutes() - hDebut * 60
              // Un bloc de moins d'un quart d'heure serait un trait illisible : on lui donne une
              // hauteur plancher, quitte à ce qu'il déborde un peu. Mieux vaut lisible et
              // approximatif qu'exact et invisible.
              const hauteur = Math.max(18, ((minutesFin - minutesDebut) / 60) * HAUTEUR_HEURE)
              const dessus = (minutesDebut / 60) * HAUTEUR_HEURE
              const fond = b.source === 'opening'
              return (
                <button
                  key={b.id}
                  type="button"
                  className={`cal-bloc ${COULEURS[b.type] || 'autre'}${fond ? ' fond' : ''}`}
                  style={{ top: dessus, height: hauteur }}
                  onClick={() => onOuvrir?.(b)}
                  title={`${hhmm(b.d1)} – ${hhmm(b.d2)} · ${b.title}`}
                >
                  <span className="cal-bloc-t">{b.title}</span>
                  {hauteur > 34 && <span className="cal-bloc-h">{hhmm(b.d1)} – {hhmm(b.d2)}</span>}
                </button>
              )
            })}
          </div>
        )
      })}
    </div>
  )
}
