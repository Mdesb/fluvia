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
  // TROIS PRÉFIXES HORS `/api`, ET LEUR ABSENCE NE PRODUISAIT AUCUNE ERREUR.
  //
  // `/media`, `/dms` et `/sepa` sont servis par des contrôleurs Symfony simples (une photo de
  // produit, un document de la GED, le fichier pain.008 d'une remise). Sans eux dans cette liste,
  // Vite ne relaie pas la requête : c'est le SPA qui répond, avec son propre `index.html` et un
  // **200**. Le navigateur enregistre donc une page HTML sous le nom du fichier attendu, ou affiche
  // une image cassée — et rien, nulle part, ne signale une erreur.
  //
  // Le symptôme a déjà été payé deux fois : les photos de produit ne s'affichaient pas, et le
  // téléchargement de la GED rendait la page de connexion. Le bloc nginx de la préprod a été
  // corrigé en miroir (`^/(api|auth|me|reporting|media|dms|sepa)`) ; les deux doivent rester
  // alignés, sinon le dev et la préprod ne se comportent pas pareil.
  // ⚠ CETTE LISTE AVAIT DÉRIVÉ DE CINQ PRÉFIXES PAR RAPPORT À NGINX, ET LE COMMENTAIRE CI-DESSUS
  // DEMANDAIT DÉJÀ QU'ELLES RESTENT ALIGNÉES.
  //
  // Relevé le 31/08 dans `infra/nginx/billetterie-preprod.conf` :
  //
  //     location ~ ^/(api|auth|me|reporting|media|dms|sepa|audit|calendar|health|mot-de-passe|utilisateurs)(/|$)
  //
  // Manquaient ici : audit, calendar, health, mot-de-passe, utilisateurs. Conséquence exacte de ce
  // que décrit le paragraphe précédent — en développement, ces routes ne sont pas relayées et c'est
  // le SPA qui répond. Trouvé en construisant le parcours « mot de passe oublié » : la préprod
  // répondait 202, le poste de développement 404, sur le même code. Une différence de comportement
  // entre dev et préprod fait chercher un défaut dans le code applicatif pendant des heures.
  //
  // Les deux listes doivent bouger ensemble. Si tu ajoutes un préfixe ici, ajoute-le là-bas.
  for (const path of [
    '/auth', '/me', '/api', '/reporting', '/media', '/dms', '/sepa',
    '/audit', '/calendar', '/health', '/mot-de-passe', '/utilisateurs',
  ]) {
    proxy[path] = { target, changeOrigin: true, secure: false }
  }
  return {
    plugins: [react()],
    server: { port: 5173, host: true, proxy },
  }
})
