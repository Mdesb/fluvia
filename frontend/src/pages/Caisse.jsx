import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Qr from '../components/Qr.jsx'
// `texte` lit un libelle multilingue : le serveur rend `{ fr: '...' }`, pas une chaine.
import { texte } from '../components/Liste.jsx'
import HistoriqueVentesModal from '../components/HistoriqueVentesModal.jsx'
import Modal from '../components/Modal.jsx'
import ScansEnDirect from '../components/ScansEnDirect.jsx'
import RechercheBilletModal from '../components/RechercheBilletModal.jsx'
import ChoixOptions from '../components/ChoixOptions.jsx'
import ClientPicker, { nomClient } from '../components/ClientPicker.jsx'
import SessionCaisse from './SessionCaisse.jsx'
import {
  libelleProduit,
  prixIndicatif,
  grillesVendables,
  libelleTarif,
  typeTarifId,
  estVendable,
  raisonNonVendable,
  expliqueNonVendable,
  euros,
} from '../api/produit.js'

// Ordre de présentation préféré des moyens de paiement au guichet.
const ORDRE_MOYENS = ['especes', 'cb', 'cheque', 'pmv']

export default function Caisse({ me, etabActif, etablissements, session, capacites = [], droits = [], onSessionRefresh }) {
  const [caisseModale, setCaisseModale] = useState(false)
  const [historique, setHistorique] = useState(false)
  // « Pourquoi mon billet ne passe pas ? » se demande AU GUICHET, pas en supervision.
  // La fenêtre existait et n'était atteignable que depuis l'écran de supervision — que le
  // caissier n'a jamais ouvert. Son propre commentaire le disait déjà.
  const [verifBillet, setVerifBillet] = useState(false)
  const [choixTarif, setChoixTarif] = useState(null)
  const [choixOptions, setChoixOptions] = useState(null)
  // ⚠ `null` = PAS LU. << Aucun produit disponible. >> lu par un caissier signifie << il n'y a
  // rien a vendre >>, et il ferme la caisse ou appelle un responsable. Sur une lecture refusee,
  // le catalogue existe et n'a simplement pas ete obtenu.
  const [produits, setProduits] = useState(null)
  const [moyens, setMoyens] = useState([])
  const [pdvs, setPdvs] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const [panier, setPanier] = useState([]) // { produit, quantite }
  const [ticket, setTicket] = useState(null)
  // Le billet remis sans ticket de caisse (R5). Distinct de `ticket` : ce n'est pas le meme
  // document, et les afficher tous les deux serait remettre deux papiers pour une seule vente.
  const [billetSeul, setBilletSeul] = useState(null)
  // Le rayon choisi, ou '' pour « tous ». Purement d'affichage : il ne touche ni au panier ni à
  // ce qui est vendable.
  const [rayonActif, setRayonActif] = useState('')
  // Ce que le serveur a decide de ce ticket, et ce qu'il reste a demander au client.
  const [finVente, setFinVente] = useState(null)

  // Client rattaché à la vente (bénéficiaire des produits nominatifs, RG-M2-04 / CA-7).
  const [client, setClient] = useState(null)
  const [pickerOuvert, setPickerOuvert] = useState(false)
  const [besoinClient, setBesoinClient] = useState(false)
  // ⚠ LE SOLDE DU PORTE-MONNAIE, QUE CET ECRAN NE LISAIT PAS.
  //
  // `null` = pas de porte-monnaie, ou pas encore lu. La caisse proposait `pmv` comme moyen de
  // paiement en testant UNIQUEMENT la capacite `porte_monnaie` de l'etablissement -- jamais le
  // client, jamais son solde. Un caissier pouvait donc choisir << porte-monnaie >> sans client
  // rattache (il n'y a alors aucun porte-monnaie a debiter) ou sur un solde vide, et decouvrir le
  // refus au moment de valider, devant la personne.
  const [pmvClient, setPmvClient] = useState(null)

  // Phase de paiement (encaissement scindé sur une vente ouverte).
  const [vente, setVente] = useState(null) // { id, reste }
  // La popup d'appairage : `null` tant qu'elle n'a pas lieu d'etre, sinon ce qu'il faut pour
  // valider ensuite sans relire un etat qui aura change.
  const [appairageEnAttente, setAppairageEnAttente] = useState(null)
  const [paiements, setPaiements] = useState([]) // règlements acceptés
  const [moyenSel, setMoyenSel] = useState('especes')
  const [montant, setMontant] = useState('')
  const [tpeSimule, setTpeSimule] = useState('accepte')
  const [busy, setBusy] = useState(false)
  const [avis, setAvis] = useState(null) // message TPE refusé, etc.

  const nomEtab = etablissements.find((e) => e.id === etabActif)?.nom || ''

  useEffect(() => {
    let annule = false
    setChargement(true)
    setErreur(null)
    setPanier([])
    setTicket(null)
    setBilletSeul(null)
    setFinVente(null)
    setVente(null)
    setPaiements([])
    setClient(null)
    setPmvClient(null)
    setBesoinClient(false)
    Promise.all([api.produits(), api.moyensPaiement(), api.pointDeVentes()])
      .then(([pc, mc, dc]) => {
        if (annule) return
        setProduits(membres(pc))
        setMoyens(membres(mc).filter((m) => m.actif !== false))
        setPdvs(membres(dc))
      })
      .catch((e) => !annule && (setErreur(e.message), setProduits(null)))
      .finally(() => !annule && setChargement(false))
    return () => {
      annule = true
    }
  }, [etabActif])

  const total = useMemo(
    () =>
      panier.reduce((s, l) => {
        const pu = parseFloat((l.prix ?? prixIndicatif(l.produit)) || '0') || 0
        return s + pu * l.quantite
      }, 0),
    [panier],
  )

  // Le point de vente de la session — deux choses en dépendent maintenant : les moyens de paiement
  // autorisés et les produits épinglés. Il était recalculé dans `moyensDispo` ; il en sort.
  const pdvActif = useMemo(() => {
    const pdvId = session?.pointDeVente?.id || session?.pointDeVente
    return pdvs.find((p) => p.id === pdvId) || null
  }, [pdvs, session])

  // Moyens réellement proposables : actifs et autorisés sur le point de vente de la session.
  const moyensDispo = useMemo(() => {
    const pdv = pdvActif
    const autorises = pdv?.moyensAutorises || []
    let liste = moyens.filter((m) => autorises.length === 0 || autorises.includes(m.code))
    // PMV : trois conditions, et l'écran n'en vérifiait qu'une.
    //
    //   1. la capacité `porte_monnaie` est active sur l'établissement  ← seule vérifiée avant
    //   2. un client est rattaché à la vente — sans lui, aucun porte-monnaie à débiter
    //   3. ce client a un porte-monnaie actif avec un solde strictement positif
    //
    // Proposer un moyen de paiement qui sera refusé n'est pas neutre : le caissier le découvre en
    // validant, devant la personne, et doit tout reprendre.
    const pmvUtilisable = capacites.includes('porte_monnaie')
      && Boolean(client?.id)
      && pmvClient?.statut === 'actif'
      && Number(pmvClient?.solde || 0) > 0
    if (!pmvUtilisable) liste = liste.filter((m) => m.code !== 'pmv')
    return liste.sort((a, b) => {
      const ia = ORDRE_MOYENS.indexOf(a.code)
      const ib = ORDRE_MOYENS.indexOf(b.code)
      return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib)
    })
  }, [moyens, pdvActif, capacites, client, pmvClient])

  // LES FAVORIS SONT CEUX DU COMPTOIR, PAS CEUX DU CAISSIER — et il faut le dire.
  //
  // `PointDeVente::$favoris` est une liste d'identifiants de produits portée par le POINT DE VENTE.
  // Deux personnes qui se relaient au même guichet voient donc les mêmes épingles, et celle qui
  // épingle change l'écran de l'autre. Ce n'est pas un défaut — un comptoir vend les mêmes choses
  // quelle que soit la personne derrière — mais quelqu'un qui croirait régler SON écran serait
  // surpris, d'où l'infobulle qui le dit.
  const favoris = pdvActif?.favoris || []

  // Les épinglés d'abord, le reste dans son ordre d'origine. Un tri qui remonterait aussi par
  // fréquence de vente serait plus malin et beaucoup moins prévisible : le caissier apprend la
  // place de ses boutons, il ne la relit pas.
  // ⚠ CE QUI N'EST PAS VENDABLE NE S'AFFICHE PLUS (R2), ET LE NOMBRE MASQUE EST DIT.
  //
  // Un produit sans tarif au guichet, ou en rupture, invitait le caissier a cliquer puis a
  // expliquer au client, devant la file, pourquoi ca ne marche pas. Il n'a plus sa tuile.
  //
  // ⚠ MAIS UNE ABSENCE MUETTE EST UN AUTRE DEFAUT. Un caissier qui cherche « Entree adulte » et ne
  // la voit pas conclut qu'elle n'existe pas — alors qu'elle existe et qu'il lui manque un tarif.
  // Le compte des masques est donc affiche sous la grille, avec la raison et ou aller la corriger.
  //
  // ⚠ ON NE FILTRE PAS SUR `enPaiement`. La tuile est desactivee par `estVendable(p) &&
  // !enPaiement` : pendant un encaissement, TOUS les produits le sont. Reprendre cette expression
  // ici aurait vide la grille au milieu de chaque paiement.
  //
  // ⚠ ET LE RAYON EST UN FILTRE D'AFFICHAGE, COMPTÉ À PART. `produitsMasques` dit « il leur manque
  // un tarif au guichet, ou ils sont en rupture ». Y ajouter ceux qu'un rayon écarte ferait
  // annoncer un problème de TARIF à propos d'un simple filtre, et enverrait le caissier corriger
  // un catalogue qui va très bien.
  const { produitsAffiches, produitsMasques, rayons } = useMemo(() => {
    const tous = produits || []
    const vendables = tous.filter(estVendable)
    const masques = tous.length - vendables.length

    // Les rayons réellement portés par ce qui est vendable ici. Proposer un rayon vide ferait
    // cliquer sur une grille vide — la collection rend `axe` et `libelle` embarqués, on n'a rien
    // d'autre à demander au serveur.
    const parId = new Map()
    for (const p of vendables) {
      for (const cat of p.categories || []) {
        if (cat && cat.axe === 'rayon' && cat.id) parId.set(cat.id, cat)
      }
    }
    const rayonsPresents = [...parId.values()].sort((a, b) => (a.libelle || '').localeCompare(b.libelle || ''))

    const dansLeRayon = rayonActif === ''
      ? vendables
      : vendables.filter((p) => (p.categories || []).some((cat) => cat && cat.id === rayonActif))

    if (favoris.length === 0) {
      return { produitsAffiches: dansLeRayon, produitsMasques: masques, rayons: rayonsPresents }
    }
    const rang = (p) => (favoris.includes(p.id) ? 0 : 1)
    return {
      produitsAffiches: [...dansLeRayon].sort((a, b) => rang(a) - rang(b)),
      produitsMasques: masques,
      rayons: rayonsPresents,
    }
  }, [produits, favoris.join(','), rayonActif])

  // ⚠ UN RAYON QUI DISPARAÎT NE DOIT PAS LAISSER UNE GRILLE VIDE SANS EXPLICATION. Si le rayon
  // choisi n'existe plus — catalogue rechargé, produit dépublié — on revient à « tous » plutôt que
  // de montrer un écran vide dont le caissier ne verrait pas la cause.
  useEffect(() => {
    if (rayonActif !== '' && !rayons.some((r) => r.id === rayonActif)) setRayonActif('')
  }, [rayons, rayonActif])

  async function basculerFavori(produitId) {
    if (!pdvActif) return
    const avant = pdvActif.favoris || []
    const apres = avant.includes(produitId)
      ? avant.filter((id) => id !== produitId)
      : [...avant, produitId]
    // On relit le point de vente depuis le serveur plutôt que de recopier l'état local : c'est lui
    // qui fait foi, et une autre caisse du même comptoir peut avoir épinglé entre-temps.
    try {
      await api.majPointDeVente(pdvActif.id, { favoris: apres })
      setPdvs(await membresPdv())
    } catch (e) {
      setErreur(e.message || 'L’épinglage n’a pas pu être enregistré.')
    }
  }

  async function membresPdv() {
    const r = await api.pointDeVentes()
    return membres(r)
  }

  // `caisse.gerer` : le meme droit que celui qui protege le PATCH du point de vente cote serveur.
  // Un caissier sans ce droit ne voit pas l'etoile, plutot que de la voir refuser au clic.
  const peutEpingler = !!pdvActif && aLeDroit(droits, 'caisse.gerer')

  const moyenCourant = moyensDispo.find((m) => m.code === moyenSel) || null
  const reste = vente ? parseFloat(vente.reste || '0') : total

  // Une ligne de panier est un produit ET un tarif : deux tarifs du meme produit sont deux lignes.
  // Les fusionner obligerait a ressaisir pour vendre un adulte et un enfant ensemble, ce qui est la
  // vente courante d'une famille au guichet.
  const cleLigne = (produitId, tarifId) => `${produitId}|${tarifId}`

  /**
   * LES GROUPES QUI DEMANDENT VRAIMENT UNE DÉCISION DU CAISSIER.
   *
   * Maxime, en voyant la première version : *« c'est trop complexe ou trop chargé pour le client
   * final »*. Il avait raison, et la faute était nette — la modale s'ouvrait dès qu'un produit portait
   * **une** option, y compris facultative. Vendre une entrée passait de un clic à trois, pour poser une
   * question dont la réponse par défaut est « non ».
   *
   * Un groupe **facultatif** n'est pas une décision : ne rien prendre est une réponse valide, et le
   * caissier peut l'ajouter après si le client le demande. Un groupe **obligatoire à valeur unique**
   * n'en est pas une non plus : il n'y a rien à arbitrer, seulement une formalité que le serveur
   * exigera. **Reste le seul vrai cas : obligatoire, et plusieurs valeurs disponibles.**
   */
  const decisionsOuvertes = (devis) =>
    (devis?.options ?? []).filter(
      (g) => g.obligatoire && (g.valeurs ?? []).filter((v) => v.disponible !== false).length > 1,
    )

  /**
   * Ce qui se choisit tout seul : un groupe obligatoire dont une seule valeur est disponible.
   *
   * Sans ça, l'écran ouvrirait une fenêtre pour faire cocher l'unique case possible — ou, pire, laisserait
   * partir la ligne que `AjoutLigneHandler` refusera en RG-OPT-03, après que le caissier a annoncé un prix.
   */
  const optionsImposees = (devis) => {
    const retenues = []
    for (const g of devis?.options ?? []) {
      if (!g.obligatoire) continue
      const dispo = (g.valeurs ?? []).filter((v) => v.disponible !== false)
      if (dispo.length === 1) retenues.push(dispo[0].valeurOption)
    }
    return retenues
  }

  /**
   * Ajoute au panier — en demandant AU SERVEUR le prix et les options proposables.
   *
   * **Un clic reste un clic quand il n'y a rien à choisir.** Si le produit n'a aucune option, la ligne
   * part immédiatement ; la modale ne s'ouvre que pour ceux qui ont un choix à faire. Une caisse se juge
   * au nombre de gestes par vente.
   *
   * **Et le prix vient du devis, plus de la grille.** L'écran choisissait la première grille vendable ;
   * le serveur applique le tarif réellement dû — saison, quotient familial. Les deux peuvent différer
   * sans que personne ne soit en faute, et c'est ce montant que le caissier annonce à voix haute.
   */
  async function ajouter(produit, grille, options = [], devisConnu = null) {
    setTicket(null)
    setBilletSeul(null)
    setFinVente(null)
    const g = grille || grillesVendables(produit)[0]
    if (!g) return

    let devis = devisConnu
    if (!devis) {
      try {
        devis = await api.tarifProduit(produit.id, { typeTarif: g.typeTarif.id })
      } catch (e) {
        setErreur(e.message || "Le prix n'a pas pu être obtenu.")
        return
      }
      // On n'ouvre que pour un arbitrage réel — jamais pour une case à cocher sans alternative.
      if (decisionsOuvertes(devis).length > 0) {
        setChoixOptions({ produit, grille: g, devis, selection: optionsImposees(devis) })
        return
      }

      // Les formalités se règlent sans le caissier, mais PAS SANS LE SERVEUR : le prix change, et un
      // prix annoncé qui n'est pas celui qui sera facturé est précisément ce qu'on corrige depuis
      // trois jours. On redemande le devis plutôt que d'ajouter l'impact ici.
      const imposees = optionsImposees(devis)
      if (imposees.length > 0) {
        try {
          devis = await api.tarifProduit(produit.id, {
            typeTarif: g.typeTarif.id,
            canal: devis.canal,
            options: imposees,
          })
          options = imposees
        } catch (e) {
          setErreur(e.message || "Le prix n'a pas pu être obtenu.")
          return
        }
      }
    }

    ajouterLigne(produit, g, options, devis)
  }

  /**
   * Rouvrir les options d'une ligne déjà au panier.
   *
   * C'est ce qui permet au premier clic de rester un clic : le caissier vend, et n'ouvre cette fenêtre
   * que si le client réclame quelque chose. L'ordre naturel du comptoir — on encaisse, puis on ajuste —
   * plutôt que l'ordre du formulaire.
   */
  async function ajusterOptions(l) {
    setErreur(null)
    try {
      const devis = await api.tarifProduit(l.produit.id, {
        typeTarif: l.typeTarifId,
        options: l.options ?? [],
      })
      setChoixOptions({
        produit: l.produit,
        grille: l.grille,
        devis,
        selection: l.options ?? [],
        remplace: l.cle,
      })
    } catch (e) {
      setErreur(e.message || "Les options n'ont pas pu être relues.")
    }
  }

  function ajouterLigne(produit, g, options, devis, remplace = null) {
    const cle = cleLigne(produit.id, g.typeTarif.id) + (options.length ? `|${[...options].sort().join(',')}` : '')
    setPanier((p) => {
      // AJUSTER N'EST PAS AJOUTER. Changer les options change la clé de ligne ; sans ce retrait, le
      // panier garderait l'ancienne version à côté de la nouvelle et facturerait les deux.
      const quantiteReprise = remplace !== null ? p.find((l) => l.cle === remplace)?.quantite : null
      if (remplace !== null && remplace !== cle) p = p.filter((l) => l.cle !== remplace)
      const i = p.findIndex((l) => l.cle === cle)
      if (i >= 0) {
        const copie = [...p]
        // Un ajustement ne vend pas une unité de plus : il rhabille celle qui est déjà là.
        copie[i] = { ...copie[i], quantite: copie[i].quantite + (remplace !== null ? 0 : 1) }
        return copie
      }
      return [
        ...p,
        {
          cle,
          produit,
          quantite: quantiteReprise ?? 1,
          typeTarifId: g.typeTarif.id,
          tarifLibelle: libelleTarif(g),
          // Conservées pour rouvrir les options sans redemander au catalogue ce qu'on a déjà.
          grille: g,
          aOptions: (devis?.options ?? []).length > 0,
          // Le prix du DEVIS, pas celui de la grille : c'est celui qui sera facturé.
          prix: devis?.totalUnitaire ?? devis?.prixUnitaire ?? g.prix,
          options,
          // Les libellés servent à afficher la ligne sans redemander ; les montants viennent du devis.
          optionsLibelles: (devis?.options ?? [])
            .flatMap((groupe) => groupe.valeurs)
            .filter((valeur) => options.includes(valeur.valeurOption))
            .map((valeur) => valeur.libelle),
        },
      ]
    })
  }

  // Un clic reste un clic quand il n'y a rien a choisir : on ne fait payer le choix qu'a ceux qui en
  // ont un. Une caisse se juge au nombre de gestes par vente.
  function choisirPuisAjouter(produit) {
    const grilles = grillesVendables(produit)
    if (grilles.length <= 1) ajouter(produit, grilles[0])
    else setChoixTarif({ produit, grilles })
  }

  // CE QU'UNE CARTE SCANNEE PEUT REJOINDRE.
  //
  // Deux conditions, et la seconde vient du serveur : `carte` non nulle sur le produit, et quantite
  // egale a 1 (RG-CQ8-02 — un identifiant explicite ne peut designer qu'un support, la contrainte
  // d'unicite globale interdit le reste). On rend les deux familles : celles qu'on peut proposer,
  // et celles qu'on doit NOMMER comme hors de portee plutot que de les taire.
  function trierAppairables(lignes) {
    const proposables = []
    const horsPortee = []
    for (const l of lignes) {
      if (!l.produit?.carte) continue
      if (l.quantite === 1 && l.ligneServeurId) proposables.push(l)
      else horsPortee.push(l)
    }
    return { proposables, horsPortee }
  }

  function changerQte(cle, delta) {
    setPanier((p) =>
      p.map((l) => (l.cle === cle ? { ...l, quantite: l.quantite + delta } : l)).filter((l) => l.quantite > 0),
    )
  }
  function retirer(cle) {
    setPanier((p) => p.filter((l) => l.cle !== cle))
  }

  // Démarre l'encaissement : crée la vente, ajoute les lignes, passe en phase paiement.
  async function demarrerPaiement() {
    if (panier.length === 0 || !session) return
    setBusy(true)
    setErreur(null)
    setAvis(null)
    setBesoinClient(false)
    setTicket(null)
    setBilletSeul(null)
    setFinVente(null)
    try {
      const v = await api.creerVente({ session: session.id })
      // Rattache le client à la vente (M2, CA-7) — préalable au bénéficiaire des lignes nominatives.
      if (client) {
        try {
          await api.rattacherClientVente(v.id, { client: client.id })
        } catch {
          /* le rattachement échoue silencieusement : la garde bénéficiaire ci-dessous prendra le relais */
        }
      }
      let courant = v
      for (const l of panier) {
        // Le tarif choisi sur la ligne, et non plus un tarif devine pour tout le panier.
        const tarif = l.typeTarifId || typeTarifId(l.produit)
        if (!tarif) throw new Error(`« ${libelleProduit(l.produit)} » n'a pas de tarif au guichet.`)
        const corps = { produit: l.produit.id, typeTarif: tarif, quantite: l.quantite }
        // Les options retenues suivent la ligne : sans elles, le serveur facturerait le prix de base
        // et le caissier aurait annoncé autre chose.
        if (l.options?.length) corps.options = l.options
        // Bénéficiaire requis pour les produits nominatifs (RG-M2-04) : on passe le client rattaché.
        if (client) corps.beneficiaire = client.id
        courant = await api.ajouterLigne(v.id, corps)
      }
      // LES LIGNES S'ALIGNENT SUR LE SERVEUR, PAS SEULEMENT LE TOTAL.
      //
      // Le total faisait déjà foi ici. Les lignes, elles, gardaient leur prix indicatif — si bien que
      // le panier pouvait afficher « 1 × Test 10,00 € » au-dessus d'un total de 15,00 €. C'est le
      // même défaut que celui du ticket, un cran plus tôt : **c'est ce montant que le caissier
      // annonce à voix haute avant d'encaisser.**
      //
      // Le tarif choisi par l'écran est la première grille vendable ; le serveur applique celui qui
      // est réellement dû — saison, quotient familial. Les deux peuvent différer sans que personne
      // ne soit en faute. Tant que la vente n'existe pas, l'écran ne peut qu'estimer ; dès qu'elle
      // existe, il n'a plus aucune raison de le faire.
      const lignesServeur = courant?.lignes || []
      if (lignesServeur.length > 0) {
        // ⚠ ON CONSOMME LE VIVIER, ON NE LE RELIT PAS.
        //
        // `find()` rendait toujours la PREMIERE ligne serveur du couple (produit, tarif) : deux
        // lignes de panier portant le même produit au même tarif — ce qui arrive dès qu'on choisit
        // des options différentes — recevaient toutes les deux le prix de la première.
        //
        // Sur un prix, le défaut se voit. Sur un identifiant de carte, il ne se verrait pas : on
        // appairerait la carte scannée à la mauvaise ligne, et le client repartirait avec une carte
        // rattachée au mauvais produit. Chaque ligne serveur n'est donc prise qu'une fois.
        const vivier = [...lignesServeur]
        const prendre = (l) => {
          const exact = vivier.findIndex(
            (x) => String(x.produit) === String(l.produit?.id)
              && String(x.typeTarif || '') === String(l.typeTarifId || ''),
          )
          const i = exact !== -1
            ? exact
            : vivier.findIndex((x) => String(x.produit) === String(l.produit?.id))
          return i === -1 ? null : vivier.splice(i, 1)[0]
        }

        setPanier((p) =>
          p.map((l) => {
            const ls = prendre(l)
            if (!ls) return l
            // L'id de ligne SERVEUR : c'est par lui que `valider` indexe les supports.
            const enrichie = { ...l, ligneServeurId: ls.id ?? null }
            return ls.prixUnitaire != null ? { ...enrichie, prix: ls.prixUnitaire } : enrichie
          }),
        )
      }

      const totalServeur = courant?.total ?? total.toFixed(2)
      const resteServeur = courant?.resteAPayer ?? totalServeur
      // Le numero est porte pour un seul usage : pouvoir NOMMER la vente si son annulation
      // echoue. Un identifiant technique ne se retrouve pas dans un journal de caisse.
      setVente({ id: v.id, numero: v.numero ?? null, reste: resteServeur, total: totalServeur })
      setPaiements([])
      setMoyenSel(moyensDispo[0]?.code || 'especes')
      setMontant(parseFloat(resteServeur) > 0 ? parseFloat(resteServeur).toFixed(2) : '')
    } catch (e) {
      const msg = e.message || "Impossible d'ouvrir la vente."
      // Garde « bénéficiaire requis » (RG-M2-04) : on invite à rattacher un client via la modale.
      if (/b[ée]n[ée]ficiaire/i.test(msg)) {
        setBesoinClient(true)
        setErreur('Ce panier contient un produit nominatif : rattachez un client bénéficiaire pour encaisser.')
        setPickerOuvert(true)
      } else {
        setErreur(msg)
      }
    } finally {
      setBusy(false)
    }
  }

  // Ajoute un règlement (paiement scindé). CB/chèque exigeant une référence => passage TPE simulé.
  async function reglerUnMoyen() {
    if (!vente || !moyenCourant) return
    setBusy(true)
    setErreur(null)
    setAvis(null)
    // Renseigne uniquement si CE règlement solde la vente ; consommé après le bloc ci-dessous, hors
    // de son `catch`, pour qu'un échec de validation ne se dise jamais « règlement refusé ».
    let aSolde = null
    try {
      const corps = { moyen: moyenCourant.code }
      const m = parseFloat(montant)
      if (!Number.isNaN(m) && m > 0) corps.montant = m.toFixed(2)
      const headers = moyenCourant.exigeReference ? { 'X-Tpe-Simule': tpeSimule } : undefined
      const res = await api.payer(vente.id, corps, headers)

      if (!res.reglementEnregistre) {
        // TPE refusé / timeout : aucun règlement ajouté, reste inchangé.
        setAvis(`Transaction ${moyenCourant.libelle} ${res.statutTPE || 'refusée'} — aucun règlement enregistré.`)
        return
      }
      setPaiements((p) => [
        ...p,
        { moyen: res.moyen, libelle: moyenCourant.libelle, montant: res.montant, rendu: res.rendu, statutTPE: res.statutTPE },
      ])
      const nouveauReste = res.resteAPayer ?? '0.00'
      setVente((v) => ({ ...v, reste: nouveauReste }))
      setMontant(parseFloat(nouveauReste) > 0 ? parseFloat(nouveauReste).toFixed(2) : '')

      // ── LE RESTE EST A ZERO : PLUS RIEN A DECIDER, SAUF S'IL Y A UNE CARTE ─────────────────
      //
      // Un reglement qui solde la vente ne laisse aucune autre issue que valider. Le clic de
      // confirmation n'apprenait donc rien — sauf quand une carte est en jeu, et c'est precisement
      // la que la popup s'intercale. Un paiement PARTIEL garde les deux etapes.
      //
      // ⚠ ON NOTE, ON N'AGIT PAS ENCORE. Enchaîner ici mettrait la validation dans le `try` de ce
      // règlement — dont le `catch` annonce « Règlement refusé ». Or à cet instant l'argent EST
      // encaissé : le caissier lirait un refus sur une vente payée et réencaisserait le client.
      if (parseFloat(nouveauReste) <= 0) {
        aSolde = [
          ...paiements,
          { moyen: res.moyen, libelle: moyenCourant.libelle, montant: res.montant, rendu: res.rendu, statutTPE: res.statutTPE },
        ]
      }
    } catch (e) {
      setErreur(e.message || 'Règlement refusé.')
      return
    } finally {
      setBusy(false)
    }

    // ── HORS DU `catch` DU REGLEMENT ────────────────────────────────────────────────────────
    //
    // La validation porte son propre message d'échec — « Échec de la validation » — qui est vrai et
    // qui n'invite pas à réencaisser. Le `return` du `catch` ci-dessus garantit qu'on n'arrive ici
    // que si le règlement a réellement abouti.
    if (!aSolde) return

    const { proposables, horsPortee } = trierAppairables(panier)
    if (proposables.length > 0 || horsPortee.length > 0) {
      setAppairageEnAttente({ venteId: vente.id, reglements: aSolde, proposables, horsPortee })
    } else {
      await validerVente(null, { venteId: vente.id, paiements: aSolde })
    }
  }

  // Le ticket vient ENTIÈREMENT du serveur, y compris les mots.
