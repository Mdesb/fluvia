import { useEffect, useMemo, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import { allerA } from '../api/url.js'
import { libelleProduit, prixIndicatif, euros, statutProduit } from '../api/produit.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

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
// LES BILLETS ONT REJOINT LA LISTE, ET ILS N'AURAIENT PAS DÛ ATTENDRE.
//
// La vérification d'un billet existait — `RechercheBilletModal`, complète — et n'était atteignable
// que depuis **Supervision**. Or la personne qui a besoin de vérifier un billet, c'est le caissier
// avec un client devant lui, ou l'agent au portique. Aucun des deux n'a Supervision ouverte, et
// aucun des deux ne va changer d'écran pendant qu'on lui parle.
//
// Un numéro de billet se cherche **à l'exact**, pas approximativement : c'est ce qui le rend
// compatible avec la règle ci-dessus. `/api/supports?identifiant=` existe déjà et rend zéro ou un
// résultat — il n'y a rien à écrire côté serveur, seulement à ouvrir la porte.
//
// > **Une fonction qu'on ne peut atteindre que depuis l'écran où elle ne sert pas n'existe pas.**
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
  const [supports, setSupports] = useState([])
  // Le billet consulte SANS QUITTER L'ECRAN. Une << vue rapide >> qui fait changer de page
  // n'est pas rapide : le caissier a un client devant lui, et son panier a l'ecran.
  const [chargement, setChargement] = useState(false)
  const [indice, setIndice] = useState(0)
  const [panne, setPanne] = useState(false)

  const champ = useRef(null)
  const boite = useRef(null)
  const cacheProduits = useRef(null)

  const peutClients = aLeDroit(droits, 'crm.lire')
  const peutProduits = aLeDroit(droits, 'offre.lire')
  const peutSupports = aLeDroit(droits, 'acces.lire')

  const resultats = useMemo(() => {
    const l = []
    clients.slice(0, 5).forEach((c) => l.push({ type: 'client', id: c.id, item: c }))
    produits.slice(0, 5).forEach((p) => l.push({ type: 'produit', id: p.id, item: p }))
    // Les billets EN PREMIER quand il y en a : on ne cherche un numéro de support que si on l'a sous
    // les yeux, donc l'intention est certaine — alors qu'un mot tapé peut viser un client ou un produit.
    supports.slice(0, 3).forEach((s) => l.unshift({ type: 'support', id: s.id, item: s }))
    return l
  }, [clients, produits, supports])

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
      setSupports([])
      setChargement(false)
      return undefined
    }

    let annule = false
    setChargement(true)
    const minuteur = setTimeout(async () => {
      try {
        const [cs, ps, ss] = await Promise.all([
          peutClients ? api.rechercheClients({ q, itemsPerPage: 5 }).catch(() => null) : null,
          peutProduits ? chargerProduits(cacheProduits).catch(() => null) : null,
          // Recherche EXACTE : `identifiant=` ne fait pas de correspondance partielle. Un numéro
          // tronqué ne rend donc rien, et c'est voulu — proposer « le billet le plus proche » sur un
          // contrôle d'accès serait la pire des complaisances.
          peutSupports ? api.supports({ identifiant: q, itemsPerPage: 3 }).catch(() => null) : null,
        ])
        if (annule) return
        // `/crm/clients/recherche` est une operation sur mesure : elle rend `{ items, total }` et non
        // une collection hydra. L'extracteur generique rendait donc toujours une liste vide.
        setClients(cs ? (Array.isArray(cs.items) ? cs.items : membres(cs)) : [])
        setProduits(ps ? filtrerProduits(ps, q) : [])
        setSupports(ss ? membres(ss) : [])
        // Une recherche qui echoue et une recherche sans resultat ne doivent pas se ressembler :
        // « aucun resultat » sur une panne envoie chercher un client qui existe pourtant.
        setPanne((peutClients && cs === null) || (peutProduits && ps === null))
        setIndice(0)
      } finally {
        if (!annule) setChargement(false)
      }
    }, DELAI_FRAPPE)

    return () => {
      annule = true
      clearTimeout(minuteur)
    }
  }, [terme, peutClients, peutProduits, peutSupports])

  function choisir(r) {
    setOuvert(false)
    setTerme('')
    champ.current?.blur()
    if (r.type === 'client') onNav('clients', { type: 'client', id: r.id })
    // Pas de navigation : la fiche s'ouvre par-dessus l'ecran courant, et se referme dessus.
    // ⚠ LA VÉRIFICATION A UNE ADRESSE, ET C'EST CELLE DE LA CAISSE. Cette barre est partout et ne
    // porte aucune adresse : elle emmène là où le geste se fait, avec le numéro déjà rempli.
    else if (r.type === 'support') allerA('caisse', { verifier: r.item.identifiant })
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

  if (!peutClients && !peutProduits && !peutSupports) return null

  const montrerPanneau = ouvert && terme.trim().length >= 2

  return (
    <div className="topbar-search" ref={boite}>
      <span className="ts-ic" aria-hidden="true">⌕</span>
      <input
        ref={champ}
        className="input"
        type="search"
        value={terme}
        placeholder={placeholder(peutClients, peutProduits, peutSupports)}
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
          ) : panne && resultats.length === 0 ? (
            <div className="ts-vide">La recherche n'a pas abouti. Réessayez dans un instant.</div>
          ) : resultats.length === 0 ? (
            <div className="ts-vide">Aucun résultat pour « {terme.trim()} ».</div>
          ) : (
            <>
              {supports.length > 0 && <div className="ts-sec">Billets et cartes</div>}
              {resultats
                .filter((r) => r.type === 'support')
                .map((r) => (
                  <Resultat
                    key={`s-${r.id}`}
                    actif={resultats[indice] === r}
                    onChoisir={() => choisir(r)}
                    principal={r.item.identifiant}
                    secondaire={mot(r.item.type) || 'support'}
                    badge={
                      r.item.statut === 'actif'
                        ? { ton: 'good', libelle: 'utilisable', aide: 'Ce support passera au contrôle.' }
                        // Un support bloqué se voit AVANT d'ouvrir la fiche : c'est l'information
                        // qu'on cherche, et la faire attendre un clic la fait manquer.
                        : { ton: 'crit', libelle: 'bloqué', aide: "Refusé au contrôle d'accès." }
                    }
                  />
                ))}

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

// LE LIBELLE ENUMERE EXACTEMENT CE QUE LA BOITE TROUVE.
//
// Une boite qui annonce moins qu'elle ne fait est aussi couteuse qu'une boite qui annonce plus :
// personne n'y tape un numero de billet si rien ne dit qu'elle en cherche. La fonctionnalite existe
// alors sans etre utilisee, ce qui revient au meme que de ne pas l'avoir ecrite.
function placeholder(peutClients, peutProduits, peutSupports) {
  const quoi = []
  if (peutClients) quoi.push('un client')
  if (peutProduits) quoi.push('un produit')
  if (peutSupports) quoi.push('un billet')
  if (quoi.length === 0) return 'Rechercher…'
  return `Rechercher ${quoi.join(', ')}…`
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
