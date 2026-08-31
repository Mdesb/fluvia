import { useEffect, useState } from 'react'
import AbsencesSection from '../components/AbsencesSection.jsx'
import Liste, { dateFr, dateHeureFr, jourLocal } from '../components/Liste.jsx'
import PlanningTravail from '../components/PlanningTravail.jsx'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { api } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

const STATUT_EMP = { Actif: 'good', actif: 'good', Suspendu: 'crit', suspendu: 'crit', Sorti: 'mut' }
const STATUT_BADGE = { Actif: 'good', actif: 'good', Revoque: 'crit', revoque: 'crit', Suspendu: 'warn' }
// LA TABLE DES COULEURS ÉTAIT ÉCRITE POUR DES STATUTS QUE LE SERVEUR N'ÉMET JAMAIS.
//
// Elle listait `complet`, `partiel`, `decouvert`, `incomplet`. Or `RosterProvider` n'en produit que
// trois, et deux d'entre eux n'y figuraient pas :
//
//     conflit       → qualification manquante ou périmée sur le créneau
//     sous_couvert  → moins d'employés affectés que l'effectif requis
//     complet       → le seul des trois que la table connaissait
//
// Les deux inconnus tombaient sur le repli `'mut'`, le badge **gris neutre**. L'écran était donc
// vert quand tout allait bien, et gris quand ça n'allait pas — jamais alarmant. Un poste
// sous-couvert et un poste en conflit de qualification avaient exactement le poids visuel d'une
// information de service.
//
// Vérifié dans le `match` du fournisseur, pas déduit des noms.
const COUV = { complet: 'good', sous_couvert: 'warn', conflit: 'crit' }

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

      <Tabs
        onglets={[
          ['employes', 'Employés'],
          ['planning', 'Planning'],
          ['roster', 'Roster'],
          ['badges', 'Badges staff'],
        ]}
        actif={sousOnglet}
        onChange={setSousOnglet}
      />

      {sousOnglet === 'employes' && (
        <ListeEmployes etabActif={etabActif} droits={droits} onBadgeEmis={() => setSousOnglet('badges')} />
      )}

      {/* LE PLANNING AVANT LE ROSTER, ET C'EST L'ORDRE DU RAISONNEMENT : le créneau dit ce qu'il
          faut couvrir, le roster dit si ça l'est. Sans le premier, le second ne peut rien
          affirmer — il comparait un besoin qu'aucun écran ne savait exprimer. */}
      {sousOnglet === 'planning' && (
        <PlanningTravail etabActif={etabActif} droits={droits} />
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
            // LA QUALIFICATION, PARCE QUE « CONFLIT » NE DIT PAS DE QUOI IL S'AGIT.
            //
            // `RosterHebdomadaire` publie `qualificationRequise` et `qualificationManquanteOuExpiree`,
            // et l'écran n'affichait ni l'un ni l'autre. Le fournisseur replie bien le second dans
            // `statutCouverture` — d'où « conflit » — mais « conflit » se lit d'abord comme un
            // chevauchement d'horaires. Personne ne devine qu'il s'agit d'un diplôme périmé, ni
            // duquel.
            //
            // Sur un poste de surveillance de bassin, la différence entre « il manque quelqu'un » et
            // « la personne présente n'a plus le droit d'y être » n'est pas une nuance.
            {
              cle: 'qualification',
              entete: 'Qualification',
              rendu: (r) => (
                r.qualificationManquanteOuExpiree ? (
                  <>
                    <span className="badge crit">manquante ou périmée</span>
                    {r.qualificationRequise && <div className="sub">{r.qualificationRequise}</div>}
                  </>
                ) : r.qualificationRequise ? (
                  <span className="sub">{r.qualificationRequise}</span>
                ) : (
                  <span className="sub">aucune requise</span>
                )
              ),
            },
            { cle: 'statutCouverture', entete: 'Couverture', rendu: (r) => <span className={`badge ${COUV[r.statutCouverture] || 'mut'}`}>{mot(r.statutCouverture)}</span> },
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
            {/* PERTE OU VOL — UN TROISIÈME GESTE, ET PAS UN SYNONYME DES DEUX AUTRES.
                Suspendre dit « ce badge ne doit plus ouvrir pour l'instant ». Révoquer dit « ce
                badge est mort ». Déclarer un incident dit « ce badge est DEHORS, entre les mains
                de quelqu'un qui n'est pas son titulaire » — c'est un fait sur le monde physique,
                pas une décision d'administration, et il se consigne pour être retrouvé. La route
                s'ouvre d'ailleurs aussi à `acces.bloquer_support`, donc à quelqu'un du contrôle
                d'accès qui n'administre pas le personnel. */}
            <button
              className="btn ghost sm"
              type="button"
              disabled={busy}
              style={{ padding: '1px 8px', fontSize: 11.5 }}
              title="Perte ou vol : consigne l'incident et bloque le badge."
              onClick={() => setDemande({ badge: r, geste: 'incident' })}
            >
              Perte ou vol
            </button>
          </div>
        )
      },
    })
  }

  return (
    <div>
      {erreur && <div className="banner banner-error">{erreur}</div>}

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
            : geste === 'incident'
              ? api.declarerIncidentBadge(badge.id, motif)
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
  const incident = demande?.geste === 'incident'

  useEffect(() => { setMotif('') }, [demande])

  return (
    <Modal
      open={!!demande}
      onClose={onFermer}
      titre={revoque ? 'Révoquer le badge' : incident ? 'Déclarer une perte ou un vol' : 'Suspendre le badge'}
      taille="sm"
    >
      <div style={{ display: 'grid', gap: 12 }}>
        <div className={revoque || incident ? 'banner banner-error' : 'banner banner-warn'}>
          {revoque
            ? 'La révocation est définitive : ce badge ne pourra pas être réactivé, il faudra en émettre un nouveau.'
            : incident
              ? 'Un badge perdu ou volé est DEHORS : quelqu’un d’autre peut s’en servir pour entrer. '
                + 'La déclaration le bloque et consigne l’incident — c’est ce qui permettra de comprendre '
                + 'un passage anormal si l’on en trouve un.'
              : 'La suspension se lève : le badge pourra être réactivé quand la personne le retrouvera.'}
        </div>

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Motif</span>
          <input
            className="input"
            type="text"
            maxLength={255}
            value={motif}
            placeholder={revoque
              ? 'Ex. fin de contrat le 31/08'
              : incident
                ? 'Ex. perdu au vestiaire le 31/08, signalé par l’agent d’accueil'
                : 'Ex. badge égaré, déclaré le 27/08'}
            onChange={(e) => setMotif(e.target.value)}
          />
          <span className="sub" style={{ fontSize: 12.5 }}>
            {incident
              ? 'Obligatoire pour une perte ou un vol : le serveur refuse sans. Les circonstances '
                + 'décident de la suite — un badge perdu sur place et un badge volé dehors n’appellent '
                + 'pas la même réaction.'
              : 'C’est la seule chose que lira celui qui rouvrira cette ligne dans six mois.'}
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
            {revoque ? 'Révoquer' : incident ? 'Déclarer la perte' : 'Suspendre'}
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
  // DEUX DROITS DISTINCTS, ET C'EST VOULU : emettre un badge n'est pas embaucher. Le serveur exige
  // `personnel.gerer_employe` sur la creation, et `personnel.gerer_badge` sur les badges. Utiliser
  // le second pour afficher le bouton de creation produirait un 403 au clic, decouvert trop tard.
  const peutGererEmploye = aLeDroit(droits, 'personnel.gerer_employe')
  const [creation, setCreation] = useState(false)
  const [rechargement, setRechargement] = useState(0)
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

  // LA SORTIE D'UN SALARIÉ — le geste qui manquait après l'embauche.
  //
  // ⚠ SUSPENDRE UN EMPLOYÉ SUSPEND AUSSI SES BADGES, en cascade côté serveur. La personne perd
  // ses accès physiques à l'instant du clic : c'est le comportement voulu pour un départ, et c'est
  // exactement pourquoi il faut le dire AVANT. Un exploitant qui suspend « pour mettre à jour une
  // fiche » ne s'attend pas à couper l'entrée du personnel.
  //
  // Et c'est réversible : `reactiver` remet les badges. On ne propose donc pas de confirmation
  // pour la réactivation — un geste réversible qui demande « êtes-vous sûr ? » apprend à cliquer
  // « oui » sans lire, et ce réflexe se transporte ensuite sur les gestes qui ne le sont pas.
  if (peutGererEmploye) {
    colonnes.push({
      cle: 'sortie',
      entete: '',
      rendu: (r) => {
        const statut = String(r.statut || '').toLowerCase()
        if (statut === 'sorti') return null
        const suspendu = statut === 'suspendu'
        return (
          <div style={{ textAlign: 'right' }}>
            <button
              className="btn ghost sm"
              type="button"
              disabled={busy}
              style={{ padding: '1px 8px', fontSize: 11.5 }}
              title={suspendu
                ? 'Rétablit le salarié et ses badges de service.'
                : 'Suspend le salarié ET ses badges de service : il perd ses accès physiques.'}
              onClick={async () => {
                if (!suspendu && !window.confirm(
                  `Suspendre ${[r.prenom, r.nom].filter(Boolean).join(' ') || 'ce salarié'} ?\n\n`
                  + 'Ses badges de service seront suspendus en même temps : il perdra ses accès '
                  + 'physiques immédiatement.\n\nLe geste est réversible.',
                )) return
                setBusy(true)
                setErreur(null)
                try {
                  if (suspendu) await api.reactiverEmploye(r.id)
                  else await api.suspendreEmploye(r.id)
                  // ⚠ PAS `onBadgeEmis` : ce rappel BASCULE vers l'onglet des badges. L'appeler
                  // ici enverrait l'utilisateur ailleurs apres une suspension, ce qui se lit
                  // comme un bug. Le compteur recharge la liste sans bouger d'ecran.
                  setRechargement((k) => k + 1)
                } catch (e) {
                  setErreur(e.message || 'Le changement de statut a échoué.')
                } finally {
                  setBusy(false)
                }
              }}
            >
              {suspendu ? 'Réactiver' : 'Suspendre'}
            </button>
          </div>
        )
      },
    })
  }

  return (
    <div>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <Liste
        titre="Employés"
        sous="effectif de l'établissement"
        deps={[etabActif, rechargement]}
        charger={api.employes}
        vide="Aucun employé déclaré. Sans effectif, ni planning, ni absence, ni badge de service."
        colonnes={colonnes}
        actions={peutGererEmploye ? (
          <button className="btn sm" type="button" onClick={() => setCreation(true)}>
            ＋ Déclarer un employé
          </button>
        ) : null}
      />

      <EmployeModal
        open={creation}
        onClose={() => setCreation(false)}
        onFait={() => { setCreation(false); setRechargement((n) => n + 1) }}
      />
    </div>
  )
}

// DÉCLARER UN EMPLOYÉ — l'écran gérait absences et badges d'un effectif qu'il ne savait pas créer.
//
// ⚠ UN DOSSIER D'EMPLOYÉ NE SE SUPPRIME PAS, ET C'EST JUSTE. `Employe` expose `Post`, `Patch`,
// `suspendre` et `reactiver` — pas de `Delete`. Un départ se traite par une date de sortie et une
// suspension, jamais par un effacement : le planning passé, les absences et les badges émis
// resteraient sans titulaire, et la paie ne se relit plus.
//
// C'est l'exemple type d'un `Post` sans `Delete` qui est un CHOIX, pas un oubli — la distinction
// que `allaccess-c2` cherche à faire déclarer, entité par entité, plutôt qu'à corriger en masse.
// L'écran le dit sous le formulaire, parce que celui qui saisit ne le devine pas.
function EmployeModal({ open, onClose, onFait }) {
  const [nom, setNom] = useState('')
  const [prenom, setPrenom] = useState('')
  const [poste, setPoste] = useState('')
  const [contrat, setContrat] = useState('cdi')
  const [matricule, setMatricule] = useState('')
  const [entree, setEntree] = useState(jourLocal())
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return
    setNom(''); setPrenom(''); setPoste(''); setContrat('cdi')
    setMatricule(''); setEntree(jourLocal()); setErreur(null)
  }, [open])

  // ⚠ `dateEntree` EST OBLIGATOIRE, et l'écran l'affichait en facultative.
  //
  // `Employe::$dateEntree` porte `Assert\NotNull` sur une colonne `date_immutable` NON NULLE.
  // La première version de cette modale ne l'étoilait pas, ne l'exigeait pas et ne l'envoyait
  // que si elle était remplie : qui saisissait les trois champs étoilés obtenait un 422 au clic,
  // sur un formulaire qui venait de lui dire qu'il était complet.
  //
  // Trouvé en RELISANT l'entité après coup, pas en l'écrivant — la première lecture avait retenu
  // les trois `NotBlank` et manqué les deux `NotNull` juste en dessous.
  const pret = nom.trim() && prenom.trim() && poste.trim() && contrat && entree

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.creerEmploye({
        nom: nom.trim(),
        prenom: prenom.trim(),
        poste: poste.trim(),
        typeContrat: contrat,
        ...(matricule.trim() ? { matricule: matricule.trim() } : {}),
        dateEntree: entree,
      })
      onFait()
    } catch (err) {
      setErreur(err.message || 'L’employé n’a pas pu être déclaré.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Déclarer un employé">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        <div className="row" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="em-prenom">Prénom *</label>
            <input id="em-prenom" className="input" value={prenom} maxLength={100}
              onChange={(e) => setPrenom(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="em-nom">Nom *</label>
            <input id="em-nom" className="input" value={nom} maxLength={100}
              onChange={(e) => setNom(e.target.value)} />
          </div>
        </div>

        <div className="row" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 220px' }}>
            <label htmlFor="em-poste">Poste *</label>
            <input id="em-poste" className="input" value={poste} maxLength={80}
              placeholder="Maître-nageur, caissier, agent d’accueil…"
              onChange={(e) => setPoste(e.target.value)} />
            <span className="hint">Ce qui apparaît sur le planning, pas l’intitulé du contrat.</span>
          </div>
          <div className="field" style={{ flex: '1 1 180px' }}>
            <label htmlFor="em-contrat">Type de contrat *</label>
            <select id="em-contrat" className="input" value={contrat} onChange={(e) => setContrat(e.target.value)}>
              {CONTRATS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
        </div>

        <div className="row" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="em-matricule">Matricule</label>
            <input id="em-matricule" className="input" value={matricule} maxLength={40}
              onChange={(e) => setMatricule(e.target.value)} />
            <span className="hint">Facultatif — celui de votre logiciel de paie, si vous en avez un.</span>
          </div>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="em-entree">Date d’entrée *</label>
            <input id="em-entree" className="input" type="date" value={entree}
              onChange={(e) => setEntree(e.target.value)} />
            <span className="hint">Obligatoire — c’est elle qui datera l’ancienneté et les plannings.</span>
          </div>
        </div>

        <p className="hint">
          ⚠ Un dossier d’employé ne se supprime pas. Un départ se déclare par une date de sortie et
          une suspension&nbsp;: le planning passé, les absences et les badges émis doivent rester
          rattachés à quelqu’un.
        </p>

        <div className="r" style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi || !pret}>
            {envoi ? 'Déclaration…' : 'Déclarer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// Les six formes de `TypeContrat`, en toutes lettres : « vacataire » et « prestataire » ne se
// devinent pas depuis un code, et le choix a des conséquences en paie.
const CONTRATS = [
  ['cdi', 'CDI'],
  ['cdd', 'CDD'],
  ['vacataire', 'Vacataire'],
  ['saisonnier', 'Saisonnier'],
  ['stagiaire', 'Stagiaire'],
  ['prestataire', 'Prestataire'],
]