//
// CE QUE CETTE FONCTION A CESSÉ DE FAIRE, ET POURQUOI C'EST UNE BONNE NOUVELLE.
//
// Première version : le ticket se construisait depuis le panier, avec un repli sur le prix indicatif
// du catalogue. Le serveur facturait 15 €, le ticket imprimait 10 € et un total de 15 € — sur le
// document que le client emporte, et qu'il a le droit de contester.
//
// Deuxième version : les montants venaient de la vente, mais les libellés restaient ceux du panier,
// appariés par (produit, tarif), parce que `LigneVente` ne sérialisait aucun nom. Ça marchait tant
// que le ticket était édité dans la foulée — et jamais pour un duplicata, où il n'y a plus de panier.
//
// `claude-G` a livré le libellé **figé au moment de la vente**, et `TicketProcessor` rend désormais
// ses lignes. L'appariement disparaît : **l'argent et le mot viennent tous les deux du serveur**, et
// un produit renommé six mois plus tard ne change pas ce qu'un ticket d'hier affirme.
//
// UNE LIMITE QUI NE BOUGERA PAS, ET QU'IL FAUT CONNAÎTRE.
//
// Les ventes antérieures à la migration portent le nom que le produit a *aujourd'hui* : cette
// information n'avait jamais été écrite et ne se reconstitue pas. Un duplicata n'est réellement
// opposable qu'à partir de cette migration.
/**
 * @param {boolean} premiereEdition vrai quand le ticket est édité dans la foulée de la vente.
 *
 * **Pourquoi ce drapeau existe.** `TicketProcessor` déclare « duplicata » dès que la vente porte déjà
 * `imprime`. Or `ValiderVenteService` la marque imprimée **à la validation**, au titre de l'impression
 * automatique au-dessus du seuil — et ce seuil vaut 0 € par défaut. **Toute vente encaissée sortait donc
 * son premier ticket estampillé DUPLICATA.**
 *
 * L'impression automatique et cette édition-ci sont **le même événement**, pas deux. Seul l'appelant
 * sait le distinguer : il vient de créer la vente. Une réimpression demandée depuis l'historique, elle,
 * reste un duplicata et le dit.
 *
 * Le modèle confond deux choses — « un ticket a été émis » et « une impression a été ordonnée » —, comme
 * la clôture Z confondait le comptage et l'arrêté. On ne les sépare pas ici : ce serait toucher à un
 * comportement scellé et testé, sur un écran qu'on est en train de vérifier.
 */
