import { useEffect, useState } from 'react'
import { boutique, clientTokenStore } from '../api/boutiqueClient.js'
import { euros, eurosCentimes, dateCourte, libelleProduit } from '../lib/format.js'
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
  const [abonnements, setAbonnements] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  async function charger() {
    setChargement(true)
    setErreur(null)
    // ⚠ ON CAPTURE L'ERREUR AU LIEU DE L'ECRASER. `catch(() => ({ billets: [] }))` transformait un
    // refus en « vous n'avez rien achete » — la phrase la plus chere du produit, lue par quelqu'un
    // qui a paye et qui attend devant un tourniquet.
    //
    // Effet de bord de cette forme, et ce n'est pas un detail : le `catch` exterieur qui traite le
    // 401 ne s'executait JAMAIS, puisque chaque appel avalait sa propre erreur et que
    // `Promise.all` ne rejetait pas. Le chemin de reconnexion existait et etait mort.
    const enveloppe = (p) => p.then((v) => ({ v })).catch((e) => ({ e }))
    const [c, cmd, bil, abo] = await Promise.all([
      enveloppe(boutique.moiCompte()),
      enveloppe(boutique.mesCommandes()),
      enveloppe(boutique.mesBillets()),
      enveloppe(boutique.mesAbonnements()),
    ])

    // Session expiree ou revoquee : on le dit et on renvoie a la connexion, plutot que d'afficher
    // un espace client vide qui ressemble a un compte sans achats.
    if ([c, cmd, bil, abo].some((r) => r.e?.status === 401)) {
      clientTokenStore.clear()
      setChargement(false)
      onDeconnexion()
      return
    }

    setCompte(c.v ?? null)
    // `null` = PAS LU, `[]` = LU ET VIDE. La distinction etait deja a l'initialisation ; c'est le
    // `.catch()` qui la detruisait.
    setCommandes(cmd.e ? null : cmd.v?.commandes || [])
    setBillets(bil.e ? null : bil.v?.billets || [])
    setAbonnements(abo.e ? null : abo.v?.abonnements || [])
    if (cmd.e && bil.e && abo.e) setErreur(cmd.e?.message || 'Chargement impossible.')
    setChargement(false)
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
          ['abonnements', 'Mes abonnements'],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <Chargement texte="Chargement de votre espace…" />
      ) : erreur ? (
        <Erreur message={erreur} onReessayer={charger} />
      ) : onglet === 'commandes' ? (
        <Commandes commandes={commandes} nonLu={commandes === null} />
      ) : onglet === 'billets' ? (
        <Billets billets={billets} nonLu={billets === null} />
      ) : (
        <MesAbonnements abonnements={abonnements} nonLu={abonnements === null} />
      )}
    </section>
  )
}

/**
 * MES COMMANDES — et le geste qui n'existait nulle part : demander un remboursement.
 *
 * ⚠ L'ÉCRAN DE L'EXPLOITANT PROMETTAIT CE CHEMIN DEPUIS TOUJOURS. Il dit, mot pour mot : « Un client
 * qui demande un remboursement depuis la boutique en ligne apparaît ici, et y reste tant que
 * personne n'a répondu. » Or rien, dans la boutique publique, n'appelait
 * `POST /boutique/demandes-remboursement`.
 *
 * L'exploitant pouvait donc **accepter et refuser des demandes qui ne pouvaient pas naître**, sur
 * une file qui ne pouvait pas se remplir. Il lisait « aucune demande en attente » comme « personne
 * ne réclame », alors que cela voulait dire « personne ne peut réclamer ». Relevé par allaccess-b8.
 *
 * ⚠ CE QUE CET ÉCRAN NE PEUT PAS FAIRE, ET QU'IL DIT. La collection des demandes est réservée à
 * l'exploitant ; le client ne peut relire qu'une demande dont il a l'identifiant. Après un
 * rechargement, cet écran ne sait donc plus qu'une demande est en cours — il l'annonce au lieu de
 * laisser croire qu'il suit le dossier.
 */
