import { useEffect, useMemo, useState } from 'react'
import { api } from '../api/client.js'
import { centimes } from '../api/produit.js'

/**
 * LA BOUTIQUE DE MODULES — demande de Maxime le 01/09.
 *
 * ── CE QUI EXISTAIT DEJA, ET QUE PERSONNE N'APPELAIT ─────────────────────────────────────────────
 *
 *   GET /api/fonctionnalites/catalogue   25 fiches : code, libelle, description, categorie
 *
 * Le compte disait 24 : `CapaciteCode` en porte 25, et le `match` de `CatalogueCapacites`
 * est exhaustif, donc les deux ne peuvent pas diverger. C'est le COMMENTAIRE qui avait pris
 * du retard sur une capacite ajoutee. Recompte le 03/09.
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
 *
 * ── PRÉSENTATION (07/09) ─────────────────────────────────────────────────────────────────────────
 *
 * La logique ci-dessus est inchangée ; seule la mise en forme a été reprise. Les états se lisent
 * maintenant à l'œil : un module actif porte un liseré vert, un module choisi passe en accent, un
 * module sans tarif est atténué. Les classes vivent dans `styles.css` (`.module-carte` et ses
 * variantes) — jamais d'espacement en dur ici, il vient des jetons `--esp-*`.
 */
