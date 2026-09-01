import { useEffect, useMemo, useState } from 'react'
import { api } from '../api/client.js'

/**
 * LA BOUTIQUE DE MODULES — demande de Maxime le 01/09.
 *
 * ── CE QUI EXISTAIT DEJA, ET QUE PERSONNE N'APPELAIT ─────────────────────────────────────────────
 *
 *   GET /api/fonctionnalites/catalogue   24 fiches : code, libelle, description, categorie
 *   GET /api/editor/plan-options         ce qui est vendable, avec son prix
 *   /me capacitesActives                 ce que cet etablissement porte deja
 *   /editeur -> Offres                   l'ecran ou l'editeur saisit les prix, deja servi
 *
 * Le seul manque etait cet ecran-ci.
 *
 * ── CE QU'IL NE FAIT PAS, ET POURQUOI IL LE DIT PLUTOT QUE DE L'IMITER ───────────────────────────
 *
 * Il n'y a AUCUN bouton « payer », et c'est deliberé :
 *
 *   1. `POST /editor/carts` cree un NOUVEAU client — c'est le tunnel de souscription. Le brancher
 *      ici creerait un doublon de client a chaque achat d'un exploitant deja en place.
 *   2. `subscription_subscription` compte zero ligne. « Ajouter un module a ce que je paie deja »
 *      presuppose un abonnement, qui n'existe pour personne.
 *
 * Un bouton qui ne peut rien faire est pire qu'un bouton absent : il fait cliquer, puis chercher ce
 * qui a echoue. L'ecran montre donc ce qui est vrai — ce qu'on a, ce qui se vend, ce que ca
 * couterait — et nomme le seul chemin qui existe aujourd'hui.
 */
