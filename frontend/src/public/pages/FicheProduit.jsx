import { useEffect, useState } from 'react'
import { boutique } from '../api/boutiqueClient.js'
import { libelleProduit, libelleCreneau, libellePrix } from '../lib/format.js'
import { Chargement, Erreur } from '../components/Etats.jsx'

// Fiche produit : détail + choix de créneau (timed-entry) + quantité + ajout au panier.
// `produit` = entrée du catalogue { produit(id), code, libelle, timedEntry, disponibilite }.
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

          <button
            type="button"
            className="btn primary lg"
            onClick={ajouter}
            disabled={ajout || enRupture || (timedEntry && !creneauChoisi)}
            aria-describedby={erreurAjout ? 'bq-fp-err' : undefined}
          >
            {ajout ? 'Ajout…' : 'Ajouter au panier'}
          </button>
        </div>
      </div>
    </section>
  )
}
