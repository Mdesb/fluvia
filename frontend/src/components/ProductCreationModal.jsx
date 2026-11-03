import { useEffect, useRef, useState } from 'react'
import Modal from './Modal.jsx'
import { api, membres } from '../api/client.js'
import { CANAUX_PRODUIT } from '../api/produit.js'
import { complementDuType } from '../api/publication.js'
import {
  ETAPES, corpsApercu, corpsPrix, corpsProduit, descriptionDuType, issueDeCreation, manquesEtape, recapitulatif,
  saisieInitiale, toutEstPret,
} from '../api/productCreation.js'

/**
 * CRÉER UN PRODUIT EN TROIS ÉTAPES (refonte de la création, lot 1 — CP-1 de Maxime, 06/10 : « modale
 * à étapes, un récapitulatif avant Créer »). Ce que vous vendez → son prix → où le vendre → vérifier.
 *
 * ⚠ RIEN N'EST ÉCRIT AVANT « Créer le produit ». Même raison que l'ancienne fiche vierge : un
 * aller-retour laisserait des produits fantômes, et aucun bouton n'en supprime.
 *
 * ⚠ DEUX APPELS, DANS CET ORDRE. Le produit (avec sa carte ou sa formule, ses sites, sa catégorie :
 * des champs du produit, donc un seul `POST`), puis son prix (une grille, qui s'attache à un produit
 * existant). Si le prix échoue, le produit existe déjà : on ouvre sa fiche quand même, et l'encadré
 * « il manque » de la fiche (#310) dit « Donnez-lui au moins un prix » — ce qui est vrai.
 *
 * La logique (étapes, valeurs proposées, corps) est dans `api/productCreation.js`, testée seule.
 */