export default function Modules({ capacites = [], me }) {
  const [catalogue, setCatalogue] = useState(null)
  const [vendables, setVendables] = useState(null)
  const [erreur, setErreur] = useState(null)
  // Renseigné quand les tarifs n'ont pas pu être lus : « aucun prix » et « aucune réponse » ne se
  // disent pas pareil, et la seconde ne doit jamais s'afficher comme la première.
  const [optionsIndisponibles, setOptionsIndisponibles] = useState(null)
  const [panier, setPanier] = useState([])

  useEffect(() => {
    let annule = false
    // ⚠ ON DISTINGUE « RIEN A VENDRE » DE « JE N'AI PAS PU DEMANDER ».
    //
    // Ce `catch` rendait `{ member: [] }`, donc l'écran affichait « aucun module n'est proposé à la
    // vente » quand l'appel échouait. Vingt options existent en base et il aurait affirmé qu'il n'y
    // en a aucune. Une liste vide n'est pas une réponse : c'est l'absence de réponse.
    Promise.all([
      api.catalogueCapacites(),
      api.optionsVendables().catch((e) => ({ __echec: e?.message || 'appel refusé' })),
    ])
      .then(([cat, opt]) => {
        if (annule) return
        setCatalogue(cat?.member || cat?.['hydra:member'] || [])
        if (opt?.__echec) {
          setOptionsIndisponibles(opt.__echec)
          setVendables([])
        } else {
          setOptionsIndisponibles(null)
          setVendables(opt?.member || opt?.['hydra:member'] || [])
        }
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
    const verticales = []
    for (const c of catalogue || []) {
      // ⚠ UNE VERTICALE N'EST PAS UN MODULE, ET NE SE VEND PAS A LA CARTE.
      //
      // `padel`, `piscine`, `sport`, `patinoire`, `musee` sont des valeurs de l'énumération
      // `Metier` : chacune est un PRESET qui active un jeu de capacités. C'est ce qu'un
      // établissement EST, pas ce qu'il ajoute. Le serveur le déclare (`estVerticale`) ; on ne le
      // redérive pas ici à partir de la catégorie, qui n'est qu'une étiquette d'affichage.
      if (c.estVerticale) {
        verticales.push({ ...c, actif: capacites.includes(c.code) })
        continue
      }
      if (capacites.includes(c.code)) actifs.push(c)
      else if (prixParCode[c.code] > 0) disponibles.push({ ...c, prix: prixParCode[c.code] })
      else pasEncore.push(c)
    }
    return { actifs, disponibles, pasEncore, verticales }
  }, [catalogue, capacites, prixParCode])

  const total = panier.reduce((s, c) => s + (prixParCode[c] || 0), 0)

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

  const nbActifs = groupes.actifs.length
  const nbDisponibles = groupes.disponibles.length
  const nbActives = groupes.verticales.filter((v) => v.actif).length

  return (
    <div>
      <div className="view-head">
        <div className="ttl">
          <h1>Modules</h1>
          <p className="sub" style={{ maxWidth: '68ch' }}>
            Ce que votre établissement utilise aujourd’hui, et ce que vous pouvez y ajouter. Un
            module activé apparaît immédiatement dans le menu.
          </p>
        </div>
        <div className="actions">
          <span className="badge good">{nbActifs} actif{nbActifs > 1 ? 's' : ''}</span>
          <span className="badge info">{nbDisponibles} disponible{nbDisponibles > 1 ? 's' : ''}</span>
        </div>
      </div>

      {/* ⚠ ON NOMME CE QUI EST ACTIF AVANT CE QUI SE VEND. Une boutique qui ouvre sur ce qu'on n'a
          pas donne l'impression d'un produit incomplet ; la meme liste, ouverte sur ce qu'on a,
          donne la mesure de ce qu'on utilise deja. */}
      <Section
        titre="Actifs"
        compte={groupes.actifs.length}
        vide={<p className="empty">Aucun module actif sur cet établissement.</p>}
      >
        {groupes.actifs.map((c) => (
          <Carte key={c.code} module={c} variante="actif" pied={<span className="badge good">actif</span>} />
        ))}
      </Section>

      <Section
        titre="Disponibles à l’ajout"
        compte={groupes.disponibles.length}
        vide={
          optionsIndisponibles
            ? (
              <div className="banner banner-warn">
                ⚠ Les tarifs n’ont pas pu être chargés ({optionsIndisponibles}). Cet écran ne sait
                donc PAS ce qui est en vente — ne concluez pas qu’il n’y a rien.
              </div>
            )
            : (
              <p className="empty">
                {(vendables || []).length === 0
                  ? 'Aucun module n’est proposé à la vente pour le moment : l’éditeur n’a créé aucune option tarifaire.'
                  : 'Tous les modules proposés à la vente sont déjà actifs ici.'}
              </p>
            )
        }
      >
        {groupes.disponibles.map((c) => {
          const choisi = panier.includes(c.code)
          return (
            <Carte
              key={c.code}
              module={c}
              variante={choisi ? 'choisie' : ''}
              pied={
                <>
                  <span className="m-prix">{centimes(c.prix)}<span className="sub"> / mois</span></span>
                  <button
                    className={choisi ? 'btn sm' : 'btn primary sm'}
                    type="button"
                    onClick={() => basculer(c.code)}
                  >
                    {choisi ? 'Retirer' : 'Ajouter'}
                  </button>
                </>
              }
            />
          )
        })}
      </Section>

      {/* ⚠ ON NOMME CE QUI N'EST PAS PROPOSE, AU LIEU DE LE CACHER. Un catalogue ampute se lit comme
          un catalogue complet — c'est le defaut qu'on a paye sur le menu, ou treize modules etaient
          absents plutot que grises, si bien que personne ne pouvait constater qu'ils manquaient. */}
      <Section
        titre="Pas encore proposés à la vente"
        compte={groupes.pasEncore.length}
        vide={<p className="empty">Tous les modules du catalogue ont un tarif.</p>}
      >
        {groupes.pasEncore.map((c) => (
          <Carte key={c.code} module={c} variante="muet" pied={<span className="sub">tarif à définir</span>} />
        ))}
      </Section>

      {/* ⚠ LES VERTICALES SONT MONTREES, PAS VENDUES. Les cacher ferait chercher « ou est le
          padel ? » ; les mettre en rayon ferait croire qu'on l'achete a la carte. On les nomme pour
          ce qu'elles sont — l'activite de l'etablissement — et on dit par ou ca se change. */}
      {groupes.verticales.length > 0 && (
        <section className="modules-section">
          <div className="modules-section-tete">
            <h3>Votre activité</h3>
            <span className="badge mut">{nbActives} / {groupes.verticales.length}</span>
          </div>
          <p className="sub" style={{ maxWidth: '68ch' }}>
            Ce que votre établissement <b>est</b>. Une activité n’est pas un module qu’on ajoute au
            panier&nbsp;: elle active d’un coup l’ensemble des fonctions qui vont avec. Elle se
            change avec Fluvia, pas depuis cette page.
          </p>
          <div className="modules-grille">
            {groupes.verticales.map((v) => (
              <div className={`card module-carte ${v.actif ? 'actif' : 'muet'}`} key={v.code}>
                <div className="m-tete">
                  <span className="m-nom">{v.libelle}</span>
                  {v.actif
                    ? <span className="badge good">votre activité</span>
                    : <span className="badge mut">non</span>}
                </div>
                <p className="m-desc">{v.description}</p>
              </div>
            ))}
          </div>
        </section>
      )}

      {panier.length > 0 && (
        <div className="card card-b modules-total">
          <h3 style={{ marginTop: 0 }}>Votre sélection</h3>
          <ul>
            {panier.map((code) => {
              const c = (catalogue || []).find((x) => x.code === code)
              return (
                <li key={code}>
                  {c?.libelle || code} — <span className="num">{centimes(prixParCode[code] || 0)}</span> / mois
                </li>
              )
            })}
          </ul>
          <p>
            <b>Total&nbsp;: <span className="num">{centimes(total)}</span> par mois</b>, qui s’ajoutent
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
      )}
    </div>
  )
}

/**
 * Une section de la boutique : un titre, un compteur, et soit la grille de cartes, soit le message
 * de vide/erreur passé en `vide` (un nœud, pas une chaîne — il peut être un bandeau).
 */
function Section({ titre, compte, vide, children }) {
  return (
    <section className="modules-section">
      <div className="modules-section-tete">
        <h3>{titre}</h3>
        <span className="badge mut">{compte}</span>
      </div>
      {compte === 0 ? vide : <div className="modules-grille">{children}</div>}
    </section>
  )
}

/** Une carte-module : nom + catégorie, description, et un pied variable (badge, prix + bouton…). */
function Carte({ module: c, variante, pied }) {
  return (
    <div className={`card module-carte ${variante || ''}`.trimEnd()}>
      <div className="m-tete">
        <span className="m-nom">{c.libelle}</span>
        <span className="badge mut">{c.categorie}</span>
      </div>
      <p className="m-desc">{c.description}</p>
      <div className="m-pied">{pied}</div>
    </div>
  )
}
