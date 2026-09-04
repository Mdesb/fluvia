/*
  Le tunnel d'inscription en essai gratuit, sur le site vitrine (ED-5).

  ⚠ TOUT CE FICHIER EST DANS UNE FONCTION, ET CE N'EST PAS UN STYLE. `tarifs.js` et celui-ci sont
  deux scripts CLASSIQUES : ils partagent la portée globale de la page. La première version déclarait
  `const BASE_API` comme `tarifs.js` — et le navigateur refusait le fichier ENTIER sur un
  « Identifier 'BASE_API' has already been declared ». Aucune trace ailleurs que dans la console : la
  page s'affichait parfaitement, le titre était bon, le déploiement se vérifiait, et le formulaire
  restait sur « Chargement des offres… » pour toujours.

  TROIS ÉTATS, ET UN SEUL VISIBLE À LA FOIS. Composer → lire le total calculé PAR LE SERVEUR →
  demander l'essai. L'étape intermédiaire n'est pas une politesse : elle existe pour que le visiteur
  voie le prix que nous facturerons, et non celui que cette page aurait additionné. Une page qui
  affiche son propre total et un serveur qui en facture un autre est la manière la plus sûre de
  perdre la confiance d'un client au premier prélèvement.

  RIEN N'EST VENDU AVANT LE CLIC DANS LE COURRIEL. La composition crée un brouillon ; la demande
  envoie un message ; c'est le lien de ce message qui ouvre la plateforme. C'est la seule garde de ce
  parcours : sans elle, ce formulaire public créerait autant d'établissements réels — groupe, région,
  compte administrateur — qu'on lui envoie de requêtes.

  POURQUOI CE FICHIER RELIT LE CATALOGUE QUE `tarifs.js` VIENT DE LIRE. Deux requêtes GET sur une
  page de vente ne coûtent rien, et le navigateur les sert de son cache. L'alternative — ranger le
  catalogue dans une variable globale que le second script attendrait — coupleraient deux fichiers
  par un ordre d'exécution que rien ne garantit, pour économiser deux requêtes.

  AUCUN PRIX N'EST ÉCRIT ICI. Comme dans `tarifs.js` : si la lecture échoue, le formulaire le dit et
  ne propose rien. Un formulaire qui affiche « Essentiel — 49 € » de mémoire vend un tarif que
  personne n'a plus.
*/

