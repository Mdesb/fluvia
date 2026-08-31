/**
 * ONGLET API — la porte vers le contrat REST de l'application.
 *
 * L'application expose une API REST documentée par API Platform (OpenAPI 3). Jusqu'ici cette
 * documentation n'était atteignable qu'en tapant `/api/docs` à la main : un intégrateur, une borne
 * ITBOX ou un développement tiers ne savait pas qu'elle existait. Cet onglet la rend visible depuis le
 * menu, réservé aux administrateurs (l'API n'est pas un écran d'exploitation).
 *
 * Rien n'est recopié ici : la page pointe vers la documentation vivante générée par le serveur
 * (Swagger UI à `/api/docs`, contrat brut à `/api/docs.jsonopenapi`), sur la MÊME origine que
 * l'application — aucune URL en dur, aucun risque de divergence avec le contrat réel.
 *
 * Le Swagger UI est intégré en cadre ; si l'en-tête `X-Frame-Options` du serveur l'interdit, les deux
 * boutons au-dessus restent le chemin sûr (ouverture dans un onglet dédié, téléchargement du contrat).
 */
export default function DocumentationApi() {
  const urlSwagger = '/api/docs'
  const urlContrat = '/api/docs.jsonopenapi'

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>API</h1>
          <div className="sub">Le contrat REST de l’application — documentation interactive et schéma OpenAPI</div>
        </div>
      </div>

      <div className="card">
        <div className="card-h">Documentation interactive</div>
        <div className="card-b">
          <p className="hint">
            L’application expose une API REST décrite au format OpenAPI 3. La documentation ci-dessous est
            générée par le serveur : elle reflète toujours le contrat réellement servi (endpoints, corps
            attendus, réponses — y compris les schémas <code>message</code> et <code>affichage</code> des bornes).
          </p>
          <div className="row">
            <a className="btn primary" href={urlSwagger} target="_blank" rel="noopener noreferrer">
              Ouvrir la documentation (Swagger UI)
            </a>
            <a className="btn" href={urlContrat} target="_blank" rel="noopener noreferrer" download="openapi.json">
              Télécharger le contrat OpenAPI (JSON)
            </a>
          </div>
          <iframe
            title="Documentation de l’API (Swagger UI)"
            src={urlSwagger}
            style={{ width: '100%', height: '70vh', border: '1px solid var(--bordure, #ddd)', borderRadius: 8, marginTop: 16 }}
          />
        </div>
      </div>
    </div>
  )
}
