import { useEffect, useState, useCallback } from 'react'
import { api, membres } from '../api/client.js'
import { libelleProduit } from '../api/produit.js'
import Modal from './Modal.jsx'

// Rattachement d'options (GroupeOption) à un produit depuis sa fiche (App\OptionProduit, RG-OPT-02).
// Liste les rattachements existants (obligatoire / facultatif, détachable) et propose les groupes
// actifs non encore rattachés.
export default function ProduitOptionsModal({ open, produit, onClose }) {
  const [liaisons, setLiaisons] = useState([])
  const [groupes, setGroupes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)

  const produitId = produit?.id

  const recharger = useCallback(async () => {
    if (!produitId) return
    setChargement(true)
    setErreur(null)
    try {
      const [opc, gc] = await Promise.all([api.optionProduits(produitId), api.groupeOptions()])
      // Garde-fou : le filtre serveur peut être ignoré selon la version — on refiltre côté client.
      const liste = membres(opc).filter((op) => String(op.produit || '').endsWith(String(produitId)))
      setLiaisons(liste)
      setGroupes(membres(gc).filter((g) => g.actif !== false))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [produitId])

  useEffect(() => {
    if (open) recharger()
  }, [open, recharger])

  const idsRattaches = new Set(
    liaisons.map((op) => (op.groupeOption?.id ? String(op.groupeOption.id) : null)).filter(Boolean),
  )
  const disponibles = groupes.filter((g) => !idsRattaches.has(String(g.id)))

  async function rattacher(groupe, obligatoire) {
    setBusy(true)
    setErreur(null)
    try {
      await api.creerOptionProduit({
        produit: `/api/produits/${produitId}`,
        groupeOption: `/api/groupe_options/${groupe.id}`,
        obligatoire: !!obligatoire,
        ordreAffichage: liaisons.length,
      })
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Échec du rattachement.')
    } finally {
      setBusy(false)
    }
  }

  async function basculerObligatoire(op) {
    setBusy(true)
    setErreur(null)
    try {
      await api.majOptionProduit(op.id, { obligatoire: !op.obligatoire })
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Échec de la mise à jour.')
    } finally {
      setBusy(false)
    }
  }

  async function detacher(op) {
    setBusy(true)
    setErreur(null)
    try {
      await api.supprimerOptionProduit(op.id)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Échec du détachement.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre={`Options — ${produit ? libelleProduit(produit) : ''}`} taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
          <div>
            <div className="fiche-sec">Options rattachées</div>
            {liaisons.length === 0 ? (
              <div className="empty" style={{ padding: 12 }}>Aucune option rattachée à ce produit.</div>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead>
                    <tr>
                      <th>Groupe</th>
                      <th>Choix</th>
                      <th>Obligatoire</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {liaisons.map((op) => (
                      <tr key={op.id}>
                        <td><span className="nm">{op.groupeOption?.libelle || '—'}</span></td>
                        <td>
                          <span className="badge mut">
                            {op.groupeOption?.modeSelection === 'multiple' ? 'multiple' : 'unique'}
                          </span>
                        </td>
                        <td>
                          <button
                            className={`btn sm${op.obligatoire ? ' primary' : ''}`}
                            type="button"
                            onClick={() => basculerObligatoire(op)}
                            disabled={busy}
                          >
                            {op.obligatoire ? 'Obligatoire' : 'Facultative'}
                          </button>
                        </td>
                        <td className="num">
                          <button className="btn ghost sm" type="button" onClick={() => detacher(op)} disabled={busy}>
                            Détacher
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          <div>
            <div className="fiche-sec">Ajouter un groupe d'options</div>
            {disponibles.length === 0 ? (
              <div className="empty" style={{ padding: 12 }}>
                {groupes.length === 0
                  ? 'Aucun groupe d’options. Créez-en dans l’onglet Options.'
                  : 'Tous les groupes actifs sont déjà rattachés.'}
              </div>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead>
                    <tr><th>Groupe</th><th>Choix</th><th></th></tr>
                  </thead>
                  <tbody>
                    {disponibles.map((g) => (
                      <tr key={g.id}>
                        <td><span className="nm">{g.libelle}</span></td>
                        <td><span className="badge mut">{g.modeSelection === 'multiple' ? 'multiple' : 'unique'}</span></td>
                        <td className="num" style={{ whiteSpace: 'nowrap' }}>
                          <button className="btn sm" type="button" onClick={() => rattacher(g, false)} disabled={busy}>
                            ＋ Facultative
                          </button>{' '}
                          <button className="btn sm primary" type="button" onClick={() => rattacher(g, true)} disabled={busy}>
                            ＋ Obligatoire
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </Modal>
  )
}
