import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// Le back Symfony n'autorise en CORS que les en-têtes `content-type` et `authorization`.
// Or le flux de vente utilise aussi `X-Etablissement`. Pour éviter tout problème CORS on
// sert le front et l'API sur la MÊME origine via un proxy de dev : le navigateur appelle
// des chemins relatifs (/auth, /me, /api/...) que Vite relaie vers le back.
// La cible du proxy est configurable par VITE_API_URL (défaut http://localhost:8080).
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = env.VITE_API_URL || 'http://localhost:8080'
  const proxy = {}
  for (const path of ['/auth', '/me', '/api']) {
    proxy[path] = { target, changeOrigin: true, secure: false }
  }
  return {
    plugins: [react()],
    server: { port: 5173, host: true, proxy },
  }
})
