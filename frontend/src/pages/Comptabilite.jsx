import { useState } from 'react'
import Liste, { euroCentimes, dateFr } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import { api } from '../api/client.js'
import ClotureComptable from '../components/ClotureComptable.jsx'
import ImpayesRecouvrement from '../components/ImpayesRecouvrement.jsx'
import PrelevementsSepa from '../components/PrelevementsSepa.jsx'
import CautionsGestion from '../components/CautionsGestion.jsx'

// Comptabilité / Régie (M6) + SEPA + impayés + cautions. Consultation multi-onglets.
//
// LES TROIS DERNIERS ONGLETS NE SONT PLUS ÉCRITS ICI, ET C'EST TOUT L'INTÉRÊT.
//
// SEPA, impayés et cautions ont désormais chacun leur entrée de menu. L'objection qui les tenait
// fermées était juste : deux portes vers la même liste, c'est deux endroits à corriger et personne
// qui sache lequel fait foi. Elle ne tient plus dès lors que les deux portes ouvrent sur le MÊME
// COMPOSANT — `PrelevementsSepa`, `ImpayesRecouvrement`, `CautionsGestion`. Il n'y a qu'une
// implémentation ; elle ne peut pas diverger d'elle-même.
//
// Les trois onglets restent donc là, où l'exploitant a l'habitude de les chercher, et ils montrent
// exactement l'écran de l'entrée de menu. Le prix payé est une seconde rangée d'onglets à
// l'intérieur de la première : c'est visible, et c'est moins cher que deux copies.
export default function Comptabilite({ etabActif, droits }) {
  // La cloture est l'onglet par defaut : c'est le seul du module qui porte un TRAVAIL. Les huit
  // listes existantes repondent a des questions qu'on se pose ; la cloture repond a une echeance.
  const [sousOnglet, setSousOnglet] = useState('cloture')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Comptabilité / Régie</h1>
          <p>Journaux, écritures, régie, SEPA &amp; impayés</p>
        </div>
      </div>

      <Tabs
        onglets={[
          ['cloture', 'Clôture'],
          ['journaux', 'Journaux & écritures'],
          ['regie', 'Régie & versements'],
          ['sepa', 'SEPA'],
          ['impayes', 'Impayés'],
          ['cautions', 'Cautions'],
        ]}
        actif={sousOnglet}
        onChange={setSousOnglet}
      />

      {sousOnglet === 'cloture' && <ClotureComptable etabActif={etabActif} droits={droits} />}

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
          {/* LES IMPAYES, A COTE DES ENCAISSEMENTS ET PAS AILLEURS.
              Une regie de recettes se lit par ce qu'elle a encaisse ET par ce qui lui manque. Ranger
              les impayes dans un autre ecran laisse regarder le solde sans son complement -- et un
              solde lu seul a l'air bon. */}
          <Liste
            titre="Ventes impayées"
            sous="à recouvrer par la régie"
            deps={[etabActif]}
            charger={api.ventesImpayeesRegie}
            vide="Aucune vente impayée."
            colonnes={[
              { cle: 'dateMarquage', entete: 'Marquée le', rendu: (r) => dateFr(r.dateMarquage) },
              { cle: 'motif', entete: 'Motif', rendu: (r) => <span className="nm">{r.motif || '—'}</span> },
              { cle: 'venteOrigine', entete: 'Vente', rendu: (r) => <span className="mono sub">{String(r.venteOrigine || '').slice(0, 8) || '—'}</span> },
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

      {sousOnglet === 'sepa' && <PrelevementsSepa etabActif={etabActif} droits={droits} />}

      {sousOnglet === 'impayes' && <ImpayesRecouvrement etabActif={etabActif} droits={droits} />}

      {sousOnglet === 'cautions' && <CautionsGestion etabActif={etabActif} droits={droits} />}
    </div>
  )
}
