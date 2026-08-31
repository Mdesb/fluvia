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
  const [oubliEnCours, setOubliEnCours] = useState(false)
  const [reponseOubli, setReponseOubli] = useState(null)
  const [info, setInfo] = useState(null)
  const [enCours, setEnCours] = useState(false)

  // ⚠ CET ÉCRAN EST LE SEUL QUI PARLE À QUELQU'UN DE NON AUTHENTIFIÉ, ET ÇA CHANGE TOUT.
  //
  // Partout ailleurs, un fait d'exécution comme « un expéditeur de courriel est-il configuré » se
  // lit dans `/me`, qu'aucun écran ne peut ne pas avoir reçu. Ici, personne n'est connecté : `/me`
  // n'a rien rendu. La seule source honnête est donc la RÉPONSE de la demande elle-même, qui porte
  // `envoiCourrielBranche` — un fait global de l'instance, qui ne dit rien sur l'adresse saisie et
  // ne compromet donc pas l'anti-énumération du serveur.
  //
  // L'écrire en constante ici aurait reproduit très exactement le défaut qu'on corrige : une phrase
  // vraie aujourd'hui, fausse le jour où un expéditeur est branché, et que rien ne relierait à ce
  // qui l'a rendue fausse.
  async function demanderMdp() {
    if (!email.trim()) {
      setErreur('Renseignez votre adresse e-mail, puis redemandez.')
      return
    }
    setOubliEnCours(true)
    setErreur(null)
    try {
      const r = await api.demanderReinitialisation(email.trim())
      setReponseOubli({ message: r?.message || 'Demande enregistrée.', branche: !!r?.envoiCourrielBranche })
    } catch (err) {
      setErreur(err.message || 'La demande n’a pas pu être envoyée.')
    } finally {
      setOubliEnCours(false)
    }
  }

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setInfo(null)
    setEnCours(true)
    try {
      const data = await api.login(email.trim(), motDePasse)

      // Cas MFA en attente : l'API renvoie un jeton intermédiaire à confirmer.
      //
      // ⚠ CE GESTIONNAIRE NE S'EST JAMAIS DÉCLENCHÉ. Il testait `mfaEnAttente`, `mfa_en_attente` et
      // `mfaRequired` — trois orthographes, et aucune n'est celle du serveur. `MfaVerifierAction`
      // répond `{mfaRequis: true, jetonPreAuth}` ; `mfaEnAttente` est le nom du CLAIM porté par le
      // jeton intermédiaire, pas du champ de la réponse.
      //
      // Conséquence : un compte avec MFA actif ne voyait pas le message ci-dessous, qui explique.
      // Il tombait sur « Réponse inattendue de l'API (jeton manquant) », une erreur technique qui
      // envoie chercher une panne. Même famille qu'un `grep` sensible à la casse sur un en-tête en
      // capitales : une chaîne qui ne correspond jamais se lit comme une absence.
      //
      // Les trois anciennes sont conservées — elles ne coûtent rien, et rien ne dit qu'aucun dérivé
      // de l'API ne les emploie. Ce qui manquait était la vraie.
      //
      // ⚠ CECI N'IMPLÉMENTE PAS LE MFA : le second facteur à la connexion reste à construire, et
      // c'est pourquoi le message dit d'appeler l'administrateur plutôt que de proposer un code.
      if (data?.mfaRequis || data?.mfaEnAttente || data?.mfa_en_attente || data?.mfaRequired) {
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

        <button
          className="btn ghost sm"
          type="button"
          style={{ marginTop: 'var(--esp-normal)' }}
          onClick={demanderMdp}
          disabled={oubliEnCours}
        >
          {oubliEnCours ? 'Envoi…' : 'Mot de passe oublié ?'}
        </button>

        {reponseOubli && (
          <div
            className={reponseOubli.branche ? 'banner banner-ok' : 'banner banner-warn'}
            style={{ marginTop: 'var(--esp-normal)' }}
          >
            {reponseOubli.message}
          </div>
        )}

        <p className="login-hint">Compte de démo pré-rempli · IT Cotation</p>
      </form>
    </div>
  )
}
