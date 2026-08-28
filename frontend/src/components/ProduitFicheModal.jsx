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

// Les trois règles de produit constaté d'avance du socle (`Offre\Enum\ReglePca`), dites en clair :
// le code brut « etalement » ne dit pas ce qui est étalé ni pourquoi.
const REGLES_PCA = {
  aucune: 'Aucune — le chiffre d’affaires est acquis à la vente',
  etalement: 'Étalement — réparti sur la durée de validité',
  consommation: 'À la consommation — acquis au fur et à mesure des entrées',
}

export default function ProduitFicheModal({
  open,
  produit,
  onClose,
  peutModifier = false,
  peutModifierCompta = false,
  onModifie,
}) {
  const [edition, setEdition] = useState(null)
  const [editionCompta, setEditionCompta] = useState(null)
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

      {/* TROIS CHAMPS ÉCRIVABLES DEPUIS LE DÉBUT, AFFICHÉS ET JAMAIS PROPOSÉS.
          `PATCH /produits/{id}/compta` existe, protégée par `offre.modifier_compta`, et accepte
          `tauxTva`, `compteComptable` et `reglePca`. Le client ne l'appelait de nulle part : la
          fiche montrait les trois valeurs, et le formulaire « Modifier » n'offrait que le nom, les
          canaux, la couleur en caisse et la note interne.
          Montrer un réglage sans donner le bouton est pire que ne rien montrer : l'exploitant sait
          que ça existe et conclut que le logiciel ne le permet pas. Maxime l'a vu de lui-même.
          Bouton séparé, parce que le DROIT est séparé : `offre.modifier_compta` n'est pas
          `offre.modifier`. Qui peut renommer un produit ne peut pas forcément changer son compte. */}
      <Section titre="Comptabilité">
        {chargement && !detail ? (
          <div className="hint">Chargement…</div>
        ) : (
          <>
            <Ligne libelle="Compte comptable" valeur={p.compteComptable || '—'} />
            <Ligne libelle="Taux de TVA" valeur={p.tauxTva != null ? `${p.tauxTva} %` : '—'} />
            <Ligne
              libelle="Règle PCA"
              valeur={REGLES_PCA[p.reglePca] || p.reglePca || '—'}
              aide="Produit constaté d'avance : comment le chiffre d'affaires est étalé dans le temps."
            />
            {peutModifierCompta && (
              <button
                className="btn ghost sm"
                type="button"
                style={{ marginTop: 10 }}
                onClick={() => setEditionCompta({
                  tauxTva: p.tauxTva != null ? String(p.tauxTva) : '',
                  compteComptable: p.compteComptable || '',
                  reglePca: p.reglePca || 'aucune',
                })}
              >
                Modifier la comptabilité
              </button>
            )}
          </>
        )}
      </Section>

      <PhotosProduit produitId={p.id} peutModifier={peutModifier} />

      {p.noteInterne && (
        <Section titre="Note interne">
          <div className="hint">{p.noteInterne}</div>
        </Section>
      )}

      <ComptaProduitModal
        edition={editionCompta}
        onClose={() => setEditionCompta(null)}
        onEnregistre={async (corps) => {
          await api.majComptaProduit(produitId, corps)
          setDetail(await api.produit(produitId))
          setEditionCompta(null)
          onModifie?.()
        }}
      />
    </Modal>
  )
}

