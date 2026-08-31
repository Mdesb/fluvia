import { useEffect, useState, useCallback } from 'react'
import VocabulaireMetier from '../components/VocabulaireMetier.jsx'
import Liste, { texte, dateHeureFr } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import PlanningOuvertureSection from '../components/PlanningOuvertureSection.jsx'
import Modal from '../components/Modal.jsx'
import ReferentielEditable from '../components/ReferentielEditable.jsx'
import TauxTvaLegaux from '../components/TauxTvaLegaux.jsx'
import PretAVendre from '../components/PretAVendre.jsx'
import RolesSection from '../components/RolesSection.jsx'
import Qr from '../components/Qr.jsx'
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
  // « Capacités activables » était le nom interne d'un mécanisme, pas celui d'un réglage. Maxime,
  // à la revue : « je ne sais pas ce que c'est ».
  ['capacites', 'Modules en service'],
  // Les horaires d'ouverture sont une CONFIGURATION du site, pas un écran de consultation : ils se
  // saisissent deux fois par an. Ils portent surtout la case qui fait refuser un passage à la
  // porte — elle n'a rien à faire dans un agenda qu'on ouvre pour regarder sa semaine.
  ['ouverture', 'Horaires d’ouverture'],
]

// Les trois formes d'exploitation que le socle connaît (`Compta\Enum\TypeExploitant`), en clair.
const PROFILS_COMPTABLES = {
  regie_directe: 'Régie directe',
  dsp: 'Délégation de service public',
  groupe_prive: 'Groupe privé',
}

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