export default function ProductCreationModal({ open, types = [], etabActif, capacites = [], onClose, onCree }) {
  const venteEnLigne = capacites.includes('boutique_en_ligne')
  const [etape, setEtape] = useState(0)
  const [saisie, setSaisie] = useState(() => saisieInitiale({ etabActif, venteEnLigne }))
  // Réponse de `POST /produits/readiness-preview` au récapitulatif : `null` tant qu'elle n'est pas lue.
  const [apercu, setApercu] = useState(null)
  const [refs, setRefs] = useState({ typesTarif: [], categories: [], etablissements: [] })
  const [montrerManques, setMontrerManques] = useState(false)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)
  const corpsRef = useRef(null)

  // Les référentiels se lisent à l'ouverture. Une lecture refusée laisse une liste vide : l'étape
  // dit alors ce qui manque, au lieu de bloquer toute la création.
  useEffect(() => {
    if (!open) return
    let vivant = true
    const lire = (p) => p.then(membres).catch(() => [])
    Promise.all([lire(api.typeTarifs()), lire(api.categories()), lire(api.etablissements())])
      .then(([typesTarif, categories, etablissements]) => {
        if (!vivant) return
        setRefs({ typesTarif, categories, etablissements })
        setSaisie(saisieInitiale({ etabActif, typesTarif, categories, venteEnLigne }))
      })
    setEtape(0)
    setMontrerManques(false)
    setErreur(null)
    setEnCours(false)
    return () => { vivant = false }
  }, [open, etabActif, venteEnLigne])

  // Le focus suit l'étape : le premier champ de la nouvelle étape, sinon son titre. Sans cela il
  // restait sur « Continuer », qui venait de changer de sens sous le doigt.
  useEffect(() => {
    if (!open) return
    const zone = corpsRef.current
    if (!zone) return
    const cible = zone.querySelector('input, select, [role="radio"][tabindex="0"]') || zone.querySelector('h4')
    cible?.focus()
  }, [etape, open])

  const type = types.find((t) => String(t.id) === String(saisie.typeId)) || null
  const cle = ETAPES[etape].cle

  // Au récapitulatif, la garde de publication dit ce qui manquerait (voir api/productCreation.js).
  // Relue à chaque arrivée sur l'étape : un retour en arrière a pu tout changer.
  useEffect(() => {
    if (!open || cle !== 'recap') return
    let vivant = true
    setApercu(null)
    api.apercuPublication(corpsApercu(saisie, type))
      .then((r) => { if (vivant) setApercu(r?.missing ?? null) })
      .catch(() => { if (vivant) setApercu(null) })
    return () => { vivant = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, cle])
  const issue = issueDeCreation(apercu)
  const manques = cle === 'recap' ? [] : manquesEtape(cle, saisie, type)
  const maj = (champ, valeur) => setSaisie((s) => ({ ...s, [champ]: valeur }))

  function continuer() {
    if (manques.length > 0) {
      setMontrerManques(true)
      return
    }
    setMontrerManques(false)
    setEtape((e) => e + 1)
  }

  // `vendre` : créer, poser le prix, puis publier dans la foulée. Si la publication est refusée (une
  // règle a changé entre l'aperçu et le clic), le produit existe : sa fiche s'ouvre et son encadré
  // « il manque » dit pourquoi.
  async function creer(vendre) {
    if (!toutEstPret(saisie, type)) return
    setEnCours(true)
    setErreur(null)
    let cree
    try {
      cree = await api.creerProduit(corpsProduit(saisie, type))
    } catch (e) {
      setErreur(e?.message || 'Le produit n’a pas pu être créé.')
      setEnCours(false)
      return
    }
    let prixPose = true
    try {
      await api.creerGrilleTarifaire(corpsPrix(cree.id, saisie))
    } catch {
      // Voir l'en-tête : la fiche dira qu'il manque un prix.
      prixPose = false
    }
    if (vendre && prixPose) {
      try {
        await api.publierProduit(cree.id)
      } catch {
        // Voir ci-dessus : la fiche dira ce qui manque.
      }
    }
    await onCree(cree)
  }

  return (
    <Modal open={open} onClose={onClose} titre="Nouveau produit" taille="md">
      <ol className="cree-etapes" aria-label="Étapes">
        {ETAPES.map((e, i) => (
          <li key={e.cle} aria-current={i === etape ? 'step' : undefined} className={i < etape ? 'fait' : undefined}>
            <span className="cree-etape-num" aria-hidden="true">{i < etape ? '✓' : i + 1}</span>
            {e.titre}
          </li>
        ))}
      </ol>

      <div ref={corpsRef}>
        <h4 className="cree-titre" tabIndex={-1} aria-live="polite">
          Étape {etape + 1} sur {ETAPES.length} · {ETAPES[etape].titre}
        </h4>

        {erreur && <div className="banner banner-error" role="alert">{erreur}</div>}

        {cle === 'quoi' && (
          <EtapeQuoi saisie={saisie} maj={maj} types={types} type={type} />
        )}

        {cle === 'prix' && (
          <>
            <div className="row row-champs cree-rangee">
              <div className="field">
                <label htmlFor="cree-prix">Prix *</label>
                <input id="cree-prix" className="input num" inputMode="decimal" placeholder="Ex. 12,50"
                  value={saisie.prix} onChange={(e) => maj('prix', e.target.value)} />
              </div>
              <div className="field">
                <label htmlFor="cree-tarif">Tarif</label>
                <select id="cree-tarif" className="select" value={saisie.typeTarifId}
                  onChange={(e) => maj('typeTarifId', e.target.value)}>
                  {refs.typesTarif.length === 0 && <option value="">Aucun tarif lisible</option>}
                  {refs.typesTarif.map((t) => <option key={t.id} value={t.id}>{t.nom}</option>)}
                </select>
              </div>
            </div>
            <p className="hint">
              Valable toute l’année. Enfant, réduit, une période de l’année : ils s’ajoutent ensuite sur la fiche.
            </p>
            <div className="field">
              <label htmlFor="cree-cat">Catégorie de recette</label>
              <select id="cree-cat" className="select" value={saisie.categorieId}
                onChange={(e) => maj('categorieId', e.target.value)}>
                <option value="">Choisir plus tard</option>
                {refs.categories.filter((c) => c.axe === 'comptable').map((c) => (
                  <option key={c.id} value={c.id}>{c.libelle || c.nom}</option>
                ))}
              </select>
              <div className="hint">Le compte où la vente sera enregistrée. Elle est nécessaire pour mettre en vente.</div>
            </div>
          </>
        )}

        {cle === 'ou' && (
          <>
            <fieldset className="cree-groupe">
              <legend>Où le vendre *</legend>
              {CANAUX_PRODUIT.map((c) => (
                <label key={c.valeur} className="cree-case">
                  <input type="checkbox" checked={saisie.canaux.includes(c.valeur)}
                    onChange={(ev) => maj('canaux', ev.target.checked
                      ? [...saisie.canaux, c.valeur] : saisie.canaux.filter((x) => x !== c.valeur))} />
                  {c.libelle}
                </label>
              ))}
            </fieldset>
            <fieldset className="cree-groupe">
              <legend>Sur quels sites *</legend>
              {refs.etablissements.map((e) => (
                <label key={e.id} className="cree-case">
                  <input type="checkbox" checked={saisie.etablissements.includes(String(e.id))}
                    onChange={(ev) => maj('etablissements', ev.target.checked
                      ? [...saisie.etablissements, String(e.id)]
                      : saisie.etablissements.filter((x) => x !== String(e.id)))} />
                  {e.nom || e.libelle}
                </label>
              ))}
            </fieldset>
          </>
        )}

        {cle === 'recap' && (
          <>
            <dl className="cree-recap">
              {recapitulatif(saisie, { type, ...refs, canaux: CANAUX_PRODUIT }).map(([k, v]) => (
                <div key={k}><dt>{k}</dt><dd>{v}</dd></div>
              ))}
            </dl>
            {apercu === null && <p className="hint" role="status">Vérification de ce qui manquerait pour le vendre…</p>}
            {issue.vendre && (
              <p className="hint">Rien ne manque : il peut être mis en vente dès sa création.</p>
            )}
            {issue.resteAFaire && <div className="banner" role="status">{issue.resteAFaire}</div>}
          </>
        )}

        {montrerManques && manques.length > 0 && (
          <div className="banner banner-warn" role="alert">
            <ul className="cree-manques">{manques.map((m) => <li key={m}>{m}</li>)}</ul>
          </div>
        )}
      </div>

      <div className="modal-actions">
        {etape > 0 && (
          <button className="btn ghost" type="button" disabled={enCours} onClick={() => { setMontrerManques(false); setEtape((e) => e - 1) }}>
            ← Retour
          </button>
        )}
        {cle === 'recap' && issue.vendre && (
          <>
            <button className="btn ghost" type="button" disabled={enCours} onClick={() => creer(false)}>
              Créer en brouillon
            </button>
            <button className="btn primary" type="button" disabled={enCours} onClick={() => creer(true)}>
              {enCours ? 'Création…' : 'Créer et mettre en vente'}
            </button>
          </>
        )}
        {cle === 'recap' && !issue.vendre && (
          <button className="btn primary" type="button" disabled={enCours} onClick={() => creer(false)}>
            {enCours ? 'Création…' : 'Créer le produit'}
          </button>
        )}
        {cle !== 'recap' && (
          <button className="btn primary" type="button" onClick={continuer}>Continuer</button>
        )}
      </div>
    </Modal>
  )
}

