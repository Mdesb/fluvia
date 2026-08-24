import { useEffect, useMemo, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import { libelleProduit, prixIndicatif, euros, statutProduit } from '../api/produit.js'

// Recherche globale de la barre du haut.
//
// La barre ne portait qu'un sélecteur d'établissement et un basculeur de thème, sur toute la largeur
// de l'écran — environ 1 300 px de bande vide sur un écran courant. C'est l'endroit le plus visible et
// le plus constant de l'application ; y mettre la recherche est ce qui rend cette place utile.
//
// CE QU'ELLE CHERCHE, ET POURQUOI PAS PLUS. Clients et produits : ce sont les deux choses qu'on
// cherche par leur nom plusieurs fois par jour, et les deux seules pour lesquelles une recherche
// serveur existe ou est possible sans en écrire une. Ventes et réservations se cherchent par date ou
// par client, pas par un mot — les inclure aurait demandé un point d'entrée de recherche transverse
// côté serveur, et une boîte qui promet de tout trouver mais ne trouve que la moitié est pire qu'une
// boîte honnête.
//
// LES DROITS SONT RESPECTÉS ICI AUSSI : on n'interroge que ce que l'utilisateur a le droit de lire.
// Une recherche qui révélerait l'existence d'un client à quelqu'un qui n'a pas `crm.lire` serait une
// fuite, même sans afficher la fiche.

const DELAI_FRAPPE = 250

export default function RechercheGlobale({ droits = [], onNav }) {
  const [terme, setTerme] = useState('')
  const [ouvert, setOuvert] = useState(false)
  const [clients, setClients] = useState([])
  const [produits, setProduits] = useState([])
  const [chargement, setChargement] = useState(false)
  const [indice, setIndice] = useState(0)

  const champ = useRef(null)
  const boite = useRef(null)
  const cacheProduits = useRef(null)

  const peutClients = droits.includes('crm.lire')
  const peutProduits = droits.includes('offre.lire')

  const resultats = useMemo(() => {
    const l = []
    clients.slice(0, 5).forEach((c) => l.push({ type: 'client', id: c.id, item: c }))
    produits.slice(0, 5).forEach((p) => l.push({ type: 'produit', id: p.id, item: p }))
    return l
  }, [clients, produits])

  // Raccourci clavier : « / » comme dans la plupart des outils, et Ctrl+K / ⌘K pour ceux qui ont
  // l'habitude. Sans raccourci, une recherche en haut de page se prend à la souris — trois secondes
  // à chaque fois, toute la journée.
  useEffect(() => {
    function onKey(e) {
      const dansUnChamp = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)
      if ((e.key === '/' && !dansUnChamp) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
        e.preventDefault()
        champ.current?.focus()
        champ.current?.select()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  // Fermeture au clic extérieur.
  useEffect(() => {
    function onClic(e) {
      if (boite.current && !boite.current.contains(e.target)) setOuvert(false)
    }
    document.addEventListener('mousedown', onClic)
    return () => document.removeEventListener('mousedown', onClic)
  }, [])

  useEffect(() => {
    const q = terme.trim()
    if (q.length < 2) {
      setClients([])
      setProduits([])
      setChargement(false)
      return undefined
    }

    let annule = false
    setChargement(true)
    const minuteur = setTimeout(async () => {
      try {
        const [cs, ps] = await Promise.all([
          peutClients ? api.rechercheClients({ q, itemsPerPage: 5 }).catch(() => null) : null,
          peutProduits ? chargerProduits(cacheProduits).catch(() => null) : null,
        ])
        if (annule) return
        setClients(cs ? membres(cs) : [])
        setProduits(ps ? filtrerProduits(ps, q) : [])
        setIndice(0)
      } finally {
        if (!annule) setChargement(false)
      }
    }, DELAI_FRAPPE)

    return () => {
      annule = true
      clearTimeout(minuteur)
    }
  }, [terme, peutClients, peutProduits])

  function choisir(r) {
    setOuvert(false)
    setTerme('')
    champ.current?.blur()
    if (r.type === 'client') onNav('clients', { type: 'client', id: r.id })
    else onNav('catalogue', { type: 'produit', id: r.id })
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') {
      setOuvert(false)
      champ.current?.blur()
      return
    }
    if (!resultats.length) return
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setIndice((i) => (i + 1) % resultats.length)
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setIndice((i) => (i - 1 + resultats.length) % resultats.length)
    } else if (e.key === 'Enter') {
      e.preventDefault()
      choisir(resultats[indice])
    }
  }

  if (!peutClients && !peutProduits) return null

  const montrerPanneau = ouvert && terme.trim().length >= 2

  return (
    <div className="topbar-search" ref={boite}>
      <span className="ts-ic" aria-hidden="true">⌕</span>
      <input
        ref={champ}
        className="input"
        type="search"
        value={terme}
        placeholder={placeholder(peutClients, peutProduits)}
        aria-label="Recherche globale"
        onChange={(e) => {
          setTerme(e.target.value)
          setOuvert(true)
        }}
        onFocus={() => setOuvert(true)}
        onKeyDown={onKeyDown}
      />
      <span className="ts-kbd" aria-hidden="true">/</span>

      {montrerPanneau && (
        <div className="ts-panel" role="listbox">
          {chargement && resultats.length === 0 ? (
            <div className="ts-vide">Recherche…</div>
          ) : resultats.length === 0 ? (
            <div className="ts-vide">Aucun résultat pour « {terme.trim()} ».</div>
          ) : (
            <>
              {clients.length > 0 && <div className="ts-sec">Clients</div>}
              {resultats
                .filter((r) => r.type === 'client')
                .map((r) => (
                  <Resultat
                    key={`c-${r.id}`}
                    actif={resultats[indice] === r}
                    onChoisir={() => choisir(r)}
                    principal={nomClient(r.item)}
                    secondaire={r.item.email || r.item.telephone || '—'}
                  />
                ))}

              {produits.length > 0 && <div className="ts-sec">Produits</div>}
              {resultats
                .filter((r) => r.type === 'produit')
                .map((r) => (
                  <Resultat
                    key={`p-${r.id}`}
                    actif={resultats[indice] === r}
                    onChoisir={() => choisir(r)}
                    principal={libelleProduit(r.item)}
                    secondaire={`${r.item.code || '—'} · ${euros(prixIndicatif(r.item))}`}
                    badge={statutProduit(r.item)}
                  />
                ))}
            </>
          )}
        </div>
      )}
    </div>
  )
}

function Resultat({ actif, onChoisir, principal, secondaire, badge }) {
  return (
    <button
      type="button"
      role="option"
      aria-selected={actif}
      className={`ts-item${actif ? ' actif' : ''}`}
      onClick={onChoisir}
    >
      <span className="ts-nm">{principal}</span>
      <span className="ts-sub">{secondaire}</span>
      {badge && <span className={`badge ${badge.ton}`} title={badge.aide}>{badge.libelle}</span>}
    </button>
  )
}

function placeholder(peutClients, peutProduits) {
  if (peutClients && peutProduits) return 'Rechercher un client, un produit…'
  if (peutClients) return 'Rechercher un client…'
  return 'Rechercher un produit…'
}

function nomClient(c) {
  const n = [c?.prenom, c?.nom].filter(Boolean).join(' ').trim()
  return n || c?.raisonSociale || c?.email || 'Client'
}

// Les produits n'ont pas de point d'entrée de recherche côté serveur : on charge la collection une
// fois et on filtre en mémoire. C'est tenable parce qu'un catalogue d'établissement se compte en
// dizaines ou en centaines, pas en dizaines de milliers — et le jour où ce ne sera plus vrai, le
// symptôme sera lisible ici plutôt que caché dans une requête lente.
async function chargerProduits(cache) {
  if (cache.current) return cache.current
  const c = await api.produits()
  cache.current = membres(c)
  return cache.current
}

function filtrerProduits(liste, q) {
  const t = q.toLowerCase()
  return liste
    .filter((p) => `${libelleProduit(p)} ${p.code || ''}`.toLowerCase().includes(t))
    .slice(0, 5)
}
