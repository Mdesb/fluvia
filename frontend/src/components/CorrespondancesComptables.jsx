import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri'

// LES CORRESPONDANCES COMPTABLES — CE QUI DÉCIDE DU COMPTE DE PRODUIT, ET QUI N'AVAIT PAS D'ÉCRAN.
//
// Une ligne de vente porte une catégorie comptable ; la correspondance dit sur quel compte de
// produit et à quel taux de TVA cette catégorie s'enregistre. Le serveur porte la table depuis
// l'origine du module, et rien ne permettait de la remplir : sur la préprod, UNE seule
// correspondance existait.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// CE QUI SE PASSE SANS CORRESPONDANCE — MESURÉ DANS LE CODE, PAS SUPPOSÉ.
//
// On m'a d'abord dit qu'une catégorie non mappée « tombe sur le compte par défaut ». C'est faux, et
// la vérité est plus grave. Trois sources concordent :
//
//   `MappingComptableGuard::anomalies()`  mapping absent ou invalide → une anomalie
//   `GenerateurEcrituresHandler` l.89     s'il y a une anomalie → `continue`, la vente est SAUTÉE
//   `RegimeBase` l.42                     ligne sans mapping valide → `continue`, aucune écriture
//
// Il n'existe aucun repli sur un compte fourre-tout dans ce chemin. **Une catégorie sans
// correspondance ne produit pas une écriture mal rangée : elle ne produit pas d'écriture du tout**,
// et la vente ressort en anomalie à la génération.
//
// La différence n'est pas de nuance. « Vos produits vont sur un compte par défaut » invite à ranger
// plus tard ; « vos ventes ne sont pas comptabilisées » est une conversation avec l'expert-comptable
// à la clôture. C'est cette phrase-là que l'écran porte.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// LA LISTE PART DES CATÉGORIES, PAS DES CORRESPONDANCES — ET C'EST TOUT L'ÉCRAN.
//
// Lister les correspondances existantes montrerait ce qui est fait et cacherait ce qui manque. Or
// ce qu'on cherche ici, c'est exactement le manque : une catégorie sans ligne est invisible dans une
// liste de lignes. Une ligne par catégorie, donc, avec ou sans compte — l'absence se voit au lieu de
// se déduire.
//
// ⚠ UNE CORRESPONDANCE PEUT EXISTER ET NE RIEN FAIRE. `MappingComptable::estValide()` exige que le
// compte ET le taux soient ACTIFS. Une correspondance qui pointe un compte désactivé produit la même
// anomalie qu'une correspondance absente — et se lit « en place » si on ne regarde que sa présence.
//
// LE VERDICT VIENT DU SERVEUR, LA CAUSE AUSSI — ET C'EST UN CHANGEMENT DE CE MATIN.
//
// La première version de cet écran rejouait la règle : elle croisait les référentiels pour savoir si
// le compte et le taux étaient actifs. C'était la seule façon de le savoir, `mapping:read` ne portant
// alors ni l'un ni l'autre. Mais un écran qui réimplémente une règle du serveur finit par en
// diverger — c'est la leçon écrite dans `droits.js`, payée sur les permissions joker.
//
// La charge utile porte désormais les deux, mesurée à l'écran avant d'être utilisée :
//
//     operante   le VERDICT, calculé par `estValide()` côté serveur
//     actif      sur le compte ET sur le taux embarqués — la CAUSE, pour dire lequel
//
// On lit donc le verdict, et on ne s'en sert des référentiels que pour ce que la charge utile ne
// dit pas : qu'une référence pointe hors du site.

// Le filtre `axe` n'existe pas sur `/api/categories` : vérifié en comparant les réponses avec et
// sans — même total, même contenu. L'envoyer donnerait une liste NON filtrée qui a l'air filtrée,
// donc on trie ici. (Si le filtre est déclaré un jour, ce tri deviendra redondant, jamais faux.)
const AXE_COMPTABLE = 'comptable'

