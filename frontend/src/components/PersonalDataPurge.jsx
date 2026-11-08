import { useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { dateFr } from './Liste.jsx'
import { api } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

// ÉPURATION ET DURÉE DE CONSERVATION (#344).
//
// Deux gestes sur le fichier client de la collectivité COURANTE — celle de l'établissement actif,
// jamais les autres, même pour un administrateur affecté à plusieurs (le serveur le garantit,
// `CustomerGroupGuard`) :
//
// 1. La DURÉE DE CONSERVATION, appliquée chaque nuit par la tâche planifiée. Absente, rien n'est
//    purgé : l'écran le dit au lieu d'afficher un défaut qui ferait croire qu'une purge a lieu.
// 2. L'ÉPURATION SÉLECTIVE : critères → aperçu → cases à cocher → confirmation. Le serveur re-vérifie
//    chaque fiche cochée ; la sélection ne peut que restreindre l'épuration.
//
// L'aperçu dit combien de fiches sont PROTÉGÉES (abonnement en cours, solde de porte-monnaie) :
// sans ce nombre, « 0 fiche à épurer » se lirait « personne d'inactif ».
export default function PersonalDataPurge({ droits, etabActif }) {
  const peutParametrer = aLeDroit(droits, 'crm.parametrer')
  return (
    <>
      <RetentionRule peutParametrer={peutParametrer} etabActif={etabActif} />
      <PurgeSection etabActif={etabActif} />
    </>
  )
}

function RetentionRule({ peutParametrer, etabActif }) {
  const [regle, setRegle] = useState(null)
  const [mois, setMois] = useState('36')
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    let vivant = true
    setErreur(null)
    api.regleConservation()
      .then((r) => {
        if (!vivant) return
        setRegle(r)
        if (r?.months) setMois(String(r.months))
      })
      .catch((e) => vivant && setErreur(e.message))
    return () => { vivant = false }
  }, [etabActif])

  async function enregistrer(e) {
    e.preventDefault()
    setErreur(null)
    setSucces(null)
    setEnvoi(true)
    try {
      const r = await api.fixerConservation(Number(mois))
      setRegle(r)
      setSucces('Durée enregistrée.')
    } catch (err) {
      setErreur(err.message)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <section className="card">
      <div className="card-h"><h3>Durée de conservation des fiches clients</h3></div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}
        {regle === null && !erreur ? (
          <div className="spinner" />
        ) : regle?.configured ? (
          <p>
            Une fiche sans visite ni achat depuis <b>{regle.months} mois</b> est anonymisée
            automatiquement, sauf si un abonnement est en cours ou s’il reste un solde sur son
            porte-monnaie.
          </p>
        ) : (
          <div className="banner banner-warn">
            <b>Aucune durée n’est fixée : aucune fiche n’est anonymisée automatiquement.</b> Les
            données des clients partis sont alors conservées sans limite, ce que le RGPD interdit.
          </div>
        )}
        <p className="hint">
          La CNIL retient trois ans (36 mois) après la fin de la relation commerciale pour un fichier
          client — c’est la durée proposée. La décision revient à votre collectivité, responsable du
          traitement.
        </p>
        {peutParametrer && (
          <form onSubmit={enregistrer} className="rgpd-actions">
            <label htmlFor="rgpd-duree">Durée, en mois</label>
            <input
              id="rgpd-duree"
              className="input sm"
              type="number"
              min="1"
              max="240"
              value={mois}
              onChange={(e) => setMois(e.target.value)}
            />
            <button className="btn" type="submit" disabled={envoi || !mois}>
              {envoi ? 'Enregistrement…' : 'Enregistrer la durée'}
            </button>
          </form>
        )}
      </div>
    </section>
  )
}

