// LE VOCABULAIRE DU CONTRÔLE D'ACCÈS, ET LES DEUX CALCULS QUI L'ACCOMPAGNENT.
//
// POURQUOI CE FICHIER PLUTÔT QUE `vocabulaire.js`. La carte globale traduit des codes partagés par
// toute l'application ; or ceux-ci entrent en COLLISION avec elle. `mot('valide')` rend « Accepté »,
// qui qualifie le résultat d'un passage — appliqué au statut de projection d'un droit, il ferait lire
// « Accepté » là où le serveur dit « la projection locale est à jour ». `mot('caisse')` rend
// « Espèces au guichet ». Un code, deux sens : une carte globale ne peut pas porter les deux.
//
// POURQUOI CE FICHIER PLUTÔT QU'UNE TABLE PAR ÉCRAN. Quatre écrans disent les mêmes choses —
// Supervision, Topologie & passages, le bandeau des scans à la caisse, le bloc de la fiche client.
// Quand la même table est recopiée quatre fois, elle finit par ne plus dire la même chose aux quatre
// endroits, et c'est l'exploitant qui découvre l'écart, en lisant deux libellés différents pour le
// même refus.

export const TYPE_EQUIPEMENT = { tourniquet: 'Tourniquet', tripode: 'Tripode', lecteur: 'Lecteur' }
export const SENS_EQUIPEMENT = { entree: 'Entrée', sortie: 'Sortie', bidirectionnel: 'Entrée et sortie' }
export const MODE_SEUIL = { blocage: 'Blocage au seuil', alerte: 'Alerte seule' }
export const MODE_RECALAGE = { remise_a_zero: 'Remise à zéro', report_residuel: 'Report du résiduel' }
export const ETAT_CONTROLEUR = { en_ligne: 'En ligne', hors_ligne: 'Hors ligne', hors_service: 'Hors service' }
export const ETAT_CLS = { en_ligne: 'good', hors_ligne: 'warn', hors_service: 'crit' }
export const RESULTAT_PASSAGE = { valide: 'Validé', refuse: 'Refusé', compte: 'Compté' }
export const RESULTAT_CLS = { valide: 'good', refuse: 'crit', compte: 'mut' }
export const SENS_PASSAGE = { entree: 'Entrée', sortie: 'Sortie' }

// CHAQUE MOTIF PORTE UNE PHRASE ET UN GESTE, ET PAS SEULEMENT UN LIBELLÉ.
//
// « hors_marge » ne dit rien à l'agent qui a le porteur devant lui. Ce qu'il lui faut, c'est ce qui
// s'est passé ET ce qu'il peut y faire — parce que les motifs ne sont pas de la même nature :
//
//   — certains se corrigent en deux clics, ici ou dans Paramètres (site fermé, seuil atteint,
//     tolérance trop courte) ;
//   — d'autres sont une décision qu'on ne défait pas au comptoir (badge bloqué, code forgé) ;
//   — d'autres encore sont des faits d'exploitation (carte épuisée : il faut la recharger).
//
// Les afficher tous du même gris envoie quelqu'un chercher une panne là où il y a un horaire.
export const MOTIF_REFUS = {
  hors_marge: {
    libelle: 'Hors créneau',
    quoi: 'Le porteur s’est présenté trop tôt ou trop tard par rapport à la fenêtre de son droit.',
    geste: 'Ajustez les tolérances d’avance et de retard sur l’équipement, dans le plan du site.',
  },
  anti_passback: {
    libelle: 'Anti-passback',
    quoi: 'Le même support a déjà été lu il y a moins que le délai configuré.',
    geste: 'Si c’est un usage légitime (sortie puis retour), baissez le délai de l’espace ou désactivez-le sur cet équipement.',
  },
  credit_epuise: {
    libelle: 'Carte épuisée',
    quoi: 'Il ne reste plus d’entrée sur la carte.',
    geste: 'Rechargez la carte depuis la caisse.',
  },
  support_bloque: {
    libelle: 'Badge bloqué',
    quoi: 'Le support a été déclaré perdu ou volé.',
    geste: 'Le déblocage se fait dans Badges & terminaux › Pertes & vols.',
  },
  seuil_fmi: {
    libelle: 'Jauge atteinte',
    quoi: 'L’espace a atteint son seuil de fréquentation et le mode est « blocage ».',
    geste: 'Relevez le seuil, ou passez l’espace en « alerte seule » si le blocage n’est pas voulu.',
  },
  droit_invalide: {
    libelle: 'Droit invalide',
    quoi: 'Support inconnu, non appairé, ou droit dévalidé.',
    geste: 'Vérifiez l’appairage dans Badges & terminaux.',
  },
  sens_interdit: {
    libelle: 'Sens interdit',
    quoi: 'L’équipement n’accepte pas ce sens de franchissement.',
    geste: 'Corrigez le sens de l’équipement dans le plan du site si le matériel a été retourné.',
  },
  non_nominatif: { libelle: 'Passage non nominatif', quoi: 'Comptage sans support identifié.', geste: '' },
  ouverture_manuelle: { libelle: 'Ouverture manuelle', quoi: 'Un agent a forcé le passage, avec motif.', geste: '' },
  federation_inactive: {
    libelle: 'Fédération inactive',
    quoi: 'Le droit vient d’un autre site du sous-réseau, mais le sous-réseau est désactivé ou le produit n’y est pas éligible.',
    geste: 'Activez le sous-réseau, ou ajoutez le produit à ses droits éligibles.',
  },
  signature_invalide: {
    libelle: 'Code forgé',
    quoi: 'La signature du support ne correspond pas.',
    geste: 'Aucun : c’est un refus de sécurité.',
  },
  // ⚠ CES DEUX MOTIFS SE RESSEMBLENT DANS UN JOURNAL ET APPELLENT DES GESTES OPPOSÉS.
  //
  // `hors_portee` parle du MATÉRIEL : le terminal qui rapporte n'a pas autorité sur cet équipement.
  // C'est une installation à vérifier, et personne n'y peut rien au comptoir.
  //
  // `zone_non_autorisee` parle du DROIT : le porteur est au bon endroit, avec un titre valide, mais
  // son produit ne couvre pas cette zone. C'est une VENTE à faire — un complément, un supplément —
  // et c'est la seule des deux qu'un agent peut résoudre en trente secondes.
  //
  // Les afficher du même gris enverrait chercher un technicien là où il fallait un caissier.
  hors_portee: {
    libelle: 'Hors portée du terminal',
    quoi: 'Le terminal qui rapporte ce passage n’a pas autorité sur cet équipement.',
    geste: 'Vérifiez la référence ITBOX du contrôleur : c’est une question d’installation, pas de titre.',
  },
  zone_non_autorisee: {
    libelle: 'Zone non comprise dans le billet',
    quoi: 'Le titre est valide et le porteur est au bon endroit : c’est son produit qui n’ouvre pas cette zone.',
    geste: 'Vendez le complément qui couvre cette zone — ou corrigez les zones du produit s’il devait les ouvrir.',
  },
  credit_epuise_hors_ligne_litige: {
    libelle: 'Litige hors ligne',
    quoi: 'Le passage a été accepté hors ligne alors que le crédit était épuisé — accepté, puis constaté à la synchronisation.',
    geste: 'Rien à faire dans l’instant : c’est une trace, pas un refus.',
  },
  hors_horaires_ouverture: {
    libelle: 'Site fermé',
    quoi: 'Le passage tombe hors des heures d’ouverture, et le refus hors horaires est activé.',
    geste: 'Corrigez la plage dans Paramètres › Heures d’ouverture — ou décochez le refus hors horaires.',
  },
}

