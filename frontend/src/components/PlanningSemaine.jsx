import { useMemo, useState } from 'react'
import { nomsDuCreneau } from '../api/slot-label.js'

/**
 * LA VUE SEMAINE — demandée par Maxime depuis le début, et jamais livrée.
 *
 * **Pourquoi une liste par jour ne suffisait pas.** L'écran existant affiche les créneaux d'une
 * journée, triés par heure. C'est exact et illisible pour la seule question qu'un exploitant se pose
 * en ouvrant un planning : *où reste-t-il de la place cette semaine ?* Une liste répond créneau par
 * créneau ; il faut lire quarante lignes et les tenir en tête.
 *
 * Une grille répond en un coup d'œil, parce qu'elle rend visible **ce qui n'est pas là** : les trous.
 * Un créneau vide n'a pas de ligne dans une liste — il n'existe donc pas à l'écran, alors que c'est
 * précisément ce qu'on cherche.
 *
 * > **Une liste montre ce qui existe. Un calendrier montre aussi ce qui manque.**
 *
 * **Les blocs sont positionnés à l'heure réelle, pas empilés.** Un créneau de 14 h à 15 h 30 occupe la
 * hauteur correspondante : deux créneaux qui se chevauchent se chevauchent à l'écran, et un chevauchement
 * est une information — sur une ressource unique, c'est une erreur de planification qu'aucune liste
 * triée ne fait apparaître.
 *
 * **L'amplitude horaire vient des données, pas d'une constante.** Un plafond fixe à 8 h – 22 h
 * masquerait sans un mot un créneau de 6 h 30 sur un bassin qui ouvre tôt. On lit les créneaux, et la
 * grille s'ajuste.
 */

const JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']
const HAUTEUR_HEURE = 46

function lundiDe(date) {
  const d = new Date(date)
  d.setHours(0, 0, 0, 0)
  // `getDay()` rend 0 pour dimanche : sans ce décalage, la semaine commencerait un dimanche et
  // l'exploitant verrait son week-end coupé en deux.
  const decalage = (d.getDay() + 6) % 7
  d.setDate(d.getDate() - decalage)
  return d
}

function memeJour(a, b) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

function heureDecimale(d) {
  return d.getHours() + d.getMinutes() / 60
}

