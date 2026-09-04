import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { dateHeureFr } from '../components/Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'
import { idDe } from '../api/iri.js'

// RELANCE DES RECETTES — huit routes servies, aucun écran, et un module qui n'a jamais tourné.
//
// ── CE QUE LE SERVEUR SAIT FAIRE, ET QUE PERSONNE NE POUVAIT DÉCLENCHER ─────────────────────────
//
// `App\RevenueRecovery` ouvre un dossier quand une recette échappe — un paiement refusé, une
// réservation annulée dans la fenêtre gratuite, un no-show — puis programme des relances par
// courriel selon une politique. Tout est écrit : le moteur, les entités, le cloisonnement, la
// commande d'envoi, les tests.
//
// Il manquait la seule chose sans laquelle rien ne part : **un écran pour créer et ACTIVER une
// politique**. `RecoverySequence::$active` vaut `false` à la construction (RG-RR-02, un choix
// délibéré : aucune relance ne part sans un geste explicite), et `RecoveryEngine::handle()` sort
// sans rien faire s'il ne trouve pas de séquence active pour le couple (établissement,
// déclencheur). Le module était donc inerte par conception, en attente d'un geste que rien
// n'offrait.
//
// Mesuré le 05/09 en base de préprod, avec un témoin positif (24 lignes dans `acces_passage`, donc
// le comptage fonctionne) : `revenue_recovery_sequence`, `_case` et `_attempt` sont à zéro. Pas
// « peu utilisé » — jamais utilisé.
//
// ── CE QUE CET ÉCRAN NE PEUT PAS FAIRE PARTIR TOUT SEUL ─────────────────────────────────────────
//
// L'envoi est porté par la commande `revenue-recovery:attempts:send`, et cette commande n'est PAS
// dans le catalogue de l'ordonnanceur (`ScheduleCatalog`, 26 tâches déclarées le 05/09 — vérifié en
// extrayant les 27 noms de commande du même fichier, donc l'extraction voit bien ce qu'elle
// cherche). Activer une politique ouvrira donc des dossiers et programmera des tentatives, mais
// rien ne les enverra tant que la tâche ne tourne pas.
//
// ⚠ CE FAIT N'EST PAS ÉCRIT EN DUR DANS L'ÉCRAN, ET C'EST VOLONTAIRE. Une phrase qui décrit un
// défaut devient un mensonge le jour où on le corrige, et rien ne relierait les deux. L'écran
// COMPTE donc les tentatives échues restées « à envoyer » (`pending` dont l'échéance est passée) et
// n'alerte que sur ce constat. Le jour où la tâche sera ordonnancée, le compte retombera à zéro et
// l'avertissement disparaîtra de lui-même, sans que personne ait à se souvenir de cette ligne.
//
// ── LES TROIS PIÈGES DU MODÈLE QUE L'ÉCRAN REND VISIBLES ────────────────────────────────────────
//
// 1. QUATRE DÉCLENCHEURS SUR HUIT SONT MORTS. `RecoveryTriggerType` en déclare huit ; quatre
//    seulement sont émis et écoutés. Les quatre autres (`cart_abandoned`, `invoice_overdue`,
//    `quote_expired`, `customer_inactive`) n'existent QUE comme cas d'enum — aucun émetteur, aucune
//    déclaration de module (mesuré par recherche du nom d'événement dans tout `app/src`, témoin :
//    `booking.no_show` sort cinq fois, dont son émetteur `BasculerNoShowCommand`).
//
//    ⚠ ET LE DÉFAUT DE L'ENTITÉ EST L'UN DES QUATRE MORTS : `triggerType` vaut `CartAbandoned` à la
//    construction. Une politique créée sans toucher au déclencheur ne se serait jamais déclenchée,
//    et rien ne l'aurait dit. Le formulaire ne propose donc que les quatre vivants — une absence se
//    remarque et se réclame, un choix décoratif ne se remarque pas. Une politique déjà posée sur un
//    déclencheur mort reste AFFICHÉE, signalée : cacher une donnée existante serait pire.
//
// 2. LES ÉTAPES AU-DELÀ DU PLAFOND SONT IGNORÉES EN SILENCE. Le moteur programme
//    `min(nombre d'étapes, maxTentatives)` tentatives. Une politique à cinq étapes plafonnée à
//    trois en exécute trois, sans erreur et sans trace.
//
// 3. UNE POLITIQUE SANS ÉTAPE OUVRE DES DOSSIERS QUI NE FERONT JAMAIS RIEN. Zéro étape donne zéro
//    tentative : le dossier s'ouvre, reste « en cours » indéfiniment, et aucune relance n'est même
//    programmée. C'est l'état par défaut d'une politique fraîchement créée.
//
// ── ET UNE RÈGLE QUI N'EST PAS UN DÉFAUT, MAIS QUI SURPREND ─────────────────────────────────────
//
// Seules les relances d'impayé (`payment_failed`, `payment_incident_reopened`) partent sur une base
// contractuelle. Les autres sont soumises au CONSENTEMENT marketing du client : une tentative peut
// donc finir « ignorée » sans que rien ne soit cassé. Sans cette explication à l'écran, un
// exploitant qui lit « 0 envoyée, 4 ignorées » conclut à une panne.

