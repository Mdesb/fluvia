import { useEffect, useState } from 'react'
import { boutique, clientTokenStore } from '../api/boutiqueClient.js'
import { euros, dateCourte } from '../lib/format.js'
import { Chargement, Erreur, Vide } from '../components/Etats.jsx'
import Tabs from '../../components/Tabs.jsx'
import Qr from '../../components/Qr.jsx'

/**
 * ESPACE CLIENT — et, depuis le 27/08, la porte d'entrée qui manquait.
 *
 * **Ce que la boutique ne savait pas faire.** Elle savait CONNECTER un client et pas en INSCRIRE un.
 * L'appel existait depuis le premier jour — `boutique.creerCompte`, avec le rattachement du panier
 * en cours « sans perte de contenu » — et aucun écran ne l'appelait. Un visiteur sans compte n'avait
 * donc aucun moyen d'en obtenir un : l'écran lui demandait des identifiants qu'il ne pouvait pas
 * créer.
 *
 * > **Une porte qu'on ne peut ouvrir que de l'intérieur n'est pas une porte.**
 *
 * Le défaut n'apparaissait dans aucun test parce qu'il n'y avait rien à tester : le serveur était
 * juste, le client aussi, et c'est le chemin entre les deux qui n'existait pas. C'est exactement ce
 * que compte le garde-fou n°15 — un appel défini qu'aucun écran n'atteint.
 *
 * **Le panier suit le compte, et c'est pour ça que l'inscription est ICI plutôt qu'ailleurs.**
 * Quelqu'un qui crée un compte a très souvent déjà rempli son panier. Le lui faire recommencer,
 * c'est perdre la vente qu'on venait de gagner.
 */
export default function MonCompte({ connecte, onConnexionChange, onNaviguer, vitrineId, panierId, onPanierRattache }) {
  if (!connecte) {
    return (
      <ConnexionClient
        onConnecte={() => onConnexionChange(true)}
        onNaviguer={onNaviguer}
        vitrineId={vitrineId}
        panierId={panierId}
        onPanierRattache={onPanierRattache}
      />
    )
  }
  return <EspaceClient onDeconnexion={() => onConnexionChange(false)} onNaviguer={onNaviguer} />
}

function ConnexionClient({ onConnecte, onNaviguer, vitrineId, panierId, onPanierRattache }) {
  const [mode, setMode] = useState('connexion')

  if (mode === 'inscription') {
    return (
      <CreationCompte
        vitrineId={vitrineId}
        panierId={panierId}
        onCree={onConnecte}
        onPanierRattache={onPanierRattache}
        onRetour={() => setMode('connexion')}
      />
    )
  }

  return <Connexion onConnecte={onConnecte} onNaviguer={onNaviguer} onInscrire={() => setMode('inscription')} />
}

