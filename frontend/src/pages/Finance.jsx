import Tabs from '../components/Tabs.jsx'
import { useEtatUrl } from '../api/url.js'
import FacturesFournisseur from '../components/FacturesFournisseur.jsx'
import NotesDeFrais from '../components/NotesDeFrais.jsx'
import ComptesBancaires from '../components/ComptesBancaires.jsx'
import ImportReleve from '../components/ImportReleve.jsx'
import RapprochementBancaire from '../components/RapprochementBancaire.jsx'
import TresorerieDashboard from '../components/TresorerieDashboard.jsx'

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
// ── LA TRÉSORERIE A ENFIN SES ÉCRANS (T26) ───────────────────────────────────────────────────────
//
// Comptes bancaires, import de relevés et rapprochement existaient côté serveur — droits compris
// (`finance.treasury_manage_account`, `_import_statement`, `_reconcile`) — et AUCUN écran ne les
// ouvrait : du travail payé qui ne servait à rien. Quatre onglets de plus, et le titre ne promet plus
// que ce qu'il rend. Le dernier, « Position & prévision », ouvre les quatre lectures live du serveur
// (`/finance/treasury/position|payment-schedule|cashflow-forecast|discrepancies`) — position, prévision,
// échéancier, écarts — qui n'avaient elles non plus aucun écran.
// ⚠ L'ONGLET ENTRE DANS L'ADRESSE EN MÊME TEMPS QUE LES ÉCRANS. Sans lui, revenir d'un écran
// retomberait sur « Factures fournisseur » quel que soit l'onglet d'où l'on venait.
const DEFAUTS_URL = { tab: 'fournisseurs', facture: '', depense: '', note: '', compte: '', avoir: '' }

export default function Finance({ etabActif, droits }) {
  const [params, majParams] = useEtatUrl('finance', DEFAUTS_URL)
  const onglet = params.tab
  // Changer d'onglet ferme les écrans : un identifiant laissé dans l'adresse rouvrirait
  // l'écran d'un autre onglet dès qu'on y reviendrait.
  const setOnglet = (v) => majParams({ tab: v, facture: '', depense: '', note: '', compte: '', avoir: '' })
  // Un écran de niveau 2 prend la page : ni titre ni onglets au-dessus de lui.
  const ecranOuvert = Boolean(params.facture || params.depense || params.note || params.compte || params.avoir)

  return (
    <div className="view large">
      {!ecranOuvert && (<>
      <div className="view-head">
        <div className="ttl">
          <h1>Achats &amp; trésorerie</h1>
          <p>
            Factures fournisseur, notes de frais, comptes bancaires, import de relevés,
            rapprochement, position et prévision de trésorerie.
          </p>
        </div>
      </div>

      <Tabs
        actif={onglet}
        onChange={setOnglet}
        onglets={[
          ['fournisseurs', 'Factures fournisseur'],
          ['frais', 'Notes de frais'],
          ['comptes', 'Comptes bancaires'],
          ['import', 'Import de relevés'],
          ['rapprochement', 'Rapprochement'],
          ['position', 'Position & prévision'],
        ]}
      />
      </>)}

      {onglet === 'fournisseurs'
        && <FacturesFournisseur etabActif={etabActif} droits={droits} params={params} majParams={majParams} />}
      {onglet === 'frais'
        && <NotesDeFrais etabActif={etabActif} droits={droits} params={params} majParams={majParams} />}
      {onglet === 'comptes'
        && <ComptesBancaires etabActif={etabActif} droits={droits} params={params} majParams={majParams} />}
      {onglet === 'import' && <ImportReleve etabActif={etabActif} droits={droits} />}
      {onglet === 'rapprochement' && <RapprochementBancaire etabActif={etabActif} droits={droits} />}
      {onglet === 'position' && <TresorerieDashboard />}
    </div>
  )
}
