import { useState } from 'react'
import { api, tokenStore } from '../api/client.js'

/**
 * ⚠ `sousTitre` EXISTE PARCE QUE CET ECRAN SERT TROIS PRODUITS.
 *
 * `Root.jsx` choisit entre le back-office, la vitrine et l'administration de l'editeur, et sa
 * regle est explicite : trois produits, trois publics. La connexion, elle, est restee commune --
 * ce qui est le bon choix, un seul formulaire a maintenir -- mais son sous-titre ne l'est pas :
 * « Accedez a la caisse et au catalogue » accueillait l'administration de l'editeur.
 *
 * C'est la seule page visible avant d'avoir un compte : celle de la demonstration, et celle sur
 * laquelle on doute quand on s'est trompe d'adresse. Elle doit dire ou l'on est.
 *
 * La valeur par defaut est celle du back-office : l'appelant qui ne dit rien garde l'existant.
 */
export default function Login({ onConnecte, sousTitre = 'Accédez à la caisse et au catalogue.' }) {
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
        <p className="login-sub">{sousTitre}</p>

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
