import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'

/**
 * PRENDRE UN RENDEZ-VOUS — le second modèle, celui du coiffeur et du masseur.
 *
 * **Ce que cet écran fait et que l'autre ne pouvait pas faire.** Le planning réserve *un créneau qui
 * existe déjà* : juste pour une séance de piscine, où la séance de 14 h existe indépendamment de qui
 * la réserve. Ici rien n'existe avant que le client n'appelle — on cherche **où le rendez-vous
 * tiendrait**, entre les horaires, les absences et ce qui est déjà pris.
 *
 * **« Avec qui est libre » est le mode par défaut, et c'est ce qui remplit un agenda.** Un client qui
 * demande « samedi matin » se moque de savoir avec qui. Lui imposer de choisir d'abord un praticien le
 * fait renoncer quand le premier essayé est complet — et l'agenda du second reste vide.
 *
 * > **Un formulaire qui fait choisir avant de montrer fait renoncer avant de proposer.**
 *
 * **Le prix n'est pas affiché ici, et c'est délibéré.** `Activite` porte un `tarifReferenceMontant`
 * et un produit de référence : le montant réellement dû se résout à la vente, avec le tarif, la
 * saison et le quotient familial. Afficher le tarif de référence comme s'il était le prix
 * reproduirait exactement le défaut corrigé à la caisse cette semaine — un prix annoncé qui n'est pas
 * celui qui sera facturé.
 */