export default function Modules({ capacites = [], me }) {
  const [catalogue, setCatalogue] = useState(null)
  const [vendables, setVendables] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [panier, setPanier] = useState([])

  useEffect(() => {
    let annule = false
    Promise.all([api.catalogueCapacites(), api.optionsVendables().catch(() => ({ member: [] }))])
      .then(([cat, opt]) => {
        if (annule) return
        setCatalogue(cat?.member || cat?.['hydra:member'] || [])
        setVendables(opt?.member || opt?.['hydra:member'] || [])
      })
      .catch((e) => {
        if (!annule) setErreur(e.message || 'Le catalogue des modules n’a pas pu être chargé.')
      })
    return () => { annule = true }
  }, [])

  const prixParCode = useMemo(() => {
    const m = {}
    for (const o of vendables || []) m[o.capability] = o.monthlyPriceCents
    return m
  }, [vendables])

  const groupes = useMemo(() => {
    const actifs = []
    const disponibles = []
    const pasEncore = []
    for (const c of catalogue || []) {
      if (capacites.includes(c.code)) actifs.push(c)
      else if (prixParCode[c.code] > 0) disponibles.push({ ...c, prix: prixParCode[c.code] })
      else pasEncore.push(c)
    }
    return { actifs, disponibles, pasEncore }
  }, [catalogue, capacites, prixParCode])

  const total = panier.reduce((s, c) => s + (prixParCode[c] || 0), 0)
  const euros = (cents) => (cents / 100).toFixed(2).replace('.', ',') + ' €'

  function basculer(code) {
    setPanier((p) => (p.includes(code) ? p.filter((x) => x !== code) : [...p, code]))
  }

  if (erreur) {
    return (
      <div className="banner banner-error">
        <b>Les modules n’ont pas pu être chargés.</b> {erreur} Cette page ne sait donc pas ce que
        votre établissement possède&nbsp;: ne concluez rien de son contenu actuel.
      </div>
    )
  }

  if (catalogue === null) return <div className="sub">Chargement des modules…</div>

  return (
    <div>
      <h2>Modules</h2>
      <p className="sub" style={{ maxWidth: '68ch' }}>
        Ce que votre établissement utilise aujourd’hui, et ce que vous pouvez y ajouter. Un module
        activé apparaît immédiatement dans le menu.
      </p>

      {/* ⚠ ON NOMME CE QUI EST ACTIF AVANT CE QUI SE VEND. Une boutique qui ouvre sur ce qu'on n'a
          pas donne l'impression d'un produit incomplet ; la meme liste, ouverte sur ce qu'on a,
          donne la mesure de ce qu'on utilise deja. */}
      <Groupe
        titre="Actifs"
        vide="Aucun module actif sur cet établissement."
        items={groupes.actifs}
        rendu={(c) => (
          <span className="badge good">actif</span>
        )}
      />

      <Groupe
        titre="Disponibles"
        vide={
          (vendables || []).length === 0
            ? "Aucun module n’est proposé à la vente pour le moment : l’éditeur n’a créé aucune option tarifaire."
            : 'Tous les modules proposés à la vente sont déjà actifs ici.'
        }
        items={groupes.disponibles}
        rendu={(c) => (
          <>
            <span className="num">{euros(c.prix)}<span className="sub"> / mois</span></span>
            <button
              className={panier.includes(c.code) ? 'btn sm' : 'btn primary sm'}
              type="button"
              onClick={() => basculer(c.code)}
            >
              {panier.includes(c.code) ? 'Retirer' : 'Ajouter'}
            </button>
          </>
        )}
      />

      {/* ⚠ ON NOMME CE QUI N'EST PAS PROPOSE, AU LIEU DE LE CACHER. Un catalogue ampute se lit comme
          un catalogue complet — c'est le defaut qu'on a paye sur le menu, ou treize modules etaient
          absents plutot que grises, si bien que personne ne pouvait constater qu'ils manquaient. */}
      <Groupe
        titre="Pas encore proposés à la vente"
        vide="Tous les modules du catalogue ont un tarif."
        items={groupes.pasEncore}
        rendu={() => <span className="sub">tarif à définir</span>}
      />

      {panier.length > 0 && (
        <div className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
          <div className="card-b">
            <h3 style={{ marginTop: 0 }}>Votre sélection</h3>
            <ul>
              {panier.map((code) => {
                const c = (catalogue || []).find((x) => x.code === code)
                return (
                  <li key={code}>
                    {c?.libelle || code} — <span className="num">{euros(prixParCode[code] || 0)}</span> / mois
                  </li>
                )
              })}
            </ul>
            <p>
              <b>Total&nbsp;: <span className="num">{euros(total)}</span> par mois</b>, qui s’ajoutent
              à votre abonnement en cours.
            </p>

            {/* ⚠ CE BANDEAU DIT L'ETAT REEL, ET IL DEVRA DISPARAITRE LE JOUR OU LA ROUTE EXISTE.
                Ecrit ici plutot que dans un bouton grise : un bouton inerte fait chercher pourquoi
                il ne repond pas ; une phrase dit pourquoi et ou aller. */}
            <div className="banner banner-info">
              <b>La souscription en ligne n’est pas encore branchée.</b> Aucun établissement n’a
              d’abonnement enregistré à ce jour&nbsp;: il n’y a donc rien à quoi rattacher ces
              modules. Cette sélection vous donne le coût mensuel exact&nbsp;; l’activation se fait
              aujourd’hui par Fluvia.
              {me?.envoiCourrielBranche === false && (
                <> ⚠ Et cette instance n’envoie aucun courriel&nbsp;: ne comptez pas sur un message
                automatique.</>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function Groupe({ titre, items, vide, rendu }) {
  return (
    <section style={{ marginTop: 'var(--esp-bloc)' }}>
      <h3>{titre} <span className="sub">({items.length})</span></h3>
      {items.length === 0 ? (
        <p className="sub">{vide}</p>
      ) : (
        <div className="grid">
          {items.map((c) => (
            <div className="card" key={c.code}>
              <div className="card-b">
                <div className="row" style={{ justifyContent: 'space-between', gap: 'var(--esp-normal)' }}>
                  <b>{c.libelle}</b>
                  <span className="badge">{c.categorie}</span>
                </div>
                <p className="sub">{c.description}</p>
                <div className="row" style={{ justifyContent: 'flex-end', gap: 'var(--esp-normal)' }}>
                  {rendu(c)}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </section>
  )
}
