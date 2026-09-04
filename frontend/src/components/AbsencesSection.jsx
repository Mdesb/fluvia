import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from './Modal.jsx'
import { confirmer } from './Confirmation.jsx'

// Absences du personnel : déclarer, valider, refuser.
//
// Trois opérations qui existaient et n'avaient aucun bouton. Un responsable ne pouvait ni accepter ni
// refuser un congé depuis le logiciel — il fallait le faire ailleurs, et le planning ne le savait pas.
//
// L'ALERTE DE COUVERTURE EST LE POINT QUI COMPTE. Le serveur lève `alerteCouverture` quand l'absence
// chevauche une affectation déjà confirmée — autrement dit : « si vous validez, un poste n'est plus
// tenu ». Il ne l'annule pas automatiquement, et il a raison : c'est une décision d'exploitation, pas
// une conséquence mécanique. Mais valider sans le voir revient à découvrir le trou le jour même.
// L'alerte est donc affichée sur la ligne ET rappelée dans la confirmation.

const TYPES = [
  ['conge', 'Congé'],
  ['maladie', 'Maladie'],
  ['formation', 'Formation'],
  ['autre', 'Autre'],
]

const STATUTS = {
  declaree: { libelle: 'À décider', ton: 'warn' },
  validee: { libelle: 'Acceptée', ton: 'good' },
  refusee: { libelle: 'Refusée', ton: 'mut' },
}

