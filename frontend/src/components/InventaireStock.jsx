import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import { dateHeureFr } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { confirmer } from './Confirmation.jsx'

// Le cycle d'inventaire : lancer, compter, régulariser, clôturer.
//
// DEUX CHOSES LUES DANS LE SERVICE AVANT D'ÉCRIRE UN SEUL CHAMP, ET QUI CHANGENT L'ÉCRAN.
//
// 1. **« Un rayon » n'est pas proposé, parce que ce n'est pas un périmètre.** L'énumération contient
//    `rayon`, le serveur l'accepte — et le filtre n'est appliqué que pour `selection` :
//    `if ($perimetre === PerimetreInventaire::Selection && $filtre !== null)`. Choisir « un rayon »
//    lance donc un inventaire sur **tous** les articles en croyant l'avoir restreint. Un choix qui
//    ment sur ce qu'il fait est pire qu'un choix absent : l'exploitant compte trois cents références
//    en pensant en compter trente. On n'offre que ce qui existe.
//
// 2. **Le théorique vient du stock du PRODUIT, pas des lots.** `disponibiliteEffective()` du produit
//    rattaché, `?? 0` sinon. Un article qui n'est rattaché à aucun produit part donc d'un théorique
//    de zéro — et compter les quarante-sept unités réellement présentes fabrique un écart de +47 qui
//    n'est pas un écart. L'écran le dit ligne par ligne au lieu de laisser régulariser à l'aveugle.
//
// Le nom de l'article n'est pas sérialisé dans `ligne_inventaire:read` : `ArticleStock` ne porte que
// des groupes `article:read`. On recoupe donc avec la liste d'articles déjà chargée par l'écran
// parent — pas un appel de plus, et le nom vaut mieux qu'un identifiant.

export default function InventaireStock({ articles, droits, etabActif, onErreur, onFait }) {
  // ⚠ `null` = PAS LU. Il ne sort pas d'ici : tout l'aval lit un tableau.
  const [inventairesLus, setInventairesLus] = useState(null)
  const inventaires = inventairesLus || []
  const [lignes, setLignes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [lancement, setLancement] = useState(false)

  const peutInventorier = aUnDesDroits(droits, ['stock.inventorier', 'stock.gerer'])
  const peutValiderEcart = aUnDesDroits(droits, ['stock.valider_ecart', 'stock.gerer'])

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const [i, l] = await Promise.all([api.stockInventaires(), api.stockLignesInventaire()])
      setInventairesLus(membres(i))
      setLignes(membres(l))
    } catch (e) {
      onErreur(e.message)
      setInventairesLus(null)
    } finally {
      setChargement(false)
    }
  }, [etabActif, onErreur])

  useEffect(() => {
    recharger()
  }, [recharger])

  const parId = useMemo(() => {
    const index = {}
    for (const a of articles) index[a.id] = a
    return index
  }, [articles])

  const enCours = inventaires.find((i) => i.statut !== 'cloture') || null

  const lignesEnCours = useMemo(() => {
    if (!enCours) return []
    return lignes.filter((l) => {
      const id = l.inventaire?.id || String(l.inventaire || '').split('/').pop()
      return id === enCours.id
    })
  }, [lignes, enCours])

  if (chargement) {
    return (
      <section className="card" style={{ marginTop: 16 }}>
        <div className="card-b center" style={{ minHeight: 80 }}><div className="spinner" /></div>
      </section>
    )
  }

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Inventaire</h3>
        <span className="sub">
          {enCours ? `en cours depuis le ${dateHeureFr(enCours.dateLancement)}` : 'compter et corriger le stock réel'}
        </span>
        {peutInventorier && !enCours && (
          <div className="r">
            <button className="btn primary sm" type="button" onClick={() => setLancement(true)}>
              ＋ Lancer un inventaire
            </button>
          </div>
        )}
      </div>

      <div className="card-b">
        {!enCours ? (
          <div className="empty">
            {inventairesLus === null ? <b>La liste des inventaires n’a pas pu être lue : ne concluez
              pas qu’aucun inventaire n’est en cours, la lecture a échoué.</b> : <>
            Aucun inventaire en cours. Un inventaire fige ce que le logiciel croit avoir, vous laisse
            compter ce qu'il y a vraiment, et corrige la différence ligne par ligne — chaque
            correction laissant sa trace dans le journal.
            </>}
            {inventaires.length > 0 && (
              <div style={{ marginTop: 8 }}>
                Dernier inventaire clôturé le {dateHeureFr(inventaires[0]?.dateCloture)}.
              </div>
            )}
          </div>
        ) : (
          <InventaireEnCours
            inventaire={enCours}
            lignes={lignesEnCours}
            parId={parId}
            peutInventorier={peutInventorier}
            peutValiderEcart={peutValiderEcart}
            onRafraichir={recharger}
            onErreur={onErreur}
            onFait={onFait}
          />
        )}
      </div>

      <LancementModal
        open={lancement}
        articles={articles}
        onClose={() => setLancement(false)}
        onFait={(m) => { setLancement(false); recharger(); onFait(m) }}
        onErreur={onErreur}
      />
    </section>
  )
}

