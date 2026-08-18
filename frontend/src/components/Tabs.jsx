// Onglets réutilisables (segmented control « .seg »). `onglets` = [[cle, libelle], …].
// Remplace la répétition du même bloc dans Paramètres / Comptabilité / Boutique.
export default function Tabs({ onglets, actif, onChange, style }) {
  return (
    <div className="seg" style={{ marginBottom: 16, flexWrap: 'wrap', ...style }}>
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
}
