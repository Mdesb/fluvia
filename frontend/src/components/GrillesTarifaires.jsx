import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { libelleProduit } from '../api/produit.js'
import { idDe } from '../api/iri.js'

/**
 * LES GRILLES TARIFAIRES, VUES D'ENSEMBLE — la lecture que personne n'appelait.
 *
 * `api.grilleTarifaires()` existe dans le client et n'est appelée par aucun écran. Les grilles se
 * créent et se modifient produit par produit, depuis la section Tarifs d'une fiche — ce qui marche,
 * et qui rend une question courante impossible : **quels produits n'ont pas de prix pour la saison
 * qui commence ?** Il fallait ouvrir chaque fiche, une par une.
 *
 * ⚠ CET ÉCRAN LIT ET MÈNE, IL NE MODIFIE PAS. Poser ici un second endroit où éditer un prix
 * recréerait exactement ce que Maxime reprochait à la fiche produit : « on a l'impression de faire
 * deux fois ». Le prix se change là où il se change déjà ; d'ici, on clique et on y va.
 *
 * ⚠ ET LE BLOC QUI COMPTE EST CELUI DES ABSENTS. Une grille manquante ne s'affiche nulle part —
 * c'est une ligne qui n'existe pas. La lister demande de croiser le catalogue avec les grilles, ce
 * qu'aucun écran ne faisait.
 */
