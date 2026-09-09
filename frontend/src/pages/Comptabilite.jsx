import { useState } from 'react'
import Liste, { euroCentimes, dateFr } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import AideComptabilite from '../components/AideComptabilite.jsx'
import LettrageEcritures from '../components/LettrageEcritures.jsx'
import SaisieEcritureManuelle from '../components/SaisieEcritureManuelle.jsx'
import VersementRegie from '../components/VersementRegie.jsx'
import MarquerImpayeeRegie from '../components/MarquerImpayeeRegie.jsx'
import BordereauxPayFip from '../components/BordereauxPayFip.jsx'
import { api } from '../api/client.js'
import ClotureComptable from '../components/ClotureComptable.jsx'

// Comptabilité / Régie (M6) : clôture, écritures, saisie manuelle, lettrage, régie.
//
// Ce qui est parti d'ici, et où : les correspondances comptables vers Paramètres (un réglage,
// pas un geste quotidien) ; SEPA, impayés et cautions vers leurs propres entrées de menu, qui
// existaient déjà. Voir le bloc ci-dessous pour le raisonnement.
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
// ⚠ SEPA EST PARTI AUSSI. Ce bloc disait « il reste, Maxime a nommé deux onglets, pas trois — la
// question lui est posée plutôt que tranchée ici ». Elle lui a été posée, et il a répondu « le
// retirer aussi ». Les trois portes en double sont donc fermées, et il n'en reste aucune.
//
// `Correspondances` part ailleurs : vers Paramètres › Correspondances comptables. Associer une
// catégorie de produit à un compte se règle une fois — c'est le critère de R21, pas du quotidien.
//
// ── LA LISTE DES JOURNAUX EST PARTIE, ET ELLE N'A ÉTÉ REMPLACÉE PAR RIEN ────────────────────────
//
// L'onglet s'appelait « Journaux & écritures » et portait DEUX listes. Celle des journaux était en
// lecture seule, et elle faisait double emploi : les journaux sont posés automatiquement à
// l'ouverture de l'établissement par `AccountingChartSeeder` — VTE, ENC, REG, PCA, EXT, OD —
// exactement comme les taux de TVA légaux, et personne n'y touche jamais.
//
// Surtout, ils sont DÉJÀ listés dans l'onglet Clôture, où chacun porte le bouton qui vérifie
// l'intégrité de sa chaîne. Là, la liste sert à quelque chose. Ici elle ne servait qu'à traduire
// un code en libellé.
//
// ⚠ CETTE TRADUCTION, ELLE, MANQUAIT VRAIMENT — ET ON NE POUVAIT PAS LA PERDRE. `ecriture:read`
// n'embarquait du journal que son `code` : la colonne « Journal » de la liste des écritures aurait
// affiché « VTE » sans que rien nulle part ne dise « Journal des ventes ». `Journal::$libelle` est
// donc entré dans le groupe — au passage, `e.journal?.libelle || e.journal?.code`, écrit dans
// `ClotureComptable` depuis l'origine, cesse de retomber toujours sur sa branche de secours.
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
          <p>Clôture, écritures et régie</p>
        </div>
      </div>

      {/* CE QUE LE MODULE FAIT, AVANT SES ONGLETS. Chaque carte explique déjà son bouton ; aucune
          ne disait dans quel ordre les boutons s'enchaînent. Le bloc se referme et s'en souvient. */}
      <AideComptabilite />

      <Tabs
        onglets={[
          ['cloture', 'Clôture'],
          ['ecritures', 'Écritures'],
          ['saisie', 'Saisie manuelle'],
          ['lettrage', 'Lettrage'],
          ['regie', 'Régie & versements'],
        ]}
        actif={sousOnglet}
        onChange={setSousOnglet}
      />

      {/* UNE PHRASE PAR ONGLET, TOUJOURS VISIBLE. Le bloc d'aide au-dessus se referme ; ces
          amorces ne se referment pas, parce qu'elles répondent à « je suis où, et ça sert à
          quoi ? » — la question qu'on se pose en arrivant, pas celle qu'on se pose une fois. */}
      {sousOnglet === 'cloture' && (
        <>
          <p className="cpt-intro">
            <b>Le travail du mois.</b> Comptabiliser les ventes, valider les écritures qu’elles
            produisent, arrêter la période, puis sortir le fichier pour l’administration ou pour
            votre comptable. C’est le seul onglet du module qui répond à une échéance.
          </p>
          <ClotureComptable etabActif={etabActif} droits={droits} />
        </>
      )}

      {/* Avant le lettrage, parce qu'on lettre ce qu'on a saisi — et parce que ces deux onglets sont
          les seuls du module ou le comptable ECRIT plutot qu'il ne consulte. */}
      {sousOnglet === 'saisie' && (
        <>
          <p className="cpt-intro">
            <b>Les écritures qu’aucune vente ne produit</b> — une régularisation, une subvention, un
            apport. Elle doit être équilibrée, débit égal crédit : une écriture qui ne l’est pas
            fera refuser la clôture de sa période.
          </p>
          <SaisieEcritureManuelle etabActif={etabActif} droits={droits} />
        </>
      )}

      {sousOnglet === 'lettrage' && (
        <>
          <p className="cpt-intro">
            <b>Rapprocher une facture du règlement qui la solde.</b> Ce qui reste non lettré est ce
            qui reste dû — c’est là-dessus que se lit un impayé, et c’est ce qui évite de relancer
            quelqu’un qui a déjà payé.
          </p>
          <LettrageEcritures etabActif={etabActif} droits={droits} />
        </>
      )}

      {sousOnglet === 'ecritures' && (
        <>
          <p className="cpt-intro">
            <b>Tout ce qui a été enregistré</b>, dans l’ordre de la chaîne scellée. En lecture
            seule&nbsp;: une écriture validée ne se corrige pas — on l’extourne depuis l’onglet
            Clôture, et la contre-écriture porte la date du jour où on la fait.
          </p>
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
              // Le journal DIT ce que l'écriture est : une vente, un encaissement, une extourne.
              // Sans lui, la liste est une suite de libellés sans famille — et c'est la seule
              // chose que la liste des journaux, partie d'ici, apportait vraiment.
              {
                cle: 'journal',
                entete: 'Journal',
                rendu: (r) => r.journal?.libelle || r.journal?.code || '—',
              },
              { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
            ]}
          />
        </>
      )}

      {sousOnglet === 'regie' && (
        <>
          <p className="cpt-intro">
            <b>Ce que le régisseur détient, ce qu’il verse, et ce qui lui manque.</b> Une régie de
            recettes autorise un agent à encaisser au nom de la collectivité, dans la limite d’un
            plafond d’espèces. Au-dessus de ce plafond, la clôture du mois est refusée tant que rien
            n’est versé.
          </p>
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
        </>
      )}

    </div>
  )
}