function hhmm(iso) {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function aujourdhui() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

export default function PriseRendezVous({ onReserve }) {
  const [activites, setActivites] = useState([])
  const [ressources, setRessources] = useState([])
  const [beneficiaires, setBeneficiaires] = useState([])

  const [activite, setActivite] = useState('')
  const [date, setDate] = useState(aujourdhui())
  const [ressource, setRessource] = useState('')
  const [pas, setPas] = useState(15)

  const [resultat, setResultat] = useState(null)
  const [recherche, setRecherche] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [pose, setPose] = useState(null) // proposition en cours de confirmation
  const [organisateur, setOrganisateur] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    let annule = false
    ;(async () => {
      try {
        const [a, r, b] = await Promise.all([
          api.reservationActivites(),
          api.reservationRessources(),
          api.beneficiaires(),
        ])
        if (annule) return
        const acts = membres(a).filter((x) => x.actif !== false)
        setActivites(acts)
        setRessources(membres(r))
        setBeneficiaires(membres(b))
        if (acts.length === 1) setActivite(acts[0].id)
      } catch (e) {
        if (!annule) setErreur(e.message || 'Le référentiel n’a pas pu être chargé.')
      }
    })()
    return () => {
      annule = true
    }
  }, [])

  const chercher = useCallback(async () => {
    if (!activite || !date) return
    setRecherche(true)
    setErreur(null)
    setSucces(null)
    setResultat(null)
    try {
      setResultat(await api.creneauxLibres({ activite, date, pas, ...(ressource ? { ressource } : {}) }))
    } catch (e) {
      setErreur(e.message || 'La recherche a échoué.')
    } finally {
      setRecherche(false)
    }
  }, [activite, date, ressource, pas])

  async function poser(proposition) {
    if (!organisateur) return
    setEnCours(true)
    setErreur(null)
    try {
      // DEUX APPELS, ET C'EST LE POINT : on cree le creneau, puis on le reserve.
      //
      // Le creneau n'est pas un artefact d'affichage — c'est lui qui portera la projection d'acces,
      // la regle d'annulation et la facturation de non-presentation. Le placement libre ne cree donc
      // pas une seconde chaine : il fabrique le meme objet que le planning, juste plus tard.
      const creneau = await api.creerCreneau({
        ressource: `/api/reservation_ressources/${proposition.ressource}`,
        activite: `/api/reservation_activites/${activite}`,
        debut: proposition.debut,
        fin: proposition.fin,
        capacite: 1,
      })
      await api.reserverCreneau({
        creneau: creneau['@id'] || `/api/reservation_creneaus/${creneau.id}`,
        organisateur: `/api/beneficiaires/${organisateur}`,
      })
      setSucces(
        `Rendez-vous posé le ${new Date(proposition.debut).toLocaleDateString('fr-FR')} à `
        + `${hhmm(proposition.debut)} avec ${proposition.ressourceLibelle}.`,
      )
      setPose(null)
      setOrganisateur('')
      await chercher()
      onReserve?.()
    } catch (e) {
      // Le message du serveur est conserve : sur un conflit de chevauchement, c'est lui qui dit que
      // quelqu'un vient de prendre le creneau. << Echec >> ferait reessayer a l'identique.
      setErreur(e.message || 'Le rendez-vous n’a pas pu être posé.')
    } finally {
      setEnCours(false)
    }
  }

  const propositions = resultat?.propositions || []

  return (
    <div style={{ display: 'grid', gap: 14 }}>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h"><span>Chercher un rendez-vous</span></div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 12, padding: 14 }}>
          <div>
            <label htmlFor="rdv-act">Prestation</label>
            <select id="rdv-act" className="select" value={activite} onChange={(e) => setActivite(e.target.value)}>
              <option value="">Choisir…</option>
              {activites.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.libelle} · {a.dureeMinutes} min
                  {a.battementMinutes > 0 ? ` (+${a.battementMinutes} min)` : ''}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="rdv-date">Date</label>
            <input id="rdv-date" className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </div>
          <div>
            <label htmlFor="rdv-res">Avec</label>
            <select id="rdv-res" className="select" value={ressource} onChange={(e) => setRessource(e.target.value)}>
              {/* Le defaut est << qui est libre >>, et il est en premier. */}
              <option value="">Qui est libre</option>
              {ressources.map((r) => <option key={r.id} value={r.id}>{r.libelle}</option>)}
            </select>
          </div>
          <div>
            <label htmlFor="rdv-pas">Pas</label>
            <select id="rdv-pas" className="select" value={pas} onChange={(e) => setPas(Number(e.target.value))}>
              <option value={5}>5 min</option>
              <option value={10}>10 min</option>
              <option value={15}>15 min</option>
              <option value={30}>30 min</option>
            </select>
          </div>
          <div style={{ display: 'flex', alignItems: 'flex-end' }}>
            <button className="btn primary" type="button" disabled={!activite || !date || recherche} onClick={chercher}>
              {recherche ? 'Recherche…' : 'Chercher'}
            </button>
          </div>
        </div>
      </section>

      {resultat && (
        <section className="card">
          <div className="card-h">
            <span>{propositions.length} proposition{propositions.length > 1 ? 's' : ''}</span>
            <span className="sub" style={{ marginLeft: 8 }}>
              {resultat.libelle} · {resultat.dureeMinutes} min
              {resultat.battementMinutes > 0 ? ` + ${resultat.battementMinutes} min de remise en état` : ''}
            </span>
          </div>

          {propositions.length === 0 ? (
            // D54 : le fait sur la donnee, et la cause probable. << Aucune proposition >> ne dit pas
            // si tout est pris ou si personne n'a la competence exigee -- deux situations qui
            // n'appellent pas la meme action.
            <div className="sub" style={{ textAlign: 'center', padding: 24 }}>
              {resultat.ressourcesInterrogees === 0
                ? 'Aucune ressource ne peut assurer cette prestation : vérifiez la compétence exigée.'
                : 'Aucun créneau ce jour-là. Essayez une autre date, ou vérifiez les horaires de la ressource.'}
            </div>
          ) : (
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, padding: 14 }}>
              {propositions.map((p) => (
                <button
                  key={`${p.debut}-${p.ressource}`}
                  type="button"
                  className={pose && pose.debut === p.debut && pose.ressource === p.ressource ? 'btn primary sm' : 'btn sm'}
                  onClick={() => setPose(p)}
                  title={p.ressourceLibelle}
                  style={{ minWidth: 128, textAlign: 'left' }}
                >
                  <b style={{ fontVariantNumeric: 'tabular-nums' }}>{hhmm(p.debut)}</b>
                  {/* Le nom du praticien est TOUJOURS affiche, meme quand on en a choisi un :
                      le caissier annonce un nom au client, et il doit le lire, pas s'en souvenir. */}
                  <div className="sub" style={{ fontSize: 11 }}>{p.ressourceLibelle}</div>
                </button>
              ))}
            </div>
          )}

          {pose && (
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap', padding: '0 14px 14px' }}>
              <div>
                <label htmlFor="rdv-benef">Pour</label>
                <select
                  id="rdv-benef"
                  className="select"
                  style={{ width: 260 }}
                  value={organisateur}
                  onChange={(e) => setOrganisateur(e.target.value)}
                >
                  <option value="">Choisir un bénéficiaire…</option>
                  {beneficiaires.map((b) => (
                    <option key={b.id} value={b.id}>
                      {[b.client?.prenom, b.client?.nom].filter(Boolean).join(' ') || b.client?.raisonSociale || b.id}
                    </option>
                  ))}
                </select>
              </div>
              <button className="btn primary" type="button" disabled={enCours || !organisateur} onClick={() => poser(pose)}>
                {enCours ? 'Enregistrement…' : `Poser à ${hhmm(pose.debut)}`}
              </button>
              <button className="btn ghost" type="button" onClick={() => setPose(null)}>Annuler</button>
            </div>
          )}
        </section>
      )}
    </div>
  )
}