function construireTicket(infoTicket, paiements, support, premiereEdition = false) {
  const lignes = (infoTicket.lignes || []).map((l) => {
    const nom = texte(l.libelle, 'Article')
    return {
      // Le tarif figure sur le ticket : sans lui, deux lignes du même produit à des prix différents
      // sont illisibles, pour le client comme pour le caissier qui le relit.
      libelle: l.tarif ? `${nom} — ${l.tarif}` : nom,
      quantite: l.quantite ?? 1,
      pu: l.prixUnitaire ?? '0.00',
      // `montantLigne` est le montant réellement facturé pour la ligne : options et remises
      // comprises. C'est lui qu'on affiche à droite, et non un produit qu'on recalculerait.
      montant: l.montantLigne ?? null,
      // LE DÉTAIL DES OPTIONS, PARCE QUE SANS LUI LE TICKET NE S'EXPLIQUE PAS.
      //
      // Le serveur les renvoyait déjà — figées à l'ajout au panier — et l'écran ne les lisait pas.
      // Un client qui paie 21,20 € pour un produit affiché 15,00 € voyait un écart sans cause : le
      // ticket s'additionnait, et restait incompréhensible. C'est le même défaut que le prix indicatif
      // d'hier, déplacé d'un cran — non plus un chiffre faux, mais un chiffre juste sans son motif.
      options: (l.optionsSelectionnees || []).map((o) => ({
        libelle: texte(o.libelle, 'Option'),
        montant: o.montantUnitaireApplique ?? null,
      })),
    }
  })

  // Un ticket qui ne s'additionne pas est un ticket qu'un client conteste, et il a raison.
  const somme = lignes.reduce(
    (s, l) => s + (l.montant != null ? parseFloat(l.montant) || 0 : (parseFloat(l.pu) || 0) * (l.quantite || 0)),
    0,
  )
  const total = parseFloat(infoTicket.total ?? '0') || 0

  return {
    numero: infoTicket.numero,
    lignes,
    // Le détail manque plutôt qu'il ne ment : si le serveur n'a rendu aucune ligne, on le dit et le
    // total reste affiché — c'est lui qui engage.
    detailIndisponible: lignes.length === 0,
    total: infoTicket.total ?? '0.00',
    ecartDetail: lignes.length > 0 && Math.abs(somme - total) > 0.005,
    duplicata: !premiereEdition && !!infoTicket.duplicata,
    paiements,
    codeSupport: support?.identifiantSupport || null,
  }
}

