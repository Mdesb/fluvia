import { useEffect, useState } from 'react'
import AbsencesSection from '../components/AbsencesSection.jsx'
import Liste, { dateFr, dateHeureFr } from '../components/Liste.jsx'
import Modal from '../components/Modal.jsx'
import { api } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

const STATUT_EMP = { Actif: 'good', actif: 'good', Suspendu: 'crit', suspendu: 'crit', Sorti: 'mut' }
const STATUT_BADGE = { Actif: 'good', actif: 'good', Revoque: 'crit', revoque: 'crit', Suspendu: 'warn' }
const COUV = { complet: 'good', partiel: 'warn', decouvert: 'crit', incomplet: 'crit' }

// Module Personnel : employés, roster (planning), badges staff.
export default function Personnel({ etabActif, droits = [] }) {
  const [sousOnglet, setSousOnglet] = useState('employes')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Personnel</h1>
          <p>Employés, planning (roster) &amp; badges staff</p>
        </div>
      </div>

      <div className="seg" style={{ marginBottom: 16 }}>
        {[
          ['employes', 'Employés'],
          ['roster', 'Roster'],
          ['badges', 'Badges staff'],
        ].map(([k, l]) => (
          <button key={k} className={sousOnglet === k ? 'on' : ''} onClick={() => setSousOnglet(k)}>{l}</button>
        ))}
      </div>

      {sousOnglet === 'employes' && (
        <ListeEmployes etabActif={etabActif} droits={droits} onBadgeEmis={() => setSousOnglet('badges')} />
      )}

      {sousOnglet === 'roster' && (
        <Liste
          titre="Roster hebdomadaire"
          sous="couverture des postes"
          deps={[etabActif]}
          charger={api.roster}
          vide="Aucun créneau au roster."
          colonnes={[
            { cle: 'poste', entete: 'Poste', rendu: (r) => <span className="nm">{r.poste || '—'}</span> },
            { cle: 'debut', entete: 'Début', rendu: (r) => dateHeureFr(r.debut) },
            { cle: 'fin', entete: 'Fin', rendu: (r) => heure(r.fin) },
            { cle: 'effectifRequis', entete: 'Requis', num: true, rendu: (r) => r.effectifRequis ?? '—' },
            { cle: 'affectes', entete: 'Affectés', num: true, rendu: (r) => (Array.isArray(r.employesAffectes) ? r.employesAffectes.length : 0) },
            { cle: 'statutCouverture', entete: 'Couverture', rendu: (r) => <span className={`badge ${COUV[r.statutCouverture] || 'mut'}`}>{r.statutCouverture || '—'}</span> },
          ]}
        />
      )}

      {sousOnglet === 'badges' && <GestionBadges etabActif={etabActif} droits={droits} />}

      {/* Absences : declarer, accepter, refuser. En bas de l'ecran Personnel parce que c'est une
          decision qui porte sur les gens qu'on vient de lire, pas une activite separee. */}
      <AbsencesSection etabActif={etabActif} droits={droits} />
    </div>
  )
}

/**
 * LES BADGES STAFF — et, depuis le 27/08, de quoi les reprendre.
 *
 * **Ce que l'écran ne savait pas faire.** Il affichait une liste. Émettre, suspendre, révoquer,
 * réactiver : quatre opérations écrites côté serveur, aucune atteignable. Le sous-titre annonçait
 * pourtant « émission / révocation » — il décrivait une intention, pas l'écran.
 *
 * > **Un badge est une clé physique. Ne pas pouvoir le désactiver n'est pas une gêne d'interface,
 * > c'est un accès qui reste ouvert.**
 *
 * **Suspendre et révoquer sont deux gestes distincts, et l'écran refuse de les confondre.** On
 * suspend un badge égaré qu'on retrouvera peut-être ; on révoque celui d'une personne partie. Les
 * mélanger revient soit à rendre une carte à quelqu'un qui n'a plus rien à faire ici, soit à
 * réémettre un badge pour rien.
 *
 * **Le motif est demandé et non deviné.** Le serveur en pose un par défaut — « Révocation manuelle »
 * — qui n'apprend rien à celui qui relira l'historique dans six mois. Un motif écrit par la personne
 * qui décide est la seule trace qui vaille.
 */
