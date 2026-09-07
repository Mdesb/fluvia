import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import Modal from './Modal.jsx'
import Tabs from './Tabs.jsx'
import { jourLocal } from './Liste.jsx'
import { libelleProduit } from '../api/produit.js'
import { euros } from '../api/produit.js'

// LA FICHE D'UN ABONNEMENT — l'écran qui manquait, et que la liste avouait ne pas avoir.
//
// `Sport.jsx` portait cette phrase sous son tableau : « Pause, résiliation et réengagement passent
// encore par l'API : cet écran les montre, il ne les édite pas. » Trois gestes que le serveur sait
// faire depuis l'origine, qu'aucun bouton ne déclenchait, et une liste comme seule vue d'un contrat
// qui engage douze mois et prélève chaque mois.
//
// ── DEUX PERSONNES, JAMAIS UNE ────────────────────────────────────────────────────────────────
//
// L'adhérent entre, le payeur règle, et ce ne sont pas les mêmes objets : `adherent` est un
// `Beneficiaire`, `payeur` un `Client`. Un enfant inscrit par ses parents est le cas qui justifie
// la séparation, et c'est celui que la liste rendait invisible en n'affichant que l'adhérent.
// L'en-tête les montre côte à côte, avec leur rôle écrit — pas deux noms qu'on devine.
//
// ── CE QU'ON NE PEUT PAS MONTRER, ET POURQUOI ON LE DIT ───────────────────────────────────────
//
// ⚠ `AbonnementFitness::$formule` n'a AUCUN groupe de sérialisation : la relation existe, l'API ne
// la rend pas. On ne peut donc pas afficher le nom de la formule souscrite. Écrire « — » ferait
// lire « aucune formule » ; on écrit ce qui est vrai : la donnée n'est pas rendue.
//
// ⚠ Le RÉENGAGEMENT n'est pas ici. Il exige un mandat NEUF, donc un IBAN et un titulaire —
// c'est un formulaire de souscription, pas un bouton. L'afficher désactivé ferait chercher ce qui
// le débloque ; on ne l'affiche pas, et la fiche dit où il se trouve.

const TONS = { actif: 'good', pause: 'warn', impaye: 'crit', resilie: 'mut' }

// ⚠ CE NOM MENTAIT : la fonction prend des CENTIMES, et tous ses appelants lui en passent.
// Elle reimplementait aussi `euros()` a la main — virgule oui, mais ni separateur de milliers ni
// espace insecable : `125000` rendait « 1250,00 € » au lieu de « 1 250,00 € ». Elle delegue
// desormais, et porte le nom que la boutique donne deja au meme calcul.
function eurosCentimes(centimes) {
  if (centimes == null) return '—'
  return euros(centimes / 100)
}

