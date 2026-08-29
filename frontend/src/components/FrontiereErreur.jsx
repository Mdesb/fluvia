import { Component } from 'react'

// UN ÉCRAN QUI PLANTE NE DOIT PAS EMPORTER L'APPLICATION.
//
// Le 28/08, `Pilotage.jsx` lisait `GLOSSAIRE` sans l'importer. Au rendu : `ReferenceError`, React
// démonte tout l'arbre, et l'utilisateur se retrouve devant une **page blanche**. Pas un écran en
// erreur — une page blanche, sans menu, sans bouton, sans rien. Le seul recours est de savoir qu'il
// faut recharger.
//
// > **Sans frontière d'erreur, le défaut le plus étroit du dépôt a la portée la plus large : un
// > identifiant oublié dans un écran de reporting ferme la caisse.**
//
// React ne le fait pas tout seul : sa console le dit à chaque plantage (« Consider adding an error
// boundary »), et personne ne l'avait lu parce que personne n'avait ouvert cet écran.
//
// CE QUE CETTE FRONTIÈRE FAIT, ET CE QU'ELLE NE FAIT PAS.
//
// Elle entoure le CONTENU, pas la coquille : le menu, le sélecteur d'établissement et la barre du
// haut survivent. L'utilisateur peut donc partir ailleurs — c'est toute la différence entre un
// écran cassé et un logiciel cassé.
//
// Elle ne masque rien : le message nomme l'écran et l'erreur, et le bouton « Réessayer » remonte la
// frontière plutôt que de recharger la page — un rechargement ferait repayer la connexion et le
// chargement du contexte pour un défaut d'affichage.
//
// ⚠ Elle n'attrape QUE les erreurs de rendu. Une promesse rejetée dans un gestionnaire d'événement
// n'y passe pas : ça reste le travail des `try/catch` des écrans.
// UN DÉPLOIEMENT CASSE LES ONGLETS RESTÉS OUVERTS, ET « RÉESSAYER » NE PEUT PAS Y RÉPONDRE.
//
// Constaté le 29/08 sur la préprod, les deux côtés mesurés :
//
//     ce que la page demandait    Finance-fQjpwJKa.js   → HTTP 404
//     ce que le serveur servait   Finance-DLV8kexz.js
//
// La page tient le manifeste du build qu'elle a chargé au démarrage. Après un déploiement, les
// empreintes changent : tout écran DIFFÉRÉ qu'on n'avait pas encore ouvert répond 404.
//
// Ce que ça coûte : une caisse reste ouverte toute la journée et on déploie plusieurs fois par
// jour. Un caissier qui ouvre en fin d'après-midi un écran qu'il n'avait pas visité le matin
// obtient un écran cassé, sans avoir rien fait, et sans pouvoir deviner la cause.
//
// ⚠ ET LE BOUTON « RÉESSAYER » RELANCE LE MÊME IMPORT VERS LA MÊME URL. Elle restera 404 tant que
// la page n'aura pas rechargé son manifeste. C'est un bouton qui propose une action qui ne peut
// pas aboutir — la famille qu'on traque : on clique deux fois, on conclut que c'est cassé, on
// appelle. Ici la seule réparation est de RECHARGER.
//
// DEUX CAUSES QUI SE RESSEMBLENT ET NE SE SOIGNENT PAS PAREIL. Un import échoue aussi quand le
// réseau est coupé. Recharger répare le premier cas et fait perdre la page dans le second. On
// regarde donc `navigator.onLine` avant de décider, plutôt que de recharger sur la seule forme du
// message.
function paquetPerime(erreur) {
  const m = String(erreur?.message || '')
  return /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed/i.test(m)
}

// Un seul rechargement automatique par session : si le paquet manque ENCORE après, ce n'est plus
// un manifeste périmé et un rechargement en boucle serait pire que l'erreur.
const CLE_RECHARGE = 'fluvia.recharge-paquet-perime'