function PurgeSection({ etabActif }) {
  const [mois, setMois] = useState('36')
  const [creeAvant, setCreeAvant] = useState('')
  const [apercu, setApercu] = useState(null)
  const [coches, setCoches] = useState(new Set())
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [confirmation, setConfirmation] = useState(false)

  // Changer d'établissement peut changer de collectivité : un aperçu d'une autre ne vaut plus rien.
  useEffect(() => { setApercu(null); setCoches(new Set()) }, [etabActif])

  function criteres() {
    const c = { inactiveMonths: Number(mois) }
    if (creeAvant) c.createdBefore = creeAvant
    return c
  }

  async function voir(e) {
    e?.preventDefault()
    setErreur(null)
    setChargement(true)
    try {
      const r = await api.apercuEpuration(criteres())
      setApercu(r)
      setCoches(new Set(r.candidates.map((c) => c.id)))
    } catch (err) {
      setApercu(null)
      setErreur(err.message)
    } finally {
      setChargement(false)
    }
  }

  function basculer(id) {
    setCoches((avant) => {
      const apres = new Set(avant)
      if (apres.has(id)) apres.delete(id)
      else apres.add(id)
      return apres
    })
  }

  async function appliquer() {
    const r = await api.epurer({ ...criteres(), clientIds: [...coches], confirm: true })
    setConfirmation(false)
    setSucces(
      `${r.anonymised} fiche(s) anonymisée(s).`
      + (r.ignored > 0 ? ` ${r.ignored} fiche(s) ignorée(s) : redevenues actives ou protégées depuis l’aperçu.` : ''),
    )
    await voir()
  }

  return (
    <section className="card">
      <div className="card-h"><h3>Épurer le fichier client</h3></div>
      <div className="card-b">
        <p className="hint">
          Repérez les fiches inactives, vérifiez-les, décochez celles à garder, puis anonymisez les
          autres. Seules les fiches de votre collectivité sont concernées.
        </p>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        <form onSubmit={voir} className="rgpd-actions">
          <label htmlFor="epur-mois">Sans visite ni achat depuis (mois)</label>
          <input id="epur-mois" className="input sm" type="number" min="1" max="240" value={mois} onChange={(e) => setMois(e.target.value)} />
          <label htmlFor="epur-cree">Créées avant le (facultatif)</label>
          <input id="epur-cree" className="input sm" type="date" value={creeAvant} onChange={(e) => setCreeAvant(e.target.value)} />
          <button className="btn" type="submit" disabled={chargement || !mois}>
            {chargement ? 'Recherche…' : 'Voir l’aperçu'}
          </button>
        </form>

        {apercu && (
          <>
            <div className="fiche-stats">
              <div className="stat-tile">
                <div className="st-val num">{apercu.inactive}</div>
                <div className="st-lbl">Fiches inactives depuis le {dateFr(apercu.inactiveSince)}</div>
              </div>
              <div className="stat-tile">
                <div className="st-val num">{apercu.protected}</div>
                <div className="st-lbl">Protégées : abonnement en cours ou solde restant</div>
              </div>
              <div className="stat-tile">
                <div className="st-val num">{apercu.eligible}</div>
                <div className="st-lbl">Peuvent être anonymisées</div>
              </div>
            </div>

            {apercu.eligible > apercu.candidates.length && (
              <p className="hint">
                Seules les {apercu.candidates.length} premières sont listées. Anonymisez-les, puis
                relancez l’aperçu pour les suivantes.
              </p>
            )}

            {apercu.candidates.length === 0 ? (
              <div className="empty">Aucune fiche à anonymiser avec ces critères.</div>
            ) : (
              <>
                <div className="rgpd-actions">
                  <button type="button" className="btn ghost sm" onClick={() => setCoches(new Set(apercu.candidates.map((c) => c.id)))}>Tout cocher</button>
                  <button type="button" className="btn ghost sm" onClick={() => setCoches(new Set())}>Tout décocher</button>
                  <span className="sub">{coches.size} fiche(s) cochée(s)</span>
                </div>
                <table className="tbl">
                  <thead>
                    <tr>
                      <th>Garder ou anonymiser</th>
                      <th>Personne</th>
                      <th>Courriel</th>
                      <th>Créée le</th>
                      <th>Dernière visite</th>
                    </tr>
                  </thead>
                  <tbody>
                    {apercu.candidates.map((c) => (
                      <tr key={c.id}>
                        <td>
                          <input
                            type="checkbox"
                            aria-label={'Anonymiser ' + nomCandidat(c)}
                            checked={coches.has(c.id)}
                            onChange={() => basculer(c.id)}
                          />
                        </td>
                        <td><span className="nm">{nomCandidat(c)}</span></td>
                        <td><span className="sub">{c.email || '—'}</span></td>
                        <td>{dateFr(c.dateCreation)}</td>
                        <td>{c.dateDerniereVisite ? dateFr(c.dateDerniereVisite) : 'jamais'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <div className="rgpd-actions">
                  <button
                    type="button"
                    className="btn danger"
                    disabled={coches.size === 0}
                    onClick={() => { setSucces(null); setConfirmation(true) }}
                  >
                    Anonymiser les {coches.size} fiche(s) cochée(s)
                  </button>
                </div>
              </>
            )}
          </>
        )}
      </div>

      <ConfirmPurge
        open={confirmation}
        nombre={coches.size}
        onClose={() => setConfirmation(false)}
        onConfirm={appliquer}
      />
    </section>
  )
}

function nomCandidat(c) {
  return [c.prenom, c.nom].filter(Boolean).join(' ') || c.raisonSociale || 'Sans nom'
}

function ConfirmPurge({ open, nombre, onClose, onConfirm }) {
  const [compris, setCompris] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => { if (open) { setCompris(false); setErreur(null) } }, [open])

  async function confirmer() {
    setErreur(null)
    setEnvoi(true)
    try {
      await onConfirm()
    } catch (err) {
      setErreur(err.message || 'L’épuration n’a pas abouti.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Anonymiser les fiches cochées">
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <p>Vous allez anonymiser <b>{nombre} fiche(s)</b>.</p>
      <div className="banner banner-warn">
        <b>Ce geste est définitif.</b> Les noms, coordonnées et dates de naissance sont effacés et ne
        pourront pas être retrouvés. Les fiches subsistent sans identité ; les ventes passées y
        restent rattachées, pour que la comptabilité reste juste.
      </div>
      <p className="hint">
        Chaque fiche anonymisée est tracée à votre nom, avec la date. Une fiche redevenue active depuis
        l’aperçu, ou qui a pris un abonnement entre-temps, est ignorée.
      </p>
      <label className="rgpd-check">
        <input type="checkbox" checked={compris} onChange={(e) => setCompris(e.target.checked)} />
        <span>J’ai vérifié la liste et je comprends que ce geste est définitif.</span>
      </label>
      <div className="rgpd-actions">
        <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
        <button type="button" className="btn danger" disabled={!compris || envoi} onClick={confirmer}>
          {envoi ? 'Anonymisation…' : 'Anonymiser définitivement'}
        </button>
      </div>
    </Modal>
  )
}
