import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

// Les casiers de piscine, et les quatre gestes qui les font tourner.
//
// UN CASIER EST UNE RESSOURCE PHYSIQUE : ON LE VOIT, ON NE LE LIT PAS.
//
// Un tableau de casiers avec une colonne « état » se lit ligne à ligne, alors que la question au
// guichet est visuelle : **lesquels sont libres, là, maintenant ?** On affiche donc une grille, comme
// pour les pointures de patins, et la couleur porte l'information avant le texte.
//
// TROIS ÉTATS, ET LE TROISIÈME N'EST PAS UNE NUANCE DU DEUXIÈME.
//
// `libre` et `occupe` sont l'exploitation normale. `non_rendu` veut dire que quelqu'un est parti avec
// la clé : le casier est immobilisé, il ne se libérera pas tout seul, et c'est le seul état qui
// demande une action de relance. Le confondre avec « occupé » ferait attendre un retour qui
// n'arrivera pas.
//
// FORCER L'OUVERTURE EXIGE UN DROIT DISTINCT, ET L'ÉCRAN DIT POURQUOI AVANT DE DIRE À QUI (D54).
//
// `piscine.forcer_casier` est plus fort que `piscine.gerer_casier` — parce que forcer, c'est ouvrir
// le casier de quelqu'un d'autre en son absence. Le motif est obligatoire et enregistré au nom de
// l'agent : c'est ce qu'on lira si le client conteste ce qu'il y avait dedans.

const ETATS = ['libre', 'occupe', 'non_rendu']

