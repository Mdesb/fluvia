import { useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { libelleProduit, prixIndicatif, euros, statutProduit } from '../api/produit.js'
import Modal from './Modal.jsx'
import { humaniser, mot } from '../api/vocabulaire.js'
import TarifsProduit from './TarifsProduit.jsx'

// Fiche produit détaillée — même niveau de détail que la fiche 360° client, en modale (D13 : la
// modale est le défaut, créer un écran est l'exception ; consulter un produit depuis sa liste ne
// justifie ni un espace de travail durable, ni un lien partageable).
//
// La liste porte déjà `produit:read` + `produit:list` : l'essentiel est donc affichable tout de
// suite, sans attendre. L'appel de détail n'ajoute que le bloc comptable (`produit:compta`) et les
// options — on affiche donc immédiatement ce qu'on sait, et on complète. Une modale qui tourne une
// seconde sur un fond vide alors qu'on avait déjà 80 % de la réponse est une seconde perdue à chaque
// ouverture.

const CANAUX_PRODUIT = [
  { valeur: 'guichet', libelle: 'Au guichet' },
  { valeur: 'en_ligne', libelle: 'En ligne' },
  { valeur: 'borne', libelle: 'Sur borne' },
]

export default function ProduitFicheModal({ open, produit, onClose, peutModifier = false, onModifie }) {
  const [edition, setEdition] = useState(null)
  const [enregistrement, setEnregistrement] = useState(false)
  const [detail, setDetail] = useState(null)
  const [liaisons, setLiaisons] = useState([])
  const [valeurs, setValeurs] = useState({}) // groupeId -> valeurs
  // Le produit n'est pas commercialisable sur l'établissement actif : le guichet ne l'aurait pas.
  const [horsSite, setHorsSite] = useState(false)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)

  const produitId = produit?.id

  useEffect(() => {
    if (!open || !produitId) return undefined
    let annule = false

    setDetail(null)
    setEdition(null)
    setLiaisons([])
    setValeurs({})
    setHorsSite(false)
    setErreur(null)
    setChargement(true)
    ;(async () => {
      try {
        // ON DEMANDE AU GUICHET CE QUE LE GUICHET AFFICHERA, PLUTÔT QUE DE LE RECONSTITUER.
        //
        // Cette section s'annonce « Aperçu de ce que le guichet affichera pour ce produit ». Elle le
        // reconstituait à partir de deux collections filtrées — les rattachements du produit, puis les
        // valeurs de chaque groupe — soit 1+N requêtes, et **une seconde implémentation de la règle
        // d'éligibilité**. Un aperçu qui recalcule ce qu'il prétend refléter ne diverge pas le jour où
        // on l'écrit : il diverge au premier correctif appliqué à un seul des deux.
        //
        // `GET /produits/{id}/options-disponibles` est **la réponse même du guichet** : `actif`,
        // restriction d'établissement (RG-OPT-07), tri d'affichage, et le cloisonnement vérifié côté
        // serveur. Une requête, et l'aperçu devient fidèle par construction au lieu de l'être par
        // ressemblance.
        //
        // Au passage, il n'emprunte aucun filtre de collection — donc aucun des deux pièges observés
        // le 27/08 sur `SearchFilter` (identifiant nu → collection entière ; IRI → collection vide).
        // Le guichet répond 404 quand le produit n'appartient PAS à l'établissement actif. Ce n'est pas
        // une panne à signaler en rouge : c'est le seul contrôle de la chaîne qui vérifie réellement
        // l'appartenance, et sa réponse est une information à afficher telle quelle.
        const [d, dispo] = await Promise.all([
          api.produit(produitId),
          api.optionsDisponibles(produitId).catch(() => 'hors-site'),
        ])
        if (annule) return
        setDetail(d)
        setHorsSite(dispo === 'hors-site')

        const groupes = dispo === 'hors-site' ? [] : (dispo?.groupes || [])
        setLiaisons(
          groupes.map((g) => ({
            id: g.optionProduit,
            obligatoire: g.obligatoire,
            groupeOption: { id: g.groupeOption, libelle: g.libelle, modeSelection: g.modeSelection },
          })),
        )
        setValeurs(Object.fromEntries(groupes.map((g) => [g.groupeOption, g.valeurs || []])))
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

  function ouvrirEdition() {
    setEdition({
      libelle: libelleProduit(p),
      canaux: Array.isArray(p.canaux) ? [...p.canaux] : [],
      couleurCaisse: p.couleurCaisse || '',
      noteInterne: p.noteInterne || '',
    })
  }

  async function enregistrer(e) {
    e.preventDefault()
    setErreur(null)
    setEnregistrement(true)
    try {
      await api.majProduit(produitId, {
        // Le libelle est multilingue cote serveur : on ne remplace que le francais, sinon une
        // traduction existante disparaitrait sans que personne ne l'ait demande.
        libelle: { ...(p.libelle && typeof p.libelle === 'object' ? p.libelle : {}), fr: edition.libelle.trim() },
        canaux: edition.canaux,
        couleurCaisse: edition.couleurCaisse || null,
        noteInterne: edition.noteInterne.trim() || null,
      })
      const rafraichi = await api.produit(produitId)
      setDetail(rafraichi)
      setEdition(null)
      onModifie?.()
    } catch (err) {
      setErreur(err.message || "L'enregistrement n'a pas abouti.")
    } finally {
      setEnregistrement(false)
    }
  }

  if (edition) {
    return (
      <Modal open={open} onClose={onClose} titre={`Modifier — ${libelleProduit(p)}`} taille="lg">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <form onSubmit={enregistrer}>
          <div className="field">
            <label htmlFor="pr-lib">Nom du produit *</label>
            <input
              id="pr-lib"
              className="input"
              required
              value={edition.libelle}
              onChange={(e) => setEdition((s) => ({ ...s, libelle: e.target.value }))}
            />
            <div className="hint">C'est ce que verront le vendeur en caisse et le client en ligne.</div>
          </div>

          <div className="field">
            <label>Où ce produit est vendu</label>
            <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
              {CANAUX_PRODUIT.map((c) => (
                <label key={c.valeur} style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 400 }}>
                  <input
                    type="checkbox"
                    checked={edition.canaux.includes(c.valeur)}
                    onChange={(ev) =>
                      setEdition((s) => ({
                        ...s,
                        canaux: ev.target.checked
                          ? [...s.canaux, c.valeur]
                          : s.canaux.filter((x) => x !== c.valeur),
                      }))
                    }
                  />
                  {c.libelle}
                </label>
              ))}
            </div>
            <div className="hint">
              Si vous ne cochez rien, le produit ne sera vendable nulle part, même une fois publié.
            </div>
          </div>

          <div className="field">
            <label htmlFor="pr-coul">Couleur en caisse</label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <input
                id="pr-coul"
                type="color"
                value={edition.couleurCaisse || '#cccccc'}
                onChange={(e) => setEdition((s) => ({ ...s, couleurCaisse: e.target.value }))}
                style={{ width: 48, height: 34, padding: 2 }}
              />
              {edition.couleurCaisse && (
                <button
                  className="btn ghost sm"
                  type="button"
                  onClick={() => setEdition((s) => ({ ...s, couleurCaisse: '' }))}
                >
                  Retirer la couleur
                </button>
              )}
            </div>
            <div className="hint">Aide le vendeur à repérer le produit d'un coup d'œil. Facultatif.</div>
          </div>

          <div className="field">
            <label htmlFor="pr-note">Note interne</label>
            <textarea
              id="pr-note"
              className="input"
              rows={3}
              value={edition.noteInterne}
              onChange={(e) => setEdition((s) => ({ ...s, noteInterne: e.target.value }))}
            />
            <div className="hint">Visible de votre équipe seulement. Jamais affichée au client.</div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={() => setEdition(null)}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enregistrement}>
              {enregistrement ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>
        </form>
      </Modal>
    )
  }

  return (
    <Modal open={open} onClose={onClose} titre={libelleProduit(p)} taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}

      <div className="fiche-ident" style={{ marginBottom: 12 }}>
        <div>
          <div className="fiche-nom">{libelleProduit(p)}</div>
          <div className="sub">
            {p.code || '—'} · {p.type?.libelle || humaniser(p.typeCode)}
          </div>
        </div>
        <span className={`badge ${st.ton}`} title={st.aide} style={{ marginLeft: 'auto' }}>
          {st.libelle}
        </span>
        {peutModifier && (
          <button className="btn ghost sm" type="button" onClick={ouvrirEdition} style={{ marginLeft: 10 }}>
            Modifier
          </button>
        )}
      </div>

      {/* Ce que l'exploitant cherche en premier : combien, où, et combien il en reste. */}
      <div className="fiche-stats">
        <div>
          <div className="st-lib">Tarif indicatif</div>
          <div className="st-val num">{euros(base)}</div>
        </div>
        <div>
          <div className="st-lib" title="Les endroits où ce produit peut être vendu.">Vendu</div>
          <div className="st-val">{(p.canaux || []).map(mot).join(', ') || '—'}</div>
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

      <Section titre="Tarifs" aide="Le prix de ce produit, par type de tarif et par période.">
        <TarifsProduit
          produit={p}
          grilles={grilles}
          peutModifier={peutModifier}
          onChange={async () => {
            setDetail(await api.produit(produitId))
            onModifie?.()
          }}
        />
      </Section>

      <Section
        titre="Options"
        aide="Ce que le guichet affichera au moment de vendre ce produit."
      >
        {chargement && liaisons.length === 0 ? (
          <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
        ) : horsSite ? (
          // D54 : d'abord le fait sur la donnée, jamais un vide muet ni un rouge sans cause.
          <div className="sub" style={{ textAlign: 'center', padding: '10px 0' }}>
            Ce produit n'est pas commercialisé sur l'établissement actif : le guichet ne l'affichera
            pas ici, options comprises.
          </div>
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
