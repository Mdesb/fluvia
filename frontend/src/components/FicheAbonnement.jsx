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
  const [geste, setGeste] = useState(null)
  const [tronque, setTronque] = useState(false)
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
          onglets={[['contrat', 'Contrat'], ['prelevements', 'Prélèvements']]}
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
                  </tr>
                </thead>
                <tbody>
                  {echeances.map((e) => (
                    <tr key={e.id}>
                      <td className="num">{jour(e.dateProgrammee)}</td>
                      <td className="num">{eurosCentimes(e.montantCentimes)}</td>
                      <td><span className="badge mut">{e.statut || '—'}</span></td>
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
    </div>
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
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    setDebut('')
    setFin('')
    setMotif('')
    setErreur(null)
  }, [geste])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      if (geste === 'pause') {
        await api.pauserAbonnement(abonnement.id, { dateDebut: debut, dateFin: fin, motif: motif.trim() || undefined })
      } else {
        await api.resilierAbonnement(abonnement.id, { motif: motif.trim() })
      }
      onFait()
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
          </>
        )}

        <div className="modal-actions">
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={envoi}>
            {envoi ? 'Envoi…' : geste === 'pause' ? 'Mettre en pause' : 'Résilier'}
          </button>
        </div>
      </form>
    </Modal>
  )
}