export default class FrontiereErreur extends Component {
  constructor(props) {
    super(props)
    this.state = { erreur: null }
  }

  static getDerivedStateFromError(erreur) {
    return { erreur }
  }

  componentDidCatch(erreur, infos) {
    if (paquetPerime(erreur) && navigator.onLine !== false) {
      let dejaTente = false
      try {
        dejaTente = sessionStorage.getItem(CLE_RECHARGE) === '1'
        if (!dejaTente) sessionStorage.setItem(CLE_RECHARGE, '1')
      } catch {
        // Navigation privée ou stockage refusé : on ne recharge pas plutôt que de risquer la
        // boucle qu'on ne saurait plus compter.
        dejaTente = true
      }
      if (!dejaTente) {
        // On ANNONCE avant de recharger. Une page qui saute toute seule fait croire à un faux clic.
        this.setState({ rechargement: true })
        setTimeout(() => window.location.reload(), 1200)
      }
    }

    // On garde la trace dans la console : c'est là qu'on ira la chercher, et la masquer rendrait le
    // diagnostic plus difficile qu'avant la frontière.
    console.error('Écran en erreur :', erreur, infos?.componentStack)
  }

  componentDidUpdate(precedentes) {
    // Changer d'écran doit rendre l'ardoise propre. Sans ça, la frontière resterait ouverte sur le
    // message d'erreur du précédent, et l'utilisateur croirait que le nouvel écran plante aussi.
    if (this.state.erreur && precedentes.cle !== this.props.cle) {
      this.setState({ erreur: null })
    }
  }

  render() {
    const { erreur, rechargement } = this.state
    if (!erreur) return this.props.children

    if (rechargement) {
      return (
        <div className="view">
          <div className="card">
            <div className="card-b center" style={{ display: 'grid', gap: 'var(--esp-large)', justifyItems: 'center' }}>
              <div className="spinner" />
              <div>
                <b>Une nouvelle version de Fluvia est disponible.</b>
                <p className="hint" style={{ marginBottom: 0 }}>
                  Rechargement en cours… Rien n’a été perdu : la page se rouvre sur le même écran.
                </p>
              </div>
            </div>
          </div>
        </div>
      )
    }

    const perime = paquetPerime(erreur)

    return (
      <div className="view">
        <div className="view-head">
          <div className="ttl">
            <h1>Cet écran n’a pas pu s’afficher</h1>
            <p>Le reste de l’application fonctionne : vous pouvez changer d’écran par le menu.</p>
          </div>
        </div>

        <div className="banner banner-error">
          <b>{perime
            ? 'Cet écran appartient à une version de Fluvia qui n’est plus en ligne.'
            : (erreur?.message || 'Erreur inattendue.')}</b>
        </div>

        <div className="card">
          <div className="card-b">
            <p style={{ marginTop: 0 }}>
              {perime
                ? 'Ce n’est pas une perte de données : une mise à jour a eu lieu pendant que cet onglet était ouvert. Recharger la page suffit — le reste de l’application continue de fonctionner.'
                : 'Ce n’est pas une perte de données : rien n’a été enregistré ni modifié. Le défaut est dans l’affichage de cet écran.'}
            </p>
            <div style={{ display: 'flex', gap: 8 }}>
              {/* SUR UN PAQUET PÉRIMÉ, « RÉESSAYER » NE PEUT RIEN : il relancerait le même import
                  vers la même URL absente. Le bouton change donc de geste ET de nom. */}
              <button
                className="btn primary"
                type="button"
                onClick={() => (perime ? window.location.reload() : this.setState({ erreur: null }))}
              >
                {perime ? 'Recharger la page' : 'Réessayer'}
              </button>
            </div>
            <div className="hint">
              Si le message revient, signalez-le avec le nom de l’écran : le détail technique est
              dans la console du navigateur.
            </div>
          </div>
        </div>
      </div>
    )
  }
}
