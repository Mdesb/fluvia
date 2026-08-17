import Liste, { dateHeureFr } from '../components/Liste.jsx'
import { api } from '../api/client.js'

// Verticale Patinoire (consultation) : locations de patins, affûtages, conflits de glace.
export default function Patinoire({ etabActif }) {
  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Patinoire</h1>
          <p>Réservation glace, location de patins &amp; affûtage</p>
        </div>
      </div>

      <div className="resa-grid">
        <Liste
          titre="Locations de patins"
          sous="sorties &amp; retours"
          deps={[etabActif]}
          charger={api.patinoireLocations}
          vide="Aucune location en cours."
          colonnes={[
            { cle: 'parcPatins', entete: 'Parc', rendu: (r) => String(r.parcPatins?.libelle || r.parcPatins || '—').toString().split('/').pop() },
            { cle: 'dateSortie', entete: 'Sortie', rendu: (r) => dateHeureFr(r.dateSortie) },
            { cle: 'dateRetour', entete: 'Retour', rendu: (r) => dateHeureFr(r.dateRetour) },
            { cle: 'statut', entete: 'Statut', rendu: (r) => (
              <span className={`badge ${r.statut === 'EnCours' || r.statut === 'en_cours' ? 'info' : 'mut'}`}>{r.statut || '—'}</span>
            ) },
          ]}
        />

        <Liste
          titre="Affûtages"
          sous="atelier lames"
          deps={[etabActif]}
          charger={api.patinoireAffutages}
          vide="Aucun affûtage."
          colonnes={[
            { cle: 'id', entete: 'Réf.', rendu: (r) => <span className="mono">{String(r.id || '').slice(0, 8)}</span> },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
          ]}
        />
      </div>

      <div style={{ marginTop: 16 }}>
        <ConflitsGlace etabActif={etabActif} />
      </div>
    </div>
  )
}

// Conflits de glace : endpoint renvoyant un objet { conflits: [...] } (pas une collection Hydra).
function ConflitsGlace({ etabActif }) {
  return (
    <Liste
      titre="Conflits de créneaux glace"
      sous="chevauchements détectés"
      deps={[etabActif]}
      charger={api.patinoireConflits}
      transforme={(_, res) => res?.conflits || []}
      vide="Aucun conflit de glace détecté."
      cle={(r, i) => r.id || i}
      colonnes={[
        { cle: 'zone', entete: 'Zone / créneau', rendu: (r) => r.zone || r.libelle || r.creneau || '—' },
        { cle: 'details', entete: 'Détail', rendu: (r) => r.message || r.detail || JSON.stringify(r).slice(0, 60) },
      ]}
    />
  )
}
