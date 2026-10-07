/**
 * ONGLET API — la porte vers le contrat REST de l'application.
 *
 * L'application expose une API REST documentée par API Platform (OpenAPI 3). Cet onglet la rend
 * visible depuis le menu, réservé aux administrateurs (l'API n'est pas un écran d'exploitation) :
 * un intégrateur, une borne ITBOX ou un développement tiers sait ainsi qu'elle existe.
 *
 * Rien n'est recopié ici : les liens pointent vers la documentation vivante générée par le serveur
 * (Swagger UI à `/api/docs`, contrat brut à `/api/docs.jsonopenapi`), sur la MÊME origine que
 * l'application — aucune URL en dur, aucun risque de divergence avec le contrat réel.
 *
 * ⚠ PAS D'IFRAME, ET C'EST VOULU. Le serveur protège `/api/docs` par `X-Frame-Options: deny` et une
 * CSP `frame-ancestors 'none'` : on ne veut pas que l'API puisse être encadrée par un site tiers.
 * Un cadre resterait donc TOUJOURS blanc, même en même origine — la version précédente de cet écran
 * intégrait un `<iframe>` qui n'a jamais pu s'afficher. La documentation s'ouvre donc dans un onglet
 * dédié (le seul chemin qui fonctionne), et le contrat brut se télécharge.
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
            L’application expose une API REST décrite au format OpenAPI 3. La documentation est générée
            par le serveur : elle reflète toujours le contrat réellement servi (endpoints, corps attendus,
            réponses — y compris les schémas <code>message</code> et <code>affichage</code> des bornes).
            Elle s’ouvre dans un onglet dédié.
          </p>
          <div className="row">
            <a className="btn primary" href={urlSwagger} target="_blank" rel="noopener noreferrer">
              Ouvrir la documentation (Swagger UI)
            </a>
            <a className="btn" href={urlContrat} target="_blank" rel="noopener noreferrer" download="openapi.json">
              Télécharger le contrat OpenAPI (JSON)
            </a>
          </div>
        </div>
      </div>
    </div>
  )
}
