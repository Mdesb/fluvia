// Onglets réutilisables (segmented control « .seg »). `onglets` = [[cle, libelle], …].
// Remplace la répétition du même bloc dans Paramètres / Comptabilité / Boutique.
//
// `actions` — CE QU'ON PEUT FAIRE SUR L'ONGLET COURANT, A DROITE DE LA MEME LIGNE.
//
// Le bouton primaire d'un ecran de liste (« ＋ Nouveau produit ») etait pose SOUS les filtres,
// entre eux et la liste : deux blocs le separaient du titre, il coutait une ligne de hauteur, et
// il repoussait d'autant la liste qu'on vient chercher. Sur la ligne des onglets, il est la ou
// l'oeil le cherche — en haut a droite — et ne prend plus de place a lui.
//
// Sans `actions`, le balisage ne bouge pas : vingt-cinq ecrans appellent ce composant.
export default function Tabs({ onglets, actif, onChange, style, actions = null }) {
  const seg = (
    <div className="seg" style={{ marginBottom: actions ? 0 : 'var(--esp-bloc)', flexWrap: 'wrap', ...style }}>
      {onglets.map(([cle, libelle]) => (
        <button
          key={cle}
          type="button"
          className={actif === cle ? 'on' : ''}
          onClick={() => onChange(cle)}
        >
          {libelle}
        </button>
      ))}
    </div>
  )

  if (!actions) return seg

  // `flexWrap` : en etroit, les actions repassent sous les onglets plutot que de comprimer le
  // segmented control jusqu'a rendre les libelles illisibles.
  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 'var(--esp-large)',
        flexWrap: 'wrap',
        marginBottom: 'var(--esp-bloc)',
      }}
    >
      {seg}
      <div style={{ display: 'flex', gap: 'var(--esp-normal)', flexWrap: 'wrap' }}>{actions}</div>
    </div>
  )
}
