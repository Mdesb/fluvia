import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from './Modal.jsx'

/**
 * LES PARTENAIRES DE REVENTE EN LIGNE — quatre routes, aucun écran.
 *
 * Un partenaire OTA revend vos billets sur sa propre plateforme. Il achète à un TARIF NET et vous
 * lui devez une COMMISSION : ce sont ces deux nombres qui décident de ce que vous encaissez, et
 * aucun écran ne permettait ni de les poser, ni même de les lire.
 *
 * ⚠ SANS CET ÉCRAN, L'ÉCRAN DES REVERSEMENTS RESTAIT VIDE À JAMAIS. On ne peut pas devoir de
 * l'argent à un partenaire qui n'existe pas. Construire le second sans le premier aurait produit
 * une page qui n'aurait jamais rien affiché — et personne n'aurait su pourquoi.
 */
export default function PartenairesOta({ etabActif, droits = [] }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [partenaires, setPartenaires] = useState(null)
  const [vitrines, setVitrines] = useState(null)
  const [edition, setEdition] = useState(null)
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  const peutGerer = aLeDroit(droits, 'boutique.gerer_connecteur_ota')

  function charger() {
    setPartenaires(null)
    api.partenairesOta()
      .then((r) => setPartenaires(membres(r)))
      .catch(() => setPartenaires(undefined))
  }

  useEffect(charger, [etabActif])

  useEffect(() => {
    api.vitrines()
      .then((r) => setVitrines(membres(r)))
      .catch(() => setVitrines(undefined))
  }, [etabActif])

  function nomVitrine(ref) {
    if (!ref) return null
    const id = typeof ref === 'string' ? String(ref).split('/').pop() : String(ref.id || '')
    if (!Array.isArray(vitrines)) return undefined
    const v = vitrines.find((x) => String(x.id) === id)
    return v ? (v.slug || v.nom) : null
  }

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Partenaires de revente</h3>
        <span className="sub">
          {partenaires === null ? 'lecture…' : partenaires === undefined ? 'illisible' : `${partenaires.length}`}
        </span>
        {peutGerer && (
          <button
            className="btn primary sm"
            type="button"
            style={{ marginLeft: 'auto' }}
            onClick={() => setEdition({ nom: '', vitrine: '', tarifNet: '0.00', commission: '0.00', codeConnecteur: '', actif: true })}
          >
            Nouveau partenaire
          </button>
        )}
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {partenaires === undefined && (
          <div className="banner banner-warn">
            Les partenaires n’ont pas pu être lus. Cet écran ne sait donc pas qui revend vos
            billets — ce n’est pas la même chose que « personne ».
          </div>
        )}

        {partenaires === null && <div className="empty">Lecture des partenaires…</div>}

        {Array.isArray(partenaires) && partenaires.length === 0 && (
          <div className="empty">
            Aucun partenaire. Un partenaire revend vos billets sur sa plateforme&nbsp;: il achète à
            un tarif net, vous lui devez une commission, et c’est de là que naissent les
            reversements.
          </div>
        )}

        {Array.isArray(partenaires) && partenaires.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Partenaire</th>
                  <th>Vitrine</th>
                  <th className="num">Tarif net</th>
                  <th className="num">Commission</th>
                  <th>Connecteur</th>
                  <th>État</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {partenaires.map((p) => (
                  <tr key={p.id}>
                    <td><span className="nm">{p.nom || '—'}</span></td>
                    <td>
                      {/* Trois cas, et le deuxième n'est pas le troisième. */}
                      {!p.vitrine
                        ? <span className="sub">—</span>
                        : nomVitrine(p.vitrine) === undefined
                          ? <span className="sub">vitrines non lues</span>
                          : nomVitrine(p.vitrine) || <span className="sub">vitrine inconnue</span>}
                    </td>
                    <td className="num">{p.tarifNet}</td>
                    <td className="num">{p.commission}</td>
                    <td>{p.codeConnecteur || <span className="sub">—</span>}</td>
                    <td>
                      {p.actif
                        ? <span className="badge good">actif</span>
                        : <span className="badge mut">inactif</span>}
                    </td>
                    {peutGerer && (
                      <td>
                        <button
                          className="btn ghost sm"
                          type="button"
                          onClick={() => setEdition({
                            id: p.id,
                            nom: p.nom || '',
                            vitrine: p.vitrine
                              ? (typeof p.vitrine === 'string' ? String(p.vitrine).split('/').pop() : String(p.vitrine.id || ''))
                              : '',
                            tarifNet: p.tarifNet || '0.00',
                            commission: p.commission || '0.00',
                            codeConnecteur: p.codeConnecteur || '',
                            actif: p.actif !== false,
                          })}
                        >
                          Modifier
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <EditionPartenaire
        valeurs={edition}
        vitrines={vitrines}
        onFermer={() => setEdition(null)}
        onFait={(m) => { setEdition(null); setSucces(m); setErreur(null); charger() }}
        onErreur={setErreur}
      />
    </section>
  )
}

/**
 * ⚠ TARIF NET ET COMMISSION SONT DEUX NOMBRES QUI DÉCIDENT DE CE QUE VOUS ENCAISSEZ.
 *
 * L'écran les nomme en clair plutôt que par leur nom technique : « ce que le partenaire vous paie »
 * et « ce que vous lui devez ». Un exploitant qui inverse les deux ne s'en aperçoit qu'au premier
 * reversement, quand le montant est faux — et rien dans le formulaire ne l'aura prévenu.
 */
function EditionPartenaire({ valeurs, vitrines, onFermer, onFait, onErreur }) {
  const [v, setV] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => { setV(valeurs) }, [valeurs])

  if (!valeurs || !v) return null

  function champ(nom, valeur) {
    setV((p) => ({ ...p, [nom]: valeur }))
  }

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = {
        nom: v.nom.trim(),
        tarifNet: String(v.tarifNet || '0.00'),
        commission: String(v.commission || '0.00'),
        codeConnecteur: v.codeConnecteur.trim() || null,
        actif: !!v.actif,
        // `null` détache explicitement : un champ absent laisserait la vitrine précédente, et on
        // n'aurait aucun moyen de retirer un rattachement posé par erreur.
        vitrine: v.vitrine ? `/api/boutique/vitrines/${v.vitrine}` : null,
      }
      if (v.id) await api.majPartenaireOta(v.id, corps)
      else await api.creerPartenaireOta(corps)
      await onFait(v.id ? 'Partenaire enregistré.' : 'Partenaire créé.')
    } catch (e) {
      onErreur(e.message || 'Le partenaire n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onFermer}
      titre={v.id ? 'Modifier le partenaire' : 'Nouveau partenaire de revente'}
      taille="sm"
    >
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Nom du partenaire *</span>
          <input className="input" value={v.nom} onChange={(e) => champ('nom', e.target.value)} maxLength={120} />
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Ce que le partenaire vous paie par billet</span>
          <input
            className="input"
            inputMode="decimal"
            value={v.tarifNet}
            onChange={(e) => champ('tarifNet', e.target.value)}
          />
          <span className="sub">Le tarif net : votre recette, hors ce que le client lui a payé.</span>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Ce que vous lui devez</span>
          <input
            className="input"
            inputMode="decimal"
            value={v.commission}
            onChange={(e) => champ('commission', e.target.value)}
          />
          <span className="sub">La commission, reversée période par période.</span>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Vitrine rattachée</span>
          {vitrines === undefined ? (
            <span className="sub">Les vitrines n’ont pas pu être lues.</span>
          ) : (
            <select className="select" value={v.vitrine} onChange={(e) => champ('vitrine', e.target.value)}>
              <option value="">— aucune —</option>
              {(vitrines || []).map((x) => (
                <option key={x.id} value={x.id}>{x.slug || x.nom || x.id}</option>
              ))}
            </select>
          )}
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Code du connecteur — facultatif</span>
          <input
            className="input"
            value={v.codeConnecteur}
            onChange={(e) => champ('codeConnecteur', e.target.value)}
            maxLength={60}
          />
        </label>

        <label style={{ display: 'flex', gap: 'var(--esp-normal)', alignItems: 'baseline' }}>
          <input type="checkbox" checked={!!v.actif} onChange={(e) => champ('actif', e.target.checked)} />
          <span>Partenaire actif</span>
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button
            className="btn primary"
            type="button"
            disabled={busy || v.nom.trim() === ''}
            onClick={enregistrer}
          >
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
