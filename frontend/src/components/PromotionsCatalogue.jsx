import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { libelleProduit } from '../api/produit.js'
import Modal from './Modal.jsx'

/**
 * LES PROMOTIONS — cinq routes servies, aucun écran.
 *
 * Lire, créer, modifier, supprimer : tout existe depuis l'origine et le frontal n'en appelait
 * aucune. Un exploitant ne pouvait pas faire de remise, ni voir celles qui couraient.
 *
 * Ce n'est pas un module inerte : `PriceQuoter` lit les promotions au moment de calculer un prix.
 * Elles s'appliquent — simplement, personne ne pouvait en créer.
 *
 * ⚠ ET LE PIÈGE EST DANS UNE CONVENTION QUE PERSONNE NE DEVINE.
 *
 * Deux tableaux, deux règles OPPOSÉES :
 *
 *   produits éligibles vide  →  la promotion ne s'applique à AUCUN produit
 *   canaux vide              →  la promotion s'applique à TOUS les canaux
 *
 * Le premier est contre-intuitif au point que le code le souligne : « une promotion dont
 * l'éligibilité ne liste aucun produit n'est éligible à AUCUN produit — et non à tous, comme on le
 * lirait spontanément. J'ai failli "corriger" ce point. »
 *
 * Un formulaire qui laisserait créer une promotion sans produit fabriquerait donc une remise
 * silencieusement inerte : elle apparaît dans la liste, elle a des dates, elle ne réduit jamais
 * rien. Cet écran l'interdit, et dit pourquoi.
 */

const TYPES = [
  ['pourcentage', 'Pourcentage de remise'],
  ['montant', 'Montant fixe déduit'],
  ['offre_groupee', 'Offre groupée'],
  ['bonus_10_12', 'Bonus 10 pour 12'],
]

const CANAUX = [
  ['guichet', 'Guichet'],
  ['en_ligne', 'En ligne'],
  ['borne', 'Borne'],
  ['appli', 'Application'],
  ['ota', 'Partenaires en ligne'],
]

const LIB_TYPE = Object.fromEntries(TYPES)
const LIB_CANAL = Object.fromEntries(CANAUX)

const VIDE = {
  nom: '',
  type: 'pourcentage',
  valeur: '',
  conditions: '',
  dateDebut: '',
  dateFin: '',
  cumul: 'cumulable',
  canaux: [],
  produits: [],
}

function jourFr(v) {
  if (!v) return null
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? null : d.toLocaleDateString('fr-FR')
}

function idsEligibles(p) {
  const l = p?.eligibilite?.produits
  return Array.isArray(l) ? l.map(String) : []
}

/** Les valeurs du formulaire, telles qu'une promotion enregistrée les remplit. */
function valeursDe(p) {
  return {
    id: p.id,
    nom: p.nom || '',
    type: p.type || 'pourcentage',
    valeur: p.valeur || '',
    conditions: p.conditions || '',
    dateDebut: p.dateDebut ? String(p.dateDebut).slice(0, 10) : '',
    dateFin: p.dateFin ? String(p.dateFin).slice(0, 10) : '',
    cumul: p.cumul || 'cumulable',
    canaux: Array.isArray(p.canaux) ? p.canaux : [],
    produits: idsEligibles(p),
  }
}

/**
 * ── LE FORMULAIRE EST UN ÉCRAN, ET IL VIT DANS L'ADRESSE ────────────────────────────────────────
 *
 * Il tenait dans une modale de 480 px de large. Mesuré le 07/09 : 918 px de haut dans une fenêtre
 * de 720, vingt champs, et huit cents pixels de largeur d'écran inutilisés de part et d'autre. La
 * boîte débordait donc de 278 px, et c'est son FOND qui défilait.
 *
 * Le paramètre `promo` porte désormais ce qu'on regarde — `nouveau`, ou l'identifiant d'une
 * promotion — comme `fiche` le fait pour un produit dans le même écran. Ce qui en découle, et qui
 * n'existait pour aucune des 139 modales du produit : l'adresse se partage et se met en signet, F5
 * ramène là où on était, et « précédent » ferme le formulaire au lieu de quitter le Catalogue.
 *
 * ⚠ LE PARENT NE MONTE LE FORMULAIRE QU'UNE FOIS LES VALEURS CONNUES. Il n'existe pas de route
 * `GET /promotions/{id}` : les valeurs viennent de la LISTE, qui met un aller-retour à arriver.
 * Monter le formulaire avant elle l'aurait laissé vide — et « Enregistrer » aurait alors CRÉÉ une
 * seconde promotion au lieu de modifier la première, puisque c'est `v.id` qui tranche entre POST
 * et PATCH.
 */
