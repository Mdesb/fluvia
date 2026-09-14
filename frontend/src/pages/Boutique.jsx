import VitrinesBoutique from '../components/VitrinesBoutique.jsx'
import Liste, { texte } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import DemandesRemboursement from '../components/DemandesRemboursement.jsx'
import RetraitsClickCollect from '../components/RetraitsClickCollect.jsx'
import PartenairesOta from '../components/PartenairesOta.jsx'
import { api } from '../api/client.js'
import { useEtatUrl } from '../api/url.js'

// Boutique en ligne (M3, vue admin) : demandes de remboursement, comptes clients, vitrines.
//
// ⚠ L'ONGLET ENTRE DANS L'ADRESSE EN MÊME TEMPS QUE L'ÉCRAN. Le traitement d'une demande vit
// dans un composant que seul l'onglet « Remboursements » monte : sans le paramètre `tab`, un
// F5 sur `?demande=…` retomberait sur un onglet qui ne le rend pas.
const DEFAUTS_URL = { tab: 'remboursements', demande: '', sens: '' }

export default function Boutique({ etabActif, droits }) {
  const [params, majParams] = useEtatUrl('boutique', DEFAUTS_URL)
  const sousOnglet = params.tab
  const setSousOnglet = (v) => majParams({ tab: v, demande: '', sens: '' })
  // Un écran de niveau 2 prend la page : ni titre ni onglets au-dessus de lui.
  const ecranOuvert = Boolean(params.demande)

  return (
    <div className="view">
      {!ecranOuvert && (<>
      <div className="view-head">
        <div className="ttl">
          <h1>Boutique en ligne</h1>
          <p>Commandes web, remboursements &amp; comptes clients</p>
        </div>
      </div>

      <Tabs
        onglets={[
          ['remboursements', 'Remboursements'],
          ['retraits', 'Retraits en boutique'],
          ['comptes', 'Comptes clients'],
          ['vitrines', 'Vitrines'],
          ['partenaires', 'Partenaires de revente'],
        ]}
        actif={sousOnglet}
        onChange={setSousOnglet}
      />
      </>)}

      {sousOnglet === 'remboursements' && (
        <DemandesRemboursement etabActif={etabActif} droits={droits} params={params} majParams={majParams} />
      )}

      {sousOnglet === 'retraits' && (
        <RetraitsClickCollect etabActif={etabActif} droits={droits} />
      )}

      {sousOnglet === 'partenaires' && (
        <PartenairesOta etabActif={etabActif} droits={droits} />
      )}

      {sousOnglet === 'comptes' && (
        <Liste
          titre="Comptes clients boutique"
          sous="titulaires de comptes en ligne"
          deps={[etabActif]}
          charger={api.comptesClientBoutique}
          vide="Aucun compte client."
          colonnes={[
            { cle: 'id', entete: 'Réf.', rendu: (r) => <span className="mono">{String(r.id || '').slice(0, 8)}</span> },
            { cle: 'client', entete: 'Client', rendu: (r) => String(r.client || '').split('/').pop() || '—' },
            { cle: 'franceConnectId', entete: 'FranceConnect', rendu: (r) => (r.franceConnectId ? <span className="badge info">lié</span> : '—') },
          ]}
        />
      )}

      {/* La liste generique montrait un libelle et un statut -- jamais l'ADRESSE. Un exploitant qui
          vient d'ouvrir sa boutique n'avait aucun moyen de savoir ou elle est. */}
      {sousOnglet === 'vitrines' && <VitrinesBoutique droits={droits} />}
    </div>
  )
}