function hhmm(d) {
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

// ⚠ `nonLu` VOYAGE A COTE DE LA LISTE, ET PAS DEDANS. L'appelant aplatit deja (`creneaux || []`),
// donc ce composant ne peut pas distinguer « vide » de « pas lu » — et faire voyager un `null` a
// sa place casserait tout ce qui le parcourt.
export default function PlanningSemaine({ creneaux, occupation, aConfirmer, ressources, onCreneau, nonLu }) {
  const [depart, setDepart] = useState(() => lundiDe(new Date()))
  const [ressourceId, setRessourceId] = useState('')

  const jours = useMemo(() => {
    return Array.from({ length: 7 }, (_, i) => {
      const d = new Date(depart)
      d.setDate(d.getDate() + i)
      return d
    })
  }, [depart])

  const visibles = useMemo(() => {
    const fin = new Date(depart)
    fin.setDate(fin.getDate() + 7)
    return creneaux
      .map((c) => ({ ...c, _debut: new Date(c.debut), _fin: new Date(c.fin) }))
      .filter((c) => !Number.isNaN(c._debut.getTime()))
      .filter((c) => c._debut >= depart && c._debut < fin)
      .filter((c) => {
        if (!ressourceId) return true
        const id = typeof c.ressource === 'string' ? c.ressource.split('/').pop() : c.ressource?.id
        return id === ressourceId
      })
  }, [creneaux, depart, ressourceId])

  // L'amplitude suit les données. Une semaine vide garde une plage par défaut plutôt qu'une grille
  // d'une ligne : sinon la vue « saute » de hauteur d'une semaine à l'autre.
  const [heureMin, heureMax] = useMemo(() => {
    if (visibles.length === 0) return [8, 20]
    let min = 24
    let max = 0
    for (const c of visibles) {
      min = Math.min(min, Math.floor(heureDecimale(c._debut)))
      max = Math.max(max, Math.ceil(heureDecimale(c._fin) || 24))
    }
    return [Math.max(0, min - 1), Math.min(24, Math.max(max + 1, min + 4))]
  }, [visibles])

  const heures = useMemo(
    () => Array.from({ length: heureMax - heureMin }, (_, i) => heureMin + i),
    [heureMin, heureMax],
  )

  function decaler(semaines) {
    const d = new Date(depart)
    d.setDate(d.getDate() + semaines * 7)
    setDepart(d)
  }

  const libelleSemaine = `${jours[0].toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })} – ${jours[6].toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })}`

  return (
    <div className="card">
      <div className="card-h" style={{ gap: 10, flexWrap: 'wrap' }}>
        <button className="btn ghost sm" type="button" onClick={() => decaler(-1)} aria-label="Semaine précédente">←</button>
        <span style={{ minWidth: 200, textAlign: 'center' }}>{libelleSemaine}</span>
        <button className="btn ghost sm" type="button" onClick={() => decaler(1)} aria-label="Semaine suivante">→</button>
        <button className="btn ghost sm" type="button" onClick={() => setDepart(lundiDe(new Date()))}>
          Cette semaine
        </button>

        <select
          className="select sm"
          style={{ marginLeft: 'auto', width: 220 }}
          value={ressourceId}
          onChange={(e) => setRessourceId(e.target.value)}
        >
          <option value="">Toutes les ressources</option>
          {ressources.map((r) => (
            <option key={r.id} value={r.id}>{r.libelle || r.nom || r.id}</option>
          ))}
        </select>
      </div>

      {/* ⚠ AU-DESSUS DE LA GRILLE, PAS EN DESSOUS. Ce message existait déjà, mais après le
          conteneur : la grille couvre 08 h – 20 h, soit environ 700 px, et il tombait sous la ligne
          de flottaison. On voyait un planning vide sans jamais apprendre qu'il l'était exprès. Un
          état vide qu'il faut chercher ne sert pas. */}
      {visibles.length === 0 && (
        <div className="empty">
          {nonLu
            ? 'Le planning n’a pas pu être lu : cette semaine est vide parce que la lecture a échoué, pas parce qu’aucun créneau n’est ouvert.'
            : `Aucun créneau cette semaine${ressourceId ? ' pour cette ressource' : ''}.`}
        </div>
      )}
      <div style={{ overflowX: 'auto' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '54px repeat(7, minmax(120px, 1fr))', minWidth: 900 }}>
          {/* En-têtes */}
          <div />
          {jours.map((d, i) => {
            const aujourdhui = memeJour(d, new Date())
            return (
              <div
                key={i}
                style={{
                  padding: '8px 6px',
                  textAlign: 'center',
                  borderBottom: '1px solid var(--line)',
                  // Le jour courant se repère sans le chercher : c'est la seule colonne qu'on regarde
                  // en priorité quand on ouvre un planning.
                  background: aujourdhui ? 'var(--panel-2)' : 'transparent',
                  fontWeight: aujourdhui ? 700 : 600,
                  fontSize: 12.5,
                }}
              >
                <div>{JOURS[i]}</div>
                <div className="sub">{d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })}</div>
              </div>
            )
          })}

          {/* Colonne des heures */}
          <div style={{ position: 'relative', height: heures.length * HAUTEUR_HEURE }}>
            {heures.map((h) => (
              <div
                key={h}
                style={{
                  position: 'absolute',
                  top: (h - heureMin) * HAUTEUR_HEURE,
                  right: 6,
                  fontSize: 11,
                  color: 'var(--ink-faint)',
                  transform: 'translateY(-6px)',
                  fontVariantNumeric: 'tabular-nums',
                }}
              >
                {String(h).padStart(2, '0')} h
              </div>
            ))}
          </div>

          {/* Une colonne par jour */}
          {jours.map((jour, i) => {
            const duJour = visibles.filter((c) => memeJour(c._debut, jour))
            return (
              <div
                key={i}
                style={{
                  position: 'relative',
                  height: heures.length * HAUTEUR_HEURE,
                  borderLeft: '1px solid var(--line)',
                }}
              >
                {heures.map((h) => (
                  <div
                    key={h}
                    style={{
                      position: 'absolute',
                      top: (h - heureMin) * HAUTEUR_HEURE,
                      left: 0,
                      right: 0,
                      height: HAUTEUR_HEURE,
                      borderTop: '1px solid var(--line)',
                      opacity: 0.5,
                    }}
                  />
                ))}

                {duJour.map((c) => {
                  const debut = heureDecimale(c._debut)
                  const fin = Number.isNaN(c._fin.getTime()) ? debut + 1 : heureDecimale(c._fin)
                  // ⚠ `occupation` EST LA JAUGE DU SERVEUR, créneau par créneau (`occupees`,
                  // `restantes`, `capacite`). Un créneau absent n'a pas été lu : il n'est ni libre ni
                  // complet, et sa case le dit par un « ? » sur fond neutre — le vert d'une case
                  // libre ferait promettre une place qu'on ne connaît pas.
                  const ligne = occupation?.[c.id]
                  const connue = ligne !== undefined
                  const prises = connue ? ligne.occupees : null
                  const capacite = (connue ? ligne.capacite : c.capacite) || 1
                  const complet = connue && ligne.restantes <= 0
                  const presque = connue && !complet && prises / capacite >= 0.8

                  // ⚠ DES PLACES PRISES PEUVENT ENCORE DISPARAÎTRE (R15 a). Une réservation
                  // `a_confirmer` occupe la place, mais sera LIBÉRÉE à l'échéance si personne ne
                  // confirme. Sans marque, le planning montre un créneau plein qui ne l'est
                  // peut-être pas — et l'exploitant l'apprend le jour où la place se rouvre seule.
                  const enAttente = aConfirmer?.[c.id] || 0
                  // Activité ET ressource : « l'une ou l'autre » laissait lire un terrain pour une activité.
                  const noms = nomsDuCreneau(c)

                  return (
                    <button
                      key={c.id}
                      type="button"
                      onClick={() => onCreneau?.(c)}
                      title={`${hhmm(c._debut)} – ${hhmm(c._fin)}${noms ? ` · ${noms}` : ''} · ${connue ? prises : '?'}/${capacite}` + (connue ? '' : ' · places non lues') + (enAttente > 0 ? ` · ${enAttente} à confirmer` : '')}
                      style={{
                        position: 'absolute',
                        top: (debut - heureMin) * HAUTEUR_HEURE + 1,
                        // Un créneau de quinze minutes reste cliquable : on ne descend jamais sous
                        // 22 px, sinon le planning devient exact et inutilisable.
                        height: Math.max(22, (fin - debut) * HAUTEUR_HEURE - 2),
                        left: 3,
                        right: 3,
                        textAlign: 'left',
                        padding: '3px 6px',
                        fontSize: 11.5,
                        lineHeight: 1.25,
                        borderRadius: 6,
                        cursor: 'pointer',
                        overflow: 'hidden',
                        // ⚠ UN LISERÉ, PAS UNE COULEUR. Le fond dit déjà la place restante, et le
                        // commentaire ci-dessous rappelle qu'une seule information peut l'occuper.
                        // Le trait tireté dit « pas encore ferme » dans un autre canal : les deux
                        // se lisent ensemble au lieu de se disputer.
                        border: enAttente > 0 ? '1px dashed var(--warn)' : '1px solid var(--line)',
                        // La couleur dit la place restante, jamais le type d'activité : c'est la
                        // question qu'on pose au planning, et une seule information peut occuper la
                        // couleur sans que les deux se brouillent.
                        background: !connue
                          ? 'var(--panel)'
                          : complet
                            ? 'color-mix(in srgb, var(--crit) 18%, var(--panel))'
                            : presque
                              ? 'color-mix(in srgb, var(--warn) 20%, var(--panel))'
                              : 'color-mix(in srgb, var(--good) 15%, var(--panel))',
                        color: 'var(--ink)',
                      }}
                    >
                      <b>{hhmm(c._debut)}</b>{' '}
                      <span style={{ fontVariantNumeric: 'tabular-nums' }}>{connue ? prises : '?'}/{capacite}</span>
                      <div className="sub" style={{ fontSize: 11 }}>
                        {noms}
                        {enAttente > 0 ? ` · ${enAttente} à confirmer` : ''}
                      </div>
                    </button>
                  )
                })}
              </div>
            )
          })}
        </div>
      </div>

    </div>
  )
}