function descripteurTva(api, profils = []) {
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
        // CRÉER UN TAUX DE TVA ÉTAIT IMPOSSIBLE, ET C'EST CE QUI BLOQUAIT TOUTE MISE EN VENTE.
        //
        // Le moteur envoie `Number(v)` pour un champ `type: 'nombre'` — donc `20`. Or
        // `Compta\Entity\TauxTva::$taux` est déclaré `private string $taux = '0.00'` : le
        // désérialiseur refuse en 422, « The type of the "taux" attribute must be "string",
        // "integer" given ». Aucun taux ne pouvait donc être créé, et sans taux aucun produit ne
        // peut être rattaché ni vendu — le troisième point de « Avant de pouvoir vendre » était
        // infranchissable pour tout le monde.
        //
        // On ne corrige PAS le moteur : `Saison::$priorite` est un `int` et attend bien un nombre.
        // Le type est une propriété du champ, pas du composant, d'où le `versCorps` — le crochet
        // prévu exactement pour ça.
        //
        // Un montant part en chaîne dans tout ce dépôt (prix, plafonds, cautions) : la virgule
        // flottante binaire ne représente pas 20,10 exactement, et sur de l'argent ça se voit.
        versCorps: (v) => (v === '' || v == null ? null : String(v)),
      },
      {
        // LE SECOND REFUS, CELUI QU'ON NE VOIT QU'APRÈS AVOIR LEVÉ LE PREMIER.
        //
        // `TauxTva::$profilExploitant` porte `nullable: false`, `NotNull`, ET le groupe
        // `taux:write` : c'est au client de le fournir, aucun listener ne le pose. Une fois le type
        // du taux corrigé, le serveur répondait « profilExploitant: This value should not be
        // null » — constaté à l'écran, pas déduit. Corriger le premier défaut sans celui-ci aurait
        // remplacé un message incompréhensible par un autre, et laissé la création tout aussi
        // impossible.
        nom: 'profilExploitant',
        libelle: 'Profil comptable',
        type: 'choix',
        requis: true,
        options: profils.map((p) => ({
          valeur: p['@id'] || `/api/profil_exploitants/${p.id}`,
          libelle: [PROFILS_COMPTABLES[p.type] || p.type, p.referentielComptable, p.siren]
            .filter(Boolean)
            .join(' · '),
        })),
        aide: profils.length === 0
          ? "Aucun profil comptable n'existe pour cet établissement, et un taux de TVA doit en "
            + 'porter un. Tant qu’il manque, aucun taux ne peut être créé — c’est lui qui fixe le '
            + 'référentiel comptable (M57, M4…) auquel le taux se rattache.'
          : 'Le référentiel comptable auquel ce taux appartient. C’est lui qui décide de la façon '
            + 'dont la TVA remonte en comptabilité.',
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

/**
 * ⚠ `estEditeur` N'EST PAS UN DROIT, C'EST UNE IDENTITE DE TENANT.
 *
 * Deux sections de cet ecran — « Nouveau client » et la liste des etablissements — decrivent le
 * parc de l'EDITEUR. Elles etaient gardees par `organisation.gerer`, que porte le role
 * « Administrateur groupe », lequel est un role CLIENT : l'administrateur d'une regie voyait donc,
 * dans son propre parametrage, le mecanisme commercial de son fournisseur.
 *
 * Aucune permission ne peut regler cela, parce que la question n'est pas « a-t-il le droit de
 * gerer une organisation ? » — il l'a — mais « DE QUELLE organisation parle-t-on ? ». C'est une
 * identite, et `/me` la rend deja sous `estEditeur`, calculee sur l'etablissement ACTIF.
 *
 * Valeur par defaut `false` : tant que le profil n'est pas charge, on CACHE. Montrer puis cacher
 * ferait apparaitre une fraction de seconde, a un client, ce qu'on veut precisement lui epargner.
 */
export default function Parametres({ etabActif, etablissements, droits = [], onCapacitesChangees, estEditeur = false, me = null, envoiCourriel = false }) {
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
  // Le profil comptable de l'établissement : obligatoire sur un taux de TVA, jamais demandé.
  // Incremente a chaque ecriture d'un reglage suivi par << Avant de pouvoir vendre >> : sans ca, la
  // liste garde son ancien decompte et dit qu'il manque ce qu'on vient de creer.
  const [versionReferentiels, setVersionReferentiels] = useState(0)
  const referentielEcrit = useCallback(() => setVersionReferentiels((v) => v + 1), [])

  const [profils, setProfils] = useState([])
  useEffect(() => {
    let annule = false
    api.profilsExploitant()
      .then((r) => { if (!annule) setProfils(membres(r)) })
      .catch(() => { if (!annule) setProfils([]) })
    return () => { annule = true }
  }, [etabActif])

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
      <PretAVendre etabActif={etabActif} droits={droits} onAller={setSousOnglet} version={versionReferentiels} />

      {sousOnglet === 'ouverture' && <PlanningOuvertureSection droits={droits} etabActif={etabActif} />}

      {sousOnglet === 'entites' && (
        <div className="resa-grid">
          {/* ⚠ RESERVE A L'EDITEUR. Ouvrir une structure, c'est creer un client de Fluvia : un
              exploitant n'a rien a y faire, et le lui montrer revient a lui exposer le mecanisme
              commercial de son fournisseur depuis l'interieur de son propre logiciel. */}
          {estEditeur && aLeDroit(droits, 'organisation.gerer') && (
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

          {/* « Regions » reste : Maxime a nomme « Nouveau client » et « etablissement », pas les
              regions. Il regardait un ecran, pas une liste exhaustive — la question lui est posee
              plutot que tranchee ici. En attendant, on ne masque que ce qu'il a nomme. */}
          <RegionsSection peutEcrire={aLeDroit(droits, 'organisation.gerer')} />
          {/* ⚠ ON NE RETIRE QUE LA GESTION, PAS L'ACCES. Un exploitant multi-sites continue de voir
              ses etablissements et d'en changer : le selecteur vit dans la barre du haut
              (`AppShell`, `aria-label="Etablissement actif"`), sans garde ni permission, et il n'a
              jamais dependu de cette section. Ce qui part ici est CREER / RENOMMER / EDITER — les
              « infos uniquement pour moi » de Maxime.

              J'avais d'abord ecrit l'inverse — « il perd la liste de SES etablissements » — sans le
              verifier. La phrase etait plausible et prudente, donc personne ne l'a mesuree : elle a
              ete relayee de session en session jusqu'a etre classee regression fonctionnelle en tete
              des decisions en attente. Une mise en garde non mesuree coute autant qu'une affirmation
              fausse, et elle se propage mieux, parce que la contredire a l'air imprudent. */}
          {/* ⚠ PAS DE GARDE `estEditeur` ICI, ET C'EST UN CHOIX DE MAXIME APRES L'AVOIR VU.
              Les deux entrees masquees juste au-dessus n'ont pas la meme nature : ouvrir une
              structure cree un CLIENT DE FLUVIA — metier de l'editeur — tandis que gerer ses six
              piscines est le metier de l'exploitant. Un groupe multi-sites garde donc la liste et
              le parametrage de SES etablissements.

              ⚠ La raison qui a circule pour ce choix etait fausse, et la mesure est ici pour qu'on
              ne la reprenne pas : masquer cette section ne faisait PAS perdre ses etablissements a
              un exploitant. Le selecteur vit dans la barre du haut (`AppShell`,
              `aria-label="Etablissement actif"`), sans garde ni permission. Ce qui partait etait le
              panneau de gestion, pas l'acces. */}
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
            onEcrit={referentielEcrit}
            peutEcrire={aLeDroit(droits, 'offre.gerer')}
          />
          <ReferentielEditable
            descripteur={descripteurTva(api, profils)}
            onEcrit={referentielEcrit}
            peutEcrire={aLeDroit(droits, 'compta.gerer')}
          />
          {/* JUSTE SOUS LA LISTE QU'IL ALIMENTE, ET PAS AILLEURS.
              « Les taux de TVA sont definis par les lois, un utilisateur n'a pas besoin de le
              creer » — mais tant que le referentiel vit dans un autre onglet, on continue de
              saisir a la main sans savoir qu'il existe. Le mettre ici met la reponse a cote de la
              question : la liste vide qu'on s'apprete a remplir, et la loi qui la remplit deja.
              `version` le fait relire apres une reprise, pour que « Deja repris » soit vrai tout
              de suite plutot qu'au prochain chargement de la page. */}
          <TauxTvaLegaux
            peutModifier={aLeDroit(droits, 'compta.gerer')}
            version={versionReferentiels}
            onRepris={referentielEcrit}
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
            onEcrit={referentielEcrit}
            peutEcrire={aLeDroit(droits, 'caisse.gerer')}
          />
          <CaissesSection etabActif={etabActif} peutGerer={aLeDroit(droits, 'caisse.gerer')} onEcrit={referentielEcrit} />
          <MoyensPaiement etabActif={etabActif} />
        </div>
      )}

      {sousOnglet === 'droits' && (
        <ComptesDroits etabActif={etabActif} etablissements={etablissements} droits={droits} me={me} />
      )}

      {sousOnglet === 'capacites' && <Capacites etabActif={etabActif} onCapacitesChangees={onCapacitesChangees} />}

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


// --- Les caisses : le tiroir depuis lequel on encaisse. ---
//
// ON NE POUVAIT PAS EN CRÉER UNE, ET C'ÉTAIT LE DERNIER VERROU DE LA MISE EN VENTE.
//
// `POST /api/caisses` existe depuis le début (droit `caisse.gerer`) et n'était appelé d'aucun écran.
// Cette liste était en lecture seule. Conséquence observée sur GI-ONE FITNESS : le formulaire
// d'ouverture de session proposait « Aucune caisse » comme unique option — sans valeur — puis
// refusait avec « Point de vente et caisse requis » alors que le point de vente était choisi. Il
// reprochait deux champs quand un seul manquait, et celui-là était impossible à remplir.
//
// Sixième occurrence de la même famille : on crée une chose là où c'est le métier (§9.3 des
// conventions), et le métier d'une caisse est ici, à côté de son point de vente.
function CaissesSection({ etabActif, peutGerer, onEcrit }) {
  const [caisses, setCaisses] = useState([])
  const [pdvs, setPdvs] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [msg, setMsg] = useState(null)
  const [edition, setEdition] = useState(null) // { caisse } ou { creation: true }

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    // Deux lectures, deux droits : sans les points de vente on peut encore LIRE les caisses, mais
    // pas en créer — le formulaire le dira plutôt que de proposer une liste vide.
    const [c, p] = await Promise.allSettled([api.caisses(), api.pointDeVentes()])
    setCaisses(c.status === 'fulfilled' ? membres(c.value) : [])
    setPdvs(p.status === 'fulfilled' ? membres(p.value) : [])
    if (c.status === 'rejected') setErreur(c.reason?.message || 'Chargement impossible.')
    setChargement(false)
  }, [etabActif])

  useEffect(() => { charger() }, [charger])

  return (
    <section className="card">
      <div className="card-h">
        <h3>Caisses</h3>
        <span className="sub">le poste depuis lequel on encaisse</span>
        {peutGerer && (
          <div className="actions" style={{ marginLeft: 'auto' }}>
            <button className="btn sm" type="button" onClick={() => { setMsg(null); setErreur(null); setEdition({ creation: true }) }}>
              ＋ Ajouter
            </button>
            <button className="btn ghost sm" type="button" onClick={charger} disabled={chargement}>↻</button>
          </div>
        )}
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {msg && <div className="banner banner-ok" style={{ margin: '0 0 12px' }}>{msg}</div>}
        {erreur && <div className="banner banner-error" style={{ margin: '0 0 12px' }}>{erreur}</div>}

        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : caisses.length === 0 ? (
          <div className="empty">
            Aucune caisse. Une caisse, c&rsquo;est le tiroir et le poste depuis lesquels on encaisse —
            un comptoir d&rsquo;accueil, une buvette, une borne. Tant qu&rsquo;il n&rsquo;y en a pas,
            aucune session ne peut s&rsquo;ouvrir et rien ne peut être vendu, même avec un point de
            vente et des tarifs.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr><th>Caisse</th><th>Point de vente</th><th>État</th>{peutGerer && <th className="num">Actions</th>}</tr>
            </thead>
            <tbody>
              {caisses.map((c) => (
                <tr key={c.id}>
                  <td><span className="nm">{c.libelle || '—'}</span></td>
                  <td>{c.pointDeVente?.libelle || <span className="sub">—</span>}</td>
                  <td>
                    {/* `securisee` est l'état NORMAL d'une caisse au repos, pas une alerte : elle
                        exige le code régisseur pour être rouverte. La peindre en rouge ferait
                        chercher un incident là où il n'y en a pas. */}
                    <span className={`badge ${c.etat === 'ouverte' ? 'good' : 'mut'}`}>
                      {mot(c.etat)}
                    </span>
                  </td>
                  {peutGerer && (
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => { setMsg(null); setErreur(null); setEdition({ caisse: c }) }}>
                        Renommer
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <CaisseModal
        edition={edition}
        pdvs={pdvs}
        onClose={() => setEdition(null)}
        onEnregistre={async (corps) => {
          const existante = edition?.caisse
          if (existante) await api.majCaisse(existante.id, { libelle: corps.libelle })
          else await api.creerCaisse(corps)
          setEdition(null)
          setMsg(existante ? 'Caisse renommée.' : `Caisse « ${corps.libelle} » créée.`)
          await charger()
          onEcrit?.()
        }}
      />
    </section>
  )
}

function CaisseModal({ edition, pdvs, onClose, onEnregistre }) {
  const caisse = edition?.caisse || null
  const creation = !!edition && !caisse
  const [libelle, setLibelle] = useState('')
  const [pdv, setPdv] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!edition) return
    setLibelle(caisse?.libelle || '')
    setPdv(caisse?.pointDeVente?.id || pdvs[0]?.id || '')
    setErreur(null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [edition])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    if (!libelle.trim()) { setErreur('Donnez un nom à cette caisse.'); return }
    if (creation && !pdv) { setErreur('Une caisse appartient à un point de vente : choisissez-en un.'); return }
    setEnvoi(true)
    try {
      await onEnregistre({ libelle: libelle.trim(), pointDeVente: `/api/point_de_ventes/${pdv}` })
    } catch (err) {
      setErreur(erreurEcriture(err))
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={!!edition} onClose={onClose} titre={creation ? 'Ajouter une caisse' : `Renommer « ${caisse?.libelle || ''} »`}>
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 12 }}>{erreur}</div>}

        <div className="field">
          <label htmlFor="ca-lib">Nom de la caisse *</label>
          <input
            id="ca-lib"
            className="input"
            value={libelle}
            maxLength={120}
            placeholder="Comptoir 1, Buvette, Borne d’entrée…"
            onChange={(e) => setLibelle(e.target.value)}
          />
          <p className="hint">
            Ce que le caissier choisira en ouvrant sa session. Nommez l’endroit, pas le matériel :
            c’est le poste qu’on reconnaît, pas le tiroir.
          </p>
        </div>

        {creation && (
          <div className="field">
            <label htmlFor="ca-pdv">Point de vente *</label>
            {/* LE POINT DE VENTE NE SE CHANGE PAS APRÈS COUP, ET CE N'EST PAS UN OUBLI.
                Les sessions, les ventes et les clôtures Z d'une caisse restent rattachées à son
                point de vente. Le déplacer ferait basculer un historique d'encaissement d'une régie
                à une autre sans que rien ne le signale. On crée une seconde caisse. */}
            {pdvs.length === 0 ? (
              <div className="banner banner-warn">
                Aucun point de vente sur cet établissement. Une caisse appartient à un point de
                vente : créez-en un ci-dessus avant d’ajouter une caisse.
              </div>
            ) : (
              <>
                <select id="ca-pdv" className="input" value={pdv} onChange={(e) => setPdv(e.target.value)}>
                  {pdvs.map((p) => <option key={p.id} value={p.id}>{p.libelle}</option>)}
                </select>
                <p className="hint">
                  Il ne pourra plus être changé : les sessions, les ventes et les clôtures Z de cette
                  caisse y resteront rattachées. Pour un autre point de vente, créez une autre caisse.
                </p>
              </>
            )}
          </div>
        )}

        <div className="r" style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi || (creation && pdvs.length === 0)}>
            {envoi ? 'Enregistrement…' : creation ? 'Créer la caisse' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

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
/**
 * MA DOUBLE AUTHENTIFICATION — le parcours qui manquait, et dont l'absence a coûté une garde.
 *
 * ── CE QUE SON ABSENCE A COÛTÉ ─────────────────────────────────────────────────────────────────
 *
 * `AffectationProcessor` exigeait le MFA du bénéficiaire avant d'affecter un rôle à privilèges.
 * Comme aucun écran ne l'activait, **on ne pouvait nommer aucun administrateur, chez aucun client**
 * — 6 rôles à privilèges, 0 compte avec MFA actif. La garde est suspendue depuis le 31/08 par
 * décision de Maxime, « lever maintenant, construire ensuite ». Ceci est le « ensuite », et c'est
 * ce qui permettra de remettre `MFA_EXIGE_POUR_ROLE_A_PRIVILEGES` à `true`.
 *
 * ── ⚠ LE SECRET ET LES CODES NE SONT RENDUS QU'UNE FOIS ───────────────────────────────────────
 *
 * Le serveur ne conserve que leur forme chiffrée ou hachée. Un écran qui ne les montre pas à
 * l'instant de l'activation les perd définitivement — et un compte sans code de récupération est un
 * compte qu'un téléphone perdu enferme dehors. D'où l'insistance de l'écran à ce moment précis :
 * c'est la seule occasion.
 *
 * ── L'ORDRE DES ÉTAPES N'EST PAS COSMÉTIQUE ────────────────────────────────────────────────────
 *
 * Activer POSE un secret sans rendre `mfaActif` vrai ; seule la confirmation par un code l'active.
 * Quelqu'un qui abandonne en cours de route n'est donc pas enfermé dehors : son compte fonctionne
 * comme avant, et il pourra recommencer.
 */
function MonMfa({ moi, actif, onChange }) {
  const [etape, setEtape] = useState('repos') // repos | secret
  const [secret, setSecret] = useState(null)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [msg, setMsg] = useState(null)

  if (!moi?.id) return null

  async function activer() {
    setBusy(true); setErreur(null); setMsg(null)
    try {
      setSecret(await api.mfaActiver(moi.id))
      setEtape('secret')
      setCode('')
    } catch (e) {
      setErreur(e.message || 'L’activation n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  async function confirmer() {
    setBusy(true); setErreur(null)
    try {
      await api.mfaConfirmer(moi.id, code.trim())
      setEtape('repos'); setSecret(null); setCode('')
      setMsg('Double authentification activée. Un code vous sera demandé à chaque connexion.')
      onChange?.()
    } catch (e) {
      setErreur(e.message || 'Code refusé.')
    } finally {
      setBusy(false)
    }
  }

  async function desactiver() {
    setBusy(true); setErreur(null)
    try {
      await api.mfaDesactiver(moi.id, code.trim())
      setCode('')
      setMsg('Double authentification désactivée.')
      onChange?.()
    } catch (e) {
      // ⚠ LE SERVEUR REFUSE SI LE COMPTE DÉTIENT UN RÔLE À PRIVILÈGES, et son message le dit. On ne
      // le reformule pas : la règle vit là-bas, et deux formulations divergeraient.
      setErreur(e.message || 'La désactivation n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="card card-espacee">
      <div className="card-h">
        <h3>Ma double authentification</h3>
        <span className={`badge ${actif ? 'good' : 'mut'}`}>{actif ? 'active' : 'inactive'}</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {msg && <div className="banner banner-ok">{msg}</div>}

        {etape === 'secret' && secret && (
          <div className="mfa-secret">
            <p>
              Scannez ce code avec votre application d’authentification, puis saisissez le code
              qu’elle affiche.
            </p>
            <Qr value={secret.uriProvisionnement} size={160} title="Configuration de la double authentification" />
            <p className="hint">
              Saisie manuelle : <span className="mono">{secret.secret}</span>
            </p>

            {/* ⚠ LES CODES DE RÉCUPÉRATION NE SERONT PLUS JAMAIS AFFICHÉS. Le serveur n'en garde
                que le haché. C'est ici, et seulement ici, qu'on peut les noter — et sans eux, un
                téléphone perdu enferme le compte dehors. */}
            <div className="banner banner-warn">
              <strong>Notez ces codes de récupération maintenant.</strong> Ils ne seront plus jamais
              affichés, et chacun ne sert qu’une fois. Sans eux, un téléphone perdu vous ferme
              l’accès jusqu’à ce qu’un administrateur réinitialise votre double authentification.
              <div className="mfa-codes">
                {(secret.codesRecuperation || []).map((c) => <span key={c} className="mono">{c}</span>)}
              </div>
            </div>

            <div className="resa-part-form">
              <input
                className="input"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                placeholder="Code à six chiffres"
                inputMode="numeric"
                aria-label="Code de confirmation"
              />
              <button className="btn" disabled={busy || !code.trim()} onClick={confirmer}>
                {busy ? 'Vérification…' : 'Confirmer l’activation'}
              </button>
              <button className="btn ghost" disabled={busy} onClick={() => { setEtape('repos'); setSecret(null) }}>
                Abandonner
              </button>
            </div>
            <p className="hint">
              Tant que vous n’avez pas confirmé, rien ne change : votre compte se connecte comme
              avant.
            </p>
          </div>
        )}

        {etape === 'repos' && !actif && (
          <>
            <p className="hint">
              Un code à usage unique vous sera demandé à chaque connexion, en plus de votre mot de
              passe. Nécessaire pour détenir un rôle à privilèges.
            </p>
            <button className="btn" disabled={busy} onClick={activer}>
              {busy ? 'Préparation…' : 'Activer la double authentification'}
            </button>
          </>
        )}

        {etape === 'repos' && actif && (
          <div className="resa-part-form">
            <input
              className="input"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="Code actuel ou code de récupération"
              aria-label="Code pour désactiver"
            />
            <button className="btn ghost" disabled={busy || !code.trim()} onClick={desactiver}>
              Désactiver
            </button>
          </div>
        )}
      </div>
    </section>
  )
}

function ComptesDroits({ etabActif, etablissements, droits = [], me = null }) {
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
                        {/* ⚠ LA SEULE ISSUE D'UN APPAREIL PERDU AVEC DES CODES DE RÉCUPÉRATION
                            ÉPUISÉS. Sans elle, le compte est fermé pour de bon — et la route
                            existait depuis l'origine sans que rien ne l'appelle. Réservée à
                            `securite.gerer`, et tracée dans le journal d'audit avec l'état avant
                            et après. On ne la propose pas sur les comptes sans MFA : il n'y aurait
                            rien à réinitialiser. */}
                        {u.mfaActif && aLeDroit(droits, 'securite.gerer') && (
                          <button
                            className="btn ghost sm"
                            disabled={busy === u.id + 'réinitialiser le MFA'}
                            onClick={() => action(api.mfaReinitialiser, u.id, 'réinitialiser le MFA')}
                            title="Efface la double authentification de ce compte : il pourra se reconnecter avec son seul mot de passe, puis la réactiver."
                          >
                            Réinitialiser le MFA
                          </button>
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

      <MonMfa
        moi={me}
        actif={utilisateurs.find((u) => u.id === me?.id)?.mfaActif === true}
        onChange={charger}
      />

      <RolesSection droits={droits} peutGerer={aLeDroit(droits, 'securite.gerer')} onChange={charger} />

      <MatriceDroits roles={roles} etabActif={etabActif} affectations={affectations} utilisateurs={utilisateurs} />

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
        envoiCourriel={envoiCourriel}
        open={modalInvit}
        roles={roles}
        etablissements={etablissements}
        etabActif={etabActif}
        onClose={() => setModalInvit(false)}
        onInvite={async (payload) => {
          // Création du compte (invitation) puis, si un rôle + établissement sont choisis,
          // affectation du rôle sur ce périmètre.
          // LE SERVEUR ACCEPTE LES DEUX CHEMINS, ET L'ECRAN N'EN OFFRAIT QU'UN.
          //
          // `UtilisateurProcessor` : si `motDePasseClair` est fourni, il le hache, efface la
          // valeur en clair et active le compte immediatement — aucun jeton d'invitation n'est
          // genere. Sinon il cree un jeton et appelle `InvitationMailer`.
          //
          // La modale n'exposait pas ce champ : le seul chemin restant passait donc par un
          // courriel, et `MAILER_DSN` vaut `null://null`. Aucun utilisateur nouveau ne pouvait se
          // connecter — ni caissier, ni comptable. Le logiciel ne savait inscrire personne.
          const cree = await api.creerUtilisateur({
            email: payload.email,
            nom: payload.nom,
            ...(payload.motDePasse ? { motDePasseClair: payload.motDePasse } : {}),
          })
          if (payload.roleId && payload.etabId) {
            await api.creerAffectation({
              utilisateur: cree['@id'] || `/api/utilisateurs/${cree.id}`,
              role: `/api/roles/${payload.roleId}`,
              etablissement: `/api/etablissements/${payload.etabId}`,
            })
          }
          setModalInvit(false)
          // ⚠ ON NE REDIT PAS LE MOT DE PASSE ICI. Il a ete saisi une fois, il est hache cote
          // serveur, et le reafficher dans un bandeau le laisserait sur l'ecran d'un poste
          // partage — souvent une caisse en libre-service.
          // ⚠ CETTE PHRASE ÉTAIT ÉCRITE EN DUR, ET ELLE SERAIT DEVENUE FAUSSE SANS PRÉVENIR.
          //
          // « aucun envoi de courriel n'est branché » était vrai à l'écriture. Le jour où Maxime
          // configure un expéditeur, elle annoncerait une invitation non partie alors qu'elle
          // serait partie — et rien ne relierait la phrase à ce qui l'a rendue fausse. C'est le
          // défaut qu'on a passé la nuit à retirer d'ailleurs ; il n'y a pas de raison de le
          // laisser ici.
          //
          // `envoiCourriel` vient de `/me` (`ExpediteurCourriel::estBranche()`), donc la phrase
          // suit l'état réel de l'instance et se corrigera toute seule.
          setMsg(payload.motDePasse
            ? `Compte créé pour ${payload.email}. Communiquez-lui son mot de passe de vive voix.`
            : envoiCourriel
              ? `Compte créé pour ${payload.email} — une invitation lui a été envoyée par courriel.`
              : `Compte créé pour ${payload.email} — invitation NON envoyée : cette instance n’a pas `
                + `d’expéditeur de courriel. Posez-lui un mot de passe depuis sa fiche, ou `
                + `recréez-le en choisissant « Je pose un mot de passe maintenant ».`)
          await charger()
        }}
      />
    </div>
  )
}

// LES NOMS DES MODULES, PARCE QUE `dms` ET `crm` NE SONT PAS DU FRANÇAIS.
//
// Le code `module.action` reste affiché à côté : c'est lui qu'on cite dans un ticket, et c'est lui
// qui figure dans la modale d'édition d'un rôle. On traduit pour lire, on garde le code pour agir.
const NOM_MODULE = {
  '*': 'Tous les modules (joker)',
  acces: 'Contrôle d’accès',
  autorisation: 'Autorisations & plafonds',
  boutique: 'Boutique en ligne',
  caisse: 'Caisse',
  campagne: 'Campagnes',
  caution: 'Cautions',
  compta: 'Comptabilité',
  crm: 'Clients & affaires',
  demo: 'Démonstration',
  dms: 'Documents',
  facturation: 'Facturation',
  fidelite: 'Fidélité',
  finance: 'Achats & trésorerie',
  fonctionnalite: 'Modules en service',
  lodging: 'Hébergement',
  musee: 'Musée',
  ocr: 'Lecture automatique de documents',
  offre: 'Catalogue & offres',
  organisation: 'Organisation & établissements',
  padel: 'Padel',
  patinoire: 'Patinoire',
  personnel: 'Personnel',
  piscine: 'Piscine',
  recouvrement: 'Recouvrement',
  reporting: 'Reporting',
  reservation: 'Réservation',
  revenue_recovery: 'Relance des recettes',
  securite: 'Comptes & rôles',
  sepa: 'Prélèvements SEPA',
  smart_flow: 'Flux SmartFlow',
  social: 'Publication sociale',
  sport: 'Sport & fitness',
  stay: 'Séjours',
  stock: 'Stock',
  support: 'Assistance',
  vente: 'Vente',
}

function nomModule(code) {
  return NOM_MODULE[code] || code
}

// UN RÔLE COUVRE-T-IL CE DROIT ? C'EST LA RÈGLE DU SERVEUR, PAS UNE ÉGALITÉ DE CHAÎNES.
//
// `GET /roles/{id}/apercu-droits` rend les codes BRUTS du rôle : un rôle qui porte `*` × `*` répond
// `["*.*"]` et rien d'autre — le serveur n'étend pas le joker, il l'interprète à la demande.
//
// L'ancienne matrice testait `codes.has(code)`, une égalité stricte. Elle affichait donc
// « Administrateur d'établissement » comme n'ayant qu'un seul droit alors qu'il les a tous, et
// « Administrateur groupe » comme n'ayant que ses 98 lignes explicites. C'est la faute exacte
// décrite en tête de `api/droits.js`, qui avait déjà vidé un menu : rejouer une règle
// d'autorisation à moitié.
//
// `aLeDroit` est la règle entière, celle que le serveur applique. On s'en sert.
function roleCouvre(codes, code) {
  return aLeDroit([...codes], code)
}

// QUI PEUT FAIRE QUOI — LA MATRICE COMPLÈTE NE RÉPONDAIT À AUCUNE QUESTION.
//
// L'ancienne version affichait 251 permissions en lignes × tous les rôles en colonnes, remplies de
// « ✓ » et de « · », avec les codes internes en clair. Sur la préprod : 57 colonnes, 200 lignes,
// 11 400 cellules. Une session en revue avec Maxime l'a constaté à l'écran : illisible, alors que
// c'est précisément l'écran censé répondre à « qui a le droit de quoi ».
//
// Une matrice n'est pas fausse en soi ; elle est fausse À CETTE TAILLE. On lit une matrice quand on
// compare quelques colonnes sur quelques lignes. Personne ne compare 57 rôles à la fois : on se
// demande « qui peut annuler une vente ? », et c'est une question qui porte sur UN module.
//
// D'où la portée par module : les lignes sont les actions de ce module, les colonnes les seuls
// rôles qui en donnent au moins une. Sur `vente` cela fait 10 lignes et une poignée de colonnes —
// une matrice qu'on peut effectivement lire.
//
// LE MODULE QUE PERSONNE NE PEUT TOUCHER EST UNE RÉPONSE, PAS UN VIDE. On part de `/api/permissions`
// (le référentiel entier, 251 codes) et non de l'union de ce que les rôles accordent : sinon un
// droit que personne ne détient disparaît de l'écran, et son absence devient invisible.
function MatriceDroits({ roles, etabActif, affectations = [], utilisateurs = [] }) {
  const [codesParRole, setCodesParRole] = useState({}) // roleId -> Set(codes bruts)
  const [permissions, setPermissions] = useState([])
  const [module, setModule] = useState('')
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [perms, entrees] = await Promise.all([
        api.permissions().catch(() => null),
        Promise.all(
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
        ),
      ])
      setPermissions(membres(perms))
      setCodesParRole(Object.fromEntries(entrees))
    } catch (e) {
      setErreur(e.message || 'Chargement des droits impossible.')
    } finally {
      setChargement(false)
    }
  }, [roles, etabActif])

  useEffect(() => {
    if (roles.length) charger()
    else setChargement(false)
  }, [roles, charger])

  const modules = [...new Set(permissions.map((p) => p.module))].sort((a, b) =>
    nomModule(a).localeCompare(nomModule(b), 'fr'),
  )

  useEffect(() => {
    if (!module && modules.length) setModule(modules.includes('vente') ? 'vente' : modules[0])
  }, [modules, module])

  const actions = permissions
    .filter((p) => p.module === module)
    .map((p) => p.code || `${p.module}.${p.action}`)
    .sort()

  // Les rôles qui donnent au moins une action de ce module — joker compris.
  const rolesConcernes = roles.filter((r) => {
    const codes = codesParRole[r.id]
    if (!codes) return false
    return actions.some((code) => roleCouvre(codes, code))
  })

  // Combien de comptes portent chaque rôle sur l'établissement affiché. « Un rôle que personne ne
  // porte » et « un rôle porté par douze personnes » n'appellent pas la même vigilance.
  const comptesParRole = {}
  for (const a of affectations) {
    const rid = typeof a.role === 'object' ? a.role?.id : String(a.role || '').split('/').pop()
    const eid = typeof a.etablissement === 'object' ? a.etablissement?.id : String(a.etablissement || '').split('/').pop()
    if (!rid || (etabActif && eid && eid !== etabActif)) continue
    comptesParRole[rid] = (comptesParRole[rid] || 0) + 1
  }

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Qui a le droit de quoi</h3>
        <span className="sub">{utilisateurs.length} compte(s) · {roles.length} rôle(s)</span>
        <button className="btn ghost sm" style={{ marginLeft: 'auto' }} onClick={charger} disabled={chargement}>↻</button>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : erreur ? (
          <div className="banner banner-error">{erreur}</div>
        ) : (
          <>
            <div className="field" style={{ maxWidth: 420 }}>
              <label htmlFor="md-module">De quoi voulez-vous voir les droits ?</label>
              <select id="md-module" className="input" value={module} onChange={(e) => setModule(e.target.value)}>
                {modules.map((m) => (
                  <option key={m} value={m}>{nomModule(m)}</option>
                ))}
              </select>
              <p className="hint">
                Un rôle marqué « tout » porte le joker : il obtient aussi les droits qui seront
                ajoutés plus tard, sans qu’on ait à le modifier.
              </p>
            </div>

            {actions.length === 0 ? (
              <div className="empty">Ce module n’expose aucune permission.</div>
            ) : rolesConcernes.length === 0 ? (
              <div className="banner banner-warn">
                Aucun rôle ne donne le moindre droit sur « {nomModule(module)} ». Personne ne peut
                s’en servir, quels que soient les comptes créés.
              </div>
            ) : (
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Ce qu’on peut faire</th>
                    {rolesConcernes.map((r) => (
                      <th key={r.id} className="num" title={r.nom}>
                        {r.nom}
                        <div className="sub" style={{ fontWeight: 400 }}>
                          {comptesParRole[r.id] ? `${comptesParRole[r.id]} compte(s)` : 'personne'}
                        </div>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {actions.map((code) => {
                    const donneurs = rolesConcernes.filter((r) => roleCouvre(codesParRole[r.id], code))
                    return (
                      <tr key={code}>
                        <td>
                          <span className="mono">{code}</span>
                          {donneurs.length === 0 && (
                            <div className="sub" style={{ color: 'var(--crit)' }}>aucun rôle ne le donne</div>
                          )}
                        </td>
                        {rolesConcernes.map((r) => {
                          const codes = codesParRole[r.id]
                          const explicite = codes?.has(code)
                          const couvert = roleCouvre(codes, code)
                          return (
                            <td key={r.id} className="num" aria-label={couvert ? 'accordé' : 'non accordé'}>
                              {couvert ? (
                                <span
                                  style={{ color: 'var(--good)' }}
                                  title={explicite ? 'Accordé explicitement.' : 'Accordé par un joker (« tout »).'}
                                >
                                  {explicite ? '✓' : '✓*'}
                                </span>
                              ) : (
                                <span style={{ color: 'var(--ink-faint)' }}>·</span>
                              )}
                            </td>
                          )
                        })}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            )}
          </>
        )}
      </div>
    </section>
  )
}

function ModalInvitation({ open, roles, etablissements, etabActif, onClose, onInvite, envoiCourriel = false }) {
  const [email, setEmail] = useState('')
  const [nom, setNom] = useState('')
  const [roleId, setRoleId] = useState('')
  const [etabId, setEtabId] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  // 'motdepasse' : le compte est actif tout de suite. 'invitation' : le serveur emet un jeton et
  // tente un courriel. Le defaut est le premier, parce que c'est le seul qui aboutisse aujourd'hui.
  const [voie, setVoie] = useState('motdepasse')
  const [motDePasse, setMotDePasse] = useState('')

  useEffect(() => {
    if (open) {
      setEmail(''); setNom(''); setRoleId(''); setEtabId(etabActif || '')
      setErreur(null); setVoie('motdepasse'); setMotDePasse('')
    }
  }, [open, etabActif])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await onInvite({
        email: email.trim(),
        nom: nom.trim(),
        roleId,
        etabId,
        motDePasse: voie === 'motdepasse' ? motDePasse : '',
      })
    } catch (err) {
      setErreur(err.message || "Échec de l'invitation.")
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Donner accès à quelqu’un">
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
          <span className="hint">Le rôle n'est affecté que si un établissement est choisi.</span>
        </div>

        <div className="field">
          <label htmlFor="inv-voie">Comment cette personne se connectera</label>
          <select id="inv-voie" className="select" value={voie} onChange={(e) => setVoie(e.target.value)}>
            <option value="motdepasse">Je pose un mot de passe maintenant</option>
            {/* ⚠ ON NE DÉSACTIVE PAS CE CHOIX, ON DIT CE QU'IL FAIT AUJOURD'HUI.
                L'invitation par courriel est le bon parcours et redeviendra le parcours normal dès
                qu'un expéditeur sera configuré. La désactiver obligerait à s'en souvenir ce jour-là
                — et personne ne repasse sur un `disabled` posé six mois plus tôt. Le libellé, lui,
                suit `/me` et se corrige tout seul. */}
            <option value="invitation">
              {envoiCourriel
                ? 'Le compte reçoit une invitation par courriel'
                : 'Invitation par courriel — aucun expéditeur configuré, rien ne partira'}
            </option>
          </select>
          {!envoiCourriel && (
            <span className="hint">
              Cette instance n’envoie aucun courriel. Le compte sera créé et restera « invité »
              jusqu’à ce qu’un mot de passe lui soit posé — préférez le premier choix.
            </span>
          )}
        </div>

        {voie === 'motdepasse' ? (
          <div className="field">
            <label htmlFor="inv-mdp">Mot de passe initial</label>
            <input
              id="inv-mdp"
              className="input"
              type="password"
              value={motDePasse}
              autoComplete="new-password"
              minLength={8}
              onChange={(e) => setMotDePasse(e.target.value)}
              required
            />
            {/* ⚠ ON NE PROMET PAS UN CHANGEMENT A LA PREMIERE CONNEXION : IL N'EXISTE PAS.
                Cherche dans tout le serveur — aucun indicateur du genre `doitChangerMotDePasse`.
                Ecrire « la personne devra le changer » serait la promesse creuse qu'on retire
                partout ailleurs. On dit donc ce qui est vrai : ce mot de passe est connu de deux
                personnes, et il le restera tant que l'interesse ne le change pas lui-meme. */}
            <span className="hint">
              Le compte est actif immédiatement. <b>Ce mot de passe sera connu de vous deux</b> —
              rien n’oblige aujourd’hui la personne à le changer à sa première connexion :
              demandez-lui de le faire.
            </span>
          </div>
        ) : (
          /* Meme geste que les boutons SMS de la caisse : on garde l'option, et on dit son etat. */
          <div className="banner banner-warn">
            <b>Aucun envoi de courriel n’est branché aujourd’hui.</b> Le compte sera créé et un jeton
            d’invitation émis, mais <b>le message ne partira pas</b> : la personne ne pourra pas se
            connecter. Tant qu’un prestataire d’envoi n’est pas raccordé, posez un mot de passe.
          </div>
        )}
        <div className="r" style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi}>
            {envoi ? 'Création…' : voie === 'motdepasse' ? 'Créer le compte' : 'Créer et inviter'}
          </button>
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
// LES MÉTIERS PROPOSÉS PAR LE SERVEUR, ET CE QUE CHACUN ALLUME.
//
// `App\Fonctionnalite\Config\PresetVerticale` fige un jeu de capacités par verticale. Le preset est
// ADDITIF — vérifié dans `Fonctionnalites::appliquerPreset`, pas supposé : il n'éteint jamais une
// capacité déjà active, et conserve les paramètres déjà saisis. C'est ce qui permet de le proposer
// sans avertissement anxiogène : au pire il en allume une de trop, qu'on éteint d'un clic.
const METIERS = [
  ['piscine', 'Piscine'],
  ['sport', 'Salle de sport / fitness'],
  ['padel', 'Padel'],
  ['patinoire', 'Patinoire'],
  ['musee', 'Musée'],
]

// CE QUE CHANGE UNE ACTIVATION, ÉCRAN PAR ÉCRAN — ET SEULEMENT CE QU'ON PEUT PROUVER.
//
// Trois capacités commandent une entrée du menu de gauche (`components/AppShell.jsx`). Les autres
// ouvrent des surfaces serveur (souscription, OCR, séjours, trésorerie) sans effet visible immédiat
// dans cette application.
//
// ⚠ ON NE SE TAIT PLUS SUR LES AUTRES, ET LE SILENCE ÉTAIT LE DÉFAUT. Ne rien dire évitait de
// promettre un effet non constaté — c'était le bon réflexe — mais laissait l'exploitant basculer
// l'interrupteur, lire « en service », et ne rien voir apparaître. Il en conclut qu'il n'a pas
// compris, ou que c'est cassé. « Activable, sans effet visible ici » est vrai, court, et ne promet
// rien. Relevé par allaccess-b8.
//
// ⚠ UNE SEULE LISTE, ET LA PHRASE SE DÉDUIT. Une seconde liste des capacités « sans écran » ferait
// deux listes à tenir, et la seconde deviendrait fausse le jour où quelqu'un construit l'écran des
// séjours — sans que rien ne relie la phrase au travail qui l'a rendue fausse. C'est la légende de
// tri de Supervision, encore. Présente ici → on dit l'effet ; absente → on dit qu'il n'y en a pas.
// Ajouter la ligne le jour venu rend les deux phrases justes ensemble.
const EFFET_VISIBLE = {
  controle_acces: 'Fait apparaître « Supervision » et « Badges & terminaux » dans le menu.',
  reservation: 'Fait apparaître « Réservation » dans le menu.',
  boutique_en_ligne: 'Fait apparaître « Boutique en ligne » dans le menu.',
}

const CATEGORIES = {
  acces: 'Accès',
  planning: 'Planning',
  finance: 'Encaissement & recouvrement',
  confort: 'Services aux visiteurs',
  securite: 'Sécurité & réglementation',
  vente: 'Vente',
}

// UN ONGLET NOMMÉ « ACTIVABLES » OÙ L'ON NE POUVAIT RIEN ACTIVER.
//
// L'écran lisait le catalogue et l'état, affichait douze lignes toutes marquées « inactive », et
// n'offrait aucune action. Le sous-titre disait « feature flags par établissement » — du jargon de
// développeur, en anglais, sur un écran d'exploitant. Maxime, à la revue : « je ne sais pas ce que
// c'est ». Ce n'était pas un défaut de libellé : l'écran ne disait ni ce que ça fait, ni ce qu'on
// est censé en faire, et le seul geste possible était de refermer l'onglet.
//
// `PATCH /etablissements/{id}/fonctionnalites` et `POST /etablissements/{id}/appliquer-preset`
// existaient depuis le début.
function Capacites({ etabActif, onCapacitesChangees }) {
  const [items, setItems] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(null)
  const [metier, setMetier] = useState('')

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

  async function basculer(c) {
    setBusy(c.code)
    setMsg(null)
    setErreur(null)
    try {
      await api.majFonctionnalite(etabActif, { capaciteCode: c.code, active: !c.active })
      await charger()
      // Le menu de gauche est construit sur `capacitesActives` de `/me` : sans ce rappel, on
      // active « Réservation » et l'entrée n'apparaît qu'au prochain rechargement de la page.
      await onCapacitesChangees?.()
      setMsg(`« ${c.libelle || c.code} » ${c.active ? 'désactivé' : 'activé'}.`)
    } catch (e) {
      setErreur(erreurEcriture(e))
    } finally {
      setBusy(null)
    }
  }

  async function appliquerPreset() {
    if (!metier) return
    setBusy('preset')
    setMsg(null)
    setErreur(null)
    try {
      const r = await api.appliquerPresetCapacites(etabActif, metier)
      await charger()
      await onCapacitesChangees?.()
      const n = (r?.capacitesActivees || []).length
      setMsg(`Jeu « ${METIERS.find(([v]) => v === metier)?.[1] || metier} » appliqué : ${n} capacité(s) active(s).`)
    } catch (e) {
      setErreur(erreurEcriture(e))
    } finally {
      setBusy(null)
    }
  }

  const actives = items.filter((c) => c.active).length
  const parCategorie = []
  for (const c of items) {
    const groupe = parCategorie.find((g) => g.cle === c.categorie)
    if (groupe) groupe.items.push(c)
    else parCategorie.push({ cle: c.categorie, titre: CATEGORIES[c.categorie] || c.categorie || 'Autres', items: [c] })
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>Ce que fait votre établissement</h3>
        <span className="sub">{actives} sur {items.length} en service</span>
        <button className="btn ghost sm" style={{ marginLeft: 'auto' }} onClick={charger} disabled={chargement}>↻</button>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {msg && <div className="banner banner-ok" style={{ margin: '0 0 12px' }}>{msg}</div>}
        {erreur && <div className="banner banner-error" style={{ margin: '0 0 12px' }}>{erreur}</div>}

        <p className="hint" style={{ marginTop: 0 }}>
          Chaque ligne est une partie du logiciel qu’on met en service ou qu’on laisse de côté. Ce
          n’est pas un réglage définitif : on active, on essaie, on désactive. Rien n’est effacé quand
          on désactive — les données saisies restent et reviennent à la réactivation.
        </p>

        {/* On ne demande pas à un exploitant de deviner lesquelles vont ensemble : le serveur
            connaît le jeu de chaque métier, et il est additif. */}
        <div className="row" style={{ gap: 8, alignItems: 'flex-end', margin: '14px 0 18px', flexWrap: 'wrap' }}>
          <div className="field" style={{ margin: 0, minWidth: 220 }}>
            <label htmlFor="cap-metier">Vous exploitez plutôt…</label>
            <select id="cap-metier" className="input" value={metier} onChange={(e) => setMetier(e.target.value)}>
              <option value="">— choisir un métier —</option>
              {METIERS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <button className="btn" onClick={appliquerPreset} disabled={!metier || busy === 'preset'}>
            {busy === 'preset' ? 'Application…' : 'Mettre en service le jeu correspondant'}
          </button>
          <span className="hint" style={{ margin: 0, flex: 1, minWidth: 240 }}>
            Active d’un coup ce qu’un site de ce type utilise. N’éteint jamais rien de ce qui tourne
            déjà.
          </span>
        </div>

        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : items.length === 0 ? (
          <div className="empty">Aucune capacité au catalogue.</div>
        ) : (
          parCategorie.map((g) => (
            <div key={g.cle} style={{ marginBottom: 18 }}>
              <div className="sub" style={{ marginBottom: 6, textTransform: 'uppercase', letterSpacing: '.04em' }}>{g.titre}</div>
              <table className="tbl">
                <tbody>
                  {g.items.map((c) => (
                    <tr key={c.code}>
                      <td style={{ width: '32%' }}>
                        <span className="nm">{c.libelle || c.code}</span>
                        <div className="sub">{c.description || ''}</div>
                        <div className="sub">
                          {EFFET_VISIBLE[c.code]
                            ?? 'Ouvre une surface serveur ; aucun écran dédié dans cette application pour l’instant.'}
                        </div>
                      </td>
                      <td style={{ width: 120 }}>
                        <span className={`badge ${c.active ? 'good' : 'mut'}`}>{c.active ? 'en service' : 'hors service'}</span>
                      </td>
                      <td className="num">
                        <button
                          className="btn ghost sm"
                          onClick={() => basculer(c)}
                          disabled={busy === c.code || !etabActif}
                        >
                          {busy === c.code ? '…' : c.active ? 'Mettre hors service' : 'Mettre en service'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))
        )}
      </div>
    </section>
  )
}
