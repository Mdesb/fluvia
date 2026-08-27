import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

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

export default function Sport({ etabActif, droits = [] }) {
  // Le droit exige par le serveur est `sport.superviser_nocturne`, et lui seul : afficher le
  // bouton a qui ne l'a pas produirait un 403 sur un geste d'urgence -- le pire moment pour
  // decouvrir qu'on n'avait pas le droit.
  const peutTraiter = aLeDroit(droits, 'sport.superviser_nocturne')

  const [sos, setSos] = useState([])
  const [alertes, setAlertes] = useState([])
  const [abonnements, setAbonnements] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)
  // Un compteur << il y a N minutes >> qui ne bouge pas est un compteur faux : on redessine.
  const [, setTic] = useState(0)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [s, a, ab] = await Promise.all([
        api.evenementsSOS(),
        api.alertesPresenceIsolee().catch(() => null),
        api.abonnementsFitness().catch(() => null),
      ])
      setSos(membres(s))
      setAlertes(a ? membres(a) : [])
      setAbonnements(ab ? membres(ab) : [])
    } catch (e) {
      setErreur(e.message || 'Le module n’a pas pu être chargé.')
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

  const ouverts = sos.filter((e) => e.statut === 'ouverte')
  const traites = sos.filter((e) => e.statut !== 'ouverte')

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>Sport &amp; fitness</h1>
          <div className="sub">
            {ouverts.length > 0
              ? `${ouverts.length} alerte${ouverts.length > 1 ? 's' : ''} à traiter`
              : 'aucune alerte en cours'}
          </div>
        </div>
      </div>

      {erreur && <div className="alert crit">{erreur}</div>}

      {/* LES SOS EN TETE, ET AFFICHES MEME VIDES.
          Un bloc absent ne se distingue pas d'un bloc qu'on a oublie de charger : l'ecran doit DIRE
          qu'il n'y a rien, sinon l'exploitant ne sait pas s'il est tranquille ou mal informe. */}
      <section className="panel" style={{ marginBottom: 14 }}>
        <div className="panel-h">
          <span>Appels d&rsquo;urgence</span>
          {ouverts.length > 0 && <span className="badge crit" style={{ marginLeft: 8 }}>{ouverts.length} ouvert{ouverts.length > 1 ? 's' : ''}</span>}
        </div>

        {ouverts.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 22 }}>
            Aucun appel d&rsquo;urgence en cours.
          </div>
        ) : (
          <div style={{ display: 'grid', gap: 8, padding: 14 }}>
            {ouverts.map((e) => (
              <article
                key={e.id}
                className="panel"
                style={{ padding: 12, border: '1px solid var(--crit)', display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}
              >
                <span className="nm">{e.espaceAcces?.libelle || e.espaceAcces?.nom || 'Espace inconnu'}</span>
                <span className="sub">
                  {quandHeure(e.horodatage)} · {depuis(e.horodatage)}
                </span>
                {e.declenchePar?.identifiantSupport && (
                  <span className="sub">support {e.declenchePar.identifiantSupport}</span>
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

      <section className="panel" style={{ marginBottom: 14 }}>
        <div className="panel-h"><span>Présences isolées détectées</span></div>
        {alertes.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 22 }}>
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
                    <td>{a.espaceAcces?.libelle || a.espaceAcces?.nom || '—'}</td>
                    <td className="num">{a.nbPersonnesDetectees}</td>
                    <td className="num">{quandHeure(a.horodatage)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="panel">
        <div className="panel-h">
          <span>Abonnements</span>
          <span className="sub" style={{ marginLeft: 8 }}>{abonnements.length}</span>
        </div>
        {abonnements.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 22 }}>Aucun abonnement fitness.</div>
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
                {abonnements.map((a) => (
                  <tr key={a.id}>
                    <td>
                      <span className="nm">
                        {a.client?.raisonSociale
                          || [a.client?.prenom, a.client?.nom].filter(Boolean).join(' ')
                          || a.beneficiaire?.id
                          || '—'}
                      </span>
                    </td>
                    <td><span className="badge mut">{a.statut || '—'}</span></td>
                    <td className="num">{a.dateDebut ? quandHeure(a.dateDebut) : '—'}</td>
                    <td className="num">{a.dateFin ? quandHeure(a.dateFin) : <span className="sub">sans terme</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="hint">
          Souscription, pause, résiliation et réengagement passent encore par l&rsquo;API : cet écran
          les montre et traite les alertes, il ne les édite pas.
        </div>
      </section>

      {traites.length > 0 && (
        <div className="sub" style={{ marginTop: 12 }}>
          {traites.length} appel{traites.length > 1 ? 's' : ''} d&rsquo;urgence déjà traité{traites.length > 1 ? 's' : ''}.
        </div>
      )}
    </div>
  )
}