const DECLENCHEURS = {
  payment_failed: 'Paiement refusé',
  payment_incident_reopened: 'Incident de paiement rouvert',
  booking_cancelled: 'Réservation annulée (dans la fenêtre gratuite)',
  booking_no_show: 'Client absent (no-show)',
  cart_abandoned: 'Panier abandonné',
  invoice_overdue: 'Facture en retard',
  quote_expired: 'Devis expiré',
  customer_inactive: 'Client inactif',
}

// Les quatre déclencheurs qu'un événement émet réellement aujourd'hui — l'autorité est
// `RevenueRecoveryEventSubscriber::getSubscribedEvents()` côté serveur, recoupée par la recherche
// des émetteurs. Mesure du 05/09 : si un émetteur est ajouté plus tard, ce déclencheur manquera
// ici, et un manque se réclame. L'inverse — proposer un déclencheur mort — ne se réclame pas : on
// croit avoir configuré quelque chose.
const DECLENCHEURS_VIVANTS = [
  'payment_failed',
  'payment_incident_reopened',
  'booking_cancelled',
  'booking_no_show',
]

// Base légale de l'envoi, par déclencheur (`RecoveryEngine::basisFor()`). Ce n'est pas un détail
// d'implémentation : elle décide si le courriel part ou non, donc l'exploitant doit la voir avant
// de compter sur une relance.
const SOUS_CONSENTEMENT = (code) => code !== 'payment_failed' && code !== 'payment_incident_reopened'

const STATUTS_DOSSIER = {
  active: ['En cours', 'warn'],
  resolved: ['Résolu', 'good'],
  stopped: ['Arrêté', 'mut'],
  exhausted: ['Épuisé', 'mut'],
}

const STATUTS_TENTATIVE = {
  pending: ['À envoyer', 'mut'],
  sent: ['Envoyée', 'good'],
  skipped: ['Ignorée', 'warn'],
  failed: ['Échouée', 'crit'],
  cancelled: ['Annulée', 'mut'],
}

// `skipReason` est un code serveur (`RecoveryEngine::SKIP_REASON_NO_CONSENT`). Traduit ici, et rendu
// tel quel s'il en apparaît un autre : afficher un code inconnu vaut mieux que n'afficher rien.
const MOTIFS_IGNORE = {
  skipped_no_consent: 'le client n’a pas consenti aux messages marketing',
}

function libelleDeclencheur(code) {
  return DECLENCHEURS[code] || code || '—'
}

function badge([libelle, classe]) {
  return <span className={`badge ${classe}`}>{libelle}</span>
}

function statutDossier(code) {
  return badge(STATUTS_DOSSIER[code] || [code || '—', 'mut'])
}

function statutTentative(code) {
  return badge(STATUTS_TENTATIVE[code] || [code || '—', 'mut'])
}

