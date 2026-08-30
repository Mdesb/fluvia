import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { euros } from '../api/produit.js'

// Les tarifs d'un produit — créer une ligne de prix, et corriger un prix existant.
//
// « Malgré les droits, je n'arrive pas à modifier le prix dans un article ou le tarif. » La raison
// n'était pas les droits : `GrilleTarifaire` expose `Post` et `Patch` depuis le début, et le front ne
// l'appelait qu'en lecture. Encore un des 181 — celui-là, Maxime l'a rencontré en essayant de
// travailler.
//
// TROIS CHOSES QUE CET ÉCRAN DIT PLUTÔT QUE DE LES LAISSER DÉCOUVRIR :
//
// 1. **On ne supprime pas un tarif.** L'API n'expose pas `Delete`, et ce n'est pas un oubli : un prix
//    engagé dans des ventes passées ne s'efface pas sans réécrire l'histoire. On corrige, on n'efface
//    pas — et l'écran l'annonce au lieu d'offrir un bouton qui échouerait.
//
// 2. **Un tarif est un triplet** produit × type × saison, unique en base. Proposer deux fois le même
//    donnerait un refus serveur incompréhensible ; l'écran retire des choix ceux qui existent déjà.
//
// 3. **Sans type de tarif, rien n'est possible.** Le message le dit et renvoie là où on les crée,
//    plutôt que d'afficher une liste vide dont on ne sait que faire.

