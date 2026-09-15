import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import FicheAbonnement from '../components/FicheAbonnement.jsx'
import { aLeDroit } from '../api/droits.js'
import { resoudre, nomOuAbsence, euroCentimes, dateFr, jourLocal } from '../components/Liste.jsx'
import Modal from '../components/Modal.jsx'
import { libelleProduit } from '../api/produit.js'
import { useEtatUrl } from '../api/url.js'

/**
 * SPORT & FITNESS — et d'abord **les alertes que personne n'entendait**.
 *
 * Le module gère des abonnements, des pauses, des résiliations, du prélèvement — et deux choses qui ne
 * relèvent pas du confort : `EvenementSOS` et `AlertePresenceIsolee`. Une salle en accès autonome, la
 * nuit, avec quelqu'un seul dedans.
 *
 * **Un SOS porte un statut *ouverte* et une opération « traiter ». Il n'existait aucun écran.** Une
 * alarme qu'aucune interface ne montre n'est pas une fonctionnalité en attente : c'est une alarme que
 * personne n'entend, sur un dispositif dont l'exploitant croit qu'il le protège.
 *
 * > **Un mécanisme d'alerte sans destinataire est plus dangereux que pas d'alerte du tout : il crée la
 * > croyance qu'on serait prévenu.**
 *
 * C'est pourquoi les SOS ouverts sont **en tête, avant tout le reste**, et affichés même quand il n'y
 * en a aucun — l'écran doit dire *« aucune alerte »*, pas se taire. Un bloc absent ne se distingue pas
 * d'un bloc qu'on a oublié de charger.
 *
 * L'ordre du reste suit la même logique : ce qui demande une action avant ce qui informe.
 */

// ── CE QU'UN TERME RACONTE, ET QUE LA SEULE DATE NE DIT PAS ────────────────────────────────────
//
// Une date d'echeance affichee nue oblige chaque lecteur a faire la soustraction dans sa tete, tous
// les jours, sur chaque ligne. C'est exactement le calcul que personne ne fait -- et c'est pour ca
// qu'un abonnement au terme pouvait rester des mois sans que quiconque le remarque.
const JOUR_MS = 24 * 60 * 60 * 1000

function etatDuTerme(iso) {
  if (!iso) return null
  const fin = new Date(iso)
  if (Number.isNaN(fin.getTime())) return null

  const jours = Math.ceil((fin.getTime() - Date.now()) / JOUR_MS)

  if (jours < 0) return { classe: 'err', texte: `au terme depuis ${-jours} j`, urgent: true }
  if (jours === 0) return { classe: 'err', texte: "au terme aujourd'hui", urgent: true }
  if (jours <= 30) return { classe: 'warn', texte: `dans ${jours} j`, urgent: true }
  return { classe: 'mut', texte: `dans ${Math.round(jours / 30)} mois`, urgent: false }
}

// Le statut ne se lit pas pareil selon ce qu'il implique : « echu » veut dire que l'acces est
// coupe, et un badge gris comme les autres le noierait dans la liste.
// ⚠ CETTE FONCTION RENDAIT `ok` ET `err`, QUI NE SONT PAS DES CLASSES.
//
// `styles.css` ne declare que `.badge.good`, `.warn`, `.crit`, `.info` et `.mut` (ligne 371,
// enumeration complete). `badge ok` et `badge err` ne peignaient donc RIEN : un abonnement `actif`
// et un abonnement `echu` s'affichaient a l'identique, sans couleur, pendant que seul
// « resilie/impaye » etait colore. La distinction la plus importante du tableau ne portait rien.
//
// Le garde-fou des classes CSS ne pouvait pas l'attraper : il lit les litteraux, et ici le nom est
// calcule (`badge ${tonStatut(...)}`).
function tonStatut(statut) {
  if (statut === 'echu') return 'crit'
  if (statut === 'resilie' || statut === 'impaye') return 'warn'
  if (statut === 'actif') return 'good'
  return 'mut'
}

// ⚠ « EN PAUSE » ET « ANNULEE » NE DOIVENT JAMAIS SE RESSEMBLER.
//
// `Gelee` veut dire que l'adherent a demande une suspension : l'echeance REVIENDRA a la reprise.
// `Annulee` veut dire qu'elle ne sera jamais collectee. Les afficher pareil ferait croire qu'un
// abonne en pause a perdu son echeancier. Elles different donc par la couleur ET par le mot — on
// ecrit « en pause », jamais « gelee », parce que le mot du modele ne dit pas au lecteur ce qui
// va se passer.
const ETAT_ECHEANCE = {
  a_venir: { mot: 'à venir', classe: 'info' },
  prelevee: { mot: 'prélevée', classe: 'good' },
  rejetee: { mot: 'rejetée', classe: 'crit' },
  gelee: { mot: 'en pause', classe: 'warn' },
  annulee: { mot: 'annulée', classe: 'mut' },
}

function etatEcheance(statut) {
  return ETAT_ECHEANCE[statut] || { mot: statut || '—', classe: 'mut' }
}

// ⚠ `adherent` ET `payeur` — PAS `beneficiaire` NI `client`.
//
// L'écran lisait `a.client` et `a.beneficiaire` : deux noms que l'API n'envoie jamais. La colonne
// « Adhérent » affichait donc « — » sur TOUS les abonnements, depuis toujours. C'est le défaut
// jumeau de celui déjà corrigé deux colonnes plus loin (`dateFinEngagement`, pas `dateFin`).
//
// Et le bon nom ne suffit pas : `adherent` revient en IRI nue, parce que ni `Beneficiaire` ni
// `Client` ne portent le groupe `abonnement:read`. On recoupe donc contre `GET /api/beneficiaires`,
// où `Client::$nom` et `$prenom` sont exposés (groupe `beneficiaire:read`) — plutôt que d'élargir
// la sérialisation pour un besoin qu'un appel existant couvre déjà.
// Le PAYEUR est un `Client`, pas un `Beneficiaire` : deux types, parce qu'ils repondent a deux
// questions differentes -- qui entre, et qui regle. L'API rend la relation embarquee ou en IRI
// selon le groupe ; on couvre les deux plutot que de supposer.
function nomPayeur(abonnement) {
  const p = abonnement?.payeur
  if (!p) return '—'
  if (typeof p === 'string') return 'client rattaché'
  return nomOuAbsence(p, '') || 'client rattaché'
}

function nomAdherent(abonnement, beneficiaires) {
  if (!abonnement) return <span className="sub">—</span>

  // ⚠ `null` = la liste n'a pas été lue (403 sans `crm.lire`, par exemple). Rendre « — » ferait
  // lire « cet abonnement n'a pas d'adhérent » là où on n'a simplement pas regardé.
  if (beneficiaires === null) {
    return <span className="sub">nom non lu — les bénéficiaires n’ont pas été obtenus</span>
  }

  const benef = resoudre(abonnement.adherent, beneficiaires)
  const nom = benef && benef.client ? nomOuAbsence(benef.client, '') : ''
  if (nom) return <span className="nm">{nom}</span>

  return <span className="sub">adhérent — nom non transmis</span>
}

