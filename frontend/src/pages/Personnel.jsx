import { useEffect, useState } from 'react'
import AbsencesSection from '../components/AbsencesSection.jsx'
import Liste, { dateFr, dateHeureFr, jourLocal } from '../components/Liste.jsx'
import PlanningTravail from '../components/PlanningTravail.jsx'
import IncidentsBadge from '../components/IncidentsBadge.jsx'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { api, membres } from '../api/client.js'
import { idDe } from '../api/iri.js'
import { useEtatUrl } from '../api/url.js'
import { TYPES_QUALIFICATION } from '../api/qualifications.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { confirmer } from '../components/Confirmation.jsx'

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

// ⚠ LA LISTE A ETE SORTIE DANS `api/qualifications.js`, ET CE N'EST PAS DU RANGEMENT.
//
// Le creneau de travail declare la qualification qu'il EXIGE, donc il propose la meme liste. Deux
// copies du meme enum divergent tot ou tard, et le jour ou l'une gagne un type que l'autre ignore,
// un creneau exige un brevet qu'aucun ecran ne sait saisir.
const QUALIFS = TYPES_QUALIFICATION

// ⚠ VALEUR PROVISOIRE, ET ELLE S'ANNONCE COMME TELLE.
//
// Elle double `CheckQualificationsCommand::HORIZON_PAR_DEFAUT` (14 jours). L'etape 7 du plan la
// remplace par un reglage PAR ETABLISSEMENT, lu par l'ecran ET par la commande — sans quoi les deux
// repondraient differemment a la meme question : la commande signalerait une echeance que l'ecran
// affiche encore comme tranquille.
const SEUIL_EXPIRATION_JOURS = 14

/**
 * Jours calendaires d'ici a une date ISO, en heure LOCALE.
 *
 * ⚠ `new Date('2026-09-15')` est interprete en UTC : passe par un fuseau a l'est, la veille au soir
 * devient deja « demain », et une qualification bascule d'etat selon l'heure a laquelle on regarde.
 * On decoupe donc la date et on construit deux minuits locaux.
 */
function joursAvant(iso) {
  if (!iso) return null
  const [a, m, j] = String(iso).slice(0, 10).split('-').map(Number)
  if (!a || !m || !j) return null
  const maintenant = new Date()
  const aujourdhui = new Date(maintenant.getFullYear(), maintenant.getMonth(), maintenant.getDate())
  return Math.round((new Date(a, m - 1, j) - aujourdhui) / 86400000)
}

/**
 * ⚠ TROIS ETATS A L'ECRAN, DEUX SEULEMENT COTE SERVEUR.
 *
 * `Qualification::getStatut()` ne rend que `valide` et `expiree` — « expire bientot » n'existe pas
 * en base et se calcule ici. On n'indexe donc PAS une table de statuts sur un troisieme nom que le
 * serveur ne rend jamais : c'est le piege deja paye sur `COUV` plus haut, ou deux etats inconnus
 * tombaient sur le repli gris et rendaient l'ecran rassurant quand ca allait mal.
 */
function etatQualification(r) {
  const jours = joursAvant(r.dateValidite)
  if (jours === null) return { cle: 'inconnu', classe: 'mut', texte: 'date absente', jours: null }
  if (jours < 0) return { cle: 'expiree', classe: 'crit', texte: `expiree depuis ${-jours} j`, jours }
  if (jours <= SEUIL_EXPIRATION_JOURS) return { cle: 'bientot', classe: 'warn', texte: `expire dans ${jours} j`, jours }
  return { cle: 'valide', classe: 'good', texte: 'valide', jours }
}

// Module Personnel : employés, roster (planning), badges staff.
// ⚠ L'ONGLET ENTRE DANS L'ADRESSE EN MÊME TEMPS QUE L'ÉCRAN. La fiche vit dans un composant
// que seul l'onglet « Employés » monte : sans le paramètre `tab`, un F5 sur `?employe=…`
// retomberait sur un onglet qui ne la rend pas.
const DEFAUTS_URL = { tab: 'employes', employe: '', nouvel: '', badge: '' }

