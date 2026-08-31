import { useEffect, useState } from 'react'
import { boutique, clientTokenStore, vitrineStore } from '../api/boutiqueClient.js'
import { libelleProduit, libelleCreneau, libellePrix } from '../lib/format.js'
import { Chargement, Erreur } from '../components/Etats.jsx'

// Fiche produit : détail + choix de créneau (timed-entry) + quantité + ajout au panier.
/**
 * S'ABONNER EN LIGNE — le canal qui n'existait pas.
 *
 * `POST /boutique/abonnements/souscrire` existait, testé, et n'était appelé par personne. La vente
 * au guichet existe depuis le 29/08 ; le même produit ne se vendait pas en ligne. Pour un
 * exploitant qui vend des abonnements annuels, c'est le canal qui coûte le moins cher à servir.
 * Relevé par allaccess-b8.
 *
 * ── ⚠ TROIS REFUS DU SERVEUR, ÉVITÉS AVANT LE CLIC PLUTÔT QU'AFFICHÉS APRÈS ────────────────────
 *
 *   · **produit sans facette SEPA** — le bouton n'apparaît que si le catalogue dit `abonnement`.
 *     Sans ce champ, il serait sur tout et refuserait au clic ;
 *   · **invité** — on le dit d'emblée, avec le chemin pour créer un compte. Un refus après la
 *     saisie d'un IBAN serait le pire moment possible ;
 *   · **iban ou nom vide** — le bouton reste inactif tant que les deux ne sont pas remplis.
 *
 * ── CE QUE L'ÉCRAN ANNONCE AVANT QU'ON S'ENGAGE ───────────────────────────────────────────────
 *
 * Que ce geste **signe un mandat de prélèvement** et **crée la commande immédiatement**. Ce n'est
 * pas un panier : il n'y a rien à confirmer ensuite, et rien à retirer. Le dire après le clic
 * reviendrait à faire signer sans prévenir.
 *
 * ⚠ NON ÉPROUVÉ DE BOUT EN BOUT : une souscription réelle exige des coordonnées bancaires, et je
 * n'en saisis pas. Ce qui est vérifié : le contrat du processeur, la facette publiée par le
 * catalogue, et que le bouton n'apparaît que là où le serveur accepterait.
 */
function Abonnement({ produit, onNaviguer }) {
  const connecte = !!clientTokenStore.get()
  const [ouvert, setOuvert] = useState(false)
  const [nom, setNom] = useState('')
  const [iban, setIban] = useState('')
  const [bic, setBic] = useState('')
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [faite, setFaite] = useState(null)

  async function souscrire() {
    if (!nom.trim() || !iban.trim()) return
    setEnvoi(true)
    setErreur(null)
    try {
      const r = await boutique.souscrireAbonnement({
        produit: produit.produit,
        // La boutique où l'achat a lieu — voir le commentaire de `souscrireAbonnement`.
        vitrine: vitrineStore.get() || undefined,
        debiteurNom: nom.trim(),
        iban: iban.trim(),
        ...(bic.trim() ? { bicDebiteur: bic.trim() } : {}),
      })
      setFaite(r)
      setOuvert(false)
    } catch (e) {
      setErreur(e?.message || 'La souscription n’a pas abouti.')
    } finally {
      setEnvoi(false)
    }
  }

  if (faite) {
    return (
      <div className="banner banner-ok" role="status">
        <strong>Abonnement souscrit.</strong> Commande {faite.numero || faite.vente?.slice(0, 8)}.
        Le prélèvement sera présenté par l’établissement ; vous retrouvez la commande dans votre
        espace.
      </div>
    )
  }

  if (!connecte) {
    return (
      <div className="bq-abo">
        <p className="bq-sub">
          Cet abonnement se règle par prélèvement. Il faut un compte pour le souscrire — le mandat
          est signé à votre nom.
        </p>
        <button type="button" className="btn primary lg" onClick={() => onNaviguer({ vue: 'compte' })}>
          Créer un compte ou se connecter
        </button>
      </div>
    )
  }

  if (!ouvert) {
    return (
      <div className="bq-abo">
        <p className="bq-sub">
          En souscrivant, vous signez un mandat de prélèvement et la commande est créée
          immédiatement — ce n’est pas un panier.
        </p>
        <button type="button" className="btn primary lg" onClick={() => setOuvert(true)}>
          S’abonner
        </button>
      </div>
    )
  }

  return (
    <div className="bq-abo">
      {erreur && <Erreur message={erreur} />}
      <div className="field">
        <label htmlFor="abo-nom">Titulaire du compte bancaire</label>
        <input id="abo-nom" className="input" value={nom} onChange={(e) => setNom(e.target.value)} required />
      </div>
      <div className="field">
        <label htmlFor="abo-iban">IBAN</label>
        <input
          id="abo-iban"
          className="input"
          value={iban}
          onChange={(e) => setIban(e.target.value)}
          autoComplete="off"
          required
        />
      </div>
      <div className="field">
        <label htmlFor="abo-bic">BIC (facultatif)</label>
        <input id="abo-bic" className="input" value={bic} onChange={(e) => setBic(e.target.value)} autoComplete="off" />
      </div>
      <p className="bq-sub">
        En validant, vous autorisez l’établissement à prélever ce compte au titre de cet abonnement.
      </p>
      <button
        type="button"
        className="btn primary lg"
        disabled={envoi || !nom.trim() || !iban.trim()}
        onClick={souscrire}
      >
        {envoi ? 'Envoi…' : 'Signer et souscrire'}
      </button>
      <button type="button" className="btn" onClick={() => { setOuvert(false); setErreur(null) }}>
        Annuler
      </button>
    </div>
  )
}

