import { api } from '../api/client.js'
import ReferentielEditable from './ReferentielEditable.jsx'

// Voir et renommer les groupes — le sommet de `Groupe → Région → Établissement → Espace`.
//
// POURQUOI CET ÉCRAN EXISTE (R18).
//
// ⚠ « GROUPE » DÉSIGNE CINQ CHOSES DIFFÉRENTES DANS CE DÉPÔT, et une seule n'avait pas d'écran.
// Les groupes d'options d'un produit, les dossiers de groupe scolaire du musée, le lettrage de
// groupe en comptabilité et les familles du CRM ont tous le leur. Le sommet de la hiérarchie
// multi-entités, non : cinq opérations exposées, aucun appel côté client. Les deux niveaux du
// DESSOUS — région et établissement — avaient chacun leur section, juste en dessous de celle-ci.
//
// POURQUOI NI CRÉATION NI SUPPRESSION.
//
// ⚠ CRÉER SERAIT UN SECOND CHEMIN VERS LE MÊME OBJET. `StructureOnboarding` (le bouton « Ouvrir
// une structure ») et `ProvisioningService` (l'abonnement) créent déjà le groupe AVEC sa région et
// son établissement, d'un seul geste — parce que le modèle exige la chaîne complète. Un formulaire
// « Nouveau groupe » ici produirait un groupe orphelin, et deux endroits pour le même geste.
//
// Supprimer, c'est le raisonnement de `RegionsSection` d'un cran plus haut : un groupe porte des
// régions, qui portent des établissements, qui portent tout le reste. Renommer suffit à corriger
// une erreur de saisie.
//
// ⚠ LE CLOISONNEMENT A ÉTÉ VÉRIFIÉ AVANT D'EXPOSER LA LISTE. `GetCollection` n'exige que
// `IS_AUTHENTICATED_FULLY` — c'est `Organisation/Doctrine/GroupScopeExtension` qui restreint
// `Groupe::class` à l'utilisateur. Sans elle, cet écran aurait montré aux uns les groupes des
// autres, et la permission n'y aurait rien vu.
export default function GroupesSection({ peutEcrire, onChange }) {
  const descripteur = {
    titre: 'Groupes',
    aQuoiCaSert:
      'L’entité qui coiffe vos régions, et donc tous vos sites. Elle est créée en même temps que '
      + 'votre première structure — on la renomme ici, on n’en ajoute pas.',
    siVide:
      'Aucun groupe visible. Il s’en crée un automatiquement à l’ouverture d’une structure : si '
      + 'cette liste est vide, c’est qu’aucune structure ne vous est rattachée.',
    consequenceSuppression: '',
    charger: api.groupes,
    creer: null,
    modifier: peutEcrire ? api.majGroupe : null,
    supprimer: null,
    champs: [
      {
        nom: 'nom',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Groupe Aquavia',
        aide: 'Le nom sous lequel l’ensemble de vos sites apparaît dans les tableaux de bord.',
      },
    ],
    colonnes: [
      { cle: 'nom', titre: 'Groupe', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
    ],
  }

  return <ReferentielEditable descripteur={descripteur} peutEcrire={peutEcrire} onChange={onChange} />
}
