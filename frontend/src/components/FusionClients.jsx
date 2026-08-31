import { useState } from 'react'
import { api } from '../api/client.js'
import Modal from './Modal.jsx'
import ClientPicker from './ClientPicker.jsx'

// FUSIONNER DEUX FICHES CLIENTS — un mécanisme complet auquel il manquait une porte.
//
// ── POURQUOI ÇA ARRIVE, ET POURQUOI ÇA NE SE RÉPARE PAS TOUT SEUL ──────────────────────────────
//
// Le même adhérent inscrit deux fois : une fois par lui-même en ligne, une fois au guichet par un
// agent qui n'a pas trouvé sa fiche. Deux cartes, deux soldes, deux historiques — et le jour où il
// réclame, personne ne sait laquelle fait foi. Tout déploiement réel accumule ces doublons ; aucun
// ne les résorbe sans un geste explicite.
//
// `GET /crm/fusions/previsualiser`, `POST /crm/fusions` et `POST /crm/fusions/{id}/defusionner`
// existaient, testés, appelés par personne.
//
// ── ⚠ TROIS TEMPS, ET LE PREMIER EST CE QUI REND LE GESTE ACCEPTABLE ──────────────────────────
//
// On ne fusionne pas à l'aveugle. On demande d'abord **ce qui diverge**, on tranche champ par
// champ, et seulement ensuite on écrit. Sans cette étape, fusionner reviendrait à écraser des
// données qu'on n'a pas regardées — et l'agent découvrirait la perte à la réclamation suivante.
//
// ⚠ Un champ non tranché garde la valeur du maître. C'est le défaut sûr : l'écran n'impose jamais
// une valeur qu'il n'a pas montrée.
//
// ── ET LE GESTE SE DÉFAIT ──────────────────────────────────────────────────────────────────────
//
// `défusionner` restaure les fiches sources à l'identique. C'est ce qui autorise à fusionner du
// tout : personne de sensé ne fusionnerait irréversiblement les deux fiches d'un client qui
// réclame. Le journal des fusions, plus bas dans l'écran, est le chemin vers ce retour.
export default function FusionClients({ open, onClose, client, onFusionnee }) {
  const [autre, setAutre] = useState(null)
  const [choixOuvert, setChoixOuvert] = useState(false)
  const [maitreEstClient, setMaitreEstClient] = useState(true)
  const [apercu, setApercu] = useState(null)
  const [arbitrages, setArbitrages] = useState({})
  const [motif, setMotif] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  const maitre = maitreEstClient ? client : autre
  const source = maitreEstClient ? autre : client

  function reinitialiser() {
    setAutre(null)
    setApercu(null)
    setArbitrages({})
    setMotif('')
    setErreur(null)
    setMaitreEstClient(true)
  }

  async function previsualiser() {
    if (!maitre || !source) return
    setBusy(true)
    setErreur(null)
    try {
      setApercu(await api.previsualiserFusion(maitre.id, [source.id]))
      setArbitrages({})
    } catch (e) {
      setErreur(e.message || 'L’aperçu n’a pas pu être calculé.')
    } finally {
      setBusy(false)
    }
  }

  async function fusionner() {
    setBusy(true)
    setErreur(null)
    try {
      const journal = await api.fusionnerClients({
        portee: 'client',
        maitre: `/api/clients/${maitre.id}`,
        sources: [`/api/clients/${source.id}`],
        // ⚠ On n'envoie que ce qui a été TRANCHÉ. Un champ absent garde la valeur du maître.
        ...(Object.keys(arbitrages).length ? { champsArbitres: arbitrages } : {}),
        ...(motif.trim() ? { motif: motif.trim() } : {}),
      })
      reinitialiser()
      onFusionnee?.(journal)
      onClose?.()
    } catch (e) {
      setErreur(e.message || 'La fusion n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  const divergents = Object.entries(apercu?.champsDivergents ?? {})

  return (
    <Modal open={open} onClose={onClose} titre="Fusionner deux fiches" taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="field">
        <span className="field-lbl">Fiche à conserver</span>
        <p className="hint">
          La fiche conservée garde son identifiant, ses cartes et son historique. L’autre disparaît
          — et pourra être restaurée depuis le journal des fusions.
        </p>
      </div>

      <div className="resa-part-form">
        <span className="nm">{nomDe(client)}</span>
        {autre ? (
          <>
            <span className="hint">et</span>
            <span className="nm">{nomDe(autre)}</span>
            <button type="button" className="btn ghost sm" onClick={() => { setAutre(null); setApercu(null) }}>
              Changer
            </button>
          </>
        ) : (
          <button type="button" className="btn" onClick={() => setChoixOuvert(true)}>
            Choisir la seconde fiche
          </button>
        )}
      </div>

      {autre && (
        <>
          <div className="field">
            <span className="field-lbl">Laquelle conserver ?</span>
            <div className="resa-part-form">
              <label>
                <input
                  type="radio"
                  name="fusion-maitre"
                  checked={maitreEstClient}
                  onChange={() => { setMaitreEstClient(true); setApercu(null) }}
                />{' '}
                {nomDe(client)}
              </label>
              <label>
                <input
                  type="radio"
                  name="fusion-maitre"
                  checked={!maitreEstClient}
                  onChange={() => { setMaitreEstClient(false); setApercu(null) }}
                />{' '}
                {nomDe(autre)}
              </label>
            </div>
          </div>

          {!apercu && (
            <button type="button" className="btn primary" disabled={busy} onClick={previsualiser}>
              {busy ? 'Comparaison…' : 'Comparer les deux fiches'}
            </button>
          )}
        </>
      )}

      {apercu && (
        <>
          {divergents.length === 0 ? (
            <div className="banner banner-ok">
              Les deux fiches ne se contredisent sur aucun champ : la fusion n’écrase rien.
            </div>
          ) : (
            <>
              {/* ⚠ CHAQUE DIVERGENCE SE TRANCHE, ET LE DÉFAUT EST LA VALEUR DU MAÎTRE. Montrer les
                  deux valeurs côte à côte est tout l'intérêt de l'aperçu : c'est là qu'on voit
                  qu'une fiche porte le bon téléphone et l'autre la bonne adresse. */}
              <p className="hint">
                {divergents.length === 1
                  ? '1 champ diffère entre les deux fiches. Choisissez la valeur à conserver.'
                  : `${divergents.length} champs diffèrent entre les deux fiches. Choisissez les valeurs à conserver.`}
              </p>
              <div className="resa-attente">
                {divergents.map(([champ, valeurs]) => (
                  <div key={champ} className="resa-part">
                    <span className="nm">{champ}</span>
                    <label>
                      <input
                        type="radio"
                        name={`arb-${champ}`}
                        checked={arbitrages[champ] === undefined}
                        onChange={() => setArbitrages((a) => { const n = { ...a }; delete n[champ]; return n })}
                      />{' '}
                      <span className="mono">{affiche(valeurs.maitre)}</span>{' '}
                      <span className="hint">(fiche conservée)</span>
                    </label>
                    {Object.entries(valeurs.sources ?? {}).map(([id, v]) => (
                      <label key={id}>
                        <input
                          type="radio"
                          name={`arb-${champ}`}
                          checked={arbitrages[champ] === v}
                          onChange={() => setArbitrages((a) => ({ ...a, [champ]: v }))}
                        />{' '}
                        <span className="mono">{affiche(v)}</span>{' '}
                        <span className="hint">(fiche absorbée)</span>
                      </label>
                    ))}
                  </div>
                ))}
              </div>
            </>
          )}

          <div className="field">
            <label className="field-lbl" htmlFor="fusion-motif">Pourquoi cette fusion ?</label>
            <input
              id="fusion-motif"
              className="input"
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              placeholder="Doublon créé au guichet le 12/08"
            />
            <p className="hint">
              Inscrit au journal avec votre nom. C’est ce qui permettra, dans six mois, de savoir
              pourquoi deux fiches n’en font plus qu’une.
            </p>
          </div>

          <div className="modal-actions">
            <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
            <button type="button" className="btn primary" disabled={busy} onClick={fusionner}>
              {busy ? 'Fusion…' : 'Fusionner'}
            </button>
          </div>
        </>
      )}

      <ClientPicker
        open={choixOuvert}
        onClose={() => setChoixOuvert(false)}
        titre="Choisir la fiche à fusionner"
        avecCreation={false}
        onSelect={(c) => { setAutre(c); setChoixOuvert(false); setApercu(null) }}
      />
    </Modal>
  )
}

function nomDe(c) {
  if (!c) return '—'
  return c.raisonSociale || [c.prenom, c.nom].filter(Boolean).join(' ') || c.email || c.id?.slice(0, 8) || '—'
}

// Une valeur nulle n'est pas une chaîne vide : sur un écran de fusion, « rien » et « vide » se
// tranchent différemment, et les confondre ferait choisir au hasard.
function affiche(v) {
  if (v === null || v === undefined || v === '') return '(vide)'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}