function Connexion({ onConnecte, onNaviguer, onInscrire }) {
  const [email, setEmail] = useState('')
  const [motDePasse, setMotDePasse] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setBusy(true)
    try {
      const data = await boutique.login(email.trim(), motDePasse)
      if (!data?.token) throw new Error("Réponse inattendue du serveur (jeton manquant).")
      clientTokenStore.set(data.token)
      onConnecte()
    } catch (err) {
      setErreur(err?.status === 401 ? 'Identifiants incorrects.' : err?.message || 'Connexion impossible.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section aria-labelledby="bq-cnx-titre" className="bq-narrow">
      <form className="card" onSubmit={soumettre} style={{ maxWidth: 440, margin: '10px auto' }}>
        <div className="card-h">
          <h2 id="bq-cnx-titre">Se connecter</h2>
        </div>
        <div className="card-b">
          <p className="bq-sub" style={{ marginTop: 0 }}>
            Accédez à vos commandes et à vos billets.
          </p>
          <Erreur message={erreur} id="bq-cnx-err" />
          <div className="field">
            <label htmlFor="bq-cnx-email">Adresse e-mail</label>
            <input
              id="bq-cnx-email"
              className="input"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              aria-describedby={erreur ? 'bq-cnx-err' : undefined}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="bq-cnx-mdp">Mot de passe</label>
            <input
              id="bq-cnx-mdp"
              className="input"
              type="password"
              autoComplete="current-password"
              value={motDePasse}
              onChange={(e) => setMotDePasse(e.target.value)}
              required
            />
          </div>
          <button type="submit" className="btn primary lg" disabled={busy}>
            {busy ? 'Connexion…' : 'Se connecter'}
          </button>
          <button
            type="button"
            className="btn lg"
            style={{ marginTop: 8 }}
            onClick={onInscrire}
          >
            Créer un compte
          </button>
          <button
            type="button"
            className="btn lg"
            style={{ marginTop: 8 }}
            onClick={() => onNaviguer({ vue: 'vitrine' })}
          >
            Retour à la boutique
          </button>
        </div>
      </form>
    </section>
  )
}

function EspaceClient({ onDeconnexion, onNaviguer }) {
  const [onglet, setOnglet] = useState('commandes')
  const [compte, setCompte] = useState(null)
  const [commandes, setCommandes] = useState(null)
  const [billets, setBillets] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  async function charger() {
    setChargement(true)
    setErreur(null)
    try {
      const [c, cmd, bil] = await Promise.all([
        boutique.moiCompte().catch(() => null),
        boutique.mesCommandes().catch(() => ({ commandes: [] })),
        boutique.mesBillets().catch(() => ({ billets: [] })),
      ])
      setCompte(c)
      setCommandes(cmd?.commandes || [])
      setBillets(bil?.billets || [])
    } catch (e) {
      if (e?.status === 401) {
        clientTokenStore.clear()
        onDeconnexion()
        return
      }
      setErreur(e?.message || 'Chargement impossible.')
    } finally {
      setChargement(false)
    }
  }

  useEffect(() => {
    charger()
  }, [])

  function deconnexion() {
    clientTokenStore.clear()
    onDeconnexion()
    onNaviguer({ vue: 'vitrine' })
  }

  return (
    <section aria-labelledby="bq-espace-titre">
      <div className="bq-view-head">
        <div>
          <h1 id="bq-espace-titre">Mon compte</h1>
          {compte?.client && (
            <p className="bq-sub">Bienvenue dans votre espace personnel.</p>
          )}
        </div>
        <button type="button" className="btn" onClick={deconnexion}>
          Se déconnecter
        </button>
      </div>

      <Tabs
        onglets={[
          ['commandes', 'Mes commandes'],
          ['billets', 'Mes billets'],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <Chargement texte="Chargement de votre espace…" />
      ) : erreur ? (
        <Erreur message={erreur} onReessayer={charger} />
      ) : onglet === 'commandes' ? (
        <Commandes commandes={commandes} />
      ) : (
        <Billets billets={billets} />
      )}
    </section>
  )
}

function Commandes({ commandes }) {
  if (!commandes || commandes.length === 0) {
    return <Vide titre="Aucune commande" texte="Vos commandes payées apparaîtront ici." />
  }
  return (
    <div className="card">
      <div className="card-b" style={{ overflowX: 'auto' }}>
        <table className="tbl">
          <caption className="sr-only">Historique de mes commandes</caption>
          <thead>
            <tr>
              <th scope="col">Numéro</th>
              <th scope="col">Date</th>
              <th scope="col">Montant</th>
              <th scope="col">Statut</th>
            </tr>
          </thead>
          <tbody>
            {commandes.map((c) => (
              <tr key={c.vente}>
                <td className="nm">{c.numero || c.vente?.slice(0, 8)}</td>
                <td>{dateCourte(c.date)}</td>
                <td className="num">{euros(c.total)}</td>
                <td>
                  <span className="badge info">{c.statutTunnel || c.statut}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function Billets({ billets }) {
  if (!billets || billets.length === 0) {
    return <Vide titre="Aucun billet" texte="Vos billets à QR apparaîtront ici après un achat." />
  }
  return (
    <ul className="bq-billets">
      {billets.map((b) => (
        <li key={b.billetSupport} className="bq-billet card">
          <div className="card-b bq-billet-b">
            <Qr value={b.qrDynamique || b.identifiantSupport} size={110} title="QR du billet" />
            <div>
              <p className="bq-billet-id mono">{b.identifiantSupport}</p>
              {b.passWalletDisponible && <span className="badge info">Wallet disponible</span>}
              {b.statutRetraitPhysique && (
                <span className="badge mut">{b.statutRetraitPhysique}</span>
              )}
            </div>
          </div>
        </li>
      ))}
    </ul>
  )
}

/**
 * CRÉER UN COMPTE, EN GARDANT SON PANIER.
 *
 * **La date de naissance est demandée parce que le serveur en a besoin, et l'écran dit pourquoi.**
 * Elle sert à ne pas confondre deux homonymes et à appliquer les tarifs liés à l'âge. Un champ
 * obligatoire dont personne ne comprend la raison est un champ qu'on remplit au hasard — et un âge
 * faux vaut un tarif faux.
 *
 * **Une adresse déjà prise n'est pas une erreur technique.** Le serveur répond 409 ; l'écran propose
 * de se connecter plutôt que d'afficher un code. La plupart des gens qui voient ce message ont
 * simplement oublié qu'ils avaient un compte.
 */
function CreationCompte({ vitrineId, panierId, onCree, onPanierRattache, onRetour }) {
  const [champs, setChamps] = useState({
    email: '', motDePasse: '', nom: '', prenom: '', dateNaissance: '',
  })
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  const poser = (cle) => (e) => setChamps((c) => ({ ...c, [cle]: e.target.value }))

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)

    if (!vitrineId) {
      // Sans vitrine, le serveur refuserait en 422. On le dit avant l'envoi plutot qu'apres.
      setErreur('Ouvrez la boutique avant de créer un compte : celui-ci est rattaché à un établissement.')
      return
    }

    setBusy(true)
    try {
      await boutique.creerCompte({
        vitrine: vitrineId,
        email: champs.email.trim(),
        motDePasse: champs.motDePasse,
        nom: champs.nom.trim(),
        prenom: champs.prenom.trim(),
        dateNaissance: champs.dateNaissance,
        // LE PANIER SUIT. C'est la moitie de l'interet de creer le compte a ce moment-la.
        ...(panierId ? { panier: panierId } : {}),
      })

      // Le compte est cree, pas connecte : le serveur ne rend pas de jeton ici. On enchaine sur
      // l'authentification pour que la personne n'ait pas a resaisir ce qu'elle vient de taper.
      const data = await boutique.login(champs.email.trim(), champs.motDePasse)
      if (!data?.token) throw new Error('Compte créé, mais la connexion a échoué. Essayez de vous connecter.')
      clientTokenStore.set(data.token)
      if (panierId && onPanierRattache) onPanierRattache()
      onCree()
    } catch (err) {
      if (err?.status === 409) {
        setErreur('Un compte existe déjà avec cette adresse. Connectez-vous plutôt.')
      } else {
        setErreur(err?.message || 'La création du compte a échoué.')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <section aria-labelledby="bq-insc-titre" className="bq-narrow">
      <form className="card" onSubmit={soumettre} style={{ maxWidth: 440, margin: '10px auto' }}>
        <div className="card-h">
          <h2 id="bq-insc-titre">Créer un compte</h2>
        </div>
        <div className="card-b">
          <p className="bq-sub" style={{ marginTop: 0 }}>
            {panierId
              ? 'Votre panier en cours sera conservé et rattaché à votre compte.'
              : 'Retrouvez ensuite vos commandes et vos billets à tout moment.'}
          </p>
          <Erreur message={erreur} id="bq-insc-err" />

          <div className="field">
            <label htmlFor="bq-insc-email">Adresse e-mail</label>
            <input
              id="bq-insc-email" className="input" type="email" autoComplete="username"
              value={champs.email} onChange={poser('email')}
              aria-describedby={erreur ? 'bq-insc-err' : undefined} required
            />
          </div>

          <div className="field">
            <label htmlFor="bq-insc-mdp">Mot de passe</label>
            <input
              id="bq-insc-mdp" className="input" type="password" autoComplete="new-password"
              value={champs.motDePasse} onChange={poser('motDePasse')} minLength={8} required
            />
          </div>

          <div className="field">
            <label htmlFor="bq-insc-nom">Nom</label>
            <input
              id="bq-insc-nom" className="input" type="text" autoComplete="family-name"
              value={champs.nom} onChange={poser('nom')} required
            />
          </div>

          <div className="field">
            <label htmlFor="bq-insc-prenom">Prénom</label>
            <input
              id="bq-insc-prenom" className="input" type="text" autoComplete="given-name"
              value={champs.prenom} onChange={poser('prenom')}
            />
          </div>

          <div className="field">
            <label htmlFor="bq-insc-naissance">Date de naissance</label>
            <input
              id="bq-insc-naissance" className="input" type="date" autoComplete="bday"
              value={champs.dateNaissance} onChange={poser('dateNaissance')} required
            />
            <p className="bq-sub" style={{ margin: '4px 0 0', fontSize: 12.5 }}>
              Elle évite de confondre deux personnes de même nom et permet d’appliquer les tarifs
              liés à l’âge.
            </p>
          </div>

          <button type="submit" className="btn primary lg" disabled={busy}>
            {busy ? 'Création…' : 'Créer mon compte'}
          </button>
          <button type="button" className="btn lg" style={{ marginTop: 8 }} onClick={onRetour}>
            J’ai déjà un compte
          </button>
        </div>
      </form>
    </section>
  )
}