export default function Personnel({ etabActif, droits = [] }) {
  const [params, majParams] = useEtatUrl('personnel', DEFAUTS_URL)
  const sousOnglet = params.tab
  const setSousOnglet = (v) => majParams({ tab: v, employe: '', nouvel: '', badge: '' })
  // Un écran de niveau 2 prend la page : ni titre ni onglets au-dessus de lui.
  const ecranOuvert = Boolean(params.employe || params.nouvel || params.badge)

  return (
    <div className="view">
      {!ecranOuvert && (<>
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
          ['qualifications', 'Qualifications'],
          // Le registre APRES les badges, et c'est l'ordre du raisonnement : on declare un incident
          // depuis la liste des badges, on le relit et on le referme ici.
          ['incidents', 'Incidents de badge'],
        ]}
        actif={sousOnglet}
        onChange={setSousOnglet}
      />
      </>)}

      {sousOnglet === 'employes' && (
        <ListeEmployes
          etabActif={etabActif}
          droits={droits}
          onBadgeEmis={() => setSousOnglet('badges')}
          params={params}
          majParams={majParams}
        />
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
      {sousOnglet === 'qualifications' && <OngletQualifications etabActif={etabActif} droits={droits} />}
      {sousOnglet === 'incidents' && <IncidentsBadge etabActif={etabActif} droits={droits} />}

      {/* Absences : declarer, accepter, refuser. En bas de l'ecran Personnel parce que c'est une
          decision qui porte sur les gens qu'on vient de lire, pas une activite separee. */}
      {/* ⚠ HORS DES CONDITIONS D ONGLET, donc rendue sous CHAQUE écran de niveau 2 — la fiche,
          la déclaration, le badge. Le masquage de l en-tête ne la couvrait pas : sous la fiche
          d un employé, la carte « Absences » de tout l effectif restait affichée. */}
      {!ecranOuvert && <AbsencesSection etabActif={etabActif} droits={droits} />}
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

  // CE QU UN BADGE OUVRE REELLEMENT (G-7) — deux lectures, jamais affichees jusqu ici.
  //
  // `PorteeAccesEmploye` est creee en side-effet de l emission et exposee en LECTURE SEULE. Ses deux
  // operations n etaient appelees par aucun ecran : on listait des badges sans jamais dire quelles
  // portes ils ouvrent, ni a quelles heures.
  //
  // ⚠ PAS D ETAT « AUCUNE ZONE » : `EmissionBadgeStaffHandler` REFUSE d emettre sans au moins un
  // espace (RG-PERSO-06/07). Une portee porte donc toujours une zone, et un affichage « aucune »
  // serait du code mort qui rassure. (La spec a d abord affirme le contraire, sur une recherche qui
  // visait `addAuthorisedSpace` — la methode d une AUTRE classe. La vraie est `addEspaceAutorise`.)
  const [portees, setPortees] = useState(null)
  const [espaces, setEspaces] = useState([])

  useEffect(() => {
    let vivant = true
    api.porteesAcces()
      .then((r) => { if (vivant) setPortees(membres(r)) })
      // ⚠ `null` reste `null` en cas d echec : une portee illisible ne doit pas s afficher comme une
      // portee vide. Zero et « je n ai pas pu demander » sont des affirmations opposees.
      .catch(() => {})
    api.espacesAcces()
      .then((r) => { if (vivant) setEspaces(membres(r)) })
      .catch(() => { if (vivant) setEspaces([]) })
    return () => { vivant = false }
  }, [etabActif, version])

  const indexEspaces = new Map(espaces.map((e) => [e.id, e]))
  const porteeDuBadge = (idBadge) => (portees || []).find((p) => idDe(p.badgeStaff) === idBadge)

  const libellesEspaces = (portee) => (portee.espacesAutorises || [])
    .map((ref) => {
      const es = indexEspaces.get(idDe(ref))
      return es ? (es.libelle || es.nom) : null
    })
    .filter(Boolean)

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
    {
      cle: 'ouvre',
      entete: 'Ouvre',
      rendu: (r) => {
        if (portees === null) return <span className="sub">lecture…</span>
        const p = porteeDuBadge(r.id)
        // Aucune portee trouvee pour ce badge : on ne dit pas « rien », on dit qu on ne sait pas.
        // Une portee est posee a l emission, donc son absence est une anomalie, pas un etat normal.
        if (!p) return <span className="sub">portee introuvable</span>
        const noms = libellesEspaces(p)
        const quand = p.modeHoraire === 'permanent'
          ? 'a toute heure'
          : `pendant ses creneaux${p.margeAvantApres != null ? ` (± ${p.margeAvantApres} min)` : ''}`
        return (
          <div>
            <div>{noms.length > 0 ? noms.join(', ') : `${(p.espacesAutorises || []).length} espace(s)`}</div>
            <div className="sub">{quand}</div>
          </div>
        )
      },
    },
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
function ListeEmployes({ etabActif, droits = [], onBadgeEmis, params = {}, majParams }) {
  const ouvert = params.employe || ''
  const fermer = () => majParams({ employe: '' }, { pousser: true })
  // ⚠ UN SEUL CHARGEUR POUR LA FICHE ET LE BADGE : l'employé se lit par son identifiant, et ses
  // trois états (lecture en cours / échec / introuvable) ne se démêlent qu'une fois.
  const idLu = params.employe || params.badge || ''
  const peutGerer = aLeDroit(droits, 'personnel.gerer_badge')
  // DEUX DROITS DISTINCTS, ET C'EST VOULU : emettre un badge n'est pas embaucher. Le serveur exige
  // `personnel.gerer_employe` sur la creation, et `personnel.gerer_badge` sur les badges. Utiliser
  // le second pour afficher le bouton de creation produirait un 403 au clic, decouvert trop tard.
  const peutGererEmploye = aLeDroit(droits, 'personnel.gerer_employe')
  // ⚠ L'EMPLOYÉ SE LIT PAR SON IDENTIFIANT, PAS DANS LA LISTE. Les lignes appartiennent à
  // `Liste`, qui les charge et les pagine lui-même : il n'y a aucun tableau ici où chercher.
  // `erreurFiche` sépare « on n'a pas pu lire » de « il n'y est pas ».
  const [employeOuvert, setEmployeOuvert] = useState(null)
  const [chargementFiche, setChargementFiche] = useState(false)
  const [erreurFiche, setErreurFiche] = useState(false)
  const [rechargement, setRechargement] = useState(0)
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!idLu) { setEmployeOuvert(null); setErreurFiche(false); return undefined }
    let vivant = true
    setChargementFiche(true)
    setErreurFiche(false)
    setEmployeOuvert(null)
    api.employe(idLu)
      .then((e) => { if (vivant) setEmployeOuvert(e) })
      // ⚠ UN 404 N'EST PAS UNE PANNE : il dit que l'identifiant ne désigne personne (ou personne
      // de visible d'ici). Tout le reste est une lecture qui a échoué, et l'écran ne doit pas
      // les confondre — l'un invite à revenir à la liste, l'autre à réessayer.
      .catch((e) => { if (vivant) setErreurFiche(e?.status !== 404) })
      .finally(() => { if (vivant) setChargementFiche(false) })
    return () => { vivant = false }
  }, [idLu, rechargement])

  const colonnes = [
    { cle: 'nom', entete: 'Employé', rendu: (r) => <span className="nm">{[r.prenom, r.nom].filter(Boolean).join(' ') || '—'}</span> },
    { cle: 'matricule', entete: 'Matricule', rendu: (r) => <span className="mono">{r.matricule || '—'}</span> },
    { cle: 'poste', entete: 'Poste', rendu: (r) => r.poste || '—' },
    { cle: 'typeContrat', entete: 'Contrat', rendu: (r) => r.typeContrat || '—' },
    { cle: 'dateEntree', entete: 'Entrée', rendu: (r) => dateFr(r.dateEntree) },
    { cle: 'statut', entete: 'Statut', rendu: (r) => <span className={`badge ${STATUT_EMP[r.statut] || 'mut'}`}>{r.statut || '—'}</span> },
    {
      cle: 'fiche',
      entete: '',
      // La fiche est en LECTURE pour tout le monde : elle rassemble ce qu'on sait deja de la
      // personne. Ce sont les gestes qu'elle contient qui sont gardes, un par un.
      rendu: (r) => (
        <div style={{ textAlign: 'right' }}>
          <button className="btn ghost sm" type="button" style={{ padding: '1px 8px', fontSize: 11.5 }}
            onClick={() => majParams({ employe: String(r.id) }, { pousser: true })}>
            Fiche
          </button>
        </div>
      ),
    },
  ]

  if (peutGerer) {
    colonnes.push({
      cle: 'badge',
      entete: '',
      // ⚠ CE BOUTON DECLENCHAIT DIRECTEMENT L APPEL, AVEC UN CORPS VIDE, ET RENDAIT 422.
      //
      // Le serveur exige l etablissement, le mode horaire et au moins un espace : trois choix qui ne
      // se devinent pas. Il ouvre donc un ecran, qui les recueille avant d appeler.
      rendu: (r) => {
        if (String(r.statut || '').toLowerCase() === 'sorti') return null
        return (
          <div style={{ textAlign: 'right' }}>
            <button
              className="btn ghost sm"
              type="button"
              disabled={busy}
              style={{ padding: '1px 8px', fontSize: 11.5 }}
              onClick={() => majParams({ badge: String(r.id) }, { pousser: true })}
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
                if (!suspendu && !await confirmer(
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

  // ── DÉCLARER UN EMPLOYÉ, EN ÉCRAN ───────────────────────────────────────────────────────
  //
  // Aucun identifiant à résoudre : le formulaire se remet à zéro à l'ouverture, une fois.
  if (params.nouvel === '1') {
    const fermerNouvel = () => majParams({ nouvel: '' }, { pousser: true })
    return (
      <div>
        <button className="btn ghost sm" type="button" onClick={fermerNouvel}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour aux employés
        </button>
        <EmployeModal
          open
          onClose={fermerNouvel}
          onFait={() => { fermerNouvel(); setRechargement((n) => n + 1) }}
        />
      </div>
    )
  }

  // ── ÉMETTRE UN BADGE, EN ÉCRAN ──────────────────────────────────────────────────────────
  //
  // ⚠ MÊMES TROIS ÉTATS QUE LA FICHE, et par le même chargeur. Le formulaire reçoit l'employé
  // tel que `api.employe` l'a rendu : son effet se rejoue sur l'identité de l'objet, qui reste
  // stable tant qu'on ne recharge pas.
  if (params.badge) {
    const fermerBadge = () => majParams({ badge: '' }, { pousser: true })
    const retour = (
      <button className="btn ghost sm" type="button" onClick={fermerBadge}
        style={{ marginBottom: 'var(--esp-large)' }}>
        ← Retour aux employés
      </button>
    )
    if (chargementFiche) {
      return <div>{retour}<div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div></div>
    }
    if (!employeOuvert) {
      return (
        <div>
          {retour}
          <div className="banner banner-warn">
            {erreurFiche
              ? 'Cet employé n’a pas pu être lu. Ce n’est pas la même chose que « il n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
              : 'Cet employé n’est plus dans l’effectif — il a sans doute quitté l’établissement depuis que ce lien a été copié.'}
          </div>
        </div>
      )
    }
    return (
      <div>
        {retour}
        <EmissionBadgeModal
          key={params.badge}
          employe={employeOuvert}
          etabActif={etabActif}
          onClose={fermerBadge}
          // Après l'émission on bascule vers l'onglet des badges, comme avant ; `setSousOnglet`
          // ferme déjà `badge` dans l'adresse.
          onFait={() => { if (onBadgeEmis) onBadgeEmis(); else fermerBadge() }}
        />
      </div>
    )
  }

  // ── LA FICHE D'UN EMPLOYÉ, EN ÉCRAN ─────────────────────────────────────────────────────
  //
  // ⚠ TROIS ÉTATS, ET AUCUN NE DOIT SE FAIRE PASSER POUR UN AUTRE. « pas encore lu » n'est pas
  // « illisible », et ni l'un ni l'autre n'est « cet identifiant ne désigne personne ». Montés
  // sur une fiche vide, les trois donneraient le même écran — et « Enregistrer » y écrirait des
  // champs vides sur un employé réel.
  if (ouvert) {
    const retour = (
      <button
        className="btn ghost sm"
        type="button"
        onClick={fermer}
        style={{ marginBottom: 'var(--esp-large)' }}
      >
        ← Retour aux employés
      </button>
    )
    if (chargementFiche) {
      return (
        <div>
          {retour}
          <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
        </div>
      )
    }
    if (!employeOuvert) {
      return (
        <div>
          {retour}
          <div className="banner banner-warn">
            {erreurFiche
              ? 'Cette fiche n’a pas pu être lue. Ce n’est pas la même chose que « cet employé n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
              : 'Cet employé n’est plus dans l’effectif — il a sans doute quitté l’établissement depuis que ce lien a été copié.'}
          </div>
        </div>
      )
    }
    return (
      <div>
        {retour}
        <FicheEmploye
          key={ouvert}
          employe={employeOuvert}
          droits={droits}
          etabActif={etabActif}
          onClose={fermer}
          onChange={() => setRechargement((n) => n + 1)}
        />
      </div>
    )
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
          /* ⚠ `primary`, comme « Déclarer une absence » du panneau voisin. Ce bouton était le seul
             des deux en contour, alors que l'état vide juste au-dessous dit la dépendance : « sans
             effectif, ni planning, ni absence, ni badge de service ». Le seul bouton plein de
             l'écran désignait donc l'action qui ne peut pas aboutir. */
          <button className="btn primary sm" type="button" onClick={() => majParams({ nouvel: '1' }, { pousser: true })}>
            ＋ Déclarer un employé
          </button>
        ) : null}
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

  if (!open) return null

  return (
    <>
      <h2>Déclarer un employé</h2>
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
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

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
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

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
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
    </>
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


// L'ONGLET QUI FERME LE PREMIER CUL-DE-SAC DU MODULE.
//
// `AffecterEmployeProcessor` refuse d'affecter un employe a un creneau qui exige une qualification
// qu'il ne detient pas (CA-5), et le roster affiche deja le badge rouge en NOMMANT le brevet
// attendu. Le produit designait donc le probleme avec precision, et n'offrait aucun geste pour le
// resoudre : `POST /api/qualifications` existe depuis l'origine et n'etait appele de nulle part.
//
// L'ordre par defaut est l'echeance la plus proche en tete — c'est la question qu'on se pose en
// ouvrant cet ecran, pas « qui a quoi ».
function OngletQualifications({ etabActif, droits = [] }) {
  const peutGerer = aLeDroit(droits, 'personnel.gerer_qualification')
  const [filtreEmploye, setFiltreEmploye] = useState('')
  const [filtreType, setFiltreType] = useState('')
  const [employes, setEmployes] = useState([])
  const [edition, setEdition] = useState(null)
  const [rechargement, setRechargement] = useState(0)

  // Les employes servent au filtre ET au formulaire. ⚠ Une erreur ici ne doit pas vider la liste
  // des qualifications : le filtre se degrade en liste vide, l'onglet continue de fonctionner.
  useEffect(() => {
    let vivant = true
    api.employes()
      .then((r) => { if (vivant) setEmployes(membres(r)) })
      .catch(() => { if (vivant) setEmployes([]) })
    return () => { vivant = false }
  }, [etabActif])

  const nomEmploye = (e) => [e?.prenom, e?.nom].filter(Boolean).join(' ') || '—'

  // ⚠ LA RELATION `employe` NE PORTE QUE SON `id`, ET LA COLONNE SORTAIT VIDE.
  //
  // `Employe::$nom` et `$prenom` sont dans les groupes `employe:read`, `affectation_travail:read`,
  // `absence:read`, `badge_staff:read`, `roster:read` — PAS dans `qualification:read`. Le serveur
  // rend donc, pour chaque ligne :
  //
  //     "employe": { "@id": "/api/employes/<uuid>", "@type": "Employe", "id": "<uuid>" }
  //
  // C'est bien un OBJET : le test `typeof r.employe === 'object'` passait, et la colonne affichait
  // « — » sur TOUTES les lignes. Ni le build, ni les garde-fous, ni un test d'API ne voient ça —
  // seulement l'ouverture de l'ecran contre un vrai serveur (mesure du 08/09 sur la pile de test).
  //
  // On resout donc par l'index des employes, deja charge pour le filtre et le formulaire.
  //
  // ⚠ Et quand l'employe n'y est pas, on le DIT. Un « — » signifierait « pas d'employe », ce qui est
  // impossible (`employe` est `nullable: false`) : ce serait affirmer une absence a la place d'une
  // ignorance. La liste des employes est bornee par le cloisonnement et par la pagination reelle.
  const indexEmployes = new Map(employes.map((e) => [e.id, e]))

  const employeDeLaLigne = (r) => {
    // ⚠ `idDe` PLUTOT QU'UN `.split('/').pop()` MAISON — et le garde-fou n'a pas eu tort.
    //
    // Seize copies de cette extraction vivaient dans seize fichiers et se repartissaient en NEUF
    // classes d'equivalence : trois rendaient `''` sur une IRI a barre oblique finale, deux
    // `undefined` sur un objet sans `id`, une inversait la priorite `id`/`@id`. Une chaine vide se
    // lit comme un identifiant valide plus loin, et fait echouer en silence la recherche par cle.
    //
    // Ici la relation arrive en objet `{ '@id', '@type', id }` — mais rien ne garantit qu'elle
    // restera sous cette forme : c'est la sérialisation qui la decide, et elle a deja change.
    const id = idDe(r.employe)
    if (!id) return { texte: 'employe non renseigne', connu: false }
    const e = indexEmployes.get(id)
    if (!e) return { texte: 'employe hors de la liste chargee', connu: false }
    return { texte: nomEmploye(e), connu: true }
  }

  const colonnes = [
    {
      cle: 'employe',
      entete: 'Employe',
      rendu: (r) => {
        const { texte, connu } = employeDeLaLigne(r)
        return connu
          ? <span className="nm">{texte}</span>
          : <span className="sub" title="Le serveur ne renvoie que l'identifiant de l'employe sur cette ressource.">{texte}</span>
      },
    },
    { cle: 'type', entete: 'Type', rendu: (r) => <span className="mono">{r.type || '—'}</span> },
    { cle: 'libelle', entete: 'Libelle', rendu: (r) => r.libelle || '—' },
    { cle: 'dateObtention', entete: 'Obtenue le', rendu: (r) => dateFr(r.dateObtention) },
    { cle: 'dateValidite', entete: 'Valide jusqu\'au', rendu: (r) => dateFr(r.dateValidite) },
    {
      cle: 'etat',
      entete: 'Etat',
      rendu: (r) => {
        const etat = etatQualification(r)
        return <span className={`badge ${etat.classe}`}>{etat.texte}</span>
      },
    },
  ]

  if (peutGerer) {
    colonnes.push({
      cle: 'corriger',
      entete: '',
      rendu: (r) => (
        <div style={{ textAlign: 'right' }}>
          <button className="btn ghost sm" type="button" style={{ padding: '1px 8px', fontSize: 11.5 }}
            onClick={() => setEdition(r)}>
            Corriger
          </button>
        </div>
      ),
    })
  }

  return (
    <div>
      <Liste
        titre="Qualifications"
        sous="brevets et habilitations, et ce qui arrive a echeance"
        deps={[etabActif, rechargement, filtreEmploye, filtreType]}
        charger={() => api.qualifications({
          ...(filtreEmploye ? { employe: filtreEmploye } : {}),
          ...(filtreType ? { type: filtreType } : {}),
        })}
        transforme={(liste) => [...liste].sort((a, b) => {
          // L'echeance la plus proche en tete, les dates absentes en dernier : une ligne sans date
          // ne doit pas s'installer en haut comme si elle etait urgente.
          const ja = joursAvant(a.dateValidite)
          const jb = joursAvant(b.dateValidite)
          if (ja === null) return 1
          if (jb === null) return -1
          return ja - jb
        })}
        vide={"Aucune qualification enregistree. Un creneau qui en exige une refusera toute "
          + "affectation tant qu'elle n'est pas saisie ici."}
        colonnes={colonnes}
        actions={(
          <div style={{ display: 'flex', gap: 'var(--esp-normal)', alignItems: 'center', flexWrap: 'wrap' }}>
            <select className="input sm" value={filtreEmploye} aria-label="Filtrer par employe"
              style={{ fontSize: 12, padding: '2px 6px' }}
              onChange={(e) => setFiltreEmploye(e.target.value)}>
              <option value="">Tous les employes</option>
              {employes.map((e) => (
                <option key={e.id} value={`/api/employes/${e.id}`}>{nomEmploye(e)}</option>
              ))}
            </select>
            <select className="input sm" value={filtreType} aria-label="Filtrer par type"
              style={{ fontSize: 12, padding: '2px 6px' }}
              onChange={(e) => setFiltreType(e.target.value)}>
              <option value="">Tous les types</option>
              {QUALIFS.map(([v]) => <option key={v} value={v}>{v}</option>)}
            </select>
            {peutGerer && (
              <button className="btn primary sm" type="button" onClick={() => setEdition({})}>
                ＋ Saisir une qualification
              </button>
            )}
          </div>
        )}
      />

      <QualificationModal
        open={edition !== null}
        valeur={edition}
        employes={employes}
        onClose={() => setEdition(null)}
        onFait={() => { setEdition(null); setRechargement((n) => n + 1) }}
      />
    </div>
  )
}

// SAISIR OU CORRIGER UN BREVET.
//
// ⚠ IL N'Y A PAS DE SUPPRESSION, ET CE N'EST PAS UN OUBLI D'ECRAN : `Qualification` expose
// `GetCollection`, `Get`, `Post` et `Patch` — pas de `Delete`. Une saisie erronee se corrige, y
// compris en changeant l'employe. L'ecran le dit plutot que de laisser chercher le bouton.
//
// ⚠ LE LIBELLE EST OBLIGATOIRE QUAND LE TYPE EST « autre » : `QualificationLibelleCoherentValidator`
// refuse en 422 un `autre` sans libelle. Le formulaire l'exige donc AVANT l'envoi — sans quoi
// l'utilisateur recoit un refus sur un formulaire qui vient de se declarer complet, exactement le
// defaut deja corrige sur `dateEntree` dans la modale voisine.
function QualificationModal({ open, valeur, employes = [], onClose, onFait }) {
  const edite = Boolean(valeur && valeur.id)
  const [employe, setEmploye] = useState('')
  const [type, setType] = useState('BNSSA')
  const [libelle, setLibelle] = useState('')
  const [obtention, setObtention] = useState('')
  const [validite, setValidite] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return
    const v = valeur || {}
    const refEmploye = typeof v.employe === 'object' && v.employe
      ? `/api/employes/${v.employe.id}`
      : (typeof v.employe === 'string' ? v.employe : '')
    setEmploye(refEmploye)
    setType(v.type || 'BNSSA')
    setLibelle(v.libelle || '')
    setObtention(v.dateObtention ? String(v.dateObtention).slice(0, 10) : '')
    setValidite(v.dateValidite ? String(v.dateValidite).slice(0, 10) : '')
    setErreur(null)
  }, [open, valeur])

  const libelleRequis = type === 'autre'
  const pret = employe && type && validite && (!libelleRequis || libelle.trim())

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      const corps = {
        employe,
        type,
        dateValidite: validite,
        ...(libelle.trim() ? { libelle: libelle.trim() } : {}),
        ...(obtention ? { dateObtention: obtention } : {}),
      }
      if (edite) await api.majQualification(valeur.id, corps)
      else await api.creerQualification(corps)
      onFait()
    } catch (err) {
      // La raison rendue par l'API, jamais un texte generique : c'est elle qui dit lequel des
      // controles serveur a refuse, et l'utilisateur ne peut pas le deviner.
      setErreur(err.message || "La qualification n'a pas pu etre enregistree.")
    } finally {
      setEnvoi(false)
    }
  }

  const nomEmploye = (e) => [e?.prenom, e?.nom].filter(Boolean).join(' ') || '—'

  return (
    <Modal open={open} onClose={onClose} titre={edite ? 'Corriger une qualification' : 'Saisir une qualification'}>
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="qu-employe">Employe *</label>
            <select id="qu-employe" className="input" value={employe} onChange={(e) => setEmploye(e.target.value)}>
              <option value="">Choisir…</option>
              {employes.map((e) => (
                <option key={e.id} value={`/api/employes/${e.id}`}>{nomEmploye(e)}</option>
              ))}
            </select>
            {edite && <span className="hint">Corriger l'employe deplace la qualification : c'est le seul moyen de reparer une saisie faite sur la mauvaise personne.</span>}
          </div>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="qu-type">Type *</label>
            <select id="qu-type" className="input" value={type} onChange={(e) => setType(e.target.value)}>
              {QUALIFS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
        </div>

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="qu-libelle">Libelle {libelleRequis ? '*' : ''}</label>
            <input id="qu-libelle" className="input" value={libelle} maxLength={120}
              placeholder={libelleRequis ? 'Intitule exact du brevet' : 'Facultatif'}
              onChange={(e) => setLibelle(e.target.value)} />
            {libelleRequis && <span className="hint">Obligatoire pour le type « autre » : sans lui, le serveur refuse l'enregistrement.</span>}
          </div>
        </div>

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="qu-obtention">Date d'obtention</label>
            <input id="qu-obtention" className="input" type="date" value={obtention}
              onChange={(e) => setObtention(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="qu-validite">Valide jusqu'au *</label>
            <input id="qu-validite" className="input" type="date" value={validite}
              onChange={(e) => setValidite(e.target.value)} />
            <span className="hint">C'est cette date qui decide si un agent peut etre affecte a un creneau le jour dit.</span>
          </div>
        </div>

        <p className="hint" style={{ marginTop: 'var(--esp-large)' }}>
          Une qualification ne se supprime pas : le serveur n'expose pas ce geste. Une saisie erronee
          se corrige, y compris en changeant l'employe.
        </p>

        <div className="modal-actions" style={{ marginTop: 'var(--esp-large)' }}>
          <button className="btn ghost" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={!pret || envoi}>
            {envoi ? 'Enregistrement…' : (edite ? 'Corriger' : 'Enregistrer')}
          </button>
        </div>
      </form>
    </Modal>
  )
}


// LA FICHE D'UN EMPLOYE — ET LE GESTE QUI LE SORT DE L'ORPHELINAT.
//
// ── LE CUL-DE-SAC QU'ELLE REFERME ───────────────────────────────────────────────────────────────
//
// `EmissionBadgeStaffHandler` refuse un badge a tout employe sans `RattachementEmploye` ACTIF sur
// l'etablissement (RG-PERSO-09). Or `EmployeModal` n'envoie a la creation que
// `{nom, prenom, poste, typeContrat, matricule, dateEntree}` — JAMAIS de rattachement — et aucun
// ecran ne savait en poser. Tout employe cree par le produit naissait donc orphelin, et le restait.
//
// ⚠ ET L'ORPHELINAT N'EST PAS QU'UNE GENE. Un employe sans rattachement n'appartient a aucun
// etablissement, donc a AUCUN CLIENT : `PerimetrePersonnelExtension` le rend visible a tout
// detenteur de `personnel.gerer_employe`, quel que soit son etablissement actif — et il apparait
// dans la COLLECTION, pas seulement en acces direct par identifiant. Mesure du 08/09.
//
// ── CE QUI EST MODIFIABLE, ET CE QUI NE L'EST PAS ───────────────────────────────────────────────
//
// ⚠ `statut` N'EST PAS PROPOSE : il est dans `employe:read` seul (`Employe.php:106-108`) et se
// change par `/suspendre` et `/reactiver`, deja branches dans la liste. L'offrir ici produirait un
// champ qui a l'air d'ecrire et n'ecrit rien.
//
// ⚠ `utilisateur` n'est pas propose non plus, bien qu'il SOIT dans `employe:write` : lier un compte
// est le lot 3, et l'exposer ici sans ecran de choix de compte offrirait un champ d'adresse brute.
function FicheEmploye({ employe, droits = [], etabActif, onClose, onChange }) {
  const ouvert = Boolean(employe && employe.id)
  const peutGererEmploye = aLeDroit(droits, 'personnel.gerer_employe')

  const [nom, setNom] = useState('')
  const [prenom, setPrenom] = useState('')
  const [poste, setPoste] = useState('')
  const [contrat, setContrat] = useState('cdi')
  const [matricule, setMatricule] = useState('')
  const [entree, setEntree] = useState('')
  const [sortie, setSortie] = useState('')

  // ⚠ TROIS ETATS, PAS DEUX : « pas encore charge », « charge », « la demande a echoue ».
  //
  // Le bandeau « pas encore rattache » se deduit d'une liste VIDE. Si un refus ou une panne rendait
  // aussi une liste vide, l'ecran affirmerait une absence qu'il n'a pas mesuree — et inviterait a
  // creer un rattachement qui existe peut-etre deja. `null` dit « je ne sais pas encore ».
  const [rattachements, setRattachements] = useState(null)
  const [erreurRattachements, setErreurRattachements] = useState(null)
  const [badges, setBadges] = useState(null)
  const [absences, setAbsences] = useState(null)
  const [etablissements, setEtablissements] = useState([])

  const [ajout, setAjout] = useState(false)
  const [siteAjout, setSiteAjout] = useState('')
  const [posteLocal, setPosteLocal] = useState('')
  const [debutAjout, setDebutAjout] = useState(jourLocal())
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  const [rechargement, setRechargement] = useState(0)

  useEffect(() => {
    if (!ouvert) return
    setNom(employe.nom || '')
    setPrenom(employe.prenom || '')
    setPoste(employe.poste || '')
    setContrat(employe.typeContrat || 'cdi')
    setMatricule(employe.matricule || '')
    setEntree(employe.dateEntree ? String(employe.dateEntree).slice(0, 10) : '')
    setSortie(employe.dateSortie ? String(employe.dateSortie).slice(0, 10) : '')
    setErreur(null)
    setAjout(false)
  }, [ouvert, employe])

  useEffect(() => {
    if (!ouvert) return
    let vivant = true
    const ref = `/api/employes/${employe.id}`

    setRattachements(null)
    setErreurRattachements(null)
    api.rattachements({ employe: ref })
      .then((r) => { if (vivant) setRattachements(membres(r)) })
      .catch((e) => {
        if (!vivant) return
        // On laisse `rattachements` a `null` : sans mesure, pas de bandeau.
        setErreurRattachements(e.message || 'Les rattachements n\'ont pas pu etre lus.')
      })

    api.badgeStaffs({ employe: ref }).then((r) => { if (vivant) setBadges(membres(r)) }).catch(() => { if (vivant) setBadges([]) })
    api.absences({ employe: ref }).then((r) => { if (vivant) setAbsences(membres(r)) }).catch(() => { if (vivant) setAbsences([]) })

    return () => { vivant = false }
  }, [ouvert, employe, rechargement])

  // ⚠ CHARGE POUR TOUT LE MONDE, PAS SEULEMENT POUR QUI PEUT ECRIRE.
  //
  // Cette liste sert le select d'ajout — mais AUSSI l'affichage du nom de chaque site rattache : la
  // relation `etablissement` arrive en IRI pure, sans nom (voir plus bas). La conditionner au droit
  // d'ecriture aurait rendu la colonne « Site » illisible pour un lecteur, alors qu'il a le droit de
  // voir les rattachements.
  useEffect(() => {
    if (!ouvert) return
    let vivant = true
    api.etablissements().then((r) => { if (vivant) setEtablissements(membres(r)) }).catch(() => { if (vivant) setEtablissements([]) })
    return () => { vivant = false }
  }, [ouvert, etabActif])

  if (!ouvert) return null

  const orphelin = Array.isArray(rattachements) && rattachements.length === 0

  // ⚠ `etablissement` ARRIVE EN IRI PURE, ET LA COLONNE SORTAIT VIDE.
  //
  // Mesure du 08/09 sur la pile de test : le serveur rend
  //
  //     "etablissement": "/api/etablissements/<uuid>"     ← une CHAINE, pas un objet
  //     "employe": { "@id": …, "@type": …, "id": … }      ← un objet, mais sans nom ni prenom
  //
  // Deux formes differentes DANS LA MEME REPONSE, et aucune ne porte de libelle. C'est la premiere
  // famille de defauts de ce depot, rencontree deux fois dans la meme journee : sur les
  // qualifications (objet sans nom) puis ici (IRI pure). `idDe` absorbe les deux formes.
  //
  // ⚠ Et quand le site n'est pas dans la liste, on LE DIT. `etablissement` est `nullable: false` :
  // un tiret affirmerait une absence impossible.
  const indexSites = new Map(etablissements.map((e) => [e.id, e]))

  const nomDuSite = (reference) => {
    const id = idDe(reference)
    if (!id) return 'site non renseigne'
    const site = indexSites.get(id)
    return site ? (site.nom || 'site sans nom') : 'site hors de la liste chargee'
  }

  async function enregistrerIdentite(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.majEmploye(employe.id, {
        nom: nom.trim(),
        prenom: prenom.trim(),
        poste: poste.trim(),
        typeContrat: contrat,
        matricule: matricule.trim() ? matricule.trim() : null,
        dateEntree: entree,
        dateSortie: sortie || null,
      })
      onChange?.()
      onClose()
    } catch (err) {
      setErreur(err.message || "La fiche n'a pas pu etre corrigee.")
    } finally {
      setEnvoi(false)
    }
  }

  async function poserRattachement() {
    setErreur(null)
    setEnvoi(true)
    try {
      await api.creerRattachement({
        employe: `/api/employes/${employe.id}`,
        etablissement: siteAjout,
        ...(posteLocal.trim() ? { posteLocal: posteLocal.trim() } : {}),
        debut: debutAjout,
      })
      setAjout(false)
      setSiteAjout('')
      setPosteLocal('')
      setRechargement((n) => n + 1)
      onChange?.()
    } catch (err) {
      setErreur(err.message || "Le rattachement n'a pas pu etre pose.")
    } finally {
      setEnvoi(false)
    }
  }

  // CLORE N'EST PAS RETIRER, ET LES DEUX EXISTENT POUR DE BONNES RAISONS.
  //
  // On CLOT le rattachement d'un salarie qui ne travaille plus sur ce site : la ligne reste, avec sa
  // date de fin. `RattachementEmploye::estActifA()` la lit, et `EmissionBadgeStaffHandler` s'en sert
  // — clore suffit donc a couper l'eligibilite au badge SANS effacer l'historique du planning passe.
  //
  // On RETIRE une ligne saisie par erreur, et seulement celle-la. Confondre les deux, c'est soit
  // garder eligible quelqu'un qui est parti, soit perdre la trace d'une periode reellement
  // travaillee — et c'est la paie qui la relit.
  //
  // Meme distinction que suspendre/revoquer sur un badge, quelques ecrans plus haut.
  async function cloreRattachement(r) {
    const site = nomDuSite(r.etablissement)
    if (!await confirmer(
      `Clore le rattachement a ${site} aujourd'hui ?\n\n`
      + "La ligne est conservee avec sa date de fin : le salarie n'est plus eligible au planning ni "
      + 'au badge sur ce site, et son historique reste lisible.',
    )) return
    setErreur(null)
    setEnvoi(true)
    try {
      await api.majRattachement(r.id, { fin: jourLocal() })
      setRechargement((n) => n + 1)
      onChange?.()
    } catch (err) {
      setErreur(err.message || "Le rattachement n'a pas pu etre clos.")
    } finally {
      setEnvoi(false)
    }
  }

  async function retirerRattachement(r) {
    const site = nomDuSite(r.etablissement)
    if (!await confirmer(
      `Retirer le rattachement a ${site} ?\n\n`
      + "La ligne est EFFACEE, son historique avec. Pour un depart, prefere « Clore » : la periode "
      + "travaillee reste lisible.\n\nS'il s'agit du dernier rattachement, l'employe ne pourra plus "
      + 'recevoir de badge et ne sera plus visible que du role RH.',
    )) return
    setErreur(null)
    setEnvoi(true)
    try {
      await api.supprimerRattachement(r.id)
      setRechargement((n) => n + 1)
      onChange?.()
    } catch (err) {
      setErreur(err.message || "Le rattachement n'a pas pu etre retire.")
    } finally {
      setEnvoi(false)
    }
  }

  const titre = [prenom, nom].filter(Boolean).join(' ') || 'Fiche employe'

  return (
    <>
      <h2>{titre}</h2>
      {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

      {/* LE BANDEAU DIT LES DEUX CONSEQUENCES MESUREES, PAS UNE INQUIETUDE VAGUE. */}
      {orphelin && (
        <div className="banner banner-warn" style={{ marginBottom: 'var(--esp-large)' }}>
          Pas encore rattache a un site : il ne peut pas recevoir de badge de service (RG-PERSO-09),
          et sa fiche n'est visible que des detenteurs du droit de gestion RH.
        </div>
      )}
      {erreurRattachements && (
        <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>
          Rattachements illisibles : {erreurRattachements} — l'absence de rattachement n'est donc pas
          etablie ici.
        </div>
      )}

      <form onSubmit={enregistrerIdentite}>
        <h4 style={{ margin: '0 0 var(--esp-normal)' }}>Identite</h4>

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 180px' }}>
            <label htmlFor="fe-prenom">Prenom *</label>
            <input id="fe-prenom" className="input" value={prenom} maxLength={100}
              disabled={!peutGererEmploye} onChange={(e) => setPrenom(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 180px' }}>
            <label htmlFor="fe-nom">Nom *</label>
            <input id="fe-nom" className="input" value={nom} maxLength={100}
              disabled={!peutGererEmploye} onChange={(e) => setNom(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 160px' }}>
            <label htmlFor="fe-matricule">Matricule</label>
            <input id="fe-matricule" className="input" value={matricule} maxLength={40}
              disabled={!peutGererEmploye} onChange={(e) => setMatricule(e.target.value)} />
          </div>
        </div>

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="fe-poste">Poste *</label>
            <input id="fe-poste" className="input" value={poste} maxLength={80}
              disabled={!peutGererEmploye} onChange={(e) => setPoste(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 160px' }}>
            <label htmlFor="fe-contrat">Type de contrat *</label>
            <select id="fe-contrat" className="input" value={contrat}
              disabled={!peutGererEmploye} onChange={(e) => setContrat(e.target.value)}>
              {CONTRATS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div className="field" style={{ flex: '1 1 140px' }}>
            <label htmlFor="fe-entree">Date d'entree *</label>
            <input id="fe-entree" className="input" type="date" value={entree}
              disabled={!peutGererEmploye} onChange={(e) => setEntree(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 140px' }}>
            <label htmlFor="fe-sortie">Date de sortie</label>
            <input id="fe-sortie" className="input" type="date" value={sortie}
              disabled={!peutGererEmploye} onChange={(e) => setSortie(e.target.value)} />
            <span className="hint">Le statut, lui, se change par « Suspendre » dans la liste.</span>
          </div>
        </div>

        {peutGererEmploye && (
          <div style={{ textAlign: 'right', marginBottom: 'var(--esp-large)' }}>
            <button className="btn primary sm" type="submit"
              disabled={envoi || !nom.trim() || !prenom.trim() || !poste.trim() || !entree}>
              {envoi ? 'Enregistrement…' : 'Enregistrer les corrections'}
            </button>
          </div>
        )}
      </form>

      <h4 style={{ margin: 'var(--esp-large) 0 var(--esp-normal)' }}>Rattachements</h4>
      <p className="hint" style={{ marginTop: 0 }}>
        Le site ou l'employe travaille. Il ouvre l'eligibilite au planning et au badge — il ne donne
        aucun acces au logiciel, qui se gere par les roles.
      </p>

      {rattachements === null && !erreurRattachements && <div className="empty">Lecture des rattachements…</div>}

      {Array.isArray(rattachements) && rattachements.length > 0 && (
        <table className="tbl">
          <thead>
            <tr><th>Site</th><th>Poste local</th><th>Depuis</th><th>Jusqu'au</th><th></th></tr>
          </thead>
          <tbody>
            {rattachements.map((r) => (
              <tr key={r.id}>
                <td>{nomDuSite(r.etablissement)}</td>
                <td>{r.posteLocal || '—'}</td>
                <td>{dateFr(r.debut)}</td>
                <td>{r.fin ? dateFr(r.fin) : '—'}</td>
                <td style={{ textAlign: 'right' }}>
                  {peutGererEmploye && !r.fin && (
                    <button className="btn ghost sm" type="button" disabled={envoi}
                      style={{ padding: '1px 8px', fontSize: 11.5 }}
                      title="Le salarie ne travaille plus sur ce site : la ligne est conservee avec sa date de fin."
                      onClick={() => cloreRattachement(r)}>
                      Clore
                    </button>
                  )}
                  {peutGererEmploye && (
                    <button className="btn ghost sm" type="button" disabled={envoi}
                      style={{ padding: '1px 8px', fontSize: 11.5, marginLeft: 'var(--esp-serre)' }}
                      title="Saisie erronee : la ligne est effacee, et son historique avec."
                      onClick={() => retirerRattachement(r)}>
                      Retirer
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {peutGererEmploye && !ajout && (
        <div style={{ marginTop: 'var(--esp-normal)' }}>
          <button className="btn ghost sm" type="button" onClick={() => setAjout(true)}>
            ＋ Rattacher a un site
          </button>
        </div>
      )}

      {peutGererEmploye && ajout && (
        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="fe-site">Site *</label>
            <select id="fe-site" className="input" value={siteAjout} onChange={(e) => setSiteAjout(e.target.value)}>
              <option value="">Choisir…</option>
              {etablissements.map((e) => (
                <option key={e.id} value={`/api/etablissements/${e.id}`}>{e.nom}</option>
              ))}
            </select>
          </div>
          <div className="field" style={{ flex: '1 1 160px' }}>
            <label htmlFor="fe-postelocal">Poste local</label>
            <input id="fe-postelocal" className="input" value={posteLocal} maxLength={80}
              placeholder="Facultatif" onChange={(e) => setPosteLocal(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 140px' }}>
            <label htmlFor="fe-debut">Depuis *</label>
            <input id="fe-debut" className="input" type="date" value={debutAjout}
              onChange={(e) => setDebutAjout(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '0 0 auto' }}>
            <button className="btn primary sm" type="button" disabled={!siteAjout || !debutAjout || envoi}
              onClick={poserRattachement}>
              Rattacher
            </button>
            <button className="btn ghost sm" type="button" style={{ marginLeft: 'var(--esp-serre)' }}
              onClick={() => setAjout(false)}>
              Annuler
            </button>
          </div>
        </div>
      )}

      {/* ON LIT ICI, ON AGIT LA-BAS. Emettre, revoquer, valider une absence restent dans leurs
          onglets : deux endroits pour le meme geste divergent des qu'on en modifie un seul. */}
      <h4 style={{ margin: 'var(--esp-large) 0 var(--esp-normal)' }}>Badges de service</h4>
      {badges === null && <div className="empty">Lecture…</div>}
      {Array.isArray(badges) && badges.length === 0 && (
        <div className="empty">Aucun badge emis. L'emission se fait depuis l'onglet « Employes ».</div>
      )}
      {Array.isArray(badges) && badges.length > 0 && (
        <table className="tbl">
          <thead><tr><th>Numero</th><th>Statut</th><th>Emis le</th></tr></thead>
          <tbody>
            {badges.map((b) => (
              <tr key={b.id}>
                <td className="mono">{b.numeroSerie || b.code || '—'}</td>
                <td><span className={`badge ${STATUT_BADGE[b.statut] || 'mut'}`}>{b.statut || '—'}</span></td>
                <td>{dateFr(b.dateEmission || b.creeLe)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <h4 style={{ margin: 'var(--esp-large) 0 var(--esp-normal)' }}>Absences</h4>
      {absences === null && <div className="empty">Lecture…</div>}
      {Array.isArray(absences) && absences.length === 0 && (
        <div className="empty">Aucune absence declaree. La declaration se fait depuis le panneau « Absences ».</div>
      )}
      {Array.isArray(absences) && absences.length > 0 && (
        <table className="tbl">
          <thead><tr><th>Type</th><th>Du</th><th>Au</th><th>Statut</th></tr></thead>
          <tbody>
            {absences.map((a) => (
              <tr key={a.id}>
                <td>{a.type || '—'}</td>
                <td>{dateFr(a.debut)}</td>
                <td>{dateFr(a.fin)}</td>
                <td>{a.statut || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <div className="modal-actions" style={{ marginTop: 'var(--esp-large)' }}>
        <button className="btn ghost" type="button" onClick={onClose}>Fermer</button>
      </div>
    </>
  )
}


// EMETTRE UN BADGE — LE GESTE QUI N AVAIT JAMAIS PU ABOUTIR.
//
// `api.emettreBadgeStaff` envoyait `body: {}`. Le processeur exige TROIS champs et refuse au premier
// manquant, donc le bouton rendait 422 depuis l origine. Constate en cliquant le 08/09 :
// « Reference "etablissement" obligatoire (UUID ou IRI). »
//
// Aucun test d API ne pouvait le voir : `BadgeStaffTest` envoie le corps complet. Le serveur etait
// prouve, l ecran ne l etait pas.
//
// ── UN BADGE EST UNE CLE PHYSIQUE ───────────────────────────────────────────────────────────────
//
// Les trois champs ne sont pas de la paperasse : ils decident QUELLES PORTES s ouvrent et QUAND.
// C est pourquoi ils sont demandes plutot que devines — un defaut par defaut serait un acces pose
// sans que personne ne l ait choisi.
//
// ⚠ L etablissement est celui qui est ACTIF, affiche et non modifiable. Le serveur accepte d en
// cibler un autre (`EtablissementCibleVerificateur`), mais l employe doit y avoir un rattachement
// ACTIF (RG-PERSO-09) : proposer un choix inviterait a des refus que l ecran ne sait pas expliquer.
function EmissionBadgeModal({ employe, etabActif, onClose, onFait }) {
  const ouvert = Boolean(employe && employe.id)
  // ⚠ TROIS ETATS, PAS DEUX — ET LE DEFAUT ETAIT ICI AVANT CETTE LIGNE.
  //
  // `null` = pas encore lu · `[]` = lu, et il n'y en a aucun · `erreurEspaces` = la demande a echoue.
  //
  // La premiere version faisait `.catch(() => setEspaces([]))`, ce qui transformait un REFUS en
  // « aucun espace d'acces sur ce site ». Mesure du 09/09 : `/api/espace_acces` rend **403** au role
  // *Personnel Administrateur RH*, alors qu'il y a huit espaces en base. L'ecran affirmait donc une
  // absence qu'il n'avait pas mesuree, et envoyait l'exploitant creer un espace qui existe deja.
  const [espaces, setEspaces] = useState(null)
  const [erreurEspaces, setErreurEspaces] = useState(null)
  const [choisis, setChoisis] = useState([])
  const [mode, setMode] = useState('shifts_uniquement')
  const [marge, setMarge] = useState(15)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!ouvert) return
    setChoisis([])
    setMode('shifts_uniquement')
    setMarge(15)
    setErreur(null)
    let vivant = true
    setEspaces(null)
    setErreurEspaces(null)
    api.espacesAcces()
      .then((r) => { if (vivant) setEspaces(membres(r)) })
      .catch((e) => { if (vivant) setErreurEspaces(e.message || 'Lecture impossible.') })
    return () => { vivant = false }
  }, [ouvert, employe])

  if (!ouvert) return null

  // Le serveur exige la marge en mode `shifts_uniquement` : on l exige ici aussi, plutot que de
  // laisser l utilisateur decouvrir le refus apres coup.
  const pret = etabActif && choisis.length > 0 && mode
    && (mode !== 'shifts_uniquement' || Number.isFinite(Number(marge)))
  const listeEspaces = espaces || []

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.emettreBadgeStaff(employe.id, {
        etablissement: `/api/etablissements/${etabActif}`,
        modeHoraire: mode,
        espacesAutorises: choisis,
        ...(mode === 'shifts_uniquement' ? { margeAvantApres: Number(marge) } : {}),
      })
      onFait()
    } catch (err) {
      // La raison du serveur, telle quelle : c est elle qui dit lequel des controles a refuse.
      // « Aucun rattachement actif » et « badge deja actif » sont deux refus differents, et
      // l utilisateur ne peut pas les distinguer sans le texte.
      setErreur(err.message || "Le badge n'a pas pu etre emis.")
    } finally {
      setEnvoi(false)
    }
  }

  const nom = [employe.prenom, employe.nom].filter(Boolean).join(' ') || 'cet employe'

  function basculer(iri) {
    setChoisis((liste) => liste.includes(iri) ? liste.filter((x) => x !== iri) : [...liste, iri])
  }

  return (
    <>
      <h2>{`Emettre un badge — ${nom}`}</h2>
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        <p className="hint" style={{ marginTop: 0 }}>
          Un badge est une cle physique : ces trois choix decident quelles portes il ouvre, et quand.
          L employe doit avoir un rattachement actif sur ce site, sinon le serveur refuse
          (RG-PERSO-09).
        </p>

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 200px' }}>
            <label htmlFor="eb-mode">Mode horaire *</label>
            <select id="eb-mode" className="input" value={mode} onChange={(e) => setMode(e.target.value)}>
              <option value="shifts_uniquement">Pendant ses creneaux seulement</option>
              <option value="permanent">Permanent</option>
            </select>
            <span className="hint">
              « Permanent » ouvre en dehors de tout planning : a reserver a qui doit entrer a toute heure.
            </span>
          </div>
          {mode === 'shifts_uniquement' && (
            <div className="field" style={{ flex: '1 1 160px' }}>
              <label htmlFor="eb-marge">Marge avant/apres (minutes) *</label>
              <input id="eb-marge" className="input" type="number" min="0" max="240" value={marge}
                onChange={(e) => setMarge(e.target.value)} />
              <span className="hint">De combien il peut arriver en avance et repartir en retard.</span>
            </div>
          )}
        </div>

        <div className="field">
          <label>Espaces autorises *</label>
          {erreurEspaces ? (
            // ⚠ ON NE DIT PAS « AUCUN ESPACE » : on n'en sait rien. Le refus le plus frequent ici est
            // un 403 -- le role *Personnel Administrateur RH* detient `personnel.gerer_badge` mais PAS
            // `acces.lire`, donc il peut emettre un badge et ne peut pas lire les espaces que
            // l'emission exige. Dire « aucun espace » enverrait creer ce qui existe deja.
            <div className="banner banner-error">
              Les espaces d acces n ont pas pu etre lus : {erreurEspaces}
              <div className="sub" style={{ marginTop: 'var(--esp-serre)' }}>
                Ce n est pas « aucun espace » : la liste n a pas pu etre demandee. Emettre un badge
                exige de lire les espaces (droit « acces.lire »), en plus du droit d emettre.
              </div>
            </div>
          ) : espaces === null ? (
            <div className="empty">Lecture des espaces…</div>
          ) : listeEspaces.length === 0 ? (
            <div className="empty">
              Aucun espace d acces sur ce site. Sans espace, le serveur refuse l emission : commence
              par en declarer un dans « Controle d acces ».
            </div>
          ) : (
            <div style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
              {listeEspaces.map((es) => {
                const iri = `/api/espace_acces/${es.id}`
                return (
                  <label key={es.id} style={{ display: 'flex', gap: 'var(--esp-normal)', alignItems: 'center' }}>
                    <input type="checkbox" checked={choisis.includes(iri)} onChange={() => basculer(iri)} />
                    <span>{es.libelle || es.nom || es.id}</span>
                  </label>
                )
              })}
            </div>
          )}
        </div>

        <div className="modal-actions" style={{ marginTop: 'var(--esp-large)' }}>
          <button className="btn ghost" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={!pret || envoi}>
            {envoi ? 'Emission…' : 'Emettre'}
          </button>
        </div>
      </form>
    </>
  )
}
