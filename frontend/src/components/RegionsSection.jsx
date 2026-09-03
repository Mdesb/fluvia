import { api } from '../api/client.js'
import ReferentielEditable from './ReferentielEditable.jsx'

// Créer et renommer une région.
//
// POURQUOI CET ÉCRAN EXISTE DEPUIS LE 28/08.
//
// Un établissement EXIGE une région — `nullable: false`, `Assert\NotNull` sur l'entité. Or aucun
// écran ne permettait d'en créer une : le client d'API ne portait qu'une lecture. Sur une
// installation neuve, sans région en base, on ne pouvait donc **pas créer son premier site**. Le
// jeu de démonstration en contenait une, ce qui masquait l'impasse.
//
// C'est le premier geste de l'accueil d'un nouveau client, et il n'avait pas d'écran.
//
// POURQUOI LA SUPPRESSION N'EST PAS PROPOSÉE.
//
// Une région porte des établissements, qui portent tout le reste. La retirer n'enlève pas une ligne
// de référentiel : elle coupe le rattachement de sites entiers. Renommer suffit à corriger une
// erreur de saisie ; effacer demande un geste dédié qui n'existe pas encore.

export default function RegionsSection({ peutEcrire, onChange }) {
  const descripteur = {
    titre: 'Régions',
    aQuoiCaSert:
      'L’échelon entre le groupe et vos sites. Chaque établissement en exige une — c’est par elle '
      + 'que les tableaux de bord regroupent plusieurs sites.',
    siVide:
      'Aucune région. Créez-en une avant votre premier établissement : un site ne peut pas exister '
      + 'sans elle.',
    consequenceSuppression: '',
    charger: api.regions,
    creer: peutEcrire ? api.creerRegion : null,
    modifier: peutEcrire ? api.majRegion : null,
    supprimer: null,
    champs: [
      {
        nom: 'nom',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Région Est',
        aide: 'Le nom que verront vos équipes dans les tableaux de bord de pilotage.',
      },
    ],
    colonnes: [
      { cle: 'nom', titre: 'Région', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
    ],
  }

  return <ReferentielEditable descripteur={descripteur} peutEcrire={peutEcrire} onChange={onChange} />
}
