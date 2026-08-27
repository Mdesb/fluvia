import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, membres, tokenStore, etablissementStore } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

/**
 * DOCUMENTS — quinze opérations serveur, aucun écran jusqu'ici.
 *
 * Le module gère des documents **versionnés**, avec politique de conservation, liens publics
 * révocables et suppression réversible. Rien de tout ça n'était atteignable : un contrat déposé par
 * l'API existait, et personne ne pouvait le relire.
 *
 * **Le téléchargement ne s'ouvre pas dans un onglet, et c'est structurel.** `GET
 * /dms/documents/{id}/download` exige un jeton porteur ; une navigation ne transporte pas d'en-tête
 * `Authorization`. On récupère donc le flux avec le jeton, on en fait un objet local, et on déclenche
 * l'enregistrement — puis **on libère l'objet**, sans quoi chaque téléchargement laisserait le fichier
 * entier en mémoire pour la durée de la session.
 *
 * > **Un lien qui a l'air de marcher et rend une page de connexion est pire qu'un bouton absent.**
 *
 * **La version courante est affichée avec son numéro.** Un document versionné dont l'écran ne montre
 * pas la version laisse croire qu'il n'y en a qu'une — et quelqu'un remplace un fichier en pensant le
 * corriger, alors qu'il en crée une nouvelle qui ne sera pas celle qu'un lien public sert.
 */

const CATEGORIES = [
  ['accounting_piece', 'Pièce comptable'],
  ['expense_receipt', 'Justificatif de frais'],
  ['contract', 'Contrat'],
  ['intervention_report', 'Rapport d’intervention'],
  ['hr_document', 'Document RH'],
  ['quote_attachment', 'Pièce jointe de devis'],
  ['marketing_asset', 'Visuel'],
  ['other', 'Autre'],
]

function libelleCategorie(v) {
  return CATEGORIES.find(([c]) => c === v)?.[1] || v || '—'
}

