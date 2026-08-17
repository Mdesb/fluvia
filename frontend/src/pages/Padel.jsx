import Liste, { dateFr } from '../components/Liste.jsx'
import { api } from '../api/client.js'

// Verticale Padel (consultation) : terrains et tournois.
export default function Padel({ etabActif }) {
  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Padel</h1>
          <p>Terrains &amp; tournois</p>
        </div>
      </div>

      <div className="resa-grid">
        <Liste
          titre="Terrains"
          sous="durées autorisées"
          deps={[etabActif]}
          charger={api.padelTerrains}
          vide="Aucun terrain déclaré."
          colonnes={[
            { cle: 'type', entete: 'Terrain', rendu: (r) => <span className="nm">{r.type || r.libelle || `Terrain ${String(r.id || '').slice(0, 6)}`}</span> },
            { cle: 'dureesAutoriseesMinutes', entete: 'Durées (min)', rendu: (r) => (Array.isArray(r.dureesAutoriseesMinutes) ? r.dureesAutoriseesMinutes.join(' / ') : '—') },
            { cle: 'actif', entete: 'État', rendu: (r) => (
              <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'actif' : 'inactif'}</span>
            ) },
          ]}
        />

        <Liste
          titre="Tournois"
          sous="format &amp; calendrier"
          deps={[etabActif]}
          charger={api.padelTournois}
          vide="Aucun tournoi programmé."
          colonnes={[
            { cle: 'nom', entete: 'Tournoi', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
            { cle: 'format', entete: 'Format', rendu: (r) => r.format || '—' },
            { cle: 'dateDebut', entete: 'Début', rendu: (r) => dateFr(r.dateDebut) },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
          ]}
        />
      </div>
    </div>
  )
}
