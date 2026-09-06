import { useState } from 'react'
import { api } from '../api/client.js'

// ACTIVER SON COMPTE, OU SE REDONNER UN MOT DE PASSE — les deux liens de courriel qui ne menaient
// nulle part.
//
// ── CE QUI ÉTAIT CASSÉ, ET CE QUE ÇA COÛTAIT ────────────────────────────────────────────────────
//
// Les deux courriels pointent vers le frontal, jeton en paramètre :
//
//     InvitationMailer          {front}/activation?jeton=…
//     ReinitialisationMailer    {front}/mot-de-passe/reinitialiser?jeton=…
//
// Les deux contrôleurs existent, complets, avec leurs refus distincts. **Aucun écran ne les
// appelait**, et `App.jsx` ne lisait de l'URL que `?support=`. Les deux liens tombaient donc sur le
// repli de l'application : l'écran de connexion. Un invité recevait un lien qui lui montrait un
// formulaire dans lequel il n'avait, par construction, aucun mot de passe à saisir.
//
// Rien ne le signalait : le garde-fou d'écart client/serveur compare le frontal aux opérations
// **API Platform**, et ces deux routes sont des contrôleurs Symfony ordinaires — invisibles pour lui.
//
// ── 422 ET 410 NE SE SOIGNENT PAS PAREIL ────────────────────────────────────────────────────────
//
// Le serveur distingue « jeton invalide » (422) de « jeton expiré ou déjà utilisé » (410), et c'est
// utile : le premier veut dire que le lien est abîmé — recopié à moitié, coupé par un client de
// messagerie ; le second, qu'il faut en redemander un. Les confondre en « une erreur est survenue »
// enverrait la moitié des gens réclamer un lien qu'ils ont déjà.
//
// ── ⚠ LE JETON NE RESTE PAS DANS LA BARRE D'ADRESSE ─────────────────────────────────────────────
//
// Même patron que `amorcerModeSupport` dans `App.jsx` : on le lit une fois, on le garde en mémoire,
// et on le retire de l'URL. Sans cela le lien se copie, se met en favori, se colle dans une
// conversation — et il vaut mot de passe tant qu'il n'est pas consommé.

export const CHEMINS = {
  '/activation': 'activation',
  // ⚠ LA PAGE N'A PAS LE CHEMIN DE L'ENDPOINT, ET C'EST LA CORRECTION.
  // `/mot-de-passe/reinitialiser` est capte par le vhost et envoye a Symfony, qui n'y accepte
  // que POST : le lien du courriel rendait 405 dans un navigateur. L'activation n'avait pas ce
  // defaut parce que sa page et son endpoint different deja.
  '/nouveau-mot-de-passe': 'reinitialisation',
}

/**
 * Le mode demandé par l'URL, jeton compris — ou `null` si cette page n'est pas concernée.
 *
 * Lu au chargement du module, avant tout React, et l'URL est nettoyée dans la foulée.
 */
export function lireDemandeDeCompte() {
  try {
    const mode = CHEMINS[window.location.pathname]
    if (!mode) return null
    const params = new URLSearchParams(window.location.search)
    const jeton = params.get('jeton') || ''
    params.delete('jeton')
    const reste = params.toString()
    window.history.replaceState(window.history.state, '',
      window.location.pathname + (reste ? `?${reste}` : ''))
    return { mode, jeton }
  } catch {
    // URL illisible : on ne prétend pas connaître la demande.
    return null
  }
}

const TEXTES = {
  activation: {
    titre: 'Activez votre compte',
    intro: 'Vous avez été invité·e à rejoindre Fluvia. Choisissez le mot de passe qui vous servira à vous connecter.',
    bouton: 'Activer mon compte',
    succes: 'Votre compte est activé. Vous pouvez maintenant vous connecter.',
    expire: 'Cette invitation a expiré, ou elle a déjà servi. Demandez à un administrateur de vous en envoyer une nouvelle.',
    invalide: 'Ce lien d’invitation n’est pas reconnu. Vérifiez qu’il a été copié en entier — certains logiciels de messagerie les coupent.',
  },
  reinitialisation: {
    titre: 'Choisissez un nouveau mot de passe',
    intro: 'Vous avez demandé à réinitialiser votre mot de passe. Le précédent cessera de fonctionner, et vos autres sessions seront fermées.',
    bouton: 'Enregistrer ce mot de passe',
    succes: 'Votre mot de passe est enregistré. Vous pouvez vous connecter avec.',
    expire: 'Ce lien a expiré, ou il a déjà servi. Redemandez-en un depuis « Mot de passe oublié ».',
    invalide: 'Ce lien n’est pas reconnu. Vérifiez qu’il a été copié en entier — certains logiciels de messagerie les coupent.',
  },
}