export default function TarifsProduit({ produit, grilles, peutModifier, onChange }) {
  const [types, setTypes] = useState([])
  const [saisons, setSaisons] = useState([])
  const [ajout, setAjout] = useState(null)
  const [edition, setEdition] = useState(null) // { id, prix }
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!peutModifier) return
    Promise.all([api.typeTarifs().catch(() => null), api.saisons().catch(() => null)]).then(([t, s]) => {
      setTypes(t ? membres(t).filter((x) => x.actif !== false) : [])
      setSaisons(s ? membres(s).filter((x) => x.actif !== false) : [])
    })
  }, [peutModifier])

  // Un triplet déjà pris n'est pas proposé : le serveur le refuserait, et son message parlerait de
  // contrainte d'unicité — ce qui n'aide personne.
  const dejaPris = new Set(
    (grilles || []).map((g) => `${g.typeTarif?.id || ''}|${g.saison?.id || ''}`),
  )

  async function creer(e) {
    e.preventDefault()
    setErreur(null)
    setEnCours(true)
    try {
      await api.creerGrilleTarifaire({
        produit: `/api/produits/${produit.id}`,
        typeTarif: `/api/type_tarifs/${ajout.typeTarif}`,
        ...(ajout.saison ? { saison: `/api/saisons/${ajout.saison}` } : {}),
        prix: Number(ajout.prix).toFixed(2),
      })
      setAjout(null)
      await onChange?.()
    } catch (err) {
      setErreur(err.message || "Le tarif n'a pas pu être ajouté.")
    } finally {
      setEnCours(false)
    }
  }

  async function enregistrerPrix(e) {
    e.preventDefault()
    setErreur(null)
    setEnCours(true)
    try {
      await api.majGrilleTarifaire(edition.id, { prix: Number(edition.prix).toFixed(2) })
      setEdition(null)
      await onChange?.()
    } catch (err) {
      setErreur(err.message || "Le prix n'a pas pu être modifié.")
    } finally {
      setEnCours(false)
    }
  }

  const typesDisponibles = types.filter((t) => !dejaPris.has(`${t.id}|`))

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}

      {/* ⚠ LA MEME PHRASE ETAIT VRAIE ET FAUSSE SELON LE PRODUIT QUI LA PORTAIT.
          << sa publication sera refusee >> est exact pour un brouillon : `PublicationGuard`
          exige un libelle, un site, un canal, un prix et une categorie comptable, et
          `TransitionProduitHandler::publier()` rend 422 en les listant. Mais la phrase
          s'affichait AUSSI sous un produit deja publie, ou elle annonce au futur un refus
          que rien n'a oppose -- juste sous un badge qui dit << Publie >>.
          Mesure du 30/08 : 3 produits publies sur 8 n'ont aucune grille, sur les quatre
          etablissements -- PRD-AUDIOGUIDE, PRD-PASS-MUSEE, PRD-EXPO-EGYPTE.

          D'OU VIENT CET ETAT, mesure et non suppose. La garde ne s'execute QUE sur la
          transition `/publier`, et rien ne la rejoue ensuite. Mais aucun ecran ne peut y
          conduire : `GrilleTarifaire` n'expose pas `Delete`, et l'entree de prix porte
          `required` -- on ne peut ni supprimer un tarif, ni le vider. Et `statut` n'est
          dans aucun groupe d'ecriture : l'API ne permet pas de publier autrement que par
          la transition. Ces trois produits viennent donc des donnees de demarrage, qui
          ecrivent en base sans passer par la garde.

          Ce qui reste vrai malgre tout : `prix` est nullable et `Patch` est expose sur la
          grille. Un appelant direct peut donc remettre un prix a null (`Un prix null vaut
          << non commercialise >>`, dit l'entite) sur le seul tarif d'un produit publie, et
          RIEN ne le detectera. L'ecran doit donc savoir afficher cet etat -- c'est ce qu'il
          fait ici -- meme s'il ne sait pas le produire. */}
      {(grilles || []).length === 0 ? (
        <div className="empty" style={{ padding: 14 }}>
          {produit?.statut === 'publie' ? (
            <>
              Aucun tarif — et ce produit est <b>publié</b>. Il n’est donc vendable nulle part,
              ni au guichet ni en ligne, malgré son statut. Ajoutez-lui un tarif ci-dessous.
            </>
          ) : (
            <>
              Aucun tarif. Un produit sans tarif ne peut pas être vendu, et sa publication sera
              refusée : il faut aussi un site, un canal et une catégorie comptable.
            </>
          )}
        </div>
      ) : (
        <table className="tbl">
          <thead>
            <tr>
              <th>Type de tarif</th>
              <th>Période</th>
              <th className="num">Prix</th>
              {peutModifier && <th />}
            </tr>
          </thead>
          <tbody>
            {grilles.map((g) => (
              <tr key={g.id}>
                <td>{g.typeTarif?.nom || g.typeTarif?.code || '—'}</td>
                <td>{g.saison?.nom || <span className="sub">Toute l'année</span>}</td>
                <td className="num">
                  {edition?.id === g.id ? (
                    <form onSubmit={enregistrerPrix} style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                      <input
                        className="input"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        autoFocus
                        value={edition.prix}
                        onChange={(ev) => setEdition((s) => ({ ...s, prix: ev.target.value }))}
                        style={{ width: 110 }}
                      />
                      <button className="btn primary sm" type="submit" disabled={enCours}>Enregistrer</button>
                      <button className="btn ghost sm" type="button" onClick={() => setEdition(null)}>Annuler</button>
                    </form>
                  ) : (
                    euros(g.prix)
                  )}
                </td>
                {peutModifier && (
                  <td className="num">
                    {edition?.id !== g.id && (
                      <button
                        className="btn ghost sm"
                        type="button"
                        onClick={() => setEdition({ id: g.id, prix: g.prix ?? '' })}
                      >
                        Modifier le prix
                      </button>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {peutModifier && !ajout && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 10 }}>
          <button
            className="btn primary sm"
            type="button"
            disabled={types.length === 0}
            onClick={() => setAjout({ typeTarif: typesDisponibles[0]?.id || '', saison: '', prix: '' })}
          >
            ＋ Ajouter un tarif
          </button>
          {types.length === 0 && (
            <span className="hint" style={{ margin: 0 }}>
              Aucun type de tarif n'existe encore. Créez-en un dans Paramètres, onglet Catalogue et
              référentiels.
            </span>
          )}
          {/* Dit une fois, calmement, plutôt que découvert au moment où l'on cherche le bouton. */}
          <span className="hint" style={{ margin: 0, marginLeft: 'auto' }}>
            Un tarif se corrige, il ne se supprime pas.
          </span>
        </div>
      )}

      {ajout && (
        <form onSubmit={creer} className="card" style={{ marginTop: 10 }}>
          <div className="card-b">
            <div className="grid g3" style={{ gap: 12 }}>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="gt-type">Type de tarif *</label>
                <select
                  id="gt-type"
                  className="select"
                  required
                  value={ajout.typeTarif}
                  onChange={(e) => setAjout((s) => ({ ...s, typeTarif: e.target.value }))}
                >
                  {typesDisponibles.length === 0 && <option value="">Tous déjà utilisés</option>}
                  {typesDisponibles.map((t) => (
                    <option key={t.id} value={t.id}>{t.nom}</option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="gt-saison">Période</label>
                <select
                  id="gt-saison"
                  className="select"
                  value={ajout.saison}
                  onChange={(e) => setAjout((s) => ({ ...s, saison: e.target.value }))}
                >
                  <option value="">Toute l'année</option>
                  {saisons.map((s) => (
                    <option key={s.id} value={s.id}>{s.nom}</option>
                  ))}
                </select>
                <div className="hint">Un prix différent pendant une période de l'année.</div>
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="gt-prix">Prix *</label>
                <input
                  id="gt-prix"
                  className="input"
                  type="number"
                  step="0.01"
                  min="0"
                  required
                  value={ajout.prix}
                  onChange={(e) => setAjout((s) => ({ ...s, prix: e.target.value }))}
                />
              </div>
            </div>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 10 }}>
              <button className="btn" type="button" onClick={() => setAjout(null)}>Annuler</button>
              <button className="btn primary" type="submit" disabled={enCours || !ajout.typeTarif}>
                {enCours ? 'Ajout…' : 'Ajouter'}
              </button>
            </div>
          </div>
        </form>
      )}
    </>
  )
}
