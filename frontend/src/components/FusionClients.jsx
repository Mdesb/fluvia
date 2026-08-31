import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import ClientPicker from './ClientPicker.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { dateHeureFr } from './Liste.jsx'

/**
 * FUSIONNER DEUX FICHES CLIENT — et pouvoir revenir en arrière.
 *
 * Trois opérations servies, zéro appel jusqu'ici. Tout déploiement réel accumule des doublons :
 * la même personne saisie au guichet, puis en ligne, puis par un collègue qui n'a pas trouvé la
 * première fiche. Deux fiches pour un client, c'est deux historiques, deux soldes de
 * porte-monnaie et deux abonnements qu'on ne voit jamais ensemble.
 *
 * ── CE QUE LE SERVEUR OFFRE, ET QUI EST RARE DANS CE DÉPÔT ──────────────────────────────────────
 *
 * Une PRÉVISUALISATION — on voit ce qui diverge avant de décider — et une DÉFUSION. Écrire une
 * défusion coûte cher ; sa présence dit que le geste a été pensé comme réversible, dans un dépôt
 * où presque toutes les écritures sont définitives. L'écran s'appuie sur les deux plutôt que de
 * demander une confirmation par « êtes-vous sûr ? », qui ne fait que déplacer la responsabilité.
 *
 * ── ARBITRER, PAS ÉCRASER ───────────────────────────────────────────────────────────────────────
 *
 * La prévisualisation rend, champ par champ, la valeur de la fiche qui survit et celle de chaque
 * fiche absorbée. Sans arbitrage, la fusion garde la valeur de la survivante — donc un e-mail
 * renseigné d'un côté et vide de l'autre peut DISPARAÎTRE si la survivante est celle qui n'en a
 * pas. L'écran montre les deux et laisse choisir, parce que c'est précisément là que se perd
 * l'information.
 */
