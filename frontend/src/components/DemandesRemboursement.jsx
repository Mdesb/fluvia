import { useCallback, useEffect, useState } from 'react'
import { dateHeureFr } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { euros } from '../api/produit.js'

// Les demandes de remboursement, et les deux gestes qui les traitent.
//
// CETTE LISTE ÉTAIT AFFICHÉE DEPUIS LE DÉBUT, ET ON NE POUVAIT RIEN EN FAIRE.
//
// `accepter` et `refuser` existaient côté serveur, le client HTTP savait les appeler, et **aucun
// écran ne les proposait**. Une demande de remboursement arrivait donc, s'affichait, et y restait :
// une boîte aux lettres sans porte. C'est la violation la plus nette de D55 dans ce produit — une
// liste de choses à traiter sans le geste qui les traite.
//
// Et le coût n'est pas le retard : c'est que **la liste apprend à son lecteur à l'ignorer**. Au bout
// de quelques semaines, plus personne ne l'ouvre — et brancher le bouton six mois plus tard ne
// défait pas cette habitude.
//
// DEUX GESTES QUI NE SE RESSEMBLENT PAS, ET L'ÉCRAN NE LES TRAITE PAS PAREIL.
//
// Accepter déclenche un avoir : c'est de l'argent qui sort, et le montant est modifiable — le
// serveur rembourse le total si on ne dit rien. On propose donc explicitement les deux, plutôt que
// de laisser un champ pré-rempli qu'on modifie par accident.
//
// Refuser exige un motif, et **ce motif est communiqué au client** (RG-M3-15). C'est la seule zone
// de saisie de cet écran qu'une personne extérieure lira. Elle est signalée comme telle : une note
// interne écrite là devient une réponse au client.

const EN_ATTENTE = ['recue', 'en_cours']

