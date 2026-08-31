import { Suspense, lazy } from 'react'

// Point d'entrée racine : choisit l'application STAFF (back-office), la VITRINE PUBLIQUE
// (front client final) ou l'ADMINISTRATION DE L'ÉDITEUR selon le chemin.
// La zone publique est servie sous le préfixe « /vitrine » (ex. /vitrine?vitrine=<id>).
// L'administration de l'éditeur est servie sous « /editeur ».
//
// LES TROIS BRANCHES SONT CHARGÉES À LA DEMANDE, et c'est structurant.
//
// Avec des imports statiques, un seul paquet contiendrait les trois applications : le caissier
// téléchargerait toute la boutique publique, et un visiteur de la vitrine téléchargerait tout le
// back-office — écrans de comptabilité, de personnel et de paramétrage compris, qu'il ne verra
// jamais et qu'il n'a pas le droit de voir.
//
// La couture existe déjà : le choix se fait une fois, au démarrage, sur le chemin. Aucun visiteur ne
// bascule d'une branche à l'autre en cours de route. C'est donc l'endroit le moins cher et le plus
// sûr où découper — et le seul qui tienne quand d'autres applications arriveront (espace client
// final, tableau de bord mobile) : sans découpage, chacune alourdirait le démarrage de toutes les
// autres.
//
// TROIS PRODUITS, TROIS PUBLICS. L'administration de l'éditeur n'est pas un onglet du back-office
// des exploitants : un exploitant de piscine n'a rien à faire dans la gestion des abonnements de
// l'éditeur, et n'a surtout pas à en télécharger le code.
const App = lazy(() => import('./App.jsx'))
const PublicApp = lazy(() => import('./public/PublicApp.jsx'))
const EditeurApp = lazy(() => import('./editeur/EditeurApp.jsx'))

function brancheDe(chemin) {
  // `/b/<nom-de-la-boutique>` — l'adresse qu'on donne a un client.
  //
  // `…/vitrine?vitrine=77eee25d-0ab5-4e69-9e17-85242e79d5aa` ne s'imprime pas sur une affiche et ne se
  // dicte pas au telephone. `/b/piscine-a` si. Les deux formes marchent : un lien deja envoye dans un
  // courriel de confirmation ne se casse pas parce qu'on a trouve mieux.
  if (chemin === '/b' || chemin.startsWith('/b/')) return 'vitrine'
  if (chemin === '/vitrine' || chemin.startsWith('/vitrine/')) return 'vitrine'
  if (chemin === '/editeur' || chemin.startsWith('/editeur/')) return 'editeur'
  return 'staff'
}

export default function Root() {
  const chemin = typeof window !== 'undefined' ? window.location.pathname : '/'
  const branche = brancheDe(chemin)

  return (
    <Suspense fallback={<div className="center" style={{ minHeight: '100vh' }}><div className="spinner" /></div>}>
      {branche === 'vitrine' && <PublicApp />}
      {branche === 'editeur' && <EditeurApp />}
      {branche === 'staff' && <App />}
    </Suspense>
  )
}
