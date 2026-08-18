import App from './App.jsx'
import PublicApp from './public/PublicApp.jsx'

// Point d'entrée racine : choisit l'application STAFF (back-office) ou la VITRINE PUBLIQUE
// (front client final) selon le chemin, sans perturber App.jsx.
// La zone publique est servie sous le préfixe « /vitrine » (ex. /vitrine?vitrine=<id>).
// Tout le reste reste l'app staff existante.
export default function Root() {
  const chemin = typeof window !== 'undefined' ? window.location.pathname : '/'
  const estPublic = chemin === '/vitrine' || chemin.startsWith('/vitrine/')
  return estPublic ? <PublicApp /> : <App />
}