// Finalise : valide la vente et édite le ticket.
  // ⚠ LES ARGUMENTS EXPLICITES NE SONT PAS UNE COQUETTERIE.
  //
  // Cette fonction est desormais appelee DEPUIS `reglerUnMoyen`, dans la meme passe que les
  // `setVente`/`setPaiements` qui la precedent. `vente` et `paiements` y sont encore les valeurs du
  // rendu precedent : lire l'etat produirait un ticket sans le dernier reglement -- celui qui vient
  // justement de solder la vente. On passe donc ce qu'on sait, et l'etat ne sert que de repli pour
  // l'appel manuel.
  async function validerVente(supports = null, ctx = {}) {
    const venteId = ctx.venteId ?? vente?.id
    const reglements = ctx.paiements ?? paiements
    if (!venteId) return
    setBusy(true)
    setErreur(null)
    setAppairageEnAttente(null)
    try {
      const venteValidee = await api.valider(venteId, supports)
      const infoTicket = await api.ticket(venteId, 'imprimer')
      // Code de support signé (HMAC) émis à la validation : 1er support porteur d'un identifiant.
      const support = (venteValidee.supports || []).find((s) => s.identifiantSupport)

      // « SOUHAITEZ-VOUS UN TICKET ? » — demande de Maxime, et le serveur savait deja repondre.
      //
      // Jusqu'ici l'ecran affichait le ticket dans tous les cas. Or `TicketProcessor` rend depuis
      // toujours trois indications qu'aucun ecran ne lisait : `impressionAutomatique` (au-dessus
      // du seuil du point de vente, le ticket sort, on ne demande rien), `venteGratuite` (total a
      // zero : aucun ticket, et ce n'est pas une affaire de seuil) et `renvoiPropose` (en dessous
      // du seuil, avec un client rattache, le renvoi a un sens).
      //
      // Trois situations, trois comportements — et un seul jusqu'a aujourd'hui.
      const t = construireTicket(infoTicket, reglements, support, true)
      if (infoTicket.impressionAutomatique) setTicket(t)
      else setFinVente({ info: infoTicket, ticket: t })
      setPanier([])
      setVente(null)
      setPaiements([])
      setAvis(null)
      setClient(null)
      setBesoinClient(false)
    } catch (e) {
      setErreur(e.message || 'Échec de la validation.')
    } finally {
      setBusy(false)
    }
  }

  async function abandonner() {
    if (!vente) return
    setBusy(true)
    const abandonnee = vente
    try {
      await api.annulerVente(abandonnee.id)
      setErreur(null)
    } catch (e) {
      // ⚠ CE `catch` AVALAIT L'ECHEC, ET LA CAISSE REDEVENAIT PROPRE.
      //
      // Le serveur refuse, la vente reste OUVERTE et numérotée, et le caissier n'a aucun moyen de
      // le savoir. Treize ventes se sont accumulées ainsi sur la préproduction en deux semaines,
      // dont huit à total zéro. Chez un exploitant, c'est un fond de caisse qui ne tombe jamais
      // juste — et personne qui sache pourquoi. Mesuré par `allaccess-b8`.
      //
      // On réinitialise quand même l'écran : bloquer la caisse sur un échec d'annulation serait
      // pire, le client suivant attend. Ce qui manquait n'était pas le geste, c'était de le dire.
      setErreur(
        `La vente ${abandonnee.numero ? `n° ${abandonnee.numero} ` : ''}n'a PAS pu être annulée `
        + `(${e.message || 'refus du serveur'}). Elle reste ouverte côté serveur : signalez-la, `
        + `elle ne se fermera pas d'elle-même. La caisse est libre pour la vente suivante.`,
      )
    } finally {
      setVente(null)
      setPaiements([])
      setAvis(null)
      setBusy(false)
    }
  }

  // Retenu depuis la modale : rattache le client (bénéficiaire) et lève la garde nominative.
  function choisirClient(c) {
    setClient(c)
    setPickerOuvert(false)
    setBesoinClient(false)
    if (besoinClient) setErreur(null)
    // Un appel de plus, sur un geste EXPLICITE du caissier : on lit le porte-monnaie du client
    // qu'il vient de rattacher. `fiche-360` porte deja `pmv: { solde, devise, statut,
    // dateEcheance }` -- rien a ajouter cote serveur.
    setPmvClient(null)
    if (c?.id) {
      api.ficheClient(c.id)
        .then((f) => setPmvClient(f?.pmv || null))
        // ⚠ ON NE DEDUIT RIEN D'UN ECHEC. Sans solde lu, `pmv` reste indisponible et l'ecran le
        // dit : mieux vaut un moyen de paiement absent qu'un moyen propose sur une supposition.
        .catch(() => setPmvClient(null))
    }
  }

  // Modale d'ouverture / clôture Z, déclenchée depuis l'écran Caisse. Réutilise SessionCaisse
  // et ses appels API existants ; se ferme seule après ouverture, laisse le récap Z après clôture.
  const modaleSession = (
    <Modal
      open={caisseModale}
      onClose={() => setCaisseModale(false)}
      taille={session ? 'lg' : 'md'}
      titre={session ? 'Clôture de caisse (Z)' : 'Ouvrir la caisse'}
    >
      <SessionCaisse
        modale
        me={me}
        etabActif={etabActif}
        session={session}
        onRefresh={onSessionRefresh}
        onClose={() => setCaisseModale(false)}
      />
    </Modal>
  )

  const modaleClient = (
    <ClientPicker
      open={pickerOuvert}
      onClose={() => setPickerOuvert(false)}
      onSelect={choisirClient}
    />
  )

  // --- Rendu : pas de session ouverte ---
  if (!chargement && !session) {
    return (
      <div className="view">
        <div className="view-head">
          <div className="ttl"><h1>Caisse</h1><p>{nomEtab}</p></div>
          {/* CAISSE FERMÉE, LES TOURNIQUETS TOURNENT QUAND MÊME.
              Un groupe passe à l'ouverture des portes avant que le guichet n'ouvre sa session, et
              quelqu'un vient demander pourquoi son billet a été refusé. Priver l'agent de la
              vérification et du fil des scans parce qu'aucune caisse n'est ouverte, c'est lui
              retirer la réponse au moment précis où on la lui demande. */}
          <div className="actions">
            <button className="btn" onClick={() => setVerifBillet(true)}>Vérifier un billet</button>
          </div>
        </div>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <ScansEnDirect key={etabActif} droits={droits} etabActif={etabActif} />
        <RechercheBilletModal open={verifBillet} onClose={() => setVerifBillet(false)} droits={droits} />
        <div className="card">
          <div className="card-b" style={{ textAlign: 'center', padding: '40px 20px' }}>
            <div style={{ fontSize: 40, marginBottom: 8 }}>🔒</div>
            <h3 style={{ marginBottom: 6 }}>Aucune caisse ouverte</h3>
            <p className="hint" style={{ marginBottom: 18 }}>
              Ouvrez la caisse (point de vente, fond de caisse, régisseur) pour encaisser.
            </p>
            <button className="btn primary" onClick={() => setCaisseModale(true)}>Ouvrir la caisse</button>
          </div>
        </div>
        {modaleSession}
      </div>
    )
  }

  const enPaiement = !!vente

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Caisse</h1>
          <p>
            Session {session?.numero ? `n° ${session.numero}` : 'au guichet'} · {nomEtab}
          </p>
        </div>
        <div className="actions">
          <button className="btn" onClick={() => setVerifBillet(true)}>Vérifier un billet</button>
          <button className="btn" onClick={() => setHistorique(true)} disabled={enPaiement}>Historique</button>
          <button className="btn" onClick={() => setCaisseModale(true)} disabled={enPaiement}>Clôture Z</button>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}

      {/* Le bandeau des scans est en position fixe : il ne prend pas de place dans la grille de la
          caisse et ne bouge pas quand le panier s'allonge. Il ne s'affiche que pour un compte qui a
          le droit de lire les passages. */}
      {/* La clé remonte le bandeau au changement de site : une interrogation partie avant la
          bascule reviendrait sinon déposer les passages de l'ancien établissement sous le nom
          du nouveau. */}
      <ScansEnDirect key={etabActif} droits={droits} etabActif={etabActif} />
      <RechercheBilletModal open={verifBillet} onClose={() => setVerifBillet(false)} droits={droits} />

      {/* Elle s'ouvre seule : `appairageEnAttente` n'est renseigné que par un règlement qui solde
          la vente alors qu'une ligne porte une carte. */}
      <AppairageModal
        etat={appairageEnAttente}
        busy={busy}
        onValider={(supports) =>
          validerVente(supports, {
            venteId: appairageEnAttente.venteId,
            paiements: appairageEnAttente.reglements,
          })
        }
        onIgnorer={() =>
          validerVente(null, {
            venteId: appairageEnAttente.venteId,
            paiements: appairageEnAttente.reglements,
          })
        }
      />

      <div className="caisse-grid">
        <aside style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="card">
            <div className="card-h">
              <h3>{enPaiement ? 'Encaissement' : 'Panier'}</h3>
              {!enPaiement && panier.length > 0 && (
                <div className="r">
                  <button className="btn ghost sm" onClick={() => setPanier([])}>Vider</button>
                </div>
              )}
            </div>
            <div className="card-b">
              <div className={`client-bar${besoinClient ? ' besoin' : ''}`}>
                {client ? (
                  <>
                    <span className="cb-info">
                      <span className="cb-ic" aria-hidden="true">👤</span>
                      <span className="nm">{nomClient(client)}</span>
                    </span>
                    {!enPaiement && (
                      <button className="btn ghost sm" type="button" onClick={() => setClient(null)}>
                        Retirer
                      </button>
                    )}
                  </>
                ) : (
                  <>
                    <span className="cb-info hint" style={{ margin: 0 }}>
                      Vente au comptoir — aucun client rattaché
                    </span>
                    <button
                      className={`btn sm${besoinClient ? ' primary' : ''}`}
                      type="button"
                      onClick={() => setPickerOuvert(true)}
                      disabled={enPaiement}
                    >
                      ＋ Rattacher un client
                    </button>
                  </>
                )}
              </div>

              {panier.length === 0 && !enPaiement ? (
                <div className="empty">Cliquez un produit pour l'ajouter.</div>
              ) : (
                <>
                  {panier.map((l) => {
                    const pu = parseFloat((l.prix ?? prixIndicatif(l.produit)) || '0') || 0
                    return (
                      <div className="cline" key={l.cle}>
                        <div className="cn">
                          <span className="nm">{libelleProduit(l.produit)}</span>
                          <div className="cp">
                            {l.tarifLibelle && (
                              <span className="badge mut" style={{ marginRight: 6 }}>{l.tarifLibelle}</span>
                            )}
                            {euros(pu)}
                          </div>
                          {/* CE QUI EST FACTURÉ SE LIT SUR LA LIGNE QUI LE FACTURE.
                              Le panier affichait un prix options comprises sans nommer les options :
                              le caissier annonçait un montant qu'il ne pouvait pas justifier au client
                              qui le lui demandait. */}
                          {l.optionsLibelles?.length > 0 && (
                            <div className="cp" style={{ opacity: 0.8 }}>
                              {l.optionsLibelles.join(' · ')}
                            </div>
                          )}
                          {!enPaiement && l.aOptions && (
                            // Présent seulement quand le produit porte des options : sinon le bouton
                            // ouvrirait une fenêtre vide, et un bouton qui ne fait rien s'apprend une
                            // fois puis se contourne pour toujours.
                            <button
                              type="button"
                              className="btn ghost sm"
                              style={{ marginTop: 4, padding: '1px 8px', fontSize: 11.5 }}
                              onClick={() => ajusterOptions(l)}
                            >
                              {l.optionsLibelles?.length > 0 ? 'Modifier les options' : '+ Options'}
                            </button>
                          )}
                        </div>
                        {!enPaiement ? (
                          <div className="qty">
                            <button onClick={() => changerQte(l.cle, -1)}>−</button>
                            <span>{l.quantite}</span>
                            <button onClick={() => changerQte(l.cle, 1)}>+</button>
                          </div>
                        ) : (
                          <span style={{ color: 'var(--ink-soft)' }}>× {l.quantite}</span>
                        )}
                        <span className="num" style={{ minWidth: 58, fontWeight: 600 }}>{euros(pu * l.quantite)}</span>
                        {!enPaiement && (
                          <button className="rm" onClick={() => retirer(l.cle)} title="Retirer">×</button>
                        )}
                      </div>
                    )
                  })}

                  <div className="cline cart-total">
                    <span>Total</span>
                    <span className="num">{euros(enPaiement && vente?.total != null ? vente.total : total)}</span>
                  </div>

                  {!enPaiement ? (
                    <button className="btn primary lg" onClick={demarrerPaiement} disabled={busy}>
                      {busy ? 'Ouverture…' : `Encaisser ${euros(total)}`}
                    </button>
                  ) : (
                    <PanneauPaiement
                      moyensDispo={moyensDispo}
                      moyenSel={moyenSel}
                      setMoyenSel={(c) => { setMoyenSel(c); setAvis(null) }}
                      moyenCourant={moyenCourant}
                      montant={montant}
                      setMontant={setMontant}
                      tpeSimule={tpeSimule}
                      setTpeSimule={setTpeSimule}
                      reste={reste}
                      paiements={paiements}
                      avis={avis}
                      busy={busy}
                      onRegler={reglerUnMoyen}
                      onValider={validerVente}
                      onAbandon={abandonner}
                    />
                  )}
                </>
              )}
            </div>
          </div>

          {finVente && (
            <FinDeVente
              info={finVente.info}
              onAfficher={() => { setTicket(finVente.ticket); setFinVente(null) }}
              onBillet={() => { setBilletSeul(finVente.ticket); setFinVente(null) }}
              onSansTicket={() => setFinVente(null)}
            />
          )}

          {ticket && <TicketVente ticket={ticket} />}
          {billetSeul && <BilletSeul ticket={billetSeul} />}

        </aside>

        <section className="card">
          <div className="card-h">
            <h3>Produits</h3>
            <span className="sub">au guichet</span>
          </div>
          <div className="card-b">
            {chargement ? (
              <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
            ) : produits === null ? (
              <div className="banner banner-error">
                Le catalogue n’a pas pu être lu. <b>Ne concluez pas qu’il n’y a rien à vendre</b>&nbsp;:
                cette liste n’a pas été obtenue. Rechargez avant d’ouvrir la caisse.
              </div>
            ) : produits.length === 0 ? (
              <div className="empty">Aucun produit disponible.</div>
            ) : produitsAffiches.length === 0 ? (
              /* ⚠ « AUCUN PRODUIT » ET « AUCUN PRODUIT VENDABLE » NE SE DISENT PAS PAREIL. Le
                 catalogue existe, il est lu, et rien n'y est vendable a ce comptoir : c'est un
                 probleme de tarifs, pas un catalogue vide. Les confondre enverrait le caissier
                 chercher au mauvais endroit. */
              <div className="banner banner-warn">
                <b>Le catalogue contient {produits.length} produit{produits.length > 1 ? 's' : ''}, mais aucun n’est vendable ici.</b>
                {' '}Il leur manque un tarif au guichet, ou ils sont en rupture. Cela se corrige dans
                Catalogue&nbsp;: ce n’est pas la caisse qui est en panne.
              </div>
            ) : (
              <>
              {/* ⚠ LA BARRE N'APPARAÎT QUE S'IL Y A DES RAYONS. Aujourd'hui il n'y en a aucun en
                  base : une barre à un seul bouton « Tous » prendrait de la place et n'apprendrait
                  rien. Elle se crée toute seule le jour où l'exploitant déclare son premier rayon
                  dans Paramètres › Catalogue & référentiels. */}
              {rayons.length > 0 && (
                <div className="row caisse-rayons">
                  <button
                    type="button"
                    className={`btn sm${rayonActif === '' ? ' primary' : ' ghost'}`}
                    onClick={() => setRayonActif('')}
                  >
                    Tous
                  </button>
                  {rayons.map((r) => (
                    <button
                      key={r.id}
                      type="button"
                      className={`btn sm${rayonActif === r.id ? ' primary' : ' ghost'}`}
                      onClick={() => setRayonActif(r.id)}
                    >
                      {r.libelle}
                    </button>
                  ))}
                </div>
              )}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 10 }}>
                {produitsAffiches.map((p) => {
                  const vendable = estVendable(p) && !enPaiement
                  const raison = raisonNonVendable(p)
                  const epingle = favoris.includes(p.id)
                  return (
                    /* L'étoile est un bouton, et la tuile aussi : l'un ne peut pas contenir
                       l'autre. Ils sont donc frères dans une enveloppe positionnée — c'est ce qui
                       permet d'épingler sans déclencher la vente. */
                    <div className="prodcase" key={p.id}>
                      <button
                        className="prodtile"
                        disabled={!vendable}
                        onClick={() => choisirPuisAjouter(p)}
                        title={
                          enPaiement
                            ? 'Encaissement en cours'
                            : vendable
                              ? 'Ajouter au panier'
                              : expliqueNonVendable(p) || ''
                        }
                      >
                        <span className="pn">{libelleProduit(p)}</span>
                        {p.code && <span className="pc">{p.code}</span>}
                        {raison ? <span className="pw">{raison}</span> : <span className="pp">{euros(prixIndicatif(p))}</span>}
                      </button>
                      {peutEpingler && (
                        <button
                          type="button"
                          className={epingle ? 'prodfav on' : 'prodfav'}
                          aria-pressed={epingle}
                          aria-label={epingle ? 'Retirer des favoris' : 'Épingler en tête'}
                          title={
                            epingle
                              ? 'Épinglé sur ce comptoir — cliquez pour retirer'
                              : 'Épingler en tête. Les favoris appartiennent au comptoir : tout le monde les verra.'
                          }
                          onClick={() => basculerFavori(p.id)}
                        >
                          {epingle ? '★' : '☆'}
                        </button>
                      )}
                    </div>
                  )
                })}
              </div>
              {/* ⚠ « AUCUN PRODUIT DANS CE RAYON » N'EST PAS « AUCUN PRODUIT ». La grille vide
                  d'un filtre se lit comme un catalogue vide si on ne dit pas laquelle des deux
                  choses on regarde. */}
              {produitsAffiches.length === 0 && rayonActif !== '' && (
                <div className="empty">
                  Aucun produit vendable dans ce rayon.{' '}
                  <button type="button" className="lnk" onClick={() => setRayonActif('')}>
                    Voir tous les produits
                  </button>
                </div>
              )}

              {produitsMasques > 0 && (
                /* ⚠ RENDRE L'ABSENCE BRUYANTE. Sans cette ligne, un produit retire faute de tarif
                   se lit « il n'existe pas » — et le caissier va le chercher la ou il n'est pas. */
                <p className="hint">
                  {produitsMasques} produit{produitsMasques > 1 ? 's' : ''} du catalogue
                  {produitsMasques > 1 ? ' ne sont pas affichés' : ' n’est pas affiché'} ici&nbsp;:
                  {produitsMasques > 1 ? ' il leur manque' : ' il lui manque'} un tarif au guichet,
                  ou {produitsMasques > 1 ? 'ils sont' : 'il est'} en rupture de stock. Cela se
                  règle dans Catalogue.
                </p>
              )}
              </>
            )}
            <div className="hint">
              {enPaiement ? 'Encaissement en cours — finalisez ou abandonnez la vente.' : 'Cliquez un produit pour l\'ajouter · paiement scindé et rendu à l\'encaissement'}
            </div>
          </div>
        </section>
      </div>
      {/* Choix du tarif : n'apparait que si le produit en a plusieurs. Un produit a tarif unique
          s'ajoute en un clic, exactement comme avant — on ne fait payer le choix qu'a ceux qui en
          ont un. */}
      <Modal
        open={!!choixTarif}
        onClose={() => setChoixTarif(null)}
        titre={choixTarif ? `${libelleProduit(choixTarif.produit)} — quel tarif ?` : ''}
        taille="sm"
      >
        {choixTarif && (
          <div style={{ display: 'grid', gap: 8 }}>
            {choixTarif.grilles.map((g) => (
              <button
                key={g.id}
                type="button"
                className="prodtile"
                onClick={() => {
                  ajouter(choixTarif.produit, g)
                  setChoixTarif(null)
                }}
              >
                <span className="pn">{libelleTarif(g)}</span>
                {g.saison?.nom && <span className="pc">{g.saison.nom}</span>}
                <span className="pp">{euros(g.prix)}</span>
              </button>
            ))}
          </div>
        )}
      </Modal>

      <ChoixOptions
        ouvert={!!choixOptions}
        produit={choixOptions?.produit}
        typeTarifId={choixOptions?.grille?.typeTarif?.id}
        tarifLibelle={choixOptions ? libelleTarif(choixOptions.grille) : null}
        devis={choixOptions?.devis}
        selectionInitiale={choixOptions?.selection}
        ajustement={choixOptions?.remplace != null}
        onFermer={() => setChoixOptions(null)}
        onValider={(retenues, devis) => {
          ajouterLigne(choixOptions.produit, choixOptions.grille, retenues, devis, choixOptions.remplace ?? null)
          setChoixOptions(null)
        }}
      />

      <HistoriqueVentesModal
        open={historique}
        onClose={() => setHistorique(false)}
        droits={droits}
        onDuplicata={async (vente) => {
          setErreur(null)
          try {
            const info = await api.ticket(vente.id, 'duplicata')
            setTicket(construireTicket(info, [], null))
            setHistorique(false)
          } catch (e) {
            setErreur(e.message || "Le duplicata n'a pas pu être édité.")
          }
        }}
      />
      {modaleSession}
      {modaleClient}
    </div>
  )
}