export default function CorrespondancesComptables({ etabActif, droits = [] }) {
  const [categories, setCategories] = useState([])
  const [comptes, setComptes] = useState([])
  const [taux, setTaux] = useState([])
  const [mappings, setMappings] = useState([])
  const [profil, setProfil] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [edition, setEdition] = useState(null) // { categorieId, compte, taux, enCours, erreur }

  const peutLire = aLeDroit(droits, 'compta.lire')
  const peutGerer = aLeDroit(droits, 'compta.gerer')

  const charger = useCallback(async () => {
    if (!peutLire) return
    setChargement(true)
    setErreur(null)
    const [c, cc, t, m, p] = await Promise.allSettled([
      api.categories(),
      api.comptesComptables(),
      api.tauxTvas(),
      api.mappingsComptables(),
      api.profilsExploitant(),
    ])
    // Chaque lecture porte son propre échec : un référentiel manquant ne doit pas faire croire que
    // les correspondances sont vides, ni l'inverse.
    setCategories(c.status === 'fulfilled' ? membres(c.value).filter((x) => x.axe === AXE_COMPTABLE) : [])
    setComptes(cc.status === 'fulfilled' ? membres(cc.value) : [])
    setTaux(t.status === 'fulfilled' ? membres(t.value) : [])
    setMappings(m.status === 'fulfilled' ? membres(m.value) : [])
    setProfil(p.status === 'fulfilled' ? membres(p.value)[0] || null : null)
    const rate = [c, cc, t, m, p].find((r) => r.status === 'rejected')
    if (rate) {
      setErreur(
        rate.reason?.status === 403
          ? 'Ce compte n’a pas le droit de lire la comptabilité de cet établissement.'
          : rate.reason?.message || 'Chargement incomplet.',
      )
    }
    setChargement(false)
  }, [peutLire])

  useEffect(() => {
    charger()
  }, [etabActif, charger])

  const lignes = useMemo(() => {
    const parCategorie = new Map(mappings.map((m) => [String(m.categorie), m]))
    const compteParId = new Map(comptes.map((c) => [c.id, c]))
    const tauxParId = new Map(taux.map((t) => [t.id, t]))

    return categories.map((cat) => {
      const mapping = parCategorie.get(String(cat.id)) || null
      const compte = mapping ? compteParId.get(idDe(mapping.compteProduit)) : null
      const tva = mapping ? tauxParId.get(idDe(mapping.tauxTva)) : null
      // Le référentiel fait foi sur `actif` : la charge utile de la correspondance ne le porte pas.
      // Le verdict est celui du serveur. `operante` absent (serveur plus ancien) : on retombe sur la
      // lecture des `actif` embarqués plutôt que de conclure « en place » par défaut — l'ancien
      // défaut était précisément de croire qu'une ligne présente était une ligne qui agit.
      const inactif =
        mapping !== null
        && (mapping.operante === false
          || (mapping.operante === undefined
            && ((compte && compte.actif === false) || (tva && tva.actif === false))))
      // La cause, pour que la phrase contienne son geste : c'est le compte, ou le taux, ou les deux.
      const causes = []
      if (mapping) {
        if (mapping.compteProduit?.actif === false || compte?.actif === false) causes.push('le compte')
        if (mapping.tauxTva?.actif === false || tva?.actif === false) causes.push('le taux de TVA')
      }
      const introuvable = mapping !== null && (!compte || !tva)
      return { categorie: cat, mapping, compte, tva, inactif, introuvable, causes }
    })
  }, [categories, mappings, comptes, taux])

  const sansCorrespondance = lignes.filter((l) => l.mapping === null || l.inactif || l.introuvable).length

  if (!peutLire) return null

  function ouvrir(ligne) {
    setSucces(null)
    setEdition({
      categorieId: ligne.categorie.id,
      libelle: ligne.categorie.libelle || ligne.categorie.nom || '',
      mappingId: ligne.mapping?.id || null,
      compte: ligne.compte?.id || comptes.find((c) => c.actif !== false)?.id || '',
      taux: ligne.tva?.id || taux.find((t) => t.actif !== false)?.id || '',
      enCours: false,
      erreur: null,
    })
  }

  async function enregistrer(e) {
    e.preventDefault()
    setEdition((s) => ({ ...s, enCours: true, erreur: null }))
    try {
      const corps = {
        profilExploitant: `/api/profil_exploitants/${profil.id}`,
        categorie: edition.categorieId,
        compteProduit: `/api/compte_comptables/${edition.compte}`,
        tauxTva: `/api/taux_tvas/${edition.taux}`,
      }
      if (edition.mappingId) await api.majMappingComptable(edition.mappingId, corps)
      else await api.creerMappingComptable(corps)
      setEdition(null)
      setSucces('Correspondance enregistrée : les ventes de cette catégorie seront comptabilisées.')
      await charger()
    } catch (err) {
      // Dans le formulaire, jamais derrière : l'unicité (profil, catégorie) et les champs
      // obligatoires refusent en 422, et c'est le cas normal ici.
      setEdition((s) => ({ ...s, enCours: false, erreur: err.message || "L'enregistrement n'a pas abouti." }))
    }
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>Correspondances comptables</h3>
        {sansCorrespondance > 0 && (
          <span className="badge crit">{sansCorrespondance} catégorie(s) sans correspondance active</span>
        )}
        <div className="r" style={{ marginLeft: 'auto' }}>
          <button title="Actualiser" className="btn ghost sm" onClick={charger} disabled={chargement}>↻</button>
        </div>
      </div>

      <div className="card-b">
        <p className="hint" style={{ marginTop: 0 }}>
          Chaque catégorie comptable dit sur quel compte de produit et à quel taux de TVA ses ventes
          s’enregistrent. <strong>Sans correspondance active, les ventes de la catégorie ne sont pas
          comptabilisées du tout</strong> : elles ressortent en anomalie à la génération des écritures,
          et n’apparaissent sur aucun compte.
        </p>

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : categories.length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>
            Aucune catégorie comptable sur ce site. Les catégories sont posées à l’ouverture de la
            structure ; tant qu’il n’y en a pas, aucune vente ne peut être rattachée à un compte de
            produit.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Catégorie</th>
                  <th>Compte de produit</th>
                  <th>TVA</th>
                  <th>État</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {lignes.map((l) => (
                  <tr key={l.categorie.id}>
                    <td className="nm">{l.categorie.libelle || l.categorie.nom}</td>
                    <td>
                      {l.compte ? (
                        <>
                          <span className="mono">{l.compte.numero}</span> {l.compte.libelle}
                        </>
                      ) : (
                        <span className="mut">—</span>
                      )}
                    </td>
                    <td>{l.tva ? `${l.tva.taux} %` : <span className="mut">—</span>}</td>
                    <td>
                      {/* Trois états, trois causes distinctes — et deux d'entre eux se ressemblent
                          à s'y méprendre si on ne regarde que la présence de la ligne. */}
                      {l.mapping === null ? (
                        <span className="badge crit">à définir</span>
                      ) : l.introuvable ? (
                        <span className="badge crit" title="Le compte ou le taux n’est pas dans le référentiel de ce site.">
                          référence introuvable
                        </span>
                      ) : l.inactif ? (
                        <>
                          <span className="badge crit">inopérante</span>
                          {/* Un verdict sans cause fait chercher : sans cette phrase, l'exploitant
                              ouvre le plan de comptes, puis les taux, et compare à la main. */}
                          <div className="mut">
                            {l.causes.length > 0
                              ? `${l.causes.join(' et ')} ${l.causes.length > 1 ? 'sont désactivés' : 'est désactivé'}`
                              : 'compte ou taux désactivé'}
                          </div>
                        </>
                      ) : (
                        <span className="badge good">en place</span>
                      )}
                    </td>
                    {peutGerer && (
                      <td className="num">
                        <button className="btn ghost sm" onClick={() => ouvrir(l)}>
                          {l.mapping ? 'Modifier' : 'Définir'}
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {edition && (
          <form onSubmit={enregistrer} style={{ borderTop: '1px solid var(--line)', marginTop: 12, paddingTop: 12 }}>
            <div className="fiche-sec">{edition.mappingId ? 'Modifier' : 'Définir'} — {edition.libelle}</div>
            {edition.erreur && <div className="banner banner-error">{edition.erreur}</div>}

            <div className="field">
              <label htmlFor="corr-compte">Compte de produit *</label>
              <select
                id="corr-compte"
                className="select"
                value={edition.compte}
                onChange={(e) => setEdition((s) => ({ ...s, compte: e.target.value }))}
                required
              >
                {comptes.map((c) => (
                  <option key={c.id} value={c.id} disabled={c.actif === false}>
                    {c.numero} — {c.libelle}{c.actif === false ? ' (désactivé)' : ''}
                  </option>
                ))}
              </select>
              <div className="hint" style={{ marginTop: 4 }}>
                Les comptes désactivés sont proposés grisés : les choisir rendrait la correspondance
                inopérante, autant le voir avant.
              </div>
            </div>

            <div className="field">
              <label htmlFor="corr-taux">Taux de TVA *</label>
              <select
                id="corr-taux"
                className="select"
                value={edition.taux}
                onChange={(e) => setEdition((s) => ({ ...s, taux: e.target.value }))}
                required
              >
                {taux.map((t) => (
                  <option key={t.id} value={t.id} disabled={t.actif === false}>
                    {t.taux} % — {t.libelle}{t.actif === false ? ' (désactivé)' : ''}
                  </option>
                ))}
              </select>
            </div>

            <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
              <button className="btn" type="button" onClick={() => setEdition(null)} disabled={edition.enCours}>
                Annuler
              </button>
              <button className="btn primary" type="submit" disabled={edition.enCours || !profil}>
                {edition.enCours ? 'Enregistrement…' : 'Enregistrer'}
              </button>
            </div>
            {!profil && (
              <div className="hint">
                Aucun profil exploitant sur ce site : la correspondance ne peut pas être rattachée.
              </div>
            )}
          </form>
        )}
      </div>
    </section>
  )
}
