import Liste, { texte } from '../components/Liste.jsx'
import { api } from '../api/client.js'

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

// Verticale Piscine (consultation) : bassins, créneaux et jauges grand public (FMI).
export default function Piscine({ etabActif }) {
  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Piscine</h1>
          <p>Bassins, créneaux et jauges de fréquentation</p>
        </div>
      </div>

      <div className="resa-grid">
        <Liste
          titre="Bassins"
          sous="capacité &amp; occupation"
          deps={[etabActif]}
          charger={api.bassins}
          vide="Aucun bassin déclaré."
          colonnes={[
            { cle: 'libelle', entete: 'Bassin', rendu: (r) => <span className="nm">{texte(r.libelle, r.code || 'Bassin')}</span> },
            { cle: 'nbLignes', entete: 'Lignes', num: true, rendu: (r) => r.nbLignes ?? '—' },
            { cle: 'capacite', entete: 'Capacité', num: true, rendu: (r) => r.capacite ?? '—' },
            { cle: 'occupationCourante', entete: 'Occupation', num: true, rendu: (r) => r.occupationCourante ?? 0 },
          ]}
        />

        <Liste
          titre="Jauges grand public"
          sous="places restantes (FMI)"
          deps={[etabActif]}
          charger={api.jaugesGrandPublic}
          vide="Aucune jauge calculée."
          colonnes={[
            { cle: 'creneauBassin', entete: 'Créneau bassin', rendu: (r) => <span className="mono">{String(r.creneauBassin || '').split('/').pop() || '—'}</span> },
            { cle: 'capaciteRestante', entete: 'Places restantes', num: true, rendu: (r) => (
              <span className={`badge ${(r.capaciteRestante ?? 0) <= 0 ? 'crit' : 'good'}`}>{r.capaciteRestante ?? '—'}</span>
            ) },
            { cle: 'modeProrata', entete: 'Mode', rendu: (r) => r.modeProrata || '—' },
          ]}
        />
      </div>

      <div style={{ marginTop: 16 }}>
        <Liste
          titre="Créneaux bassins"
          sous="planning surveillance"
          deps={[etabActif]}
          charger={api.creneauxBassin}
          vide="Aucun créneau planifié."
          colonnes={[
            { cle: 'bassin', entete: 'Bassin', rendu: (r) => texte(r.bassin?.libelle, String(r.bassin || '').split('/').pop() || '—') },
            { cle: 'debut', entete: 'Début', rendu: (r) => heure(r.debut) },
            { cle: 'fin', entete: 'Fin', rendu: (r) => heure(r.fin) },
            { cle: 'encadrantRequis', entete: 'Encadrant', rendu: (r) => (r.encadrantRequis ? 'requis' : '—') },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
          ]}
        />
      </div>
    </div>
  )
}