;(function () {
'use strict'
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

function prix(cents) {
  return cents % 100 === 0 ? eurosRonds.format(cents / 100) : eurosCentimes.format(cents / 100)
}

async function lire(chemin) {
  const reponse = await fetch(`${BASE_API}${chemin}`, { headers: { Accept: 'application/ld+json' } })
  if (!reponse.ok) {
    throw new Error(`${chemin} : ${reponse.status}`)
  }
  const corps = await reponse.json()

  return corps.member || corps['hydra:member'] || []
}

/**
 * Le message à montrer au visiteur pour un refus.
 *
 * ⚠ **ON NE RELAIE LE SERVEUR QUE LÀ OÙ IL ÉCRIT POUR UN HUMAIN**, c'est-à-dire sur les 422 que nos
 * processeurs rédigent. Relayer tout ce qui arrive a produit, le 04/09 sur cette page même, un
 * « Not Found » en anglais affiché en gros à un prospect francophone : c'était le `detail` d'une 404
 * d'API Platform, écrit pour un intégrateur. Un message technique sur une page de vente ne dit rien
 * au visiteur et dit tout de nous.
 */
function messageDeRefus(statut, corps) {
  if (422 === statut) {
    const ecrit = corps?.['hydra:description'] || corps?.description || corps?.detail
    if (ecrit) {
      return ecrit
    }
  }

  if (429 === statut) {
    return 'Trop de demandes depuis cet appareil. Patientez quelques minutes et reprenez.'
  }

  return 'La demande n’a pas abouti. Réessayez dans un instant, ou écrivez-nous.'
}

/**
 * Envoie et rend le corps, ou lève avec le message du serveur.
 *
 * LE MESSAGE VIENT DU SERVEUR, JAMAIS D'ICI. Le back sait pourquoi il refuse — une composition qui
 * n'est plus vendable, un lien déjà utilisé — et il écrit ses refus pour un visiteur. Réécrire ici
 * un message générique effacerait la seule information utile.
 */
async function envoyer(chemin, charge) {
  const reponse = await fetch(`${BASE_API}${chemin}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: JSON.stringify(charge),
  })

  const corps = await reponse.json().catch(() => null)

  if (!reponse.ok) {
    throw new Error(messageDeRefus(reponse.status, corps))
  }

  return corps
}

// ── Construction du formulaire ────────────────────────────────────────────────────────────────

function choixFormule(formule, premiere) {
  const etiquette = document.createElement('label')
  etiquette.className = 'choix'

  const bouton = document.createElement('input')
  bouton.type = 'radio'
  bouton.name = 'formule'
  bouton.value = formule.code
  bouton.checked = premiere
  // Les capacités comprises servent à ne pas proposer en supplément ce qui est déjà payé.
  bouton.dataset.comprises = (formule.includedCapabilities || []).map((c) => c.capability).join(',')

  const texte = document.createElement('span')
  texte.className = 'choix-texte'

  const nom = document.createElement('strong')
  nom.textContent = formule.label

  const montant = document.createElement('span')
  montant.className = 'choix-prix'
  montant.textContent = `${prix(formule.monthlyPriceCents)} / mois`

  texte.append(nom, montant)
  etiquette.append(bouton, texte)

  return etiquette
}

function choixOption(option) {
  const etiquette = document.createElement('label')
  etiquette.className = 'choix'
  etiquette.dataset.capacite = option.capability

  const case_ = document.createElement('input')
  case_.type = 'checkbox'
  case_.name = 'option'
  case_.value = option.capability

  const texte = document.createElement('span')
  texte.className = 'choix-texte'

  const nom = document.createElement('span')
  nom.textContent = option.label

  const montant = document.createElement('span')
  montant.className = 'choix-prix'
  montant.textContent = `+ ${prix(option.monthlyPriceCents)} / mois`

  texte.append(nom, montant)
  etiquette.append(case_, texte)

  return etiquette
}

/**
 * Masque les options que la formule choisie comprend déjà.
 *
 * Le serveur ne les facture pas deux fois (`billableExtras`) ; les proposer quand même ferait croire
 * au visiteur qu'il paie un supplément pour ce qu'il a déjà, et le total renvoyé le démentirait.
 */
function masquerCeQuiEstCompris(formulaire) {
  const choisie = formulaire.querySelector('input[name="formule"]:checked')
  const comprises = (choisie?.dataset.comprises || '').split(',').filter(Boolean)

  for (const etiquette of formulaire.querySelectorAll('[data-capacite]')) {
    const comprise = comprises.includes(etiquette.dataset.capacite)
    etiquette.hidden = comprise
    if (comprise) {
      etiquette.querySelector('input').checked = false
    }
  }
}

// ── Parcours ──────────────────────────────────────────────────────────────────────────────────

async function monter() {
  const etat = document.getElementById('tunnel-etat')
  const formulaire = document.getElementById('tunnel-formulaire')

  // ⚠ CE GARDE NE CITE QUE DES IDENTIFIANTS QUI EXISTENT, ET C'EST UNE CORRECTION.
  //
  // La première version cherchait aussi un `#tunnel` qui n'a jamais été dans la page : `monter()`
  // sortait donc AVANT tout, en silence, et laissait « Chargement des offres… » à l'écran pour
  // toujours. Rien n'échouait — ni la console, ni le déploiement, ni le témoin de `deploy.sh` qui
  // relit le titre. Un visiteur voyait une page normale avec un formulaire qui n'arrivait jamais.
  //
  // Il reste ici parce que `confirmation.html` charge... non : elle ne charge pas ce fichier. Ce
  // garde protège d'une seule chose — que ce script soit un jour inclus dans une page sans tunnel —
  // et il ne doit citer QUE ce dont ce fichier a réellement besoin.
  if (!etat || !formulaire) {
    return
  }

  let formules = []
  let options = []

  try {
    ;[formules, options] = await Promise.all([lire('/editor/plans'), lire('/editor/plan-options')])
  } catch {
    etat.textContent =
      'Nous ne pouvons pas afficher les offres pour le moment. Écrivez-nous et nous composons la vôtre avec vous.'
    return
  }

  if (formules.length === 0) {
    // Le même état légitime que la section Tarifs : un catalogue en préparation. On ne montre pas un
    // formulaire qui ne peut rien envoyer — `openCart` refuse un panier sans formule.
    etat.textContent = 'Nos formules sont en cours de mise à jour. Revenez très bientôt.'
    return
  }

  const listeFormules = document.getElementById('tunnel-formules')
  listeFormules.replaceChildren(...formules.map((f, i) => choixFormule(f, 0 === i)))

  const listeOptions = document.getElementById('tunnel-options')
  const blocOptions = document.getElementById('tunnel-bloc-options')
  if (options.length > 0) {
    listeOptions.replaceChildren(...options.map(choixOption))
  } else {
    blocOptions.hidden = true
  }

  masquerCeQuiEstCompris(formulaire)
  formulaire.addEventListener('change', (e) => {
    if ('formule' === e.target.name) {
      masquerCeQuiEstCompris(formulaire)
      reprendreLaComposition()
    }
    if ('option' === e.target.name) {
      reprendreLaComposition()
    }
  })

  etat.hidden = true
  formulaire.hidden = false
}

let panier = null

/**
 * Toute modification de la composition invalide le total déjà affiché.
 *
 * Sans ça, on garderait à l'écran un total calculé pour une autre composition — et le bouton
 * « Recevoir mon accès » enverrait le panier d'avant, celui dont l'identifiant est en mémoire.
 * Le visiteur croirait souscrire ce qu'il vient de cocher.
 */
function reprendreLaComposition() {
  panier = null
  const recap = document.getElementById('tunnel-total')
  const envoi = document.getElementById('tunnel-envoyer')
  recap.hidden = true
  envoi.hidden = true
}

function composition(formulaire) {
  const formule = formulaire.querySelector('input[name="formule"]:checked')
  const options = [...formulaire.querySelectorAll('input[name="option"]:checked')].map((c) => c.value)
  const comprises = (formule?.dataset.comprises || '').split(',').filter(Boolean)

  return {
    companyName: formulaire.elements.companyName.value.trim(),
    email: formulaire.elements.email.value.trim(),
    planCode: formule?.value || '',
    // Le serveur attend les capacités VOULUES, formule comprise ; il en déduit seul les suppléments
    // facturables. Lui envoyer les seules options cochées lui ferait recalculer autre chose.
    capabilities: [...new Set([...comprises, ...options])],
  }
}

/**
 * Fait monter le montant jusqu'a sa valeur, plutot que de le poser d'un coup.
 *
 * ⚠ ON ANIME L'ARRIVEE, PAS LE CALCUL. Le tunnel promet en toutes lettres que « c'est notre serveur
 * qui le calcule, pas cette page ». Cette fonction interpole entre 0 et un montant DEJA RENDU par le
 * serveur : elle n'additionne rien. Un compteur qui totaliserait les cases cochees cote navigateur
 * romprait la promesse, et le premier ecart avec le montant facture serait releve par le prospect.
 *
 * ⚠ QUI DEMANDE MOINS DE MOUVEMENT VOIT LE MONTANT TOUT DE SUITE. Un prix n'est pas une decoration :
 * l'animation ne doit jamais faire attendre quelqu'un qui veut le lire.
 */
function afficherLeTotal(recap, centimes) {
  const phrase = (montant) =>
    `${prix(montant)} par mois à l’issue de vos 14 jours d’essai. Rien n’est prélevé aujourd’hui.`

  const sobre = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

  if (sobre || centimes <= 0) {
    recap.textContent = phrase(centimes)
    return
  }

  const duree = 520
  const depart = performance.now()

  const pas = (maintenant) => {
    const avancee = Math.min((maintenant - depart) / duree, 1)
    // Une courbe qui ralentit a l'arrivee : le dernier chiffre se lit, il ne defile pas.
    const adouci = 1 - Math.pow(1 - avancee, 3)

    recap.textContent = phrase(Math.round(centimes * adouci))

    if (avancee < 1) {
      requestAnimationFrame(pas)
    } else {
      // ⚠ ON REPOSE LA VALEUR EXACTE. L'arrondi de la derniere image pourrait tomber a un centime
      // pres du montant reel — sur un prix, ce n'est pas un detail d'affichage.
      recap.textContent = phrase(centimes)
    }
  }

  requestAnimationFrame(pas)
}

async function calculerLeTotal(formulaire) {
  const message = document.getElementById('tunnel-message')
  const recap = document.getElementById('tunnel-total')
  const envoi = document.getElementById('tunnel-envoyer')
  const compose = composition(formulaire)

  message.textContent = 'Calcul de votre total…'

  try {
    panier = await envoyer('/editor/carts', compose)
  } catch (erreur) {
    message.textContent = erreur.message
    return
  }

  message.textContent = ''
  recap.hidden = false
  envoi.hidden = false
  afficherLeTotal(recap, panier.monthlyPriceCents)
}

async function demanderLessai(formulaire) {
  const message = document.getElementById('tunnel-message')

  if (!panier) {
    // Ne peut normalement pas arriver : le bouton n'apparaît qu'avec un panier. Mais un bouton qui
    // enverrait `cartId: undefined` produirait un refus incompréhensible plutôt que rien.
    message.textContent = 'Recomposez votre offre : votre panier n’est plus valable.'
    return
  }

  message.textContent = 'Envoi de votre lien…'

  try {
    const reponse = await envoyer('/editor/trial-requests', {
      cartId: panier.id,
      // L'adresse est redonnée volontairement : elle prouve au serveur que c'est bien la personne
      // qui a composé ce panier qui demande l'essai (voir `RequestTrialProcessor`).
      email: formulaire.elements.email.value.trim(),
    })

    formulaire.hidden = true
    const fini = document.getElementById('tunnel-envoye')
    fini.textContent = `Un lien de confirmation part vers ${reponse.maskedEmail}. Il est valable ${reponse.confirmationHours} heures. Ouvrez-le : votre plateforme s’ouvre à ce moment-là, pas avant.`
    fini.hidden = false
    message.textContent = ''
  } catch (erreur) {
    message.textContent = erreur.message
  }
}

document.addEventListener('submit', (e) => {
  if ('tunnel-formulaire' !== e.target.id) {
    return
  }

  e.preventDefault()

  // Un seul formulaire, deux boutons — et c'est LE BOUTON PRESSÉ qui décide, pas l'état de la page.
  // Choisir d'après « un panier existe-t-il ? » se trompait dans un cas réel : le visiteur qui a vu
  // son total, puis qui reclique sur « Voir mon total », déclenchait la demande d'essai. Il aurait
  // souscrit en croyant recalculer.
  if ('tunnel-envoyer' === e.submitter?.id) {
    demanderLessai(e.target)
  } else {
    calculerLeTotal(e.target)
  }
})

monter()
})()
