import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import Modal from './Modal.jsx'

/**
 * LES RETRAITS CLICK & COLLECT — une commande payée en ligne que personne ne pouvait remettre.
 *
 * `GET /retrait_click_collects` et `POST /boutique/retraits/{id}/valider` existent depuis
 * l'origine ; aucune fonction cliente ne les appelait. Un client payait en ligne, se présentait au
 * comptoir, et l'agent n'avait aucun écran pour dire « je vous l'ai remise ».
 *
 * ⚠ CET ÉCRAN N'AFFICHE PAS LE CODE DE RETRAIT, ET C'EST TOUT L'ENJEU.
 *
 * Le serveur compare le code présenté avec `hash_equals` — une comparaison en temps constant, celle
 * qu'on réserve aux secrets. Le code est donc conçu comme une preuve : le client le présente,
 * l'agent le saisit, le serveur tranche.
 *
 * Un écran qui l'afficherait détruirait ce contrôle sans rien casser de visible : l'agent lirait le
 * code au lieu de le demander, et n'importe qui pourrait repartir avec la commande d'un autre. Le
 * contrôle resterait vert, la protection aurait disparu.
 *
 * ⚠ ELLE EST DÉJÀ ENTAMÉE, ET CE N'EST PAS RÉPARABLE ICI : `codeRetrait` est dans le groupe
 * `retrait:read`. L'API le renvoie donc à quiconque peut lire la collection. Ne pas l'afficher est
 * nécessaire et insuffisant — le vrai correctif est de le retirer du groupe de lecture, côté
 * serveur. Signalé.
 */
