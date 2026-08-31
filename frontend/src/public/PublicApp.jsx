import { useCallback, useEffect, useMemo, useState } from 'react'
import { boutique, panierStore, clientTokenStore, vitrineStore } from './api/boutiqueClient.js'
import PublicHeader from './components/PublicHeader.jsx'
import PublicFooter from './components/PublicFooter.jsx'
import PageLegale from './pages/PageLegale.jsx'
import { Chargement, Erreur } from './components/Etats.jsx'
import Configuration from './pages/Configuration.jsx'
import Vitrine from './pages/Vitrine.jsx'
import FicheProduit from './pages/FicheProduit.jsx'
import Panier from './pages/Panier.jsx'
import Tunnel from './pages/Tunnel.jsx'
import MonCompte from './pages/MonCompte.jsx'

// Racine de la ZONE PUBLIQUE (front client final), totalement indépendante de l'app staff :
// pas d'AppShell, pas de JWT staff, pas de X-Etablissement. Routage léger par état (`vue`),
// dans le même esprit que l'app staff qui route par `onglet`.
export default function PublicApp() {
  const [vitrineId, setVitrineId] = useState(() => lireVitrineInitiale())
  // ⚠ « Pas de boutique » et « on ne sait pas encore » ne sont pas la meme chose, et les confondre
  // fait clignoter l'ecran de configuration a chaque ouverture. Tant que l'hote n'a pas tranche, on
  // charge ; on ne propose pas de choisir.
  const [hoteTranche, setHoteTranche] = useState(() => !!lireVitrineInitiale())
  const [vitrine, setVitrine] = useState(null)
  const [catalogue, setCatalogue] = useState(null)
  const [chargement, setChargement] = useState(!!vitrineId)
  const [erreur, setErreur] = useState(null)

  const [panier, setPanier] = useState(null)
  const [busyPanier, setBusyPanier] = useState(false)
  const [erreurPanier, setErreurPanier] = useState(null)
  const [metaCreneaux, setMetaCreneaux] = useState({})

  const [connecte, setConnecte] = useState(!!clientTokenStore.get())
  const [route, setRoute] = useState({ vue: 'vitrine' })

  const langue = vitrine?.langues?.[0] || catalogue?.langues?.[0] || 'fr'

  // ⚠ LE DOCUMENT DOIT ANNONCER LA LANGUE QU'IL REND, ET `index.html` PORTE `fr` EN DUR.
  //
  // Une vitrine qui déclare `en` rend un contenu anglais dans un document annoncé français : une
  // synthèse vocale le lit avec la prononciation française. Le texte est juste, la voix est
  // inintelligible — et c'est un défaut qu'on n'entend jamais en relisant du code.
  //
  // On pose l'attribut sur l'élément racine plutôt que sur un conteneur : c'est celui que les
  // technologies d'assistance consultent, et le seul qui vaille pour la page entière.
  useEffect(() => {
    if (typeof document !== 'undefined' && langue) {
      document.documentElement.lang = langue
    }
  }, [langue])

  // Métadonnées produits (libellé, timed-entry…) indexées par id, pour enrichir panier & tunnel.
  const metaProduits = useMemo(() => {
    const m = {}
    for (const p of catalogue?.produits || []) m[p.produit] = p
    return m
  }, [catalogue])

  const onNaviguer = useCallback((r) => {
    setErreurPanier(null)
    setRoute(typeof r === 'string' ? { vue: r } : r)
    if (typeof window !== 'undefined') window.scrollTo({ top: 0, behavior: 'auto' })
  }, [])

  // Charge la vitrine (branding) + le catalogue.
  const chargerVitrine = useCallback(async (id) => {
    setChargement(true)
    setErreur(null)
    try {
      const [v, cat] = await Promise.all([
        // ⚠ CET APPEL ECHOUE QUAND L'ADRESSE PORTE UN SLUG, ET C'EST STRUCTUREL.
        //
        // `GET /boutique/vitrines/{id}` est une operation ITEM : API Platform convertit `{id}` en
        // `Uuid` AVANT d'atteindre le fournisseur, et rend 404 sur `piscine-a` sans que le code du
        // fournisseur soit jamais execute. Le catalogue, lui, est une operation collection a chemin
        // personnalise : la valeur y reste une chaine, donc le slug passe.
        //
        // On ne force pas le passage : le catalogue rend DEJA le logo, les couleurs et les langues.
        // Un second appel pour les memes donnees serait un aller-retour de plus a chaque ouverture de
        // boutique, sur le chemin critique du client.
        boutique.vitrine(id).catch(() => null),
        boutique.catalogue(id),
      ])
      // Le catalogue fait foi pour l'identite visuelle ; l'appel item n'ajoute que ce qu'il est seul
      // a porter. Sans ce repli, une boutique ouverte sur `/b/piscine-a` perdrait son logo et ses
      // couleurs -- elle s'afficherait en blanc, sans erreur, et personne ne saurait pourquoi.
      setVitrine(v || (cat ? { logo: cat.logo, couleurs: cat.couleurs, langues: cat.langues, slug: cat.slug } : null))
      setCatalogue(cat)
    } catch (e) {
      setErreur(e?.message || "Cette boutique est introuvable ou indisponible.")
      setCatalogue(null)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    if (vitrineId) {
      vitrineStore.set(vitrineId)
      chargerVitrine(vitrineId)
    }
  }, [vitrineId, chargerVitrine])

  // D104 — AUCUNE FORME D'URL : on demande au serveur ce que dit l'HÔTE.
  //
  // `piscine-ville.fluvia-app.com` désigne une boutique sans rien mettre dans le chemin. Le
  // découpage se fait côté serveur (`VitrineResolver`), jamais ici : le navigateur ne connaît pas le
  // domaine de la plateforme, et le lui apprendre en dur le figerait.
  //
  // ⚠ La mémoire ne sert QU'APRÈS ce refus. Un 404 ici est le cas NORMAL en développement et en
  // préprod, où l'hôte est `localhost` — ce n'est pas une erreur à montrer.
  useEffect(() => {
    if (vitrineId) return
    let annule = false
    boutique
      .vitrineCourante()
      .then((v) => {
        if (annule) return
        if (v?.id) setVitrineId(v.id)
        else setVitrineId(vitrineStore.get() || '')
        setHoteTranche(true)
      })
      .catch(() => {
        if (annule) return
        setVitrineId(vitrineStore.get() || '')
        setHoteTranche(true)
      })
    return () => {
      annule = true
    }
  }, [vitrineId])

  // Restaure un panier en cours (id + jeton conservés en local) au démarrage.
  useEffect(() => {
    const id = panierStore.getId()
    if (!id || !panierStore.getToken()) return
    let annule = false
    boutique
      .panier(id)
      .then((p) => {
        if (annule) return
        if (p?.statut === 'ouvert') setPanier(p)
        else panierStore.clear()
      })
      .catch(() => panierStore.clear())
    return () => {
      annule = true
    }
  }, [])

  // Ouvre un panier si nécessaire puis ajoute une ligne. Renvoie le panier à jour.
  const ajouterAuPanier = useCallback(
    async (corps, creneauMeta) => {
      let courant = panier
      if (!courant) {
        const ouvert = await boutique.ouvrirPanier(vitrineId)
        // Le jeton en clair n'est renvoyé qu'ici (X-Panier-Token pour les appels suivants).
        panierStore.set(ouvert.id, ouvert.jetonSession)
        courant = ouvert
        setPanier(ouvert)
      }
      const maj = await boutique.ajouterLigne(courant.id, corps)
      if (creneauMeta?.creneau) {
        setMetaCreneaux((m) => ({ ...m, [creneauMeta.creneau]: creneauMeta }))
      }
      setPanier(maj)
      return maj
    },
    [panier, vitrineId],
  )

  const retirerLigne = useCallback(
    async (ligneId) => {
      if (!panier) return
      setBusyPanier(true)
      setErreurPanier(null)
      try {
        const maj = await boutique.retirerLigne(panier.id, ligneId)
        setPanier(maj)
      } catch (e) {
        setErreurPanier(e?.message || 'Le retrait a échoué.')
      } finally {
        setBusyPanier(false)
      }
    },
    [panier],
  )

  // VIDER LE PANIER — l'opération existait côté serveur depuis le début, sans bouton pour l'appeler.
  //
  // Retirer les articles un par un marche, jusqu'à ce qu'il y en ait six. Au-delà, la personne ferme
  // l'onglet — et un panier abandonné ressemble en base à une hésitation alors que c'est un abandon
  // d'interface.
  const viderPanier = useCallback(async () => {
    if (!panier) return
    setBusyPanier(true)
    setErreurPanier(null)
    try {
      const maj = await boutique.viderPanier(panier.id)
      setPanier(maj)
    } catch (e) {
      setErreurPanier(e?.message || 'Le panier n’a pas pu être vidé.')
    } finally {
      setBusyPanier(false)
    }
  }, [panier])

  // Modification de quantité : opération dédiée côté back (ajustement direct de la ligne).
  const modifierQuantite = useCallback(
    async (ligne, delta) => {
      if (!panier) return
      const nouvelle = Math.max(1, (ligne.quantite || 1) + delta)
      if (nouvelle === (ligne.quantite || 1)) return
      setBusyPanier(true)
      setErreurPanier(null)
      try {
        const maj = await boutique.ajusterQuantite(panier.id, ligne.id, nouvelle)
        setPanier(maj)
      } catch (e) {
        setErreurPanier(e?.message || 'La mise à jour de la quantité a échoué.')
        // Rafraîchit pour rester cohérent avec le serveur.
        try {
          setPanier(await boutique.panier(panier.id))
        } catch {
          /* ignore */
        }
      } finally {
        setBusyPanier(false)
      }
    },
    [panier],
  )

  const commandeConfirmee = useCallback(() => {
    panierStore.clear()
    setPanier(null)
    setMetaCreneaux({})
  }, [])

  const nbArticles = (panier?.lignes || []).reduce((n, l) => n + (l.quantite || 1), 0)

  // --- Rendu ---
  if (!vitrineId) {
    return (
      <Cadre vitrine={null} nbArticles={0} connecte={connecte} vue={route.vue} onNaviguer={onNaviguer}>
        {hoteTranche ? (
          <Configuration onValider={(id) => setVitrineId(id)} />
        ) : (
          <Chargement texte="Ouverture de la boutique…" />
        )}
      </Cadre>
    )
  }

  return (
    <Cadre
      vitrine={vitrine}
      etablissementId={catalogue?.etablissement}
      nbArticles={nbArticles}
      connecte={connecte}
      vue={route.vue}
      onNaviguer={onNaviguer}
    >
      {chargement && route.vue === 'vitrine' ? (
        <Chargement texte="Chargement de la boutique…" />
      ) : erreur && route.vue === 'vitrine' ? (
        <Erreur message={erreur} onReessayer={() => chargerVitrine(vitrineId)} />
      ) : route.vue === 'vitrine' ? (
        <Vitrine catalogue={catalogue} langue={langue} onNaviguer={onNaviguer} />
      ) : route.vue === 'produit' ? (
        <FicheProduit
          produit={metaProduits[route.produitId]}
          langue={langue}
          onAjouter={ajouterAuPanier}
          onNaviguer={onNaviguer}
        />
      ) : route.vue === 'panier' ? (
        <Panier
          panier={panier}
          metaProduits={metaProduits}
          metaCreneaux={metaCreneaux}
          langue={langue}
          busy={busyPanier}
          erreur={erreurPanier}
          onRetirer={retirerLigne}
          onModifier={modifierQuantite}
          onVider={viderPanier}
          onNaviguer={onNaviguer}
        />
      ) : route.vue === 'tunnel' ? (
        panier && (panier.lignes || []).length > 0 ? (
          <Tunnel
            panier={panier}
            vitrineId={vitrineId}
            metaProduits={metaProduits}
            metaCreneaux={metaCreneaux}
            langue={langue}
            connecte={connecte}
            onPanierMaj={setPanier}
            onConnexionClient={() => setConnecte(true)}
            onCommandeConfirmee={commandeConfirmee}
            onNaviguer={onNaviguer}
          />
        ) : (
          <Panier
            panier={panier}
            metaProduits={metaProduits}
            metaCreneaux={metaCreneaux}
            langue={langue}
            busy={busyPanier}
            erreur={erreurPanier}
            onRetirer={retirerLigne}
            onModifier={modifierQuantite}
            onVider={viderPanier}
            onNaviguer={onNaviguer}
          />
        )
      ) : route.vue === 'legal' ? (
        <PageLegale
          etablissementId={catalogue?.etablissement}
          slug={route.slug}
          onNaviguer={onNaviguer}
        />
      ) : route.vue === 'compte' ? (
        <MonCompte connecte={connecte} onConnexionChange={setConnecte} onNaviguer={onNaviguer} />
      ) : erreur ? (
        // ⚠ LE REPLI RENDAIT LA BOUTIQUE SANS LA GARDE D'ERREUR, ET C'EST UNE PAGE PUBLIQUE.
        //
        // La branche `vitrine` teste `erreur` avant d'afficher le catalogue ; celle-ci, non. Sur une
        // lecture echouee, `catalogue` vaut `null`, donc `produits` vaut `[]`, donc la page annonce
        // au client << Aucun billet en vente. Cette boutique ne propose aucun billet pour le
        // moment. >> C'est le mensonge du zero sur la surface la plus exposee du produit : un
        // acheteur s'en va, et rien n'aura signale de panne.
        //
        // ⚠ CETTE BRANCHE EST INATTEIGNABLE AUJOURD'HUI, et je le dis plutot que de laisser croire
        // a une correction : les six vues (`vitrine`, `produit`, `panier`, `tunnel`, `legal`,
        // `compte`) sont toutes traitees au-dessus, et ce sont les seules que `onNaviguer` emette.
        // C'est une prevention, pas un correctif -- le jour ou quelqu'un ajoute une vue, il herite
        // du bon comportement au lieu d'un mensonge silencieux.
        <Erreur message={erreur} onReessayer={() => chargerVitrine(vitrineId)} />
      ) : (
        <Vitrine catalogue={catalogue} langue={langue} onNaviguer={onNaviguer} />
      )}
    </Cadre>
  )
}

function Cadre({ vitrine, etablissementId, nbArticles, connecte, vue, onNaviguer, children }) {
  return (
    <div className="bq">
      <PublicHeader
        vitrine={vitrine}
        nbArticles={nbArticles}
        connecte={connecte}
        vue={vue}
        onNaviguer={onNaviguer}
      />
      <main id="bq-main" className="bq-main" tabIndex={-1}>
        {children}
      </main>
      <PublicFooter etablissementId={etablissementId} onNaviguer={onNaviguer} />
    </div>
  )
}

// Priorité : `/b/<slug>`, puis `?vitrine=<id>`, puis `#vitrine=<id>`.
//
// L'HÔTE (D104) et la dernière vitrine mémorisée viennent APRÈS, dans un effet — parce que l'hôte se
// résout par un appel serveur, et qu'un appel n'est pas synchrone.
//
// LE CHEMIN PASSE DEVANT LE PARAMÈTRE, ET C'EST L'ORDRE QUI COMPTE.
//
// Une boutique ouverte sur `/b/piscine-a` doit afficher Piscine A, même si le navigateur se souvient
// d'une autre vitrine visitée hier. L'ordre inverse ferait qu'un lien envoyé à un client ouvrirait la
// boutique du voisin — sans erreur, sans message, avec un catalogue plausible.
//
// Le serveur accepte les deux formes (`VitrineResolver`) : rien à convertir ici.
function lireVitrineInitiale() {
  try {
    const chemin = window.location.pathname || ''
    if (chemin.startsWith('/b/')) {
      const slug = decodeURIComponent(chemin.slice(3).split('/')[0] || '').trim()
      if (slug) return slug
    }
    const params = new URLSearchParams(window.location.search)
    const q = params.get('vitrine')
    if (q) return q
    // Support d'un identifiant transmis dans le hash (#vitrine=…).
    const hash = new URLSearchParams((window.location.hash || '').replace(/^#/, ''))
    if (hash.get('vitrine')) return hash.get('vitrine')
  } catch {
    /* ignore */
  }
  // ⚠ PLUS DE REPLI SUR LA MEMOIRE ICI. Il descend d'un cran, APRES la resolution par hote (D104) :
  // sur `piscine-a.fluvia-app.com`, la vitrine visitee hier ne doit pas gagner. Ce serait la
  // « boutique du voisin » decrite plus haut, sous une forme neuve.
  return ''
}
