import { useState } from 'react'
import Liste, { dateHeureFr, texte } from '../components/Liste.jsx'
import { api } from '../api/client.js'

const STATUT_REMB = {
  Recue: 'warn', recue: 'warn', Acceptee: 'good', acceptee: 'good',
  Refusee: 'crit', refusee: 'crit', Traitee: 'good',
}

// Boutique en ligne (M3, vue admin) : demandes de remboursement, comptes clients, vitrines.
export default function Boutique({ etabActif }) {
  const [sousOnglet, setSousOnglet] = useState('remboursements')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Boutique en ligne</h1>
          <p>Commandes web, remboursements &amp; comptes clients</p>
        </div>
      </div>

      <div className="seg" style={{ marginBottom: 16 }}>
        {[
          ['remboursements', 'Remboursements'],
          ['comptes', 'Comptes clients'],
          ['vitrines', 'Vitrines'],
        ].map(([k, l]) => (
          <button key={k} className={sousOnglet === k ? 'on' : ''} onClick={() => setSousOnglet(k)}>{l}</button>
        ))}
      </div>

      {sousOnglet === 'remboursements' && (
        <Liste
          titre="Demandes de remboursement"
          sous="workflow reçue → acceptée / refusée"
          deps={[etabActif]}
          charger={api.demandesRemboursement}
          vide="Aucune demande de remboursement."
          colonnes={[
            { cle: 'id', entete: 'Réf.', rendu: (r) => <span className="mono">{String(r.id || '').slice(0, 8)}</span> },
            { cle: 'motif', entete: 'Motif', rendu: (r) => r.motif || '—' },
            { cle: 'origineAutomatique', entete: 'Origine', rendu: (r) => (r.origineAutomatique ? 'auto' : 'manuelle') },
            { cle: 'dateDemande', entete: 'Demandée le', rendu: (r) => dateHeureFr(r.dateDemande) },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className={`badge ${STATUT_REMB[r.statut] || 'mut'}`}>{r.statut || '—'}</span> },
          ]}
        />
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

      {sousOnglet === 'vitrines' && (
        <Liste
          titre="Vitrines"
          sous="points de vente en ligne"
          deps={[etabActif]}
          charger={api.vitrines}
          vide="Aucune vitrine configurée."
          colonnes={[
            { cle: 'libelle', entete: 'Vitrine', rendu: (r) => <span className="nm">{texte(r.libelle, r.nom || r.code || 'Vitrine')}</span> },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || r.etat || '—'}</span> },
          ]}
        />
      )}
    </div>
  )
}