// Une phrase courte, pour les endroits étroits (bandeau de caisse, colonne de tableau). Le code brut
// est rendu tel quel s'il n'est pas connu : un motif qu'on n'a pas encore traduit vaut mieux affiché
// que masqué — c'est le seul indice d'un refus qu'on n'avait pas prévu.
export function phraseMotif(passage) {
  const connu = passage?.codeMotif ? MOTIF_REFUS[passage.codeMotif] : null
  if (connu) return connu.quoi
  return passage?.motif || passage?.codeMotif || null
}

// UN ÉTAT « EN LIGNE » N'EST PAS UN SIGNE DE VIE, ET LES CONFONDRE FAIT MENTIR L'ÉCRAN.
//
// Constaté le 29/08 contre la préprod : le contrôleur « Entrée A1 » de Piscine A s'affiche EN LIGNE
// avec un dernier signe de vie à **jamais**. L'état vient des données d'installation, pas d'un ping.
// Un écran de supervision qui répète « en ligne » sur un matériel qui n'a jamais parlé raconte une
// histoire, et c'est le seul écran qui n'a pas le droit d'en raconter.
//
// Trois cas, parce qu'ils appellent trois gestes différents :
//   — JAMAIS VU : le matériel n'a jamais rapporté. C'est une installation à finir, pas une panne.
//   — VU RÉCEMMENT : rien à faire.
//   — SILENCIEUX : il parlait, il s'est tu. C'est une panne, et c'est le seul cas qui presse.
//
// Le seuil est une CONVENTION DE L'INTERFACE, pas une règle du serveur : rien, côté API, ne déclare
// la période de battement attendue d'un ITBOX. Dix minutes est large exprès — mieux vaut signaler
// tard que crier sur un matériel qui va bien. Le jour où le serveur portera la période, c'est elle
// qu'il faudra lire ici.
export const SILENCE_ALERTE_MS = 10 * 60 * 1000

export function depuis(v) {
  if (!v) return 'jamais'
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return '—'
  const s = Math.max(0, Math.round((Date.now() - d.getTime()) / 1000))
  if (s < 60) return `il y a ${s} s`
  if (s < 3600) return `il y a ${Math.round(s / 60)} min`
  if (s < 86400) return `il y a ${Math.round(s / 3600)} h`
  return `il y a ${Math.round(s / 86400)} j`
}

export function signeDeVie(controleur) {
  const brut = controleur?.dernierHeartbeat
  if (!brut) {
    return controleur?.etat === 'en_ligne'
      ? { texte: 'annoncé en ligne, mais aucun signe de vie', cls: 'warn', suspect: true }
      : { texte: 'aucun signe de vie', cls: 'mut', suspect: false }
  }
  const d = new Date(brut)
  if (Number.isNaN(d.getTime())) return { texte: 'signe de vie illisible', cls: 'warn', suspect: true }
  if (Date.now() - d.getTime() > SILENCE_ALERTE_MS) {
    return { texte: `silencieux depuis ${depuis(brut).replace('il y a ', '')}`, cls: 'crit', suspect: true }
  }
  return { texte: `vu ${depuis(brut)}`, cls: 'mut', suspect: false }
}

// Un délai en secondes, lisible. `null` n'est pas « 0 seconde » : c'est « pas de valeur propre ».
export function duree(secondes) {
  if (secondes === null || secondes === undefined) return '—'
  if (secondes < 60) return `${secondes} s`
  return `${Math.round(secondes / 60)} min`
}
