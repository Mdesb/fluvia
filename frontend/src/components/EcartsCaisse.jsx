import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { dateHeureFr } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'

// Les écarts de caisse, et le geste qui les explique.
//
// ON PART DE L'ÉCART, PAS DE LA VENTE.
//
// La correction de règlement s'applique à une vente. Le chemin naturel serait donc de chercher la
// vente et de la corriger — mais **ce chemin n'arrive jamais dans une vraie caisse** : il suppose
// qu'on se souvienne d'un écart en regardant une vente, des heures après la clôture.
//
// On part donc de ce qu'on ne comprend pas — l'écart du Z — et on descend vers les ventes de CETTE
// session. C'est le seul cadre où retrouver la vente mal ventilée est possible : sans ce filtre, on
// cherche une aiguille dans l'historique entier.
//
// UNE LISTE QUI NE DESCEND PAS À ZÉRO EST DU DÉCOR (D55).
//
// C'est la raison d'être de cet écran. La liste des écarts existait côté serveur depuis le début, en
// lecture seule, et rien ne permettait d'en clore un. Un écart expliqué cesse d'être un écart : la
// correction retient l'alerte qu'elle explique, et la ligne quitte la liste.
//
// CE QUE LE SERVEUR IMPOSE, ET QUE L'ÉCRAN DIT AVANT L'ENVOI.
//
// - **Seule une vente validée se corrige.** Sur un panier en cours il n'y a rien de scellé ; le
//   règlement se reprend directement. On ne propose donc que des ventes validées.
// - **Le motif est obligatoire.** Un geste qui déplace de l'argent doit rester défendable en
//   contrôle.
// - **On ne sort pas d'un moyen plus que ce qui y reste**, net des corrections déjà passées. Le
//   serveur répond 422 avec le montant disponible : on l'affiche tel quel, c'est lui qui sait.
// - **`vente.corriger_reglement` est distinct de `caisse.gerer`** (D54) : qui peut sortir des espèces
//   vers la carte peut masquer un manquant.
//
// ET CE QU'IL FAUT DIRE SUR LE Z, SANS QUOI ON CONCLURA À UN BOGUE.
//
// La correction est **datée du jour du geste**. Le Z d'hier garde donc sa ventilation fausse — ce
// n'est pas un défaut à cacher, c'est ce qui rend un Z digne de foi. Un exploitant qui corrige puis
// retourne voir son Z d'hier doit lire cette phrase, sinon il conclura que le logiciel n'a rien fait.