export default function FusionClients({ droits = [], onFusionFaite }) {
  const [journaux, setJournaux] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [busy, setBusy] = useState(false)
  const [fusionOuverte, setFusionOuverte] = useState(false)

  const peutFusionner = aLeDroit(droits, 'crm.fusionner')

  const recharger = useCallback(async () => {
    if (!peutFusionner) return
    try {
      setJournaux(membres(await api.fusions()))
    } catch (e) {
      // ⚠ `null` = PAS LU. « Aucune fusion » et « je n'ai pas pu lire l'historique » sont deux
      // choses opposées quand la question est « cette fiche a-t-elle déjà été fusionnée ? ».
      setJournaux(null)
      setErreur(e.message || 'L’historique des fusions n’a pas pu être lu.')
    }
  }, [peutFusionner])

  useEffect(() => { recharger() }, [recharger])

  if (!peutFusionner) return null

  const actives = (journaux || []).filter((j) => j.statut === 'active')

  return (
    <div>
      <div className="fiche-sec">Fusion de fiches</div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <div className="hint" style={{ marginTop: 0 }}>
        Deux fiches pour la même personne, c’est deux historiques et deux soldes. La fusion les
        réunit sur une fiche survivante — et se défait si l’on s’est trompé.
      </div>

      <button className="btn sm" type="button" onClick={() => setFusionOuverte(true)}>
        Fusionner deux fiches
      </button>

      {journaux === null ? (
        <div className="banner banner-error">
          L’historique des fusions n’a pas pu être lu. <b>N’en concluez pas qu’aucune fusion n’a
          eu lieu</b> : cette liste n’a pas été obtenue.
        </div>
      ) : actives.length === 0 ? (
        <div className="empty">Aucune fusion en vigueur.</div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Quand</th>
                <th>Portée</th>
                <th className="num">Fiches absorbées</th>
                <th>Motif</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {actives.map((j) => (
                <tr key={j.id}>
                  <td>{dateHeureFr(j.dateFusion)}</td>
                  <td>{j.portee === 'famille' ? 'Famille' : 'Client'}</td>
                  <td className="num">{(j.fichesSources || []).length}</td>
                  <td>{j.motif || <span className="sub">—</span>}</td>
                  <td className="num">
                    <button
                      className="btn sm"
                      type="button"
                      disabled={busy}
                      title="Rétablit les fiches absorbées. La fusion reste au journal, marquée défusionnée."
                      onClick={async () => {
                        setBusy(true)
                        setErreur(null)
                        setSucces(null)
                        try {
                          await api.defusionner(j.id)
                          setSucces('Fusion défaite : les fiches absorbées sont rétablies.')
                          await recharger()
                          onFusionFaite?.()
                        } catch (e) {
                          setErreur(e.message || 'La défusion a échoué.')
                        } finally {
                          setBusy(false)
                        }
                      }}
                    >
                      Défusionner
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <FusionModal
        open={fusionOuverte}
        onClose={() => setFusionOuverte(false)}
        onFait={(m) => {
          setFusionOuverte(false)
          setSucces(m)
          recharger()
          onFusionFaite?.()
        }}
      />
    </div>
  )
}

function FusionModal({ open, onClose, onFait }) {
  const [survivante, setSurvivante] = useState(null)
  const [absorbee, setAbsorbee] = useState(null)
  const [pickerPour, setPickerPour] = useState(null) // 'survivante' | 'absorbee'
  const [apercu, setApercu] = useState(null)
  const [arbitrages, setArbitrages] = useState({})
  const [motif, setMotif] = useState('')
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    setSurvivante(null)
    setAbsorbee(null)
    setApercu(null)
    setArbitrages({})
    setMotif('')
    setErreur(null)
  }, [open])

  // La prévisualisation se relance dès que le couple change : on ne décide jamais sur un aperçu
  // qui décrit un autre couple que celui affiché.
  useEffect(() => {
    setApercu(null)
    setArbitrages({})
    if (!survivante?.id || !absorbee?.id) return undefined
    let annule = false
    setBusy(true)
    setErreur(null)
    api.previsualiserFusion(survivante.id, [absorbee.id])
      .then((r) => { if (!annule) setApercu(r) })
      .catch((e) => { if (!annule) setErreur(e.message || 'La prévisualisation a échoué.') })
      .finally(() => { if (!annule) setBusy(false) })
    return () => { annule = true }
  }, [survivante?.id, absorbee?.id])

  const divergents = apercu?.champsDivergents || {}
  const memeFiche = survivante?.id && absorbee?.id && survivante.id === absorbee.id

  async function fusionner() {
    setErreur(null)
    setBusy(true)
    try {
      // ⚠ ICI CE SONT DES IRI, alors que la prévisualisation prenait des UUID. Les deux formes
      // dans le même geste : c'est le serveur qui en décide, l'écran s'y plie.
      await api.fusionnerClients({
        portee: 'client',
        maitre: `/api/clients/${survivante.id}`,
        sources: [`/api/clients/${absorbee.id}`],
        ...(Object.keys(arbitrages).length ? { champsArbitres: arbitrages } : {}),
        ...(motif.trim() ? { motif: motif.trim() } : {}),
      })
      onFait('Fiches fusionnées.')
    } catch (e) {
      setErreur(e.message || 'La fusion a échoué.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Fusionner deux fiches" taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="hint" style={{ marginTop: 0 }}>
        La <b>fiche survivante</b> est celle qui reste. L’autre est absorbée : son historique, ses
        passages et ses règlements sont rattachés à la survivante.
      </div>

      <div className="field">
        <label>Fiche survivante *</label>
        <div className="r">
          <span>{survivante ? nomClient(survivante) : <span className="sub">aucune choisie</span>}</span>
          <button className="btn sm" type="button" onClick={() => setPickerPour('survivante')}>
            {survivante ? 'Changer' : 'Choisir'}
          </button>
        </div>
      </div>

      <div className="field">
        <label>Fiche absorbée *</label>
        <div className="r">
          <span>{absorbee ? nomClient(absorbee) : <span className="sub">aucune choisie</span>}</span>
          <button className="btn sm" type="button" onClick={() => setPickerPour('absorbee')}>
            {absorbee ? 'Changer' : 'Choisir'}
          </button>
        </div>
      </div>

      {memeFiche && (
        <div className="banner banner-error">
          Les deux fiches choisies sont la même. Choisissez le doublon à absorber.
        </div>
      )}

      {busy && !apercu && !memeFiche && survivante && absorbee && (
        <div className="center"><div className="spinner" /></div>
      )}

      {apercu && !memeFiche && (
        <>
          <div className="fiche-sec">Ce qui diverge</div>
          {Object.keys(divergents).length === 0 ? (
            <div className="empty">
              Aucun champ ne diverge : la fusion ne fera perdre aucune information.
            </div>
          ) : (
            <>
              <div className="hint" style={{ marginTop: 0 }}>
                ⚠ Sans choix de votre part, la <b>valeur de la survivante</b> est conservée — y
                compris quand elle est vide. Cochez la valeur à garder pour chaque champ.
              </div>
              <table className="tbl">
                <thead>
                  <tr><th>Champ</th><th>Fiche survivante</th><th>Fiche absorbée</th></tr>
                </thead>
                <tbody>
                  {Object.entries(divergents).map(([champ, v]) => {
                    const valeurAbsorbee = Object.values(v.sources || {})[0]
                    const choisi = arbitrages[champ]
                    return (
                      <tr key={champ}>
                        <td><span className="nm">{champ}</span></td>
                        <td>
                          <label className="bq-check">
                            <input
                              type="radio"
                              name={`arb-${champ}`}
                              checked={choisi === undefined || choisi === v.maitre}
                              onChange={() => setArbitrages((a) => {
                                const c = { ...a }
                                delete c[champ]
                                return c
                              })}
                            />
                            <span>{valeurOuVide(v.maitre)}</span>
                          </label>
                        </td>
                        <td>
                          <label className="bq-check">
                            <input
                              type="radio"
                              name={`arb-${champ}`}
                              checked={choisi !== undefined && choisi === valeurAbsorbee}
                              onChange={() => setArbitrages((a) => ({ ...a, [champ]: valeurAbsorbee }))}
                            />
                            <span>{valeurOuVide(valeurAbsorbee)}</span>
                          </label>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </>
          )}

          <div className="field">
            <label htmlFor="fus-motif">Motif</label>
            <input
              id="fus-motif"
              className="input"
              value={motif}
              maxLength={200}
              placeholder="Doublon créé au guichet le 12/08"
              onChange={(e) => setMotif(e.target.value)}
            />
            <span className="hint">
              Facultatif, et pourtant utile : c’est ce qui permettra de comprendre la fusion dans
              six mois, quand personne ne se souviendra du doublon.
            </span>
          </div>
        </>
      )}

      <div className="r">
        <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
        <button
          type="button"
          className="btn primary"
          disabled={busy || !apercu || Boolean(memeFiche)}
          onClick={fusionner}
        >
          {busy ? 'Fusion…' : 'Fusionner'}
        </button>
      </div>

      <ClientPicker
        open={pickerPour !== null}
        titre={pickerPour === 'survivante' ? 'Choisir la fiche survivante' : 'Choisir la fiche à absorber'}
        avecCreation={false}
        onClose={() => setPickerPour(null)}
        onSelect={(c) => {
          if (pickerPour === 'survivante') setSurvivante(c)
          else setAbsorbee(c)
          setPickerPour(null)
        }}
      />
    </Modal>
  )
}

function nomClient(c) {
  return [c.prenom, c.nom].filter(Boolean).join(' ') || c.raisonSociale || c.id
}

// Une valeur vide se DIT, elle ne se rend pas par une cellule blanche : c'est justement le cas
// où l'arbitrage compte, puisque garder la survivante ferait perdre la donnée de l'autre.
function valeurOuVide(v) {
  if (v === null || v === undefined || v === '') return <span className="sub">(vide)</span>
  return String(v)
}
