import { useState } from 'react'
import { api, tokenStore } from '../api/client.js'

export default function Login({ onConnecte }) {
  const [email, setEmail] = useState('admin@itcotation.com')
  const [motDePasse, setMotDePasse] = useState('AdminSocle#2026')
  const [erreur, setErreur] = useState(null)
  const [info, setInfo] = useState(null)
  const [enCours, setEnCours] = useState(false)

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setInfo(null)
    setEnCours(true)
    try {
      const data = await api.login(email.trim(), motDePasse)

      // Cas MFA en attente : l'API renvoie un jeton intermédiaire à confirmer.
      if (data?.mfaEnAttente || data?.mfa_en_attente || data?.mfaRequired) {
        setInfo(
          "Authentification à deux facteurs requise. Cette étape n'est pas encore gérée par cette interface — contactez l'administrateur.",
        )
        return
      }

      if (!data?.token) {
        throw new Error("Réponse inattendue de l'API (jeton manquant).")
      }
      tokenStore.set(data.token)
      onConnecte()
    } catch (err) {
      if (err?.status === 401) {
        setErreur('Identifiants incorrects.')
      } else {
        setErreur(err?.message || 'Échec de la connexion.')
      }
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div className="login-wrap">
      <form className="login-card" onSubmit={soumettre}>
        <div className="brand">
          <span className="brand-dot">B</span>
          <span className="brand-name">Billetterie</span>
        </div>
        <h1>Connexion</h1>
        <p className="login-sub">Accédez à la caisse et au catalogue.</p>

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {info && <div className="banner banner-ok">{info}</div>}

        <div className="field">
          <label htmlFor="email">Adresse e-mail</label>
          <input
            id="email"
            type="email"
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>
        <div className="field">
          <label htmlFor="mdp">Mot de passe</label>
          <input
            id="mdp"
            type="password"
            autoComplete="current-password"
            value={motDePasse}
            onChange={(e) => setMotDePasse(e.target.value)}
            required
          />
        </div>

        <button className="btn btn-lg" type="submit" disabled={enCours}>
          {enCours ? 'Connexion…' : 'Se connecter'}
        </button>

        <p className="login-hint">Compte de démo pré-rempli · IT Cotation</p>
      </form>
    </div>
  )
}