// ── LA MODALE D'APPAIRAGE ──────────────────────────────────────────────────────────────────
//
// Elle s'ouvre SEULE, une fois la vente soldée, quand une ligne porte une carte multi-entrées.
// Elle ne bloque rien : fermer émet un support à code généré, exactement comme avant.
function AppairageModal({ etat, onValider, onIgnorer, busy }) {
  const [saisies, setSaisies] = useState({})

  if (!etat) return null

  const supports = etat.proposables
    .map((l) => ({ ligne: l.ligneServeurId, identifiant: (saisies[l.ligneServeurId] || '').trim() }))
    .filter((s) => s.identifiant !== '')

  return (
    <Modal open onClose={onIgnorer} titre="Appairer une carte" taille="md">
      <p className="sub" style={{ marginTop: 0 }}>
        Le paiement est encaissé. Scannez ou saisissez le numéro de la carte physique remise au
        client — ou fermez cette fenêtre.
      </p>

      {etat.proposables.map((l) => (
        <div className="field" key={l.ligneServeurId}>
          <label htmlFor={`app-${l.ligneServeurId}`}>{libelleProduit(l.produit)}</label>
          <input
            id={`app-${l.ligneServeurId}`}
            className="input"
            autoFocus={etat.proposables[0] === l}
            autoComplete="off"
            placeholder="Numéro de la carte"
            value={saisies[l.ligneServeurId] || ''}
            onChange={(e) => setSaisies((s) => ({ ...s, [l.ligneServeurId]: e.target.value }))}
          />
        </div>
      ))}

      {/* ⚠ DEUX OPERATIONS DIFFERENTES, ET SEUL L'IDENTIFIANT LES DISTINGUE. Le caissier doit
          savoir laquelle il déclenche avant de la déclencher : recharger la carte d'un client
          n'est pas lui en vendre une neuve, et ça ne se rattrape pas d'un clic. */}
      <div className="banner">
        <b>Une carte déjà connue est rechargée</b>, pas réémise&nbsp;: son solde d'entrées augmente
        et le client garde son support. Un numéro inédit crée une carte neuve portant ce numéro.
      </div>

      {etat.horsPortee.length > 0 && (
        <div className="banner banner-warn">
          <b>Non appairable ici&nbsp;:</b>{' '}
          {etat.horsPortee.map((l) => libelleProduit(l.produit)).join(', ')}. Un numéro de carte ne
          peut désigner qu'un seul support&nbsp;: séparez la ligne en quantités de 1 pour scanner
          chaque carte.
        </div>
      )}

      <div className="row" style={{ justifyContent: 'flex-end', gap: 'var(--esp-moyen)' }}>
        <button className="btn ghost" type="button" onClick={onIgnorer} disabled={busy}>
          Sans carte physique
        </button>
        <button
          className="btn primary"
          type="button"
          disabled={busy || supports.length === 0}
          onClick={() => onValider(supports)}
        >
          {busy ? 'Validation…' : 'Appairer et valider'}
        </button>
      </div>

      {/* ⚠ UNE FERMETURE N'ANNULE RIEN, ET IL FAUT LE DIRE. Un caissier qui ferme une fenêtre
          sans explication suppose avoir perdu la vente et la ressaisit — en double. */}
      <p className="sub" style={{ marginBottom: 0 }}>
        Fermer ne perd rien&nbsp;: la vente est validée et un support à code généré est émis, comme
        pour une vente sans carte physique.
      </p>
    </Modal>
  )
}