export default function DemandesRemboursement({ etabActif, droits, params = {}, majParams }) {
  const ouvert = params.demande || ''
  const sensConnu = params.sens === 'accepter' || params.sens === 'refuser'
  const ouvrir = (id, sens) => majParams({ demande: String(id), sens }, { pousser: true })
  const fermer = () => majParams({ demande: '', sens: '' }, { pousser: true })
  const [demandes, setDemandes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const peutTraiter = aLeDroit(droits, 'boutique.traiter_remboursement')

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setDemandes(membres(await api.demandesRemboursement()))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  const attente = demandes.filter((d) => EN_ATTENTE.includes(d.statut))
  const traitees = demandes.filter((d) => !EN_ATTENTE.includes(d.statut))

  // ── LE TRAITEMENT D'UNE DEMANDE, EN ÉCRAN ───────────────────────────────────────────────
  //
  // ⚠ DEUX PARAMÈTRES, PAS UN : la demande ET le sens. Le sens décide du titre, du formulaire
  // et de la route appelée — accepter émet un avoir, refuser prévient le client. Un sens
  // inconnu ne doit donc PAS retomber silencieusement sur « accepter » : l'écran le refuse.
  //
  // ⚠ `demandes` part à [] et non à null : la longueur de la liste ne dit rien sur la lecture.
  // C'est `chargement` qui porte la distinction, consulté avant de conclure « introuvable ».
  if (ouvert) {
    const retour = (
      <button
        className="btn ghost sm"
        type="button"
        onClick={fermer}
        style={{ marginBottom: 'var(--esp-large)' }}
      >
        ← Retour aux demandes
      </button>
    )
    if (chargement) {
      return (
        <>
          {retour}
          <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
        </>
      )
    }
    const demande = demandes.find((d) => String(d.id) === String(params.demande))
    if (!demande || !sensConnu) {
      return (
        <>
          {retour}
          <div className="banner banner-warn">
            {!sensConnu
              ? 'Ce lien ne dit pas s’il s’agit d’accepter ou de refuser. Revenez à la liste et choisissez : les deux gestes n’ont pas les mêmes conséquences.'
              : 'Cette demande n’est plus dans la liste — elle a sans doute été traitée depuis que ce lien a été copié.'}
          </div>
        </>
      )
    }
    return (
      <>
        {retour}
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <TraitementModal
          key={`${params.demande}:${params.sens}`}
          etat={{ demande, sens: params.sens }}
          onClose={fermer}
          onFait={(m) => { fermer(); setSucces(m); setErreur(null); recharger() }}
          onErreur={setErreur}
        />
      </>
    )
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h">
          <h3>Demandes à traiter</h3>
          <span className="sub">
            {attente.length === 0 ? 'aucune en attente' : `${attente.length} en attente de réponse`}
          </span>
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : attente.length === 0 ? (
            <div className="empty">
              Aucune demande en attente. Un client qui demande un remboursement depuis la boutique en
              ligne apparaît ici, et y reste tant que personne n'a répondu.
            </div>
          ) : (
            <>
              {!peutTraiter && (
                <div className="banner banner-warn">
                  Votre profil peut lire ces demandes mais pas y répondre. Accepter ou refuser un
                  remboursement demande le droit « traiter les remboursements ».
                </div>
              )}
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Demandée le</th>
                    <th>Motif du client</th>
                    <th>Origine</th>
                    <th>État</th>
                    {peutTraiter && <th />}
                  </tr>
                </thead>
                <tbody>
                  {attente.map((d) => (
                    <tr key={d.id}>
                      <td>{dateHeureFr(d.dateDemande)}</td>
                      <td>
                        <span className="nm">{d.motif || <span className="sub">aucun motif donné</span>}</span>
                        <div className="sub mono">{String(d.id || '').slice(0, 8)}</div>
                      </td>
                      <td>
                        {d.origineAutomatique ? (
                          <span className="badge info" title="Créée par le logiciel, pas par le client.">
                            automatique
                          </span>
                        ) : (
                          <span className="sub">le client</span>
                        )}
                      </td>
                      <td><span className="badge warn">{mot(d.statut)}</span></td>
                      {peutTraiter && (
                        <td className="num">
                          <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                            <button
                              className="btn primary sm"
                              type="button"
                              onClick={() => ouvrir(d.id, 'accepter')}
                            >
                              Accepter
                            </button>
                            <button
                              className="btn ghost sm"
                              type="button"
                              onClick={() => ouvrir(d.id, 'refuser')}
                            >
                              Refuser
                            </button>
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
        </div>
      </section>

      {traitees.length > 0 && (
        <section className="card" style={{ marginTop: 16 }}>
          <div className="card-h">
            <h3>Demandes déjà traitées</h3>
            <span className="sub">pour mémoire — plus rien à faire dessus</span>
          </div>
          <div className="card-b">
            <table className="tbl">
              <thead>
                <tr>
                  <th>Demandée le</th>
                  <th>Motif du client</th>
                  <th>Réponse</th>
                  <th>Traitée le</th>
                </tr>
              </thead>
              <tbody>
                {traitees.map((d) => (
                  <tr key={d.id}>
                    <td>{dateHeureFr(d.dateDemande)}</td>
                    <td>{d.motif || '—'}</td>
                    <td>
                      <span className={`badge ${d.statut === 'acceptee' ? 'good' : 'mut'}`}>
                        {mot(d.statut)}
                      </span>
                      {d.motifRefus && <div className="sub">« {d.motifRefus} »</div>}
                    </td>
                    <td>{d.dateTraitement ? dateHeureFr(d.dateTraitement) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

    </>
  )
}

function TraitementModal({ etat, onClose, onFait, onErreur }) {
  const [partiel, setPartiel] = useState(false)
  const [montant, setMontant] = useState('')
  const [motifRefus, setMotifRefus] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (etat) { setPartiel(false); setMontant(''); setMotifRefus('') }
  }, [etat])

  const accepte = etat?.sens === 'accepter'

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      if (accepte) {
        await api.accepterRemboursement(etat.demande.id, partiel ? montant : undefined)
        onFait(
          partiel
            ? `Remboursement de ${euros(montant)} accepté : un avoir est émis.`
            : 'Remboursement accepté en totalité : un avoir est émis.',
        )
      } else {
        await api.refuserRemboursement(etat.demande.id, motifRefus.trim())
        onFait('Demande refusée. Le client reçoit le motif que vous avez écrit.')
      }
    } catch (err) {
      onErreur(err.message || "L'opération n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <>
      <h2>{accepte ? 'Accepter le remboursement' : 'Refuser la demande'}</h2>
      {etat && (
        <form onSubmit={envoyer}>
          <p style={{ marginTop: 0 }}>
            Demande du {dateHeureFr(etat.demande.dateDemande)}
            {etat.demande.motif && <> — « {etat.demande.motif} »</>}
          </p>

          {accepte ? (
            <>
              <div className="fiche-sec" style={{ marginTop: 0 }}>Combien rembourser ?</div>

              <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 0', fontWeight: 400 }}>
                <input
                  type="radio"
                  name="montant-remb"
                  checked={!partiel}
                  onChange={() => setPartiel(false)}
                  style={{ marginTop: 3 }}
                />
                <span>
                  <b>La totalité</b>
                  <div className="sub">C'est ce que fait le logiciel si vous ne précisez rien.</div>
                </span>
              </label>

              <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 0', fontWeight: 400 }}>
                <input
                  type="radio"
                  name="montant-remb"
                  checked={partiel}
                  onChange={() => setPartiel(true)}
                  style={{ marginTop: 3 }}
                />
                <span>
                  <b>Une partie seulement</b>
                  <div className="sub">
                    Par exemple si une prestation a été consommée, ou en retenant des frais.
                  </div>
                </span>
              </label>

              {partiel && (
                <div className="field" style={{ marginTop: 8 }}>
                  <label htmlFor="rb-montant">Montant à rembourser</label>
                  <input
                    id="rb-montant"
                    className="input"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    value={montant}
                    onChange={(e) => setMontant(e.target.value)}
                  />
                  <div className="hint">
                    Le serveur refusera un montant supérieur à ce qui reste remboursable sur la vente,
                    et vous dira lequel.
                  </div>
                </div>
              )}

              <div className="banner banner-warn">
                Accepter émet un <b>avoir</b> : la vente d'origine n'est pas modifiée, une écriture
                s'ajoute. C'est ce qui permet de retrouver plus tard ce qui a été vendu <i>et</i> ce
                qui a été rendu.
              </div>
            </>
          ) : (
            <div className="field">
              <label htmlFor="rb-motif">Motif du refus *</label>
              <textarea
                id="rb-motif"
                className="input"
                rows={3}
                required
                value={motifRefus}
                placeholder="La séance a été consommée le 12/08, l'entrée a été contrôlée à 14 h 32."
                onChange={(e) => setMotifRefus(e.target.value)}
              />
              <div className="banner banner-warn" style={{ marginTop: 8, marginBottom: 0 }}>
                <b>Ce texte est envoyé au client.</b> C'est la seule zone de cet écran qu'une personne
                extérieure lira : une note interne écrite ici devient votre réponse.
              </div>
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button
              className="btn primary"
              type="submit"
              disabled={
                enCours
                || (accepte && partiel && !montant)
                || (!accepte && !motifRefus.trim())
              }
            >
              {enCours ? 'Envoi…' : accepte ? 'Accepter et émettre l’avoir' : 'Refuser et prévenir le client'}
            </button>
          </div>
        </form>
      )}
    </>
  )
}
