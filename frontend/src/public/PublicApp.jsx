import { useCallback, useEffect, useMemo, useState } from 'react'
import { boutique, panierStore, clientTokenStore, vitrineStore } from './api/boutiqueClient.js'
import PublicHeader from './components/PublicHeader.jsx'
import PublicFooter from './components/PublicFooter.jsx'
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
        boutique.vitrine(id).catch(() => null),
        boutique.catalogue(id),
      ])
      setVitrine(v)
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
        <Configuration onValider={(id) => setVitrineId(id)} />
      </Cadre>
    )
  }

  return (
    <Cadre
      vitrine={vitrine}
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
            onNaviguer={onNaviguer}
          />
        )
      ) : route.vue === 'compte' ? (
        <MonCompte connecte={connecte} onConnexionChange={setConnecte} onNaviguer={onNaviguer} />
      ) : (
        <Vitrine catalogue={catalogue} langue={langue} onNaviguer={onNaviguer} />
      )}
    </Cadre>
  )
}

function Cadre({ vitrine, nbArticles, connecte, vue, onNaviguer, children }) {
  return (
    <div className="pub">
      <PublicHeader
        vitrine={vitrine}
        nbArticles={nbArticles}
        connecte={connecte}
        vue={vue}
        onNaviguer={onNaviguer}
      />
      <main id="pub-main" className="pub-main" tabIndex={-1}>
        {children}
      </main>
      <PublicFooter />
    </div>
  )
}

// Priorité : ?vitrine=<id> dans l'URL, puis la dernière vitrine mémorisée.
function lireVitrineInitiale() {
  try {
    const params = new URLSearchParams(window.location.search)
    const q = params.get('vitrine')
    if (q) return q
    // Support d'un identifiant transmis dans le hash (#vitrine=…).
    const hash = new URLSearchParams((window.location.hash || '').replace(/^#/, ''))
    if (hash.get('vitrine')) return hash.get('vitrine')
  } catch {
    /* ignore */
  }
  return vitrineStore.get() || ''
}