function GestionBadges({ etabActif, droits = [] }) {
  const peutGerer = aLeDroit(droits, 'personnel.gerer_badge')
  const [version, setVersion] = useState(0)
  const [demande, setDemande] = useState(null)
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  async function agir(action) {
    setBusy(true)
    setErreur(null)
    try {
      await action()
      setVersion((v) => v + 1)
    } catch (e) {
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  const colonnes = [
    { cle: 'employe', entete: 'Employé', rendu: (r) => String(r.employe?.nom || r.employe || '—').toString().split('/').pop() },
    { cle: 'dateEmission', entete: 'Émis le', rendu: (r) => dateFr(r.dateEmission) },
    { cle: 'dateRevocation', entete: 'Révoqué le', rendu: (r) => dateFr(r.dateRevocation) },
    { cle: 'statut', entete: 'Statut', rendu: (r) => <span className={`badge ${STATUT_BADGE[r.statut] || 'mut'}`}>{r.statut || '—'}</span> },
  ]

  if (peutGerer) {
    colonnes.push({
      cle: 'actions',
      entete: '',
      rendu: (r) => {
        const statut = String(r.statut || '').toLowerCase()
        if (statut === 'revoque') {
          // RIEN A PROPOSER, ET ON L'ECRIT. Un badge revoque ne se reactive pas -- il faut en emettre
          // un neuf. Afficher un bouton grise laisserait croire a un droit manquant.
          return <span className="sub" style={{ fontSize: 12 }}>Définitif</span>
        }
        return (
          <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
            {statut === 'suspendu' ? (
              <button
                className="btn ghost sm"
                type="button"
                disabled={busy}
                style={{ padding: '1px 8px', fontSize: 11.5 }}
                onClick={() => agir(() => api.reactiverBadgeStaff(r.id))}
              >
                Réactiver
              </button>
            ) : (
              <button
                className="btn ghost sm"
                type="button"
                disabled={busy}
                style={{ padding: '1px 8px', fontSize: 11.5 }}
                onClick={() => setDemande({ badge: r, geste: 'suspendre' })}
              >
                Suspendre
              </button>
            )}
            <button
              className="btn ghost sm"
              type="button"
              disabled={busy}
              style={{ padding: '1px 8px', fontSize: 11.5 }}
              onClick={() => setDemande({ badge: r, geste: 'revoquer' })}
            >
              Révoquer
            </button>
          </div>
        )
      },
    })
  }

  return (
    <div>
      {erreur && <div className="alert crit">{erreur}</div>}

      <Liste
        titre="Badges staff"
        sous={peutGerer ? 'suspension et révocation' : 'consultation'}
        deps={[etabActif, version]}
        charger={api.badgeStaffs}
        vide="Aucun badge staff émis."
        colonnes={colonnes}
      />

      <MotifBadge
        demande={demande}
        busy={busy}
        onFermer={() => setDemande(null)}
        onConfirmer={(motif) => {
          const { badge, geste } = demande
          setDemande(null)
          agir(() => (geste === 'revoquer'
            ? api.revoquerBadgeStaff(badge.id, motif)
            : api.suspendreBadgeStaff(badge.id, motif)))
        }}
      />
    </div>
  )
}

/**
 * Le motif d'une suspension ou d'une révocation.
 *
 * **La révocation est irréversible et le dit avant, pas après.** Un badge révoqué ne se réactive
 * pas : il faut en émettre un neuf, avec le support physique que ça suppose. Découvrir ça une fois
 * le clic donné est le genre d'erreur qu'on ne peut pas rattraper en cliquant ailleurs.
 */
function MotifBadge({ demande, busy, onFermer, onConfirmer }) {
  const [motif, setMotif] = useState('')
  const revoque = demande?.geste === 'revoquer'

  useEffect(() => { setMotif('') }, [demande])

  return (
    <Modal
      open={!!demande}
      onClose={onFermer}
      titre={revoque ? 'Révoquer le badge' : 'Suspendre le badge'}
      taille="sm"
    >
      <div style={{ display: 'grid', gap: 12 }}>
        <div className={revoque ? 'alert crit' : 'alert warn'}>
          {revoque
            ? 'La révocation est définitive : ce badge ne pourra pas être réactivé, il faudra en émettre un nouveau.'
            : 'La suspension se lève : le badge pourra être réactivé quand la personne le retrouvera.'}
        </div>

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Motif</span>
          <input
            className="input"
            type="text"
            maxLength={255}
            value={motif}
            placeholder={revoque ? 'Ex. fin de contrat le 31/08' : 'Ex. badge égaré, déclaré le 27/08'}
            onChange={(e) => setMotif(e.target.value)}
          />
          <span className="sub" style={{ fontSize: 12.5 }}>
            C’est la seule chose que lira celui qui rouvrira cette ligne dans six mois.
          </span>
        </label>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer} disabled={busy}>Annuler</button>
          <button
            className="btn primary"
            type="button"
            disabled={busy || motif.trim() === ''}
            onClick={() => onConfirmer(motif.trim())}
          >
            {revoque ? 'Révoquer' : 'Suspendre'}
          </button>
        </div>
      </div>
    </Modal>
  )
}

/**
 * L'EFFECTIF — et le geste qui commence un badge.
 *
 * **Émettre se fait ici, pas dans l'onglet des badges.** On donne un badge À QUELQU'UN : le geste
 * part de la personne. Le proposer depuis la liste des badges obligerait à choisir un employé dans
 * un menu déroulant, c'est-à-dire à retrouver dans une liste celui qu'on avait déjà sous les yeux.
 *
 * **Un employé sorti ne reçoit pas de badge**, et l'écran n'offre pas le bouton plutôt que de le
 * griser : un bouton grisé se lit comme un droit manquant, et quelqu'un ira chercher une permission
 * qui n'est pas en cause.
 *
 * Après l'émission, on bascule sur l'onglet des badges — parce que c'est là que se trouve ce qu'on
 * vient de créer, et qu'un geste dont on ne voit pas le résultat se refait.
 */
function ListeEmployes({ etabActif, droits = [], onBadgeEmis }) {
  const peutGerer = aLeDroit(droits, 'personnel.gerer_badge')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  const colonnes = [
    { cle: 'nom', entete: 'Employé', rendu: (r) => <span className="nm">{[r.prenom, r.nom].filter(Boolean).join(' ') || '—'}</span> },
    { cle: 'matricule', entete: 'Matricule', rendu: (r) => <span className="mono">{r.matricule || '—'}</span> },
    { cle: 'poste', entete: 'Poste', rendu: (r) => r.poste || '—' },
    { cle: 'typeContrat', entete: 'Contrat', rendu: (r) => r.typeContrat || '—' },
    { cle: 'dateEntree', entete: 'Entrée', rendu: (r) => dateFr(r.dateEntree) },
    { cle: 'statut', entete: 'Statut', rendu: (r) => <span className={`badge ${STATUT_EMP[r.statut] || 'mut'}`}>{r.statut || '—'}</span> },
  ]

  if (peutGerer) {
    colonnes.push({
      cle: 'badge',
      entete: '',
      rendu: (r) => {
        if (String(r.statut || '').toLowerCase() === 'sorti') return null
        return (
          <div style={{ textAlign: 'right' }}>
            <button
              className="btn ghost sm"
              type="button"
              disabled={busy}
              style={{ padding: '1px 8px', fontSize: 11.5 }}
              onClick={async () => {
                setBusy(true)
                setErreur(null)
                try {
                  await api.emettreBadgeStaff(r.id)
                  onBadgeEmis?.()
                } catch (e) {
                  setErreur(e.message || 'Le badge n’a pas pu être émis.')
                } finally {
                  setBusy(false)
                }
              }}
            >
              Émettre un badge
            </button>
          </div>
        )
      },
    })
  }

  return (
    <div>
      {erreur && <div className="alert crit">{erreur}</div>}
      <Liste
        titre="Employés"
        sous="effectif de l'établissement"
        deps={[etabActif]}
        charger={api.employes}
        vide="Aucun employé."
        colonnes={colonnes}
      />
    </div>
  )
}