export default function EcartsCaisse({ etabActif, droits }) {
  // ⚠ `null` = PAS LU, `[]` = LU ET VIDE. « Aucun ecart inexplique » sur une lecture refusee
  // annonce une caisse saine qu'on n'a pas regardee.
  const [alertesLu, setAlertesLu] = useState(null)
  // ⚠ `null` NE SORT PAS D'ICI. Il dit « pas lu » et rien d'autre ; tout l'aval — y
  // compris ce qui part en prop vers un enfant — lit un tableau. Sans cette ligne il faut
  // trouver chaque usage, et un usage manque ne se signale que par un ecran mort.
  const alertes = alertesLu || []
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [explication, setExplication] = useState(null)

  const peutVoir = aLeDroit(droits, 'caisse.voir_ecart')
  const peutCorriger = aLeDroit(droits, 'vente.corriger_reglement')

  const recharger = useCallback(async () => {
    if (!peutVoir) return
    setChargement(true)
    try {
      setAlertesLu(membres(await api.alertesEcartCaisse()))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif, peutVoir])

  useEffect(() => {
    recharger()
  }, [recharger])

  if (!peutVoir) return null

  // `expliquee` — et pas `estExpliquee`, que j'avais demandé : le sérialiseur n'accepte les groupes
  // que sur des méthodes préfixées `get`, `is`, `has`, `can` ou `set`, donc `isExpliquee()`, donc un
  // champ nommé `expliquee`. `claude-G` me l'a dit avant que je construise dessus, ce qui m'a évité
  // une liste qui ne serait jamais descendue en silence.
  //
  // Rien n'est stocké côté serveur : c'est un fait constaté à la lecture, calculé en une requête
  // pour toute la page. L'alerte reste l'entité immuable qu'elle déclare être, et il n'y a pas de
  // drapeau à maintenir — donc pas de drapeau qu'on oublie de maintenir.
  const ouvertes = (alertes || []).filter((a) => !a.expliquee)
  const closes = (alertes || []).filter((a) => a.expliquee)

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Écarts de caisse</h3>
        <span className="sub">
          {ouvertes.length === 0 ? 'aucun écart inexpliqué' : `${ouvertes.length} à expliquer`}
        </span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {chargement ? (
          <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
        ) : ouvertes.length === 0 ? (
          <div className="empty">
            {alertesLu === null ? <b>La liste des écarts n’a pas pu être lue : elle est vide parce que la lecture a échoué, pas parce qu’il n’y a rien.</b> : <>
            Aucun écart inexpliqué. Une clôture dont le comptage ne tombe pas juste apparaît ici, et
            en repart dès qu'une correction de règlement dit ce qui s'est passé.
            </>}
            {closes.length > 0 && (
              <div style={{ marginTop: 8 }}>
                {closes.length} écart{closes.length > 1 ? 's ont' : ' a'} été expliqué
                {closes.length > 1 ? 's' : ''}.
              </div>
            )}
          </div>
        ) : (
          <>
            {!peutCorriger && (
              <div className="banner banner-warn">
                Vous voyez les écarts mais ne pouvez pas les corriger. Déplacer un règlement d'un moyen
                de paiement vers un autre demande le droit « corriger un règlement », distinct de la
                gestion de caisse — parce que qui peut sortir des espèces vers la carte peut masquer un
                manquant.
              </div>
            )}
            <table className="tbl">
              <thead>
                <tr>
                  <th>Clôture</th>
                  <th className="num">Écart</th>
                  <th className="num">Tolérance</th>
                  <th>Constaté le</th>
                  {peutCorriger && <th />}
                </tr>
              </thead>
              <tbody>
                {ouvertes.map((a) => {
                  const ecart = parseFloat(a.ecartMontant) || 0
                  return (
                    <tr key={a.id}>
                      <td>
                        <span className="mono">{String(a.cloture || '').split('/').pop().slice(0, 8) || '—'}</span>
                      </td>
                      <td className="num">
                        <span className={`badge ${Math.abs(ecart) > 0 ? 'crit' : 'mut'}`}>
                          {ecart > 0 ? `+${euros(a.ecartMontant)}` : euros(a.ecartMontant)}
                        </span>
                      </td>
                      <td className="num">{euros(a.toleranceAppliquee)}</td>
                      <td>{dateHeureFr(a.horodatage)}</td>
                      {peutCorriger && (
                        <td className="num">
                          <button
                            className="btn primary sm"
                            type="button"
                            onClick={() => setExplication(a)}
                          >
                            Expliquer
                          </button>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
            <div className="hint">
              Un écart se corrige en disant d'où l'argent est réellement venu : on retire le montant du
              moyen de paiement saisi par erreur et on le porte sur le bon. La vente elle-même n'est
              jamais modifiée.
            </div>
          </>
        )}
      </div>

      <ExplicationModal
        alerte={explication}
        onClose={() => setExplication(null)}
        onFait={(m) => {
          setExplication(null)
          setSucces(m)
          setErreur(null)
          recharger()
        }}
        onErreur={setErreur}
      />
    </section>
  )
}

function ExplicationModal({ alerte, onClose, onFait, onErreur }) {
  const [ventes, setVentes] = useState([])
  const [moyens, setMoyens] = useState([])
  const [vente, setVente] = useState('')
  const [debite, setDebite] = useState('')
  const [credite, setCredite] = useState('')
  const [montant, setMontant] = useState('')
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [chargement, setChargement] = useState(false)

  useEffect(() => {
    if (!alerte) return
    setVente('')
    setDebite('')
    setCredite('')
    setMontant(String(Math.abs(parseFloat(alerte.ecartMontant) || 0).toFixed(2)))
    setMotif('')
    setChargement(true)

    // Les ventes de CETTE session : sans ce filtre, on cherche dans l'historique entier une vente
    // dont on ne sait rien sinon qu'elle a été mal ventilée.
    const idSession = String(alerte.session || '').split('/').pop()
    Promise.all([
      api.ventes({ itemsPerPage: 200, statut: 'validee', ...(idSession ? { session: idSession } : {}) }),
      api.moyensPaiement(),
    ])
      .then(([v, m]) => {
        setVentes(membres(v))
        setMoyens(membres(m))
      })
      .catch(() => {
        setVentes([])
        setMoyens([])
      })
      .finally(() => setChargement(false))
  }, [alerte])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.corrigerReglement(vente, {
        moyenDebite: debite,
        moyenCredite: credite,
        montant,
        motif: motif.trim(),
        alerteEcart: alerte.id,
      })
      onFait("Écart expliqué. La correction est datée d'aujourd'hui : le Z concerné garde sa ventilation d'origine.")
    } catch (err) {
      // Le message du serveur est affiché tel quel : c'est lui qui connaît le montant encore
      // disponible sur le moyen, et le reformuler ferait diverger le diagnostic de la réalité.
      onErreur(err.message || "La correction n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  const memeMoyen = debite !== '' && debite === credite

  return (
    <Modal open={!!alerte} onClose={onClose} titre="Expliquer un écart de caisse" taille="lg">
      {alerte && (
        <form onSubmit={envoyer}>
          <p style={{ marginTop: 0 }}>
            Écart de <b>{euros(alerte.ecartMontant)}</b> constaté le {dateHeureFr(alerte.horodatage)}.
            Dites d'où l'argent est réellement venu : on retire le montant du moyen saisi par erreur et
            on le porte sur le bon.
          </p>

          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : (
            <>
              <div className="field">
                <label htmlFor="ec-vente">Vente concernée *</label>
                <select id="ec-vente" className="input" required value={vente} onChange={(e) => setVente(e.target.value)}>
                  <option value="">Choisir…</option>
                  {ventes.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.numero || String(v.id).slice(0, 8)} — {euros(v.totalTTC ?? v.total ?? '0')}
                      {v.date ? ` — ${dateHeureFr(v.date)}` : ''}
                    </option>
                  ))}
                </select>
                <div className="hint">
                  {ventes.length === 0
                    ? "Aucune vente validée n'a été trouvée pour cette session de caisse."
                    : 'Seules les ventes validées de la session concernée sont proposées : un panier en cours n’a rien de scellé, son règlement se reprend directement.'}
                </div>
              </div>

              <div className="grid" style={{ gridTemplateColumns: '1fr 1fr 1fr', gap: 10 }}>
                <div className="field" style={{ margin: 0 }}>
                  <label htmlFor="ec-deb">Moyen saisi par erreur *</label>
                  <select id="ec-deb" className="input" required value={debite} onChange={(e) => setDebite(e.target.value)}>
                    <option value="">Choisir…</option>
                    {moyens.map((m) => (
                      <option key={m.id || m.code} value={m.code}>{m.libelle || m.code}</option>
                    ))}
                  </select>
                </div>
                <div className="field" style={{ margin: 0 }}>
                  <label htmlFor="ec-cred">Moyen réellement utilisé *</label>
                  <select id="ec-cred" className="input" required value={credite} onChange={(e) => setCredite(e.target.value)}>
                    <option value="">Choisir…</option>
                    {moyens.map((m) => (
                      <option key={m.id || m.code} value={m.code}>{m.libelle || m.code}</option>
                    ))}
                  </select>
                </div>
                <div className="field" style={{ margin: 0 }}>
                  <label htmlFor="ec-montant">Montant *</label>
                  <input
                    id="ec-montant"
                    className="input"
                    type="number"
                    step="0.01"
                    min="0.01"
                    required
                    value={montant}
                    onChange={(e) => setMontant(e.target.value)}
                  />
                </div>
              </div>

              {memeMoyen && (
                <div className="banner banner-warn">
                  Les deux moyens sont identiques : il n'y a rien à corriger.
                </div>
              )}

              <div className="field">
                <label htmlFor="ec-motif">Ce qui s'est passé *</label>
                <textarea
                  id="ec-motif"
                  className="input"
                  rows={2}
                  required
                  value={motif}
                  placeholder="Réglé en carte, saisi en espèces par erreur à 16 h 05."
                  onChange={(e) => setMotif(e.target.value)}
                />
                <div className="hint">
                  Obligatoire. Un geste qui déplace de l'argent d'un moyen à un autre doit rester
                  défendable devant un contrôle, des mois plus tard.
                </div>
              </div>

              <div className="banner banner-warn">
                <b>La correction est datée d'aujourd'hui.</b> Le Z de la journée concernée garde sa
                ventilation d'origine — ce n'est pas un oubli, c'est ce qui rend un Z digne de foi. La
                correction apparaîtra sur celui d'aujourd'hui.
              </div>
            </>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button
              className="btn primary"
              type="submit"
              disabled={enCours || chargement || !vente || !debite || !credite || memeMoyen || !motif.trim()}
            >
              {enCours ? 'Enregistrement…' : "Enregistrer l'explication"}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}