function poids(octets) {
  const n = Number(octets)
  if (!Number.isFinite(n)) return '—'
  if (n < 1024) return `${n} o`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} Ko`
  return `${(n / (1024 * 1024)).toFixed(1)} Mo`
}

function quand(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

export default function Documents({ etabActif, droits = [] }) {
  const peutEcrire = aLeDroit(droits, 'dms.write')

  const [documents, setDocuments] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [categorie, setCategorie] = useState('')
  const [recherche, setRecherche] = useState('')
  const [busy, setBusy] = useState(false)

  const champFichier = useRef(null)
  const champRemplacement = useRef(null)
  const [aRemplacer, setARemplacer] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setDocuments(membres(await api.documentsDms()))
    } catch (e) {
      setErreur(e.message || 'Les documents n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  const visibles = useMemo(() => {
    const q = recherche.trim().toLowerCase()
    return documents
      .filter((d) => d.status !== 'deleted')
      .filter((d) => !categorie || d.category === categorie)
      .filter((d) => !q || (d.title || '').toLowerCase().includes(q))
  }, [documents, categorie, recherche])

  async function telecharger(doc) {
    setErreur(null)
    try {
      // Le jeton voyage dans l'en-tete : une navigation ne le porterait pas, et le serveur rendrait
      // la page de connexion a la place du fichier.
      const reponse = await fetch(api.urlTelechargementDocument(doc.id), {
        headers: {
          Authorization: `Bearer ${tokenStore.get()}`,
          'X-Etablissement': etablissementStore.get() || '',
        },
      })
      if (!reponse.ok) throw new Error(`Téléchargement refusé (${reponse.status}).`)

      const blob = await reponse.blob()
      const url = URL.createObjectURL(blob)
      const lien = document.createElement('a')
      lien.href = url
      lien.download = doc.currentVersion?.originalFilename || doc.title || 'document'
      lien.click()
      // Sans cette liberation, chaque telechargement garde le fichier entier en memoire jusqu'a la
      // fermeture de l'onglet. Sur des contrats scannes, ca se compte en centaines de megaoctets.
      URL.revokeObjectURL(url)
    } catch (e) {
      setErreur(e.message || 'Le téléchargement a échoué.')
    }
  }

  async function televerser(fichier, titre, cat) {
    setBusy(true)
    setErreur(null)
    try {
      const fd = new FormData()
      fd.append('file', fichier)
      fd.append('title', titre || fichier.name)
      fd.append('category', cat || 'other')
      await api.televerserDocument(fd)
      setSucces(`« ${titre || fichier.name} » déposé.`)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Le dépôt a échoué.')
    } finally {
      setBusy(false)
    }
  }

  async function remplacer(doc, fichier) {
    setBusy(true)
    setErreur(null)
    try {
      const fd = new FormData()
      fd.append('file', fichier)
      await api.remplacerVersionDocument(doc.id, fd)
      setSucces(`Nouvelle version déposée pour « ${doc.title} ».`)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Le remplacement a échoué.')
    } finally {
      setBusy(false)
      setARemplacer(null)
    }
  }

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>Documents</h1>
          <div className="sub">{visibles.length} document{visibles.length > 1 ? 's' : ''}</div>
        </div>
        {peutEcrire && (
          <>
            <input
              ref={champFichier}
              type="file"
              style={{ display: 'none' }}
              onChange={(e) => {
                const f = e.target.files?.[0]
                // On remet le champ a zero : sans ca, redeposer LE MEME fichier ne declencherait
                // aucun evenement, et l'utilisateur conclurait que le bouton ne marche plus.
                e.target.value = ''
                if (f) televerser(f, f.name, categorie || 'other')
              }}
            />
            <button className="btn primary" type="button" disabled={busy} onClick={() => champFichier.current?.click()}>
              + Déposer un document
            </button>
          </>
        )}
      </div>

      {erreur && <div className="alert crit">{erreur}</div>}
      {succes && <div className="alert good">{succes}</div>}

      <div className="panel">
        <div className="panel-h" style={{ gap: 10, flexWrap: 'wrap' }}>
          <span>Bibliothèque</span>
          <select className="select sm" style={{ width: 220 }} value={categorie} onChange={(e) => setCategorie(e.target.value)}>
            <option value="">Toutes les catégories</option>
            {CATEGORIES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          <input
            className="input sm"
            style={{ marginLeft: 'auto', width: 260 }}
            placeholder="Rechercher un titre…"
            value={recherche}
            onChange={(e) => setRecherche(e.target.value)}
          />
        </div>

        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : visibles.length === 0 ? (
          <div className="sub" style={{ textAlign: 'center', padding: 26 }}>
            {documents.length === 0
              ? 'Aucun document. Contrats, rapports d’intervention et pièces comptables se déposent ici.'
              : 'Aucun document ne correspond à ce filtre.'}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Titre</th>
                  <th>Catégorie</th>
                  <th className="num">Version</th>
                  <th className="num">Taille</th>
                  <th className="num">Déposé le</th>
                  <th>Conservation</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {visibles.map((d) => (
                  <tr key={d.id}>
                    <td>
                      <span className="nm">{d.title}</span>
                      {d.currentVersion?.originalFilename && (
                        <div className="sub">{d.currentVersion.originalFilename}</div>
                      )}
                    </td>
                    <td>{libelleCategorie(d.category)}</td>
                    <td className="num">
                      {/* Un document versionne dont l'ecran ne montre pas la version laisse croire
                          qu'il n'y en a qu'une -- et quelqu'un << corrige >> un fichier en creant en
                          realite une version de plus. */}
                      {d.currentVersion?.versionNumber ? `v${d.currentVersion.versionNumber}` : '—'}
                    </td>
                    <td className="num">{poids(d.currentVersion?.sizeBytes)}</td>
                    <td className="num">{quand(d.createdAt)}</td>
                    <td>
                      {d.retainUntil
                        ? <span className="sub">jusqu’au {quand(d.retainUntil)}</span>
                        : <span className="sub">sans limite</span>}
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                        <button className="btn ghost sm" type="button" onClick={() => telecharger(d)}>
                          Télécharger
                        </button>
                        {peutEcrire && (
                          <button
                            className="btn ghost sm"
                            type="button"
                            disabled={busy}
                            onClick={() => {
                              setARemplacer(d)
                              champRemplacement.current?.click()
                            }}
                          >
                            Nouvelle version
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <input
        ref={champRemplacement}
        type="file"
        style={{ display: 'none' }}
        onChange={(e) => {
          const f = e.target.files?.[0]
          e.target.value = ''
          if (f && aRemplacer) remplacer(aRemplacer, f)
        }}
      />
    </div>
  )
}
