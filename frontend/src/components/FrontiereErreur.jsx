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
export default class FrontiereErreur extends Component {
  constructor(props) {
    super(props)
    this.state = { erreur: null }
  }

  static getDerivedStateFromError(erreur) {
    return { erreur }
  }

  componentDidCatch(erreur, infos) {
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
    const { erreur } = this.state
    if (!erreur) return this.props.children

    return (
      <div className="view">
        <div className="view-head">
          <div className="ttl">
            <h1>Cet écran n’a pas pu s’afficher</h1>
            <p>Le reste de l’application fonctionne : vous pouvez changer d’écran par le menu.</p>
          </div>
        </div>

        <div className="banner banner-error">
          <b>{erreur?.message || 'Erreur inattendue.'}</b>
        </div>

        <div className="card">
          <div className="card-b">
            <p style={{ marginTop: 0 }}>
              Ce n’est pas une perte de données : rien n’a été enregistré ni modifié. Le défaut est
              dans l’affichage de cet écran.
            </p>
            <div style={{ display: 'flex', gap: 8 }}>
              <button
                className="btn primary"
                type="button"
                onClick={() => this.setState({ erreur: null })}
              >
                Réessayer
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
