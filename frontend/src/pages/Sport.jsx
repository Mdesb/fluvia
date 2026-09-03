import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import FicheAbonnement from '../components/FicheAbonnement.jsx'
import { aLeDroit } from '../api/droits.js'
import { resoudre, nomOuAbsence, euroCentimes, dateFr, jourLocal } from '../components/Liste.jsx'
import Modal from '../components/Modal.jsx'
import { libelleProduit } from '../api/produit.js'

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

export default function Sport({ etabActif, droits = [] }) {
  // Le droit exige par le serveur est `sport.superviser_nocturne`, et lui seul : afficher le
  // bouton a qui ne l'a pas produirait un 403 sur un geste d'urgence -- le pire moment pour
  // decouvrir qu'on n'avait pas le droit.
  const peutTraiter = aLeDroit(droits, 'sport.superviser_nocturne')
  // Le droit exige par `POST /sport/abonnements/souscrire`, et lui seul.
  const peutGererAbonnement = aLeDroit(droits, 'sport.gerer_abonnement')
  const [souscription, setSouscription] = useState(false)
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
  const [abonnements, setAbonnements] = useState(null)
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
      const [s, a, ab, es, ec, bf] = await Promise.all([
        api.evenementsSOS(),
        api.alertesPresenceIsolee().catch(() => null),
        api.abonnementsFitness().catch(() => null),
        api.espaces().catch(() => null),
        api.echeancesSepaSport().catch(() => null),
        // Le nom de l'adhérent n'est nulle part ailleurs : `abonnement.adherent` est une IRI nue.
        // `crm.lire` peut manquer — d'où le `.catch` et le `null` conservé.
        api.beneficiaires().catch(() => null),
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
    } catch (e) {
      setErreur(e.message || 'Le module n’a pas pu être chargé.')
      // On ne garde rien de partiel : un decompte a moitie lu a l'air normal.
      setSos(null)
      setAlertes(null)
      setAbonnements(null)
      setEcheances(null)
      setBeneficiaires(null)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  useEffect(() => {
    const t = setInterval(() => setTic((n) => n + 1), 60000)
    return () => clearInterval(t)
  }, [])

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
        <div className="card-h"><span>Présences isolées détectées</span></div>
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

      <section className="card">
        <div className="card-h">
          <span>Abonnements</span>
          <span className="sub" style={{ marginLeft: 8 }}>{abonnements === null ? '—' : abonnements.length}</span>
          {peutGererAbonnement && (
            <div className="actions" style={{ marginLeft: 'auto' }}>
              <button className="btn sm" type="button" onClick={() => setSouscription(true)}>
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

        <SouscriptionModal
          open={souscription}
          onClose={() => setSouscription(false)}
          onFait={() => { setSouscription(false); recharger() }}
        />
      </section>

      <Echeancier
        echeances={echeances}
        abonnements={abonnements}
        beneficiaires={beneficiaires}
        peutGerer={peutGererAbonnement}
        onAnnuler={setAnnulation}
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
function Echeancier({ echeances, abonnements, beneficiaires, peutGerer, onAnnuler }) {
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
                  {peutGerer && <th aria-label="Actions" />}
                </tr>
              </thead>
              <tbody>
                {echeances.map((e) => {
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
                      {peutGerer && (
                        <td>
                          {/* Le serveur refuse (422) toute échéance qui n'est pas « à venir », en
                              nommant son état. On n'affiche donc pas un bouton qui serait refusé :
                              un contrôle proposé puis refusé apprend au lecteur à s'en méfier. */}
                          {e.statut === 'a_venir' && (
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
function SouscriptionModal({ open, onClose, onFait }) {
  const [beneficiaires, setBeneficiaires] = useState([])
  const [clients, setClients] = useState([])
  const [produits, setProduits] = useState([])
  const [adherent, setAdherent] = useState('')
  const [payeur, setPayeur] = useState('')
  const [produit, setProduit] = useState('')
  const [periodicite, setPeriodicite] = useState('mensuel')
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
    setAdherent(''); setPayeur(''); setProduit(''); setPeriodicite('mensuel')
    setDuree('12'); setIban(''); setTitulaire(''); setErreur(null)
    Promise.allSettled([api.beneficiaires(), api.rechercheClients({ itemsPerPage: 100 }), api.produits()])
      .then(([b, c, p]) => {
        setBeneficiaires(b.status === 'fulfilled' ? membres(b.value) : [])
        setClients(c.status === 'fulfilled' ? (c.value?.items || membres(c.value)) : [])
        setProduits(p.status === 'fulfilled' ? membres(p.value) : [])
      })
  }, [open])

  const formules = produits.filter((p) => p.formule?.id)
  // Le tarif du produit choisi, tel que le catalogue le porte. On l'AFFICHE : c'est ce que le
  // serveur résoudra, et le montrer avant permet de s'apercevoir qu'il manque avant de valider.
  const produitChoisi = formules.find((p) => p.id === produit)
  const grilleTarif = (produitChoisi?.grilles || []).find((g) => g && g.prix !== null && g.prix !== undefined && g.prix !== '')
  const tarifAffiche = grilleTarif
    ? Number(grilleTarif.prix).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
    : null
  const pret = adherent && payeur && produit && periodicite
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
        periodicite,
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
    <Modal open={open} onClose={onClose} titre="Souscrire un abonnement" taille="lg">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        {formules.length === 0 && (
          <div className="banner banner-warn">
            Aucun produit ne porte de formule d’abonnement. Une souscription s’appuie sur une
            formule : créez d’abord un produit de type abonnement dans <b>Catalogue</b>.
          </div>
        )}

        <div className="row" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="ab-adherent">Adhérent *</label>
            <select id="ab-adherent" className="input" value={adherent} onChange={(e) => setAdherent(e.target.value)}>
              <option value="">— choisir —</option>
              {beneficiaires.map((b) => (
                <option key={b.id} value={b.id}>
                  {[b.prenom, b.nom].filter(Boolean).join(' ') || b.id}
                </option>
              ))}
            </select>
            <span className="hint">Celui qui vient s’entraîner.</span>
          </div>
          <div className="field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="ab-payeur">Payeur *</label>
            <select id="ab-payeur" className="input" value={payeur} onChange={(e) => setPayeur(e.target.value)}>
              <option value="">— choisir —</option>
              {clients.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.raisonSociale || [c.prenom, c.nom].filter(Boolean).join(' ') || c.id}
                </option>
              ))}
            </select>
            {/* Deux personnes différentes dans le cas courant : un parent règle pour son enfant.
                Les confondre ferait prélever le mineur. */}
            <span className="hint">Celui qui sera prélevé — souvent le parent, pas l’adhérent.</span>
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

        <div className="row" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
          <div className="field" style={{ flex: '1 1 160px' }}>
            <label htmlFor="ab-periodicite">Périodicité *</label>
            <select id="ab-periodicite" className="input" value={periodicite} onChange={(e) => setPeriodicite(e.target.value)}>
              <option value="mensuel">Mensuelle</option>
              <option value="hebdomadaire">Hebdomadaire</option>
            </select>
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
        <div className="row" style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
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
    </Modal>
  )
}
