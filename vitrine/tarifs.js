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

// ⚠ DEUX FORMATEURS, PARCE QU'UN PRIX ROND ET UN PRIX A CENTIMES NE S'ECRIVENT PAS PAREIL.
//
// Il n'y en avait qu'un, avec `minimumFractionDigits: 0`. C'etait juste tant que tous les prix
// etaient ronds : « 19 € » plutot que « 19,00 € ». La premiere formule non ronde a montre l'autre
// moitie de la regle — 39,90 s'affichait « 39,9 € », et le total « 58,9 € ».
//
// Un prix ampute de sa decimale ne fait pas negliger : il se LIT comme un autre prix. On garde donc
// l'intention d'origine et on la complete, plutot que d'imposer « 19,00 € » partout.
const eurosRonds = new Intl.NumberFormat('fr-FR', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
})

const eurosCentimes = new Intl.NumberFormat('fr-FR', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

/** Les prix sont transportés en centimes entiers : un flottant y perdrait des centimes réels. */
function prix(cents) {
  return cents % 100 === 0 ? eurosRonds.format(cents / 100) : eurosCentimes.format(cents / 100)
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

/**
 * La table de rangement, telle que le serveur l'a posee dans la page.
 *
 * ⚠ ON NE LA RECOPIE PAS EN JAVASCRIPT. Deux tables — une en PHP, une ici — divergeraient au
 * premier module ajoute, et la page rangerait les options autrement que le serveur ne le croit.
 * Absente (page ancienne, balise supprimee), on rend `null` et l'affichage retombe sur une liste
 * simple : mieux vaut une liste a plat que pas de tarifs du tout.
 */
function tableDesFamilles() {
  const balise = document.getElementById('familles-modules')
  if (balise === null) return null

  try {
    const table = JSON.parse(balise.textContent)
    return Array.isArray(table.familles) && table.familles.length > 0 ? table : null
  } catch {
    return null
  }
}

function groupeDeFamille(famille, options) {
  const groupe = document.createElement('div')
  groupe.className = 'famille-tarif'

  const tete = document.createElement('div')
  tete.className = 'famille-tarif-tete'

  const trait = document.createElement('span')
  trait.className = 'famille-trait'
  trait.style.background = famille.teinte

  const titre = document.createElement('h3')
  titre.textContent = famille.titre

  tete.append(trait, titre)

  const liste = document.createElement('ul')
  liste.className = 'options'
  liste.setAttribute('role', 'list')
  liste.append(...options.map(ligneOption))

  groupe.append(tete, liste)
  return groupe
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

    const table = tableDesFamilles()

    if (table === null) {
      // Sans table, on affiche a plat plutot que rien : voir `tableDesFamilles`.
      const simple = document.createElement('ul')
      simple.className = 'options'
      simple.setAttribute('role', 'list')
      simple.append(...options.map(ligneOption))
      liste.replaceChildren(simple)
      liste.hidden = false
      return
    }

    // ⚠ CHAQUE OPTION TOMBE QUELQUE PART. Le `refuge` reprend celles que la table ne nomme pas —
    // une option vendable absente de la page des tarifs serait facturee sans etre affichee, et
    // personne ne le verrait puisqu'elle ne manquerait nulle part.
    const parFamille = new Map(table.familles.map((f) => [f.cle, []]))

    for (const option of options) {
      const cle = table.rangement[option.capability] ?? table.refuge
      const groupe = parFamille.get(cle) ?? parFamille.get(table.refuge)
      groupe.push(option)
    }

    liste.replaceChildren(
      ...table.familles
        .filter((famille) => parFamille.get(famille.cle).length > 0)
        .map((famille) => groupeDeFamille(famille, parFamille.get(famille.cle))),
    )
    liste.hidden = false
  } catch {
    echec(etat)
  }
}

afficherFormules()
afficherOptions()