// Le type en cartes, comme un groupe de boutons radio : une seule tabulation pour y entrer, les
// flèches pour changer — le motif radiogroup de l'ARIA APG, sans `<input type=radio>` caché.
function EtapeQuoi({ saisie, maj, types, type }) {
  const complement = complementDuType(type)
  const choisi = types.findIndex((t) => String(t.id) === String(saisie.typeId))

  function surTouche(e, i) {
    const pas = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key]
    if (!pas) return
    e.preventDefault()
    const suivant = (i + pas + types.length) % types.length
    maj('typeId', String(types[suivant].id))
    e.currentTarget.parentElement.children[suivant]?.focus()
  }

  return (
    <>
      <div className="field">
        <label htmlFor="cree-nom">Nom *</label>
        <input id="cree-nom" className="input" placeholder="Ex. Entrée adulte" value={saisie.libelle}
          onChange={(e) => maj('libelle', e.target.value)} />
      </div>

      <div id="cree-type-legende" className="cree-legende">Ce que c’est *</div>
      <div className="cree-types" role="radiogroup" aria-labelledby="cree-type-legende">
        {types.map((t, i) => {
          const d = descriptionDuType(t)
          const coche = i === choisi
          return (
            // Nom et description EXPLICITES : l'arbre d'accessibilité relevé le 03/11 donnait des
            // « radio » sans nom, le nom calculé depuis le contenu n'étant pas repris partout.
            <button key={t.id} type="button" role="radio" aria-checked={coche}
              aria-label={t.libelle} aria-describedby={d ? `cree-type-d-${t.id}` : undefined}
              tabIndex={coche || (choisi === -1 && i === 0) ? 0 : -1}
              className="cree-type" onClick={() => maj('typeId', String(t.id))} onKeyDown={(e) => surTouche(e, i)}>
              <span className="cree-type-nom">{t.libelle}</span>
              {d && (
                <span id={`cree-type-d-${t.id}`} className="cree-type-desc">
                  <span className="cree-type-phrase">{d.phrase}</span>
                  <span className="cree-type-ex">Ex. : {d.exemple}</span>
                </span>
              )}
            </button>
          )
        })}
      </div>
      <p className="hint">
        Enfant, réduit, étudiant… ce sont des tarifs du même produit, pas des produits.
        Le type ne se change plus ensuite.
      </p>

      {complement === 'carte' && (
        <div className="row row-champs cree-rangee">
          <div className="field">
            <label htmlFor="cree-paye">Entrées payées *</label>
            <input id="cree-paye" className="input num" type="number" min="1" value={saisie.carte.nbPaye}
              onChange={(e) => maj('carte', { ...saisie.carte, nbPaye: e.target.value })} />
          </div>
          <div className="field">
            <label htmlFor="cree-recu">Entrées reçues *</label>
            <input id="cree-recu" className="input num" type="number" min="1" value={saisie.carte.nbCredite}
              onChange={(e) => maj('carte', { ...saisie.carte, nbCredite: e.target.value })} />
          </div>
          <p className="hint cree-pleine">On paie {saisie.carte.nbPaye || '?'} entrées, on en reçoit {saisie.carte.nbCredite || '?'}.</p>
        </div>
      )}

      {complement === 'formule' && (
        <div className="row row-champs cree-rangee">
          <div className="field">
            <label htmlFor="cree-per">Périodicité *</label>
            <select id="cree-per" className="select" value={saisie.formule.periodicite}
              onChange={(e) => maj('formule', { ...saisie.formule, periodicite: e.target.value })}>
              <option value="mensuel">Mensuelle</option>
              <option value="annuel">Annuelle</option>
            </select>
          </div>
          <label className="cree-case">
            <input type="checkbox" checked={saisie.formule.sepaActif}
              onChange={(e) => maj('formule', { ...saisie.formule, sepaActif: e.target.checked })} />
            Prélever automatiquement (SEPA)
          </label>
          {saisie.formule.sepaActif && (
            <div className="field">
              <label htmlFor="cree-jour">Jour du prélèvement (1 à 28)</label>
              <input id="cree-jour" className="input num" type="number" min="1" max="28" value={saisie.formule.jourPrelevement}
                onChange={(e) => maj('formule', { ...saisie.formule, jourPrelevement: e.target.value })} />
            </div>
          )}
        </div>
      )}
    </>
  )
}