// `produit` = entrée du catalogue { produit(id), code, libelle, timedEntry, disponibilite, abonnement }.
export default function FicheProduit({ produit, langue, onAjouter, onNaviguer }) {
  const [creneaux, setCreneaux] = useState(null)
  const [chargementCr, setChargementCr] = useState(false)
  const [erreurCr, setErreurCr] = useState(null)
  const [creneauChoisi, setCreneauChoisi] = useState(null)
  const [quantite, setQuantite] = useState(1)
  const [ajout, setAjout] = useState(false)
  const [erreurAjout, setErreurAjout] = useState(null)

  const timedEntry = !!produit?.timedEntry
  const nom = libelleProduit(produit, langue)
  const prix = libellePrix(produit?.prix)
  const enRupture = typeof produit?.disponibilite === 'number' && produit.disponibilite <= 0

  useEffect(() => {
    if (!timedEntry || !produit?.produit) return
    let annule = false
    setChargementCr(true)
    setErreurCr(null)
    boutique
      .creneaux(produit.produit)
      .then((r) => {
        if (!annule) setCreneaux(r?.creneaux || [])
      })
      .catch((e) => {
        if (!annule) setErreurCr(e?.message || 'Impossible de charger les horaires.')
      })
      .finally(() => {
        if (!annule) setChargementCr(false)
      })
    return () => {
      annule = true
    }
  }, [timedEntry, produit?.produit])

  async function ajouter() {
    setErreurAjout(null)
    if (timedEntry && !creneauChoisi) {
      setErreurAjout('Veuillez choisir un horaire avant d\'ajouter au panier.')
      return
    }
    setAjout(true)
    try {
      const cr = creneauChoisi ? creneaux?.find((c) => c.creneau === creneauChoisi) : null
      await onAjouter(
        {
          produit: produit.produit,
          quantite,
          creneau: creneauChoisi || undefined,
        },
        cr ? { creneau: cr.creneau, debut: cr.debut, fin: cr.fin } : undefined,
      )
      onNaviguer({ vue: 'panier' })
    } catch (e) {
      setErreurAjout(e?.message || "L'ajout au panier a échoué.")
    } finally {
      setAjout(false)
    }
  }

  const resteCreneau =
    timedEntry && creneauChoisi
      ? creneaux?.find((c) => c.creneau === creneauChoisi)?.reste
      : null

  // ⚠ CE RETOUR ANTICIPÉ EST SOUS LES HOOKS, ET IL DOIT Y RESTER.
  //
  // Il était placé au-dessus du `useEffect` ci-dessus. React compte les hooks à chaque rendu et
  // exige le même nombre : `PublicApp` passe `produit={metaProduits[route.produitId]}`, donc tout
  // rendu où `produit` est absent puis présent fait passer le composant de sept hooks à huit —
  // « Rendered more hooks than during the previous render », écran blanc.
  //
  // ⚠ CORRECTION DE CE QUE J'AVAIS ÉCRIT ICI. J'affirmais que le chemin était « un client qui ouvre
  // un lien direct vers un billet », et que ce n'était pas un cas limite. C'est faux, et mesuré
  // deux fois : dans la page par la revue d'écrans — ouvrir une fiche ne change ni le chemin, ni le
  // hash, ni la requête, l'URL reste `/b/` — et dans le code par moi : `PublicApp` n'extrait de
  // l'URL qu'un identifiant de VITRINE (slug, `?vitrine=`, `#vitrine=`, sinon stockage local).
  // Jamais de produit.
  //
  // LE DÉFAUT EST DONC LATENT, PAS ACTIF : `route.produitId` ne peut être posé que par un clic dans
  // l'application déjà chargée, et `metaProduits` est alors rempli. Le correctif reste juste — un
  // hook après un retour anticipé est une faute quoi qu'il arrive — mais décrire un chemin qui
  // n'existe pas oriente la relecture, et avec autorité.
  //
  // CE QUI LE RENDRAIT ACTIF : donner une URL propre à une fiche produit. C'est une demande produit
  // ouverte — une billetterie où l'on ne peut pas envoyer le lien d'un billet n'a ni partage, ni QR
  // sur affiche, ni lien de campagne, et le bouton Précédent sort de la boutique. Celui qui
  // l'implémentera rendra ce défaut atteignable le jour même : c'est pour lui que ces lignes sont
  // écrites.
  //
  // Ni le build ni le lint ne le voient : ce n'est pas une faute de syntaxe, c'est une règle
  // d'exécution.
  //
  // Produit inconnu (lien périmé ou catalogue non chargé) : message clair plutôt qu'un écran cassé.
  if (!produit) {
    return (
      <section>
        <button type="button" className="btn ghost bq-retour" onClick={() => onNaviguer({ vue: 'vitrine' })}>
          ← Retour à la boutique
        </button>
        <Erreur message="Ce billet n'est plus disponible ou le lien est incorrect." />
      </section>
    )
  }

  return (
    <section aria-labelledby="bq-fp-titre">
      <button type="button" className="btn ghost bq-retour" onClick={() => onNaviguer({ vue: 'vitrine' })}>
        ← Retour à la boutique
      </button>

      <div className="bq-fiche">
        <div className="bq-fiche-media">
          {produit.visuel ? (
            <img src={produit.visuel} alt={nom} />
          ) : (
            <span aria-hidden="true">{nom.slice(0, 1).toUpperCase()}</span>
          )}
        </div>

        <div className="bq-fiche-info">
          <h1 id="bq-fp-titre">{nom}</h1>
          {produit?.code && <p className="bq-carte-code">{produit.code}</p>}
          <div className="bq-carte-tags" style={{ marginTop: 10 }}>
            {timedEntry && <span className="badge info">Horaire à choisir</span>}
            {enRupture && <span className="badge crit">Épuisé</span>}
          </div>

          {prix ? (
            <p className="bq-fiche-prix">{prix}</p>
          ) : (
            <p className="bq-sub" style={{ marginTop: 14 }}>
              Le tarif applicable est calculé et confirmé à l'étape de paiement.
            </p>
          )}

          {timedEntry && (
            <fieldset className="bq-fieldset">
              <legend>Choisissez votre horaire</legend>
              {chargementCr ? (
                <Chargement texte="Chargement des horaires…" />
              ) : erreurCr ? (
                <Erreur message={erreurCr} />
              ) : (creneaux || []).length === 0 ? (
                <p className="empty" style={{ padding: 0, textAlign: 'left' }}>
                  Aucun horaire disponible pour ce billet actuellement.
                </p>
              ) : (
                <ul className="bq-creneaux" role="radiogroup" aria-label="Horaires disponibles">
                  {creneaux.map((c) => {
                    const plein = typeof c.reste === 'number' && c.reste <= 0
                    const actif = creneauChoisi === c.creneau
                    return (
                      <li key={c.creneau}>
                        <button
                          type="button"
                          role="radio"
                          aria-checked={actif}
                          className={`bq-creneau${actif ? ' on' : ''}`}
                          disabled={plein}
                          onClick={() => setCreneauChoisi(c.creneau)}
                        >
                          <span className="bq-creneau-h">{libelleCreneau(c.debut, c.fin)}</span>
                          <span className={`bq-creneau-r${plein ? ' full' : ''}`}>
                            {plein ? 'Complet' : `Reste ${c.reste}`}
                          </span>
                        </button>
                      </li>
                    )
                  })}
                </ul>
              )}
            </fieldset>
          )}

          <div className="bq-qte">
            <label htmlFor="bq-qte-input">Quantité</label>
            <div className="bq-qte-ctrl">
              <button
                type="button"
                aria-label="Diminuer la quantité"
                onClick={() => setQuantite((n) => Math.max(1, n - 1))}
                disabled={quantite <= 1}
              >
                −
              </button>
              <input
                id="bq-qte-input"
                className="input"
                type="number"
                min="1"
                value={quantite}
                onChange={(e) => setQuantite(Math.max(1, parseInt(e.target.value, 10) || 1))}
              />
              <button
                type="button"
                aria-label="Augmenter la quantité"
                onClick={() => setQuantite((n) => n + 1)}
                disabled={typeof resteCreneau === 'number' && quantite >= resteCreneau}
              >
                +
              </button>
            </div>
          </div>

          <Erreur message={erreurAjout} id="bq-fp-err" />

          {/* ⚠ UN ABONNEMENT NE S'AJOUTE PAS AU PANIER. Il crée une vente et signe un mandat de
              prélèvement dans le même geste. Les deux chemins s'excluent donc, et l'écran ne doit
              pas proposer les deux : un « ajouter au panier » sur un abonnement mènerait à un
              tunnel de paiement qui n'a rien à encaisser. */}
          {produit.abonnement ? (
            <Abonnement produit={produit} onNaviguer={onNaviguer} />
          ) : (
            <button
              type="button"
              className="btn primary lg"
              onClick={ajouter}
              disabled={ajout || enRupture || (timedEntry && !creneauChoisi)}
              aria-describedby={erreurAjout ? 'bq-fp-err' : undefined}
            >
              {ajout ? 'Ajout…' : 'Ajouter au panier'}
            </button>
          )}
        </div>
      </div>
    </section>
  )
}
