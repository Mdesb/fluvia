import { useState } from 'react'
import { api, tokenStore } from '../api/client.js'
import { t } from '../i18n/index.js'

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
 *
 * Écran témoin de la traduction (lot « socle » i18n) : tout son texte passe par `t()`, et
 * `verifier-chaines-traduites.mjs` refuse qu'une phrase en dur y revienne.
 */
export default function Login({ onConnecte, sousTitre = t('login.subtitle') }) {
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

  // ⚠ LE JETON PRE-AUTH NE VA PAS DANS `tokenStore`. Il ne vaut que pour `/auth/mfa-verifier`, il
  // porte le claim `mfaEnAttente`, et le poser dans le stockage ferait croire à toute l'application
  // qu'on est connecté — avec un jeton que chaque écran verrait refuser. Il vit ici, le temps de la
  // seconde étape, et disparaît avec le composant.
  const [defiMfa, setDefiMfa] = useState(null)
  const [codeMfa, setCodeMfa] = useState('')

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
      setErreur(t('login.reset.email_required'))
      return
    }
    setOubliEnCours(true)
    setErreur(null)
    try {
      const r = await api.demanderReinitialisation(email.trim())
      // Le FAIT vient de la réponse (`envoiCourrielBranche`) ; le TEXTE, du catalogue : le serveur
      // ne traduit pas, et sa phrase française reste pour les clients qui n'ont pas de catalogue.
      const branche = !!r?.envoiCourrielBranche
      setReponseOubli({ message: t(branche ? 'login.reset.sent' : 'login.reset.no_mailer'), branche })
    } catch (err) {
      setErreur(err.message || t('login.reset.failed'))
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

      // ── SECOND FACTEUR ────────────────────────────────────────────────────────────────────
      //
      // ⚠ CE BLOC RÉPONDAIT « contactez l'administrateur », ET C'ÉTAIT UNE IMPASSE. Le serveur sait
      // relever le défi depuis l'origine — code TOTP ou code de récupération, cinq échecs et
      // verrouillage, les deux issues tracées — mais aucun écran ne l'appelait. Un compte protégé
      // ne pouvait plus entrer, ce qui faisait de l'activation du MFA un piège.
      //
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
        const preAuth = data.jetonPreAuth ?? data.jeton_pre_auth ?? null
        if (!preAuth) {
          // Défi annoncé sans jeton pour le relever : on le dit plutôt que d'afficher un champ de
          // code qui ne pourrait aboutir. Une impasse nommée vaut mieux qu'une impasse déguisée.
          setErreur(t('login.mfa.missing_challenge'))
          return
        }
        setDefiMfa(preAuth)
        setCodeMfa('')
        setInfo(t('login.mfa.prompt'))
        return
      }

      if (!data?.token) {
        throw new Error(t('login.error.missing_token'))
      }
      tokenStore.set(data.token)
      onConnecte()
    } catch (err) {
      if (err?.status === 401) {
        setErreur(t('login.error.invalid_credentials'))
      } else {
        setErreur(err?.message || t('login.error.failed'))
      }
    } finally {
      setEnCours(false)
    }
  }

  // ⚠ LE CODE PEUT ÊTRE UN CODE DE RÉCUPÉRATION, ET C'EST CE QUI SAUVE LES COMPTES.
  //
  // Le serveur accepte les deux et consomme le code de récupération s'il est employé. Sans cette
  // seconde forme, un téléphone perdu enfermerait dehors — et la seule issue serait la
  // réinitialisation par un administrateur, qui n'existe pas non plus côté écran.
  //
  // Les échecs comptent comme ceux du mot de passe : cinq tentatives, puis verrouillage. On ne
  // reformule donc pas le refus du serveur, qui distingue « code invalide » de « compte
  // verrouillé ».
  async function verifierSecondFacteur(e) {
    e.preventDefault()
    if (!codeMfa.trim()) return
    setEnCours(true)
    setErreur(null)
    setInfo(null)
    try {
      const data = await api.mfaVerifier(defiMfa, codeMfa.trim())
      if (!data?.token) {
        throw new Error(t('login.error.missing_token'))
      }
      tokenStore.set(data.token)
      onConnecte()
    } catch (err) {
      // `err.message` est déjà traduit quand le serveur a codé son refus (`error.auth.*`).
      setErreur(err?.message || t('login.mfa.refused'))
    } finally {
      setEnCours(false)
    }
  }

  // L'écran du second facteur remplace le formulaire plutôt que de s'y ajouter : garder l'e-mail et
  // le mot de passe sous les yeux inviterait à les resoumettre, ce qui ferait repartir la première
  // étape et invaliderait le jeton intermédiaire qu'on vient d'obtenir.
  if (defiMfa !== null) {
    return (
      <div className="login-wrap">
        <form className="login-card" onSubmit={verifierSecondFacteur}>
          <div className="side-brand">
            <img className="logo" src="/fluvia-192.png" alt="" width="28" height="28" /> Fluvia
          </div>
          <h1>{t('login.mfa.title')}</h1>
          <p className="login-sub">{t('login.mfa.subtitle')}</p>

          {erreur && <div className="banner banner-error">{erreur}</div>}
          {info && <div className="banner banner-ok">{info}</div>}

          <div className="field">
            <label htmlFor="code-mfa">{t('login.mfa.code')}</label>
            <input
              id="code-mfa"
              className="input"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              autoFocus
              value={codeMfa}
              onChange={(e) => setCodeMfa(e.target.value)}
              required
            />
            <p className="login-hint">{t('login.mfa.recovery_hint')}</p>
          </div>

          <button className="btn primary" type="submit" disabled={enCours || !codeMfa.trim()}>
            {enCours ? t('login.mfa.verifying') : t('login.mfa.submit')}
          </button>
          <button
            className="btn ghost"
            type="button"
            onClick={() => { setDefiMfa(null); setCodeMfa(''); setInfo(null); setErreur(null) }}
          >
            {t('login.mfa.back')}
          </button>
        </form>
      </div>
    )
  }

  return (
    <div className="login-wrap">
      <form className="login-card" onSubmit={soumettre}>
        <div className="side-brand">
          <img className="logo" src="/fluvia-192.png" alt="" width="28" height="28" /> Fluvia
        </div>
        <h1>{t('login.title')}</h1>
        <p className="login-sub">{sousTitre}</p>

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {info && <div className="banner banner-ok">{info}</div>}

        <div className="field">
          <label htmlFor="email">{t('login.email')}</label>
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
          <label htmlFor="mdp">{t('login.password')}</label>
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
          {enCours ? t('login.submitting') : t('login.submit')}
        </button>

        <button
          className="btn ghost sm"
          type="button"
          style={{ marginTop: 'var(--esp-normal)' }}
          onClick={demanderMdp}
          disabled={oubliEnCours}
        >
          {oubliEnCours ? t('login.forgot_sending') : t('login.forgot')}
        </button>

        {reponseOubli && (
          <div
            className={reponseOubli.branche ? 'banner banner-ok' : 'banner banner-warn'}
            style={{ marginTop: 'var(--esp-normal)' }}
          >
            {reponseOubli.message}
          </div>
        )}

        <p className="login-hint">{t('login.demo_hint')}</p>
      </form>
    </div>
  )
}