function PanneauPaiement({
  moyensDispo, moyenSel, setMoyenSel, moyenCourant, montant, setMontant,
  tpeSimule, setTpeSimule, reste, paiements, avis, busy, onRegler, onValider, onAbandon,
}) {
  const solde = parseFloat((reste || 0).toFixed ? reste.toFixed(2) : reste) || 0
  const paye = solde <= 0.0001

  return (
    <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 12 }}>
      <div className="reste-box">
        <span>Reste à payer</span>
        <span className={`num reste-val${paye ? ' paye' : ''}`}>{euros(Math.max(0, solde))}</span>
      </div>

      {paiements.length > 0 && (
        <div className="pay-list">
          {paiements.map((p, i) => (
            <div className="pay-row" key={i}>
              <span>{p.libelle || p.moyen}</span>
              <span className="num">{euros(p.montant)}{parseFloat(p.rendu || '0') > 0 ? ` (rendu ${euros(p.rendu)})` : ''}</span>
            </div>
          ))}
        </div>
      )}

      {avis && <div className="banner banner-error" style={{ margin: 0 }}>{avis}</div>}

      {!paye && (
        <>
          <div className="pay-moyens">
            {moyensDispo.map((m) => (
              <button
                key={m.code}
                className={`pay-chip${moyenSel === m.code ? ' on' : ''}`}
                onClick={() => setMoyenSel(m.code)}
                type="button"
              >
                {m.libelle}
              </button>
            ))}
          </div>

          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="mtt">Montant{moyenCourant?.autoriseRendu ? ' (rendu possible)' : ''}</label>
            <input
              id="mtt"
              className="input"
              type="number"
              step="0.01"
              min="0"
              value={montant}
              onChange={(e) => setMontant(e.target.value)}
              placeholder={Math.max(0, solde).toFixed(2)}
            />
          </div>

          {moyenCourant?.exigeReference && (
            <div className="field" style={{ margin: 0 }}>
              <label>Simulation TPE</label>
              <div className="seg">
                {['accepte', 'refuse', 'timeout'].map((s) => (
                  <button key={s} type="button" className={tpeSimule === s ? 'on' : ''} onClick={() => setTpeSimule(s)}>
                    {s === 'accepte' ? 'Accepté' : s === 'refuse' ? 'Refusé' : 'Timeout'}
                  </button>
                ))}
              </div>
            </div>
          )}

          <button className="btn primary lg" onClick={onRegler} disabled={busy}>
            {busy ? 'Traitement…' : `Régler ${moyenCourant?.libelle || ''}`}
          </button>
          {busy && moyenCourant?.exigeReference && (
            <div className="hint" style={{ margin: 0, textAlign: 'center' }}>
              Transaction en cours au terminal de paiement… (quelques secondes)
            </div>
          )}
        </>
      )}

      {paye && (
        <button className="btn primary lg" onClick={onValider} disabled={busy}>
          {/* LE BOUTON NE PROMET PLUS D'IMPRIMER, PARCE QU'IL N'EN SAIT RIEN.
              « Valider & imprimer » était juste tant que l'écran imprimait dans tous les cas. Le
              ticket ne sort désormais tout seul qu'au-dessus du seuil du point de vente ; en
              dessous il se propose, et sur une vente gratuite il ne sort pas. Le caissier lit ce
              bouton juste avant de le presser : lui annoncer une impression qui n'aura pas lieu
              lui ferait chercher un ticket dans l'imprimante. */}
          {busy ? 'Validation…' : 'Valider la vente'}
        </button>
      )}

      <button className="btn ghost sm" onClick={onAbandon} disabled={busy} style={{ alignSelf: 'center' }}>
        Abandonner la vente
      </button>
    </div>
  )
}

