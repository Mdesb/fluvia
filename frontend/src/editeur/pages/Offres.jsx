import { api } from '../../api/client.js'
import ReferentielEditable from '../../components/ReferentielEditable.jsx'

// Le catalogue commercial de l'éditeur : ses formules et ses options (ED-6).
//
// DEUX RÉFÉRENTIELS, UN SEUL COMPOSANT. `ReferentielEditable` porte déjà le formulaire, la
// confirmation qui dit ce que la suppression casse, et le vide qui explique au lieu de constater.
// En réécrire une variante ici, c'est garantir que la prochaine amélioration ne profitera qu'à
// l'un des deux.
//
// CE QUE CET ÉCRAN MONTRE ET QUE LA VITRINE CACHE. La page publique écarte les formules retirées de
// la vente et celles qui promettent une capacité inexistante : annoncer ce qu'on ne sait pas livrer
// est une faute. Ici elles apparaissent — c'est le seul endroit où on peut les corriger.
//
// PAS DE FILTRAGE DE DROITS CÔTÉ CLIENT (D39). L'accès est décidé par le serveur, sur l'identité du
// tenant éditeur, et il répond 404 à qui n'en est pas. Rejouer la règle ici ne pourrait que la
// rejouer à moitié.

function descripteurFormules() {
  return {
    titre: 'Formules',
    aQuoiCaSert:
      "Ce qu'un client souscrit : un prix mensuel et les modules compris dedans. "
      + 'Les options se facturent en plus.',
    siVide:
      "Vous n'avez aucune formule. Tant qu'il n'y en a pas, personne ne peut souscrire — la page "
      + 'de tarifs reste vide et le tunnel refuse toute composition. Commencez par « Essentiel ».',
    consequenceSuppression:
      'Les abonnements deja souscrits sur cette formule continuent : ils gardent leur prix. '
      + 'En revanche plus personne ne pourra la choisir. Pour arreter les ventes sans toucher aux '
      + 'abonnements en cours, decochez plutot « En vente ».',
    charger: api.editorPlans,
    creer: api.creerEditorPlan,
    modifier: api.majEditorPlan,
    supprimer: api.supprimerEditorPlan,
    champs: [
      {
        nom: 'code',
        libelle: 'Code de la formule',
        type: 'text',
        requis: true,
        exemple: 'essentiel',
        // Le code voyage dans les contrats et les paniers déjà composés : le renommer casse des
        // liens qu'on ne voit pas d'ici.
        aide: "Identifiant stable. Une fois des clients abonnés, ne le changez plus.",
      },
      {
        nom: 'label',
        libelle: 'Nom affiché',
        type: 'text',
        requis: true,
        exemple: 'Essentiel',
        aide: "C'est ce que lira le prospect sur la page de tarifs.",
      },
      {
        nom: 'monthlyPriceCents',
        libelle: 'Prix mensuel, en centimes',
        type: 'nombre',
        requis: true,
        exemple: '4900',
        aide: '4900 pour 49,00 €. En centimes entiers, parce que les prorata se calculent dessus.',
      },
      {
        nom: 'active',
        libelle: 'En vente',
        type: 'bool',
        libelleCase: 'Cette formule peut être souscrite',
        aide:
          "Décocher la retire de la page de tarifs et du tunnel. Les abonnements en cours ne sont "
          + 'pas touchés.',
      },
    ],
    colonnes: [
      { cle: 'label', titre: 'Formule', rendu: (r) => <span className="nm">{r.label || '—'}</span> },
      { cle: 'code', titre: 'Code', rendu: (r) => <span className="mut">{r.code}</span> },
      {
        cle: 'monthlyPriceCents',
        titre: 'Par mois',
        num: true,
        rendu: (r) => prix(r.monthlyPriceCents),
      },
      {
        cle: 'includedCapabilities',
        titre: 'Modules compris',
        rendu: (r) => (r.includedCapabilities?.length ? r.includedCapabilities.length : '—'),
        aide: 'Modules inclus dans le prix de la formule.',
      },
      {
        cle: 'active',
        titre: 'État',
        rendu: (r) =>
          r.active ? <span className="badge good">En vente</span> : <span className="badge mut">Retirée</span>,
      },
    ],
  }
}

function descripteurOptions() {
  return {
    titre: 'Options',
    aQuoiCaSert:
      'Les modules vendus à la carte, en plus de la formule. Une option est un module : ce que vous '
      + 'mettez en vente ici est exactement ce qui sera activé chez le client après paiement.',
    siVide:
      "Vous n'avez aucune option en vente. Vos formules restent souscriptibles, mais un client ne "
      + 'pourra rien y ajouter — chaque besoin supplémentaire deviendra une formule de plus.',
    consequenceSuppression:
      "Les abonnements qui portent deja cette option continuent de la payer et de l'utiliser. "
      + "Seule la mise en vente s'arrete.",
    charger: api.editorOptions,
    creer: api.creerEditorOption,
    modifier: api.majEditorOption,
    supprimer: api.supprimerEditorOption,
    champs: [
      {
        nom: 'capability',
        libelle: 'Module',
        type: 'text',
        requis: true,
        exemple: 'reservation',
        // Le serveur refuse une capacité que le catalogue technique ne connaît pas : on ne peut donc
        // pas vendre un module qui n'existe pas. Le dire ici évite d'attendre l'erreur pour l'apprendre.
        aide:
          "Le code technique du module. S'il n'existe pas dans la plateforme, la mise en vente sera "
          + 'refusée — on ne peut pas vendre ce qu\'on ne sait pas livrer.',
      },
      {
        nom: 'label',
        libelle: 'Nom affiché',
        type: 'text',
        requis: true,
        exemple: 'Réservation',
        aide: "C'est ce que lira le prospect sur la page de tarifs.",
      },
      {
        nom: 'monthlyPriceCents',
        libelle: 'Prix mensuel, en centimes',
        type: 'nombre',
        requis: true,
        exemple: '1500',
        aide: '1500 pour 15,00 €.',
      },
      {
        nom: 'active',
        libelle: 'En vente',
        type: 'bool',
        libelleCase: 'Cette option peut être ajoutée',
        aide: 'Décocher la retire du tunnel sans toucher aux abonnements qui la portent déjà.',
      },
    ],
    colonnes: [
      { cle: 'label', titre: 'Option', rendu: (r) => <span className="nm">{r.label || '—'}</span> },
      { cle: 'capability', titre: 'Module', rendu: (r) => <span className="mut">{r.capability}</span> },
      {
        cle: 'monthlyPriceCents',
        titre: 'Par mois',
        num: true,
        rendu: (r) => prix(r.monthlyPriceCents),
      },
      {
        cle: 'active',
        titre: 'État',
        rendu: (r) =>
          r.active ? <span className="badge good">En vente</span> : <span className="badge mut">Retirée</span>,
      },
    ],
  }
}

const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' })

function prix(cents) {
  return euros.format((cents || 0) / 100)
}

export default function Offres() {
  return (
    <>
      <ReferentielEditable descripteur={descripteurFormules()} peutEcrire />
      <ReferentielEditable descripteur={descripteurOptions()} peutEcrire />
    </>
  )
}