export default function AccesCompte({ demande, onTermine }) {
  const t = TEXTES[demande.mode]
  const [motDePasse, setMotDePasse] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [fait, setFait] = useState(false)

  // ⚠ CE MINIMUM EXISTE AUSSI CÔTÉ SERVEUR, ET C'EST LÀ QU'IL ENGAGE.
  //
  // Les deux contrôleurs acceptaient toute chaîne non vide : un appel direct posait « a ». Écrire
  // ici « ce contrôle est un confort » aurait annoncé le trou sans le fermer, donc la même valeur
  // est appliquée dans `ActivationController` et `ReinitialisationMotDePasseController`
  // (`TAILLE_MIN_MOT_DE_PASSE`). Si vous changez ce nombre, changez les deux — sinon l'écran
  // promet une règle que le serveur ignore, ou refuse ce que le serveur accepterait.
  //
  // ⚠ Et ce seuil ne vaut QUE ces deux flux : le dépôt n'a aucune politique de mot de passe
  // ailleurs, création d'utilisateur comprise. En poser une partout est une décision de produit.
  const TAILLE_MIN = 12
  const tropCourt = motDePasse.length > 0 && motDePasse.length < TAILLE_MIN
  const discordent = confirmation.length > 0 && motDePasse !== confirmation
  const pret = motDePasse.length >= TAILLE_MIN && motDePasse === confirmation

  async function envoyer(e) {
    e.preventDefault()
    setErreur(null)
    setEnCours(true)
    try {
      if (demande.mode === 'activation') {
        await api.activerCompte({ jeton: demande.jeton, motDePasse })
      } else {
        await api.reinitialiserMotDePasse({ jeton: demande.jeton, nouveauMotDePasse: motDePasse })
      }
      setFait(true)
    } catch (err) {
      // Le serveur sépare « pas reconnu » de « périmé » : on garde la distinction, parce que les
      // deux se soignent différemment.
      setErreur(err?.status === 410 ? t.expire : err?.status === 422 ? t.invalide
        : (err?.message || 'L’opération n’a pas pu aboutir.'))
    } finally {
      setEnCours(false)
    }
  }

  if (!demande.jeton) {
    return (
      <div className="login-wrap">
        <div className="login-card">
        <div className="side-brand">
          <img className="logo" src="/fluvia-192.png" alt="" width="28" height="28" /> Fluvia
        </div>
          <h1>{t.titre}</h1>
          <div className="banner banner-error">
            Ce lien ne porte aucun jeton. Ouvrez-le depuis le courriel plutôt que de le retaper.
          </div>
          <button className="btn" type="button" onClick={onTermine}>Aller à la connexion</button>
        </div>
      </div>
    )
  }

  if (fait) {
    return (
      <div className="login-wrap">
        <div className="login-card">
        <div className="side-brand">
          <img className="logo" src="/fluvia-192.png" alt="" width="28" height="28" /> Fluvia
        </div>
          <h1>{t.titre}</h1>
          <div className="banner banner-ok">{t.succes}</div>
          <button className="btn primary" type="button" onClick={onTermine}>Se connecter</button>
        </div>
      </div>
    )
  }

  return (
    <div className="login-wrap">
      <form className="login-card" onSubmit={envoyer}>
        <div className="side-brand">
          <img className="logo" src="/fluvia-192.png" alt="" width="28" height="28" /> Fluvia
        </div>
        <h1>{t.titre}</h1>
        <p className="login-sub">{t.intro}</p>

        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="field">
          <label className="field-lbl" htmlFor="ac-mdp">Nouveau mot de passe</label>
          <input id="ac-mdp" className="input" type="password" autoComplete="new-password"
            value={motDePasse} onChange={(ev) => setMotDePasse(ev.target.value)} />
          <span className="hint">
            {tropCourt
              ? `${TAILLE_MIN} caractères au minimum — il en manque ${TAILLE_MIN - motDePasse.length}.`
              : `${TAILLE_MIN} caractères au minimum.`}
          </span>
        </div>

        <div className="field">
          <label className="field-lbl" htmlFor="ac-conf">Répétez-le</label>
          <input id="ac-conf" className="input" type="password" autoComplete="new-password"
            value={confirmation} onChange={(ev) => setConfirmation(ev.target.value)} />
          {discordent && <span className="hint">Les deux saisies diffèrent.</span>}
        </div>

        <button className="btn primary" type="submit" disabled={enCours || !pret}>
          {enCours ? 'Enregistrement…' : t.bouton}
        </button>
        <button className="btn ghost" type="button" onClick={onTermine}>Retour à la connexion</button>
      </form>
    </div>
  )
}