function quandHeure(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function depuis(v) {
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return null
  const minutes = Math.floor((Date.now() - d.getTime()) / 60000)
  if (minutes < 1) return 'à l’instant'
  if (minutes < 60) return `il y a ${minutes} min`
  const heures = Math.floor(minutes / 60)
  if (heures < 24) return `il y a ${heures} h`
  return `il y a ${Math.floor(heures / 24)} j`
}

// Un espace se nomme `libelle` ou `nom` selon l'entité : on accepte les deux plutôt que de parier.
function nomEspace(espace) {
  if (!espace) return null
  return espace.libelle || espace.nom || null
}

// ⚠ CETTE PAGE N'AVAIT AUCUN ÉTAT D'URL. On lui en pose un, avec le seul paramètre dont elle a
// besoin : la souscription ouverte. Tout le reste de l'écran reste en état local, parce que
// rien d'autre n'a de raison d'être partagé, mis en signet, ni retrouvé après un F5.
const DEFAUTS_URL = { souscrire: '', rejet: '' }

export default function Sport({ etabActif, droits = [] }) {
  const [params, majParams] = useEtatUrl('sport', DEFAUTS_URL)
  // Le droit exige par le serveur est `sport.superviser_nocturne`, et lui seul : afficher le
  // bouton a qui ne l'a pas produirait un 403 sur un geste d'urgence -- le pire moment pour
  // decouvrir qu'on n'avait pas le droit.
  const peutTraiter = aLeDroit(droits, 'sport.superviser_nocturne')
  // Le droit exige par `POST /sport/abonnements/souscrire`, et lui seul.
  const peutGererAbonnement = aLeDroit(droits, 'sport.gerer_abonnement')
  // ⚠ UN DROIT DISTINCT, et pas `sport.gerer_abonnement` : enregistrer un retour banque declenche
  // du recouvrement sur un adherent. Le serveur exige `recouvrement.piloter` — le bouton apparait
  // donc exactement quand il marchera.
  const peutPiloterRecouvrement = aLeDroit(droits, 'recouvrement.piloter')

  /**
   * DETECTER MAINTENANT, ESPACE PAR ESPACE.
   *
   * ⚠ LE COMPTE-RENDU NOMME CE QUI A ETE BALAYE, PAS SEULEMENT CE QUI A ETE TROUVE. La route est
   * par espace : sans espace, il n'y a rien a interroger, et annoncer « aucune presence isolee »
   * serait affirmer une absence qu'on n'a pas mesuree. `espaces` retombe a `[]` quand sa lecture
   * echoue — le cas se produit vraiment, il n'est pas theorique.
   *
   * ⚠ ET LES ECHECS PARTIELS SE DISENT. Un espace peut refuser (droit, cloisonnement) pendant que
   * les autres repondent. Taire ces refus ferait passer une detection incomplete pour complete.
   */
  async function detecterMaintenant() {
    const cibles = Array.isArray(espaces) ? espaces.filter((e) => e && e.id) : []
    if (cibles.length === 0) {
      setCompteRendu({
        grave: true,
        texte: 'Aucun espace à interroger : la liste des espaces est vide ou n’a pas pu être lue. '
          + 'La détection n’a rien mesuré — ce n’est pas la même chose que « rien trouvé ».',
      })
      return
    }
    setDetection(true)
    setCompteRendu(null)
    const resultats = await Promise.allSettled(cibles.map((e) => api.detecterPresenceIsolee(e.id)))
    const refuses = resultats.filter((r) => r.status === 'rejected').length
    const aboutis = resultats.length - refuses
    try {
      await recharger()
    } catch {
      // Le rechargement peut echouer sans que la detection ait echoue. On ne le confond pas.
    }
    setCompteRendu({
      grave: refuses > 0,
      texte: refuses === 0
        ? `${aboutis} espace(s) interrogé(s). La liste ci-dessous est à jour.`
        : `${aboutis} espace(s) interrogé(s), ${refuses} refusé(s). La liste ci-dessous ne couvre `
          + 'donc pas tous les espaces : ceux qui ont refusé n’ont pas été vérifiés.',
    })
    setDetection(false)
  }
  const [echeances, setEcheances] = useState(null)
  const [beneficiaires, setBeneficiaires] = useState(null)
  const [abonnementOuvert, setAbonnementOuvert] = useState(null)
  const [annulation, setAnnulation] = useState(null)

  // ⚠ `null` VEUT DIRE << PAS LU >>, `[]` VEUT DIRE << LU ET VIDE >>. SUR CET ECRAN, LA
  // DIFFERENCE EST UNE QUESTION DE SECURITE.
  //
  // Ces trois etats partaient a `[]` et y RESTAIENT quand la lecture echouait. L'ecran annoncait
  // alors << aucune alerte en cours >>, << Aucun appel d'urgence en cours >> et << Aucune presence
  // isolee signalee >> -- trois phrases qui disent a un exploitant qu'il peut etre tranquille,
  // alors que personne n'a pu demander. Sur un module de travailleur isole, c'est la pire phrase
  // que ce produit puisse afficher.
  //
  // Le commentaire du bloc SOS, quinze lignes plus bas, enonce deja le principe : << un bloc absent
  // ne se distingue pas d'un bloc qu'on a oublie de charger >>. Il avait ete applique au VIDE et
  // pas au NON-LU -- si bien que le raisonnement qui a motive le bloc etait defait par l'etat
  // manquant.
  const [sos, setSos] = useState(null)
  const [alertes, setAlertes] = useState(null)
  const [detection, setDetection] = useState(false)
  // Le compte-rendu de la derniere detection. Il porte TOUJOURS le nombre d'espaces interroges :
  // sans lui, « rien trouve » et « rien cherche » se lisent pareil.
  const [compteRendu, setCompteRendu] = useState(null)
  const [abonnements, setAbonnements] = useState(null)
  // Même discipline que partout ici : `null` = pas lu, `[]` = lu et vide. Sur une liste de
  // demandes en attente, confondre les deux dirait « personne n'attend » à un responsable qui
  // n'a simplement pas pu regarder.
  const [resiliations, setResiliations] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)
  // Un compteur << il y a N minutes >> qui ne bouge pas est un compteur faux : on redessine.
  const [, setTic] = useState(0)

  const [espaces, setEspaces] = useState([])

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // OU EST L ALERTE : la premiere question sur un appel d urgence, et elle etait sans reponse.
      //
      // `EvenementSOS.espaceAcces` revient en IRI nue — `EspaceAcces` n'expose rien dans le groupe
      // `sos:read`, vérifié dans l'entité. La carte affichait donc « Espace inconnu » sur CHAQUE
      // alerte, y compris celles dont l'espace est parfaitement enregistré. Sur un écran où l'on
      // court, ce n'est pas une colonne vide : c'est l'information qui dit où courir.
      const [s, a, ab, es, ec, bf, rs] = await Promise.all([
        api.evenementsSOS(),
        api.alertesPresenceIsolee().catch(() => null),
        api.abonnementsFitness().catch(() => null),
        api.espaces().catch(() => null),
        api.echeancesSepaSport().catch(() => null),
        // Le nom de l'adhérent n'est nulle part ailleurs : `abonnement.adherent` est une IRI nue.
        // `crm.lire` peut manquer — d'où le `.catch` et le `null` conservé.
        api.beneficiaires().catch(() => null),
        api.resiliationsSport().catch(() => null),
      ])
      setSos(membres(s))
      // ⚠ `a ? … : []` TRANSFORMAIT UN ECHEC EN LISTE VIDE. Les trois lectures tolerees rendent
      // `null` sur echec (le `.catch` ci-dessus) : les convertir en `[]` effacait la distinction
      // au moment meme ou on l'avait. On garde `null`.
      setAlertes(a ? membres(a) : null)
      setAbonnements(ab ? membres(ab) : null)
      setEspaces(es ? membres(es) : [])
      // Meme regle que les trois au-dessus : `null` reste `null`. Un echeancier illisible qui
      // s'afficherait « aucune echeance » dirait a l'exploitant que personne n'est prelevable.
      setEcheances(ec ? membres(ec) : null)
      setBeneficiaires(bf ? membres(bf) : null)
      setResiliations(rs ? membres(rs) : null)
    } catch (e) {
      setErreur(e.message || 'Le module n’a pas pu être chargé.')
      // On ne garde rien de partiel : un decompte a moitie lu a l'air normal.
      setSos(null)
      setAlertes(null)
      setAbonnements(null)
      setEcheances(null)
      setBeneficiaires(null)
      setResiliations(null)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  // ⚠ L'ÉCHÉANCE D'UN REJET SE LIT PAR SON IDENTIFIANT, PAS DANS L'ÉCHÉANCIER : celui-ci est
  // borné à 200 et lu avec six autres listes. Seul un 404 dit « elle n'existe pas » ; tout le
  // reste est une lecture qui a échoué.
  //
  // ⚠ ET « PAS ENCORE LUE » N'EST PAS « LUE, 404 ». Le chargement était un drapeau posé DANS
  // l'effet, donc après le premier rendu : pendant une trame, drapeau baissé et échéance nulle,
  // l'écran affirmait « n'existe pas » sur une échéance qui existait — une absence jamais mesurée.
  // Le chargement se DÉDUIT désormais : tant que la lecture rangée ne porte pas cet identifiant ET
  // cet établissement, rien n'a été lu. Changer d'établissement relit aussi : l'échéance lue
  // depuis l'ancien ne dit rien de ce que voit le nouveau.
  const [lectureRejet, setLectureRejet] = useState({ id: null, etab: null, echeance: null, echouee: false })
  useEffect(() => {
    const id = params.rejet
    if (!id) return undefined
    let vivant = true
    api.echeanceSepa(id)
      .then((e) => { if (vivant) setLectureRejet({ id, etab: etabActif, echeance: e, echouee: false }) })
      .catch((e) => { if (vivant) setLectureRejet({ id, etab: etabActif, echeance: null, echouee: e?.status !== 404 }) })
    return () => { vivant = false }
  }, [params.rejet, etabActif])
  const chargementRejet = !!params.rejet
    && (lectureRejet.id !== params.rejet || lectureRejet.etab !== etabActif)
  const echeanceRejet = chargementRejet ? null : lectureRejet.echeance
  const lectureRejetEchouee = !chargementRejet && lectureRejet.echouee

  useEffect(() => {
    const t = setInterval(() => setTic((n) => n + 1), 60000)
    return () => clearInterval(t)
  }, [])

  /**
   * VALIDER LE MOTIF LEGITIME D'UNE DEMANDE DE RESILIATION.
   *
   * ⚠ CE N'EST PAS UN ACCORD DE PRINCIPE : LE PREAVIS COMMENCE. La demande passe `en_preavis`, et a
   * sa date d'effet l'acces est coupe, le mandat revoque, les echeances restantes annulees. On le
   * DIT avant, dans la ligne, plutot que de le decouvrir apres.
   *
   * ⚠ ET ON NE LE PROPOSE PAS A QUI NE L'A PAS. Le serveur exige `sport.gerer_abonnement` : montrer
   * le bouton sans le droit produirait un 403 sur une demande qu'un adherent attend.
   */
  async function validerMotif(resiliation) {
    setBusy(true)
    setErreur(null)
    try {
      await api.validerMotifLegitimeResiliation(resiliation.id)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'La validation du motif légitime n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  async function traiter(evenement) {
    setBusy(true)
    setErreur(null)
    try {
      await api.traiterSOS(evenement.id)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'La prise en charge a échoué.')
    } finally {
      setBusy(false)
    }
  }

  // ── LA SOUSCRIPTION, EN ÉCRAN ───────────────────────────────────────────────────────────────
  //
  // Elle prend la page entière. Placée AVANT la garde de chargement : le formulaire va chercher
  // ses propres listes (bénéficiaires, clients, produits) et n'a besoin de rien de ce que la page
  // lit pour elle-même — attendre les alertes et les abonnements ne ferait que retarder un écran
  // qui n'en dépend pas.
  if (params.souscrire === '1') {
    return (
      <div className="view">
        <SouscriptionModal
          open
          onClose={() => majParams({ souscrire: '' }, { pousser: true })}
          onFait={() => { majParams({ souscrire: '' }, { pousser: true }); recharger() }}
        />
      </div>
    )
  }

  // ── LE REJET BANCAIRE, EN ÉCRAN ────────────────────────────────────────────────────────────
  //
  // Même place que la souscription, et pour la même raison : il lit son échéance lui-même.
  //
  // ⚠ L'ADRESSE CONTOURNE LES DEUX CONDITIONS DU BOUTON ET L'ÉCRAN LES REPREND. La seconde est la
  // plus grave : le processeur ne refuse AUCUN état. Un rejet déclaré sur une échéance qui n'est
  // pas prélevée ouvrirait un impayé imaginaire — et le serveur l'accepterait.
  if (params.rejet) {
    const fermerRejet = () => majParams({ rejet: '' }, { pousser: true })
    const e = echeanceRejet
    let contenu
    if (!peutPiloterRecouvrement) {
      contenu = (
        <div className="banner banner-warn">
          Enregistrer un rejet bancaire demande le droit de piloter le recouvrement, que ce compte n’a pas.
        </div>
      )
    } else if (chargementRejet) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (!e) {
      contenu = (
        <div className="banner banner-warn">
          {lectureRejetEchouee
            ? 'Cette échéance n’a pas pu être lue. Ce n’est pas la même chose que « elle n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
            : 'Cette échéance n’existe pas, ou n’est pas visible depuis cet établissement.'}
        </div>
      )
    } else if (e.statut !== 'prelevee') {
      contenu = (
        <div className="banner banner-warn">
          Cette échéance est « {etatEcheance(e.statut)?.mot || e.statut} » : un rejet bancaire ne se
          déclare que sur une échéance prélevée. Un prélèvement qui n’est jamais parti ne peut pas
          revenir impayé.
        </div>
      )
    } else {
      contenu = (
        <RejetEcheanceModal
          key={params.rejet}
          echeance={e}
          onClose={fermerRejet}
          onFait={() => { fermerRejet(); recharger() }}
        />
      )
    }
    return (
      <div className="view">
        <button className="btn ghost sm" type="button" onClick={fermerRejet}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour à Sport &amp; fitness
        </button>
        {contenu}
      </div>
    )
  }

  if (chargement) return <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>

  const ouverts = (sos || []).filter((e) => e.statut === 'ouverte')
  const traites = (sos || []).filter((e) => e.statut !== 'ouverte')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Sport &amp; fitness</h1>
          <div className="sub">
            {sos === null
              ? 'état des alertes inconnu — la lecture n’a pas abouti'
              : ouverts.length > 0
                ? `${ouverts.length} alerte${ouverts.length > 1 ? 's' : ''} à traiter`
                : 'aucune alerte en cours'}
          </div>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      {/* LES SOS EN TETE, ET AFFICHES MEME VIDES.
          Un bloc absent ne se distingue pas d'un bloc qu'on a oublie de charger : l'ecran doit DIRE
          qu'il n'y a rien, sinon l'exploitant ne sait pas s'il est tranquille ou mal informe. */}
      <section className="card" style={{ marginBottom: 14 }}>
        <div className="card-h">
          <span>Appels d&rsquo;urgence</span>
          {ouverts.length > 0 && <span className="badge crit" style={{ marginLeft: 8 }}>{ouverts.length} ouvert{ouverts.length > 1 ? 's' : ''}</span>}
        </div>

        {sos === null ? (
          // ⚠ TON D'ALERTE, PAS TON NEUTRE. Un exploitant qui survole cet ecran doit s'arreter ici :
          // ne pas savoir s'il y a un appel en cours est un evenement, pas une absence d'evenement.
          <div className="banner banner-error" style={{ margin: 'var(--esp-large)' }}>
            <b>Les appels d’urgence n’ont pas pu être lus.</b> Cet écran ne peut pas dire s’il y en a
            un en cours. Rechargez, et si le refus persiste, prévenez quelqu’un sur place plutôt que
            de conclure que tout va bien.
          </div>
        ) : ouverts.length === 0 ? (
          <div className="empty">
            Aucun appel d&rsquo;urgence en cours.
          </div>
        ) : (
          <div style={{ display: 'grid', gap: 8, padding: 14 }}>
            {ouverts.map((e) => (
              <article
                key={e.id}
                className="card"
                style={{ padding: 12, border: '1px solid var(--crit)', display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}
              >
                <span className="nm">{nomEspace(resoudre(e.espaceAcces, espaces)) || 'espace non transmis'}</span>
                <span className="sub">
                  {quandHeure(e.horodatage)} · {depuis(e.horodatage)}
                </span>
                {/* QUI A DÉCLENCHÉ, ET POURQUOI ON LE DIT MÊME QUAND ON NE PEUT PAS LE LIRE.
                    `EvenementSOS.declenchePar` pointe `Support` (le badge), qui n'expose rien dans
                    le groupe `sos:read` : le champ revient en IRI nue et la mention ne s'affichait
                    jamais. Elle disparaissait en silence — indiscernable d'une alerte déclenchée
                    sans badge, par un bouton mural par exemple.
                    Sur un appel d'urgence, « un badge a déclenché mais je ne peux pas le nommer »
                    et « aucun badge » ne mènent pas au même endroit : le premier identifie une
                    personne, le second non. On distingue les deux. */}
                {e.declenchePar && (
                  <span className="sub">
                    {typeof e.declenchePar === 'object' && e.declenchePar.identifiantSupport
                      ? `support ${e.declenchePar.identifiantSupport}`
                      : 'déclenché par un badge — identifiant non transmis'}
                  </span>
                )}
                {peutTraiter && (
                  <button
                    className="btn primary sm"
                    type="button"
                    style={{ marginLeft: 'auto' }}
                    disabled={busy}
                    onClick={() => traiter(e)}
                  >
                    Marquer traité
                  </button>
                )}
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="card" style={{ marginBottom: 14 }}>
        <div className="card-h">
          <span>Présences isolées détectées</span>
          {peutTraiter && (
            <button
              className="btn ghost sm"
              type="button"
              style={{ marginLeft: 'auto' }}
              disabled={detection}
              title="Interroge chaque espace maintenant, sans attendre le prochain passage automatique."
              onClick={detecterMaintenant}
            >
              {detection ? 'Détection…' : 'Détecter maintenant'}
            </button>
          )}
        </div>
        {compteRendu && (
          <div className={compteRendu.grave ? 'banner banner-warn' : 'banner'} style={{ margin: 'var(--esp-large)' }}>
            {compteRendu.texte}
          </div>
        )}
        {alertes === null ? (
          <div className="banner banner-error" style={{ margin: 'var(--esp-large)' }}>
            Les présences isolées n’ont pas pu être lues. Il y en a peut-être une en cours&nbsp;:
            cet écran ne le sait pas.
          </div>
        ) : alertes.length === 0 ? (
          <div className="empty">
            Aucune présence isolée signalée.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Espace</th>
                  <th className="num">Personnes</th>
                  <th className="num">Détectée</th>
                </tr>
              </thead>
              <tbody>
                {alertes.map((a) => (
                  <tr key={a.id}>
                    <td>{nomEspace(resoudre(a.espaceAcces, espaces)) || '—'}</td>
                    <td className="num">{a.nbPersonnesDetectees}</td>
                    <td className="num">{quandHeure(a.horodatage)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {/* ── LES DEMANDES DE RESILIATION QUI ATTENDENT UN RESPONSABLE ───────────────────────────
          ⚠ CETTE LISTE N'EXISTAIT PAS, ET LA FICHE ABONNEMENT PROMETTAIT POURTANT LE GESTE.

          Pendant l'engagement, une demande motivee (demenagement, perte d'emploi, raison medicale)
          reste `refusee` en attendant qu'un responsable valide le motif. La fiche l'annonce mot pour
          mot. Or la route de validation n'etait appelee par AUCUN ecran : la demande dormait, et
          pendant ce temps l'adherent restait actif ET PRELEVE. C'est ce dernier point qui fait de ce
          bloc une liste de travail et pas une consultation.

          ⚠ ELLE S'AFFICHE MEME VIDE. Un bloc absent ne se distingue pas d'un bloc qu'on a oublie de
          charger — le principe est deja pose plus haut sur cet ecran, il vaut ici aussi. */}
      <SectionMotifsLegitimes
        resiliations={resiliations}
        abonnements={abonnements}
        beneficiaires={beneficiaires}
        peutGerer={peutGererAbonnement}
        busy={busy}
        onValider={validerMotif}
      />

      <section className="card">
        <div className="card-h">
          <span>Abonnements</span>
          <span className="sub" style={{ marginLeft: 8 }}>{abonnements === null ? '—' : abonnements.length}</span>
          {peutGererAbonnement && (
            <div className="actions" style={{ marginLeft: 'auto' }}>
              <button className="btn sm" type="button" onClick={() => majParams({ souscrire: '1' }, { pousser: true })}>
                ＋ Souscrire un abonnement
              </button>
            </div>
          )}
        </div>
        {abonnements === null ? (
          <div className="empty">
            La liste des abonnements n’a pas pu être lue.
          </div>
        ) : abonnements.length === 0 ? (
          <div className="empty">Aucun abonnement fitness.</div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Adhérent</th>
                  <th>Statut</th>
                  <th className="num">Début</th>
                  <th className="num">Échéance</th>
                </tr>
              </thead>
              <tbody>
                {abonnements.map((a) => {
                  /* ⚠ `dateFinEngagement`, PAS `dateFin`. L'écran lisait `a.dateFin` — un nom que
                     l'API n'envoie jamais — donc la colonne affichait « sans terme » sur TOUS les
                     abonnements depuis toujours. Un engagement de trois ans et un abonnement sans
                     terme se lisaient à l'identique. */
                  const terme = etatDuTerme(a.dateFinEngagement)
                  return (
                    <tr key={a.id}>
                      <td>
                        {/* Le nom ouvre la fiche : c'est la colonne que l'oeil vise, et un bouton
                            « Voir » de plus ferait une colonne pour un geste que la ligne porte
                            deja. Meme patron que le catalogue. */}
                        <button
                          type="button"
                          className="lnk"
                          onClick={() => setAbonnementOuvert(a)}
                          title="Ouvrir la fiche de l’abonnement"
                        >
                          {nomAdherent(a, beneficiaires)}
                        </button>
                      </td>
                      <td><span className={`badge ${tonStatut(a.statut)}`}>{a.statut || '—'}</span></td>
                      <td className="num">
                        {a.dateDebutEngagement ? quandHeure(a.dateDebutEngagement) : '—'}
                      </td>
                      <td className="num">
                        {a.dateFinEngagement ? quandHeure(a.dateFinEngagement) : '—'}
                        {terme && (
                          <>
                            {' '}
                            <span className={`badge ${terme.classe}`}>{terme.texte}</span>
                          </>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        {abonnementOuvert && (
          <FicheAbonnement
            abonnement={abonnementOuvert}
            nomAdherent={nomAdherent(abonnementOuvert, beneficiaires)}
            nomPayeur={nomPayeur(abonnementOuvert)}
            onFerme={() => setAbonnementOuvert(null)}
            onModifie={recharger}
          />
        )}

        <div className="hint">
          Le réengagement d&rsquo;un abonnement résilié exige un mandat SEPA neuf : il passe par la
          souscription, pas par la fiche.
        </div>

      </section>

      <Echeancier
        echeances={echeances}
        abonnements={abonnements}
        beneficiaires={beneficiaires}
        peutGerer={peutGererAbonnement}
        onAnnuler={setAnnulation}
        peutPiloterRecouvrement={peutPiloterRecouvrement}
        onRejet={(e) => majParams({ rejet: String(e.id) }, { pousser: true })}
      />


      <AnnulationEcheanceModal
        echeance={annulation}
        onClose={() => setAnnulation(null)}
        onFait={() => { setAnnulation(null); recharger() }}
      />

      {/* « 2 APPELS DÉJÀ TRAITÉS » N'EST PAS UN REGISTRE, C'EST UN COMPTEUR.
          `EvenementSOS` porte `traitePar` et `dateTraitement` — QUI est intervenu et QUAND — et
          l'écran n'affichait ni l'un ni l'autre : juste un nombre. Or c'est exactement ce qu'on
          vient chercher après coup, pour un rapport d'incident ou quand quelqu'un demande si on
          est venu. Un compteur dit qu'une alerte a été fermée ; il ne dit pas que quelqu'un y est
          allé.
          Le manque a été relevé par la session qui tient le back, en recoupant ma liste de
          relations muettes avec son propre outil — je ne l'avais pas vu. */}
      {traites.length > 0 && (
        <section className="card" style={{ marginTop: 16 }}>
          <div className="card-h">
            <h3>Appels d&rsquo;urgence traités</h3>
            <span className="sub">qui est intervenu, et quand</span>
          </div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Espace</th>
                  <th>Déclenché</th>
                  <th>Traité par</th>
                  <th>Traité</th>
                </tr>
              </thead>
              <tbody>
                {traites.map((e) => (
                  <tr key={e.id}>
                    <td><span className="nm">{nomEspace(resoudre(e.espaceAcces, espaces)) || '—'}</span></td>
                    <td>{quandHeure(e.horodatage)}</td>
                    {/* Même distinction que partout ailleurs : « personne » et « je ne sais pas
                        lire le nom » ne sont pas la même réponse — surtout ici, où la question
                        est de savoir si quelqu'un y est allé. */}
                    <td>{nomOuAbsence(e.traitePar, 'non renseigné')}</td>
                    <td>{e.dateTraitement ? quandHeure(e.dateTraitement) : <span className="sub">—</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  )
}

// LES MOTIFS LEGITIMES EN ATTENTE — CE QUI SEPARE UNE DEMANDE D'UN CONTRAT ROMPU.
//
// Une demande en attente n'est pas un dossier a classer : tant qu'elle attend, l'abonnement reste
// actif et le prelevement continue. Le bandeau porte donc le NOMBRE et l'ANCIENNETE de la plus
// vieille — le fait qu'on ne peut pas voir en lisant ligne a ligne, exactement comme sur
// l'echeancier juste en dessous.
function SectionMotifsLegitimes({ resiliations, abonnements, beneficiaires, peutGerer, busy, onValider }) {
  // ⚠ LE TRI EST FAIT ICI PARCE QUE LE SERVEUR N'OFFRE AUCUN FILTRE SUR CETTE COLLECTION, ET ON LE
  // DIT PLUS BAS. Le cloisonnement, lui, reste serveur : on ne trie pas ce qu'on n'a pas le droit
  // de voir, on trie ce que le serveur a deja restreint a l'etablissement actif.
  //
  // « en attente » = motif legitime declare + statut encore `refusee`. Une demande sans motif
  // legitime est refusee DEFINITIVEMENT : elle n'attend personne, et l'inclure ici ferait chercher
  // une decision a prendre la ou il n'y en a pas.
  const enAttente = Array.isArray(resiliations)
    ? resiliations.filter((r) => r?.motifLegitime === true && r?.statut === 'refusee')
    : resiliations

  const plusAncienne = Array.isArray(enAttente) && enAttente.length > 0
    ? enAttente.reduce((a, b) => (String(a.dateDemande || '') <= String(b.dateDemande || '') ? a : b))
    : null

  return (
    <section className="card" style={{ marginBottom: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <span>Résiliations en attente de validation</span>
        <span className="sub" style={{ marginLeft: 'var(--esp-normal)' }}>
          {Array.isArray(enAttente) ? enAttente.length : '—'}
        </span>
      </div>

      {enAttente === null ? (
        <div className="empty">
          <b>Les demandes de résiliation n’ont pas pu être lues.</b> Cette liste est vide parce
          qu’on n’a pas pu regarder, pas parce que personne n’attend.
        </div>
      ) : enAttente.length === 0 ? (
        <div className="empty">Aucune demande n’attend de validation.</div>
      ) : (
        <>
          <div className="card-b">
            <div className="banner banner-warn" style={{ margin: 0 }}>
              <b>
                {enAttente.length} demande{enAttente.length > 1 ? 's' : ''} attend
                {enAttente.length > 1 ? 'ent' : ''} un responsable
              </b>
              {plusAncienne ? `, la plus ancienne depuis le ${dateFr(plusAncienne.dateDemande)}` : ''}.
              {' '}Tant qu’elles attendent, ces abonnements <b>restent actifs et prélevés</b>.
            </div>
          </div>
          <div className="card-b" style={{ overflowX: 'auto', paddingTop: 0 }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Adhérent</th>
                  <th>Demandé le</th>
                  <th>Motif</th>
                  <th>Justificatif</th>
                  <th>Effet si validé</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {enAttente.map((r) => {
                  const abo = resoudre(r.abonnement, abonnements)
                  return (
                    <tr key={r.id}>
                      <td>{nomAdherent(abo, beneficiaires)}</td>
                      <td>{dateFr(r.dateDemande)}</td>
                      <td>{r.motif || <span className="sub">—</span>}</td>
                      {/* Le serveur ne rend qu'un CHEMIN, et aucune route ne le sert : dire « fourni »
                          est tout ce qu'on peut honnêtement affirmer. Afficher le chemin laisserait
                          croire qu'on peut l'ouvrir. */}
                      <td>
                        {r.justificatifChemin
                          ? <span className="badge good">fourni</span>
                          : <span className="badge mut">aucun</span>}
                      </td>
                      <td>
                        {dateFr(r.dateEffet)}
                        <span className="sub"> · préavis {r.preavisAppliqueJours ?? '—'} j</span>
                      </td>
                      {peutGerer && (
                        <td style={{ whiteSpace: 'nowrap' }}>
                          <button
                            className="btn sm"
                            type="button"
                            disabled={busy}
                            onClick={() => onValider(r)}
                          >
                            Valider le motif
                          </button>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div className="card-b" style={{ paddingTop: 0 }}>
            <p className="hint" style={{ margin: 0 }}>
              Valider ne fait pas qu’accepter&nbsp;: <b>le préavis commence</b>. À la date d’effet,
              l’accès est coupé, le mandat SEPA est révoqué — sauf si un autre abonnement s’en sert —
              et les échéances postérieures sont annulées. Les deux cents demandes les plus récentes
              sont examinées&nbsp;: cette collection n’offre aucun filtre côté serveur.
            </p>
          </div>
        </>
      )}
    </section>
  )
}

// L'ECHEANCIER — LA VUE QUI MANQUAIT, ET SANS LAQUELLE `annulee` N'ATTEIGNAIT PERSONNE.
//
// L'etat « annulee » et son operation d'API ont ete poses par une autre session, qui a refuse
// l'ecran en le disant : « il n'y a pas un bouton a ajouter, il y a une vue a construire ». Sa
// fiche donne la mesure du manque — 38 echeances d'essai annulees par un script appelant l'API une
// par une, faute d'ecran. Un exploitant n'a pas ce recours : chez lui, une echeance abandonnee
// reste « a venir » indefiniment, en se presentant comme due.
//
// ⚠ LE BANDEAU EST LA MOITIE UTILE DE CET ECRAN. Une liste seule montrerait exactement ce qui a
// laisse passer ces 38 echeances : des lignes « a venir » d'apparence normale. Le bandeau compte
// celles dont la date est passee et nomme la plus ancienne — c'est le fait qu'on ne peut pas voir
// en lisant ligne a ligne.
function Echeancier({
  echeances, abonnements, beneficiaires, peutGerer, onAnnuler,
  peutPiloterRecouvrement = false, onRejet,
}) {
  // ⚠ COMPARAISON PAR JOUR LOCAL, PAS PAR INSTANT. Le garde-fou n°31 documente la famille :
  // une date envoyee a minuit UTC tombe du mauvais cote d'un seuil calcule autrement, et le
  // defaut ne se voit que quelques heures par jour — donc jamais en relecture. `jourLocal()` rend
  // `AAAA-MM-JJ` en heure locale ; deux de ces chaines se comparent directement.
  const aujourdhui = jourLocal()

  const enRetard = (echeances || []).filter(
    (e) => e.statut === 'a_venir' && e.dateProgrammee && jourLocal(e.dateProgrammee) < aujourdhui,
  )
  const plusAncienne = enRetard.reduce(
    (min, e) => (min === null || e.dateProgrammee < min ? e.dateProgrammee : min),
    null,
  )

  // ⚠ L'ECHEANCIER ARRIVAIT DANS L'ORDRE DES UUID — 25 lignes de septembre 2025 a septembre 2026
  // entremelees. Mesure du 07/09 contre l'API de preprod, pas une impression.
  //
  // La ressource porte desormais `order: ['dateProgrammee' => 'ASC']`, donc cette liste arrive
  // triee. On la trie QUAND MEME ici, et ce n'est pas de la redondance : un ecran qui depend d'un
  // ordre qu'il ne controle pas se recasse en silence le jour ou la ressource change, sans qu'une
  // seule ligne de ce fichier bouge. Le tri est un invariant de l'affichage, on l'ecrit ici aussi.
  const rangees = [...(echeances || [])].sort(
    (x, y) => (x.dateProgrammee || '').localeCompare(y.dateProgrammee || ''),
  )

  // Le talon `{ id }` se recoupe avec les abonnements deja charges par la page. Si CETTE liste-la
  // n'a pas pu etre lue, on ne remplace pas le nom par un tiret muet : on le dit.
  function adherent(echeance) {
    // Deux sauts : l'échéance porte un talon d'abonnement, l'abonnement porte une IRI d'adhérent.
    // Si la première liste manque, on le dit — c'est elle qui manque, pas l'adhérent.
    if (abonnements === null) {
      return <span className="sub">nom non lu — la liste des abonnements n’a pas été obtenue</span>
    }
    return nomAdherent(resoudre(echeance.abonnement, abonnements), beneficiaires)
  }

  return (
    <section className="card">
      <div className="card-h">
        <span>Échéancier des prélèvements</span>
        {/* Pas de marge en ligne : `.card-h` porte déjà `gap: 10px`. */}
        <span className="sub">
          {echeances === null ? '—' : echeances.length}
        </span>
      </div>

      {echeances === null ? (
        <div className="banner banner-error">
          L’échéancier n’a pas pu être lu. <b>N’en concluez pas qu’aucune échéance n’est
          programmée</b>&nbsp;: cette liste n’a pas été obtenue.
        </div>
      ) : echeances.length === 0 ? (
        <div className="empty">
          Aucune échéance. Un échéancier naît d’une souscription&nbsp;: tant qu’aucun abonnement
          n’est signé, il n’y a rien à prélever.
        </div>
      ) : (
        <>
          {enRetard.length > 0 && (
            <div className="banner banner-warn">
              <b>{enRetard.length} échéance{enRetard.length > 1 ? 's' : ''} « à venir » dont la date
              est passée</b>, la plus ancienne du {dateFr(plusAncienne)}. Elles se présentent comme
              dues et ne partiront pas&nbsp;: une remise écarte ce qui n’a pas de préavis, et
              désormais aussi ce dont le mandat est révoqué. Si l’abonnement correspondant est
              terminé, annulez-les avec leur motif — sinon elles resteront là indéfiniment.
            </div>
          )}
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Adhérent</th>
                  <th>Programmée le</th>
                  <th className="num">Montant</th>
                  <th>Statut</th>
                  <th>Ce qui s’est passé</th>
                  {(peutGerer || peutPiloterRecouvrement) && <th aria-label="Actions" />}
                </tr>
              </thead>
              <tbody>
                {rangees.map((e) => {
                  const etat = etatEcheance(e.statut)
                  return (
                    <tr key={e.id}>
                      <td>{adherent(e)}</td>
                      <td>{dateFr(e.dateProgrammee)}</td>
                      <td className="num">{euroCentimes(e.montantCentimes)}</td>
                      <td><span className={`badge ${etat.classe}`}>{etat.mot}</span></td>
                      <td>
                        {/* ⚠ C'EST ICI QUE « EN PAUSE » ET « ANNULEE » SE SEPARENT POUR DE BON.
                            Le badge donne la couleur ; cette colonne donne la suite. Une pause
                            reviendra, une annulation non — et une annulation sans son motif est un
                            trou : la seule question posee plus tard sera « pourquoi ». */}
                        {e.statut === 'annulee' ? (
                          <>
                            <span className="nm">{e.cancellationReason || 'motif non transmis'}</span>
                            {e.cancelledAt && (
                              <div className="sub">annulée le {dateFr(e.cancelledAt)}</div>
                            )}
                          </>
                        ) : e.statut === 'gelee' ? (
                          <span className="sub">en pause — elle reviendra à la reprise</span>
                        ) : e.statut === 'prelevee' ? (
                          <span className="sub">
                            {e.dateExecutionReelle
                              ? `présentée le ${dateFr(e.dateExecutionReelle)}`
                              : 'présentée à la banque'}
                          </span>
                        ) : e.statut === 'rejetee' ? (
                          <span className="sub">rejetée par la banque — voir Recouvrement</span>
                        ) : (
                          <span className="sub">—</span>
                        )}
                      </td>
                      {(peutGerer || peutPiloterRecouvrement) && (
                        <td>
                          {/* ⚠ LE REJET NE S'AFFICHE QUE SUR UNE ÉCHÉANCE PRÉLEVÉE. Le processeur,
                              lui, ne refuse aucun état : il passe l'échéance en « rejetée » quoi
                              qu'il arrive. Proposer le geste sur une échéance « à venir »
                              inviterait à déclarer le rejet d'un prélèvement qui n'est jamais
                              parti, et l'impayé qui en naîtrait serait parfaitement imaginaire.
                              Même règle que le bouton d'à côté, pour une raison différente : lui
                              serait refusé par le serveur, celui-ci serait accepté à tort. */}
                          {peutPiloterRecouvrement && e.statut === 'prelevee' && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              onClick={() => onRejet(e)}
                              title="La banque a retourné ce prélèvement impayé."
                            >
                              Rejet bancaire
                            </button>
                          )}
                          {/* Le serveur refuse (422) toute échéance qui n'est pas « à venir », en
                              nommant son état. On n'affiche donc pas un bouton qui serait refusé :
                              un contrôle proposé puis refusé apprend au lecteur à s'en méfier. */}
                          {peutGerer && e.statut === 'a_venir' && (
                            <button className="btn danger sm" type="button" onClick={() => onAnnuler(e)}>
                              Annuler
                            </button>
                          )}
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
      <div className="hint">
        Annuler une échéance l’abandonne définitivement&nbsp;: elle ne sera jamais collectée et ne
        se remet pas « à venir ». C’est différent d’une pause, qui la rend à la reprise. Seule une
        échéance « à venir » s’annule — une échéance prélevée correspond à un mouvement bancaire
        réel, et une rejetée a ouvert un incident d’impayé.
      </div>
    </section>
  )
}

// ANNULER UNE ECHEANCE — ET LE MOTIF N'EST PAS UN CHAMP DE POLITESSE.
//
// Le serveur rend 422 sur un motif blanc, et il a raison : une echeance annulee est une somme que
// le club n'encaissera jamais. La modale l'exige donc aussi, plutot que de laisser partir un appel
// qui reviendra en erreur — et elle dit POURQUOI, sinon l'obligation passe pour une tracasserie.
function AnnulationEcheanceModal({ echeance, onClose, onFait }) {
  const [motif, setMotif] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (echeance) { setMotif(''); setErreur(null) }
  }, [echeance])

  async function envoyer(e) {
    e.preventDefault()
    setEnvoi(true)
    setErreur(null)
    try {
      await api.annulerEcheanceSepa(echeance.id, motif.trim())
      onFait()
    } catch (err) {
      setErreur(err.message || "L’échéance n’a pas pu être annulée.")
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={Boolean(echeance)} onClose={onClose} titre="Annuler une échéance">
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <p className="sub">
          Échéance du {dateFr(echeance?.dateProgrammee)} pour {euroCentimes(echeance?.montantCentimes)}.
          Elle ne sera jamais collectée, et elle ne se remet pas « à venir ».
        </p>
        <div className="field">
          <label htmlFor="motif-annulation">Pourquoi cette échéance ne sera-t-elle pas encaissée ? *</label>
          <textarea
            id="motif-annulation"
            className="input"
            rows={3}
            required
            value={motif}
            placeholder="Adhérent résilié, échéancier refait…"
            onChange={(ev) => setMotif(ev.target.value)}
          />
          <div className="hint">
            Obligatoire. Dans six mois, la seule question posée sur cette ligne sera
            «&nbsp;pourquoi n’a-t-elle pas été encaissée&nbsp;?&nbsp;», et un état sans motif y
            répond «&nbsp;on ne sait pas&nbsp;».
          </div>
        </div>
        {/* Pas de marge en ligne : le `.field` au-dessus porte déjà `margin-bottom: 14px`. */}
        <div className="row actions">
          <button className="btn" type="button" onClick={onClose}>Fermer</button>
          <button className="btn danger" type="submit" disabled={envoi || motif.trim() === ''}>
            {envoi ? 'Annulation…' : 'Annuler l’échéance'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// SOUSCRIRE UN ABONNEMENT — le premier pas d'une chaîne dont nous avions bâti tout l'aval.
//
//     souscription → échéance → prélèvement SEPA → rejet → impayé
//                  → représentation → blocage d'accès → recouvrement
//
// L'écran Recouvrement explique très bien qu'« un impayé s'ouvre à partir d'une échéance
// d'abonnement rejetée ». C'est vrai, et le premier maillon n'existait dans aucun écran : deux
// endroits du serveur instancient un abonnement, tous deux hors de portée du frontal. Un
// exploitant de salle ne pouvait pas inscrire un adhérent — la seule chose que son métier fait
// tous les jours, et la seule qui produise du revenu récurrent.
//
// ⚠ UNE FORMULE N'A PAS DE ROUTE À ELLE : elle est portée par un produit (`Produit::$formule`).
// On choisit donc le PRODUIT, et on envoie l'identifiant de sa formule. Un produit sans formule
// n'est pas un abonnement et n'a rien à faire dans cette liste.
//
// ⚠ ET SOUSCRIRE N'OUVRE PAS LE TOURNIQUET. `Formule::$droitAcces` est une CONFIGURATION
// (`{ mode: 'illimite' }`), pas un droit. Le vrai `DroitAcces` naît de l'appairage d'un support
// physique, et `POST /sport/abonnements/{id}/rattacher-droit-acces` le relie à l'abonnement. Tant
// que ce rattachement n'a pas eu lieu, l'adhérent paie et la porte refuse. La modale le dit — ce
// serait le symétrique exact du blocage pour impayé qu'on vient de démêler, en pire : celui-là
// frapperait quelqu'un qui est en règle.
// ⚠ CE N'EST PLUS UNE MODALE — le nom est historique. La garde `if (!open) return null`
// remplace celle que `Modal` portait : sans elle, le formulaire s'afficherait sous la page.
function SouscriptionModal({ open, onClose, onFait }) {
  const [beneficiaires, setBeneficiaires] = useState([])
  const [clients, setClients] = useState([])
  const [produits, setProduits] = useState([])
  const [adherent, setAdherent] = useState('')
  const [payeur, setPayeur] = useState('')
  // ⚠ UNE SUGGESTION NE DOIT PAS ÉCRASER UN CHOIX. Dès que quelqu'un désigne le payeur
  //   lui-même, on ne suggère plus — jusqu'au prochain changement d'adhérent, qui repart de
  //   zéro parce que c'est le début d'une autre souscription.
  const [payeurManuel, setPayeurManuel] = useState(false)
  const [produit, setProduit] = useState('')
  const [duree, setDuree] = useState('12')
  // ⚠ PLUS DE MONTANT SAISI. Arbitrage de Maxime du 01/09 : « il ne doit pas y avoir de prix
  //    libre. » Le serveur résout désormais depuis la grille tarifaire et REFUSE le champ s'il est
  //    envoyé — l'écran doit donc cesser de le poser, sinon chaque souscription rendrait 422.
  const [iban, setIban] = useState('')
  const [titulaire, setTitulaire] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return
    setAdherent(''); setPayeur(''); setPayeurManuel(false); setProduit('')
    setDuree('12'); setIban(''); setTitulaire(''); setErreur(null)
    Promise.allSettled([api.beneficiaires(), api.rechercheClients({ itemsPerPage: 100 }), api.produits()])
      .then(([b, c, p]) => {
        setBeneficiaires(b.status === 'fulfilled' ? membres(b.value) : [])
        setClients(c.status === 'fulfilled' ? (c.value?.items || membres(c.value)) : [])
        setProduits(p.status === 'fulfilled' ? membres(p.value) : [])
      })
  }, [open])

  // LE CLIENT RATTACHÉ À L'ADHÉRENT — c'est lui qu'on suggère comme payeur.
  //
  // ⚠ `b.client`, PAS `b.prenom`/`b.nom`. `GET /api/beneficiaires` n'expose ni l'un ni l'autre au
  //   premier niveau : mesuré, 0 sur 11. Le sélecteur d'adhérent lisait ces deux champs
  //   inexistants et retombait sur `|| b.id` — on choisissait dans une liste d'UUID. Le bon
  //   idiome est vingt lignes plus haut, dans `nomAdherent()`.
  const benefChoisi = beneficiaires.find((b) => b.id === adherent) || null
  const clientAdherent = benefChoisi?.client || null

  useEffect(() => {
    if (payeurManuel || !adherent) return
    const b = beneficiaires.find((x) => x.id === adherent)
    const c = b?.client
    if (!c?.id) return
    setPayeur(c.id)
    // Le titulaire du mandat n'est écrasé que s'il est vide : une saisie manuelle prime.
    setTitulaire((t) => (t.trim() ? t : (nomOuAbsence(c, '') || '')))
  }, [adherent, payeurManuel, beneficiaires])

  // ⚠ LE CLIENT SUGGÉRÉ PEUT MANQUER DE LA LISTE : `rechercheClients` est bornée à 100. Poser
  //   une valeur qu'aucune option ne porte afficherait un champ vide tout en l'ayant remplie.
  const optionsPayeur = clientAdherent?.id && !clients.some((c) => c.id === clientAdherent.id)
    ? [clientAdherent, ...clients]
    : clients

  // LA CADENCE VIENT DE LA FORMULE, PLUS DE CET ÉCRAN.
  //
  // ⚠ ELLE ÉTAIT SAISIE ICI ET LA FORMULE ÉTAIT IGNORÉE. `Formule::$periodicite` est éditable
  //   dans la fiche produit et n'était lue par personne : une formule déclarée ANNUELLE
  //   souscrite depuis cette modale devenait MENSUELLE, en silence. Le serveur refuse
  //   désormais le champ plutôt que de l'ignorer — l'envoyer rendrait 422.
  const CADENCES = { mensuel: 'Mensuelle', annuel: 'Annuelle', personnalise: 'Personnalisée' }

  const formules = produits.filter((p) => p.formule?.id)
  // Le tarif du produit choisi, tel que le catalogue le porte. On l'AFFICHE : c'est ce que le
  // serveur résoudra, et le montrer avant permet de s'apercevoir qu'il manque avant de valider.
  const produitChoisi = formules.find((p) => p.id === produit)
  const grilleTarif = (produitChoisi?.grilles || []).find((g) => g && g.prix !== null && g.prix !== undefined && g.prix !== '')
  const tarifAffiche = grilleTarif
    ? Number(grilleTarif.prix).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
    : null
  if (!open) return null

  const pret = adherent && payeur && produit
    // ⚠ `tarifAffiche` REMPLACE `centimes > 0` dans la garde : sans tarif au catalogue, le
    //    serveur refusera la souscription. Bloquer ici évite un aller-retour et une erreur
    //    technique là où la cause est un produit sans prix.
    && Number(duree) > 0 && tarifAffiche && iban.trim() && titulaire.trim()

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      const choisi = formules.find((p) => p.id === produit)
      await api.souscrireAbonnement({
        adherent,
        payeur,
        formule: choisi.formule.id,
        dureeEngagementMois: Number(duree),
        iban: iban.trim(),
        titulaireMandat: titulaire.trim(),
      })
      onFait()
    } catch (err) {
      setErreur(err.message || 'La souscription n’a pas abouti.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <>
      <button className="btn ghost sm" type="button" onClick={onClose} style={{ marginBottom: 'var(--esp-large)' }}>
        ← Retour aux abonnements
      </button>
      <h2>Souscrire un abonnement</h2>
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        {formules.length === 0 && (
          <div className="banner banner-warn">
            Aucun produit ne porte de formule d’abonnement. Une souscription s’appuie sur une
            formule : créez d’abord un produit de type abonnement dans <b>Catalogue</b>.
          </div>
        )}

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="ab-adherent">Adhérent *</label>
            <select id="ab-adherent" className="input" value={adherent} onChange={(e) => setAdherent(e.target.value)}>
              <option value="">— choisir —</option>
              {beneficiaires.map((b) => (
                <option key={b.id} value={b.id}>
                  {(b.client ? nomOuAbsence(b.client, '') : '') || `sans nom — ${b.id.slice(0, 8)}`}
                </option>
              ))}
            </select>
            <span className="hint">Celui qui vient s’entraîner.</span>
          </div>
          <div className="field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="ab-payeur">Payeur *</label>
            <select
              id="ab-payeur"
              className="input"
              value={payeur}
              onChange={(e) => { setPayeur(e.target.value); setPayeurManuel(true) }}
            >
              <option value="">— choisir —</option>
              {optionsPayeur.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.raisonSociale || [c.prenom, c.nom].filter(Boolean).join(' ') || c.id}
                </option>
              ))}
            </select>
            {/* Deux personnes différentes dans le cas courant : un parent règle pour son enfant.
                Les confondre ferait prélever le mineur.

                ⚠ ET C'EST POURQUOI LA SUGGESTION SE DIT. Pré-remplir en silence le champ qui
                décide de QUI EST PRÉLEVÉ échangerait une saisie oubliée contre un prélèvement
                sur la mauvaise personne — le même défaut, en moins visible. */}
            {!payeurManuel && payeur && clientAdherent?.id === payeur ? (
              <span className="hint">
                <b>Suggéré</b> : l’adhérent se paie lui-même. Changez-le si quelqu’un d’autre règle.
              </span>
            ) : benefChoisi && !clientAdherent ? (
              <span className="hint">
                Cet adhérent n’est rattaché à aucun client : rien à suggérer, choisissez qui sera prélevé.
              </span>
            ) : (
              <span className="hint">Celui qui sera prélevé — souvent le parent, pas l’adhérent.</span>
            )}
          </div>
        </div>

        <div className="field">
          <label htmlFor="ab-formule">Formule *</label>
          <select id="ab-formule" className="input" value={produit} onChange={(e) => setProduit(e.target.value)}>
            <option value="">— choisir —</option>
            {formules.map((p) => (
              <option key={p.id} value={p.id}>{libelleProduit(p)}{p.code ? ` (${p.code})` : ''}</option>
            ))}
          </select>
        </div>

        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 160px' }}>
            <label htmlFor="ab-periodicite">Périodicité</label>
            {/* ⚠ AFFICHÉE, PLUS SAISIE — même motif que le montant juste à côté, et pour la même
                raison : la laisser modifiable ferait croire qu'on fixe la cadence alors que le
                serveur applique celle de la formule. L'écart ne se verrait qu'au relevé bancaire. */}
            <input
              id="ab-periodicite"
              className="input"
              value={CADENCES[produitChoisi?.formule?.periodicite] || (produit ? '—' : '')}
              readOnly
              placeholder="choisissez un produit"
              aria-describedby="ab-periodicite-aide"
            />
            <span className="hint" id="ab-periodicite-aide">
              {produit && !produitChoisi?.formule?.periodicite
                ? 'Cette formule n’en déclare aucune : le serveur refusera. Renseignez-la dans la fiche produit.'
                : 'Déclarée par la formule. Se change dans la fiche produit.'}
            </span>
          </div>
          <div className="field" style={{ flex: '1 1 160px' }}>
            <label htmlFor="ab-duree">Engagement (mois) *</label>
            <input id="ab-duree" className="input" type="number" min="1" value={duree}
              onChange={(e) => setDuree(e.target.value)} />
          </div>
          <div className="field" style={{ flex: '1 1 160px' }}>
            <label htmlFor="ab-montant">Montant par échéance</label>
            {/* ⚠ AFFICHÉ, PLUS SAISI. Le laisser modifiable ferait croire qu'on fixe le prix
                alors que le serveur applique le tarif — un écart qui ne se verrait qu'au relevé
                bancaire. Sans tarif au catalogue, on le dit et on bloque : le serveur refuserait
                de toute façon, et une erreur technique n'aurait pas nommé la cause. */}
            <input id="ab-montant" className="input" value={tarifAffiche || ''} readOnly
              placeholder="—" aria-describedby="ab-montant-aide" />
            <span className="hint" id="ab-montant-aide">
              {!produit
                ? 'Choisissez une formule : son tarif s’affichera ici.'
                : tarifAffiche
                  ? 'Tarif du catalogue. C’est ce qui sera prélevé à chaque échéance.'
                  : 'Ce produit n’a aucun tarif au catalogue : la souscription sera refusée. Ajoutez un tarif sur sa fiche produit.'}
            </span>
          </div>
        </div>

        <div className="fiche-sec" style={{ marginTop: 'var(--esp-bloc)' }}>Mandat de prélèvement</div>
        <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 260px' }}>
            <label htmlFor="ab-iban">IBAN *</label>
            <input id="ab-iban" className="input" value={iban} autoComplete="off"
              onChange={(e) => setIban(e.target.value)} />
            {/* L'IBAN ne transite qu'ici : le serveur le tokenise avant de persister, et ne le
                remontera jamais en clair. Une erreur de saisie se corrige donc en signant un
                nouveau mandat, pas en relisant celui-ci. */}
            <span className="hint">
              Saisi une seule fois. Le serveur le chiffre immédiatement et ne le rendra plus jamais :
              une erreur se corrige en signant un nouveau mandat.
            </span>
          </div>
          <div className="field" style={{ flex: '1 1 260px' }}>
            <label htmlFor="ab-titulaire">Titulaire du compte *</label>
            <input id="ab-titulaire" className="input" value={titulaire}
              onChange={(e) => setTitulaire(e.target.value)} />
            <span className="hint">Le nom tel qu’il figure sur le compte bancaire du payeur.</span>
          </div>
        </div>

        <div className="banner banner-warn">
          <b>Souscrire n’ouvre pas encore la porte.</b> L’adhérent pourra être prélevé, mais le
          tourniquet le refusera tant qu’un badge ne lui aura pas été appairé et son droit d’accès
          rattaché à cet abonnement. Faites-le dans <b>Badges &amp; terminaux</b> juste après.
        </div>

        <div className="r" style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi || !pret}>
            {envoi ? 'Souscription…' : 'Souscrire'}
          </button>
        </div>
      </form>
    </>
  )
}


// Les codes retour SEPA que l'exploitant lit sur son releve. Ce sont des codes ISO 20022 : la
// banque en donne un, l'ecran le traduit — personne ne devrait avoir a retenir qu'AM04 veut dire
// « provision insuffisante ». La saisie libre reste possible pour les codes plus rares.
const CODES_RETOUR = [
  ['AM04', 'Provision insuffisante'],
  ['AC01', 'Compte inexistant'],
  ['AC04', 'Compte clôturé'],
  ['AC06', 'Compte bloqué'],
  ['AG01', 'Opération interdite sur ce compte'],
  ['MD01', 'Mandat inexistant ou non valide'],
  ['MD06', 'Remboursement demandé par le débiteur'],
  ['MS02', 'Refus du débiteur'],
  ['MS03', 'Motif non communiqué'],
  ['SL01', 'Filtrage demandé par le débiteur'],
]

/**
 * ENREGISTRER UN RETOUR BANQUE — et dire ce qui va REELLEMENT se passer.
 *
 * ⚠ Le moteur anti-impayes est pilote par `PolitiqueRecouvrement.momentRefusAcces`. La creation
 * d'un incident ne coupe RIEN par defaut : la politique par defaut est
 * `apres_representation_echouee`. Annoncer « l'acces sera coupe » serait donc faux dans le cas
 * courant, et « rien ne se passe » serait faux dans les autres.
 *
 * ⚠ DEUX CAS COUPENT DES L'ENREGISTREMENT, et c'est ce geste-ci qui les declenche :
 * `apres_1er_echec` (le rejet enregistre EST le premier echec) et toute politique a zero
 * representation (rien a attendre). Voir `MoteurRecouvrementHandler::detecterRejet()`.
 *
 * La fenetre LIT la politique de l'etablissement et nomme la consequence. Quand elle n'a pas pu la
 * lire, elle le dit — plutot que d'afficher la version rassurante par defaut.
 */
// ⚠ UNE LISTE VIDE N'EST PAS UNE LECTURE ÉCHOUÉE, ET « AUCUNE POLITIQUE » N'EST PAS « ON NE SAIT PAS ».
// Sans politique paramétrée, `MoteurRecouvrementHandler::politiquePour()` en construit une par
// défaut, dont l'entité fixe `momentRefusAcces` à `apres_representation_echouee`. L'écran affichait
// « n'a pas pu être lue » sur un 200 sans membre : c'était la valeur de l'échec.
const POLITIQUE_PAR_DEFAUT = Object.freeze({
  momentRefusAcces: 'apres_representation_echouee',
  nbRepresentationsMax: 1,
  calendrierRepresentationJours: [5],
  parDefaut: true,
})

// Les mots de l'écran de la politique (ImpayesRecouvrement) : les deux écrans nomment la même règle
// de la même façon. Le code brut ne s'affiche que s'il est inconnu ici.
const MOMENTS_REFUS = Object.freeze({
  apres_1er_echec: 'dès le premier échec',
  apres_representation_echouee: 'après une représentation échouée',
  apres_n_representations_echouees: 'après N représentations échouées',
})

const representationsEchouees = (n) => `${n} représentation${n > 1 ? 's' : ''} échouée${n > 1 ? 's' : ''}`

function libelleMomentRefus(politique) {
  const moment = politique.momentRefusAcces
  if (moment === 'apres_n_representations_echouees' && politique.nReprAvantBlocage != null) {
    return `après ${representationsEchouees(Math.max(politique.nReprAvantBlocage, 1))}`
  }
  return MOMENTS_REFUS[moment] || moment || 'non renseignée'
}

// ⚠ CETTE PHRASE RECOPIE `MoteurRecouvrementHandler` : `detecterRejet()` pour ce qui se passe à
// l'enregistrement, `enregistrerResultatRepresentation()` pour la suite. Une règle changée côté
// serveur la rend fausse sans rien casser d'autre.
//
// ⚠ ELLE COMPARAIT À `'immediat'`, QUI N'EST PAS UNE VALEUR DE `MomentRefusAcces`. La branche
// « refusé dès l'enregistrement » était morte, et `apres_1er_echec` — qui coupe bien à
// l'enregistrement — s'annonçait « pas refusé tout de suite ».
function consequenceRejet(politique) {
  const nbMax = politique.nbRepresentationsMax ?? 1
  const exemption = ' Un adhérent exempté de blocage garde son accès.'
  if (nbMax === 0) {
    return 'aucune représentation n’est prévue : l’impayé passe directement en recouvrement et '
      + 'l’accès de l’adhérent est refusé dès l’enregistrement.' + exemption
  }
  switch (politique.momentRefusAcces) {
    case 'apres_1er_echec':
      return 'ce rejet est le premier échec : l’accès de l’adhérent est refusé dès l’enregistrement.' + exemption
    case 'apres_representation_echouee': {
      const delai = politique.calendrierRepresentationJours?.[0] ?? 5
      return `l’accès n’est pas refusé tout de suite : le prélèvement est représenté ${delai} jour${delai > 1 ? 's' : ''} `
        + 'après la date du rejet, et l’accès est refusé si cette représentation échoue.' + exemption
    }
    case 'apres_n_representations_echouees': {
      const n = politique.nReprAvantBlocage
      // Le moteur compare au seuil sans plancher (`>=`) : un seuil nul coupe à la première représentation échouée.
      if (n == null) {
        return 'l’accès n’est pas refusé tout de suite, ni plus tard : la politique ne fixe aucun nombre de '
          + 'représentations avant blocage, et le recouvrement ne refusera pas l’accès de lui-même.'
      }
      if (n > nbMax) {
        return `l’accès n’est pas refusé tout de suite, ni plus tard : la politique attend ${representationsEchouees(n)} `
          + `mais n’en prévoit que ${nbMax}, et le recouvrement ne refusera pas l’accès de lui-même.`
      }
      return `l’accès n’est pas refusé tout de suite : il le sera après ${representationsEchouees(Math.max(n, 1))}.` + exemption
    }
    default:
      return 'cet écran ne connaît pas ce moment de refus : il ne peut pas dire quand l’accès sera refusé.'
  }
}

function RejetEcheanceModal({ echeance, onClose, onFait }) {
  const [code, setCode] = useState('AM04')
  const [codeLibre, setCodeLibre] = useState('')
  const [libelle, setLibelle] = useState('')
  const [dateRejet, setDateRejet] = useState('')
  const [politique, setPolitique] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!echeance) return
    setCode('AM04'); setCodeLibre(''); setLibelle(''); setDateRejet(''); setErreur(null)
    let annule = false
    setPolitique(null)
    api.politiquesRecouvrement()
      .then((r) => { if (!annule) setPolitique(membres(r)[0] ?? POLITIQUE_PAR_DEFAUT) })
      .catch(() => { if (!annule) setPolitique(undefined) })
    return () => { annule = true }
  }, [echeance])

  const codeFinal = code === 'autre' ? codeLibre.trim().toUpperCase() : code
  const pret = codeFinal !== ''

  async function envoyer(e) {
    e.preventDefault()
    setEnvoi(true)
    setErreur(null)
    try {
      const corps = { codeRetour: codeFinal }
      if (libelle.trim() !== '') corps.libelleRetour = libelle.trim()
      if (dateRejet !== '') corps.dateRejet = dateRejet
      await api.enregistrerRejetEcheance(echeance.id, corps)
      onFait()
    } catch (err) {
      setErreur(err.message || 'Le rejet n’a pas pu être enregistré.')
    } finally {
      setEnvoi(false)
    }
  }

  if (!echeance) return null

  return (
    <>
      <h2>Enregistrer un rejet bancaire</h2>
      <form onSubmit={envoyer} style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div className="banner banner-warn">
          <b>Ce geste n’est pas un essai.</b> L’échéance passe en « rejetée » et un impayé est ouvert
          au nom de l’adhérent. Ne l’enregistrez que si la banque a réellement retourné ce
          prélèvement.
        </div>

        <div className="sub">
          {politique === null && 'Lecture de la politique de recouvrement…'}
          {politique === undefined && (
            <>La politique de recouvrement n’a pas pu être lue : cet écran ne peut pas dire si
            l’accès de l’adhérent sera refusé. Ce n’est pas la même chose que « il ne le sera pas ».</>
          )}
          {politique && (
            <>Selon la politique en vigueur (refus d’accès <b>{libelleMomentRefus(politique)}</b>
            {politique.parDefaut ? ' — aucune n’est paramétrée ici, c’est le défaut du serveur' : ''}),
            {' ' + consequenceRejet(politique)}</>
          )}
        </div>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Code retour de la banque</span>
          <select className="select" value={code} onChange={(ev) => setCode(ev.target.value)}>
            {CODES_RETOUR.map(([v, l]) => <option key={v} value={v}>{v} — {l}</option>)}
            <option value="autre">Autre code…</option>
          </select>
        </label>

        {code === 'autre' && (
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Code — tel qu’il figure sur le relevé</span>
            <input
              className="input"
              value={codeLibre}
              onChange={(ev) => setCodeLibre(ev.target.value)}
              maxLength={8}
              placeholder="RR04"
            />
          </label>
        )}

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Libellé de la banque — facultatif</span>
          <input
            className="input"
            value={libelle}
            onChange={(ev) => setLibelle(ev.target.value)}
            maxLength={140}
            placeholder="tel que le relevé le formule"
          />
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Date du rejet — facultative</span>
          <input
            className="input"
            type="date"
            value={dateRejet}
            onChange={(ev) => setDateRejet(ev.target.value)}
          />
        </label>

        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onClose}>Annuler</button>
          <button className="btn danger" type="submit" disabled={envoi || !pret}>
            {envoi ? 'Enregistrement…' : 'Enregistrer le rejet'}
          </button>
        </div>
      </form>
    </>
  )
}
