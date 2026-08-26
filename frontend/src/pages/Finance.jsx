import FacturesFournisseur from '../components/FacturesFournisseur.jsx'

// Achats & trésorerie — soixante opérations exposées, sept atteignables jusqu'ici.
//
// L'écran s'ouvre sur les factures fournisseur parce que c'est le seul endroit du module où
// quelqu'un attend : un fournisseur qu'on ne paie pas relance, puis arrête de livrer. Le reste du
// module — trésorerie, rapprochement bancaire, notes de frais — répond à des questions qu'on se pose,
// pas à une attente.
export default function Finance({ etabActif, droits }) {
  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Achats &amp; trésorerie</h1>
          <p>Factures fournisseur, litiges et circuit de paiement</p>
        </div>
      </div>

      <FacturesFournisseur etabActif={etabActif} droits={droits} />
    </div>
  )
}