export default function CasiersPiscine({ etabActif, droits }) {
  // ⚠ `null` = PAS LU, `[]` = LU ET VIDE. « Aucun casier enregistre » sur une lecture refusee
  // envoie parametrer des casiers qui existent peut-etre deja.
  const [casiersLu, setCasiersLu] = useState(null)
  // ⚠ `null` NE SORT PAS D'ICI. Il dit « pas lu » et rien d'autre ; tout l'aval — y
  // compris ce qui part en prop vers un enfant — lit un tableau. Sans cette ligne il faut
  // trouver chaque usage, et un usage manque ne se signale que par un ecran mort.
  const casiers = casiersLu || []
  const [bracelets, setBracelets] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [attribution, setAttribution] = useState(null)
  const [forcage, setForcage] = useState(null)
  const [zone, setZone] = useState('')

  const peutGerer = aUnDesDroits(droits, ['piscine.gerer_casier', 'piscine.gerer'])
  const peutForcer = aUnDesDroits(droits, ['piscine.forcer_casier', 'piscine.gerer'])

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const [c, b] = await Promise.all([api.piscineCasiers(), api.piscineBracelets()])
      setCasiersLu(membres(c).sort((x, y) => (x.numero || 0) - (y.numero || 0)))
      setBracelets(membres(b))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  const zones = useMemo(
    () => [...new Set(casiers.map((c) => c.zone).filter(Boolean))].sort(),
    [casiers],
  )

  const visibles = zone ? (casiers || []).filter((c) => c.zone === zone) : casiers
  const compte = Object.fromEntries(
    ETATS.map((e) => [e, (casiers || []).filter((c) => c.etat === e).length]),
  )

  async function geste(casier, action, message) {
    setErreur(null)
    try {
      await action(casier.id)
      await recharger()
      setSucces(message)
    } catch (e) {
      setErreur(e.message || "L'opération n'a pas abouti.")
    }
  }

  function cliquer(c) {
    if (!peutGerer) return
    if (c.etat === 'libre') setAttribution(c)
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {compte.non_rendu > 0 && (
        <div className="banner banner-warn">
          <b>{compte.non_rendu} casier{compte.non_rendu > 1 ? 's' : ''} non rendu
          {compte.non_rendu > 1 ? 's' : ''}.</b> Quelqu'un est parti avec la clé : ces casiers sont
          immobilisés et ne se libéreront pas tout seuls. Relancez le porteur, ou forcez l'ouverture
          si le bracelet est perdu.
        </div>
      )}

      <section className="card">
        <div className="card-h">
          <h3>Casiers</h3>
          <span className="sub">
            {compte.libre} libre{compte.libre > 1 ? 's' : ''} · {compte.occupe} occupé
            {compte.occupe > 1 ? 's' : ''}
            {compte.non_rendu > 0 && ` · ${compte.non_rendu} non rendu${compte.non_rendu > 1 ? 's' : ''}`}
          </span>
          {zones.length > 1 && (
            <div className="r" style={{ minWidth: 180 }}>
              <select className="input" value={zone} onChange={(e) => setZone(e.target.value)}>
                <option value="">Toutes les zones</option>
                {zones.map((z) => (
                  <option key={z} value={z}>{z}</option>
                ))}
              </select>
            </div>
          )}
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
          ) : (casiers || []).length === 0 ? (
            <div className="empty">
              {casiersLu === null ? (
                <b>La liste des casiers n’a pas pu être lue. Cette liste est vide parce que la lecture a échoué, pas parce qu’il n’y a rien.</b>
              ) : (
                <>
                  Aucun casier enregistré. Les casiers se créent dans le paramétrage de la piscine,
                  avec leur numéro et leur zone — c'est ce numéro que le nageur retiendra.
                </>
              )}
            </div>
          ) : (
            <>
              {peutGerer && (
                <div className="hint" style={{ marginTop: 0, marginBottom: 10 }}>
                  Cliquez un casier libre pour l'attribuer à un bracelet. Les casiers occupés portent
                  leurs propres gestes.
                </div>
              )}
              <div className="pat-parc">
                {visibles.map((c) => (
                  <div key={c.id} className={`cas-tuile ${c.etat}`}>
                    <button
                      type="button"
                      className="cas-num"
                      disabled={!peutGerer || c.etat !== 'libre'}
                      onClick={() => cliquer(c)}
                      title={
                        c.etat === 'libre'
                          ? 'Attribuer ce casier'
                          : c.etat === 'non_rendu'
                            ? 'Clé non rendue : relancer ou forcer'
                            : 'Casier occupé'
                      }
                    >
                      {c.numero}
                    </button>
                    <span className="cas-etat">{mot(c.etat)}</span>
                    {c.zone && <span className="cas-zone">{c.zone}</span>}
                    {peutGerer && c.etat !== 'libre' && (
                      <div className="cas-actes">
                        <button
                          className="btn ghost sm"
                          type="button"
                          onClick={() => geste(c, api.piscineLibererCasier, `Casier ${c.numero} libéré.`)}
                        >
                          Libérer
                        </button>
                        {c.etat === 'non_rendu' && (
                          <button
                            className="btn ghost sm"
                            type="button"
                            onClick={() => geste(c, api.piscineRelancerCasier, `Relance envoyée pour le casier ${c.numero}.`)}
                          >
                            Relancer
                          </button>
                        )}
                        {peutForcer && (
                          <button className="btn ghost sm" type="button" onClick={() => setForcage(c)}>
                            Forcer
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                ))}
              </div>
              {!peutForcer && compte.non_rendu > 0 && (
                <div className="hint">
                  Forcer l'ouverture d'un casier demande un droit distinct de la gestion courante :
                  c'est ouvrir le casier de quelqu'un d'autre en son absence.
                </div>
              )}
            </>
          )}
        </div>
      </section>

      <AttributionModal
        casier={attribution}
        bracelets={bracelets}
        onClose={() => setAttribution(null)}
        onFait={(m) => { setAttribution(null); setSucces(m); recharger() }}
        onErreur={setErreur}
      />

      <ForcageCasierModal
        casier={forcage}
        onClose={() => setForcage(null)}
        onFait={(m) => { setForcage(null); setSucces(m); recharger() }}
        onErreur={setErreur}
      />
    </>
  )
}

function AttributionModal({ casier, bracelets, onClose, onFait, onErreur }) {
  const [bracelet, setBracelet] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (casier) setBracelet('')
  }, [casier])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.piscineAttribuerCasier(casier.id, { bracelet })
      onFait(`Casier ${casier.numero} attribué.`)
    } catch (err) {
      onErreur(err.message || "L'attribution n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!casier} onClose={onClose} titre={casier ? `Attribuer le casier ${casier.numero}` : ''}>
      {casier && (
        <form onSubmit={envoyer}>
          <div className="field">
            <label htmlFor="cs-bracelet">Bracelet *</label>
            <select id="cs-bracelet" className="input" required value={bracelet} onChange={(e) => setBracelet(e.target.value)}>
              <option value="">Choisir…</option>
              {bracelets.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.support?.identifiantSupport || `Bracelet ${String(b.id).slice(0, 8)}`}
                </option>
              ))}
            </select>
            <div className="hint">
              {bracelets.length === 0
                ? "Aucun bracelet n'est enregistré : le casier ne peut pas être attribué sans support."
                : "C'est le bracelet qui ouvrira le casier. Le nageur n'a que son numéro à retenir."}
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !bracelet}>
              {enCours ? 'Attribution…' : 'Attribuer'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

function ForcageCasierModal({ casier, onClose, onFait, onErreur }) {
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (casier) setMotif('')
  }, [casier])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.piscineForcerCasier(casier.id, motif.trim())
      onFait(`Casier ${casier.numero} forcé.`)
    } catch (err) {
      onErreur(err.message || "Le forçage n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!casier} onClose={onClose} titre={casier ? `Forcer le casier ${casier.numero}` : ''}>
      {casier && (
        <form onSubmit={envoyer}>
          <div className="banner banner-warn">
            <b>Vous allez ouvrir le casier de quelqu'un d'autre, en son absence.</b> Le contenu vous
            engage : c'est ce motif qu'on relira si le client conteste ce qu'il y avait dedans.
          </div>

          <div className="field">
            <label htmlFor="cs-motif">Pourquoi forcez-vous ce casier ? *</label>
            <textarea
              id="cs-motif"
              className="input"
              rows={3}
              required
              value={motif}
              placeholder="Bracelet perdu, client présent au guichet avec sa pièce d'identité."
              onChange={(e) => setMotif(e.target.value)}
            />
            <div className="hint">Obligatoire, enregistré à votre nom et daté.</div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !motif.trim()}>
              {enCours ? 'Ouverture…' : 'Forcer l’ouverture'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}
