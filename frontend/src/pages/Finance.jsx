import { useState } from 'react'
import Tabs from '../components/Tabs.jsx'
import FacturesFournisseur from '../components/FacturesFournisseur.jsx'
import NotesDeFrais from '../components/NotesDeFrais.jsx'

// Achats & trésorerie — soixante opérations exposées, sept atteignables jusqu'ici.
//
// L'écran s'ouvre sur les factures fournisseur parce que c'est le seul endroit du module où
// quelqu'un attend : un fournisseur qu'on ne paie pas relance, puis arrête de livrer.
//
// ── LES NOTES DE FRAIS ARRIVENT EN SECOND, ET POUR LA MÊME RAISON ───────────────────────────────
//
// Elles n'avaient AUCUN écran : six opérations serveur, toutes injoignables. C'est le deuxième
// endroit du module où quelqu'un attend — un salarié qui a avancé de l'argent. Un remboursement
// qu'on ne peut pas tracer se règle de travers, puis se discute après coup.
//
// ⚠ CE QUI RESTE SANS ÉCRAN, ET QUI SE DIT PLUTÔT QUE DE SE TAIRE : la trésorerie proprement dite
// — comptes bancaires, rapprochement, position, échéancier, prévision. Tout existe côté serveur,
// droits compris (`finance.treasury_manage_account`, `_import_statement`, `_reconcile`). Le titre
// de cet écran promet donc encore plus qu'il ne rend, et le sous-titre le dit maintenant.
export default function Finance({ etabActif, droits }) {
  const [onglet, setOnglet] = useState('fournisseurs')

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Achats &amp; trésorerie</h1>
          <p>
            Factures fournisseur, notes de frais et circuit de paiement — la trésorerie
            (comptes, rapprochement, prévision) n’a pas encore d’écran.
          </p>
        </div>
      </div>

      <Tabs
        actif={onglet}
        onChange={setOnglet}
        onglets={[
          ['fournisseurs', 'Factures fournisseur'],
          ['frais', 'Notes de frais'],
        ]}
      />

      {onglet === 'fournisseurs' ? (
        <FacturesFournisseur etabActif={etabActif} droits={droits} />
      ) : (
        <NotesDeFrais etabActif={etabActif} droits={droits} />
      )}
    </div>
  )
}
