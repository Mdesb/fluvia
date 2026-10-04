import { useEffect, useState } from 'react'
import { boutique } from '../api/boutiqueClient.js'
import Markdown from '../components/Markdown.jsx'

/**
 * Une page legale de la boutique.
 *
 * **La date et la version sont affichees, et ce n'est pas decoratif.** Des CGV ne sont opposables que
 * dans la version que le client a pu lire au moment ou il a paye. Afficher << version 3, publiee le
 * 12 mars >> donne au client le moyen de constater qu'elles ont change depuis son achat -- et a
 * l'exploitant le moyen de montrer laquelle s'appliquait.
 */
export default function PageLegale({ etablissementId, slug, onNaviguer }) {
  const [documents, setDocuments] = useState(null)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!etablissementId) return undefined
    let annule = false
    boutique
      .documentsLegaux(etablissementId)
      .then((r) => { if (!annule) setDocuments(r?.documents || []) })
      .catch((e) => { if (!annule) setErreur(e.message || 'Document indisponible.') })
    return () => { annule = true }
  }, [etablissementId])

  if (erreur) return <div className="bq-empty">{erreur}</div>
  if (documents === null) return <div className="bq-empty">Chargement…</div>

  const page = documents.find((d) => d.slug === slug)

  if (!page) {
    return (
      <div className="bq-empty">
        Ce document n'est pas publie.
        <div style={{ marginTop: 10 }}>
          <button type="button" className="btn" onClick={() => onNaviguer?.({ vue: 'vitrine' })}>
            Retour a la boutique
          </button>
        </div>
      </div>
    )
  }

  return (
    <article className="bq-legal">
      <Markdown texte={page.contenu} />
      <p className="bq-sub" style={{ marginTop: 26 }}>
        {/* Politique TYPE (#101) : servie quand l'établissement n'a rien publié. Elle n'a ni version
            ni date — « Version » suivi de rien ferait croire à un document publié. */}
        {page.parDefaut ? (
          "Modèle par défaut, en attendant la politique de l'établissement."
        ) : (
          <>
            Version {page.version}
            {page.publieLe
              ? ` · publiee le ${new Date(page.publieLe).toLocaleDateString('fr-FR')}`
              : ''}
          </>
        )}
      </p>
    </article>
  )
}
