import { useEffect, useState } from 'react'
import { boutique } from '../api/boutiqueClient.js'

/**
 * Pied de page public — et les mentions obligatoires, qui n'y figuraient pas.
 *
 * **Ce pied de page annoncait << Vos donnees sont traitees conformement au RGPD >> sans lien vers la
 * moindre politique.** C'est la forme la plus courante du manquement : une phrase rassurante a la
 * place du document qu'elle resume. Le RGPD n'impose pas de dire qu'on le respecte, il impose de dire
 * COMMENT -- finalites, durees, destinataires, droits, voie de reclamation.
 *
 * **Les liens ne s'affichent que pour les documents reellement publies.** Un lien vers des CGV
 * inexistantes est pire que pas de lien : il donne l'apparence de la conformite, et c'est cette
 * apparence qu'un controle releve.
 */
export default function PublicFooter({ etablissementId, onNaviguer }) {
  const [documents, setDocuments] = useState([])

  useEffect(() => {
    if (!etablissementId) return undefined
    let annule = false
    boutique
      .documentsLegaux(etablissementId)
      .then((r) => { if (!annule) setDocuments(r?.documents || []) })
      // Une erreur ici n'a pas a s'afficher : elle produirait un bandeau rouge en bas de toutes les
      // pages de la boutique pour un contenu secondaire. L'absence de liens dit deja qu'il n'y a rien.
      .catch(() => { if (!annule) setDocuments([]) })
    return () => { annule = true }
  }, [etablissementId])

  return (
    <footer className="bq-footer">
      <div className="bq-footer-in">
        <p>Billetterie en ligne · Service public</p>

        {documents.length > 0 && (
          <nav className="bq-footer-legal" aria-label="Informations legales">
            {documents.map((d, i) => (
              <span key={d.slug}>
                {i > 0 && <span aria-hidden="true"> · </span>}
                <button
                  type="button"
                  className="bq-footer-lien"
                  onClick={() => onNaviguer?.({ vue: 'legal', slug: d.slug })}
                >
                  {d.titre}
                </button>
              </span>
            ))}
          </nav>
        )}

        <p className="bq-footer-sub">
          Paiement securise
          {documents.some((d) => d.slug === 'confidentialite')
            ? ' · Vos donnees sont traitees conformement au RGPD.'
            // Sans politique publiee, on ne l'affirme pas. Une affirmation invérifiable sur un site
            // marchand engage plus qu'elle ne rassure.
            : ''}
        </p>
      </div>
    </footer>
  )
}
