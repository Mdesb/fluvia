import { useEffect, useState, useCallback } from 'react'
import VocabulaireMetier from '../components/VocabulaireMetier.jsx'
import Liste, { texte, dateHeureFr } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import PlanningOuvertureSection from '../components/PlanningOuvertureSection.jsx'
import Modal from '../components/Modal.jsx'
import ReferentielEditable from '../components/ReferentielEditable.jsx'
import PretAVendre from '../components/PretAVendre.jsx'
import RolesSection from '../components/RolesSection.jsx'
import EtablissementsSection from '../components/EtablissementsSection.jsx'
import RegionsSection from '../components/RegionsSection.jsx'
import OuvrirStructure from '../components/OuvrirStructure.jsx'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { api, membres } from '../api/client.js'

const SOUS = [
  ['entites', 'Établissement & entités'],
  // Les suffixes « (M1) », « (M2) », « (M8) » étaient nos codes de modules internes. Un exploitant
  // n'a aucune raison de les connaître, et ils n'apportaient rien à ceux qui les connaissent.
  ['referentiels', 'Catalogue & référentiels'],
  ['caisse', 'Caisse & moyens de paiement'],
  ['droits', 'Utilisateurs & droits'],
  ['capacites', 'Capacités activables'],
  // Les horaires d'ouverture sont une CONFIGURATION du site, pas un écran de consultation : ils se
  // saisissent deux fois par an. Ils portent surtout la case qui fait refuser un passage à la
  // porte — elle n'a rien à faire dans un agenda qu'on ouvre pour regarder sa semaine.
  ['ouverture', 'Horaires d’ouverture'],
]

const STATUT_BADGE = { actif: 'good', invite: 'warn', suspendu: 'crit' }

// Paramètres : hub d'administration du socle. Consultation des référentiels, édition simple là où
// l'API le permet (moyens de paiement, comptes & droits), lecture ailleurs.
// Descripteurs des référentiels modifiables.
//
// Chaque champ dit sa CONSÉQUENCE et non sa nature : « ce qui change pour le client ou pour la
// caisse », jamais « ce qui est stocké ». Un régisseur de piscine n'a pas à deviner ce qu'est un
// « canal de visibilité » — il a besoin de savoir où le tarif apparaîtra.
const CANAUX = [
  { valeur: 'guichet', libelle: 'Au guichet' },
  { valeur: 'en_ligne', libelle: 'En ligne' },
  { valeur: 'borne', libelle: 'Sur borne' },
]

function descripteurTypesTarif(api) {
  return {
    titre: 'Types de tarif',
    aQuoiCaSert:
      "Les catégories de prix que vous proposez : plein tarif, tarif réduit, enfant, abonné… "
      + 'Chaque produit porte un prix par type de tarif.',
    siVide:
      "Vous n'avez aucun type de tarif. Tant qu'il n'y en a pas, vos produits ne peuvent recevoir "
      + 'aucun prix, donc rien ne peut être vendu ni publié. Commencez par « Plein tarif ».',
    consequenceSuppression:
      "Si ce type de tarif est utilise par le prix d'un produit, la suppression sera refusee : "
      + 'vous pourrez alors le rendre inutilisable plutot que de le supprimer.',
    charger: api.typeTarifs,
    creer: api.creerTypeTarif,
    modifier: api.majTypeTarif,
    supprimer: api.supprimerTypeTarif,
    champs: [
      {
        nom: 'nom',
        libelle: 'Nom du tarif',
        type: 'text',
        requis: true,
        exemple: 'Plein tarif',
        aide: "C'est ce que verra le vendeur au moment de choisir un prix.",
      },
      {
        nom: 'visibiliteCanal',
        libelle: 'Où ce tarif est proposé',
        type: 'choix-multiples',
        options: CANAUX,
        aide: "Si vous ne cochez rien, le tarif n'apparaîtra nulle part — ni en caisse, ni en ligne.",
      },
      {
        nom: 'actif',
        libelle: 'Utilisable',
        type: 'bool',
        libelleCase: 'Ce tarif peut être utilisé',
        aide: 'Décocher masque le tarif pour les nouvelles ventes sans toucher aux ventes passées.',
      },
    ],
    colonnes: [
      { cle: 'nom', titre: 'Nom du tarif', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
      {
        cle: 'visibiliteCanal',
        titre: 'Proposé',
        aide: 'Les endroits où ce tarif peut être choisi.',
        rendu: (r) => {
          const l = Array.isArray(r.visibiliteCanal) ? r.visibiliteCanal : []
          if (l.length === 0) return <span className="badge crit" title="Ce tarif n apparait nulle part.">nulle part</span>
          return l.map((c) => CANAUX.find((x) => x.valeur === c)?.libelle || c).join(', ')
        },
      },
      {
        cle: 'actif',
        titre: 'État',
        rendu: (r) => <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'utilisable' : 'masqué'}</span>,
      },
    ],
  }
}

function descripteurTva(api) {
  return {
    titre: 'Taux de TVA',
    aQuoiCaSert:
      'Les taux appliqués à vos ventes. Chaque produit porte un taux, qui détermine la TVA facturée '
      + 'et ce qui remonte en comptabilité.',
    siVide:
      "Aucun taux de TVA n'est enregistré. Vos produits ne pourront pas être rattachés à un taux, et "
      + 'la comptabilité ne pourra pas être tenue correctement.',
    consequenceSuppression: '',
    charger: api.tauxTvas,
    creer: api.creerTauxTva,
    modifier: api.majTauxTva,
    supprimer: null,
    champs: [
      {
        nom: 'libelle',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Taux normal',
        aide: 'Le nom que vous lui donnez, pour le reconnaître dans la liste.',
      },
      {
        nom: 'taux',
        libelle: 'Pourcentage',
        type: 'nombre',
        pas: '0.01',
        requis: true,
        exemple: '20',
        aide: 'Le pourcentage appliqué au prix hors taxes. Saisissez 20 pour 20 %.',
      },
      {
        nom: 'actif',
        libelle: 'Utilisable',
        type: 'bool',
        libelleCase: 'Ce taux peut être choisi',
        aide: 'Décocher empêche de le choisir sur un nouveau produit, sans rien changer aux ventes passées.',
      },
    ],
    colonnes: [
      { cle: 'libelle', titre: 'Nom', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
      { cle: 'taux', titre: 'Pourcentage', num: true, rendu: (r) => (r.taux != null ? `${r.taux} %` : '—') },
      {
        cle: 'actif',
        titre: 'État',
        rendu: (r) => <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'utilisable' : 'masqué'}</span>,
      },
    ],
  }
}

