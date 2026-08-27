import { useState } from 'react'
import Liste, { euroCentimes, dateFr, dateHeureFr } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import { api } from '../api/client.js'
import ClotureComptable from '../components/ClotureComptable.jsx'
import ImpayesRecouvrement from '../components/ImpayesRecouvrement.jsx'

// Comptabilité / Régie (M6) + SEPA + impayés + cautions. Consultation multi-onglets.
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
          {/* LES REJETS, QUI ETAIENT LA SEULE PIECE INVISIBLE DE LA CHAINE.
              L'ecran montrait ce qu'on envoie a la banque (remises) et qui l'a autorise (mandats),
              jamais ce que la banque RENVOIE. Or un rejet ouvre un incident d'impaye, programme des
              representations et peut bloquer l'acces du redevable : c'est l'evenement qui declenche
              tout le reste, et il n'apparaissait sur aucun ecran.
              Le code motif de la banque est affiche brut a cote de son libelle : c'est lui qu'on
              cite au telephone quand on rappelle sa banque, et le libelle traduit ne suffit pas. */}
          <Liste
            titre="Rejets bancaires"
            sous="ce que la banque renvoie"
            deps={[etabActif]}
            charger={api.rejetsSepa}
            vide="Aucun rejet. Les prelevements refuses par la banque apparaitront ici, et ouvriront un impaye."
            colonnes={[
              { cle: 'dateRejet', entete: 'Rejet', rendu: (r) => dateFr(r.dateRejet) },
              { cle: 'mndtId', entete: 'Mandat', rendu: (r) => <span className="mono">{r.mndtId || '—'}</span> },
              {
                cle: 'codeMotif',
                entete: 'Motif',
                rendu: (r) => (
                  <span>
                    {r.libelleMotif || '—'}
                    {r.codeMotif && <div className="sub mono">{r.codeMotif}</div>}
                  </span>
                ),
              },
              { cle: 'endToEndId', entete: 'Reference', rendu: (r) => <span className="mono">{r.endToEndId || '—'}</span> },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'impayes' && <ImpayesRecouvrement etabActif={etabActif} droits={droits} />}

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
