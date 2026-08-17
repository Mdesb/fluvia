import { useState } from 'react'
import Liste, { euroCentimes, dateFr, dateHeureFr } from '../components/Liste.jsx'
import { api } from '../api/client.js'

// Comptabilité / Régie (M6) + SEPA + impayés + cautions. Consultation multi-onglets.
export default function Comptabilite({ etabActif }) {
  const [sousOnglet, setSousOnglet] = useState('journaux')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Comptabilité / Régie</h1>
          <p>Journaux, écritures, régie, SEPA &amp; impayés</p>
        </div>
      </div>

      <div className="seg" style={{ marginBottom: 16 }}>
        {[
          ['journaux', 'Journaux & écritures'],
          ['regie', 'Régie & versements'],
          ['sepa', 'SEPA'],
          ['impayes', 'Impayés'],
          ['cautions', 'Cautions'],
        ].map(([k, l]) => (
          <button key={k} className={sousOnglet === k ? 'on' : ''} onClick={() => setSousOnglet(k)}>{l}</button>
        ))}
      </div>

      {sousOnglet === 'journaux' && (
        <div className="resa-grid">
          <Liste
            titre="Journaux comptables"
            deps={[etabActif]}
            charger={api.journaux}
            vide="Aucun journal."
            colonnes={[
              { cle: 'code', entete: 'Code', rendu: (r) => <span className="mono">{r.code || '—'}</span> },
              { cle: 'libelle', entete: 'Libellé', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
            ]}
          />
          <Liste
            titre="Écritures comptables"
            sous="chaîne signée"
            deps={[etabActif]}
            charger={api.ecrituresComptables}
            vide="Aucune écriture."
            colonnes={[
              { cle: 'numeroSequence', entete: 'Séq.', num: true, rendu: (r) => r.numeroSequence ?? '—' },
              { cle: 'dateEcriture', entete: 'Date', rendu: (r) => dateFr(r.dateEcriture) },
              { cle: 'libelle', entete: 'Libellé', rendu: (r) => r.libelle || '—' },
              { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'regie' && (
        <div className="resa-grid">
          <Liste
            titre="Régies de recettes"
            deps={[etabActif]}
            charger={api.regieRecettes}
            vide="Aucune régie."
            colonnes={[
              { cle: 'libelle', entete: 'Régie', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
              { cle: 'soldeEncaisseCentimes', entete: 'Solde encaisse', num: true, rendu: (r) => euroCentimes(r.soldeEncaisseCentimes) },
              { cle: 'plafondEncaisseCentimes', entete: 'Plafond', num: true, rendu: (r) => euroCentimes(r.plafondEncaisseCentimes) },
            ]}
          />
          <Liste
            titre="Bordereaux de versement"
            deps={[etabActif]}
            charger={api.bordereauxVersement}
            vide="Aucun bordereau."
            colonnes={[
              { cle: 'dateVersement', entete: 'Date', rendu: (r) => dateFr(r.dateVersement) },
              { cle: 'montantCentimes', entete: 'Montant', num: true, rendu: (r) => euroCentimes(r.montantCentimes) },
              { cle: 'ecritureGeneree', entete: 'Écriture', rendu: (r) => (r.ecritureGeneree ? <span className="badge good">générée</span> : <span className="badge mut">—</span>) },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'sepa' && (
        <div className="resa-grid">
          <Liste
            titre="Remises de prélèvement (pain.008)"
            sous="lots SEPA"
            deps={[etabActif]}
            charger={api.remisesSepa}
            vide="Aucune remise SEPA."
            colonnes={[
              { cle: 'messageId', entete: 'Message ID', rendu: (r) => <span className="mono">{r.messageId || '—'}</span> },
              { cle: 'dateCollecte', entete: 'Collecte', rendu: (r) => dateFr(r.dateCollecte) },
              { cle: 'nbTxs', entete: 'Nb tx', num: true, rendu: (r) => r.nbTxs ?? '—' },
              { cle: 'ctrlSumCentimes', entete: 'Total', num: true, rendu: (r) => euroCentimes(r.ctrlSumCentimes) },
              { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
            ]}
          />
          <Liste
            titre="Mandats SEPA"
            deps={[etabActif]}
            charger={api.mandatsSepa}
            vide="Aucun mandat."
            colonnes={[
              { cle: 'rum', entete: 'RUM', rendu: (r) => <span className="mono">{r.rum || '—'}</span> },
              { cle: 'debiteurNom', entete: 'Débiteur', rendu: (r) => r.debiteurNom || '—' },
              { cle: 'iban4Derniers', entete: 'IBAN', rendu: (r) => (r.iban4Derniers ? `••••${r.iban4Derniers}` : '—') },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'impayes' && (
        <Liste
          titre="Incidents d'impayé"
          sous="anti-impayés (recouvrement)"
          deps={[etabActif]}
          charger={api.incidentsImpayes}
          vide="Aucun impayé en cours."
          colonnes={[
            { cle: 'referenceRedevable', entete: 'Redevable', rendu: (r) => r.referenceRedevable || '—' },
            { cle: 'montantCentimes', entete: 'Montant', num: true, rendu: (r) => euroCentimes(r.montantCentimes) },
            { cle: 'dateRejet', entete: 'Rejet', rendu: (r) => dateHeureFr(r.dateRejet) },
            { cle: 'motifBancaire', entete: 'Motif', rendu: (r) => r.motifBancaire || '—' },
            { cle: 'accesBloque', entete: 'Accès', rendu: (r) => (r.accesBloque ? <span className="badge crit">bloqué</span> : <span className="badge good">ouvert</span>) },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
          ]}
        />
      )}

      {sousOnglet === 'cautions' && (
        <Liste
          titre="Cautions"
          sous="dépôts &amp; retenues"
          deps={[etabActif]}
          charger={api.cautions}
          vide="Aucune caution."
          colonnes={[
            { cle: 'typeCible', entete: 'Type', rendu: (r) => r.typeCible || '—' },
            { cle: 'referenceCible', entete: 'Référence', rendu: (r) => <span className="mono">{String(r.referenceCible || '').slice(0, 10) || '—'}</span> },
            { cle: 'montantCentimes', entete: 'Montant', num: true, rendu: (r) => euroCentimes(r.montantCentimes) },
            { cle: 'montantRetenuCentimes', entete: 'Retenu', num: true, rendu: (r) => euroCentimes(r.montantRetenuCentimes) },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
          ]}
        />
      )}
    </div>
  )
}
