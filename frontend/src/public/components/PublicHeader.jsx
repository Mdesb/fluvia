import { useEffect, useState } from 'react'

// En-tête public : marque de la vitrine, navigation principale, accès panier et compte.
// Sémantique : <header> + <nav>. Le lien d'évitement (« Aller au contenu ») précède la navigation.

export default function PublicHeader({ vitrine, nbArticles, connecte, onNaviguer, vue }) {
  // UN LOGO CONFIGURÉ N'EST PAS UN LOGO QUI EXISTE.
  //
  // Le repli ne jouait que si `logo` était vide. Or la vitrine de démonstration pointe sur
  // `/assets/vitrine-a-logo.svg`, qui n'a jamais été déployé : la balise partait, le fichier
  // répondait 404, et **la première chose que voyait un client sur la boutique était une image
  // cassée**. C'est le cas courant, pas le cas limite — une adresse saisie par l'exploitant cesse
  // de répondre le jour où il refait son site, et personne ne le lui dira.
  //
  // On ne teste donc pas la configuration, on teste le chargement.
  const [logoCasse, setLogoCasse] = useState(false)
  const logo = logoCasse ? null : vitrine?.logo

  useEffect(() => {
    setLogoCasse(false)
  }, [vitrine?.logo])
  return (
    <header className="bq-header">
      <a className="bq-skip" href="#bq-main">
        Aller au contenu
      </a>
      <div className="bq-header-in">
        <button
          type="button"
          className="bq-brand"
          onClick={() => onNaviguer({ vue: 'vitrine' })}
          aria-label="Retour à la boutique"
        >
          {logo ? (
            <img className="bq-brand-logo" src={logo} alt="" onError={() => setLogoCasse(true)} />
          ) : (
            <span className="bq-brand-mark" aria-hidden="true">
              ◈
            </span>
          )}
          <span>Billetterie</span>
        </button>

        <nav className="bq-nav" aria-label="Navigation principale">
          <button
            type="button"
            className={vue === 'vitrine' ? 'bq-nav-link on' : 'bq-nav-link'}
            aria-current={vue === 'vitrine' ? 'page' : undefined}
            onClick={() => onNaviguer({ vue: 'vitrine' })}
          >
            Boutique
          </button>
          <button
            type="button"
            className={vue === 'compte' ? 'bq-nav-link on' : 'bq-nav-link'}
            aria-current={vue === 'compte' ? 'page' : undefined}
            onClick={() => onNaviguer({ vue: 'compte' })}
          >
            {connecte ? 'Mon compte' : 'Se connecter'}
          </button>
          <button
            type="button"
            className={vue === 'panier' ? 'bq-nav-link bq-cart on' : 'bq-nav-link bq-cart'}
            aria-current={vue === 'panier' ? 'page' : undefined}
            onClick={() => onNaviguer({ vue: 'panier' })}
          >
            Panier
            <span className="bq-cart-count" aria-label={`${nbArticles} article(s) dans le panier`}>
              {nbArticles}
            </span>
          </button>
        </nav>
      </div>
    </header>
  )
}