const AXES = [
  { valeur: 'marketing', libelle: 'Regroupement commercial' },
  { valeur: 'comptable', libelle: 'Ventilation comptable' },
  { valeur: 'rayon', libelle: 'Rayon en boutique' },
]

function descripteurCategories(api) {
  return {
    titre: 'Catégories',
    aQuoiCaSert:
      'Des regroupements de produits. Ils servent à retrouver un produit plus vite en caisse, à '
      + 'organiser votre boutique en ligne, ou à ventiler votre chiffre d’affaires en comptabilité.',
    siVide:
      "Vous n'avez aucune catégorie. Ce n'est pas bloquant : vos produits restent vendables. Mais "
      + 'au-delà d’une trentaine de produits, une caisse sans catégories devient difficile à utiliser.',
    consequenceSuppression:
      'Les produits rattachés à cette catégorie ne seront pas supprimés — ils perdront simplement ce '
      + 'regroupement.',
    charger: api.categories,
    creer: api.creerCategorie,
    modifier: api.majCategorie,
    supprimer: api.supprimerCategorie,
    champs: [
      {
        nom: 'libelle',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Activités aquatiques',
        aide: 'Le nom tel qu’il apparaîtra dans les écrans.',
      },
      {
        nom: 'axe',
        libelle: 'À quoi elle sert',
        type: 'choix',
        options: AXES,
        aide:
          'Un regroupement commercial sert à la vente ; une ventilation comptable sert à vos écritures ; '
          + 'un rayon organise votre boutique. Un même produit peut appartenir à plusieurs catégories '
          + 'd’axes différents.',
      },
    ],
    colonnes: [
      { cle: 'libelle', titre: 'Nom', rendu: (r) => <span className="nm">{texte(r.libelle, '—')}</span> },
      {
        cle: 'axe',
        titre: 'Sert à',
        rendu: (r) => AXES.find((a) => a.valeur === r.axe)?.libelle || r.axe || '—',
      },
      { cle: 'chemin', titre: 'Rattachée à', rendu: (r) => <span className="mono">{r.chemin || '—'}</span> },
    ],
  }
}

function descripteurSaisons(api) {
  return {
    titre: 'Saisons',
    aQuoiCaSert:
      'Des périodes de l’année pendant lesquelles vos prix changent : haute saison, vacances '
      + 'scolaires, hors saison. Un produit peut avoir un prix différent par saison.',
    siVide:
      "Aucune saison n'est définie. Ce n'est pas bloquant : vos prix s'appliquent alors toute l'année "
      + 'de la même façon.',
    consequenceSuppression:
      'Les prix définis pour cette saison ne s’appliqueront plus. Les produits reviendront à leur prix '
      + 'habituel.',
    charger: api.saisons,
    creer: api.creerSaison,
    modifier: api.majSaison,
    supprimer: api.supprimerSaison,
    champs: [
      {
        nom: 'nom',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Haute saison',
      },
      {
        nom: 'dateDebut',
        libelle: 'Du',
        type: 'date',
        requis: true,
        aide: 'Premier jour où les prix de cette saison s’appliquent.',
      },
      {
        nom: 'dateFin',
        libelle: 'Au',
        type: 'date',
        requis: true,
        aide: 'Dernier jour inclus.',
      },
      {
        nom: 'priorite',
        libelle: 'Priorité',
        type: 'nombre',
        defaut: 0,
        aide:
          'Départage deux saisons qui se recouvrent : la plus haute l’emporte. Deux saisons de même '
          + 'priorité ne peuvent pas se chevaucher — l’enregistrement sera refusé.',
      },
      {
        nom: 'recurrenceAnnuelle',
        libelle: 'Chaque année',
        type: 'bool',
        defaut: false,
        libelleCase: 'Cette période revient tous les ans',
        aide: 'Évite de recréer la même saison chaque année.',
      },
      {
        nom: 'actif',
        libelle: 'Utilisable',
        type: 'bool',
        libelleCase: 'Cette saison est appliquée',
        aide: 'Décocher suspend ses prix sans supprimer la saison ni son paramétrage.',
      },
    ],
    colonnes: [
      { cle: 'nom', titre: 'Nom', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
      {
        cle: 'periode',
        titre: 'Période',
        rendu: (r) => {
          const d = (r.dateDebut || '').slice(0, 10)
          const f = (r.dateFin || '').slice(0, 10)
          return d && f ? `${d} → ${f}` : '—'
        },
      },
      { cle: 'priorite', titre: 'Priorité', num: true, rendu: (r) => r.priorite ?? '—' },
      {
        cle: 'actif',
        titre: 'État',
        rendu: (r) => <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'appliquée' : 'suspendue'}</span>,
      },
    ],
  }
}

