import React from 'react'
import { createRoot } from 'react-dom/client'
import Root from './Root.jsx'
import './styles.css'

createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <Root />
  </React.StrictMode>,
)

// LE SERVICE WORKER N'EST ENREGISTRE QU'EN PRODUCTION, ET C'EST INDISPENSABLE.
//
// En developpement, Vite sert les modules un par un et les remplace a chaud. Un service worker qui
// s'interpose transforme ce rechargement en devinette : on modifie un fichier, l'ecran ne bouge
// pas, et on cherche le defaut dans le code. Il n'a d'utilite que la ou les assets sont figes.
//
// L'echec est avale : un navigateur qui refuse les service workers (mode prive, politique
// d'entreprise) doit servir l'application normalement, sans un mot. Ce fichier ne sert qu'a rendre
// l'installation possible ; son absence ne retire rien.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {})
  })
}