export default function RelanceRecettes({ etabActif, droits }) {
  const peutConfigurer = aLeDroit(droits, 'revenue_recovery.configure')
  const peutPiloter = aLeDroit(droits, 'revenue_recovery.manage')

  const [onglet, setOnglet] = useState('dossiers')
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [dossiers, setDossiers] = useState([])
  const [politiques, setPolitiques] = useState([])
  const [tentatives, setTentatives] = useState([])
  // Combien de tentatives le serveur dit détenir quand il en a rendu moins. Sans ça, le compteur
  // d'échues plus bas annoncerait un chiffre partiel avec l'aplomb d'un total.
  const [tentativesTronquees, setTentativesTronquees] = useState(false)

  const [filtreStatut, setFiltreStatut] = useState('active')
  const [dossierOuvert, setDossierOuvert] = useState(null)
  const [politiqueOuverte, setPolitiqueOuverte] = useState(null)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // Trois lectures indépendantes, mais un seul refus suffit à rendre l'écran faux : on ne
      // rattrape pas au coup par coup pour afficher deux listes sur trois. Un écran incomplet qui
      // ne se présente pas comme tel est pire qu'un écran en erreur.
      const [resDossiers, resPolitiques, resTentatives] = await Promise.all([
        api.dossiersRelance(),
        api.politiquesRelance(),
        api.tentativesRelance(),
      ])
      setDossiers(membres(resDossiers))
      setPolitiques(membres(resPolitiques))
      const recues = membres(resTentatives)
      setTentatives(recues)
      const total = resTentatives?.['hydra:totalItems'] ?? resTentatives?.totalItems
      setTentativesTronquees(typeof total === 'number' && total > recues.length)
    } catch (e) {
      // ⚠ ON NE RETOMBE PAS SUR DES LISTES VIDES. Un 403 rendu en « aucun dossier » ferait dire à
      // l'écran qu'il n'y a rien à relancer, ce qu'il n'a pas mesuré.
      setDossiers([])
      setPolitiques([])
      setTentatives([])
      setErreur(e?.message || 'Impossible de lire les relances.')
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  const tentativesParDossier = useMemo(() => {
    const par = {}
    for (const t of tentatives) {
      const cle = idDe(t.recoveryCase)
      if (!cle) continue
      ;(par[cle] = par[cle] || []).push(t)
    }
    for (const cle of Object.keys(par)) par[cle].sort((a, b) => a.stepIndex - b.stepIndex)
    return par
  }, [tentatives])

  // LE CONSTAT QUI REMPLACE L'AFFIRMATION « RIEN N'EST ORDONNANCÉ ».
  //
  // Une tentative dont l'échéance est passée et qui est toujours « à envoyer » n'a pas été traitée.
  // Peu importe pourquoi — tâche absente, tâche en panne, tâche arrêtée : le symptôme est le même
  // et il est vrai indépendamment de la cause qu'on lui suppose.
  const echues = useMemo(() => {
    const maintenant = Date.now()
    return tentatives.filter(
      (t) => t.status === 'pending' && t.scheduledAt && new Date(t.scheduledAt).getTime() < maintenant,
    ).length
  }, [tentatives])

  const politiquesActives = politiques.filter((p) => p.active).length

  const dossiersAffiches = useMemo(
    () => (filtreStatut === 'tous' ? dossiers : dossiers.filter((d) => d.status === filtreStatut)),
    [dossiers, filtreStatut],
  )

  async function arreter(dossier, motif) {
    setErreur(null)
    setSucces(null)
    try {
      await api.arreterDossierRelance(idDe(dossier), motif)
      setSucces('Dossier arrêté : les relances qui restaient ne partiront pas.')
      setDossierOuvert(null)
      await charger()
    } catch (e) {
      setErreur(e?.message || "L'arrêt du dossier a échoué.")
    }
  }

  async function enregistrerPolitique(valeurs, id) {
    setErreur(null)
    setSucces(null)
    try {
      if (id) await api.majPolitiqueRelance(id, valeurs)
      else await api.creerPolitiqueRelance(valeurs)
      setSucces(id ? 'Politique enregistrée.' : 'Politique créée.')
      setPolitiqueOuverte(null)
      await charger()
    } catch (e) {
      setErreur(e?.message || "L'enregistrement a échoué.")
    }
  }

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Relance des recettes</h1>
          <p>Les recettes qui échappent, et les relances qu&rsquo;on envoie pour les rattraper</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {!chargement && echues > 0 && (
        <div className="banner banner-warn">
          <strong>
            {echues} tentative{echues > 1 ? 's' : ''} {echues > 1 ? 'ont' : 'a'} dépassé son échéance
            sans partir.
          </strong>{' '}
          Les relances sont programmées ici, mais elles sont envoyées par une tâche planifiée
          (<code>revenue-recovery:attempts:send</code>). Tant qu&rsquo;elle ne tourne pas, les
          dossiers s&rsquo;ouvrent et les tentatives s&rsquo;accumulent sans qu&rsquo;aucun courriel
          ne parte.
          {tentativesTronquees && ' Le serveur en détient davantage que ce que cette page a lu : ce nombre est un minimum.'}
        </div>
      )}

      <Tabs
        onglets={[
          ['dossiers', 'Dossiers'],
          ['politiques', `Politiques${politiques.length ? ` (${politiques.length})` : ''}`],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
      ) : onglet === 'politiques' ? (
        <OngletPolitiques
          politiques={politiques}
          peutConfigurer={peutConfigurer}
          onEditer={setPolitiqueOuverte}
        />
      ) : (
        <OngletDossiers
          dossiers={dossiersAffiches}
          total={dossiers.length}
          filtre={filtreStatut}
          onFiltre={setFiltreStatut}
          tentativesParDossier={tentativesParDossier}
          politiquesActives={politiquesActives}
          politiquesTotal={politiques.length}
          onOuvrirPolitiques={() => setOnglet('politiques')}
          onOuvrir={setDossierOuvert}
        />
      )}

      {dossierOuvert && (
        <DetailDossier
          dossier={dossierOuvert}
          tentatives={tentativesParDossier[idDe(dossierOuvert)] || []}
          peutPiloter={peutPiloter}
          onFermer={() => setDossierOuvert(null)}
          onArreter={arreter}
        />
      )}

      {politiqueOuverte && (
        <EditionPolitique
          politique={politiqueOuverte === 'nouvelle' ? null : politiqueOuverte}
          dejaPosees={politiques.map((p) => p.triggerType)}
          onFermer={() => setPolitiqueOuverte(null)}
          onEnregistrer={enregistrerPolitique}
        />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ Dossiers (la file de travail) */

function OngletDossiers({
  dossiers,
  total,
  filtre,
  onFiltre,
  tentativesParDossier,
  politiquesActives,
  politiquesTotal,
  onOuvrirPolitiques,
  onOuvrir,
}) {
  return (
    <>
      {/* UN ÉCRAN VIDE DOIT DIRE POURQUOI IL EST VIDE.
          Sans politique active, aucun dossier ne peut s'ouvrir : « aucun dossier » ne décrit alors
          pas l'activité de l'établissement, il décrit une configuration absente. Les deux se
          ressemblent à l'écran et n'appellent pas du tout le même geste. */}
      {politiquesActives === 0 && (
        <div className="banner banner-info">
          {politiquesTotal === 0
            ? 'Aucune politique de relance n’est définie : aucun dossier ne peut s’ouvrir, quoi qu’il se passe en caisse ou en réservation.'
            : 'Aucune politique n’est active : les politiques existent mais aucune n’agit, donc aucun dossier ne s’ouvre.'}{' '}
          <button type="button" className="btn ghost" onClick={onOuvrirPolitiques}>
            Voir les politiques
          </button>
        </div>
      )}

      <div className="card">
        <div className="card-h">
          <h3>
            Dossiers{total > 0 ? ` — ${dossiers.length} sur ${total}` : ''}
          </h3>
          <select
            className="select"
            value={filtre}
            onChange={(e) => onFiltre(e.target.value)}
            aria-label="Filtrer par statut"
          >
            <option value="active">En cours</option>
            <option value="resolved">Résolus</option>
            <option value="stopped">Arrêtés</option>
            <option value="exhausted">Épuisés</option>
            <option value="tous">Tous</option>
          </select>
        </div>
        <div className="card-b">
          {dossiers.length === 0 ? (
            <p className="empty">
              {total === 0
                ? 'Aucun dossier de relance.'
                : 'Aucun dossier dans ce statut — d’autres existent sous un autre filtre.'}
            </p>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Déclencheur</th>
                    <th>Objet</th>
                    <th className="num">Montant en jeu</th>
                    <th>Ouvert le</th>
                    <th>Relances</th>
                    <th>Statut</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {dossiers.map((d) => {
                    const tent = tentativesParDossier[idDe(d)] || []
                    const envoyees = tent.filter((t) => t.status === 'sent').length
                    return (
                      <tr key={idDe(d)}>
                        <td>{libelleDeclencheur(d.triggerType)}</td>
                        <td>
                          <span className="mono">{d.subjectType || '—'}</span>
                          {d.subjectRef ? <div className="sub mono">{d.subjectRef}</div> : null}
                        </td>
                        <td className="num">
                          {/* `amountCents` est en CENTIMES ; `euros()` attend des euros. */}
                          {d.amountCents == null ? '—' : euros(d.amountCents / 100)}
                        </td>
                        <td>{dateHeureFr(d.openedAt)}</td>
                        <td>
                          {tent.length === 0 ? (
                            <span className="sub">aucune programmée</span>
                          ) : (
                            `${envoyees} / ${tent.length}`
                          )}
                        </td>
                        <td>{statutDossier(d.status)}</td>
                        <td className="num">
                          <button type="button" className="btn" onClick={() => onOuvrir(d)}>
                            Détail
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </>
  )
}

function DetailDossier({ dossier, tentatives, peutPiloter, onFermer, onArreter }) {
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)
  const arretable = dossier.status === 'active'

  async function valider() {
    setEnCours(true)
    await onArreter(dossier, motif.trim())
    setEnCours(false)
  }

  return (
    <Modal open onClose={onFermer} titre={libelleDeclencheur(dossier.triggerType)} taille="lg">
      <div className="deflist">
        <div><span>Statut</span><span>{statutDossier(dossier.status)}</span></div>
        <div><span>Objet</span><span className="mono">{dossier.subjectType} {dossier.subjectRef}</span></div>
        <div>
          <span>Montant en jeu</span>
          <span>{dossier.amountCents == null ? '—' : euros(dossier.amountCents / 100)}</span>
        </div>
        <div><span>Ouvert le</span><span>{dateHeureFr(dossier.openedAt)}</span></div>
        {dossier.resolvedAt && <div><span>Résolu le</span><span>{dateHeureFr(dossier.resolvedAt)}</span></div>}
        {dossier.stoppedAt && <div><span>Arrêté le</span><span>{dateHeureFr(dossier.stoppedAt)}</span></div>}
        {dossier.stopReason && <div><span>Motif de l’arrêt</span><span>{dossier.stopReason}</span></div>}
      </div>

      {SOUS_CONSENTEMENT(dossier.triggerType) && (
        <p className="hint">
          Ce déclencheur relève du consentement marketing : une relance n&rsquo;est envoyée
          qu&rsquo;aux clients qui l&rsquo;ont accepté. Une tentative « ignorée » n&rsquo;est donc
          pas une panne.
        </p>
      )}

      <h4>Relances</h4>
      {tentatives.length === 0 ? (
        <p className="empty">
          Aucune relance n&rsquo;a été programmée pour ce dossier — la politique n&rsquo;avait aucune
          étape au moment où il s&rsquo;est ouvert.
        </p>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Étape</th>
                <th>Prévue le</th>
                <th>Envoyée le</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              {tentatives.map((t) => (
                <tr key={idDe(t)}>
                  <td>{t.stepIndex + 1}</td>
                  <td>{dateHeureFr(t.scheduledAt)}</td>
                  <td>{t.sentAt ? dateHeureFr(t.sentAt) : '—'}</td>
                  <td>
                    {statutTentative(t.status)}
                    {t.skipReason && (
                      <div className="sub">{MOTIFS_IGNORE[t.skipReason] || t.skipReason}</div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {arretable && peutPiloter && (
        <>
          <h4>Arrêter les relances</h4>
          <p className="hint">
            Les relances encore à venir ne partiront pas. Le dossier reste consultable, avec le motif
            et le nom de qui l&rsquo;a arrêté.
          </p>
          <div className="field">
            <label htmlFor="rr-motif">Motif (facultatif)</label>
            <input
              id="rr-motif"
              className="input"
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              placeholder="Le client a réglé par un autre moyen"
            />
          </div>
          <div className="bar">
            <button type="button" className="btn" onClick={onFermer}>Fermer</button>
            <button type="button" className="btn primary" onClick={valider} disabled={enCours}>
              {enCours ? 'Arrêt…' : 'Arrêter les relances'}
            </button>
          </div>
        </>
      )}

      {arretable && !peutPiloter && (
        <p className="hint">
          Arrêter un dossier demande le droit <code>revenue_recovery.manage</code>.
        </p>
      )}

      {!arretable && (
        <div className="bar">
          <button type="button" className="btn" onClick={onFermer}>Fermer</button>
        </div>
      )}
    </Modal>
  )
}

/* ------------------------------------------------------------ Politiques (ce qui rend le module vivant) */

function OngletPolitiques({ politiques, peutConfigurer, onEditer }) {
  const posees = politiques.map((p) => p.triggerType)
  const restants = DECLENCHEURS_VIVANTS.filter((c) => !posees.includes(c))

  return (
    <div className="card">
      <div className="card-h">
        <h3>Politiques de relance</h3>
        {peutConfigurer && restants.length > 0 && (
          <button type="button" className="btn primary" onClick={() => onEditer('nouvelle')}>
            ＋ Nouvelle politique
          </button>
        )}
      </div>
      <div className="card-b">
        <p className="hint">
          Une politique par déclencheur. Elle décide si un dossier s&rsquo;ouvre, combien de relances
          partent et à quel rythme — <strong>et rien ne se déclenche tant qu&rsquo;elle n&rsquo;est
          pas active</strong>.
        </p>

        {politiques.length === 0 ? (
          <p className="empty">Aucune politique définie.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Déclencheur</th>
                  <th>État</th>
                  <th className="num">Relances max</th>
                  <th>Rythme</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {politiques.map((p) => {
                  const etapes = Array.isArray(p.steps) ? p.steps : []
                  const effectives = Math.min(etapes.length, Math.max(0, p.maxAttempts || 0))
                  const mort = !DECLENCHEURS_VIVANTS.includes(p.triggerType)
                  return (
                    <tr key={idDe(p)}>
                      <td>
                        {libelleDeclencheur(p.triggerType)}
                        {mort && (
                          <div className="sub">
                            ⚠ Rien n&rsquo;émet encore ce déclencheur : cette politique ne
                            s&rsquo;appliquera jamais.
                          </div>
                        )}
                      </td>
                      <td>
                        {p.active
                          ? <span className="badge good">Active</span>
                          : <span className="badge mut">Inactive</span>}
                      </td>
                      <td className="num">{p.maxAttempts}</td>
                      <td>
                        {etapes.length === 0 ? (
                          <span className="sub">
                            aucune étape — les dossiers s&rsquo;ouvriront sans qu&rsquo;aucune
                            relance ne soit programmée
                          </span>
                        ) : (
                          <>
                            {etapes.map((e, i) => `J+${e?.delayDays ?? 0}`).join(', ')}
                            {effectives < etapes.length && (
                              <div className="sub">
                                ⚠ Seules les {effectives} premières partiront : le plafond de
                                relances est inférieur au nombre d&rsquo;étapes.
                              </div>
                            )}
                          </>
                        )}
                      </td>
                      <td className="num">
                        <button
                          type="button"
                          className="btn"
                          onClick={() => onEditer(p)}
                          disabled={!peutConfigurer}
                        >
                          {peutConfigurer ? 'Modifier' : 'Lecture seule'}
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        {peutConfigurer && restants.length === 0 && politiques.length > 0 && (
          <p className="hint">
            Les quatre déclencheurs disponibles ont chacun leur politique. Les quatre autres du
            modèle (panier abandonné, facture en retard, devis expiré, client inactif) ne sont émis
            par rien aujourd&rsquo;hui : leur proposer une politique laisserait croire à un réglage
            qui n&rsquo;agirait pas.
          </p>
        )}
      </div>
    </div>
  )
}

function EditionPolitique({ politique, dejaPosees, onFermer, onEnregistrer }) {
  const creation = !politique
  const dispo = DECLENCHEURS_VIVANTS.filter((c) => !dejaPosees.includes(c))

  const [declencheur, setDeclencheur] = useState(politique?.triggerType || dispo[0] || '')
  const [actif, setActif] = useState(!!politique?.active)
  const [maxTentatives, setMaxTentatives] = useState(politique?.maxAttempts ?? 3)
  const [etapes, setEtapes] = useState(
    Array.isArray(politique?.steps) && politique.steps.length
      ? politique.steps.map((e) => ({
          delayDays: e?.delayDays ?? 0,
          channel: e?.channel || 'email',
          templateCode: e?.templateCode || '',
        }))
      : [{ delayDays: 1, channel: 'email', templateCode: '' }],
  )
  const [enCours, setEnCours] = useState(false)

  const effectives = Math.min(etapes.length, Math.max(0, Number(maxTentatives) || 0))

  function majEtape(i, champ, valeur) {
    setEtapes((s) => s.map((e, j) => (j === i ? { ...e, [champ]: valeur } : e)))
  }

  async function valider() {
    setEnCours(true)
    // `templateCode` vide est RETIRÉ, pas envoyé vide : le moteur retombe alors sur sa clé par
    // défaut (`revenue_recovery.<déclencheur>`). Envoyer une chaîne vide désignerait un modèle de
    // courriel qui n'existe pas.
    const corps = {
      active: actif,
      maxAttempts: Number(maxTentatives) || 0,
      steps: etapes.map((e) => ({
        delayDays: Number(e.delayDays) || 0,
        channel: e.channel || 'email',
        ...(e.templateCode.trim() ? { templateCode: e.templateCode.trim() } : {}),
      })),
    }
    // Le déclencheur ne s'envoie qu'à la CRÉATION : il fait partie de la clé d'unicité
    // (établissement × déclencheur), le changer sur une politique existante reviendrait à en
    // déplacer une sur une autre — un geste que personne n'a demandé.
    if (creation) corps.triggerType = declencheur
    await onEnregistrer(corps, creation ? null : idDe(politique))
    setEnCours(false)
  }

  return (
    <Modal
      open
      onClose={onFermer}
      titre={creation ? 'Nouvelle politique de relance' : libelleDeclencheur(politique.triggerType)}
      taille="lg"
    >
      {creation ? (
        <div className="field">
          <label htmlFor="rr-decl">Déclencheur</label>
          <select
            id="rr-decl"
            className="select"
            value={declencheur}
            onChange={(e) => setDeclencheur(e.target.value)}
          >
            {dispo.map((c) => (
              <option key={c} value={c}>{DECLENCHEURS[c]}</option>
            ))}
          </select>
          <div className="hint">
            Seuls les déclencheurs qu&rsquo;un événement émet réellement sont proposés. Le modèle en
            compte huit ; quatre n&rsquo;ont pas encore d&rsquo;émetteur, et une politique posée sur
            l&rsquo;un d&rsquo;eux ne s&rsquo;appliquerait jamais.
          </div>
        </div>
      ) : (
        <p className="hint">
          Le déclencheur ne se change pas : il identifie la politique au sein de
          l&rsquo;établissement. Pour en couvrir un autre, créez une seconde politique.
        </p>
      )}

      {SOUS_CONSENTEMENT(declencheur) && (
        <div className="banner banner-info">
          Ce déclencheur relève du <strong>consentement marketing</strong> : les relances ne partiront
          qu&rsquo;aux clients qui l&rsquo;ont accepté. Seules celles qui suivent un impayé
          (paiement refusé, incident rouvert) partent sur une base contractuelle.
        </div>
      )}

      <div className="field">
        <label>État</label>
        <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--esp-serre)', fontWeight: 400 }}>
          <input type="checkbox" checked={actif} onChange={(e) => setActif(e.target.checked)} />
          Active — les dossiers s&rsquo;ouvrent et les relances se programment
        </label>
        {!actif && (
          <div className="hint">
            Tant qu&rsquo;elle est inactive, rien ne se passe : aucun dossier ne s&rsquo;ouvre, même
            si l&rsquo;événement a lieu.
          </div>
        )}
      </div>

      <div className="field">
        <label htmlFor="rr-max">Nombre maximum de relances</label>
        <input
          id="rr-max"
          className="input num"
          type="number"
          min="0"
          value={maxTentatives}
          onChange={(e) => setMaxTentatives(e.target.value)}
        />
      </div>

      <h4>Rythme des relances</h4>
      <p className="hint">
        Chaque délai se compte depuis le fait déclencheur, pas depuis la relance précédente : « J+1,
        J+3 » envoie le lendemain, puis trois jours après l&rsquo;événement.
      </p>

      {etapes.map((e, i) => (
        <div key={i} className="row" style={{ gap: 'var(--esp-normal)', alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '0 0 8rem' }}>
            <label htmlFor={`rr-j-${i}`}>Relance {i + 1} — J+</label>
            <input
              id={`rr-j-${i}`}
              className="input num"
              type="number"
              min="0"
              value={e.delayDays}
              onChange={(ev) => majEtape(i, 'delayDays', ev.target.value)}
            />
          </div>
          <div className="field" style={{ flex: '1 1 14rem' }}>
            <label htmlFor={`rr-t-${i}`}>Modèle de courriel (facultatif)</label>
            <input
              id={`rr-t-${i}`}
              className="input"
              value={e.templateCode}
              onChange={(ev) => majEtape(i, 'templateCode', ev.target.value)}
              placeholder={`revenue_recovery.${declencheur || 'declencheur'}`}
            />
          </div>
          <div className="field" style={{ flex: '0 0 auto' }}>
            <button
              type="button"
              className="btn"
              onClick={() => setEtapes((s) => s.filter((_, j) => j !== i))}
              disabled={etapes.length === 1}
            >
              Retirer
            </button>
          </div>
        </div>
      ))}

      <p className="hint">
        Laissé vide, le modèle de courriel retombe sur la clé par défaut du déclencheur. Le canal est
        le courriel — c&rsquo;est le seul que le serveur sache utiliser aujourd&rsquo;hui.
      </p>

      <button
        type="button"
        className="btn"
        onClick={() => setEtapes((s) => [...s, { delayDays: 0, channel: 'email', templateCode: '' }])}
      >
        ＋ Ajouter une relance
      </button>

      {etapes.length > effectives && (
        <div className="banner banner-warn">
          {etapes.length} relances sont décrites, mais le plafond en autorise {effectives} :{' '}
          {etapes.length - effectives} ne partira{etapes.length - effectives > 1 ? 'ont' : ''} jamais.
        </div>
      )}

      {actif && etapes.length === 0 && (
        <div className="banner banner-warn">
          Sans aucune relance, cette politique ouvrira des dossiers sur lesquels rien ne sera jamais
          programmé.
        </div>
      )}

      <div className="bar">
        <button type="button" className="btn" onClick={onFermer}>Annuler</button>
        <button
          type="button"
          className="btn primary"
          onClick={valider}
          disabled={enCours || (creation && !declencheur)}
        >
          {enCours ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </Modal>
  )
}