function InventaireEnCours({
  inventaire, lignes, parId, peutInventorier, peutValiderEcart, onRafraichir, onErreur, onFait,
}) {
  const [saisies, setSaisies] = useState({})
  const [occupe, setOccupe] = useState(null)

  const nonRattaches = lignes.filter((l) => {
    const a = parId[l.articleStock?.id || String(l.articleStock || '').split('/').pop()]
    return a && !a.produit
  }).length

  const restantes = lignes.filter((l) => l.quantiteComptee == null).length

  async function compter(ligne) {
    const v = saisies[ligne.id]
    if (v === undefined || v === '') return
    setOccupe(ligne.id)
    try {
      await api.stockSaisirComptage(ligne.id, { quantiteComptee: String(v) })
      await onRafraichir()
    } catch (e) {
      onErreur(e.message || "Le comptage n'a pas été enregistré.")
    } finally {
      setOccupe(null)
    }
  }

  async function regulariser(ligne) {
    setOccupe(ligne.id)
    try {
      await api.stockRegulariserLigne(ligne.id)
      await onRafraichir()
      onFait('Écart régularisé : le stock est aligné sur ce qui a été compté.')
    } catch (e) {
      onErreur(e.message || "La régularisation n'a pas abouti.")
    } finally {
      setOccupe(null)
    }
  }

  async function cloturer() {
    if (
      !await confirmer(
        'Clôturer cet inventaire ?\n\nLes lignes ne seront plus modifiables. Les écarts non '
          + "régularisés resteront tels quels : le stock gardera la valeur d'avant le comptage.",
      )
    )
      return
    try {
      await api.stockCloturerInventaire(inventaire.id)
      await onRafraichir()
      onFait('Inventaire clôturé.')
    } catch (e) {
      onErreur(e.message || "La clôture n'a pas abouti.")
    }
  }

  return (
    <>
      {nonRattaches > 0 && (
        <div className="banner banner-warn">
          <b>{nonRattaches} ligne{nonRattaches > 1 ? 's partent' : ' part'} d'un attendu de zéro sans
          que le stock soit vide.</b> La quantité attendue est celle du produit vendu ; ces
          articles-là ne sont rattachés à aucun produit, donc le logiciel n'attend rien pour eux.
          L'écart affiché sera égal à tout ce que vous compterez — ce n'est pas une différence, c'est
          l'absence de référence. Rattachez-les avant de régulariser, sinon vous corrigerez un chiffre
          contre rien.
        </div>
      )}

      <div className="hint" style={{ marginTop: 0 }}>
        {restantes === 0
          ? 'Toutes les lignes sont comptées. Régularisez les écarts que vous acceptez, puis clôturez.'
          : `${restantes} ligne${restantes > 1 ? 's' : ''} à compter sur ${lignes.length}.`}
      </div>

      <table className="tbl">
        <thead>
          <tr>
            <th>Article</th>
            <th className="num">Attendu</th>
            <th className="num">Compté</th>
            <th className="num">Écart</th>
            <th>État</th>
            {peutInventorier && <th />}
          </tr>
        </thead>
        <tbody>
          {lignes.map((l) => {
            const idArticle = l.articleStock?.id || String(l.articleStock || '').split('/').pop()
            const article = parId[idArticle]
            const compte = l.quantiteComptee != null
            const ecart = parseFloat(l.ecart) || 0
            const regularisee = l.mouvementRegularisation != null
            const bloquee = l.significatif && !peutValiderEcart
            return (
              <tr key={l.id}>
                <td>
                  <span className="nm">{article?.libelle || `Article ${String(idArticle).slice(0, 8)}`}</span>
                  {article && !article.produit && (
                    <span className="badge warn" style={{ marginLeft: 6 }}>non suivi</span>
                  )}
                </td>
                <td className="num">{nb(l.quantiteTheorique)}</td>
                <td className="num">
                  {regularisee || !peutInventorier ? (
                    nb(l.quantiteComptee)
                  ) : (
                    <input
                      className="input"
                      type="number"
                      step="0.001"
                      min="0"
                      style={{ width: 100, textAlign: 'right' }}
                      value={saisies[l.id] ?? (l.quantiteComptee ?? '')}
                      disabled={occupe === l.id}
                      onChange={(e) => setSaisies((s) => ({ ...s, [l.id]: e.target.value }))}
                      onBlur={() => compter(l)}
                    />
                  )}
                </td>
                <td className="num">
                  {!compte ? (
                    <span className="sub">—</span>
                  ) : (
                    <span className={`badge ${ecart === 0 ? 'good' : l.significatif ? 'crit' : 'warn'}`}>
                      {ecart > 0 ? `+${nb(l.ecart)}` : nb(l.ecart)}
                    </span>
                  )}
                </td>
                <td>
                  {regularisee ? (
                    <span className="badge good">corrigé</span>
                  ) : !compte ? (
                    <span className="sub">à compter</span>
                  ) : l.significatif ? (
                    // D54 : d'abord le fait sur la donnée, ensuite le fait sur l'utilisateur.
                    // « Cet écart dépasse le seuil » explique la situation ; « il vous manque un
                    // droit » ne fait qu'envoyer chercher dans le mauvais endroit.
                    <span className="badge crit" title="Écart au-delà du seuil réglé pour cet établissement.">
                      écart important
                    </span>
                  ) : (
                    <span className="sub">prêt</span>
                  )}
                </td>
                {peutInventorier && (
                  <td className="num">
                    {!regularisee && compte && ecart !== 0 && (
                      <button
                        className="btn ghost sm"
                        type="button"
                        disabled={occupe === l.id || bloquee}
                        title={
                          bloquee
                            ? "Cet écart dépasse le seuil de l'établissement : sa validation demande le droit « valider un écart ». Faites-le valider par un responsable."
                            : 'Aligner le stock sur la quantité comptée.'
                        }
                        onClick={() => regulariser(l)}
                      >
                        {bloquee ? 'À faire valider' : 'Régulariser'}
                      </button>
                    )}
                  </td>
                )}
              </tr>
            )
          })}
          {lignes.length === 0 && (
            <tr>
              <td colSpan={peutInventorier ? 6 : 5} className="empty">
                Cet inventaire ne contient aucune ligne : aucun article actif ne correspondait au
                périmètre choisi.
              </td>
            </tr>
          )}
        </tbody>
      </table>

      {peutInventorier && (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={cloturer}>Clôturer l'inventaire</button>
        </div>
      )}
    </>
  )
}