// LA FIN DE VENTE : CE QU'ON PROPOSE, ET CE QU'ON REFUSE DE PROMETTRE.
//
// ⚠ LES BOUTONS D'ENVOI SONT DESACTIVES, ET CE N'EST PAS UN OUBLI.
//
// `TicketProcessor` accepte `mode: "renvoyer"` avec un canal `email` ou `sms`, et rend
// `renvoye: true`. Mais le processeur ne contient NI expediteur, NI passerelle SMS, NI evenement :
// verifie ligne a ligne le 29/08, il ne fait que retourner le booleen. Et `MAILER_DSN` vaut
// `null://null`, de sorte que meme un envoi ecrit ne partirait nulle part.
//
// Un bouton actif ici afficherait donc « Ticket envoye » pour un courriel jamais compose. C'est le
// mensonge le plus cher du lot : le client repart sans rien, le caissier croit l'avoir servi, et
// personne ne s'en apercoit avant la reclamation. On garde les boutons — Maxime les a demandes, et
// les effacer ferait oublier la demande — mais ils disent leur etat.
function FinDeVente({ info, onAfficher, onBillet, onSansTicket }) {
  // Une vente a zero euro ne sort pas de ticket, et ce n'est pas une question de seuil : une entree
  // offerte ou un lot d'invitations faisait sortir un ticket a 0 € que le client jette.
  if (info.venteGratuite) {
    return (
      <div className="card" style={{ marginTop: 12 }}>
        <div className="card-b">
          <div className="nm">Vente enregistrée — aucun ticket</div>
          <p className="hint" style={{ marginTop: 6 }}>
            Le total est de 0 € : il n’y a rien à justifier au client. La vente est bien
            enregistrée et comptabilisée — c’est le papier qu’on ne sort pas, pas l’opération.
          </p>
          {/* ⚠ PAS DE TICKET NE VEUT PAS DIRE RIEN À REMETTRE (R5). Un ticket justifie un
              PAIEMENT ; un billet ouvre un ACCÈS. Une entrée offerte n'a rien à justifier, et le
              client a quand même besoin de quoi passer la porte. */}
          <div className="ticket-actions">
            <button className="btn primary sm" type="button" onClick={onBillet}>Afficher le billet</button>
            <button className="btn ghost sm" type="button" onClick={onSansTicket}>Fermer</button>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="card" style={{ marginTop: 12 }}>
      <div className="card-b">
        <div className="nm">Souhaitez-vous un ticket ?</div>
        <p className="hint" style={{ marginTop: 6 }}>
          Le montant est en dessous du seuil d’impression de ce point de vente : le ticket ne sort
          pas tout seul. Il reste éditable ici, et depuis l’historique des ventes.
        </p>

        <div className="row" style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
          <button className="btn primary" type="button" onClick={onAfficher}>Afficher le ticket</button>
          {/* « Sans ticket » fermait sans rien remettre. Le client repart quand même avec son
              accès : c'est le billet, sans aucun montant dessus (R5). */}
          <button className="btn" type="button" onClick={onBillet}>Le billet seul</button>
          <button className="btn ghost" type="button" onClick={onSansTicket}>Ni l’un ni l’autre</button>
        </div>

        {info.renvoiPropose ? (
          <>
            <div className="fiche-sec" style={{ marginTop: 14 }}>L’envoyer au client</div>
            <div className="row" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button className="btn sm" type="button" disabled>Par courriel</button>
              <button className="btn sm" type="button" disabled>Par SMS</button>
            </div>
            <p className="hint">
              <b>Aucun envoi n’est branché aujourd’hui</b> — ni courriel, ni SMS. Le serveur accepte
              la demande sans l’exécuter : un bouton actif annoncerait un envoi qui n’a pas lieu.
              Ils s’activeront quand un expéditeur sera configuré.
            </p>
          </>
        ) : (
          <p className="hint" style={{ marginTop: 10 }}>
            Aucun client n’est rattaché à cette vente : il n’y a pas d’adresse ni de numéro où
            envoyer le ticket. Rattachez un client avant de valider pour pouvoir le lui envoyer.
          </p>
        )}
      </div>
    </div>
  )
}

// LE BILLET SEUL — ce qu'on remet quand il n'y a pas de ticket de caisse (R5).
//
// ⚠ AUCUN MONTANT N'Y FIGURE, ET C'EST LE POINT. Un ticket de caisse justifie un paiement ; un
// billet ouvre un accès. Y remettre les prix en ferait un ticket dégradé — deux documents qui se
// ressemblent, dont un seul est opposable, et personne pour savoir lequel fait foi.
//
// Il porte donc ce qu'il faut pour entrer : le produit, le numéro, le code du support.
function BilletSeul({ ticket }) {
  // ⚠ UNE VENTE PEUT N'OUVRIR AUCUN ACCÈS — une boisson, un article de boutique. Il n'y a alors
  // pas de billet à remettre, et le dire vaut mieux que de tendre un papier vide. On ne montre pas
  // un cadre avec un trou à la place du code.
  if (!ticket?.codeSupport) {
    return (
      <div className="card">
        <div className="card-b">
          <div className="nm">Aucun billet à remettre</div>
          <p className="hint">
            Cette vente n’ouvre aucun accès&nbsp;: il n’y a pas de billet à imprimer. La vente est
            enregistrée, et le ticket reste éditable depuis l’historique des ventes.
          </p>
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="ticket doc-imprimer">
        <div className="th">
          <span className="ok">✓</span>
          <div>
            <b>Billet</b>
            <div className="tnum">{ticket.numero}</div>
          </div>
        </div>
        <div className="tb">
          {ticket.lignes.map((l, i) => (
            <div className="trow" key={i}>
              {/* La quantité et le libellé, jamais le prix. */}
              <span>{l.quantite} × {l.libelle}</span>
            </div>
          ))}
          <div className="tqr">
            <Qr value={ticket.codeSupport} size={84} title={`QR billet ${ticket.numero}`} />
            <div>
              <span className="mono" style={{ fontSize: 10.5, wordBreak: 'break-all' }}>{ticket.codeSupport}</span>
            </div>
          </div>
        </div>
      </div>

      <div className="ticket-actions noprint">
        <button className="btn ghost sm" type="button" onClick={() => window.print()}>
          Imprimer le billet
        </button>
      </div>
    </>
  )
}

function TicketVente({ ticket }) {
  return (
    <>
      {/* ⚠ `doc-imprimer` FAIT SORTIR CE TICKET SEUL SUR LA FEUILLE, et sans lui il ne sortait
          RIEN DU TOUT : la regle `@media print` de `styles.css` masquait tout l'ecran et ne
          revelait que la facture. Un ticket affiche, un Ctrl+P, une feuille blanche — sans erreur.

          ⚠ CE N'EST PAS UN SECOND FORMAT. Le papier A4 porte exactement ce bloc, c'est-a-dire ce
          que `TicketProcessor` a rendu : memes lignes, meme numero, meme mention DUPLICATA. Il n'y
          a pas de gabarit A4 separe qui pourrait diverger en silence du format contraint NF525. */}
      <div className="ticket doc-imprimer">
      <div className="th">
        <span className="ok">✓</span>
        <h3>Vente encaissée</h3>
      </div>
      <div className="tb">
        <div className="tnum">Ticket {ticket.numero}</div>
        {ticket.lignes.map((l, i) => (
          <div key={i}>
          <div className="trow">
            <span>{l.quantite} × {l.libelle}</span>
            <span className="num">
              {euros(l.montant != null ? l.montant : parseFloat(l.pu || '0') * l.quantite)}
            </span>
          </div>
          {l.options.map((o, j) => (
            <div className="trow" key={`o${j}`} style={{ paddingLeft: 14, opacity: 0.75, fontSize: 13 }}>
              <span>· {o.libelle}</span>
              <span className="num">{o.montant != null ? euros(o.montant) : ''}</span>
            </div>
          ))}
          </div>
        ))}
        {ticket.duplicata && (
          <div className="trow" style={{ color: 'var(--warn)' }}>
            <span><b>DUPLICATA</b> — ce ticket a déjà été édité.</span>
          </div>
        )}
        {ticket.detailIndisponible && (
          <div className="trow" style={{ color: 'var(--warn)' }}>
            <span>Le détail des lignes n'a pas pu être relu. Le total ci-dessous fait foi.</span>
          </div>
        )}
        {ticket.ecartDetail && (
          <div className="trow" style={{ color: 'var(--warn)' }}>
            <span>
              Le détail ci-dessus ne fait pas le total : des remises, options ou promotions
              s'appliquent. <b>Le montant dû est le total.</b>
            </span>
          </div>
        )}
        <div className="trow tt">
          <span>Total</span>
          <span className="num">{euros(ticket.total)}</span>
        </div>
        {ticket.paiements.map((p, i) => (
          <div className="trow" key={`p${i}`}>
            <span>Règlement · {p.libelle || p.moyen}</span>
            <span className="num">{euros(p.montant)}</span>
          </div>
        ))}
        {ticket.paiements.some((p) => parseFloat(p.rendu || '0') > 0) && (
          <div className="trow">
            <span>Rendu</span>
            <span className="num">
              {euros(ticket.paiements.reduce((s, p) => s + (parseFloat(p.rendu || '0') || 0), 0))}
            </span>
          </div>
        )}
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 14, paddingTop: 14, borderTop: '1px solid var(--line)' }}>
          {ticket.codeSupport && (
            <Qr value={ticket.codeSupport} size={84} title={`QR billet ${ticket.numero}`} />
          )}
          <div className="hint" style={{ margin: 0 }}>
            {ticket.codeSupport ? (
              <>Billet + QR édités · support appairé
                <br />
                <span className="mono" style={{ fontSize: 10.5, wordBreak: 'break-all' }}>{ticket.codeSupport}</span>
              </>
            ) : (
              'Billet édité · aucun support QR sur cette vente'
            )}
          </div>
        </div>
        </div>
      </div>

      {/* Les commandes vivent HORS du `.doc-imprimer` : dedans, elles s'imprimeraient avec lui.
          `noprint` est une ceinture de plus, pour le cas ou ce bloc migrerait un jour. */}
      <div className="ticket-actions noprint">
        <button className="btn ghost sm" type="button" onClick={() => window.print()}>
          Imprimer le ticket
        </button>
      </div>
    </>
  )
}
