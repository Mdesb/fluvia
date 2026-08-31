import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import ReferentielEditable from './ReferentielEditable.jsx'

// Créer et modifier un établissement.
//
// POURQUOI LA SUPPRESSION N'EST PAS PROPOSÉE, alors que l'API l'expose.
//
// Un établissement n'est pas une ligne de référentiel : c'est le périmètre auquel tout le reste est
// rattaché — ventes, clients, caisses, droits d'accès, comptabilité. Le supprimer ne « retire pas une
// entrée », ça coupe le rattachement de tout ce qui a été fait dessus. Le serveur refusera
// probablement, contraintes obligent, mais un bouton qui échoue une fois sur deux enseigne surtout
// qu'on peut réessayer.
//
// Ce qu'un exploitant veut réellement dans ce cas est **désactiver** : l'établissement cesse d'être
// proposé, et son historique reste consultable. C'est ce que fait la case « en service », et c'est
// réversible. Une fermeture définitive, si elle doit exister un jour, mérite un geste dédié avec ses
// propres avertissements — pas la même croix que pour un taux de TVA.
//
// LA RÉGION EST OBLIGATOIRE, et ce commentaire disait le contraire.
//
// Il affirmait que « le modèle accepte » un établissement sans région. C'est faux : l'entité porte
// `nullable: false` et `Assert\NotNull`. L'option « Aucune », posée sur cette croyance, produisait
// un message technique anglais affiché tel quel à l'exploitant — vu dans le navigateur le 28/08 :
//
//     The type of the "region" attribute must be "array" (nested document) or "string" (IRI),
//     "NULL" given.
//
// Le champ est donc requis, et sans valeur vide. Si aucune région n'existe encore, l'écran le dit
// et renvoie vers la section qui permet d'en créer une — elle non plus n'existait pas avant ce jour.

export default function EtablissementsSection({ peutEcrire, onChange }) {
  const [regions, setRegions] = useState([])
  const [chargement, setChargement] = useState(true)

  useEffect(() => {
    api
      .regions()
      .then((c) => setRegions(membres(c)))
      .catch(() => setRegions([]))
      .finally(() => setChargement(false))
  }, [])

  if (chargement) {
    return (
      <section className="card">
        <div className="card-b center" style={{ minHeight: 80 }}><div className="spinner" /></div>
      </section>
    )
  }

  const descripteur = {
    titre: 'Établissements',
    aQuoiCaSert:
      'Vos sites : une piscine, un musée, une patinoire. Tout ce que vous faites — ventes, clients, '
      + 'caisses, plannings — est rattaché à l’établissement sur lequel vous travaillez.',
    siVide:
      "Aucun établissement n'est accessible depuis ce compte. Sans établissement, rien ne peut être "
      + 'vendu ni paramétré.',
    consequenceSuppression: '',
    charger: api.etablissements,
    creer: peutEcrire ? api.creerEtablissement : null,
    modifier: peutEcrire ? api.majEtablissement : null,
    // Volontairement absent : voir l'en-tête de ce fichier.
    supprimer: null,
    champs: [
      {
        nom: 'nom',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Piscine des Trois Fontaines',
        aide: 'Le nom que verront vos équipes en changeant de site.',
      },
      {
        nom: 'region',
        libelle: 'Région',
        type: 'choix',
        requis: true,
        options: regions.map((r) => ({ valeur: `/api/regions/${r.id}`, libelle: r.nom })),
        aide: regions.length === 0
          ? 'Aucune région n’existe encore. Créez-en une juste au-dessus : un établissement ne peut '
            + 'pas exister sans elle.'
          : 'Obligatoire. C’est par la région que les tableaux de bord regroupent plusieurs sites.',
        versValeur: (l) => (l.region?.id ? `/api/regions/${l.region.id}` : ''),
        versCorps: (v) => v,
      },
      {
        nom: 'actif',
        libelle: 'En service',
        type: 'bool',
        libelleCase: 'Cet établissement est en service',
        aide:
          'Décocher le retire des sites proposés sans rien supprimer : ses ventes et son historique '
          + 'restent consultables, et vous pouvez le remettre en service à tout moment.',
      },
    ],
    colonnes: [
      { cle: 'nom', titre: 'Établissement', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
      { cle: 'region', titre: 'Région', rendu: (r) => r.region?.nom || '—' },
      {
        cle: 'actif',
        titre: 'État',
        rendu: (r) => (
          <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'en service' : 'hors service'}</span>
        ),
      },
    ],
  }

  return <ReferentielEditable descripteur={descripteur} peutEcrire={peutEcrire} onChange={onChange} />
}
