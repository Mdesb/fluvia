import { useState } from 'react'
import Liste, { dateFr, dateHeureFr } from '../components/Liste.jsx'
import { api } from '../api/client.js'

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

const STATUT_EMP = { Actif: 'good', actif: 'good', Suspendu: 'crit', suspendu: 'crit', Sorti: 'mut' }
const STATUT_BADGE = { Actif: 'good', actif: 'good', Revoque: 'crit', revoque: 'crit', Suspendu: 'warn' }
const COUV = { complet: 'good', partiel: 'warn', decouvert: 'crit', incomplet: 'crit' }

// Module Personnel : employés, roster (planning), badges staff.
export default function Personnel({ etabActif }) {
  const [sousOnglet, setSousOnglet] = useState('employes')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Personnel</h1>
          <p>Employés, planning (roster) &amp; badges staff</p>
        </div>
      </div>

      <div className="seg" style={{ marginBottom: 16 }}>
        {[
          ['employes', 'Employés'],
          ['roster', 'Roster'],
          ['badges', 'Badges staff'],
        ].map(([k, l]) => (
          <button key={k} className={sousOnglet === k ? 'on' : ''} onClick={() => setSousOnglet(k)}>{l}</button>
        ))}
      </div>

      {sousOnglet === 'employes' && (
        <Liste
          titre="Employés"
          sous="effectif de l'établissement"
          deps={[etabActif]}
          charger={api.employes}
          vide="Aucun employé."
          colonnes={[
            { cle: 'nom', entete: 'Employé', rendu: (r) => <span className="nm">{[r.prenom, r.nom].filter(Boolean).join(' ') || '—'}</span> },
            { cle: 'matricule', entete: 'Matricule', rendu: (r) => <span className="mono">{r.matricule || '—'}</span> },
            { cle: 'poste', entete: 'Poste', rendu: (r) => r.poste || '—' },
            { cle: 'typeContrat', entete: 'Contrat', rendu: (r) => r.typeContrat || '—' },
            { cle: 'dateEntree', entete: 'Entrée', rendu: (r) => dateFr(r.dateEntree) },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className={`badge ${STATUT_EMP[r.statut] || 'mut'}`}>{r.statut || '—'}</span> },
          ]}
        />
      )}

      {sousOnglet === 'roster' && (
        <Liste
          titre="Roster hebdomadaire"
          sous="couverture des postes"
          deps={[etabActif]}
          charger={api.roster}
          vide="Aucun créneau au roster."
          colonnes={[
            { cle: 'poste', entete: 'Poste', rendu: (r) => <span className="nm">{r.poste || '—'}</span> },
            { cle: 'debut', entete: 'Début', rendu: (r) => dateHeureFr(r.debut) },
            { cle: 'fin', entete: 'Fin', rendu: (r) => heure(r.fin) },
            { cle: 'effectifRequis', entete: 'Requis', num: true, rendu: (r) => r.effectifRequis ?? '—' },
            { cle: 'affectes', entete: 'Affectés', num: true, rendu: (r) => (Array.isArray(r.employesAffectes) ? r.employesAffectes.length : 0) },
            { cle: 'statutCouverture', entete: 'Couverture', rendu: (r) => <span className={`badge ${COUV[r.statutCouverture] || 'mut'}`}>{r.statutCouverture || '—'}</span> },
          ]}
        />
      )}

      {sousOnglet === 'badges' && (
        <Liste
          titre="Badges staff"
          sous="émission / révocation"
          deps={[etabActif]}
          charger={api.badgeStaffs}
          vide="Aucun badge staff émis."
          colonnes={[
            { cle: 'employe', entete: 'Employé', rendu: (r) => String(r.employe?.nom || r.employe || '—').toString().split('/').pop() },
            { cle: 'dateEmission', entete: 'Émis le', rendu: (r) => dateFr(r.dateEmission) },
            { cle: 'dateRevocation', entete: 'Révoqué le', rendu: (r) => dateFr(r.dateRevocation) },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className={`badge ${STATUT_BADGE[r.statut] || 'mut'}`}>{r.statut || '—'}</span> },
          ]}
        />
      )}
    </div>
  )
}