// LE TAUX DE TVA D'UN PRODUIT EST UNE VALEUR, PAS UNE RELATION, ET ÇA CHANGE LE FORMULAIRE.
//
// `Produit::$tauxTva` est une colonne `decimal(5,2)` nullable — pas une clé vers `TauxTva`. Le
// produit ne « pointe » donc pas le référentiel : il recopie un pourcentage. On propose quand même
// la liste des taux déclarés, parce que saisir 20 à la main quand l'établissement a déclaré 20,00
// est le meilleur moyen de créer deux vérités ; mais on envoie bien la valeur, pas un identifiant.
//
// Et on part en CHAÎNE : la colonne est décimale, et le désérialiseur refuse un entier — c'est
// exactement le défaut qui rendait la création d'un taux de TVA impossible.
function ComptaProduitModal({ edition, onClose, onEnregistre }) {
  const [taux, setTaux] = useState([])
  const [valeurs, setValeurs] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!edition) return
    setValeurs(edition)
    setErreur(null)
  }, [edition])

  useEffect(() => {
    if (!edition) return undefined
    let annule = false
    api.tauxTvas()
      // Un référentiel illisible (droits comptables absents) ne doit pas fermer le formulaire :
      // la liste disparaît, la saisie libre reste.
      .then((r) => { if (!annule) setTaux(membres(r).filter((t) => t.actif !== false)) })
      .catch(() => { if (!annule) setTaux([]) })
    return () => { annule = true }
  }, [edition])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await onEnregistre({
        tauxTva: valeurs.tauxTva === '' ? null : String(valeurs.tauxTva),
        compteComptable: valeurs.compteComptable.trim() || null,
        reglePca: valeurs.reglePca,
      })
    } catch (err) {
      setErreur(err.message || "L'enregistrement n'a pas abouti.")
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={!!edition} onClose={onClose} titre="Comptabilité du produit" taille="md">
      {valeurs && (
        <form onSubmit={soumettre}>
          {erreur && <div className="banner banner-error">{erreur}</div>}

          <div className="field">
            <label htmlFor="pc-tva">Taux de TVA</label>
            <select
              id="pc-tva"
              className="input"
              value={valeurs.tauxTva}
              onChange={(e) => setValeurs((s) => ({ ...s, tauxTva: e.target.value }))}
            >
              <option value="">Aucun taux</option>
              {taux.map((t) => (
                <option key={t.id} value={String(t.taux)}>
                  {t.libelle} — {t.taux} %
                </option>
              ))}
              {/* Un produit peut porter un taux qui n'est plus au référentiel (masqué depuis).
                  Le retirer de la liste ferait perdre la valeur au premier enregistrement. */}
              {valeurs.tauxTva !== '' && !taux.some((t) => String(t.taux) === String(valeurs.tauxTva)) && (
                <option value={valeurs.tauxTva}>{valeurs.tauxTva} % (taux retiré du référentiel)</option>
              )}
            </select>
            <p className="hint">
              {taux.length === 0
                ? 'Aucun taux n’est déclaré pour cet établissement : renseignez-les dans Paramètres › Catalogue & référentiels.'
                : 'Le taux facturé sur ce produit, et celui qui remontera en comptabilité.'}
            </p>
          </div>

          <div className="field">
            <label htmlFor="pc-compte">Compte comptable</label>
            <input
              id="pc-compte"
              className="input mono"
              maxLength={32}
              value={valeurs.compteComptable}
              placeholder="706100"
              onChange={(e) => setValeurs((s) => ({ ...s, compteComptable: e.target.value }))}
            />
            <p className="hint">
              Le compte de produit sur lequel les ventes de cet article seront imputées. Saisie
              libre : le plan comptable n’est pas exposé à cet écran.
            </p>
          </div>

          <div className="field">
            <label htmlFor="pc-pca">Règle de produit constaté d’avance</label>
            <select
              id="pc-pca"
              className="input"
              value={valeurs.reglePca}
              onChange={(e) => setValeurs((s) => ({ ...s, reglePca: e.target.value }))}
            >
              {Object.entries(REGLES_PCA).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
            <p className="hint">
              Décide du moment où l’argent encaissé devient du chiffre d’affaires. Un abonnement
              annuel vendu en janvier ne se gagne pas en janvier.
            </p>
          </div>

          <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
            <button type="button" className="btn" onClick={onClose}>Annuler</button>
            <button type="submit" className="btn primary" disabled={envoi}>
              {envoi ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>
        </form>
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

/**
 * LES PHOTOS DU PRODUIT — ce que le visiteur verra de lui.
 *
 * Deux choses que l'écran dit tout haut, parce que les taire ferait conclure à une panne :
 *
 *   - **le texte alternatif est exigé.** Une image sans alternative est invisible pour un lecteur
 *     d'écran et pour un moteur de recherche ; pour un établissement public, le RGAA en fait un
 *     critère. Le champ est demandé au téléversement, seul moment où quelqu'un sait ce que montre
 *     la photo ;
 *   - **une photo ne s'affiche en boutique que si le produit y est vendu.** Publié, et au canal
 *     « en ligne ». Sans ce rappel, l'exploitant téléverse, ne voit rien, et cherche du côté du
 *     fichier.
 *
 * La première photo est celle que la boutique montre. Les suivantes attendent une galerie.
 */
function PhotosProduit({ produitId, peutModifier }) {
  const [etat, setEtat] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [alt, setAlt] = useState('')
  const [fichier, setFichier] = useState(null)

  useEffect(() => {
    let vivant = true
    if (!produitId) return undefined
    api.photosProduit(produitId)
      .then((r) => { if (vivant) setEtat(r) })
      .catch(() => { if (vivant) setEtat(null) })

    return () => { vivant = false }
  }, [produitId])

  async function recharger() {
    try {
      setEtat(await api.photosProduit(produitId))
    } catch {
      setEtat(null)
    }
  }

  async function televerser() {
    setBusy(true)
    setErr(null)
    try {
      await api.televerserPhotoProduit(produitId, fichier, alt.trim())
      setAlt('')
      setFichier(null)
      await recharger()
    } catch (e) {
      setErr(e.message || 'La photo n’a pas pu être ajoutée.')
    } finally {
      setBusy(false)
    }
  }

  async function retirer(id) {
    setBusy(true)
    setErr(null)
    try {
      await api.supprimerPhotoProduit(id)
      await recharger()
    } catch (e) {
      setErr(e.message || 'La photo n’a pas pu être retirée.')
    } finally {
      setBusy(false)
    }
  }

  if (!etat) return null

  const photos = etat.photos || []

  return (
    <Section titre="Photos" aide="La première est celle qu’affiche la boutique en ligne.">
      {!etat.visiblePubliquement && (
        <div className="hint" style={{ marginBottom: 8 }}>
          Ce produit n’est pas vendu en ligne : ses photos ne s’afficheront nulle part tant qu’il
          n’est pas <strong>publié</strong> et ouvert au canal <strong>en ligne</strong>.
        </div>
      )}

      {photos.length > 0 && (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 10 }}>
          {photos.map((photo, i) => (
            <figure key={photo.id} style={{ margin: 0, width: 132 }}>
              <img
                src={photo.url}
                alt={photo.altText}
                style={{
                  width: 132,
                  height: 92,
                  objectFit: 'cover',
                  display: 'block',
                  border: '1px solid var(--bord, #ddd)',
                }}
              />
              <figcaption className="sub" style={{ marginTop: 4, lineHeight: 1.3 }}>
                {i === 0 && <strong>Affichée en boutique — </strong>}
                {photo.altText}
              </figcaption>
              {peutModifier && (
                <button
                  className="btn ghost sm"
                  type="button"
                  disabled={busy}
                  style={{ marginTop: 4 }}
                  onClick={() => retirer(photo.id)}
                >
                  Retirer
                </button>
              )}
            </figure>
          ))}
        </div>
      )}

      {err && <div className="banner banner-error" style={{ marginBottom: 8 }}>{err}</div>}

      {peutModifier && (
        <div style={{ display: 'grid', gap: 8 }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Fichier — JPEG, PNG, WEBP ou AVIF, 2 Mo maximum</label>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp,image/avif"
              onChange={(e) => setFichier(e.target.files?.[0] || null)}
            />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Que montre cette photo ? — lu à voix haute aux visiteurs malvoyants</label>
            <input
              value={alt}
              onChange={(e) => setAlt(e.target.value)}
              placeholder="Le bassin nordique au coucher du soleil"
              maxLength={160}
            />
          </div>
          <div>
            <button
              className="btn primary sm"
              type="button"
              disabled={busy || !fichier || alt.trim().length < 3}
              onClick={televerser}
            >
              Ajouter la photo
            </button>
          </div>
        </div>
      )}

      {photos.length === 0 && !peutModifier && <div className="hint">Aucune photo.</div>}
    </Section>
  )
}
