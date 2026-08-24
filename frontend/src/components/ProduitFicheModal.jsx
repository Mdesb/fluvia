import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { libelleProduit, prixIndicatif, euros, statutProduit } from '../api/produit.js'
import Modal from './Modal.jsx'

// Fiche produit détaillée — même niveau de détail que la fiche 360° client, en modale (D13 : la
// modale est le défaut, créer un écran est l'exception ; consulter un produit depuis sa liste ne
// justifie ni un espace de travail durable, ni un lien partageable).
//
// La liste porte déjà `produit:read` + `produit:list` : l'essentiel est donc affichable tout de
// suite, sans attendre. L'appel de détail n'ajoute que le bloc comptable (`produit:compta`) et les
// options — on affiche donc immédiatement ce qu'on sait, et on complète. Une modale qui tourne une
// seconde sur un fond vide alors qu'on avait déjà 80 % de la réponse est une seconde perdue à chaque
// ouverture.

export default function ProduitFicheModal({ open, produit, onClose }) {
  const [detail, setDetail] = useState(null)
  const [liaisons, setLiaisons] = useState([])
  const [valeurs, setValeurs] = useState({}) // groupeId -> valeurs
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)

  const produitId = produit?.id

  useEffect(() => {
    if (!open || !produitId) return undefined
    let annule = false

    setDetail(null)
    setLiaisons([])
    setValeurs({})
    setErreur(null)
    setChargement(true)
    ;(async () => {
      try {
        const [d, ops] = await Promise.all([api.produit(produitId), api.optionProduits(produitId)])
        if (annule) return
        setDetail(d)
        const l = membres(ops)
        setLiaisons(l)

        // Les valeurs de chaque groupe rattaché : c'est ce qui permet de montrer le RÉSULTAT plutôt
        // que la mécanique. Sans elles on ne saurait afficher que « un groupe est rattaché », ce qui
        // n'apprend rien à personne.
        const groupes = l.map((op) => op.groupeOption).filter((g) => g?.id)
        const listes = await Promise.all(groupes.map((g) => api.valeurOptions(g.id).catch(() => null)))
        if (annule) return
        const parGroupe = {}
        groupes.forEach((g, i) => {
          parGroupe[g.id] = listes[i] ? membres(listes[i]).filter((v) => v.actif !== false) : []
        })
        setValeurs(parGroupe)
      } catch (e) {
        if (!annule) setErreur(e.message || 'Détail indisponible.')
      } finally {
        if (!annule) setChargement(false)
      }
    })()

    return () => {
      annule = true
    }
  }, [open, produitId])

  if (!produit) return null

  const p = detail || produit
  const st = statutProduit(p)
  const grilles = p.grilles || []
  const base = prixIndicatif(p)

  return (
    <Modal open={open} onClose={onClose} titre={libelleProduit(p)} taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="fiche-ident" style={{ marginBottom: 12 }}>
        <div>
          <div className="fiche-nom">{libelleProduit(p)}</div>
          <div className="sub">
            {p.code || '—'} · {p.typeCode || 'Type inconnu'}
          </div>
        </div>
        <span className={`badge ${st.ton}`} title={st.aide} style={{ marginLeft: 'auto' }}>
          {st.libelle}
        </span>
      </div>

      {/* Ce que l'exploitant cherche en premier : combien, où, et combien il en reste. */}
      <div className="fiche-stats">
        <div>
          <div className="st-lib">Tarif indicatif</div>
          <div className="st-val num">{euros(base)}</div>
        </div>
        <div>
          <div className="st-lib">Canaux</div>
          <div className="st-val">{(p.canaux || []).join(', ') || '—'}</div>
        </div>
        <div>
          <div className="st-lib" title="Quantité disponible à la vente, tenue par le module Stock.">
            Stock
          </div>
          <div className="st-val num">
            {p.stock && typeof p.stock.disponibilite === 'number' ? p.stock.disponibilite : 'Non suivi'}
          </div>
        </div>
      </div>

      <Section titre="Tarifs">
        {grilles.length === 0 ? (
          <div className="empty">
            Aucun tarif. Un produit sans tarif au guichet ne peut pas être vendu, et sa publication sera refusée.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Type de tarif</th>
                <th className="num">Prix</th>
              </tr>
            </thead>
            <tbody>
              {grilles.map((g, i) => (
                <tr key={g.id || i}>
                  <td>{g.typeTarif?.libelle || g.typeTarif?.code || '—'}</td>
                  <td className="num">{euros(g.prix)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>

      <Section
        titre="Options"
        aide="Ce que le guichet affichera au moment de vendre ce produit."
      >
        {chargement && liaisons.length === 0 ? (
          <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
        ) : liaisons.length === 0 ? (
          <div className="empty">Aucune option rattachée : le produit se vend tel quel.</div>
        ) : (
          <ApercuCaisse liaisons={liaisons} valeurs={valeurs} base={base} />
        )}
      </Section>

      <Section titre="Diffusion">
        <Ligne libelle="Sites de commercialisation" valeur={(p.etablissements || []).length || '—'} />
        <Ligne libelle="Catégories" valeur={(p.categories || []).length || '—'} />
        <Ligne
          libelle="Durée de validité"
          valeur={p.dureeValidite || '—'}
          aide="Durée pendant laquelle le droit vendu reste utilisable."
        />
      </Section>

      <Section titre="Comptabilité">
        {chargement && !detail ? (
          <div className="hint">Chargement…</div>
        ) : (
          <>
            <Ligne libelle="Compte comptable" valeur={p.compteComptable || '—'} />
            <Ligne libelle="Taux de TVA" valeur={p.tauxTva != null ? `${p.tauxTva} %` : '—'} />
            <Ligne
              libelle="Règle PCA"
              valeur={p.reglePca || '—'}
              aide="Produit constaté d'avance : comment le chiffre d'affaires est étalé dans le temps."
            />
          </>
        )}
      </Section>

      {p.noteInterne && (
        <Section titre="Note interne">
          <div className="hint">{p.noteInterne}</div>
        </Section>
      )}
    </Modal>
  )
}

/* ------------------------------------------------------------------ Aperçu caisse */

// Le cœur de cette fiche, et la réponse à « je ne comprends rien aux options produit ».
//
// L'écran de paramétrage montre la MÉCANIQUE — rattacher un groupe, basculer un drapeau — et jamais
// le RÉSULTAT. Quelqu'un qui n'a pas écrit le modèle ne peut pas deviner ce qu'une option fait, ce
// que le client verra, ni ce que ça change au prix. On montre donc ici ce que le guichet affichera,
// avec le prix réellement atteint. Un exemple concret vaut mieux que trois définitions.
function ApercuCaisse({ liaisons, valeurs, base }) {
  const prixBase = typeof base === 'number' ? base : parseFloat(base)
  const exemple = calculExemple(liaisons, valeurs, prixBase)

  return (
    <>
      <div className="hint" style={{ marginBottom: 10 }}>
        Aperçu de ce que le guichet affichera pour ce produit.
      </div>

      {liaisons.map((op) => {
        const g = op.groupeOption || {}
        const vals = valeurs[g.id] || []
        const multiple = g.modeSelection === 'multiple'

        return (
          <div key={op.id} className="card" style={{ marginBottom: 10 }}>
            <div className="card-b">
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 6 }}>
                <b>{g.libelle || 'Groupe'}</b>
                <span
                  className={`badge ${op.obligatoire ? 'good' : 'mut'}`}
                  title={
                    op.obligatoire
                      ? "Le vendeur ne pourra pas terminer la vente sans avoir choisi dans ce groupe."
                      : 'Le vendeur peut passer sans rien choisir.'
                  }
                >
                  {op.obligatoire ? 'choix obligatoire' : 'choix facultatif'}
                </span>
                <span className="sub">
                  {multiple ? 'plusieurs choix possibles' : 'un seul choix'}
                </span>
              </div>

              {vals.length === 0 ? (
                <div className="empty">
                  Ce groupe n'a aucune valeur active : rien ne s'affichera au guichet, et un groupe
                  obligatoire sans valeur bloquerait la vente.
                </div>
              ) : (
                <ul style={{ margin: 0, paddingLeft: 18 }}>
                  {vals.map((v) => (
                    <li key={v.id}>
                      {v.libelle} <span className="sub">{impactLisible(v)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        )
      })}

      {exemple != null && Number.isFinite(prixBase) && (
        <div className="banner">
          Exemple : {euros(prixBase)} de base, avec les choix obligatoires les moins chers →{' '}
          <b>{euros(exemple)}</b> au guichet.
        </div>
      )}
    </>
  )
}

// « +2,00 € » ou « +10 % » plutôt que « montant / 2.00 » : le libellé doit se lire comme il
// s'appliquera, pas comme il est stocké.
function impactLisible(v) {
  const n = parseFloat(v?.impactValeur)
  if (!Number.isFinite(n) || n === 0) return 'sans supplément'
  const signe = n > 0 ? '+' : '−'
  const abs = Math.abs(n)
  return v?.impactType === 'pourcentage' ? `${signe}${abs} %` : `${signe}${euros(abs)}`
}

// Prix atteint si le vendeur prend, dans chaque groupe obligatoire, la valeur la moins chère : c'est
// le PLANCHER réel du produit, et c'est le chiffre qu'un exploitant veut connaître — pas le tarif de
// base, qui n'est atteignable que si aucune option n'est obligatoire.
function calculExemple(liaisons, valeurs, prixBase) {
  if (!Number.isFinite(prixBase)) return null
  let total = prixBase
  let touche = false

  for (const op of liaisons) {
    if (!op.obligatoire) continue
    const vals = valeurs[op.groupeOption?.id] || []
    if (vals.length === 0) continue

    const impacts = vals.map((v) => {
      const n = parseFloat(v.impactValeur)
      if (!Number.isFinite(n)) return 0
      return v.impactType === 'pourcentage' ? (prixBase * n) / 100 : n
    })
    total += Math.min(...impacts)
    touche = true
  }

  return touche ? total : null
}

/* ------------------------------------------------------------------ Petits blocs */

function Section({ titre, aide, children }) {
  return (
    <div style={{ marginTop: 14 }}>
      <div className="fiche-sec" title={aide}>{titre}</div>
      {children}
    </div>
  )
}

function Ligne({ libelle, valeur, aide }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '4px 0' }}>
      <span className="sub" title={aide}>{libelle}</span>
      <span>{valeur}</span>
    </div>
  )
}