function Commandes({ commandes, nonLu }) {
  const [pour, setPour] = useState(null)
  const [motif, setMotif] = useState('')
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [deposees, setDeposees] = useState({})

  if (!commandes || commandes.length === 0) {
    return nonLu ? (
      <Vide
        titre="Vos commandes n’ont pas pu être lues"
        texte="Cette liste est vide parce que la lecture a échoué, pas parce que vous n’avez rien commandé. Rechargez la page dans un moment."
      />
    ) : (
      <Vide titre="Aucune commande" texte="Vos commandes payées apparaîtront ici." />
    )
  }

  async function demander(commande) {
    if (!motif.trim()) return
    setEnvoi(true)
    setErreur(null)
    try {
      await boutique.deposerDemandeRemboursement({ vente: commande.vente, motif: motif.trim() })
      setDeposees((d) => ({ ...d, [commande.vente]: true }))
      setPour(null)
      setMotif('')
    } catch (e) {
      // Le serveur refuse une commande qui n'appartient pas au compte, et un motif vide. On affiche
      // son message : il nomme la raison mieux qu'une phrase générique.
      setErreur(e?.message || 'La demande n’a pas pu être envoyée.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <div className="card">
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {erreur && <div className="banner banner-error" role="alert">{erreur}</div>}
        <table className="tbl">
          <caption className="sr-only">Historique de mes commandes</caption>
          <thead>
            <tr>
              <th scope="col">Numéro</th>
              <th scope="col">Date</th>
              <th scope="col">Montant</th>
              <th scope="col">Statut</th>
              <th scope="col">Remboursement</th>
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
                <td>
                  {deposees[c.vente] ? (
                    <span className="badge">Demande envoyée</span>
                  ) : pour === c.vente ? (
                    <div className="field">
                      <label className="sr-only" htmlFor={`motif-${c.vente}`}>
                        Motif de votre demande de remboursement
                      </label>
                      <input
                        id={`motif-${c.vente}`}
                        className="input"
                        value={motif}
                        onChange={(e) => setMotif(e.target.value)}
                        placeholder="Pourquoi demandez-vous un remboursement ?"
                      />
                      <button
                        type="button"
                        className="btn primary"
                        disabled={envoi || !motif.trim()}
                        onClick={() => demander(c)}
                      >
                        {envoi ? 'Envoi…' : 'Envoyer'}
                      </button>
                      <button
                        type="button"
                        className="btn"
                        onClick={() => { setPour(null); setMotif(''); setErreur(null) }}
                      >
                        Annuler
                      </button>
                    </div>
                  ) : (
                    <button
                      type="button"
                      className="btn"
                      onClick={() => { setPour(c.vente); setMotif(''); setErreur(null) }}
                    >
                      Demander un remboursement
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <p className="bq-sub">
          Aucun remboursement n’est automatique : votre demande est transmise à l’établissement, qui
          y répond. Elle n’apparaîtra plus sur cette page après rechargement — conservez le message
          de confirmation.
        </p>
      </div>
    </div>
  )
}

function Billets({ billets, nonLu }) {
  if (!billets || billets.length === 0) {
    // ⚠ « Aucun billet » a quelqu'un qui a paye, c'est lui dire que son achat n'a pas abouti. On ne
    // le dit que quand on a REGARDE.
    return nonLu ? (
      <Vide
        titre="Vos billets n’ont pas pu être lus"
        texte="Ne concluez pas que votre achat n’a pas abouti : nous n’avons pas réussi à lire vos billets à l’instant. Rechargez la page dans un moment, et gardez votre e-mail de confirmation."
      />
    ) : (
      <Vide titre="Aucun billet" texte="Vos billets à QR apparaîtront ici après un achat." />
    )
  }
  return (
    <ul className="bq-billets">
      {billets.map((b) => (
        <li key={b.billetSupport} className="card">
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

// MES ABONNEMENTS — le contrat souscrit en ligne, que le client ne retrouvait NULLE PART. Statut,
// prochain prélèvement, mandat. Même distinction `null` (pas lu) / `[]` (lu et vide) que les autres.
// ⚠ Nom distinct de l'écran STAFF `Abonnements` (pages/Abonnements.jsx, qui teste des droits) : le
// garde-fou D39 résout les composants par nom, un homonyme lui ferait exiger `droits` ici.
function MesAbonnements({ abonnements, nonLu }) {
  if (!abonnements || abonnements.length === 0) {
    return nonLu ? (
      <Vide
        titre="Vos abonnements n’ont pas pu être lus"
        texte="Cette liste est vide parce que la lecture a échoué, pas parce que vous n’êtes pas abonné. Rechargez la page dans un moment."
      />
    ) : (
      <Vide titre="Aucun abonnement" texte="Vos abonnements souscrits en ligne apparaîtront ici." />
    )
  }
  return (
    <ul className="bq-billets">
      {abonnements.map((a) => (
        <li key={a.id} className="card">
          <div className="card-b">
            <p className="bq-billet-id">{libelleProduit({ libelle: a.libelle })}</p>
            <p>
              <span className="badge info">{a.statut}</span>{' '}
              <span className="badge mut">{a.periodicite}</span>
            </p>
            <p className="bq-sub">
              {eurosCentimes(a.montantCentimes)} · engagement du {dateCourte(a.dateDebutEngagement)} au{' '}
              {dateCourte(a.dateFinEngagement)}
            </p>
            {a.prochaineEcheance ? (
              <p className="bq-sub">
                Prochain prélèvement : {dateCourte(a.prochaineEcheance.date)} —{' '}
                {eurosCentimes(a.prochaineEcheance.montantCentimes)}
              </p>
            ) : (
              <p className="bq-sub">Aucun prélèvement à venir.</p>
            )}
            {a.mandatIban4Derniers && (
              <p className="bq-sub">
                Mandat SEPA •••• {a.mandatIban4Derniers} <span className="badge">{a.mandatStatut}</span>
              </p>
            )}
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
