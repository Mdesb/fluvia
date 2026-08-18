// En-tête public : marque de la vitrine, navigation principale, accès panier et compte.
// Sémantique : <header> + <nav>. Le lien d'évitement (« Aller au contenu ») précède la navigation.

export default function PublicHeader({ vitrine, nbArticles, connecte, onNaviguer, vue }) {
  const logo = vitrine?.logo
  return (
    <header className="pub-header">
      <a className="pub-skip" href="#pub-main">
        Aller au contenu
      </a>
      <div className="pub-header-in">
        <button
          type="button"
          className="pub-brand"
          onClick={() => onNaviguer({ vue: 'vitrine' })}
          aria-label="Retour à la boutique"
        >
          {logo ? (
            <img className="pub-brand-logo" src={logo} alt="" />
          ) : (
            <span className="pub-brand-mark" aria-hidden="true">
              ◈
            </span>
          )}
          <span className="pub-brand-txt">Billetterie</span>
        </button>

        <nav className="pub-nav" aria-label="Navigation principale">
          <button
            type="button"
            className={vue === 'vitrine' ? 'pub-nav-link on' : 'pub-nav-link'}
            aria-current={vue === 'vitrine' ? 'page' : undefined}
            onClick={() => onNaviguer({ vue: 'vitrine' })}
          >
            Boutique
          </button>
          <button
            type="button"
            className={vue === 'compte' ? 'pub-nav-link on' : 'pub-nav-link'}
            aria-current={vue === 'compte' ? 'page' : undefined}
            onClick={() => onNaviguer({ vue: 'compte' })}
          >
            {connecte ? 'Mon compte' : 'Se connecter'}
          </button>
          <button
            type="button"
            className={vue === 'panier' ? 'pub-nav-link pub-cart on' : 'pub-nav-link pub-cart'}
            aria-current={vue === 'panier' ? 'page' : undefined}
            onClick={() => onNaviguer({ vue: 'panier' })}
          >
            Panier
            <span className="pub-cart-count" aria-label={`${nbArticles} article(s) dans le panier`}>
              {nbArticles}
            </span>
          </button>
        </nav>
      </div>
    </header>
  )
}
