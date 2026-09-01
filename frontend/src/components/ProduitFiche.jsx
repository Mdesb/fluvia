import { useMemo, useEffect, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import { libelleProduit, prixIndicatif, euros, statutProduit, sansTarifConnu } from '../api/produit.js'
import Modal from './Modal.jsx'
import Tabs from './Tabs.jsx'
// Rendu Markdown en éléments React, jamais en HTML injecté. Écrit pour la boutique
// publique ; réutilisé ici pour que la description s'affiche EXACTEMENT comme elle
// s'affichera devant un client, et pour ne pas inventer un second format.
import Markdown from '../public/components/Markdown.jsx'
import { humaniser, mot } from '../api/vocabulaire.js'
import ZonesAccesProduit from './ZonesAccesProduit.jsx'
import TarifsProduit from './TarifsProduit.jsx'

// Fiche produit détaillée — même niveau de détail que la fiche 360° client, en modale (D13 : la
// modale est le défaut, créer un écran est l'exception ; consulter un produit depuis sa liste ne
// justifie ni un espace de travail durable, ni un lien partageable).
//
// La liste porte déjà `produit:read` + `produit:list` : l'essentiel est donc affichable tout de
// suite, sans attendre. L'appel de détail n'ajoute que le bloc comptable (`produit:compta`) et les
// options — on affiche donc immédiatement ce qu'on sait, et on complète. Une modale qui tourne une
// seconde sur un fond vide alors qu'on avait déjà 80 % de la réponse est une seconde perdue à chaque
// ouverture.

const CANAUX_PRODUIT = [
  { valeur: 'guichet', libelle: 'Au guichet' },
  { valeur: 'en_ligne', libelle: 'En ligne' },
  { valeur: 'borne', libelle: 'Sur borne' },
]

// Les trois règles de produit constaté d'avance du socle (`Offre\Enum\ReglePca`), dites en clair :
// le code brut « etalement » ne dit pas ce qui est étalé ni pourquoi.
const REGLES_PCA = {
  aucune: 'Aucune — le chiffre d’affaires est acquis à la vente',
  etalement: 'Étalement — réparti sur la durée de validité',
  consommation: 'À la consommation — acquis au fur et à mesure des entrées',
}

// LA FICHE PRODUIT EST UNE PAGE, PLUS UNE MODALE -- demande de Maxime, 29/08, formulee deux fois
// en deux minutes : << je pense que pour le produit on devrait faire pareil que pour le client. >>
//
// Ce n'est pas un changement d'emballage. Une modale se ferme, donc elle ne peut pas porter d'URL,
// donc on ne peut ni la partager, ni y revenir apres une expiration de session. Et elle interdisait
// une fenetre par-dessus : le commentaire de `TarifsProduit` disait << une modale dans une modale est
// pire que le formulaire >>, ce qui obligeait a rendre les tarifs en ligne. En page, la contrainte
// disparait.
//
// La modale de comptabilite ecrite plus tot aujourd'hui est DEPLACEE, pas reecrite : elle s'ouvre
// desormais au-dessus d'une page, ce qui est le cas normal.
// Une relation sérialisée arrive tantôt en IRI nu, tantôt en objet réduit. Les deux formes se
// présentent selon l'opération : on lit l'une et l'autre plutôt que de parier.
function idDeRef(ref) {
  if (!ref) return null
  if (typeof ref === 'string') return ref.split('/').pop()
  return ref.id || String(ref['@id'] || '').split('/').pop() || null
}

// LA DURÉE REVIENT DÉVELOPPÉE, ET C'EST UN PIÈGE D'ALLER-RETOUR.
//
// `dureeValidite: "P1D"` est accepté à l'écriture ; à la relecture le serveur rend
// `P0Y0M1DT0H0M0S`. Un formulaire qui réécrirait cette chaîne telle quelle irait bien, mais un
// formulaire qui la lirait comme un nombre de jours sans la comprendre écrirait n'importe quoi.
//
// On n'expose donc que les JOURS — la seule unité qu'une durée de validité de billet utilise en
// pratique — et on rend `null` quand la valeur porte autre chose : mieux vaut un champ vide et un
// avertissement qu'un champ qui affiche « 0 » pour six mois.
function joursDepuisIntervalle(valeur) {
  if (!valeur || typeof valeur !== 'string') return ''
  const m = /^P(\d+)Y(\d+)M(\d+)DT(\d+)H(\d+)M(\d+)S$/.exec(valeur)
    || /^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?$/.exec(valeur)
  if (!m) return ''
  const [annees, mois, jours, heures, minutes, secondes] = m.slice(1).map((x) => Number(x || 0))
  if (annees || mois || heures || minutes || secondes) return ''
  return jours ? String(jours) : ''
}

// Une durée que le champ « jours » ne sait pas représenter : on le dit plutôt que de l'écraser.
function dureeNonExprimableEnJours(valeur) {
  return !!valeur && typeof valeur === 'string' && joursDepuisIntervalle(valeur) === ''
    && !/^P0Y0M0DT0H0M0S$/.test(valeur)
}

// La description est multilingue, comme le libellé : `{ fr: '…' }`, pas une chaîne. Un composant
// qui lirait `p.description` directement afficherait « [object Object] ».
function descriptionFr(p) {
  const d = p?.description
  if (!d) return ''
  if (typeof d === 'string') return d
  return d.fr || Object.values(d)[0] || ''
}

// Un produit associé arrive en IRI nu ou en objet réduit : on ne sait donc pas toujours son nom.
// On le retrouve dans la liste du catalogue quand elle est là, et on affiche l'identifiant sinon —
// jamais un blanc, qui se lirait comme « produit sans nom ».
function nomProduitAssocie(assoc, catalogue) {
  if (assoc && typeof assoc === 'object' && (assoc.libelle || assoc.nom)) {
    return libelleProduit(assoc)
  }
  const id = idDeRef(assoc)
  const connu = (catalogue || []).find((x) => String(x.id) === String(id))
  return connu ? libelleProduit(connu) : (id || '—')
}

// Les trois axes de catégories (RG-M1-05), indépendants : un produit porte au plus une valeur
// par axe. L'axe comptable est le seul qui ait un effet sur les écritures.
const AXES = [['comptable', 'Axe comptable'], ['marketing', 'Axe marketing'], ['rayon', 'Rayon']]

/**
 * Le mode, en mots de l'utilisateur.
 *
 * ⚠ « Obligatoire » n'est pas une nuance d'affichage : un complément obligatoire dont le produit
 * devient invendable rend la vente du PARENT impossible. Le libellé doit dire la conséquence, pas
 * traduire l'énumération.
 */
function libelleMode(mode) {
  if (mode === 'required') return 'Obligatoire — la vente refuse sans lui'
  if (mode === 'optional') return 'Proposé'
  return 'Suggéré'
}

export default function ProduitFiche({
  produit,
  // Sert UNIQUEMENT à prévenir avant qu'une fiche ne sorte du périmètre de celui qui l'édite —
  // voir l'avertissement des sites de commercialisation.
  etabActif,
  peutModifier = false,
  peutModifierCompta = false,
  onModifie,
  // Les zones d'accès ne se règlent pas avec les droits de l'Offre : ouvrir une porte n'est pas
  // modifier un prix. La section porte donc ses propres droits (`acces.lire` / `acces.gerer`).
  droits = [],
  // Les capacités ACTIVES de l'établissement (`me.capacitesActives`), qui décident des onglets.
  //
  // ⚠ Le défaut est la liste VIDE, donc le jeu minimal : Présentation et Vente. Un défaut
  // « tout afficher » aurait rendu la composition invisible tant qu'aucun appelant ne passe la
  // prop — l'écran aurait paru marcher, et n'aurait rien composé.
  capacites = [],
}) {
  const [edition, setEdition] = useState(null)
  const [editionCompta, setEditionCompta] = useState(null)
  const [enregistrement, setEnregistrement] = useState(false)
  // LA FICHE PORTE DEUX MÉTIERS, ET ILS NE SE LISENT PAS DANS LE MÊME ÉTAT D'ESPRIT.
  //
  // Demande de Maxime : « il doit y avoir une partie WYSIWYG et une autre config, ça peut faire
  // l'objet d'onglets différents ». L'ordre d'avant était administratif — tarif, canaux, stock,
  // tarifs, zones, options, diffusion, comptabilité, PHOTO en dernier, après le taux de TVA. Sur
  // une fiche produit, l'image est la première chose qu'on regarde.
  //
  // L'onglet vit en état local et non dans l'URL : c'est une vue d'un même objet, pas une
  // navigation. Le retour au catalogue et l'adresse de la fiche, eux, sont dans l'URL.
  const [vueFiche, setVueFiche] = useState('vitrine')

  // ── LES ONGLETS SE COMPOSENT, ILS NE SONT PAS UNE LISTE FIXE ─────────────────────────────────
  //
  // Le controle d'acces, la comptabilite et le stock sont trois MODULES SEPARES : un exploitant
  // peut n'en avoir aucun, ou les trois. Un onglet fixe « Acces & comptabilite » serait a moitie
  // vide chez qui n'a que l'un des deux, et mentirait sur ce que le produit sait faire.
  //
  // ⚠ ABSENT, JAMAIS GRISE. Un onglet grise fait chercher ce qui manque et invite a « debloquer »
  // ce qui n'est pas bloque ; un onglet absent ne pose aucune question. Meme regle que les actions
  // de statut, plus haut dans ce module.
  const onglets = useMemo(() => {
    const liste = [['vitrine', 'Présentation'], ['vente', 'Vente']]
    if (capacites.includes('agenda')) liste.push(['agenda', 'Agenda'])
    if (capacites.includes('controle_acces')) liste.push(['acces', 'Accès'])
    if (capacites.includes('stock')) liste.push(['stock', 'Stock'])
    if (capacites.includes('comptabilite')) liste.push(['compta', 'Comptabilité'])
    return liste
  }, [capacites])

  // ⚠ SANS CE REPLI, LA FICHE PEUT SE RENDRE VIDE. `capacitesActives` depend de l'etablissement
  // ACTIF : en changer pendant qu'on est sur « Comptabilité » laisserait `vueFiche` sur un onglet
  // qui n'existe plus, et aucun bloc ne rendrait rien — un ecran blanc, sans erreur.
  const vueConnue = onglets.some(([cle]) => cle === vueFiche)
  const vue = vueConnue ? vueFiche : 'vitrine'

  // ALLER A UNE SECTION DEPUIS LA LIGNE COMPACTE. Les deux cibles vivent dans l'onglet « Vente ».
  //
  // ⚠ LE DEFILEMENT ATTEND QUE REACT AIT RENDU L'ONGLET : avant la validation du rendu, l'ancre
  // n'existe pas dans le document et `getElementById` rend `null`. Deux `requestAnimationFrame`
  // imbriques garantissent qu'on passe apres.
  //
  // ⚠ Et `scroll-margin-top` (styles.css) decale l'arret sous la barre collante de 62 px — sans
  // elle, la section visee arrive DESSOUS. Trouve en cliquant, pas en lisant.
  function allerA(ancre) {
    setVueFiche('vente')
    requestAnimationFrame(() => requestAnimationFrame(() => {
      const cible = document.getElementById(ancre)
      if (!cible) return
      const doux = !window.matchMedia('(prefers-reduced-motion: reduce)').matches
      cible.scrollIntoView({ behavior: doux ? 'smooth' : 'auto', block: 'start' })
    }))
  }

  const [detail, setDetail] = useState(null)
  // Référentiels du bloc Diffusion. Chargés une fois par fiche, et leur absence n'empêche pas de
  // modifier le reste : `Promise.allSettled`, jamais `all`.
  const [etablissements, setEtablissements] = useState([])
  const [categories, setCategories] = useState([])
  const [tousProduits, setTousProduits] = useState([])
  const [liaisons, setLiaisons] = useState([])
  const [complements, setComplements] = useState([])
  const [ajoutComplement, setAjoutComplement] = useState({ produit: '', mode: 'suggested', quantite: 1 })

  // ⚠ CES DEUX GESTES ECRIVENT IMMEDIATEMENT, hors du cycle « Enregistrer / Annuler » de la fiche.
  // Les complements sont des entites a part : les faire passer par le formulaire ferait qu'un lien
  // ajoute puis annule resterait pose, et « Annuler » cesserait de vouloir dire ce qu'il dit.
  async function ajouterLeComplement() {
    if (!ajoutComplement.produit) return
    try {
      await api.ajouterComplement(
        produitId,
        ajoutComplement.produit,
        ajoutComplement.mode,
        ajoutComplement.quantite,
      )
      setComplements(membres(await api.complementsDeProduit(produitId)))
      setAjoutComplement({ produit: '', mode: 'suggested', quantite: 1 })
      setErreur(null)
    } catch (e) {
      setErreur(e.message || "Le complément n'a pas pu être ajouté.")
    }
  }

  async function retirerLeComplement(lien) {
    // On demande confirmation seulement pour « obligatoire » : c'est le seul mode dont le retrait
    // change ce qui peut etre vendu. Confirmer les trois habituerait a cliquer sans lire.
    if (lien.mode === 'required'
      && !window.confirm(
        'Retirer ce complément obligatoire ?\n\nLa vente du produit ne l’exigera plus.',
      )) {
      return
    }
    try {
      await api.retirerComplement(lien.id)
      setComplements(membres(await api.complementsDeProduit(produitId)))
      setErreur(null)
    } catch (e) {
      setErreur(e.message || "Le complément n'a pas pu être retiré.")
    }
  }
  const [valeurs, setValeurs] = useState({}) // groupeId -> valeurs
  // Le produit n'est pas commercialisable sur l'établissement actif : le guichet ne l'aurait pas.
  const [horsSite, setHorsSite] = useState(false)
  // ⚠ TROIS ETATS, PAS DEUX : l'echec de lecture des options n'est pas une reponse.
  const [optionsIllisibles, setOptionsIllisibles] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)

  const produitId = produit?.id

  useEffect(() => {
    Promise.allSettled([api.etablissements(), api.categories(), api.produits()]).then(([e, c, pr]) => {
      setEtablissements(e.status === 'fulfilled' ? membres(e.value) : [])
      setCategories(c.status === 'fulfilled' ? membres(c.value) : [])
      setTousProduits(pr.status === 'fulfilled' ? membres(pr.value) : [])
    })
  }, [])

  useEffect(() => {
    if (!produitId) return undefined
    let annule = false

    setDetail(null)
    setEdition(null)
    setLiaisons([])
    setValeurs({})
    setHorsSite(false)
    setOptionsIllisibles(null)
    setErreur(null)
    setChargement(true)
    ;(async () => {
      try {
        // ON DEMANDE AU GUICHET CE QUE LE GUICHET AFFICHERA, PLUTÔT QUE DE LE RECONSTITUER.
        //
        // Cette section s'annonce « Aperçu de ce que le guichet affichera pour ce produit ». Elle le
        // reconstituait à partir de deux collections filtrées — les rattachements du produit, puis les
        // valeurs de chaque groupe — soit 1+N requêtes, et **une seconde implémentation de la règle
        // d'éligibilité**. Un aperçu qui recalcule ce qu'il prétend refléter ne diverge pas le jour où
        // on l'écrit : il diverge au premier correctif appliqué à un seul des deux.
        //
        // `GET /produits/{id}/options-disponibles` est **la réponse même du guichet** : `actif`,
        // restriction d'établissement (RG-OPT-07), tri d'affichage, et le cloisonnement vérifié côté
        // serveur. Une requête, et l'aperçu devient fidèle par construction au lieu de l'être par
        // ressemblance.
        //
        // Au passage, il n'emprunte aucun filtre de collection — donc aucun des deux pièges observés
        // le 27/08 sur `SearchFilter` (identifiant nu → collection entière ; IRI → collection vide).
        // Le guichet répond 404 quand le produit n'appartient PAS à l'établissement actif. Ce n'est pas
        // une panne à signaler en rouge : c'est le seul contrôle de la chaîne qui vérifie réellement
        // l'appartenance, et sa réponse est une information à afficher telle quelle.
        const [d, dispo, comps] = await Promise.all([
          api.produit(produitId),
          // ⚠ ON RETIENT LE STATUT AU LIEU DE CONCLURE. `catch(() => 'hors-site')` transformait
          // N'IMPORTE QUEL echec -- 500, coupure reseau, refus de droit -- en une affirmation sur
          // le perimetre du produit. Seul un 404 dit quelque chose ici, et encore : voir plus bas.
          api.optionsDisponibles(produitId).catch((e) => ({ __echec: true, statut: e.status || null })),
          // Un echec ici ne doit pas priver de la fiche : on perd la liste des complements, pas le
          // produit. Le tableau vide est distinct de « aucun complement » seulement dans ce commentaire,
          // et c'est assumé — l'ecran ne promet rien sur la disponibilite de ce bloc.
          api.complementsDeProduit(produitId).then(membres).catch(() => []),
        ])
        if (annule) return
        setDetail(d)
        setComplements(comps)

        // ⚠ << AUCUN SITE >> VEUT DIRE << TOUS >>, ET L'ECRAN L'AFFIRMAIT A L'ENVERS.
        //
        // `PerimetreProduitExtension` traite un produit sans etablissement comme le SOCLE PARTAGE
        // -- son en-tete le dit en toutes lettres, et le formulaire de cette fiche aussi :
        // << Aucun site coche signifie qu'il reste visible partout : c'est la liste qui restreint,
        // pas l'inverse. >> L'ecran enoncait donc la regle a un endroit et la contredisait a deux
        // autres.
        //
        // Mesure du 31/08 sur GI-ONE : les HUIT produits publies ont `etablissements: []`, et
        // `GET /produits/{id}/options-disponibles` rend 404 << Produit introuvable >> pour chacun
        // -- exactement comme pour un identifiant invente (temoin verifie). Le frontal ne peut donc
        // pas distinguer << hors de mon perimetre >> de << n'existe pas >> de << cette route ignore
        // la regle du socle >>. Il n'affirme plus rien dans ce cas.
        const echec = dispo && dispo.__echec === true
        const socle = ((d?.etablissements || []).length === 0)
        // On n'affirme le hors-perimetre que si le produit DECLARE des sites et que le serveur a
        // repondu 404. Sinon on dit qu'on n'a pas pu lire, ce qui est le fait.
        const vraimentHorsSite = echec && dispo.statut === 404 && !socle
        setHorsSite(vraimentHorsSite)
        setOptionsIllisibles(echec && !vraimentHorsSite ? { statut: dispo.statut, socle } : null)

        const groupes = echec ? [] : (dispo?.groupes || [])
        setLiaisons(
          groupes.map((g) => ({
            id: g.optionProduit,
            obligatoire: g.obligatoire,
            groupeOption: { id: g.groupeOption, libelle: g.libelle, modeSelection: g.modeSelection },
          })),
        )
        setValeurs(Object.fromEntries(groupes.map((g) => [g.groupeOption, g.valeurs || []])))
      } catch (e) {
        if (!annule) setErreur(e.message || 'Détail indisponible.')
      } finally {
        if (!annule) setChargement(false)
      }
    })()

    return () => {
      annule = true
    }
  }, [produitId])

  if (!produit) return null

  const p = detail || produit
  const st = statutProduit(p)
  const grilles = p.grilles || []
  const base = prixIndicatif(p)

  function ouvrirEdition() {
    setEdition({
      libelle: libelleProduit(p),
      canaux: Array.isArray(p.canaux) ? [...p.canaux] : [],
      couleurCaisse: p.couleurCaisse || '',
      noteInterne: p.noteInterne || '',
      // LE BLOC DIFFUSION ÉTAIT AFFICHÉ ET RIEN NE LE CHANGEAIT.
      //
      // Conséquence exacte, relevée sur l'audioguide : la fiche affiche « Ce produit n'est pas
      // commercialisé sur l'établissement actif : le guichet ne l'affichera pas ici, options
      // comprises » — et l'écran qui énonce le problème ne porte pas le geste qui le résout.
      // Les trois champs sont pourtant en écriture côté serveur (`produit:write`), vérifié par un
      // aller-retour réel avant d'écrire ce formulaire.
      description: descriptionFr(p),
      // `produitsAssocies` n'est plus edite ici : remplace par les complements, qui sont des
      // entites a part. Le champ reste en base jusqu'a son retrait par allaccess-73.

      etablissements: (p.etablissements || []).map(idDeRef).filter(Boolean),
      categories: (p.categories || []).map(idDeRef).filter(Boolean),
      jours: joursDepuisIntervalle(p.dureeValidite),
    })
  }

  async function enregistrer(e) {
    e.preventDefault()
    setErreur(null)
    setEnregistrement(true)
    try {
      await api.majProduit(produitId, {
        // Le libelle est multilingue cote serveur : on ne remplace que le francais, sinon une
        // traduction existante disparaitrait sans que personne ne l'ait demande.
        libelle: { ...(p.libelle && typeof p.libelle === 'object' ? p.libelle : {}), fr: edition.libelle.trim() },
        canaux: edition.canaux,
        couleurCaisse: edition.couleurCaisse || null,
        noteInterne: edition.noteInterne.trim() || null,
        // Multilingue comme le libellé : on ne remplace que le français, sinon une traduction
        // existante disparaîtrait sans que personne ne l'ait demandé.
        description: edition.description.trim()
          ? { ...(p.description && typeof p.description === 'object' ? p.description : {}), fr: edition.description.trim() }
          : null,
        // ⚠ On n'envoie plus `produitsAssocies` : rien ne le lisait cote serveur, et l'ecran
        // ecrivait donc dans le vide avec un enregistrement qui reussissait. Ne plus l'envoyer
        // n'efface pas les valeurs existantes ; elles partiront avec la table.

        etablissements: edition.etablissements.map((id) => `/api/etablissements/${id}`),
        categories: edition.categories.map((id) => `/api/categories/${id}`),
        // Le serveur rend la durée en forme développée (`P0Y0M1DT0H0M0S`) et accepte la forme
        // courte : on renvoie `P<n>D`, ou `null` pour « sans limite ».
        dureeValidite: edition.jours === '' ? null : `P${Number(edition.jours)}D`,
      })
      const rafraichi = await api.produit(produitId)
      setDetail(rafraichi)
      setEdition(null)
      onModifie?.()
    } catch (err) {
      setErreur(err.message || "L'enregistrement n'a pas abouti.")
    } finally {
      setEnregistrement(false)
    }
  }

  if (edition) {
    return (
      <section className="card"><div className="card-h"><h3>Modifier — {libelleProduit(p)}</h3></div><div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <form onSubmit={enregistrer}>
          <div className="field">
            <label htmlFor="pr-lib">Nom du produit *</label>
            <input
              id="pr-lib"
              className="input"
              required
              value={edition.libelle}
              onChange={(e) => setEdition((s) => ({ ...s, libelle: e.target.value }))}
            />
            <div className="hint">C'est ce que verront le vendeur en caisse et le client en ligne.</div>
          </div>

          <div className="field">
            <label>Où ce produit est vendu</label>
            <div style={{ display: 'flex', gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
              {CANAUX_PRODUIT.map((c) => (
                <label key={c.valeur} style={{ display: 'flex', alignItems: 'center', gap: 'var(--esp-serre)', fontWeight: 400 }}>
                  <input
                    type="checkbox"
                    checked={edition.canaux.includes(c.valeur)}
                    onChange={(ev) =>
                      setEdition((s) => ({
                        ...s,
                        canaux: ev.target.checked
                          ? [...s.canaux, c.valeur]
                          : s.canaux.filter((x) => x !== c.valeur),
                      }))
                    }
                  />
                  {c.libelle}
                </label>
              ))}
            </div>
            <div className="hint">
              Si vous ne cochez rien, le produit ne sera vendable nulle part, même une fois publié.
            </div>
          </div>

          <div className="field">
            <label htmlFor="pr-coul">Couleur en caisse</label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--esp-normal)' }}>
              <input
                id="pr-coul"
                type="color"
                value={edition.couleurCaisse || '#cccccc'}
                onChange={(e) => setEdition((s) => ({ ...s, couleurCaisse: e.target.value }))}
                style={{ width: 48, height: 34, padding: 2 }}
              />
              {edition.couleurCaisse && (
                <button
                  className="btn ghost sm"
                  type="button"
                  onClick={() => setEdition((s) => ({ ...s, couleurCaisse: '' }))}
                >
                  Retirer la couleur
                </button>
              )}
            </div>
            <div className="hint">Aide le vendeur à repérer le produit d'un coup d'œil. Facultatif.</div>
          </div>

          <div className="field">
            <label htmlFor="pr-note">Note interne</label>
            <textarea
              id="pr-note"
              className="input"
              rows={3}
              value={edition.noteInterne}
              onChange={(e) => setEdition((s) => ({ ...s, noteInterne: e.target.value }))}
            />
            <div className="hint">Visible de votre équipe seulement. Jamais affichée au client.</div>
          </div>

          <div className="fiche-sec" style={{ marginTop: 'var(--esp-bloc)' }}>Vitrine</div>

          <div className="field">
            <label htmlFor="pr-desc">Description</label>
            <textarea
              id="pr-desc"
              className="input"
              rows={6}
              value={edition.description}
              placeholder="Ce qu’on voit, ce qu’on fait, combien de temps ça dure."
              onChange={(e) => setEdition((st) => ({ ...st, description: e.target.value }))}
            />
            {/* ⚠ MISE EN FORME LÉGÈRE, ET SURTOUT PAS DE HTML.
                Le texte est saisi par l'exploitant, donc « de confiance » — sauf que la confiance
                porte sur la PERSONNE, pas sur le CONTENU : un passage collé depuis un traitement
                de texte ou une IA transporte du balisage que personne n'a voulu. Le rendu produit
                des éléments React (`public/components/Markdown.jsx`) et n'interprète jamais de
                HTML : l'injection devient structurellement impossible au lieu d'être improbable.
                On ne l'assainit pas, on ne se pose pas la question. */}
            <p className="hint">
              Mise en forme légère&nbsp;: <code>**gras**</code>, <code>*italique*</code>, listes
              avec un tiret, titres avec <code>#</code>. Le HTML n’est pas interprété — il
              s’afficherait tel quel.
            </p>
            {edition.description.trim() && (
              <div className="card" style={{ marginTop: 'var(--esp-normal)' }}>
                <div className="card-b">
                  <div className="sub" style={{ marginBottom: 'var(--esp-serre)' }}>Aperçu</div>
                  <Markdown texte={edition.description} />
                </div>
              </div>
            )}
          </div>

          {/* ⚠ LES COMPLÉMENTS S'ENREGISTRENT IMMÉDIATEMENT, pas au « Enregistrer » de la fiche.
              Ce sont des entités à part, avec leurs propres routes. Mélanger les deux temps ferait
              qu'un lien ajouté puis « annulé » resterait posé — un bouton qui ne défait pas ce
              qu'il annonce. */}
          <div className="field">
            <label htmlFor="pr-complements">Produits complémentaires</label>
            {complements.length === 0 ? (
              <div className="empty">
                Aucun complément. C’est ce qui permet de proposer le casier avec l’entrée, ou
                l’audioguide avec la visite.
              </div>
            ) : (
              <table className="tbl" id="pr-complements">
                <thead>
                  <tr>
                    <th>Produit proposé</th>
                    <th>Comment</th>
                    <th className="num">Qté</th>
                    <th className="num">Retirer</th>
                  </tr>
                </thead>
                <tbody>
                  {complements.map((c) => (
                    <tr key={c.id}>
                      <td>{nomProduitAssocie(c.complement, tousProduits)}</td>
                      <td>{libelleMode(c.mode)}</td>
                      <td className="num">{c.defaultQuantity}</td>
                      <td className="num">
                        <button
                          className="btn ghost sm"
                          type="button"
                          onClick={() => retirerLeComplement(c)}
                        >
                          Retirer
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}

            <div className="field">
              <label htmlFor="pr-comp-ajout">Ajouter un complément</label>
              <select
                id="pr-comp-ajout"
                className="select"
                value={ajoutComplement.produit}
                onChange={(ev) => setAjoutComplement((st) => ({ ...st, produit: ev.target.value }))}
              >
                <option value="">Choisir un produit…</option>
                {tousProduits
                  .filter((x) => String(x.id) !== String(produitId))
                  .filter((x) => !complements.some((c) => idDeRef(c.complement) === String(x.id)))
                  .map((x) => (
                    <option key={x.id} value={x.id}>{libelleProduit(x)}</option>
                  ))}
              </select>
              <select
                className="select"
                aria-label="Comment le proposer"
                value={ajoutComplement.mode}
                onChange={(ev) => setAjoutComplement((st) => ({ ...st, mode: ev.target.value }))}
              >
                <option value="optional">Proposé — l’agent le voit, il choisit</option>
                <option value="suggested">Suggéré — coché d’avance, l’agent peut retirer</option>
                <option value="required">Obligatoire — la vente refuse sans lui</option>
              </select>
              <input
                className="input"
                type="number"
                min="1"
                aria-label="Quantité proposée par défaut"
                value={ajoutComplement.quantite}
                onChange={(ev) => setAjoutComplement((st) => ({ ...st, quantite: Number(ev.target.value) || 1 }))}
              />
              <button
                className="btn"
                type="button"
                disabled={!ajoutComplement.produit}
                onClick={ajouterLeComplement}
              >
                Ajouter
              </button>
              <div className="hint">
                ⚠ « Obligatoire » refuse la vente du produit tant que le complément n’est pas
                vendable — s’il passe en rupture, c’est ce produit-ci qu’on ne peut plus vendre.
              </div>
            </div>

            <p className="hint">
              Un complément est un produit entier : il fait sa propre ligne, avec sa TVA et son
              tarif. Pour un simple supplément sans TVA propre, utilisez plutôt une option.
            </p>
          </div>

          <div className="fiche-sec" style={{ marginTop: 'var(--esp-bloc)' }}>Diffusion</div>

          <div className="field">
            <label htmlFor="pr-etabs">Sites de commercialisation</label>
            <div id="pr-etabs" style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
              {etablissements.map((e) => (
                <label key={e.id} style={{ display: 'flex', gap: 'var(--esp-normal)', alignItems: 'center' }}>
                  <input
                    type="checkbox"
                    checked={edition.etablissements.includes(e.id)}
                    onChange={(ev) => setEdition((st) => ({
                      ...st,
                      etablissements: ev.target.checked
                        ? [...st.etablissements, e.id]
                        : st.etablissements.filter((x) => x !== e.id),
                    }))}
                  />
                  <span>{e.nom || e.libelle || e.id}</span>
                </label>
              ))}
            </div>
            {/* C'EST CE CHAMP QUI REND SOLUBLE LE MESSAGE AFFICHÉ PLUS HAUT.
                « Ce produit n'est pas commercialisé sur l'établissement actif » n'avait aucun
                geste correspondant : on constatait, on ne pouvait pas agir. */}
            <div className="hint">
              Un produit ne s’affiche au guichet que sur les sites cochés ici. <b>Aucun site coché
              signifie qu’il reste visible partout</b> : c’est la liste qui restreint, pas
              l’inverse. C’est ce que dit le message d’avertissement de la fiche, et c’est ici qu’il
              se corrige.
            </div>
            {/* ⚠ COCHER DES SITES SANS Y METTRE LE SIEN FAIT DISPARAÎTRE LA FICHE À L'ENREGISTREMENT.
                Mesuré, pas supposé : en attachant l'audioguide à GI-ONE depuis Piscine A, le PATCH
                rend 200 et la relecture qui suit rend 404 — l'extension de périmètre a exclu le
                produit dans l'intervalle. L'écran affichait « Not Found », un message qui ne dit ni
                ce qui s'est passé ni que l'enregistrement a RÉUSSI.
                On prévient donc avant, plutôt que d'expliquer après : la fiche ne sera plus
                joignable depuis cet établissement, et il faudra basculer sur l'un des sites cochés
                pour y revenir. */}
            {edition.etablissements.length > 0 && etabActif
              && !edition.etablissements.includes(etabActif) && (
              <div className="banner banner-warn">
                <b>Vous n’avez pas coché l’établissement où vous êtes.</b> L’enregistrement
                réussira, puis ce produit sortira de votre périmètre&nbsp;: la fiche ne sera plus
                accessible d’ici, et il faudra basculer sur l’un des sites cochés pour la rouvrir.
              </div>
            )}
          </div>

          <div className="field">
            <label htmlFor="pr-cats">Catégories</label>
            <div id="pr-cats" style={{ display: 'grid', gap: 'var(--esp-normal)' }}>
              {AXES.map(([axe, libelleAxe]) => {
                const duAxe = categories.filter((c) => c.axe === axe)
                if (duAxe.length === 0) return null
                const choisie = edition.categories.find((id) => duAxe.some((c) => c.id === id)) || ''
                return (
                  <label key={axe} style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
                    <span className="sub">{libelleAxe}</span>
                    <select
                      className="input"
                      value={choisie}
                      onChange={(ev) => setEdition((st) => ({
                        ...st,
                        // UNE SEULE VALEUR PAR AXE (RG-M1-05) : choisir remplace la précédente du
                        // même axe au lieu de s'y ajouter. Un produit à deux catégories comptables
                        // s'imputerait sur deux comptes.
                        categories: [
                          ...st.categories.filter((id) => !duAxe.some((c) => c.id === id)),
                          ...(ev.target.value ? [ev.target.value] : []),
                        ],
                      }))}
                    >
                      <option value="">— aucune —</option>
                      {duAxe.map((c) => <option key={c.id} value={c.id}>{c.libelle || c.nom}</option>)}
                    </select>
                  </label>
                )
              })}
            </div>
            <div className="hint">
              Une seule catégorie par axe. L’axe <b>comptable</b> décide du compte de produit :
              sans lui, la vente n’est pas comptabilisée et ressort en anomalie à la clôture.
            </div>
          </div>

          <div className="field">
            <label htmlFor="pr-duree">Durée de validité (jours)</label>
            <input
              id="pr-duree"
              className="input"
              type="number"
              min="0"
              value={edition.jours}
              placeholder="sans limite"
              onChange={(e) => setEdition((st) => ({ ...st, jours: e.target.value }))}
            />
            {dureeNonExprimableEnJours(p.dureeValidite) ? (
              <div className="banner banner-warn">
                La durée enregistrée (<code>{p.dureeValidite}</code>) n’est pas exprimable en jours.
                Ce champ est resté vide pour ne pas l’écraser par erreur — <b>enregistrer avec un
                nombre de jours la remplacera</b>, et le laisser vide la supprimera.
              </div>
            ) : (
              <div className="hint">
                Combien de temps le billet reste utilisable après l’achat. Vide = sans limite.
              </div>
            )}
          </div>

          <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end', marginTop: 'var(--esp-large)' }}>
            <button className="btn" type="button" onClick={() => setEdition(null)}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enregistrement}>
              {enregistrement ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>
        </form>
      </div></section>
    )
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="fiche-ident" style={{ marginBottom: 'var(--esp-large)' }}>
        <div>
          <div className="fiche-nom">{libelleProduit(p)}</div>
          <div className="sub">
            {p.code || '—'} · {p.type?.libelle || humaniser(p.typeCode)}
          </div>
        </div>
        <span className={`badge ${st.ton}`} title={st.aide} style={{ marginLeft: 'auto' }}>
          {st.libelle}
        </span>
        {peutModifier && (
          <button className="btn ghost sm" type="button" onClick={ouvrirEdition} style={{ marginLeft: 'var(--esp-normal)' }}>
            Modifier
          </button>
        )}
      </div>

      {/* ── LA LIGNE COMPACTE ────────────────────────────────────────────────────────────────
          Trois tuiles hautes remplacees par une ligne de sous-titre. Deux des trois valeurs se
          modifiaient PLUS BAS — `Tarif` dans la section Tarifs, `Vendu` dans Diffusion — et le
          bandeau le disait lui-meme (« ajoutez une grille dans Tarifs, plus bas »). On lisait, on
          descendait, on relisait, on modifiait : c'est ca, « faire deux fois ».

          Elles restent lisibles d'un coup d'oeil, mais elles MENENT desormais a leur section au
          lieu d'y renvoyer par une phrase.

          ⚠ L'ETAT N'EST PAS ICI, ET C'EST VOULU. Le badge de la ligne d'identite le porte deja —
          `statutProduit()` le passe en orange quand un produit publie n'a aucun tarif. Le remettre
          ici recreerait le doublon qu'on supprime, deplace d'un cran. */}
      <div className="fiche-ligne">
        <span className="fl-item">
          <span className="fl-lib">Tarif</span>
          {/* ⚠ `sansTarifConnu` rend `null` quand les grilles ne sont pas chargees, et on ne
              conclut RIEN d'un `null` : on n'ecrit « invendable » que sur un `true` franc. */}
          {p?.statut === 'publie' && sansTarifConnu(p) === true ? (
            <button
              type="button"
              className="fl-val alerte"
              onClick={() => allerA('prod-tarifs')}
              aria-label="Aucun tarif, produit invendable — aller à la section Tarifs"
            >
              aucun — invendable
            </button>
          ) : (
            <button
              type="button"
              className="fl-val"
              onClick={() => allerA('prod-tarifs')}
              aria-label={`Tarif indicatif ${euros(base)} — aller à la section Tarifs`}
            >
              {euros(base)}
            </button>
          )}
        </span>

        <span className="fl-sep" aria-hidden="true">·</span>

        <span className="fl-item">
          <span className="fl-lib">Vendu</span>
          <button
            type="button"
            className="fl-val"
            onClick={() => allerA('prod-diffusion')}
            aria-label="Canaux de vente — aller à la section Diffusion"
          >
            {(p.canaux || []).map(mot).join(', ') || 'aucun canal'}
          </button>
        </span>

        <span className="fl-sep" aria-hidden="true">·</span>

        {/* ⚠ NI SOULIGNE NI CLIQUABLE. Aucune section de cette fiche ne porte le stock — le module
            Stock le tient de son cote. Il n'y a donc nulle part ou aller, et un faux raccourci
            coute plus cher qu'une valeur sans raccourci. */}
        <span className="fl-item">
          <span className="fl-lib" title="Quantité disponible à la vente, tenue par le module Stock.">
            Stock
          </span>
          <span className="fl-val muet">
            {p.stock && typeof p.stock.disponibilite === 'number' ? p.stock.disponibilite : 'non suivi'}
          </span>
        </span>
      </div>

      <Tabs
        onglets={onglets}
        actif={vueFiche}
        onChange={setVueFiche}
      />

      {vue === 'vitrine' && (
        <>
          {/* LA PHOTO REMONTE EN PREMIER. Elle était en dernier, après le taux de TVA et la règle
              de comptabilisation — c'est-à-dire après tout ce qu'un client ne verra jamais. */}
          <PhotosProduit produitId={p.id} peutModifier={peutModifier} />

          <Section
            titre="Description"
            aide="Le texte que le visiteur lit avant d'acheter."
          >
            {descriptionFr(p) ? (
              <Markdown texte={descriptionFr(p)} />
            ) : (
              <div className="empty">
                Aucune description. C’est le texte qui donne envie&nbsp;: ce qu’on voit, ce qu’on
                fait, combien de temps ça dure.
              </div>
            )}
            {/* ⚠ ELLE N'EST AFFICHÉE NULLE PART AUJOURD'HUI, ET LE TAIRE SERAIT LE PIRE.
                `Produit::$description` est en lecture et en écriture depuis le début, et la
                boutique publique ne la lit pas — vérifié : le mot n'apparaît dans `src/public`
                que dans l'extraction d'un message d'erreur. Quelqu'un qui écrirait une belle
                description sans le savoir travaillerait pour personne. */}
            <p className="hint">
              <b>La boutique en ligne n’affiche pas encore ce texte.</b> Il est enregistré et
              s’affichera dès que la fiche publique le reprendra — mais aujourd’hui, personne ne le
              lit hors de cet écran.
            </p>
          </Section>

          <Section
            titre="Produits complémentaires"
            aide="Ce qu'on propose avec : le casier avec l'entrée piscine."
          >
            {complements.length === 0 ? (
              <div className="empty">
                Aucun complément. C’est ce qui permet de proposer le casier avec l’entrée, ou
                l’audioguide avec la visite.
              </div>
            ) : (
              <ul>
                {complements.map((c) => (
                  <li key={c.id}>
                    {nomProduitAssocie(c.complement, tousProduits)} — {libelleMode(c.mode)}
                    {c.defaultQuantity > 1 ? ` (×${c.defaultQuantity})` : ''}
                  </li>
                ))}
              </ul>
            )}
          </Section>
        </>
      )}

      {/* VENTE, premier bloc. Le second (Diffusion) est plus bas : entre les deux vivent les
          sections d'acces, qui ne rendent rien quand on est sur Vente — a l'ecran, Tarifs et
          Diffusion se suivent donc bien. */}
      {vue === 'vente' && (
      <>
      <Section titre="Tarifs" ancre="prod-tarifs" aide="Le prix de ce produit, par type de tarif et par période.">
        <TarifsProduit
          produit={p}
          grilles={grilles}
          peutModifier={peutModifier}
          onChange={async () => {
            setDetail(await api.produit(produitId))
            onModifie?.()
          }}
        />
      </Section>

      </>
      )}

      {/* ACCES — n'existe que si l'etablissement porte la capacite `controle_acces`. */}
      {vue === 'acces' && (
      <>
      <Section
        titre="Zones d'accès"
        aide="Les zones que ce produit ouvre aux tourniquets. Aucune zone déclarée = il les ouvre toutes."
      >
        <ZonesAccesProduit produitId={produitId} droits={droits} />
      </Section>

      <Section
        titre="Options"
        aide="Ce que le guichet affichera au moment de vendre ce produit."
      >
        {chargement && liaisons.length === 0 ? (
          <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
        ) : horsSite ? (
          // D54 : d'abord le fait sur la donnée, jamais un vide muet ni un rouge sans cause.
          <div className="empty">
            Ce produit n'est pas commercialisé sur l'établissement actif : le guichet ne l'affichera
            pas ici, options comprises.
          </div>
        ) : optionsIllisibles ? (
          <div className="banner banner-warn">
            {optionsIllisibles.socle ? (
              <>
                Les options n’ont pas pu être lues pour ce produit. <b>N’en concluez pas qu’il n’est
                pas vendable ici</b>&nbsp;: il n’est rattaché à aucun site, ce qui signifie
                <b> visible partout</b> — le catalogue et la caisse de cet établissement l’affichent.
                C’est cette lecture-là qui a échoué, pas la vente.
              </>
            ) : (
              <>Les options de ce produit n’ont pas pu être lues&nbsp;: ce cadre est vide parce que
                la lecture a échoué, pas parce qu’il n’y a pas d’option.</>
            )}
          </div>
        ) : liaisons.length === 0 ? (
          <div className="empty">Aucune option rattachée : le produit se vend tel quel.</div>
        ) : (
          <ApercuCaisse liaisons={liaisons} valeurs={valeurs} base={base} />
        )}
      </Section>

      </>
      )}

      {/* VENTE, second bloc — voir le commentaire du premier. */}
      {vue === 'vente' && (
      <>
      <Section titre="Diffusion" ancre="prod-diffusion">
        {/* ⚠ << — >> SE LIT << AUCUN >>, ET LA VALEUR SIGNIFIE << TOUS >>. C'est la liste qui
            restreint : un produit sans site coche est du socle, partage par tous les
            etablissements. Afficher un tiret ici faisait croire a un rattachement manquant, et
            invitait a << reparer >> ce qui n'etait pas casse. */}
        <Ligne
          libelle="Sites de commercialisation"
          valeur={(p.etablissements || []).length || 'Tous — aucun site coché, donc socle partagé'}
        />
        <Ligne libelle="Catégories" valeur={(p.categories || []).length || '—'} />
        <Ligne
          libelle="Durée de validité"
          valeur={p.dureeValidite || '—'}
          aide="Durée pendant laquelle le droit vendu reste utilisable."
        />
      </Section>

      {/* TROIS CHAMPS ÉCRIVABLES DEPUIS LE DÉBUT, AFFICHÉS ET JAMAIS PROPOSÉS.
          `PATCH /produits/{id}/compta` existe, protégée par `offre.modifier_compta`, et accepte
          `tauxTva`, `compteComptable` et `reglePca`. Le client ne l'appelait de nulle part : la
          fiche montrait les trois valeurs, et le formulaire « Modifier » n'offrait que le nom, les
          canaux, la couleur en caisse et la note interne.
          Montrer un réglage sans donner le bouton est pire que ne rien montrer : l'exploitant sait
          que ça existe et conclut que le logiciel ne le permet pas. Maxime l'a vu de lui-même.
          Bouton séparé, parce que le DROIT est séparé : `offre.modifier_compta` n'est pas
          `offre.modifier`. Qui peut renommer un produit ne peut pas forcément changer son compte. */}
      </>
      )}

      {/* COMPTABILITE — n'existe que si l'etablissement tient ses comptes ici. La note interne la
          suit : c'est le seul autre endroit de la fiche qui ne regarde ni le client ni la vente. */}
      {vue === 'compta' && (
      <>
      <Section titre="Comptabilité">
        {chargement && !detail ? (
          <div className="hint">Chargement…</div>
        ) : (
          <>
            <Ligne libelle="Compte comptable" valeur={p.compteComptable || '—'} />
            <Ligne libelle="Taux de TVA" valeur={p.tauxTva != null ? `${p.tauxTva} %` : '—'} />
            <Ligne
              libelle="Règle PCA"
              valeur={REGLES_PCA[p.reglePca] || p.reglePca || '—'}
              aide="Produit constaté d'avance : comment le chiffre d'affaires est étalé dans le temps."
            />
            {peutModifierCompta && (
              <button
                className="btn ghost sm"
                type="button"
                style={{ marginTop: 'var(--esp-normal)' }}
                onClick={() => setEditionCompta({
                  tauxTva: p.tauxTva != null ? String(p.tauxTva) : '',
                  compteComptable: p.compteComptable || '',
                  reglePca: p.reglePca || 'aucune',
                })}
              >
                Modifier la comptabilité
              </button>
            )}
          </>
        )}
      </Section>


      {p.noteInterne && (
        <Section titre="Note interne">
          <div className="hint">{p.noteInterne}</div>
        </Section>
      )}
      </>
      )}

      {/* STOCK — n'existe que si l'etablissement suit ses stocks. Ces valeurs n'etaient visibles
          nulle part ailleurs sur la fiche : la ligne du haut n'en montre que le nombre. */}
      {vue === 'stock' && (
        <Section titre="Stock" aide="Quantité disponible à la vente, tenue par le module Stock.">
          {!p.stock ? (
            <div className="hint">
              Ce produit n’est pas suivi en stock : il peut être vendu sans limite de quantité.
            </div>
          ) : (
            <>
              <Ligne
                libelle="Type de stock"
                valeur={p.stock.type === 'pool' ? 'Partagé (pool)' : 'Dédié à ce produit'}
                aide="Un stock dédié n'appartient qu'à ce produit ; un pool est partagé entre plusieurs."
              />
              <Ligne libelle="Disponible" valeur={String(p.stock.disponibilite ?? '—')} />
              {/* ⚠ ON NE LIT `pool.libelle` QUE SI LE POOL EST LA. `pool` est nullable, et une
                  lecture en profondeur sur `null` rendrait `undefined` — un tiret qui ne dirait
                  pas s'il n'y a pas de pool ou si on n'a pas su le lire. */}
              {p.stock.pool && (
                <>
                  <Ligne libelle="Pool" valeur={p.stock.pool.libelle || '—'} />
                  <Ligne
                    libelle="Disponible dans le pool"
                    valeur={String(p.stock.pool.disponibilite ?? '—')}
                    aide="Ce que le pool a en tout, tous produits confondus."
                  />
                </>
              )}
            </>
          )}
        </Section>
      )}

      {/* AGENDA — n'existe que si l'etablissement vend du date. */}
      {vue === 'agenda' && <AgendaProduit produitId={produitId} />}

      <ComptaProduitModal
        edition={editionCompta}
        onClose={() => setEditionCompta(null)}
        onEnregistre={async (corps) => {
          await api.majComptaProduit(produitId, corps)
          setDetail(await api.produit(produitId))
          setEditionCompta(null)
          onModifie?.()
        }}
      />
    </>
  )
}

// LE TAUX DE TVA D'UN PRODUIT EST UNE VALEUR, PAS UNE RELATION, ET ÇA CHANGE LE FORMULAIRE.
//
// `Produit::$tauxTva` est une colonne `decimal(5,2)` nullable — pas une clé vers `TauxTva`. Le
// produit ne « pointe » donc pas le référentiel : il recopie un pourcentage. On propose quand même
// la liste des taux déclarés, parce que saisir 20 à la main quand l'établissement a déclaré 20,00
// est le meilleur moyen de créer deux vérités ; mais on envoie bien la valeur, pas un identifiant.
//
// Et on part en CHAÎNE : la colonne est décimale, et le désérialiseur refuse un entier — c'est
// exactement le défaut qui rendait la création d'un taux de TVA impossible.
function ComptaProduitModal({ edition, onClose, onEnregistre }) {
  const [taux, setTaux] = useState([])
  const [valeurs, setValeurs] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!edition) return
    setValeurs(edition)
    setErreur(null)
  }, [edition])

  useEffect(() => {
    if (!edition) return undefined
    let annule = false
    api.tauxTvas()
      // Un référentiel illisible (droits comptables absents) ne doit pas fermer le formulaire :
      // la liste disparaît, la saisie libre reste.
      .then((r) => { if (!annule) setTaux(membres(r).filter((t) => t.actif !== false)) })
      .catch(() => { if (!annule) setTaux([]) })
    return () => { annule = true }
  }, [edition])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await onEnregistre({
        tauxTva: valeurs.tauxTva === '' ? null : String(valeurs.tauxTva),
        compteComptable: valeurs.compteComptable.trim() || null,
        reglePca: valeurs.reglePca,
      })
    } catch (err) {
      setErreur(err.message || "L'enregistrement n'a pas abouti.")
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={!!edition} onClose={onClose} titre="Comptabilité du produit" taille="md">
      {valeurs && (
        <form onSubmit={soumettre}>
          {erreur && <div className="banner banner-error">{erreur}</div>}

          <div className="field">
            <label htmlFor="pc-tva">Taux de TVA</label>
            <select
              id="pc-tva"
              className="input"
              value={valeurs.tauxTva}
              onChange={(e) => setValeurs((s) => ({ ...s, tauxTva: e.target.value }))}
            >
              <option value="">Aucun taux</option>
              {taux.map((t) => (
                <option key={t.id} value={String(t.taux)}>
                  {t.libelle} — {t.taux} %
                </option>
              ))}
              {/* Un produit peut porter un taux qui n'est plus au référentiel (masqué depuis).
                  Le retirer de la liste ferait perdre la valeur au premier enregistrement. */}
              {valeurs.tauxTva !== '' && !taux.some((t) => String(t.taux) === String(valeurs.tauxTva)) && (
                <option value={valeurs.tauxTva}>{valeurs.tauxTva} % (taux retiré du référentiel)</option>
              )}
            </select>
            <p className="hint">
              {taux.length === 0
                ? 'Aucun taux n’est déclaré pour cet établissement : renseignez-les dans Paramètres › Catalogue & référentiels.'
                : 'Le taux facturé sur ce produit, et celui qui remontera en comptabilité.'}
            </p>
          </div>

          <div className="field">
            <label htmlFor="pc-compte">Compte comptable</label>
            <input
              id="pc-compte"
              className="input mono"
              maxLength={32}
              value={valeurs.compteComptable}
              placeholder="706100"
              onChange={(e) => setValeurs((s) => ({ ...s, compteComptable: e.target.value }))}
            />
            <p className="hint">
              Le compte de produit sur lequel les ventes de cet article seront imputées. Saisie
              libre : le plan comptable n’est pas exposé à cet écran.
            </p>
          </div>

          <div className="field">
            <label htmlFor="pc-pca">Règle de produit constaté d’avance</label>
            <select
              id="pc-pca"
              className="input"
              value={valeurs.reglePca}
              onChange={(e) => setValeurs((s) => ({ ...s, reglePca: e.target.value }))}
            >
              {Object.entries(REGLES_PCA).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
            <p className="hint">
              Décide du moment où l’argent encaissé devient du chiffre d’affaires. Un abonnement
              annuel vendu en janvier ne se gagne pas en janvier.
            </p>
          </div>

          <div className="row" style={{ justifyContent: 'flex-end', gap: 'var(--esp-normal)', marginTop: 'var(--esp-large)' }}>
            <button type="button" className="btn" onClick={onClose}>Annuler</button>
            <button type="submit" className="btn primary" disabled={envoi}>
              {envoi ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

/* ------------------------------------------------------------------ Aperçu caisse */

// Le cœur de cette fiche, et la réponse à « je ne comprends rien aux options produit ».
//
// L'écran de paramétrage montre la MÉCANIQUE — rattacher un groupe, basculer un drapeau — et jamais
// le RÉSULTAT. Quelqu'un qui n'a pas écrit le modèle ne peut pas deviner ce qu'une option fait, ce
// que le client verra, ni ce que ça change au prix. On montre donc ici ce que le guichet affichera,
// avec le prix réellement atteint. Un exemple concret vaut mieux que trois définitions.
function ApercuCaisse({ liaisons, valeurs, base }) {
  const prixBase = typeof base === 'number' ? base : parseFloat(base)
  const exemple = calculExemple(liaisons, valeurs, prixBase)

  return (
    <>
      <div className="hint" style={{ marginBottom: 'var(--esp-normal)' }}>
        Aperçu de ce que le guichet affichera pour ce produit.
      </div>

      {liaisons.map((op) => {
        const g = op.groupeOption || {}
        const vals = valeurs[g.id] || []
        const multiple = g.modeSelection === 'multiple'

        return (
          <div key={op.id} className="card" style={{ marginBottom: 'var(--esp-normal)' }}>
            <div className="card-b">
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--esp-normal)', marginBottom: 'var(--esp-serre)' }}>
                <b>{g.libelle || 'Groupe'}</b>
                <span
                  className={`badge ${op.obligatoire ? 'good' : 'mut'}`}
                  title={
                    op.obligatoire
                      ? "Le vendeur ne pourra pas terminer la vente sans avoir choisi dans ce groupe."
                      : 'Le vendeur peut passer sans rien choisir.'
                  }
                >
                  {op.obligatoire ? 'choix obligatoire' : 'choix facultatif'}
                </span>
                <span className="sub">
                  {multiple ? 'plusieurs choix possibles' : 'un seul choix'}
                </span>
              </div>

              {vals.length === 0 ? (
                <div className="empty">
                  Ce groupe n'a aucune valeur active : rien ne s'affichera au guichet, et un groupe
                  obligatoire sans valeur bloquerait la vente.
                </div>
              ) : (
                <ul style={{ margin: 0, paddingLeft: 18 }}>
                  {vals.map((v) => (
                    <li key={v.id}>
                      {v.libelle} <span className="sub">{impactLisible(v)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        )
      })}

      {exemple != null && Number.isFinite(prixBase) && (
        <div className="banner">
          Exemple : {euros(prixBase)} de base, avec les choix obligatoires les moins chers →{' '}
          <b>{euros(exemple)}</b> au guichet.
        </div>
      )}
    </>
  )
}

// « +2,00 € » ou « +10 % » plutôt que « montant / 2.00 » : le libellé doit se lire comme il
// s'appliquera, pas comme il est stocké.
function impactLisible(v) {
  const n = parseFloat(v?.impactValeur)
  if (!Number.isFinite(n) || n === 0) return 'sans supplément'
  const signe = n > 0 ? '+' : '−'
  const abs = Math.abs(n)
  return v?.impactType === 'pourcentage' ? `${signe}${abs} %` : `${signe}${euros(abs)}`
}

// Prix atteint si le vendeur prend, dans chaque groupe obligatoire, la valeur la moins chère : c'est
// le PLANCHER réel du produit, et c'est le chiffre qu'un exploitant veut connaître — pas le tarif de
// base, qui n'est atteignable que si aucune option n'est obligatoire.
function calculExemple(liaisons, valeurs, prixBase) {
  if (!Number.isFinite(prixBase)) return null
  let total = prixBase
  let touche = false

  for (const op of liaisons) {
    if (!op.obligatoire) continue
    const vals = valeurs[op.groupeOption?.id] || []
    if (vals.length === 0) continue

    const impacts = vals.map((v) => {
      const n = parseFloat(v.impactValeur)
      if (!Number.isFinite(n)) return 0
      return v.impactType === 'pourcentage' ? (prixBase * n) / 100 : n
    })
    total += Math.min(...impacts)
    touche = true
  }

  return touche ? total : null
}

/* ------------------------------------------------------------------ Petits blocs */

/**
 * LES DATES AUXQUELLES CE PRODUIT EST PROPOSE.
 *
 * ⚠ « DATE » N'EST PAS UNE PROPRIETE DU PRODUIT. Un produit porte une `dureeValidite` — combien de
 * temps le billet reste utilisable — et rien qui ressemble a une date. Ce qui date un produit,
 * c'est ce qui POINTE VERS LUI : une `Exposition` (produit + dates + jauge). La Reservation, elle,
 * ne reference aucun produit — verifie, temoin positif a l'appui.
 *
 * Cet ecran MONTRE donc ces dates ; il n'en cree pas. En creer supposerait de decider ou vit la
 * date, ce qui est une decision de modele et pas d'ecran.
 *
 * ⚠ ET IL DISTINGUE TROIS SILENCES. « Aucune date », « je n'ai pas le droit de savoir » et « la
 * lecture a echoue » se ressemblent tous les trois a l'ecran s'ils rendent une liste vide — et le
 * premier est le seul qui soit une reponse.
 */
function AgendaProduit({ produitId }) {
  const [dates, setDates] = useState(null)
  const [refus, setRefus] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    let vivant = true
    api.expositionsDuProduit(produitId)
      .then((r) => { if (vivant) setDates(membres(r)) })
      .catch((e) => {
        if (!vivant) return
        // 403 : la lecture des expositions demande `musee.lire`, que ce lecteur n'a pas.
        if (e?.statut === 403 || /403/.test(String(e?.message))) setRefus(true)
        else setErreur(e?.message || 'Les dates n’ont pas pu être lues.')
      })
    return () => { vivant = false }
  }, [produitId])

  if (refus) {
    return (
      <Section titre="Agenda">
        <div className="hint">
          Vous n’avez pas le droit de lire l’agenda de ce produit — il peut en avoir un. Ce droit
          est celui du module Musée, pas celui du catalogue.
        </div>
      </Section>
    )
  }

  if (erreur) {
    return (
      <Section titre="Agenda">
        <div className="banner banner-error">{erreur}</div>
      </Section>
    )
  }

  return (
    <Section titre="Agenda" aide="Les dates auxquelles ce produit est proposé.">
      {dates === null && <div className="hint">Chargement…</div>}
      {dates !== null && dates.length === 0 && (
        <div className="hint">
          Ce produit n’est proposé à aucune date : il est vendable en permanence.
        </div>
      )}
      {(dates ?? []).map((d) => (
        <Ligne
          key={d.id}
          libelle={`${dateFr(d.dateDebut)} → ${dateFr(d.dateFin)}`}
          valeur={d.aJauge ? `jauge ${d.jaugeGlobale ?? '—'}` : 'sans jauge'}
        />
      ))}
    </Section>
  )
}

function dateFr(valeur) {
  const s = String(valeur || '').slice(0, 10)
  const [a, m, j] = s.split('-')
  return j ? `${j}/${m}/${a}` : '—'
}

function Section({ titre, aide, children, ancre }) {
  return (
    // `ancre` est facultative : seules les sections vers lesquelles la ligne compacte renvoie en
    // portent une. En donner une a toutes creerait des identifiants que rien n'utilise.
    <div id={ancre} style={{ marginTop: 'var(--esp-large)' }}>
      <div className="fiche-sec" title={aide}>{titre}</div>
      {children}
    </div>
  )
}

function Ligne({ libelle, valeur, aide }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--esp-large)', padding: '4px 0' }}>
      <span className="sub" title={aide}>{libelle}</span>
      <span>{valeur}</span>
    </div>
  )
}

// LA ZONE DE DÉPÔT — le seul point de style que Maxime ait signalé de lui-même.
//
// Ses mots, devant la fiche produit : « le choisir un fichier et que montre cette photo c'est
// horrible. » Le contrôle natif d'un navigateur affiche « Choisir un fichier · Aucun fichier
// choisi » dans la police du système, sans rapport avec le reste de l'écran, et ne dit RIEN de ce
// qu'il accepte. On apprend qu'un fichier est trop lourd après l'avoir téléversé.
//
// LES LIMITES SONT ÉCRITES DANS LA ZONE, PAS DANS LE MESSAGE D'ERREUR. C'est la moitié utile du
// changement : « JPEG, PNG, WebP ou AVIF · 2 Mo maximum » avant le dépôt évite l'aller-retour que
// le contrôle natif imposait.
//
// ⚠ LE FILTRE D'ICI EST UN CONFORT, PAS UNE GARDE, ET IL NE FAUT PAS LE PRENDRE POUR AUTRE CHOSE.
//
// `UploadProductPhotoProcessor` mesure le type RÉEL du fichier avec `getimagesize()` — il lit les
// octets au lieu de croire ce que le navigateur déclare — parce que cette photo est publiée en
// ligne. Ce que la zone refuse ici, elle le refuse pour épargner un téléversement inutile ; ce qui
// passerait quand même est arrêté au serveur. On ne déplace pas la vérification, on ajoute du
// confort par-dessus : une illusion de protection vaut moins qu'une absence de protection, parce
// qu'elle fait cesser de chercher.
const TYPES_PHOTO = ['image/jpeg', 'image/png', 'image/webp', 'image/avif']
const TAILLE_MAX_PHOTO = 2 * 1024 * 1024

function ZoneDepotPhoto({ fichier, onFichier, onRefus }) {
  const champ = useRef(null)
  const [survol, setSurvol] = useState(false)
  const [apercu, setApercu] = useState(null)

  // L'aperçu est une URL d'objet : sans révocation, chaque fichier essayé laisse ses octets en
  // mémoire jusqu'au rechargement de la page.
  useEffect(() => {
    if (!fichier) { setApercu(null); return undefined }
    const url = URL.createObjectURL(fichier)
    setApercu(url)
    return () => URL.revokeObjectURL(url)
  }, [fichier])

  function retenir(f) {
    if (!f) return
    if (!TYPES_PHOTO.includes(f.type)) {
      onRefus('Ce fichier n’est pas une image acceptée : il faut du JPEG, du PNG, du WebP ou de l’AVIF.')
      return
    }
    if (f.size > TAILLE_MAX_PHOTO) {
      // Les deux nombres passent par le meme formatage : « 2049 Ko » face a « 2 048 Ko » se lit
      // comme deux unites differentes, et fait douter de la comparaison au moment ou l'on
      // cherche justement a savoir de combien on depasse.
      const ko = (o) => Math.round(o / 1024).toLocaleString('fr-FR')
      onRefus(
        `Cette image pèse ${ko(f.size)} Ko ; la limite est de ${ko(TAILLE_MAX_PHOTO)} Ko. `
        + 'Redimensionnez-la avant de la déposer — au-delà, la boutique la ferait attendre à '
        + 'chaque visiteur.',
      )
      return
    }
    onFichier(f)
  }

  return (
    <div>
      <div
        className={survol ? 'depot survol' : 'depot'}
        onDragOver={(e) => { e.preventDefault(); setSurvol(true) }}
        onDragLeave={() => setSurvol(false)}
        onDrop={(e) => {
          e.preventDefault()
          setSurvol(false)
          retenir(e.dataTransfer.files?.[0])
        }}
      >
        {apercu ? (
          <div className="depot-choisi">
            <img src={apercu} alt="" className="depot-apercu" />
            <div style={{ minWidth: 0 }}>
              <div className="nm" style={{ overflowWrap: 'anywhere' }}>{fichier.name}</div>
              <div className="sub">{Math.round(fichier.size / 1024)} Ko</div>
              <div style={{ display: 'flex', gap: 'var(--esp-normal)', marginTop: 'var(--esp-serre)' }}>
                <button className="btn ghost sm" type="button" onClick={() => champ.current?.click()}>
                  Changer
                </button>
                <button className="btn ghost sm" type="button" onClick={() => onFichier(null)}>
                  Retirer
                </button>
              </div>
            </div>
          </div>
        ) : (
          <button className="depot-vide" type="button" onClick={() => champ.current?.click()}>
            <span className="depot-signe" aria-hidden="true">▣</span>
            <span className="depot-titre">Déposez une photo ici, ou cliquez pour la choisir</span>
            <span className="sub">JPEG, PNG, WebP ou AVIF · 2 Mo maximum</span>
          </button>
        )}
      </div>

      {/* Le champ natif reste dans la page : c'est lui qui ouvre le sélecteur du système, et c'est
          par lui que la zone reste utilisable au clavier et par un lecteur d'écran. Il est masqué,
          pas supprimé — le remplacer par un faux bouton aurait retiré l'accès clavier. */}
      <input
        ref={champ}
        type="file"
        className="depot-champ"
        accept={TYPES_PHOTO.join(',')}
        onChange={(e) => { retenir(e.target.files?.[0]); e.target.value = '' }}
      />
    </div>
  )
}

/**
 * LES PHOTOS DU PRODUIT — ce que le visiteur verra de lui.
 *
 * Deux choses que l'écran dit tout haut, parce que les taire ferait conclure à une panne :
 *
 *   - **le texte alternatif est exigé.** Une image sans alternative est invisible pour un lecteur
 *     d'écran et pour un moteur de recherche ; pour un établissement public, le RGAA en fait un
 *     critère. Le champ est demandé au téléversement, seul moment où quelqu'un sait ce que montre
 *     la photo ;
 *   - **une photo ne s'affiche en boutique que si le produit y est vendu.** Publié, et au canal
 *     « en ligne ». Sans ce rappel, l'exploitant téléverse, ne voit rien, et cherche du côté du
 *     fichier.
 *
 * La première photo est celle que la boutique montre. Les suivantes attendent une galerie.
 */
function PhotosProduit({ produitId, peutModifier }) {
  const [etat, setEtat] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [alt, setAlt] = useState('')
  const [fichier, setFichier] = useState(null)

  useEffect(() => {
    let vivant = true
    if (!produitId) return undefined
    api.photosProduit(produitId)
      .then((r) => { if (vivant) setEtat(r) })
      .catch(() => { if (vivant) setEtat(null) })

    return () => { vivant = false }
  }, [produitId])

  async function recharger() {
    try {
      setEtat(await api.photosProduit(produitId))
    } catch {
      setEtat(null)
    }
  }

  async function televerser() {
    setBusy(true)
    setErr(null)
    try {
      await api.televerserPhotoProduit(produitId, fichier, alt.trim())
      setAlt('')
      setFichier(null)
      await recharger()
    } catch (e) {
      setErr(e.message || 'La photo n’a pas pu être ajoutée.')
    } finally {
      setBusy(false)
    }
  }

  async function retirer(id) {
    setBusy(true)
    setErr(null)
    try {
      await api.supprimerPhotoProduit(id)
      await recharger()
    } catch (e) {
      setErr(e.message || 'La photo n’a pas pu être retirée.')
    } finally {
      setBusy(false)
    }
  }

  if (!etat) return null

  const photos = etat.photos || []

  return (
    <Section titre="Photos" aide="La première est celle qu’affiche la boutique en ligne.">
      {!etat.visiblePubliquement && (
        <div className="hint" style={{ marginBottom: 'var(--esp-normal)' }}>
          Ce produit n’est pas vendu en ligne : ses photos ne s’afficheront nulle part tant qu’il
          n’est pas <strong>publié</strong> et ouvert au canal <strong>en ligne</strong>.
        </div>
      )}

      {photos.length > 0 && (
        <div style={{ display: 'flex', gap: 'var(--esp-normal)', flexWrap: 'wrap', marginBottom: 'var(--esp-normal)' }}>
          {photos.map((photo, i) => (
            <figure key={photo.id} style={{ margin: 0, width: 132 }}>
              <img
                src={photo.url}
                alt={photo.altText}
                style={{
                  width: 132,
                  height: 92,
                  objectFit: 'cover',
                  display: 'block',
                  border: '1px solid var(--line)',
                }}
              />
              <figcaption className="sub" style={{ marginTop: 'var(--esp-serre)', lineHeight: 1.3 }}>
                {i === 0 && <strong>Affichée en boutique — </strong>}
                {photo.altText}
              </figcaption>
              {peutModifier && (
                <button
                  className="btn ghost sm"
                  type="button"
                  disabled={busy}
                  style={{ marginTop: 'var(--esp-serre)' }}
                  onClick={() => retirer(photo.id)}
                >
                  Retirer
                </button>
              )}
            </figure>
          ))}
        </div>
      )}

      {err && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-normal)' }}>{err}</div>}

      {peutModifier && (
        <div style={{ display: 'grid', gap: 'var(--esp-normal)' }}>
          <ZoneDepotPhoto
            fichier={fichier}
            onFichier={(f) => { setErr(null); setFichier(f) }}
            onRefus={setErr}
          />

          {/* LE TEXTE ALTERNATIF NE SE DEMANDE QU'UNE FOIS LA PHOTO DÉPOSÉE.
              On décrit ce qu'on voit, pas ce qu'on va choisir : demandé au-dessus d'un sélecteur
              vide, le champ appelait une description de mémoire — et c'est ainsi qu'on obtient
              « photo du produit », qui ne décrit rien pour celui qui ne voit pas l'image. */}
          {fichier && (
            <div className="field" style={{ marginBottom: 0 }}>
              <label htmlFor="ph-alt">
                Que montre cette photo ? — lu à voix haute aux visiteurs malvoyants
              </label>
              <input
                id="ph-alt"
                className="input"
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
                placeholder="Ce qu’on voit sur l’image, en une phrase"
                maxLength={160}
              />
              {/* L'exemple d'origine — « Le bassin nordique au coucher du soleil » — s'affichait
                  tel quel sur un audioguide. Un exemple qui contredit le produit qu'on regarde
                  s'apprend comme une consigne de remplissage, pas comme un modèle. */}
              <p className="hint">
                Décrivez la scène, pas le produit : « Bassin extérieur chauffé, vu depuis la
                terrasse » plutôt que « photo du produit ». C’est ce que le visiteur malvoyant
                entendra à la place de l’image.
              </p>
            </div>
          )}

          <div>
            <button
              className="btn primary sm"
              type="button"
              disabled={busy || !fichier || alt.trim().length < 3}
              onClick={televerser}
            >
              {busy ? 'Ajout…' : 'Ajouter la photo'}
            </button>
          </div>
        </div>
      )}

      {photos.length === 0 && !peutModifier && <div className="hint">Aucune photo.</div>}
    </Section>
  )
}