export default function RetraitsClickCollect({ etabActif, droits = [] }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [retraits, setRetraits] = useState(null)
  // ⚠ `pointRetrait` ARRIVE EN IDENTIFIANT NU. `Espace::$nom` n'est pas dans `retrait:read` : la
  // relation n'est pas sérialisée, et lire `pointRetrait.nom` vaut `undefined` pour tout le monde.
  // On résout donc le nom depuis `/api/espaces`, qui l'expose. `undefined` = catalogue non lu, à ne
  // pas confondre avec « espace inconnu ».
  const [espaces, setEspaces] = useState(null)
  const [aRemettre, setARemettre] = useState(null)
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  const peutRemettre = aUnDesDroits(droits, ['boutique.traiter_retrait', 'acces.controler'])

  function charger() {
    setRetraits(null)
    api.retraitsClickCollect()
      .then((r) => setRetraits(membres(r)))
      .catch(() => setRetraits(undefined))
  }

  useEffect(charger, [etabActif])

  useEffect(() => {
    api.espaces()
      .then((r) => setEspaces(membres(r)))
      .catch(() => setEspaces(undefined))
  }, [etabActif])

  function nomEspace(ref) {
    if (!ref) return null
    const id = typeof ref === 'string' ? String(ref).split('/').pop() : String(ref.id || '')
    if (!Array.isArray(espaces)) return undefined
    const e = espaces.find((x) => String(x.id) === id)
    return e ? e.nom : null
  }

  const attente = Array.isArray(retraits) ? retraits.filter((r) => r.statut === 'a_retirer') : []
  const remis = Array.isArray(retraits) ? retraits.filter((r) => r.statut !== 'a_retirer') : []

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Retraits en boutique</h3>
        <span className="sub">
          {retraits === null
            ? 'lecture…'
            : retraits === undefined
              ? 'illisible'
              : `${attente.length} en attente`}
        </span>
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {retraits === undefined && (
          <div className="banner banner-warn">
            Les retraits n’ont pas pu être lus. Un client attend peut-être sa commande&nbsp;: cet
            écran ne le sait pas.
          </div>
        )}

        {retraits === null && <div className="empty">Lecture des retraits…</div>}

        {Array.isArray(retraits) && retraits.length === 0 && (
          <div className="empty">
            Aucun retrait. Les commandes payées en ligne à retirer sur place apparaîtront ici.
          </div>
        )}

        {attente.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Commande</th>
                  <th>Point de retrait</th>
                  <th>État</th>
                  {peutRemettre && <th />}
                </tr>
              </thead>
              <tbody>
                {attente.map((r) => (
                  <tr key={r.id}>
                    <td>
                      {/* ⚠ ON MONTRE LA RÉFÉRENCE, PAS LE CODE. La référence identifie la commande
                          pour l'agent ; le code prouve que le porteur est le bon. Les confondre,
                          c'est afficher la réponse à côté de la question. */}
                      <span className="mono">{String(r.id || '').slice(0, 8)}</span>
                    </td>
                    <td>
                      {/* Trois cas, et le deuxième n'est pas le troisième : « catalogue non lu »
                          veut dire qu'on n'a pas su regarder ; « espace inconnu » veut dire qu'on a
                          regardé et que la référence ne désigne rien. */}
                      {!r.pointRetrait
                        ? <span className="sub">—</span>
                        : nomEspace(r.pointRetrait) === undefined
                          ? <span className="sub">espaces non lus</span>
                          : nomEspace(r.pointRetrait) || <span className="sub">espace inconnu</span>}
                    </td>
                    <td><span className="badge warn">à retirer</span></td>
                    {peutRemettre && (
                      <td>
                        <button
                          className="btn primary sm"
                          type="button"
                          onClick={() => { setARemettre(r); setErreur(null); setSucces(null) }}
                        >
                          Remettre la commande
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {remis.length > 0 && (
          <div className="sub" style={{ marginTop: 'var(--esp-normal)' }}>
            {remis.length} commande{remis.length > 1 ? 's' : ''} déjà remise{remis.length > 1 ? 's' : ''}.
          </div>
        )}
      </div>

      <RemiseRetrait
        retrait={aRemettre}
        onFermer={() => setARemettre(null)}
        onFait={(message) => { setARemettre(null); setSucces(message); charger() }}
        onErreur={setErreur}
      />
    </section>
  )
}

/**
 * ⚠ LE CODE SE DEMANDE AU CLIENT, IL NE SE LIT PAS À L'ÉCRAN.
 *
 * La fenêtre le dit à l'agent, parce qu'un champ vide sans explication invite à chercher la valeur
 * ailleurs — et elle est à portée de clic dans la réponse de l'API. Nommer la raison est ce qui
 * transforme une contrainte en geste compris.
 *
 * Le serveur refuse un code faux par un 422 explicite. On l'affiche tel quel : « code invalide »
 * est exactement ce que l'agent doit lire, et l'inventer autrement le ferait douter de l'écran
 * plutôt que du code.
 */
function RemiseRetrait({ retrait, onFermer, onFait, onErreur }) {
  const [code, setCode] = useState('')
  const [support, setSupport] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => { setCode(''); setSupport('') }, [retrait])

  async function remettre(e) {
    e.preventDefault()
    setBusy(true)
    onErreur(null)
    try {
      const corps = { codeRetrait: code.trim() }
      if (support.trim() !== '') corps.identifiantSupportPhysique = support.trim()
      await api.validerRetraitClickCollect(retrait.id, corps)
      onFait('Commande remise.')
    } catch (err) {
      onErreur(err.message || 'La remise n’a pas pu être enregistrée.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={!!retrait} onClose={onFermer} titre="Remettre la commande" taille="sm">
      <form onSubmit={remettre} style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div className="sub">
          Demandez au client le code reçu à la confirmation de sa commande. C’est ce code qui prouve
          qu’il est bien le destinataire&nbsp;— il ne s’affiche pas ici, exprès.
        </div>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Code présenté par le client</span>
          <input
            className="input"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            autoComplete="off"
            maxLength={64}
          />
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Support physique remis — facultatif</span>
          <input
            className="input"
            value={support}
            onChange={(e) => setSupport(e.target.value)}
            placeholder="n° de bracelet, de carte…"
            maxLength={64}
          />
          <span className="sub">
            À renseigner si la commande s’accompagne d’un support à identifier.
          </span>
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={busy || code.trim() === ''}>
            {busy ? 'Enregistrement…' : 'Confirmer la remise'}
          </button>
        </div>
      </form>
    </Modal>
  )
}