export default function GrillesTarifaires({ etabActif, majParams }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [grilles, setGrilles] = useState(null)
  const [produits, setProduits] = useState(null)
  const [saisons, setSaisons] = useState(null)
  const [saison, setSaison] = useState('')

  // ⚠ « PAS PU LIRE » NE DIT PAS POURQUOI, ET LA RAISON LA PLUS FRÉQUENTE N'EST PAS UNE PANNE.
  //
  // Ces trois lectures exigent toutes `offre.lire` — vérifié sur les trois entités. Un compte qui
  // ne l'a pas voyait « les grilles n'ont pas pu être lues » et partait chercher un incident
  // technique, alors qu'il lui suffisait qu'on lui accorde une permission. Le message avait
  // d'autant plus de force qu'il avait l'air d'une information.
  //
  // Un seul drapeau pour les trois : c'est le même droit, donc la même phrase. En poser trois
  // laisserait croire qu'ils peuvent diverger.
  const [refus, setRefus] = useState(false)

  useEffect(() => {
    setGrilles(null)
    setRefus(false)
    const echec = (poser) => (e) => {
      if (e?.status === 403) setRefus(true)
      poser(undefined)
    }
    api.grilleTarifaires().then((r) => setGrilles(membres(r))).catch(echec(setGrilles))
    api.produits({ itemsPerPage: 200 }).then((r) => setProduits(membres(r))).catch(echec(setProduits))
    api.saisons().then((r) => setSaisons(membres(r))).catch(echec(setSaisons))
  }, [etabActif])

  // ⚠ LE PRODUIT ARRIVE EN IDENTIFIANT NU. `Produit` n'a aucun champ dans le groupe `grille:read` —
  // vérifié — alors que le type de tarif et la saison, eux, arrivent complets. On résout donc le
  // nom depuis le catalogue, et on distingue « catalogue non lu » de « produit inconnu ».
  const nomProduit = useMemo(() => {
    const m = {}
    for (const p of produits || []) m[String(p.id)] = libelleProduit(p)
    return m
  }, [produits])

  // `idDe` vient d'`api/iri.js` (§8.3, garde-fou n°53). Cette page en avait posé une copie :
  // `.split('/').pop()` rend la chaîne VIDE sur une référence terminée par un slash, là où la
  // canonique filtre les segments vides. Même contrat de retour (`null` quand il n'y a rien),
  // donc les trois appels ci-dessous n'ont pas bougé.

  const filtrees = useMemo(() => {
    if (!Array.isArray(grilles)) return []
    if (saison === '') return grilles
    return grilles.filter((g) => idDe(g.saison) === saison)
  }, [grilles, saison])

  // ⚠ LES ABSENTS NE SE CALCULENT QUE SI LES DEUX LECTURES ONT ABOUTI. Croiser un catalogue lu
  // avec des grilles illisibles déclarerait TOUT LE MONDE sans tarif — l'alerte la plus fausse
  // possible, et la plus crédible.
  const sansTarif = useMemo(() => {
    if (!Array.isArray(grilles) || !Array.isArray(produits)) return null
    const couverts = new Set(filtrees.map((g) => idDe(g.produit)).filter(Boolean))
    return produits.filter((p) => !couverts.has(String(p.id)))
  }, [grilles, produits, filtrees])

  function ouvrirProduit(id) {
    if (!id) return
    // Même geste que la recherche globale : on bascule d'onglet ET on ouvre la fiche.
    majParams({ tab: 'produits', fiche: String(id) }, { pousser: true })
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--esp-bloc)' }}>
      <section className="card">
        <div className="card-h">
          <h3>Grilles tarifaires</h3>
          <span className="sub">
            {grilles === null ? 'lecture…' : grilles === undefined ? 'illisible' : `${filtrees.length} tarif(s)`}
          </span>
          {Array.isArray(saisons) && saisons.length > 0 && (
            <select
              className="select"
              value={saison}
              onChange={(e) => setSaison(e.target.value)}
              style={{ marginLeft: 'auto', maxWidth: 260 }}
              aria-label="Filtrer par saison"
            >
              <option value="">Toutes les saisons</option>
              {saisons.map((s) => <option key={s.id} value={String(s.id)}>{s.libelle || s.nom || s.id}</option>)}
            </select>
          )}
        </div>

        <div className="card-b">
          <div className="sub" style={{ marginBottom: 'var(--esp-normal)' }}>
            Cet écran montre les prix, il ne les change pas&nbsp;: cliquez un produit pour aller les
            modifier dans sa fiche, là où ils se règlent déjà.
          </div>

          {grilles === undefined && (
            <div className="banner banner-warn">
              Les grilles n’ont pas pu être lues. Cet écran ne sait donc pas quels produits ont un
              prix — ce n’est pas la même chose que « aucun ».
              {refus && (
                <>
                  {' '}<strong>Ce n’est pas une panne</strong> : ce compte n’a pas le droit de lire
                  le catalogue de cet établissement. Demandez la permission <code>offre.lire</code> à
                  un administrateur.
                </>
              )}
            </div>
          )}

          {grilles === null && <div className="empty">Lecture des grilles…</div>}

          {Array.isArray(grilles) && filtrees.length === 0 && (
            <div className="empty">
              {saison === ''
                ? 'Aucune grille tarifaire. Un produit sans grille n’a pas de prix, et ne peut pas être vendu.'
                : 'Aucune grille pour cette saison.'}
            </div>
          )}

          {filtrees.length > 0 && (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Produit</th>
                    <th>Type de tarif</th>
                    <th>Saison</th>
                    <th className="num">Prix</th>
                    <th>Tranche</th>
                  </tr>
                </thead>
                <tbody>
                  {filtrees.map((g) => {
                    const pid = idDe(g.produit)
                    const nom = pid ? nomProduit[pid] : null
                    return (
                      <tr key={g.id}>
                        <td>
                          {produits === undefined ? (
                            <span className="sub">catalogue non lu</span>
                          ) : nom ? (
                            <button type="button" className="lnk" onClick={() => ouvrirProduit(pid)}>
                              {nom}
                            </button>
                          ) : (
                            <span className="sub">produit inconnu</span>
                          )}
                        </td>
                        <td>{g.typeTarif?.libelle || g.typeTarif?.code || <span className="sub">—</span>}</td>
                        <td>{g.saison?.libelle || g.saison?.nom || <span className="sub">hors saison</span>}</td>
                        <td className="num">{g.prix ?? <span className="sub">—</span>}</td>
                        <td>{g.trancheQf?.libelle || <span className="sub">—</span>}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </section>

      {/* ⚠ LE BLOC QU'AUCUN ÉCRAN NE POUVAIT RENDRE. Une grille manquante est une ligne qui
          n'existe pas : elle ne s'affiche nulle part, et on ne la découvre qu'au moment où la
          caisse refuse de vendre. */}
      <section className="card">
        <div className="card-h">
          <h3>Produits sans prix</h3>
          <span className="sub">
            {sansTarif === null
              ? 'calcul impossible'
              : `${sansTarif.length}${saison === '' ? '' : ' pour cette saison'}`}
          </span>
        </div>
        <div className="card-b">
          {sansTarif === null ? (
            <div className="banner banner-warn">
              Il manque une des deux lectures — catalogue ou grilles. Cet écran ne peut pas dire qui
              est sans prix, et il ne l’invente pas&nbsp;: une liste calculée sur une lecture ratée
              déclarerait tout le catalogue sans tarif.
            </div>
          ) : sansTarif.length === 0 ? (
            <div className="empty">
              Tous les produits ont un prix{saison === '' ? '' : ' pour cette saison'}.
            </div>
          ) : (
            <>
              <div className="sub" style={{ marginBottom: 'var(--esp-normal)' }}>
                Ces produits n’ont aucune grille{saison === '' ? '' : ' pour la saison choisie'}. Ils
                ne peuvent pas être vendus tant qu’un prix n’est pas posé.
              </div>
              <div style={{ display: 'flex', gap: 'var(--esp-normal)', flexWrap: 'wrap' }}>
                {sansTarif.map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    className="btn ghost sm"
                    onClick={() => ouvrirProduit(p.id)}
                    title="Ouvrir la fiche pour y poser un tarif"
                  >
                    {libelleProduit(p)}
                  </button>
                ))}
              </div>
            </>
          )}
        </div>
      </section>
    </div>
  )
}
