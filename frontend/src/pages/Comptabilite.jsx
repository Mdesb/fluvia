import { useState } from 'react'
import Liste, { euroCentimes, dateFr } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import LettrageEcritures from '../components/LettrageEcritures.jsx'
import SaisieEcritureManuelle from '../components/SaisieEcritureManuelle.jsx'
import VersementRegie from '../components/VersementRegie.jsx'
import MarquerImpayeeRegie from '../components/MarquerImpayeeRegie.jsx'
import BordereauxPayFip from '../components/BordereauxPayFip.jsx'
import { api } from '../api/client.js'
import ClotureComptable from '../components/ClotureComptable.jsx'
import PrelevementsSepa from '../components/PrelevementsSepa.jsx'

// Comptabilité / Régie (M6). Consultation multi-onglets.
//
// ⚠ CE BLOC DISAIT L'INVERSE, ET LE RAISONNEMENT ÉTAIT JUSTE — C'EST LE CRITÈRE QUI A CHANGÉ.
//
// Il défendait de garder ici les onglets SEPA, Impayés et Cautions alors qu'ils ont chacun leur
// entrée de menu : « les deux portes ouvrent sur le MÊME COMPOSANT, il n'y a qu'une
// implémentation, elle ne peut pas diverger d'elle-même ». C'est exact, et ça répondait à
// l'objection de la DIVERGENCE.
//
// Maxime en a posé une autre à la revue du 02/09 (R29, R30), qui ne parle pas de divergence mais
// d'ENCOMBREMENT : « il existe des menus spécifiques, donc pas besoin de les mettre ici ». Un
// écran qu'on ouvre tous les matins ne doit pas contenir une seconde rangée d'onglets vers des
// écrans qui ont déjà leur porte. Les deux qu'il a nommés partent.
//
// ⚠ SEPA RESTE, ET C'EST DÉLIBÉRÉ : il est dans exactement la même situation, mais Maxime a nommé
// deux onglets, pas trois. La question lui est posée plutôt que tranchée ici.
//
// `Correspondances` part ailleurs : vers Paramètres › Correspondances comptables. Associer une
// catégorie de produit à un compte se règle une fois — c'est le critère de R21, pas du quotidien.
//
// Les trois onglets restent donc là, où l'exploitant a l'habitude de les chercher, et ils montrent
// exactement l'écran de l'entrée de menu. Le prix payé est une seconde rangée d'onglets à
// l'intérieur de la première : c'est visible, et c'est moins cher que deux copies.
export default function Comptabilite({ etabActif, droits }) {
  // La cloture est l'onglet par defaut : c'est le seul du module qui porte un TRAVAIL. Les huit
  // listes existantes repondent a des questions qu'on se pose ; la cloture repond a une echeance.
  const [sousOnglet, setSousOnglet] = useState('cloture')
  // Un versement emet un bordereau : sans ce compteur, la liste voisine afficherait encore
  // « Aucun bordereau » juste apres en avoir cree un.
  const [versements, setVersements] = useState(0)

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Comptabilité / Régie</h1>
          <p>Journaux, écritures, régie &amp; SEPA</p>
        </div>
      </div>

      <Tabs
        onglets={[
          ['cloture', 'Clôture'],
          ['journaux', 'Journaux & écritures'],
          ['saisie', 'Saisie manuelle'],
          ['lettrage', 'Lettrage'],
          ['regie', 'Régie & versements'],
          ['sepa', 'SEPA'],
        ]}
        actif={sousOnglet}
        onChange={setSousOnglet}
      />

      {sousOnglet === 'cloture' && <ClotureComptable etabActif={etabActif} droits={droits} />}

      {/* Apres les journaux, parce qu'on lettre ce qu'on vient d'y lire — et avant les listes de
          consultation, parce que c'est un des rares onglets de ce module qui porte un TRAVAIL. */}
      {/* Avant le lettrage, parce qu'on lettre ce qu'on a saisi — et parce que ces deux onglets sont
          les seuls du module ou le comptable ECRIT plutot qu'il ne consulte. */}
      {sousOnglet === 'saisie' && <SaisieEcritureManuelle etabActif={etabActif} droits={droits} />}

      {sousOnglet === 'lettrage' && <LettrageEcritures etabActif={etabActif} droits={droits} />}

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
          {/* La liste des regies etait en LECTURE SEULE, et c'est ce qui bloquait la cloture :
              on y lisait « au-dessus du plafond » sans pouvoir verser. */}
          <VersementRegie
            etabActif={etabActif}
            droits={droits}
            onVersement={() => setVersements((n) => n + 1)}
          />
          {/* LES IMPAYES, A COTE DES ENCAISSEMENTS ET PAS AILLEURS.
              Une regie de recettes se lit par ce qu'elle a encaisse ET par ce qui lui manque. Ranger
              les impayes dans un autre ecran laisse regarder le solde sans son complement -- et un
              solde lu seul a l'air bon. */}
          {/* La liste etait en LECTURE SEULE : elle ne pouvait que rester vide, ce qui se lit
              « aucun impaye » au lieu de « rien ne peut en creer ». Le composant liste ET marque. */}
          <MarquerImpayeeRegie etabActif={etabActif} droits={droits} />
          <Liste
            titre="Bordereaux de versement"
            deps={[etabActif, versements]}
            charger={api.bordereauxVersement}
            vide="Aucun bordereau."
            colonnes={[
              { cle: 'dateVersement', entete: 'Date', rendu: (r) => dateFr(r.dateVersement) },
              { cle: 'montantCentimes', entete: 'Montant', num: true, rendu: (r) => euroCentimes(r.montantCentimes) },
              { cle: 'ecritureGeneree', entete: 'Écriture', rendu: (r) => (r.ecritureGeneree ? <span className="badge good">générée</span> : <span className="badge mut">—</span>) },
            ]}
          />
          {/* PayFiP a cote de la regie, parce que c'est le meme metier : encaisser pour le compte
              du Tresor. Le referentiel entier etait invisible — un paiement dont le retour ne
              revient jamais restait en attente sans que personne puisse le constater. */}
          <BordereauxPayFip etabActif={etabActif} />
        </div>
      )}

      {sousOnglet === 'sepa' && <PrelevementsSepa etabActif={etabActif} droits={droits} />}

    </div>
  )
}