function jour(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

function Champ({ libelle, children, aide }) {
  return (
    <div className="field">
      <label>{libelle}</label>
      <div>{children}</div>
      {aide && <p className="hint">{aide}</p>}
    </div>
  )
}

export default function FicheAbonnement({ abonnement, nomAdherent, nomPayeur, onFerme, onModifie }) {
  const [onglet, setOnglet] = useState('contrat')
  const [echeances, setEcheances] = useState(null)
  const [reduction, setReduction] = useState(null)
  const [geste, setGeste] = useState(null)
  const [tronque, setTronque] = useState(false)
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un objet = trouve ; `false` = lu, aucun
  // statut pour cet abonnement. Quatre etats, parce que « pas de statut » et « pas pu lire » ne se
  // corrigent pas de la meme facon — et qu'annoncer « acces ouvert » sur une lecture ratee
  // enverrait quelqu'un a la porte pour rien.
  const [acces, setAcces] = useState(null)
  // ⚠ TROIS ETATS, comme pour l'echeancier. `undefined` = pas lu ou refuse ; `null` = lu, aucun
  // produit ne porte cette formule ; une chaine = le libelle. Un « — » a la place d'un refus ferait
  // croire a un abonnement sans formule, alors que le champ est NON NULLABLE en base.
  const [formuleNom, setFormuleNom] = useState(undefined)

  const a = abonnement

  useEffect(() => {
    if (!a?.id) return undefined
    let annule = false
    setEcheances(null)
    // Le filtre serveur existe depuis `37473e7` : `SearchFilter` en `exact` sur `abonnement`.
    //
    // Cet ecran lisait auparavant TOUTE la collection et triait ici, parce que l'entite ne
    // declarait aucun filtre -- `?abonnement=` etait alors accepte et IGNORE EN SILENCE, ce qui est
    // le pire des deux mondes : ca a l'air de marcher. Le tri local disparait avec sa cause.
    //
    // ⚠ LE FILTRE EST PROUVE PAR UNE EXCLUSION, pas par la justesse de ce qu'il rend. Son test
    // fabrique un voisin, exige que la collection entiere porte au moins deux abonnements, puis
    // que la liste filtree soit STRICTEMENT plus courte. Une liste vide ou une liste inchangee
    // le font tomber -- sans ces deux temoins, un filtre qui efface tout aurait l'air de marcher.
    api
      .echeancesSepaSport({
        abonnement: `/api/abonnement_fitnesses/${a.id}`,
        'order[dateProgrammee]': 'asc',
      })
      // ⚠ TROIS ETATS, ET LA DIFFERENCE N'EST PAS COSMETIQUE. `null` = on lit encore ; `undefined`
      // = on n'a PAS PU lire (un compte sans droit comptable recoit un 403 sur ce referentiel) ;
      // une liste vide = on a lu, il n'y a rien. Confondre les deux derniers ferait dire a l'ecran
      // « aucune echeance » la ou il n'a rien mesure.
      .then((r) => {
        if (annule) return
        const miennes = membres(r)
        setEcheances(miennes)
        // Le plafond porte desormais sur UN abonnement : 200 mensualites, c'est plus de seize ans.
        // L'avertissement reste -- un plafond muet est un mensonge -- mais le cas cesse d'etre
        // courant, alors qu'il etait atteignable des 200 echeances toutes formules confondues.
        setTronque(miennes.length >= 200)
      })
      .catch(() => { if (!annule) setEcheances(undefined) })
    return () => { annule = true }
  }, [a?.id])

  useEffect(() => {
    if (!a?.id) return
    let annule = false
    setAcces(null)
    api
      .statutsAccesFitness()
      .then((r) => {
        if (annule) return
        // ⚠ TRI LOCAL, faute de filtre declare cote serveur. `AbonnementFitness::$id` est dans le
        // groupe `statut_acces:read` (verifie) : l'objet imbrique porte donc bien `id`.
        const mien = membres(r).find((s) => {
          const ref = s.abonnement
          const id = typeof ref === 'string' ? String(ref).split('/').pop() : ref?.id
          return String(id) === String(a.id)
        })
        setAcces(mien || false)
      })
      .catch(() => { if (!annule) setAcces(undefined) })
    return () => { annule = true }
  }, [a?.id])

  // LE NOM DE LA FORMULE, PAR LE CATALOGUE.
  //
  // `Formule` n'est pas une ressource independante -- c'est une facette de `Produit` -- donc aucune
  // IRI ne peut pointer dessus. L'abonnement expose l'IDENTIFIANT (`getFormuleId`, groupe
  // `abonnement:read`) et le catalogue expose l'objet (`Produit::$formule`, groupe `produit:read`,
  // avec son `id`). Le rapprochement se fait donc ici, exactement comme `Sport.jsx:786` le fait deja
  // pour la souscription.
  useEffect(() => {
    const fid = a?.formuleId
    if (!fid) { setFormuleNom(null); return }
    let annule = false
    setFormuleNom(undefined)
    api
      .produits()
      .then((r) => {
        if (annule) return
        const p = membres(r).find((x) => x?.formule?.id === fid)
        setFormuleNom(p ? libelleProduit(p) : null)
      })
      .catch(() => { if (!annule) setFormuleNom(undefined) })
    return () => { annule = true }
  }, [a?.formuleId])

  const prochaine = useMemo(() => {
    if (!Array.isArray(echeances)) return null
    // ⚠ `jourLocal`, et jamais une date UTC tronquee : celle-ci rend LA VEILLE entre minuit et
    // deux heures du matin a Paris en ete. « Prochaine echeance » aurait alors designe celle
    // d'hier, deux heures par nuit. Le garde-fou n°31 l'a attrape sur ce fichier meme.
    const aujourdhui = jourLocal()
    return echeances.find((e) => (e.dateProgrammee || '') >= aujourdhui) || null
  }, [echeances])

  if (!a) return null

  const mandat = a.mandatSepa && typeof a.mandatSepa === 'object' ? a.mandatSepa : null

  return (
    <div className="card card-espacee">
      {/* ── identité : deux personnes, chacune avec son rôle ───────────────────────────────── */}
      <div className="card-h">
        <div className="fiche-ident">
          <div className="fiche-avatar" aria-hidden="true">AB</div>
          <div>
            <div className="fiche-nom">{nomAdherent}</div>
            <div className="sub">adhérent — celui qui entre</div>
          </div>
        </div>
        <div className="fiche-ident">
          <div>
            <div className="fiche-nom">{nomPayeur}</div>
            <div className="sub">payeur — celui qui règle</div>
          </div>
        </div>
        <div className="r">
          <span className={`badge ${TONS[a.statut] || 'mut'}`}>{a.statut || '—'}</span>
          <button className="btn sm" type="button" onClick={onFerme}>Fermer</button>
        </div>
      </div>

      {/* ── état : ce qu'aucun onglet ne modifie ───────────────────────────────────────────── */}
      <div className="card-b">
        <div className="fiche-stats">
          <div className="stat-tile">
            <div className="st-val num">{eurosCentimes(a.montantCentimes)}</div>
            <div className="st-lbl">Montant courant</div>
          </div>
          <div className="stat-tile">
            <div className="st-val">{jour(a.dateFinEngagement)}</div>
            <div className="st-lbl">Terme de l’engagement</div>
          </div>
          <div className="stat-tile">
            <div className="st-val">{prochaine ? jour(prochaine.dateProgrammee) : '—'}</div>
            <div className="st-lbl">Prochaine échéance</div>
          </div>
          <div className="stat-tile">
            <div className="st-val num">{a.preavisResiliationJours ?? '—'} j</div>
            <div className="st-lbl">Préavis</div>
          </div>
        </div>
      </div>

      <div className="card-b">
        <Tabs
          onglets={[['contrat', 'Contrat'], ['prelevements', 'Prélèvements'], ['acces', 'Accès']]}
          actif={onglet}
          onChange={setOnglet}
        />
      </div>

      {onglet === 'contrat' && (
        <div className="card-b">
          <Champ libelle="Périodicité">{a.periodicite || '—'}</Champ>
          <Champ libelle="Souscrit le">{jour(a.dateSouscription)}</Champ>
          <Champ libelle="Engagement">
            {jour(a.dateDebutEngagement)} → {jour(a.dateFinEngagement)}
          </Champ>
          <Champ
            libelle="Formule"
            aide="La formule est une facette du produit : son nom vient du catalogue, rapproché par identifiant."
          >
            {formuleNom === undefined
              ? <span className="sub">catalogue non lu</span>
              : formuleNom || <span className="sub">introuvable au catalogue</span>}
          </Champ>

          <div className="card-h">
            <h3>Gestes</h3>
            <div className="r">
              <button className="btn sm" type="button" onClick={() => setGeste('pause')}>
                Mettre en pause
              </button>
              <button className="btn sm" type="button" onClick={() => setGeste('resiliation')}>
                Résilier
              </button>
            </div>
          </div>
          <p className="hint">
            Le réengagement d’un abonnement résilié exige un mandat SEPA neuf — donc un IBAN et un
            titulaire. Il passe par la souscription, pas par cette fiche.
          </p>
        </div>
      )}

      {onglet === 'acces' && <OngletAcces acces={acces} />}

      {onglet === 'prelevements' && (
        <div className="card-b">
          {mandat ? (
            <>
              <Champ libelle="Mandat SEPA">
                <span className="mono">{mandat.rum || '—'}</span>
              </Champ>
              <Champ libelle="IBAN">
                <span className="mono">•••• {mandat.iban4Derniers || '••••'}</span>
              </Champ>
              <Champ libelle="Signé le">{jour(mandat.dateSignature)}</Champ>
              <Champ libelle="Statut du mandat">
                <span className={`badge ${mandat.statut === 'actif' ? 'good' : 'mut'}`}>
                  {mandat.statut || '—'}
                </span>
              </Champ>
            </>
          ) : (
            <p className="hint">Aucun mandat rendu pour cet abonnement.</p>
          )}

          {echeances === null && <p className="hint">Chargement des échéances…</p>}
          {echeances === undefined && (
            <p className="hint">
              Les échéances n’ont pas pu être lues — ce compte n’a probablement pas le droit
              comptable. Ce n’est pas « aucune échéance ».
            </p>
          )}
          {Array.isArray(echeances) && echeances.length === 0 && (
            <p className="hint">Aucune échéance programmée.</p>
          )}
          {tronque && (
            <div className="banner banner-warn">
              Cet echeancier s’arrete a 200 lignes pour ce seul abonnement — plus de seize ans de
              mensualites. S’il s’affiche, la liste ci-dessous est incomplete.
            </div>
          )}
          {Array.isArray(echeances) && echeances.length > 0 && (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th className="num">Date</th>
                    <th className="num">Montant</th>
                    <th>Statut</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {echeances.map((e) => (
                    <tr key={e.id}>
                      <td className="num">{jour(e.dateProgrammee)}</td>
                      <td className="num">
                        {eurosCentimes(e.montantCentimes)}
                        {/* ⚠ ON MONTRE LES DEUX MONTANTS, PAS SEULEMENT LE RÉDUIT. Un prélèvement
                            qui ne correspond pas au contrat se conteste ; l'écart doit se lire ici,
                            avec son motif, plutôt que se reconstituer six mois plus tard. */}
                        {e.montantInitialCentimes != null && (
                          <div className="sub">
                            <s>{eurosCentimes(e.montantInitialCentimes)}</s>
                            {e.reductionMotif ? ` · ${e.reductionMotif}` : ''}
                          </div>
                        )}
                      </td>
                      <td><span className="badge mut">{e.statut || '—'}</span></td>
                      <td>
                        {e.statut === 'a_venir' && e.montantInitialCentimes == null && (
                          <button className="btn sm" type="button" onClick={() => setReduction(e)}>
                            Réduire
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      <GesteModal
        geste={geste}
        abonnement={a}
        onClose={() => setGeste(null)}
        onFait={() => { setGeste(null); onModifie?.() }}
      />

      <ReductionModal
        echeance={reduction}
        onClose={() => setReduction(null)}
        onFait={() => { setReduction(null); onModifie?.() }}
      />
    </div>
  )
}

/**
 * UNE OFFRE SUR UN PRÉLÈVEMENT À VENIR.
 *
 * ⚠ ELLE DIT CE QU'ELLE RETIRE, JAMAIS LE MONTANT D'ARRIVÉE. « moins 10 € » et « à 10 € » se
 * confondent dans une tête pressée, et la confusion ne produit ni erreur ni message : elle produit
 * un prélèvement faux. Le champ est donc libellé « Montant retiré », et la fenêtre affiche le calcul
 * en toutes lettres avant de valider.
 *
 * ⚠ ET ELLE ANNONCE LA CONSÉQUENCE QUE PERSONNE NE DEVINERAIT : changer le montant repousse le
 * prélèvement. Le préavis SEPA doit être réémis pour le nouveau montant, et le délai légal repart de
 * zéro — « un montant qui change doit rendre au client la totalité du délai ». Un geste consenti
 * trois jours avant l'échéance la décale donc de deux semaines. Le serveur rend la date ; on la
 * montre au moment du geste, pas au relevé.
 */
function ReductionModal({ echeance, onClose, onFait }) {
  const [montant, setMontant] = useState('')
  const [motif, setMotif] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  const [faite, setFaite] = useState(null)

  useEffect(() => {
    setMontant(''); setMotif(''); setErreur(null); setFaite(null)
  }, [echeance])

  // ⚠ `Math.round`, PAS UNE TRONCATURE : `10,50 €` doit valoir 1050 centimes, et `parseInt` en
  //    rendrait 10. Le serveur refuse tout ce qui n'est pas un entier, mais un entier FAUX passerait.
  const centimes = montant.trim() === '' ? null : Math.round(Number(montant.replace(',', '.')) * 100)
  const valide = centimes !== null && Number.isFinite(centimes) && centimes > 0
    && echeance && centimes < echeance.montantCentimes && motif.trim() !== ''

  async function envoyer(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      setFaite(await api.reduireEcheance(echeance.id, { montantCentimes: centimes, motif: motif.trim() }))
    } catch (err) {
      setErreur(err.message || 'La réduction n’a pas abouti.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={!!echeance} onClose={onClose} titre="Réduire un prélèvement à venir" taille="md">
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {faite ? (
          <>
            <div className="banner banner-ok">
              <b>Prélèvement réduit</b> — {eurosCentimes(faite.montantInitialCentimes)} →{' '}
              {eurosCentimes(faite.montantCentimes)}.
            </div>
            {/* Le serveur ne rend cette date que sur la réponse au geste : elle ne se relit pas. */}
            {faite.prelevementPasAvant && (
              <div className="banner banner-warn">
                <b>Le prélèvement est repoussé.</b> Le client doit être prévenu du nouveau montant, et
                le délai légal repart de zéro : il ne pourra pas partir avant le{' '}
                {jour(faite.prelevementPasAvant)}.
              </div>
            )}
            <div className="modal-actions">
              <button className="btn primary" type="button" onClick={onFait}>Fermer</button>
            </div>
          </>
        ) : (
          <>
            <p className="hint">
              Échéance du {jour(echeance?.dateProgrammee)}, {eurosCentimes(echeance?.montantCentimes)}.
            </p>
            <div className="field">
              <label htmlFor="red-montant">Montant retiré (€) *</label>
              <input id="red-montant" className="input" type="number" step="0.01" min="0"
                value={montant} onChange={(ev) => setMontant(ev.target.value)} />
              <span className="hint">
                {valide
                  ? `Le client sera prélevé de ${eurosCentimes(echeance.montantCentimes - centimes)} au lieu de ${eurosCentimes(echeance.montantCentimes)}.`
                  : 'Ce qu’on retire, pas le montant d’arrivée. Pour ne rien prélever, annulez l’échéance.'}
              </span>
            </div>
            <div className="field">
              <label htmlFor="red-motif">Motif *</label>
              <input id="red-motif" className="input" value={motif}
                onChange={(ev) => setMotif(ev.target.value)} placeholder="Parrainage, geste commercial…" />
              <span className="hint">
                La seule question posée six mois plus tard, devant un relevé qui ne correspond pas au
                contrat, sera « pourquoi ».
              </span>
            </div>
            <div className="modal-actions">
              <button className="btn" type="button" onClick={onClose}>Annuler</button>
              <button className="btn primary" type="submit" disabled={!valide || envoi}>
                {envoi ? 'Envoi…' : 'Réduire'}
              </button>
            </div>
          </>
        )}
      </form>
    </Modal>
  )
}

// LES DEUX GESTES, ET LEUR CONSÉQUENCE ÉCRITE DANS LA FENÊTRE.
//
// Le patron vient de `ImpayesRecouvrement` : une confirmation dit CE QUI VA SE PASSER, jamais
// « êtes-vous sûr ». Résilier révoque le mandat quand aucun autre abonnement ne s'en sert ; c'est
// exactement le genre de conséquence qu'on découvre autrement au relevé bancaire.
function GesteModal({ geste, abonnement, onClose, onFait }) {
  const [debut, setDebut] = useState('')
  const [fin, setFin] = useState('')
  const [motif, setMotif] = useState('')
  const [motifLegitime, setMotifLegitime] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  // ⚠ CE QUE LE SERVEUR A RÉPONDU, PAS CE QU'ON CROIT QU'IL A FAIT. Une résiliation demandée
  //   pendant l'engagement revient en 201 avec le statut « refusee » : la fenêtre se fermait
  //   dessus sans un mot, et l'exploitant croyait avoir résilié.
  const [resultat, setResultat] = useState(null)

  // En engagement, une demande simple est refusée d'office. C'est la seule situation où la case
  // du motif légitime a un sens — hors engagement la demande passe directement en préavis.
  const enEngagement = !!abonnement?.dateFinEngagement
    && new Date(abonnement.dateFinEngagement) > new Date()

  useEffect(() => {
    setDebut('')
    setFin('')
    setMotif('')
    setMotifLegitime(false)
    setErreur(null)
    setResultat(null)
  }, [geste])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      if (geste === 'pause') {
        await api.pauserAbonnement(abonnement.id, { dateDebut: debut, dateFin: fin, motif: motif.trim() || undefined })
        onFait()
        return
      }

      const resiliation = await api.resilierAbonnement(abonnement.id, {
        motif: motif.trim(),
        motifLegitime: motifLegitime || undefined,
      })

      // ⚠ ON NE FERME PLUS SUR UN REFUS. Les trois issues sont réelles et se distinguent par le
      //   statut rendu ; les confondre était le défaut. Le geste reste enregistré dans tous les
      //   cas — c'est ce qu'il DEVIENT qui change.
      setResultat(resiliation?.statut || 'inconnu')
    } catch (err) {
      setErreur(err.message || 'Le geste n’a pas abouti.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal
      open={!!geste}
      onClose={onClose}
      titre={geste === 'pause' ? 'Mettre l’abonnement en pause' : 'Résilier l’abonnement'}
      taille="md"
    >
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {geste === 'pause' ? (
          <>
            <p className="hint">
              La pause décale le terme de l’engagement d’autant de jours : l’adhérent ne perd pas
              ce qu’il a payé, il le décale.
            </p>
            <div className="field">
              <label htmlFor="pa-debut">Début de la pause</label>
              <input id="pa-debut" className="input" type="date" value={debut} onChange={(e) => setDebut(e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor="pa-fin">Fin de la pause</label>
              <input id="pa-fin" className="input" type="date" value={fin} onChange={(e) => setFin(e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor="pa-motif">Motif</label>
              <input id="pa-motif" className="input" value={motif} onChange={(e) => setMotif(e.target.value)} placeholder="Facultatif" />
            </div>
          </>
        ) : (
          <>
            <p className="hint">
              La résiliation prend effet après le préavis de {abonnement?.preavisResiliationJours ?? 30} jours.
              À sa date d’effet, l’accès est coupé et le mandat SEPA est révoqué — sauf si un autre
              abonnement actif s’en sert encore.
            </p>
            <div className="field">
              <label htmlFor="re-motif">Motif</label>
              <input
                id="re-motif"
                className="input"
                value={motif}
                onChange={(e) => setMotif(e.target.value)}
                required
              />
              <p className="hint">
                Exigé par le serveur : une résiliation sans motif est refusée. La seule question
                posée six mois plus tard sera « pourquoi ».
              </p>
            </div>

            {enEngagement && (
              <div className="field">
                <label htmlFor="re-legitime">
                  <input
                    id="re-legitime"
                    type="checkbox"
                    checked={motifLegitime}
                    onChange={(e) => setMotifLegitime(e.target.checked)}
                  />{' '}
                  Motif légitime (déménagement, perte d’emploi, raison médicale…)
                </label>
                {/* ⚠ SANS CETTE CASE, LA DEMANDE EST UN CUL-DE-SAC. Pendant l'engagement, le
                    serveur refuse toute demande ; seul un motif légitime déclaré ouvre la voie de
                    la validation manuelle par un responsable. La fenêtre ne l'offrait pas, donc
                    elle ne pouvait produire qu'un refus définitif. */}
                <p className="hint">
                  L’engagement court jusqu’au {jour(abonnement?.dateFinEngagement)}. Sans motif
                  légitime, la demande sera refusée. Avec, elle attendra la validation d’un
                  responsable avant que le préavis commence.
                </p>
              </div>
            )}
          </>
        )}

        {/* ⚠ CE QUI S'EST RÉELLEMENT PASSÉ, AVANT DE POUVOIR FERMER. La fenêtre appelait
            `onFait()` sur un 201 et se fermait : un refus était indiscernable d'une réussite. */}
        {resultat && (
          <div className={`banner ${resultat === 'en_preavis' ? 'banner-ok' : 'banner-warn'}`}>
            {resultat === 'en_preavis' && (
              <>
                <b>Résiliation acceptée.</b> Le préavis court ; à sa date d’effet, l’accès sera
                coupé et les échéances restantes annulées.
              </>
            )}
            {resultat === 'refusee' && motifLegitime && (
              <>
                <b>Demande enregistrée, en attente de validation.</b> L’abonnement est encore en
                engagement : un responsable doit valider le motif légitime pour que le préavis
                commence. Tant qu’il ne l’a pas fait, l’abonnement reste actif et prélevé.
              </>
            )}
            {resultat === 'refusee' && !motifLegitime && (
              <>
                <b>Demande refusée.</b> L’abonnement est en engagement jusqu’au{' '}
                {jour(abonnement?.dateFinEngagement)}. La demande est conservée, mais elle ne
                produira aucun effet : l’abonnement reste actif et prélevé. Un motif légitime
                permet d’y déroger, après validation d’un responsable.
              </>
            )}
            {resultat !== 'en_preavis' && resultat !== 'refusee' && (
              <>
                <b>Demande enregistrée</b>, statut « {resultat} ». Vérifiez la fiche : ce statut
                n’est pas un de ceux que cet écran sait interpréter.
              </>
            )}
          </div>
        )}

        <div className="modal-actions">
          {resultat ? (
            <button className="btn primary" type="button" onClick={onFait}>Fermer</button>
          ) : (
            <>
              <button className="btn" type="button" onClick={onClose}>Annuler</button>
              <button className="btn primary" type="submit" disabled={envoi}>
                {envoi ? 'Envoi…' : geste === 'pause' ? 'Mettre en pause' : 'Résilier'}
              </button>
            </>
          )}
        </div>
      </form>
    </Modal>
  )
}


// Les quatre raisons pour lesquelles un accès se ferme. Le mot brut du serveur ne dit rien à un
// agent d'accueil ; ces phrases disent ce qu'il faut FAIRE, pas ce qui s'est passé.
const MOTIF_ACCES = {
  impaye: ['un impayé', 'Le prélèvement a été rejeté. L’accès rouvre au règlement.'],
  pause: ['une pause', 'L’adhérent a demandé la suspension. L’accès rouvre à la reprise.'],
  resiliation: ['la résiliation', 'Le contrat est résilié. L’accès ne rouvrira pas.'],
  terme: ['le terme du contrat', 'L’engagement est arrivé à échéance sans reconduction.'],
}

/**
 * L'ACCÈS D'UN ADHÉRENT — la question qu'on pose à la porte.
 *
 * ⚠ CE N'EST PAS L'ONGLET DE LA MAQUETTE, ET C'EST DÉLIBÉRÉ. Elle montrait les droits de la
 * FORMULE — « Salle + cours collectifs ». Ce champ est stocké, exposé par l'API, et lu par personne :
 * les cinq appels à `getDroitAcces()` portent sur `StatutAccesFitness` ou `BadgeStaff`, jamais sur
 * une `Formule`. L'afficher décrirait un réglage qui ne décide de rien.
 *
 * Ce que cet onglet montre est ce qui gouverne vraiment : l'accès est-il ouvert, et sinon pourquoi.
 */
function OngletAcces({ acces }) {
  if (acces === null) return <div className="empty">Lecture du statut d’accès…</div>

  if (acces === undefined) {
    return (
      <div className="banner banner-warn">
        Le statut d’accès n’a pas pu être lu. Cet écran ne sait donc pas si cet adhérent peut
        entrer — ce n’est pas la même chose que « il peut ».
      </div>
    )
  }

  if (acces === false) {
    return (
      <div className="empty">
        Aucun statut d’accès n’est rattaché à cet abonnement. Le contrôle d’accès ne le connaît
        donc pas : il ne le laissera pas entrer, et aucun motif ne l’expliquera à la porte.
      </div>
    )
  }

  const [motif, quoiFaire] = MOTIF_ACCES[acces.motifInactivite] || [acces.motifInactivite, null]

  // ⚠ `actif` NE DIT PAS QUE LA PORTE S'OUVRE. Il porte l'état métier de l'abonnement — à jour, non
  // suspendu. Le lien vers le support physique est `droitAcces`, nul jusqu'à l'appairage. Cet
  // onglet rendait « ouvert » sur le seul `actif` : mesuré en préproduction, un seul des cinq
  // statuts porte un `droitAcces`, et les autres s'affichaient donc « ouvert » alors qu'aucun badge
  // ne leur est rattaché. Un exploitant qui lit « ouvert » ne cherche pas pourquoi l'adhérent reste
  // dehors.
  const rattache = Boolean(acces.droitAcces)

  return (
    <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
      <div className="fiche-stats">
        <div className="stat-tile">
          <div className="st-val">
            {!rattache
              ? <span className="badge warn">non rattaché</span>
              : acces.actif
                ? <span className="badge good">ouvert</span>
                : <span className="badge crit">fermé</span>}
          </div>
          <div className="st-lbl">Accès</div>
        </div>
        <div className="stat-tile">
          <div className="st-val">
            {acces.dateDernierePropagation ? jour(acces.dateDernierePropagation) : '—'}
          </div>
          <div className="st-lbl">Dernière mise à jour du badge</div>
        </div>
      </div>

      {!rattache && (
        <div className="banner banner-warn">
          <b>Aucun support physique n’est rattaché à cet abonnement.</b> Le contrôle d’accès ne
          connaît donc pas cet adhérent&nbsp;: quel que soit l’état du contrat, la porte ne
          s’ouvrira pas. Le rattachement se fait à l’appairage du badge ou du bracelet.
          {acces.actif && (
            <> L’abonnement, lui, est en règle&nbsp;— ce n’est pas un impayé ni une suspension.</>
          )}
        </div>
      )}

      {rattache && !acces.actif && acces.motifInactivite && (
        <div className="banner banner-warn">
          <b>Fermé pour {motif}.</b>{quoiFaire ? ` ${quoiFaire}` : null}
        </div>
      )}

      {/* ⚠ LE DROIT LUI-MÊME N'EST PAS RENDU. `DroitAcces` n'a aucun champ dans le groupe
          `statut_acces:read` : le serveur envoie un identifiant nu. La fenêtre horaire et le crédit
          restant existent en base et ne sont pas lisibles d'ici — on le dit, plutôt que d'afficher
          un vide qui se lirait « aucune fenêtre ». */}
      <div className="sub">
        Le détail du droit — fenêtre horaire, crédit restant — existe en base mais n’est pas rendu
        par l’API sur cette lecture. Cet écran dit si l’accès est ouvert, pas ce qu’il permet
        exactement.
      </div>
    </div>
  )
}
