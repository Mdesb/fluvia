import { useState } from 'react'
import { api, tokenStore } from '../api/client.js'

export default function Login({ onConnecte }) {
  const [email, setEmail] = useState('admin@itcotation.com')
  // ⚠ DOIT VALOIR `SocleFixtures::ADMIN_MDP`. Les deux ont diverge le 27/08 quand les fixtures sont
  // passees a « aaa » : l'ecran a continue d'annoncer un compte de demo pre-rempli, et de proposer
  // un mot de passe que plus aucun compte ne portait. La premiere page du produit repondait
  // « Identifiants incorrects » a qui cliquait, sans que rien ne relie la cause a l'effet.
  //
  // ⚠ CETTE COMMODITE N'A RIEN A FAIRE CHEZ UN CLIENT. Elle vit pour la demonstration et la
  // preprod ; un deploiement reel doit vider ces deux champs.
  const [motDePasse, setMotDePasse] = useState('aaa')
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
        <div className="side-brand">
          <span className="logo">◈</span> Fluvia
        </div>
        <h1>Connexion</h1>
        <p className="login-sub">Accédez à la caisse et au catalogue.</p>

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {info && <div className="banner banner-ok">{info}</div>}

        <div className="field">
          <label htmlFor="email">Adresse e-mail</label>
          <input
            id="email"
            className="input"
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
            className="input"
            type="password"
            autoComplete="current-password"
            value={motDePasse}
            onChange={(e) => setMotDePasse(e.target.value)}
            required
          />
        </div>

        <button className="btn primary lg" type="submit" disabled={enCours}>
          {enCours ? 'Connexion…' : 'Se connecter'}
        </button>

        <p className="login-hint">Compte de démo pré-rempli · IT Cotation</p>
      </form>
    </div>
  )
}