export default function AbsencesSection({ etabActif, droits = [] }) {
  // ⚠ `null` = PAS LU. << Aucune absence enregistree >> decide d'un planning : on affecte
  // quelqu'un qui est peut-etre en arret.
  const [lignes, setLignes] = useState(null)
  const [employes, setEmployes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [enCours, setEnCours] = useState(null)
  const [declaration, setDeclaration] = useState(null)

  const peutDecider = aLeDroit(droits, 'personnel.valider_absence')
  const peutDeclarer =
    aLeDroit(droits, 'personnel.gerer_planning') || aLeDroit(droits, 'personnel.declarer_absence_soi')

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setLignes(membres(await api.absences()))
    } catch (e) {
      setErreur(e.message)
      setLignes(null)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [etabActif, recharger])

  useEffect(() => {
    if (!peutDeclarer) return
    api.employes().then((c) => setEmployes(membres(c))).catch(() => {})
  }, [peutDeclarer])

  async function decider(l, accepte) {
    if (accepte && l.alerteCouverture) {
      const ok = await confirmer(
        `Accepter cette absence ?\n\nElle chevauche une affectation déjà confirmée : un poste ne sera `
          + `plus tenu sur la période. L'affectation n'est pas annulée automatiquement — c'est à vous `
          + `de la réorganiser.`,
      )
      if (!ok) return
    }
    setErreur(null)
    setSucces(null)
    setEnCours(l.id)
    try {
      await (accepte ? api.validerAbsence(l.id) : api.refuserAbsence(l.id))
      setSucces(accepte ? 'Absence acceptée.' : 'Absence refusée.')
      await recharger()
    } catch (e) {
      setErreur(e.message || "La décision n'a pas abouti.")
    } finally {
      setEnCours(null)
    }
  }

  async function declarer(e) {
    e.preventDefault()
    setErreur(null)
    setSucces(null)
    setEnCours('declaration')
    try {
      await api.declarerAbsence({
        employe: `/api/employes/${declaration.employe}`,
        debut: declaration.debut,
        fin: declaration.fin,
        type: declaration.type,
        ...(declaration.motif.trim() ? { motif: declaration.motif.trim() } : {}),
      })
      setSucces('Absence déclarée.')
      setDeclaration(null)
      await recharger()
    } catch (err) {
      setErreur(err.message || "La déclaration n'a pas abouti.")
    } finally {
      setEnCours(null)
    }
  }

  const aDecider = (lignes || []).filter((l) => l.statut === 'declaree').length

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Absences</h3>
        {aDecider > 0 && <span className="badge warn">{aDecider} à décider</span>}
        {peutDeclarer && !declaration && (
          <div className="r">
            <button
              className="btn primary sm"
              type="button"
              onClick={() =>
                setDeclaration({ employe: employes[0]?.id || '', debut: '', fin: '', type: 'conge', motif: '' })
              }
            >
              ＋ Déclarer une absence
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}



        {chargement ? (
          <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
        ) : lignes === null ? (
          <div className="banner banner-error">
            Les absences n’ont pas pu être lues. <b>Ne bâtissez pas un planning sur ce cadre</b>&nbsp;:
            quelqu’un est peut-être en congé ou en arrêt sans que cet écran le sache.
          </div>
        ) : lignes.length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>
            Aucune absence enregistrée. Les congés, arrêts et formations déclarés apparaîtront ici, et
            c'est là qu'ils s'acceptent ou se refusent.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Personne</th>
                  <th>Motif</th>
                  <th>Période</th>
                  <th>Décision</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(lignes || []).map((l) => {
                  const st = STATUTS[l.statut] || { libelle: l.statut, ton: 'mut' }
                  const emp = l.employe || {}
                  return (
                    <tr key={l.id}>
                      <td>
                        <span className="nm">
                          {[emp.prenom, emp.nom].filter(Boolean).join(' ') || emp.matricule || '—'}
                        </span>
                      </td>
                      <td>
                        {TYPES.find(([v]) => v === l.type)?.[1] || l.type || '—'}
                        {l.motif && <div className="hint" style={{ margin: 0 }}>{l.motif}</div>}
                      </td>
                      <td>
                        {periode(l.debut, l.fin)}
                        {/* Le signal que le serveur lève et que personne ne voyait. */}
                        {l.alerteCouverture && (
                          <div>
                            <span
                              className="badge crit"
                              title="Cette absence chevauche une affectation déjà confirmée : un poste ne sera plus tenu."
                            >
                              poste à recouvrir
                            </span>
                          </div>
                        )}
                      </td>
                      <td><span className={`badge ${st.ton}`}>{st.libelle}</span></td>
                      <td className="num">
                        {l.statut === 'declaree' && peutDecider && (
                          <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                            <button
                              className="btn primary sm"
                              type="button"
                              disabled={enCours !== null}
                              onClick={() => decider(l, true)}
                            >
                              Accepter
                            </button>
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={enCours !== null}
                              onClick={() => decider(l, false)}
                            >
                              Refuser
                            </button>
                          </div>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* LE FORMULAIRE EST PASSÉ EN MODALE (28/08), ET CE N’EST PAS COSMÉTIQUE.
          Il s’insérait ENTRE l’en-tête et le tableau : déclarer une absence poussait vers le bas la
          liste qu’on était en train de lire, et les lignes « à décider » disparaissaient sous le
          pli au moment où l’on ouvrait le formulaire. Une modale laisse la liste où elle est. */}
      <Modal
        open={!!declaration}
        onClose={() => setDeclaration(null)}
        titre="Déclarer une absence"
      >
        {declaration && (
          <form onSubmit={declarer}>
            <div className="grid g2" style={{ gap: 12 }}>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="ab-emp">Personne *</label>
                <select
                  id="ab-emp"
                  className="select"
                  required
                  value={declaration.employe}
                  onChange={(e) => setDeclaration((s) => ({ ...s, employe: e.target.value }))}
                >
                  {employes.map((emp) => (
                    <option key={emp.id} value={emp.id}>
                      {[emp.prenom, emp.nom].filter(Boolean).join(' ') || emp.matricule || 'Employé'}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="ab-type">Motif</label>
                <select
                  id="ab-type"
                  className="select"
                  value={declaration.type}
                  onChange={(e) => setDeclaration((s) => ({ ...s, type: e.target.value }))}
                >
                  {TYPES.map(([v, l]) => (
                    <option key={v} value={v}>{l}</option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="ab-debut">Du *</label>
                <input
                  id="ab-debut"
                  className="input"
                  type="datetime-local"
                  required
                  value={declaration.debut}
                  onChange={(e) => setDeclaration((s) => ({ ...s, debut: e.target.value }))}
                />
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="ab-fin">Au *</label>
                <input
                  id="ab-fin"
                  className="input"
                  type="datetime-local"
                  required
                  value={declaration.fin}
                  onChange={(e) => setDeclaration((s) => ({ ...s, fin: e.target.value }))}
                />
              </div>
            </div>
            <div className="field">
              <label htmlFor="ab-motif">Précision</label>
              <input
                id="ab-motif"
                className="input"
                value={declaration.motif}
                placeholder="Facultatif"
                onChange={(e) => setDeclaration((s) => ({ ...s, motif: e.target.value }))}
              />
            </div>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <button className="btn" type="button" onClick={() => setDeclaration(null)}>Annuler</button>
              <button className="btn primary" type="submit" disabled={enCours !== null}>
                Déclarer
              </button>
            </div>

          </form>
        )}
      </Modal>
    </section>
  )
}

function periode(debut, fin) {
  const d = debut ? new Date(debut) : null
  const f = fin ? new Date(fin) : null
  if (!d || Number.isNaN(d.getTime())) return '—'
  const opts = { dateStyle: 'short', timeStyle: 'short' }
  if (!f || Number.isNaN(f.getTime())) return d.toLocaleString('fr-FR', opts)
  return `${d.toLocaleString('fr-FR', opts)} → ${f.toLocaleString('fr-FR', opts)}`
}
