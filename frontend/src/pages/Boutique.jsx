import { useState } from 'react'
import VitrinesBoutique from '../components/VitrinesBoutique.jsx'
import Liste, { texte } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import DemandesRemboursement from '../components/DemandesRemboursement.jsx'
import RetraitsClickCollect from '../components/RetraitsClickCollect.jsx'
import { api } from '../api/client.js'

// Boutique en ligne (M3, vue admin) : demandes de remboursement, comptes clients, vitrines.
export default function Boutique({ etabActif, droits }) {
  const [sousOnglet, setSousOnglet] = useState('remboursements')

  return (
    <div className="view">
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
        ]}
        actif={sousOnglet}
        onChange={setSousOnglet}
      />

      {sousOnglet === 'remboursements' && (
        <DemandesRemboursement etabActif={etabActif} droits={droits} />
      )}

      {sousOnglet === 'retraits' && (
        <RetraitsClickCollect etabActif={etabActif} droits={droits} />
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
