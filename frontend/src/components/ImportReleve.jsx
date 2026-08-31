import { useCallback, useEffect, useRef, useState } from 'react'
import { api, membres, ApiError } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

/**
 * IMPORT DE RELEVÉS BANCAIRES (T26).
 *
 * Le serveur attend le fichier en base64 dans le corps JSON — pas un envoi multipart : il déchiffre,
 * parse (CSV `date;libelle;montant;reference`, première ligne ignorée), puis vide le contenu. Deux
 * idempotences le protègent, et l'écran les explique plutôt que de laisser un « 409 » nu :
 *  - même fichier ré-importé (empreinte SHA-256) → refusé en bloc ;
 *  - lignes déjà connues d'un import précédent → comptées « ignorées », jamais dupliquées.
 */
const LIBELLE_STATUT = { imported: 'importé', processed: 'traité', error: 'erreur' }

export default function ImportReleve({ etabActif, droits }) {
  const [comptes, setComptes] = useState([])
  const [compte, setCompte] = useState('')
  const [imports, setImports] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [resultat, setResultat] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  const champFichier = useRef(null)

  const peutImporter = aLeDroit(droits, 'finance.treasury_import_statement')

  const chargerComptes = useCallback(async () => {
    setChargement(true)
    try {
      const c = membres(await api.comptesBancaires())
      setComptes(c)
      setCompte((prec) => prec || c[0]?.id || '')
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  const chargerImports = useCallback(async () => {
    if (!compte) { setImports([]); return }
    try {
      const i = membres(await api.importsReleve({ bankAccount: compte, itemsPerPage: 50 }))
      setImports(i)
    } catch (e) {
      setErreur(e.message)
    }
  }, [compte])

  useEffect(() => { chargerComptes() }, [chargerComptes])
  useEffect(() => { chargerImports() }, [chargerImports])

  async function importer(fichier) {
    if (!fichier || !compte) return
    setEnvoi(true)
    setErreur(null)
    setResultat(null)
    try {
      const base64 = await lireBase64(fichier)
      const rep = await api.importerReleve({
        bankAccount: `/api/bank_accounts/${compte}`,
        format: 'csv',
        content: base64,
        fileName: fichier.name,
        fileMimeType: fichier.type || 'text/csv',
      })
      setResultat({
        linesCreated: rep.linesCreated ?? 0,
        linesSkipped: rep.linesSkipped ?? 0,
        errorMessage: rep.errorMessage || null,
      })
      await chargerImports()
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        setErreur('Ce fichier a déjà été importé sur ce compte : rien n’a été ré-enregistré. Un relevé ne s’importe qu’une fois.')
      } else {
        setErreur(e.message || 'L’import a échoué.')
      }
    } finally {
      setEnvoi(false)
      if (champFichier.current) champFichier.current.value = ''
    }
  }

  if (chargement) {
    return (
      <section className="card">
        <div className="card-b center"><div className="spinner" /></div>
      </section>
    )
  }

  if (comptes.length === 0) {
    return <div className="empty">Aucun compte bancaire. Créez-en un dans l’onglet « Comptes » avant d’importer un relevé.</div>
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {resultat && (
        <div className="banner banner-ok">
          <b>{resultat.linesCreated} ligne{resultat.linesCreated > 1 ? 's' : ''} importée{resultat.linesCreated > 1 ? 's' : ''}.</b>
          {resultat.linesSkipped > 0 && ` ${resultat.linesSkipped} ignorée${resultat.linesSkipped > 1 ? 's' : ''} (déjà connues ou illisibles).`}
          {resultat.errorMessage && ` — ${resultat.errorMessage}`}
        </div>
      )}

      <section className="card">
        <div className="card-h"><h3>Importer un relevé</h3><span className="sub">CSV — colonnes date · libellé · montant · référence</span></div>
        <div className="card-b">
          <div className="field">
            <label htmlFor="imp-compte">Compte bancaire</label>
            <select id="imp-compte" className="input" value={compte} onChange={(e) => setCompte(e.target.value)}>
              {comptes.map((c) => (
                <option key={c.id} value={c.id}>{c.label}{c.ibanLast4 ? ` — •••• ${c.ibanLast4}` : ''}</option>
              ))}
            </select>
          </div>
          {peutImporter ? (
            <>
              <input
                ref={champFichier}
                id="imp-fichier"
                type="file"
                accept=".csv,text/csv"
                style={{ display: 'none' }}
                onChange={(e) => importer(e.target.files?.[0])}
              />
              <div className="row" style={{ gap: 'var(--esp-normal)', alignItems: 'center' }}>
                <button className="btn primary" type="button" disabled={envoi} onClick={() => champFichier.current?.click()}>
                  {envoi ? 'Import en cours…' : 'Choisir un fichier CSV…'}
                </button>
                <span className="hint">Le montant positif est un crédit, négatif un débit. La première ligne (en-tête) est ignorée.</span>
              </div>
            </>
          ) : (
            <div className="hint">Vous n’avez pas le droit d’importer des relevés (finance.treasury_import_statement).</div>
          )}
        </div>
      </section>

      <section className="card">
        <div className="card-h"><h3>Imports récents</h3><span className="sub">{imports.length} import{imports.length > 1 ? 's' : ''}</span></div>
        <div className="card-b">
          {imports.length === 0 ? (
            <div className="empty">Aucun import sur ce compte.</div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Fichier</th>
                  <th className="num">Importées</th>
                  <th className="num">Ignorées</th>
                  <th>État</th>
                </tr>
              </thead>
              <tbody>
                {imports.map((im) => (
                  <tr key={im.id}>
                    <td className="mono">{im.importedAt ? String(im.importedAt).slice(0, 10) : '—'}</td>
                    <td><span className="nm">{im.fileName || '(saisie manuelle)'}</span></td>
                    <td className="num">{im.linesCreated ?? '—'}</td>
                    <td className="num">{im.linesSkipped ?? '—'}</td>
                    <td>
                      <span className={`badge ${im.status === 'error' ? 'crit' : 'good'}`}>
                        {LIBELLE_STATUT[im.status] || im.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>
    </>
  )
}

/** Lit un fichier et renvoie son contenu en base64 (sans le préfixe `data:…;base64,`). */
function lireBase64(fichier) {
  return new Promise((resolve, reject) => {
    const lecteur = new FileReader()
    lecteur.onerror = () => reject(new Error('Lecture du fichier impossible.'))
    lecteur.onload = () => {
      const res = String(lecteur.result || '')
      const virgule = res.indexOf(',')
      resolve(virgule >= 0 ? res.slice(virgule + 1) : res)
    }
    lecteur.readAsDataURL(fichier)
  })
}