// Lancer un inventaire. Deux périmètres seulement — voir l'en-tête du fichier pour « rayon ».
function LancementModal({ open, articles, onClose, onFait, onErreur }) {
  const [perimetre, setPerimetre] = useState('tous')
  const [choisis, setChoisis] = useState([])
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (open) { setPerimetre('tous'); setChoisis([]) }
  }, [open])

  const actifs = articles.filter((a) => a.actif !== false)

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.stockLancerInventaire({
        perimetre,
        ...(perimetre === 'selection' ? { filtre: choisis } : {}),
      })
      onFait(
        perimetre === 'tous'
          ? `Inventaire lancé sur ${actifs.length} article${actifs.length > 1 ? 's' : ''}.`
          : `Inventaire lancé sur ${choisis.length} article${choisis.length > 1 ? 's' : ''}.`,
      )
    } catch (err) {
      onErreur(err.message || "L'inventaire n'a pas pu être lancé.")
    } finally {
      setEnCours(false)
    }
  }

  function basculer(id) {
    setChoisis((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]))
  }

  return (
    <Modal open={open} onClose={onClose} titre="Lancer un inventaire" taille="lg">
      <form onSubmit={envoyer}>
        <p style={{ marginTop: 0 }}>
          Le logiciel fige ce qu'il croit avoir, vous comptez ce qu'il y a vraiment, et vous corrigez
          les différences une par une. Seuls les articles <b>en service</b> sont inventoriés.
        </p>

        <div className="fiche-sec" style={{ marginTop: 0 }}>Sur quoi porte le comptage ?</div>

        <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 0', fontWeight: 400 }}>
          <input
            type="radio"
            name="perimetre"
            checked={perimetre === 'tous'}
            onChange={() => setPerimetre('tous')}
            style={{ marginTop: 3 }}
          />
          <span>
            <b>Tout le stock</b>
            <div className="sub">Les {actifs.length} articles en service. C'est l'inventaire annuel.</div>
          </span>
        </label>

        <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 0', fontWeight: 400 }}>
          <input
            type="radio"
            name="perimetre"
            checked={perimetre === 'selection'}
            onChange={() => setPerimetre('selection')}
            style={{ marginTop: 3 }}
          />
          <span>
            <b>Quelques articles</b>
            <div className="sub">
              Un comptage tournant : on vérifie régulièrement une poignée de références plutôt que
              tout arrêter une fois par an.
            </div>
          </span>
        </label>

        {perimetre === 'selection' && (
          <div className="field">
            <label>Articles à compter — {choisis.length} sélectionné{choisis.length > 1 ? 's' : ''}</label>
            <div
              style={{
                maxHeight: 240,
                overflowY: 'auto',
                border: '1px solid var(--line)',
                borderRadius: 8,
                padding: 8,
              }}
            >
              {actifs.map((a) => (
                <label
                  key={a.id}
                  style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '3px 0', fontWeight: 400 }}
                >
                  <input type="checkbox" checked={choisis.includes(a.id)} onChange={() => basculer(a.id)} />
                  {a.libelle}
                  {!a.produit && <span className="badge warn">non suivi</span>}
                </label>
              ))}
              {actifs.length === 0 && <div className="sub">Aucun article en service.</div>}
            </div>
          </div>
        )}

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button
            className="btn primary"
            type="submit"
            disabled={enCours || (perimetre === 'selection' && choisis.length === 0)}
          >
            {enCours ? 'Lancement…' : 'Lancer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function nb(v) {
  const n = typeof v === 'number' ? v : parseFloat(v)
  if (!Number.isFinite(n)) return '—'
  return String(Math.round(n * 1000) / 1000).replace('.', ',')
}
