import { useEffect, useState } from 'react'
import { boutique, clientTokenStore } from '../api/boutiqueClient.js'
import { euros, dateCourte } from '../lib/format.js'
import { Chargement, Erreur, Vide } from '../components/Etats.jsx'
import Tabs from '../../components/Tabs.jsx'
import Qr from '../../components/Qr.jsx'

// Espace client : connexion (JWT client via /auth) puis « Mes commandes » / « Mes billets ».
export default function MonCompte({ connecte, onConnexionChange, onNaviguer }) {
  if (!connecte) {
    return <ConnexionClient onConnecte={() => onConnexionChange(true)} onNaviguer={onNaviguer} />
  }
  return <EspaceClient onDeconnexion={() => onConnexionChange(false)} onNaviguer={onNaviguer} />
}

function ConnexionClient({ onConnecte, onNaviguer }) {
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
    <section aria-labelledby="pub-cnx-titre" className="pub-narrow">
      <form className="card" onSubmit={soumettre} style={{ maxWidth: 440, margin: '10px auto' }}>
        <div className="card-h">
          <h2 id="pub-cnx-titre">Se connecter</h2>
        </div>
        <div className="card-b">
          <p className="pub-sub" style={{ marginTop: 0 }}>
            Accédez à vos commandes et à vos billets.
          </p>
          <Erreur message={erreur} id="pub-cnx-err" />
          <div className="field">
            <label htmlFor="pub-cnx-email">Adresse e-mail</label>
            <input
              id="pub-cnx-email"
              className="input"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              aria-describedby={erreur ? 'pub-cnx-err' : undefined}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="pub-cnx-mdp">Mot de passe</label>
            <input
              id="pub-cnx-mdp"
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
    <section aria-labelledby="pub-espace-titre">
      <div className="pub-view-head">
        <div>
          <h1 id="pub-espace-titre">Mon compte</h1>
          {compte?.client && (
            <p className="pub-sub">Bienvenue dans votre espace personnel.</p>
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
    <ul className="pub-billets">
      {billets.map((b) => (
        <li key={b.billetSupport} className="pub-billet card">
          <div className="card-b pub-billet-b">
            <Qr value={b.qrDynamique || b.identifiantSupport} size={110} title="QR du billet" />
            <div>
              <p className="pub-billet-id mono">{b.identifiantSupport}</p>
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