function descripteurPointsDeVente(api, etabActif, moyens = []) {
  return {
    titre: 'Points de vente',
    aQuoiCaSert:
      'Les endroits où vous encaissez : un guichet, une borne, un comptoir. Une caisse s’ouvre '
      + 'toujours sur un point de vente.',
    siVide:
      "Vous n'avez aucun point de vente. Tant qu'il n'y en a pas, aucune caisse ne peut être ouverte "
      + 'et rien ne peut être encaissé. Commencez par « Guichet principal ».',
    consequenceSuppression: '',
    charger: api.pointDeVentes,
    // L'établissement n'est pas un champ du formulaire : il vient du contexte de travail, jamais
    // d'une saisie. Le proposer à choisir serait offrir de créer un point de vente chez quelqu'un
    // d'autre.
    creer: (corps) => api.creerPointDeVente({ ...corps, etablissement: `/api/etablissements/${etabActif}` }),
    modifier: api.majPointDeVente,
    supprimer: null,
    champs: [
      {
        nom: 'libelle',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Guichet principal',
        aide: 'Le nom que verra le caissier en ouvrant sa caisse.',
      },
      // LES MOYENS DE PAIEMENT ÉTAIENT LISIBLES ET PAS RÉGLABLES.
      //
      // Le champ `moyensAutorises` est écrivable côté serveur depuis le début (groupe `pdv:write`,
      // droit `caisse.gerer`) ; l'écran l'affichait en colonne et ne le mettait pas au formulaire.
      // Signalé par Maxime à la revue des écrans.
      //
      // ⚠ RIEN DE COCHÉ = AUCUNE RESTRICTION, et c'est le contraire de ce qu'on lit spontanément.
      // `PaiementHandler` teste `getMoyensAutorises() !== []` avant de vérifier quoi que ce soit :
      // une liste vide laisse donc TOUT passer. Cocher trois moyens, c'est restreindre à ces trois ;
      // n'en cocher aucun, c'est tout autoriser. L'aide le dit, parce qu'une case à cocher vide se
      // lit d'habitude comme « rien n'est permis ».
      {
        nom: 'moyensAutorises',
        libelle: 'Paiements acceptés à ce point de vente',
        type: 'choix-multiples',
        options: moyens.map((m) => ({ valeur: m.code, libelle: m.libelle || m.code })),
        aide: moyens.length === 0
          ? "Aucun moyen de paiement n'est déclaré pour cet établissement : renseignez-les plus bas, "
            + 'puis revenez restreindre ce point de vente si besoin.'
          : "Ne cochez rien pour accepter tous les moyens déclarés. Cochez-en pour n'autoriser "
            + "que ceux-là : un caissier qui tentera un autre moyen se verra refuser l'encaissement.",
      },
      {
        nom: 'tpe',
        libelle: 'Un terminal bancaire est rattaché',
        type: 'bool',
        aide: 'Détermine si le paiement par carte passe par un terminal plutôt que par une saisie.',
      },
    ],
    colonnes: [
      { cle: 'libelle', titre: 'Nom', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
      {
        cle: 'tpe',
        titre: 'Terminal de paiement',
        aide: 'Un terminal bancaire est-il rattaché à ce point de vente ?',
        rendu: (r) => (r.tpe ? 'oui' : '—'),
      },
      {
        cle: 'moyensAutorises',
        titre: 'Paiements acceptés',
        // Le serveur stocke des CODES ; on affiche les libellés du référentiel. « tous » est exact :
        // une liste vide vaut absence de restriction (voir l'aide du champ, plus haut).
        rendu: (r) => {
          const codes = Array.isArray(r.moyensAutorises) ? r.moyensAutorises : []
          if (codes.length === 0) return <span className="sub">tous</span>
          return codes.map((c) => moyens.find((m) => m.code === c)?.libelle || c).join(', ')
        },
      },
    ],
  }
}

export default function Parametres({ etabActif, etablissements, droits = [] }) {
  const [ouvertureStructure, setOuvertureStructure] = useState(false)
  const [sousOnglet, setSousOnglet] = useState('entites')

  // LES MOYENS DE PAIEMENT DU REFERENTIEL, POUR POUVOIR LES COCHER PAR POINT DE VENTE.
  //
  // Maxime, a la revue des ecrans : << on ne peut pas ajouter/enlever les moyens de paiement
  // d une caisse >>. Le champ est ecrivable cote serveur depuis le debut ; l ecran l affichait
  // en colonne et ne le proposait pas au formulaire.
  //
  // La liste ne peut pas etre une constante : ce sont les moyens que CET etablissement a
  // declares, juste en dessous dans le meme onglet.
  const [moyens, setMoyens] = useState([])
  useEffect(() => {
    let annule = false
    api.moyensPaiement()
      .then((r) => { if (!annule) setMoyens(membres(r)) })
      // Un referentiel illisible ne doit pas empecher de renommer un point de vente : la case
      // a cocher disparait, le reste du formulaire fonctionne.
      .catch(() => { if (!annule) setMoyens([]) })
    return () => { annule = true }
  }, [etabActif])

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Paramètres</h1>
          <p>Ce que vous vendez, comment vous encaissez, et qui a le droit de faire quoi</p>
        </div>
      </div>

      <Tabs onglets={SOUS} actif={sousOnglet} onChange={setSousOnglet} />

      {/* En tete, avant les onglets de contenu : c'est la premiere chose que voit quelqu'un qui
          arrive ici sans savoir par ou commencer. Il se replie tout seul des que les trois
          conditions sont remplies. */}
      <PretAVendre etabActif={etabActif} droits={droits} onAller={setSousOnglet} />

      {sousOnglet === 'ouverture' && <PlanningOuvertureSection droits={droits} etabActif={etabActif} />}

      {sousOnglet === 'entites' && (
        <div className="resa-grid">
          {aLeDroit(droits, 'organisation.gerer') && (
            <section className="card">
              <div className="card-h">
                <h3>Nouveau client</h3>
              </div>
              <div className="card-b">
                <p className="sub" style={{ marginTop: 0 }}>
                  Tapez le nom de la société ou son SIREN : l’annuaire officiel remplit la
                  dénomination, le SIRET, le numéro de TVA et l’adresse du siège. La structure est
                  ouverte avec son point de vente, prête à encaisser.
                </p>
                <button className="btn primary" type="button" onClick={() => setOuvertureStructure(true)}>
                  Ouvrir une structure
                </button>
              </div>
            </section>
          )}

          <RegionsSection peutEcrire={aLeDroit(droits, 'organisation.gerer')} />
          <EtablissementsSection peutEcrire={aLeDroit(droits, 'organisation.gerer')} />
          {/* LES MOTS DU METIER, A COTE DE L'ETABLISSEMENT QU'ILS CONCERNENT.
              << Ressource >> veut dire praticien chez le coiffeur, ligne d'eau a la piscine. Le mettre
              dans un onglet << apparence >> le ferait chercher ailleurs : c'est un reglage de
              l'etablissement, pas du theme. */}
          <VocabulaireMetier etabActif={etabActif} peutEcrire={aLeDroit(droits, 'organisation.gerer')} />
          <Liste
            titre="Espaces"
            sous="zones physiques"
            deps={[etabActif]}
            charger={api.espaces}
            vide="Aucun espace."
            colonnes={[
              { cle: 'nom', entete: 'Espace', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
              { cle: 'type', entete: 'Nature', rendu: (r) => mot(r.type) },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'referentiels' && (
        <>
          {/* Ce qu'il FAUT régler avant de pouvoir vendre, séparé de ce qu'on PEUT régler ensuite.
              Un débutant ne sait pas par où commencer, et une liste de cinq blocs équivalents ne le
              lui dit pas. */}
          <div className="fiche-sec" style={{ marginBottom: 10 }}>Indispensable pour vendre</div>
          <ReferentielEditable
            descripteur={descripteurTypesTarif(api)}
            peutEcrire={aLeDroit(droits, 'offre.gerer')}
          />
          <ReferentielEditable
            descripteur={descripteurTva(api)}
            peutEcrire={aLeDroit(droits, 'compta.gerer')}
          />

          <div className="fiche-sec" style={{ margin: '24px 0 10px' }}>Pour aller plus loin</div>
          {/* Ces deux-là ne bloquent pas la vente, d'où leur place ici plutôt qu'au-dessus. Elles
              sont modifiables au même titre que les autres : je les avais laissées en consultation
              en croyant qu'un « axe » était un référentiel à exposer et que le format de date était
              incertain. Les deux étaient faux — l'axe est une énumération de trois valeurs, et le
              format `AAAA-MM-JJ` est celui qu'utilise la suite de tests du serveur. */}
          <ReferentielEditable
            descripteur={descripteurCategories(api)}
            peutEcrire={aLeDroit(droits, 'offre.gerer')}
          />
          <ReferentielEditable
            descripteur={descripteurSaisons(api)}
            peutEcrire={aLeDroit(droits, 'offre.gerer')}
          />
        </>
      )}

      {sousOnglet === 'caisse' && (
        <div className="resa-grid">
          <ReferentielEditable
            descripteur={descripteurPointsDeVente(api, etabActif, moyens)}
            peutEcrire={aLeDroit(droits, 'caisse.gerer')}
          />
          <Liste
            titre="Caisses"
            deps={[etabActif]}
            charger={api.caisses}
            vide="Aucune caisse."
            colonnes={[
              { cle: 'libelle', entete: 'Caisse', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
              { cle: 'pointDeVente', entete: 'Point de vente', rendu: (r) => texte(r.pointDeVente?.libelle, '—') },
              { cle: 'etat', entete: 'État', rendu: (r) => <span className="badge mut">{r.etat || '—'}</span> },
            ]}
          />
          <MoyensPaiement etabActif={etabActif} />
        </div>
      )}

      {sousOnglet === 'droits' && (
        <ComptesDroits etabActif={etabActif} etablissements={etablissements} droits={droits} />
      )}

      {sousOnglet === 'capacites' && <Capacites etabActif={etabActif} />}

      {/* ⚠ ENTRE LES CONDITIONNELS DE SOUS-ONGLET, et c'est le point.
          Posée à l'intérieur de l'un d'eux — ce qui est arrivé deux fois — elle n'existe pas quand
          on clique depuis un autre onglet : le bouton ne fait rien, sans erreur ni trace. Le bloc
          « droits » court sur quatre cents lignes, ce qui rend la faute facile et invisible. */}
      <OuvrirStructure
        ouvert={ouvertureStructure}
        onFermer={() => setOuvertureStructure(false)}
        onOuverte={() => window.location.reload()}
      />
    </div>
  )
}

// --- Moyens de paiement : éditables (ajouter, modifier, activer/désactiver). Écriture gardée par
// `compta.gerer` côté back : les erreurs (dont 403) sont surfacées proprement. ---
//
// ON POUVAIT TOUT RÉGLER À LA CRÉATION, ET PLUS RIEN ENSUITE.
//
// Les cinq propriétés de `MoyenPaiement` portent le groupe `moyen:write` et l'entité expose un
// `Patch` : tout est modifiable côté serveur depuis le début. L'écran, lui, n'offrait après coup que
// « Renommer » et « Activer / Désactiver ». Une case cochée de travers au moment de la création
// devenait définitive — sauf à créer un second moyen et à désactiver le premier, ce qui laisse deux
// lignes dans le référentiel et brouille les états de caisse.
//
// CE QUE FONT VRAIMENT CES TROIS CASES, LU DANS `PaiementHandler`, PAS DÉDUIT DE LEUR NOM.
//
//   autoriseRendu    ligne 97  : sans elle, encaisser PLUS que le reste à payer est refusé en 422.
//                                C'est la case qui permet de rendre la monnaie.
//   autoriseDiffere  ligne 90  : sans elle, un règlement marqué « différé » est refusé en 422.
//   exigeReference   ligne 125 : le règlement PART AU TERMINAL BANCAIRE et n'est enregistré que s'il
//                                revient accepté. Un refus ne crée aucun paiement.
//
// Le libellé « Exige une référence (TPE) » décrivait donc mal la troisième : elle ne demande pas une
// saisie à l'agent, elle branche l'encaissement sur le TPE. Renommée en conséquence.
function MoyensPaiement({ etabActif }) {
  const [rows, setRows] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(null) // id en cours de bascule
  const [edition, setEdition] = useState(null) // { moyen: null } = création, { moyen } = modification

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setRows(membres(await api.moyensPaiement()))
    } catch (e) {
      setErreur(e.message || 'Chargement impossible.')
      setRows([])
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  async function basculerActif(m) {
    const actif = m.actif !== false
    setBusy(m.id)
    setMsg(null)
    setErreur(null)
    try {
      await api.majMoyenPaiement(m.id, { actif: !actif })
      await charger()
      setMsg(`« ${m.libelle} » ${actif ? 'désactivé' : 'activé'}.`)
    } catch (e) {
      setErreur(erreurEcriture(e))
    } finally {
      setBusy(null)
    }
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>Moyens de paiement</h3>
        <span className="sub">éditable</span>
        <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
          <button className="btn sm" onClick={() => { setMsg(null); setErreur(null); setEdition({ moyen: null }) }}>+ Ajouter</button>
          <button className="btn ghost sm" onClick={charger} disabled={chargement}>↻</button>
        </div>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {msg && <div className="banner" style={{ background: 'var(--good-bg, var(--panel-2))', color: 'var(--good)', margin: '0 0 12px' }}>{msg}</div>}
        {erreur && <div className="banner banner-error" style={{ margin: '0 0 12px' }}>{erreur}</div>}
        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : (
          <table className="tbl">
            <thead>
              <tr><th>Moyen</th><th>Code</th><th>Ce qu’il permet en caisse</th><th>État</th><th className="num">Actions</th></tr>
            </thead>
            <tbody>
              {rows.map((m) => {
                const actif = m.actif !== false
                return (
                  <tr key={m.id}>
                    <td><span className="nm">{m.libelle || '—'}</span></td>
                    <td><span className="mono">{m.code || '—'}</span></td>
                    <td>
                      {/* Les trois réglages qui décident du comportement de l'encaissement étaient
                          invisibles sauf un, et sous un nom qui disait autre chose que son effet. */}
                      {m.exigeReference && (
                        <span className="badge info" title="Le règlement part au terminal bancaire et n’est enregistré que s’il revient accepté.">
                          terminal bancaire
                        </span>
                      )}{' '}
                      {m.autoriseRendu && (
                        <span className="badge info" title="On peut encaisser plus que le montant dû et rendre la différence.">
                          rendu de monnaie
                        </span>
                      )}{' '}
                      {m.autoriseDiffere && (
                        <span className="badge info" title="Le règlement peut être enregistré comme différé (encaissement plus tard).">
                          différé
                        </span>
                      )}
                      {!m.exigeReference && !m.autoriseRendu && !m.autoriseDiffere && (
                        <span className="sub" title="Encaissement du montant exact, sans terminal ni report.">
                          montant exact, comptant
                        </span>
                      )}
                    </td>
                    <td><span className={`badge ${actif ? 'good' : 'mut'}`}>{actif ? 'actif' : 'inactif'}</span></td>
                    <td className="num">
                      <div style={{ display: 'inline-flex', gap: 6 }}>
                        <button className="btn ghost sm" onClick={() => { setMsg(null); setErreur(null); setEdition({ moyen: m }) }}>Modifier</button>
                        <button
                          className="btn ghost sm"
                          onClick={() => basculerActif(m)}
                          disabled={busy === m.id}
                          aria-label={actif ? `Désactiver ${m.libelle}` : `Activer ${m.libelle}`}
                        >
                          {busy === m.id ? '…' : actif ? 'Désactiver' : 'Activer'}
                        </button>
                      </div>
                    </td>
                  </tr>
                )
              })}
              {rows.length === 0 && !erreur && <tr><td colSpan={5} className="empty">Aucun moyen de paiement.</td></tr>}
            </tbody>
          </table>
        )}
      </div>

      <ModalMoyenPaiement
        edition={edition}
        onClose={() => setEdition(null)}
        onEnregistre={async (corps) => {
          const existant = edition?.moyen
          if (existant) await api.majMoyenPaiement(existant.id, corps)
          else await api.creerMoyenPaiement({ ...corps, actif: true })
          setEdition(null)
          setMsg(existant ? `« ${corps.libelle} » mis à jour.` : `Moyen « ${corps.libelle} » ajouté.`)
          await charger()
        }}
      />
    </section>
  )
}

// LES CINQ CODES QUE LE LOGICIEL RECONNAÎT COMME DU PAPIER QU'ON DÉTIENT.
//
// `Vente\Port\MoyenPaiement::estFiduciaire()` est une LISTE DE CODES EN DUR, pas une propriété de
// l'entité. Elle décide d'une seule chose, mais elle en décide une lourde : `PaiementHandler` ligne
// 73 refuse un moyen fiduciaire hors session de caisse — sans espèces ni chèque, rien à compter,
// donc rien à clôturer.
//
// Conséquence pour cet écran : un moyen créé ici avec un code hors de cette liste ne sera JAMAIS
// traité comme du papier. Un exploitant qui ajoute « chèque sport » obtient un moyen encaissable en
// vente directe, sans session, alors que quelqu'un tient physiquement le papier et devra en
// répondre. Le docblock du serveur le dit lui-même et renvoie la propriété à `MoyenPaiement` —
// demande écrite, pas encore faite.
//
// On ne peut pas le corriger depuis le front. On peut refuser de le laisser passer en silence.
const CODES_FIDUCIAIRES = ['especes', 'cheque', 'cheque_vacances', 'cheque_culture', 'cheque_loisirs']

function ModalMoyenPaiement({ edition, onClose, onEnregistre }) {
  const moyen = edition?.moyen || null
  const creation = !!edition && !moyen

  const [code, setCode] = useState('')
  const [libelle, setLibelle] = useState('')
  const [exigeReference, setExigeReference] = useState(false)
  const [autoriseRendu, setAutoriseRendu] = useState(false)
  const [autoriseDiffere, setAutoriseDiffere] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!edition) return
    setCode(moyen?.code || '')
    setLibelle(moyen?.libelle || '')
    setExigeReference(!!moyen?.exigeReference)
    setAutoriseRendu(!!moyen?.autoriseRendu)
    setAutoriseDiffere(!!moyen?.autoriseDiffere)
    setErreur(null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [edition])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await onEnregistre({ code: code.trim(), libelle: libelle.trim(), exigeReference, autoriseRendu, autoriseDiffere })
    } catch (err) {
      setErreur(erreurEcriture(err))
    } finally {
      setEnvoi(false)
    }
  }

  // Le code saisi ressemble-t-il à un instrument papier que le logiciel ne saura pas reconnaître ?
  const codeNettoye = code.trim().toLowerCase()
  const papierNonReconnu =
    codeNettoye !== ''
    && !CODES_FIDUCIAIRES.includes(codeNettoye)
    && /(cheque|chèque|espece|espèce|liquide|ticket|bon)/.test(codeNettoye + ' ' + libelle.toLowerCase())

  return (
    <Modal
      open={!!edition}
      onClose={onClose}
      titre={creation ? 'Ajouter un moyen de paiement' : `Modifier « ${moyen?.libelle || ''} »`}
    >
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 12 }}>{erreur}</div>}

        <div className="field">
          <label htmlFor="mp-code">Code</label>
          <input id="mp-code" className="input" value={code} onChange={(e) => setCode(e.target.value)} required maxLength={32} placeholder="ex. cb, especes, cheque" />
          <p className="hint">
            Le code est ce que la caisse et la comptabilité manipulent ; le libellé n’est que
            l’étiquette affichée. Le changer sur un moyen déjà utilisé ne renomme PAS les règlements
            déjà encaissés : ils gardent l’ancien code en clair.
          </p>
        </div>

        {papierNonReconnu && (
          <div className="banner banner-warn" style={{ marginBottom: 12 }}>
            Ce moyen ressemble à un instrument qu’on remet en main propre, et le logiciel ne le
            reconnaîtra pas comme tel : seuls {CODES_FIDUCIAIRES.join(', ')} obligent à ouvrir une
            session de caisse. Avec un autre code, une vente directe l’acceptera sans qu’aucune
            session ne réponde du papier reçu.
          </div>
        )}

        <div className="field">
          <label htmlFor="mp-libelle">Libellé</label>
          <input id="mp-libelle" className="input" value={libelle} onChange={(e) => setLibelle(e.target.value)} required maxLength={80} placeholder="ex. Carte bancaire" />
        </div>

        <div className="field">
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, flexDirection: 'row' }}>
            <input type="checkbox" checked={exigeReference} onChange={(e) => setExigeReference(e.target.checked)} /> Passe par le terminal bancaire
          </label>
          <p className="hint">
            Le règlement est envoyé au TPE et n’est enregistré que s’il revient accepté. Un refus ne
            crée aucun règlement — et il est consigné.
          </p>

          <label style={{ display: 'flex', alignItems: 'center', gap: 8, flexDirection: 'row' }}>
            <input type="checkbox" checked={autoriseRendu} onChange={(e) => setAutoriseRendu(e.target.checked)} /> Autorise le rendu de monnaie
          </label>
          <p className="hint">
            Sans cette case, encaisser plus que le montant dû est refusé. C’est elle qui permet à un
            caissier de prendre un billet de 20 € pour 13 € et de rendre la différence.
          </p>

          <label style={{ display: 'flex', alignItems: 'center', gap: 8, flexDirection: 'row' }}>
            <input type="checkbox" checked={autoriseDiffere} onChange={(e) => setAutoriseDiffere(e.target.checked)} /> Autorise le paiement différé
          </label>
          <p className="hint">
            Permet d’enregistrer la vente comme réglée plus tard. Sans cette case, tout règlement
            marqué différé sur ce moyen est refusé.
          </p>
        </div>

        <div className="r" style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi}>
            {envoi ? 'Enregistrement…' : creation ? 'Ajouter' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// --- Comptes & droits (M8) : création/invitation, cycle de vie des comptes, matrice vivante des
// droits. Écriture gardée par `securite.gerer` côté back. ---
function ComptesDroits({ etabActif, etablissements, droits = [] }) {
  const [utilisateurs, setUtilisateurs] = useState([])
  const [roles, setRoles] = useState([])
  const [affectations, setAffectations] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [statut, setStatut] = useState(null)
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(null)
  const [modalInvit, setModalInvit] = useState(false)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [u, r, a] = await Promise.all([
        api.utilisateurs(),
        api.roles(),
        api.affectations().catch(() => null),
      ])
      setUtilisateurs(membres(u))
      setRoles(membres(r))
      setAffectations(membres(a))
    } catch (e) {
      setErreur(e.message || 'Chargement impossible.')
      setStatut(e.status || null)
      setUtilisateurs([]); setRoles([]); setAffectations([])
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  async function action(fn, id, libelleAction) {
    setBusy(id + libelleAction)
    setMsg(null)
    setErreur(null)
    try {
      await fn(id)
      await charger()
      setMsg(`Action « ${libelleAction} » effectuée.`)
    } catch (e) {
      // RG-M8-07 (dernier administrateur) remonte en 422 avec un message explicite.
      setErreur(e.message || `Échec de l'action « ${libelleAction} ».`)
    } finally {
      setBusy(null)
    }
  }

  if (chargement) {
    return <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
  }

  if (erreur && statut === 403) {
    return (
      <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>
        Accès non autorisé : la gestion des comptes &amp; droits requiert le droit <span className="mono">securite.gerer</span> sur cet établissement.
      </div>
    )
  }

  return (
    <div>
      {msg && <div className="banner" style={{ background: 'var(--good-bg, var(--panel-2))', color: 'var(--good)', marginBottom: 12 }}>{msg}</div>}
      {erreur && statut !== 403 && <div className="banner banner-error" style={{ marginBottom: 12 }}>{erreur}</div>}

      <section className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>Comptes utilisateurs</h3>
          <span className="sub">{utilisateurs.length} compte(s)</span>
          <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
            <button className="btn sm" onClick={() => { setMsg(null); setErreur(null); setModalInvit(true) }}>+ Inviter un utilisateur</button>
            <button className="btn ghost sm" onClick={charger}>↻</button>
          </div>
        </div>
        <div className="card-b" style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr><th>Utilisateur</th><th>E-mail</th><th>MFA</th><th>Statut</th><th>Dernier accès</th><th className="num">Actions</th></tr>
            </thead>
            <tbody>
              {utilisateurs.map((u) => {
                const st = u.statut || (u.actif ? 'actif' : '—')
                return (
                  <tr key={u.id}>
                    <td><span className="nm">{u.nom || u.email || '—'}</span></td>
                    <td>{u.email || '—'}</td>
                    <td>{u.mfaActif ? <span className="badge good">actif</span> : <span className="badge mut">—</span>}</td>
                    <td><span className={`badge ${STATUT_BADGE[st] || 'mut'}`}>{st}</span></td>
                    <td>{dateHeureFr(u.dernierAcces)}</td>
                    <td className="num">
                      <div style={{ display: 'inline-flex', gap: 6, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                        {st === 'suspendu' ? (
                          <button className="btn ghost sm" disabled={busy === u.id + 'réactiver'} onClick={() => action(api.reactiverUtilisateur, u.id, 'réactiver')}>Réactiver</button>
                        ) : (
                          <button className="btn ghost sm" disabled={busy === u.id + 'suspendre'} onClick={() => action(api.suspendreUtilisateur, u.id, 'suspendre')}>Suspendre</button>
                        )}
                        {st === 'invite' && (
                          <button className="btn ghost sm" disabled={busy === u.id + 'réinviter'} onClick={() => action(api.reinviterUtilisateur, u.id, 'réinviter')}>Réinviter</button>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
              {utilisateurs.length === 0 && <tr><td colSpan={6} className="empty">Aucun compte.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>

      <RolesSection droits={droits} peutGerer={aLeDroit(droits, 'securite.gerer')} onChange={charger} />

      <MatriceDroits roles={roles} etabActif={etabActif} />

      <section className="card" style={{ marginTop: 16 }}>
        <div className="card-h"><h3>Affectations</h3><span className="sub">rôle × utilisateur × établissement</span></div>
        <div className="card-b" style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr><th>Utilisateur</th><th>Rôle</th><th>Établissement</th></tr>
            </thead>
            <tbody>
              {affectations.map((a) => (
                <tr key={a.id}>
                  <td><span className="nm">{a.utilisateur?.nom || a.utilisateur?.email || iriFin(a.utilisateur)}</span></td>
                  <td>{a.role?.nom || iriFin(a.role)}</td>
                  <td>{a.etablissement?.nom || iriFin(a.etablissement)}</td>
                </tr>
              ))}
              {affectations.length === 0 && <tr><td colSpan={3} className="empty">Aucune affectation.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>

      <ModalInvitation
        open={modalInvit}
        roles={roles}
        etablissements={etablissements}
        etabActif={etabActif}
        onClose={() => setModalInvit(false)}
        onInvite={async (payload) => {
          // Création du compte (invitation) puis, si un rôle + établissement sont choisis,
          // affectation du rôle sur ce périmètre.
          const cree = await api.creerUtilisateur({ email: payload.email, nom: payload.nom })
          if (payload.roleId && payload.etabId) {
            await api.creerAffectation({
              utilisateur: cree['@id'] || `/api/utilisateurs/${cree.id}`,
              role: `/api/roles/${payload.roleId}`,
              etablissement: `/api/etablissements/${payload.etabId}`,
            })
          }
          setModalInvit(false)
          setMsg(`Invitation envoyée à ${payload.email}.`)
          await charger()
        }}
      />
    </div>
  )
}

// Matrice « vivante » des droits : pour chaque rôle, on interroge `/roles/{id}/apercu-droits` sur
// l'établissement actif (équivalent /me simulé). Rôles en colonnes, permissions en lignes (groupées
// par module).
function MatriceDroits({ roles, etabActif }) {
  const [codesParRole, setCodesParRole] = useState({}) // roleId -> Set(codes)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const entrees = await Promise.all(
        roles.map(async (r) => {
          try {
            const res = await api.apercuDroitsRole(r.id, etabActif)
            return [r.id, new Set(res.codes || [])]
          } catch {
            // Repli : dérive les codes depuis les permissions embarquées du rôle.
            const codes = (r.permissions || []).map((p) => p.code || `${p.module}.${p.action}`)
            return [r.id, new Set(codes)]
          }
        }),
      )
      setCodesParRole(Object.fromEntries(entrees))
    } catch (e) {
      setErreur(e.message || 'Chargement de la matrice impossible.')
    } finally {
      setChargement(false)
    }
  }, [roles, etabActif])

  useEffect(() => {
    if (roles.length) charger()
    else setChargement(false)
  }, [roles, charger])

  // Union des codes conférés par au moins un rôle, groupés par module.
  const tousCodes = [...new Set(Object.values(codesParRole).flatMap((s) => [...s]))].sort()
  const parModule = {}
  for (const code of tousCodes) {
    const mod = code.split('.')[0]
    ;(parModule[mod] ||= []).push(code)
  }
  const modules = Object.keys(parModule).sort()

  return (
    <section className="card">
      <div className="card-h">
        <h3>Matrice des droits</h3>
        <span className="sub">rôles × permissions (établissement actif)</span>
        <button className="btn ghost sm" style={{ marginLeft: 'auto' }} onClick={charger} disabled={chargement}>↻</button>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : erreur ? (
          <div className="banner banner-error">{erreur}</div>
        ) : roles.length === 0 || tousCodes.length === 0 ? (
          <div className="empty">Aucun droit à représenter.</div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Permission</th>
                {roles.map((r) => <th key={r.id} className="num">{r.nom}</th>)}
              </tr>
            </thead>
            <tbody>
              {modules.map((mod) => (
                <RowsModule key={mod} module={mod} codes={parModule[mod]} roles={roles} codesParRole={codesParRole} />
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}

function RowsModule({ module, codes, roles, codesParRole }) {
  return (
    <>
      <tr>
        <td colSpan={roles.length + 1} style={{ background: 'var(--panel-2)', fontWeight: 700, textTransform: 'capitalize' }}>{module}</td>
      </tr>
      {codes.map((code) => (
        <tr key={code}>
          <td><span className="mono">{code}</span></td>
          {roles.map((r) => {
            const a = codesParRole[r.id]?.has(code)
            return (
              <td key={r.id} className="num" aria-label={a ? 'accordé' : 'non accordé'}>
                {a ? <span style={{ color: 'var(--good)' }}>✓</span> : <span style={{ color: 'var(--ink-faint)' }}>·</span>}
              </td>
            )
          })}
        </tr>
      ))}
    </>
  )
}

function ModalInvitation({ open, roles, etablissements, etabActif, onClose, onInvite }) {
  const [email, setEmail] = useState('')
  const [nom, setNom] = useState('')
  const [roleId, setRoleId] = useState('')
  const [etabId, setEtabId] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (open) { setEmail(''); setNom(''); setRoleId(''); setEtabId(etabActif || ''); setErreur(null) }
  }, [open, etabActif])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await onInvite({ email: email.trim(), nom: nom.trim(), roleId, etabId })
    } catch (err) {
      setErreur(err.message || "Échec de l'invitation.")
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Inviter un utilisateur">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 12 }}>{erreur}</div>}
        <div className="field">
          <label htmlFor="inv-nom">Nom</label>
          <input id="inv-nom" className="input" value={nom} onChange={(e) => setNom(e.target.value)} required maxLength={180} />
        </div>
        <div className="field">
          <label htmlFor="inv-email">E-mail</label>
          <input id="inv-email" className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="inv-role">Rôle (optionnel)</label>
          <select id="inv-role" className="select" value={roleId} onChange={(e) => setRoleId(e.target.value)}>
            <option value="">— Aucun rôle —</option>
            {roles.map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="inv-etab">Établissement</label>
          <select id="inv-etab" className="select" value={etabId} onChange={(e) => setEtabId(e.target.value)} disabled={!roleId}>
            <option value="">— Sélectionner —</option>
            {(etablissements || []).map((e) => <option key={e.id} value={e.id}>{e.nom}</option>)}
          </select>
          <span className="hint">Le rôle n'est affecté que si un établissement est choisi. Sans mot de passe, le compte reçoit une invitation par e-mail.</span>
        </div>
        <div className="r" style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi}>{envoi ? 'Envoi…' : 'Inviter'}</button>
        </div>
      </form>
    </Modal>
  )
}

// Message d'écriture lisible : distingue le refus de droit (403) du reste.
function erreurEcriture(e) {
  if (e?.status === 403) return "Droit insuffisant pour cette modification (compta.gerer requis)."
  return e?.message || 'Enregistrement impossible.'
}

// Dernier segment d'un IRI (repli quand l'objet lié n'est pas embarqué).
function iriFin(v) {
  if (!v) return '—'
  if (typeof v === 'string') return v.split('/').pop()
  return v.nom || v.email || (v.id ? String(v.id) : '—')
}

// Capacités activables : catalogue socle croisé avec l'état par établissement (lecture).
function Capacites({ etabActif }) {
  const [items, setItems] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [cat, flags] = await Promise.all([
        api.catalogueCapacites(),
        etabActif ? api.fonctionnalitesEtablissement(etabActif).catch(() => null) : Promise.resolve(null),
      ])
      const actives = {}
      for (const f of membres(flags)) actives[f.capaciteCode] = f.active
      setItems(membres(cat).map((c) => ({ ...c, active: actives[c.code] ?? false })))
    } catch (e) {
      setErreur(e.message || 'Chargement du catalogue impossible.')
      setItems([])
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  return (
    <section className="card">
      <div className="card-h">
        <h3>Capacités activables</h3>
        <span className="sub">feature flags par établissement</span>
        <button className="btn ghost sm" style={{ marginLeft: 'auto' }} onClick={charger} disabled={chargement}>↻</button>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : erreur ? (
          <div className="banner banner-error">{erreur}</div>
        ) : (
          <table className="tbl">
            <thead>
              <tr><th>Capacité</th><th>Catégorie</th><th>Description</th><th>État (établissement)</th></tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.code}>
                  <td><span className="nm">{c.libelle || c.code}</span> <span className="mono" style={{ color: 'var(--ink-faint)' }}>{c.code}</span></td>
                  <td>{c.categorie || '—'}</td>
                  <td style={{ color: 'var(--ink-soft)' }}>{c.description || '—'}</td>
                  <td><span className={`badge ${c.active ? 'good' : 'mut'}`}>{c.active ? 'activée' : 'inactive'}</span></td>
                </tr>
              ))}
              {items.length === 0 && <tr><td colSpan={4} className="empty">Aucune capacité au catalogue.</td></tr>}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}
