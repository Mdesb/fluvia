/*
  Confirme l'adresse et ouvre l'essai (ED-5, dernière étape).

  LE JETON NE SORT PAS DE CETTE PAGE. Il arrive dans l'adresse, il part dans le corps d'un `POST`, et
  il est retiré de la barre d'adresse aussitôt. Un jeton qui reste dans l'URL finit dans l'historique
  du navigateur, dans le `Referer` envoyé au premier lien cliqué, et dans la capture d'écran que le
  client nous enverra le jour où quelque chose ira mal.

  ELLE NE DIT JAMAIS « c'est ouvert » SANS L'AVOIR LU DE LA RÉPONSE. Le nom de la structure et la
  date de fin viennent du serveur : les afficher depuis l'adresse ou depuis rien du tout ferait une
  page qui affirme une ouverture qu'elle n'a pas constatée.
*/

const BASE_API = `${window.API_BASE || ''}/api`

const jour = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' })

function jeton() {
  return new URLSearchParams(window.location.search).get('jeton') || ''
}

/** Retire le jeton de la barre d'adresse sans recharger ni ajouter d'entrée à l'historique. */
function effacerLeJetonDeLadresse() {
  window.history.replaceState({}, '', window.location.pathname)
}

/**
 * Le message à montrer au visiteur pour un refus.
 *
 * ⚠ **ON NE RELAIE LE SERVEUR QUE SUR LES 422**, celles que nos processeurs rédigent : ils
 * distinguent « jeton inconnu » de « lien expiré » et écrivent les deux pour un visiteur, avec le
 * geste suivant. Les remplacer par un message unique effacerait la seule chose utile.
 *
 * Tout le reste est réécrit ici, et c'est une correction du 04/09 : cette page a affiché
 * « Not Found » en gros à un prospect francophone — le `detail` d'une 404 d'API Platform, écrit pour
 * un intégrateur. Un message technique sur une page de vente ne dit rien au visiteur, et tout de nous.
 */
function messageDeRefus(statut, corps) {
  if (422 === statut) {
    const ecrit = corps?.['hydra:description'] || corps?.description || corps?.detail
    if (ecrit) {
      return ecrit
    }
  }

  if (429 === statut) {
    return 'Trop de tentatives depuis cet appareil. Patientez quelques minutes et rouvrez votre lien.'
  }

  return 'Nous n’avons pas pu ouvrir votre essai pour le moment. Votre lien reste valable : réessayez dans un instant.'
}

async function confirmer() {
  const etat = document.getElementById('confirmation-etat')
  const succes = document.getElementById('confirmation-succes')
  const titre = document.getElementById('confirmation-titre')
  const suite = document.getElementById('confirmation-suite')

  const valeur = jeton()

  if ('' === valeur) {
    etat.textContent =
      'Ce lien est incomplet. Ouvrez-le depuis le message que nous vous avons envoyé, sans le recopier à la main.'
    return
  }

  effacerLeJetonDeLadresse()

  let reponse = null

  try {
    const requete = await fetch(`${BASE_API}/editor/trial-confirmations`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
      body: JSON.stringify({ token: valeur }),
    })

    const corps = await requete.json().catch(() => null)

    if (!requete.ok) {
      etat.textContent = messageDeRefus(requete.status, corps)
      return
    }

    reponse = corps
  } catch {
    etat.textContent =
      'Nous n’avons pas pu joindre nos serveurs. Réessayez dans un instant — votre lien reste valable.'
    return
  }

  const fin = reponse.trialEndsAt ? new Date(reponse.trialEndsAt) : null

  titre.textContent = `${reponse.companyName} : votre plateforme est ouverte.`
  suite.textContent =
    fin && !Number.isNaN(fin.valueOf())
      ? `Votre essai gratuit court jusqu’au ${jour.format(fin)}. Un second message vous donne vos accès d’administrateur ; sans lui, personne ne peut encore se connecter.`
      : 'Un second message vous donne vos accès d’administrateur ; sans lui, personne ne peut encore se connecter.'

  etat.hidden = true
  succes.hidden = false
}

confirmer()
