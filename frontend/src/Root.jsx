import { Suspense, lazy } from 'react'

// Point d'entrée racine : choisit l'application STAFF (back-office) ou la VITRINE PUBLIQUE
// (front client final) selon le chemin.
// La zone publique est servie sous le préfixe « /vitrine » (ex. /vitrine?vitrine=<id>).
//
// LES DEUX BRANCHES SONT CHARGÉES À LA DEMANDE, et c'est structurant.
//
// Avec deux imports statiques, un seul paquet contenait les deux applications : le caissier
// téléchargeait toute la boutique publique, et un visiteur de la vitrine téléchargeait tout le
// back-office — écrans de comptabilité, de personnel et de paramétrage compris, qu'il ne verra
// jamais et qu'il n'a pas le droit de voir.
//
// La couture existe déjà : le choix se fait une fois, au démarrage, sur le chemin. Aucun visiteur ne
// bascule d'une branche à l'autre en cours de route. C'est donc l'endroit le moins cher et le plus
// sûr où découper — et le seul qui tienne quand deux applications de plus arriveront (espace client
// final, tableau de bord mobile) : sans découpage, chacune alourdirait le démarrage de toutes les
// autres.
const App = lazy(() => import('./App.jsx'))
const PublicApp = lazy(() => import('./public/PublicApp.jsx'))

export default function Root() {
  const chemin = typeof window !== 'undefined' ? window.location.pathname : '/'
  const estPublic = chemin === '/vitrine' || chemin.startsWith('/vitrine/')

  return (
    <Suspense fallback={<div className="center" style={{ minHeight: '100vh' }}><div className="spinner" /></div>}>
      {estPublic ? <PublicApp /> : <App />}
    </Suspense>
  )
}
