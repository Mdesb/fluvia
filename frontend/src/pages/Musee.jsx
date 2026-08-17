import Liste, { texte, dateFr } from '../components/Liste.jsx'
import { api } from '../api/client.js'

// Verticale Musée (consultation) : expositions et visites guidées.
export default function Musee({ etabActif }) {
  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Musée</h1>
          <p>Expositions &amp; visites guidées</p>
        </div>
      </div>

      <div className="resa-grid">
        <Liste
          titre="Expositions"
          sous="jauges &amp; période"
          deps={[etabActif]}
          charger={api.museeExpositions}
          vide="Aucune exposition."
          colonnes={[
            { cle: 'produit', entete: 'Exposition', rendu: (r) => <span className="nm">{texte(r.libelle, String(r.produit || '').split('/').pop() || 'Exposition')}</span> },
            { cle: 'dateDebut', entete: 'Début', rendu: (r) => dateFr(r.dateDebut) },
            { cle: 'dateFin', entete: 'Fin', rendu: (r) => dateFr(r.dateFin) },
            { cle: 'aJauge', entete: 'Jauge', rendu: (r) => (r.aJauge ? <span className="badge info">{r.jaugeGlobale ?? '●'}</span> : '—') },
          ]}
        />

        <Liste
          titre="Visites guidées"
          sous="thème, langue, guide"
          deps={[etabActif]}
          charger={api.museeVisitesGuidees}
          vide="Aucune visite guidée."
          colonnes={[
            { cle: 'theme', entete: 'Thème', rendu: (r) => <span className="nm">{texte(r.theme, '—')}</span> },
            { cle: 'langue', entete: 'Langue', rendu: (r) => r.langue || '—' },
            { cle: 'pointRDV', entete: 'RDV', rendu: (r) => r.pointRDV || '—' },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
          ]}
        />
      </div>
    </div>
  )
}