export default function PromotionsCatalogue({ etabActif, droits = [], params = {}, majParams }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [promotions, setPromotions] = useState(null)
  const [produits, setProduits] = useState(null)
  const [aSupprimer, setASupprimer] = useState(null)
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  // ⚠ `params` ET `majParams` SONT REQUIS, ET AUCUN REPLI NE LES REMPLACE. Un repli « si le
  // parent ne les passe pas, on ne fait rien » rendrait les deux boutons muets : on clique, rien
  // ne s'ouvre, et rien ne dit pourquoi. Le seul appelant est `Catalogue`, qui les passe.
  const ouvert = params.promo || ''
  const ouvrir = (v) => majParams({ promo: v }, { pousser: true })
  const fermer = () => ouvrir('')

  const peutGerer = aLeDroit(droits, 'offre.gerer')

  function charger() {
    setPromotions(null)
    api.promotions()
      .then((r) => setPromotions(membres(r)))
      .catch(() => setPromotions(undefined))
  }

  useEffect(charger, [etabActif])

  useEffect(() => {
    api.produits({ itemsPerPage: 200 })
      .then((r) => setProduits(membres(r)))
      .catch(() => setProduits(undefined))
  }, [etabActif])

  const nomProduit = useMemo(() => {
    const m = {}
    for (const p of produits || []) m[String(p.id)] = libelleProduit(p)
    return m
  }, [produits])

  const inertes = Array.isArray(promotions)
    ? promotions.filter((p) => idsEligibles(p).length === 0).length
    : 0

  // ── L'ÉCRAN DU FORMULAIRE, QUAND L'ADRESSE EN DÉSIGNE UN ────────────────────────────────────
  //
  // Il prend la place de la liste, comme la fiche produit prend la place du catalogue.
  if (ouvert) {
    const retour = (
      <button className="btn ghost sm" type="button" onClick={fermer}>
        ← Retour aux promotions
      </button>
    )

    // ⚠ TROIS ÉTATS AVANT LE FORMULAIRE, ET AUCUN NE DOIT SE FAIRE PASSER POUR UN AUTRE.
    // « on n'a pas encore lu », « on n'a pas pu lire » et « cet identifiant ne désigne rien »
    // conduisent chacun à un formulaire vide si on les confond — et un formulaire vide qu'on
    // enregistre CRÉE une promotion.
    if (ouvert !== 'nouveau' && promotions === null) {
      return (
        <section className="card">
          <div className="card-b">
            {retour}
            <div className="empty">Lecture des promotions…</div>
          </div>
        </section>
      )
    }

    if (ouvert !== 'nouveau' && promotions === undefined) {
      return (
        <section className="card">
          <div className="card-b">
            {retour}
            <div className="banner banner-warn">
              Les promotions n’ont pas pu être lues, donc celle-ci non plus. Ce n’est pas la même
              chose que « elle n’existe pas » : réessayez avant d’en recréer une.
            </div>
          </div>
        </section>
      )
    }

    const connue = ouvert === 'nouveau'
      ? null
      : (promotions || []).find((p) => String(p.id) === String(ouvert))

    if (ouvert !== 'nouveau' && !connue) {
      return (
        <section className="card">
          <div className="card-b">
            {retour}
            <div className="banner banner-warn">
              Cette promotion n’est plus dans la liste — elle a sans doute été supprimée depuis que
              ce lien a été copié. Rien n’a été perdu : revenez à la liste.
            </div>
          </div>
        </section>
      )
    }

    return (
      <section className="card">
        <div className="card-b">
          {retour}
          {erreur && <div className="banner banner-error">{erreur}</div>}
          <EditionPromotion
            // ⚠ LA CLÉ, ET PAS UN EFFET DE SYNCHRONISATION. `valeurs` est recalculé à chaque rendu,
            // donc d'identité neuve à chaque frappe : un `useEffect([valeurs])` qui réinjecte les
            // valeurs enregistrées effacerait la saisie à chaque caractère. La clé remonte le
            // formulaire quand on change de promotion, et le laisse tranquille sinon.
            key={ouvert}
            valeurs={connue ? valeursDe(connue) : { ...VIDE }}
            produits={produits}
            onFermer={fermer}
            onFait={(m) => { setSucces(m); setErreur(null); charger(); fermer() }}
            onErreur={setErreur}
          />
        </div>
      </section>
    )
  }

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Promotions</h3>
        <span className="sub">
          {promotions === null ? 'lecture…' : promotions === undefined ? 'illisible' : `${promotions.length}`}
        </span>
        {peutGerer && (
          <button
            className="btn primary sm"
            type="button"
            style={{ marginLeft: 'auto' }}
            onClick={() => { setErreur(null); setSucces(null); ouvrir('nouveau') }}
          >
            Nouvelle promotion
          </button>
        )}
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {promotions === undefined && (
          <div className="banner banner-warn">
            Les promotions n’ont pas pu être lues. Cet écran ne sait donc pas ce qui court — ce n’est
            pas la même chose que « aucune promotion ».
          </div>
        )}

        {/* ⚠ LE BANDEAU QUI COMPTE. Une promotion sans produit éligible ne réduit jamais rien : elle
            existe, elle a des dates, et elle est inerte. Sans ce compte, personne ne le découvre —
            surtout pas au moment où un client demande pourquoi la remise ne s'applique pas. */}
        {inertes > 0 && (
          <div className="banner banner-warn">
            <b>
              {inertes} promotion{inertes > 1 ? 's' : ''} ne s’applique
              {inertes > 1 ? 'nt' : ''} à aucun produit.
            </b>{' '}
            Une promotion sans produit éligible n’est pas « valable partout » — elle est sans effet.
            Ouvrez-la et choisissez les produits concernés.
          </div>
        )}

        {promotions === null && <div className="empty">Lecture des promotions…</div>}

        {Array.isArray(promotions) && promotions.length === 0 && (
          <div className="empty">
            Aucune promotion. Une promotion réduit le prix de produits désignés, sur une période et
            des canaux choisis.
          </div>
        )}

        {Array.isArray(promotions) && promotions.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Promotion</th>
                  <th>Remise</th>
                  <th>Période</th>
                  <th>S’applique à</th>
                  <th>Canaux</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {promotions.map((p) => {
                  const ids = idsEligibles(p)
                  const debut = jourFr(p.dateDebut)
                  const fin = jourFr(p.dateFin)
                  const canaux = Array.isArray(p.canaux) ? p.canaux : []
                  return (
                    <tr key={p.id}>
                      <td>
                        <span className="nm">{p.nom || '—'}</span>
                        {p.cumul === 'exclusif' && (
                          <div className="sub">exclusive — ne se cumule pas</div>
                        )}
                      </td>
                      <td>
                        {p.valeur || '—'}
                        <div className="sub">{LIB_TYPE[p.type] || p.type || '—'}</div>
                      </td>
                      <td>
                        {debut || fin
                          ? <>{debut || '—'} → {fin || 'sans fin'}</>
                          : <span className="sub">sans période</span>}
                      </td>
                      <td>
                        {ids.length === 0 ? (
                          <span className="badge warn" title="Sans produit éligible, cette promotion ne réduit jamais rien.">
                            aucun produit
                          </span>
                        ) : produits === undefined ? (
                          <span className="sub">{ids.length} produit(s), catalogue non lu</span>
                        ) : (
                          <span title={ids.map((i) => nomProduit[i] || i).join(', ')}>
                            {ids.length} produit{ids.length > 1 ? 's' : ''}
                          </span>
                        )}
                      </td>
                      <td>
                        {/* ⚠ CONVENTION INVERSE DE CELLE DES PRODUITS. Un tableau de canaux vide
                            veut dire TOUS — le calcul du prix ne filtre que si la liste est
                            renseignée. Écrire « aucun » ici serait exactement faux. */}
                        {canaux.length === 0
                          ? <span className="sub">tous</span>
                          : canaux.map((c) => LIB_CANAL[c] || c).join(', ')}
                      </td>
                      {peutGerer && (
                        <td>
                          <div style={{ display: 'flex', gap: 'var(--esp-normal)' }}>
                            <button
                              className="btn ghost sm"
                              type="button"
                              onClick={() => ouvrir(String(p.id))}
                            >
                              Modifier
                            </button>
                            <button
                              className="btn ghost sm"
                              type="button"
                              style={{ color: 'var(--crit)' }}
                              onClick={() => setASupprimer(p)}
                            >
                              Supprimer
                            </button>
                          </div>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <SuppressionPromotion
        promotion={aSupprimer}
        onFermer={() => setASupprimer(null)}
        onFait={(m) => { setASupprimer(null); setSucces(m); charger() }}
        onErreur={setErreur}
      />
    </section>
  )
}

/**
 * ⚠ AU MOINS UN PRODUIT, ET LE FORMULAIRE LE REFUSE PLUTÔT QUE DE LAISSER CRÉER UNE PROMOTION
 * INERTE.
 *
 * Le calcul du prix rend `false` dès que la liste des produits éligibles est vide ou absente. Une
 * promotion créée sans produit apparaîtrait dans la liste, porterait des dates, et ne réduirait
 * jamais rien — un défaut qu'on ne découvre qu'au comptoir, devant un client.
 *
 * Les canaux suivent la règle INVERSE : vides, ils valent tous. Le formulaire le dit à l'endroit
 * où on coche, parce que deux tableaux voisins qui se lisent à l'envers l'un de l'autre ne se
 * devinent pas.
 */
function EditionPromotion({ valeurs, produits, onFermer, onFait, onErreur }) {
  // ⚠ INITIALISATION PARESSEUSE, ET AUCUN EFFET DE SYNCHRONISATION. Le parent recalcule `valeurs`
  // à chaque rendu : un `useEffect([valeurs])` qui réinjecterait les valeurs enregistrées
  // effacerait la frappe en cours à chaque caractère. C'est la clé posée par le parent qui remonte
  // ce formulaire quand on change de promotion — le seul moment où il DOIT repartir de zéro.
  const [v, setV] = useState(() => valeurs)
  const [busy, setBusy] = useState(false)
  const [recherche, setRecherche] = useState('')

  if (!v) return null

  function champ(nom, valeur) { setV((p) => ({ ...p, [nom]: valeur })) }

  function basculer(liste, valeur) {
    return liste.includes(valeur) ? liste.filter((x) => x !== valeur) : [...liste, valeur]
  }

  const visibles = (produits || []).filter((p) => {
    if (recherche.trim() === '') return true
    return libelleProduit(p).toLowerCase().includes(recherche.trim().toLowerCase())
  })

  const pret = v.nom.trim() !== '' && v.valeur.trim() !== '' && v.produits.length > 0

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = {
        nom: v.nom.trim(),
        type: v.type,
        valeur: v.valeur.trim(),
        conditions: v.conditions.trim() || null,
        dateDebut: v.dateDebut || null,
        dateFin: v.dateFin || null,
        cumul: v.cumul,
        canaux: v.canaux,
        eligibilite: { produits: v.produits },
      }
      if (v.id) await api.majPromotion(v.id, corps)
      else await api.creerPromotion(corps)
      await onFait(v.id ? 'Promotion enregistrée.' : 'Promotion créée.')
    } catch (e) {
      onErreur(e.message || 'La promotion n’a pas pu être enregistrée.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h2>{v.id ? 'Modifier la promotion' : 'Nouvelle promotion'}</h2>
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Nom *</span>
          <input
            className="input"
            value={v.nom}
            onChange={(e) => champ('nom', e.target.value)}
            placeholder="Tarif d’été, offre de rentrée…"
            maxLength={120}
          />
        </label>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-normal)' }}>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Type</span>
            <select className="select" value={v.type} onChange={(e) => champ('type', e.target.value)}>
              {TYPES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </label>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Valeur *</span>
            <input
              className="input"
              value={v.valeur}
              onChange={(e) => champ('valeur', e.target.value)}
              placeholder={v.type === 'pourcentage' ? '10' : '2.50'}
            />
          </label>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-normal)' }}>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Du</span>
            <input className="input" type="date" value={v.dateDebut} onChange={(e) => champ('dateDebut', e.target.value)} />
          </label>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Au</span>
            <input className="input" type="date" value={v.dateFin} onChange={(e) => champ('dateFin', e.target.value)} />
          </label>
        </div>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Cumul</span>
          <select className="select" value={v.cumul} onChange={(e) => champ('cumul', e.target.value)}>
            <option value="cumulable">Cumulable avec d’autres promotions</option>
            <option value="exclusif">Exclusive — elle seule s’applique</option>
          </select>
        </label>

        <div style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Canaux</span>
          <div style={{ display: 'flex', gap: 'var(--esp-normal)', flexWrap: 'wrap' }}>
            {CANAUX.map(([k, l]) => (
              <label key={k} style={{ display: 'flex', gap: 'var(--esp-serre)', alignItems: 'baseline' }}>
                <input
                  type="checkbox"
                  checked={v.canaux.includes(k)}
                  onChange={() => champ('canaux', basculer(v.canaux, k))}
                />
                <span>{l}</span>
              </label>
            ))}
          </div>
          <span className="sub">
            {v.canaux.length === 0
              ? 'Aucun coché : la promotion s’applique sur TOUS les canaux.'
              : 'Elle ne s’applique que sur les canaux cochés.'}
          </span>
        </div>

        {/* ⚠ LA PARTIE QUI COMPTE. Sans produit, la promotion est inerte — et rien à l'usage ne le
            dirait. Le bouton reste donc bloqué, et la raison est écrite ici, pas dans un message
            d'erreur qui arriverait après la saisie. */}
        <div style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Produits concernés *</span>
          {produits === undefined ? (
            <span className="sub">
              Le catalogue n’a pas pu être lu : impossible de choisir les produits pour l’instant.
            </span>
          ) : (
            <>
              <input
                className="input"
                value={recherche}
                onChange={(e) => setRecherche(e.target.value)}
                placeholder="filtrer le catalogue…"
              />
              <div style={{ maxHeight: 200, overflowY: 'auto', border: '1px solid var(--line)', borderRadius: 8, padding: 'var(--esp-normal)' }}>
                {visibles.length === 0 ? (
                  <span className="sub">Aucun produit ne correspond.</span>
                ) : visibles.map((p) => (
                  <label key={p.id} style={{ display: 'flex', gap: 'var(--esp-serre)', alignItems: 'baseline' }}>
                    <input
                      type="checkbox"
                      checked={v.produits.includes(String(p.id))}
                      onChange={() => champ('produits', basculer(v.produits, String(p.id)))}
                    />
                    <span>{libelleProduit(p)}</span>
                  </label>
                ))}
              </div>
            </>
          )}
          <span className={v.produits.length === 0 ? 'sub' : 'sub'}>
            {v.produits.length === 0
              ? '⚠ Aucun produit choisi : la promotion ne réduirait RIEN. Ce n’est pas « valable partout ».'
              : `${v.produits.length} produit${v.produits.length > 1 ? 's' : ''} concerné${v.produits.length > 1 ? 's' : ''}.`}
          </span>
        </div>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Conditions — texte libre</span>
          <textarea
            className="input"
            rows={2}
            value={v.conditions}
            onChange={(e) => champ('conditions', e.target.value)}
            placeholder="sur présentation d’un justificatif…"
          />
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="button" disabled={busy || !pret} onClick={enregistrer}>
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </>
  )
}

/**
 * La fenêtre dit CE QUI VA SE PASSER, jamais « êtes-vous sûr » — patron repris d'`ImpayesRecouvrement`.
 * Une promotion supprimée ne se retrouve pas : le serveur la retire, il ne l'archive pas.
 */
function SuppressionPromotion({ promotion, onFermer, onFait, onErreur }) {
  const [busy, setBusy] = useState(false)

  async function supprimer() {
    setBusy(true)
    onErreur(null)
    try {
      await api.supprimerPromotion(promotion.id)
      await onFait('Promotion supprimée.')
    } catch (e) {
      onErreur(e.message || 'La promotion n’a pas pu être supprimée.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={!!promotion} onClose={onFermer} titre="Supprimer la promotion" taille="sm">
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div>
          <b>{promotion?.nom}</b> disparaît définitivement. Les ventes déjà conclues gardent la
          remise qu’elles ont reçue — c’est la promotion à venir qui cesse de s’appliquer.
        </div>
        <div className="sub">
          Pour l’arrêter sans la perdre, fermez plutôt sa période&nbsp;: mettez une date de fin
          d’hier.
        </div>
        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn" type="button" disabled={busy} onClick={supprimer}>
            {busy ? 'Suppression…' : 'Supprimer'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
