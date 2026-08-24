/*
  Remplissage de la section « Tarifs » depuis le catalogue réel (ED-5).

  POURQUOI LIRE PLUTÔT QU'ÉCRIRE LES PRIX DANS LA PAGE. Un tarif de page de vente qui diverge du
  tarif facturé est un écart que le prospect relève avant nous, et qu'on ne découvre qu'au premier
  prélèvement contesté. La page lit donc la même source que la facturation.

  CE QUI SE PASSE QUAND LA LECTURE ÉCHOUE. La section bascule sur un message qui dit franchement que
  les tarifs ne peuvent pas être affichés, avec un moyen de contact. Elle n'affiche JAMAIS de valeur
  de repli : un prix inventé sur une page publique est un engagement qu'on ne tient pas.

  ACCESSIBILITÉ. Les deux zones d'état portent `role="status"` : leur contenu est annoncé quand il
  change, sans voler le focus. C'est ce qui fait qu'un lecteur d'écran apprend que les tarifs sont
  arrivés — sinon la page reste, pour lui, en cours de chargement pour toujours.
*/

// API Platform sert toutes ses ressources sous `/api` (`config/routes/api_platform.yaml`). Le préfixe
// est ici plutôt que recopié dans chaque appel : le jour où il change, il change à un seul endroit.
const BASE_API = `${window.API_BASE || ''}/api`

const euros = new Intl.NumberFormat('fr-FR', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
})

/** Les prix sont transportés en centimes entiers : un flottant y perdrait des centimes réels. */
function prix(cents) {
  return euros.format(cents / 100)
}

async function lire(chemin) {
  const reponse = await fetch(`${BASE_API}${chemin}`, {
    headers: { Accept: 'application/ld+json' },
  })

  if (!reponse.ok) {
    throw new Error(`${chemin} : ${reponse.status}`)
  }

  const corps = await reponse.json()

  // API Platform sert `member` en JSON-LD ; les versions antérieures servaient `hydra:member`. On
  // accepte les deux plutôt que de dépendre d'une montée de version du back.
  return corps.member || corps['hydra:member'] || []
}

function carteFormule(formule) {
  const carte = document.createElement('div')
  carte.className = 'carte carte-formule'

  const titre = document.createElement('h3')
  titre.textContent = formule.label
  carte.append(titre)

  const montant = document.createElement('p')
  montant.className = 'prix'
  montant.textContent = prix(formule.monthlyPriceCents)

  const unite = document.createElement('span')
  unite.className = 'prix-unite'
  unite.textContent = ' / mois'
  montant.append(unite)
  carte.append(montant)

  const comprises = formule.includedCapabilities || []
  if (comprises.length > 0) {
    const liste = document.createElement('ul')
    for (const capacite of comprises) {
      const ligne = document.createElement('li')
      // Le libellé, jamais le code : « controle_acces » n'a rien à faire sur une page de vente.
      // Le code voyage dans la charge utile parce que le tunnel en a besoin, pas l'œil du visiteur.
      ligne.textContent = capacite.label
      liste.append(ligne)
    }
    carte.append(liste)
  }

  return carte
}

function ligneOption(option) {
  const ligne = document.createElement('li')

  const libelle = document.createElement('span')
  libelle.textContent = option.label

  const montant = document.createElement('span')
  montant.className = 'option-prix'
  montant.textContent = `${prix(option.monthlyPriceCents)} / mois`

  ligne.append(libelle, montant)
  return ligne
}

function echec(zoneEtat) {
  zoneEtat.hidden = false
  zoneEtat.textContent =
    'Les tarifs ne sont pas affichables pour le moment. Écrivez-nous et nous vous les envoyons.'
}

async function afficherFormules() {
  const etat = document.getElementById('tarifs-etat')
  const grille = document.getElementById('tarifs-formules')

  try {
    const formules = await lire('/editor/plans')

    if (formules.length === 0) {
      // Aucune formule en vente n'est un état légitime — un catalogue en cours de préparation — et
      // il se dit tel quel plutôt que par une grille vide que personne ne sait interpréter.
      etat.textContent = 'Nos formules sont en cours de mise à jour.'
      return
    }

    grille.replaceChildren(...formules.map(carteFormule))
    grille.hidden = false
    etat.hidden = true
  } catch {
    echec(etat)
  }
}

async function afficherOptions() {
  const etat = document.getElementById('tarifs-options-etat')
  const liste = document.getElementById('tarifs-options')

  try {
    const options = await lire('/editor/plan-options')

    if (options.length === 0) {
      // Pas d'options en vente : on n'affiche ni titre vide ni message d'erreur. L'absence d'option
      // n'est pas une panne.
      return
    }

    liste.replaceChildren(...options.map(ligneOption))
    liste.hidden = false
  } catch {
    echec(etat)
  }
}

afficherFormules()
afficherOptions()
