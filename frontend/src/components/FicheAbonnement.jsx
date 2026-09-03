import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import Modal from './Modal.jsx'
import Tabs from './Tabs.jsx'
import { jourLocal } from './Liste.jsx'

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

function euros(centimes) {
  if (centimes == null) return '—'
  return `${(centimes / 100).toFixed(2).replace('.', ',')} €`
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

  const a = abonnement

  useEffect(() => {
    if (!a?.id) return undefined
    let annule = false
    setEcheances(null)
    // ⚠ ON NE FILTRE PAS AU SERVEUR, ET CE N'EST PAS UN OUBLI.
    //
    // `EcheanceSepa` ne déclare AUCUN `ApiFilter`. Passer `?abonnement=<id>` serait accepté et
    // IGNORÉ en silence : la réponse porterait les échéances de tous les abonnements, et cette
    // fiche afficherait l'échéancier du voisin sans qu'aucune erreur ne le dise. Un filtre non
    // déclaré est le pire des deux mondes — il a l'air de marcher.
    //
    // On lit donc la collection et on trie ici, sur l'IRI de la relation. 61 échéances en base
    // aujourd'hui, plafond de lecture à 200 : au-delà, la fiche n'en verrait qu'une partie, et
    // c'est ce que dit l'avertissement plus bas plutôt que de laisser croire à un échéancier
    // complet. Le vrai remède est un filtre déclaré côté serveur — hors de cet écran.
    api
      .echeancesSepaSport({ 'order[dateProgrammee]': 'asc' })
      // ⚠ `null` reste `null` sur un refus : la liste vide dirait « aucune échéance » là où on n'a
      // pas pu lire. Un compte sans droit comptable reçoit un 403 sur ce référentiel.
      .then((r) => {
        if (annule) return
        const tout = membres(r)
        const miennes = tout.filter((e) => {
          const ref = e.abonnement
          const id = typeof ref === 'string' ? ref.split('/').pop() : ref?.id
          return id === a.id
        })
        setEcheances(miennes)
        setTronque(tout.length >= 200)
      })
      .catch(() => { if (!annule) setEcheances(undefined) })
    return () => { annule = true }
  }, [a?.id])

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
            <div className="st-val num">{euros(a.montantCentimes)}</div>
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
            aide="L’API ne rend pas encore cette relation : la formule est liée à l’abonnement en base, mais elle n’est pas exposée en lecture."
          >
            <span className="sub">non rendue par l’API</span>
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
              La lecture des échéances s’arrête à 200 lignes, tous abonnements confondus, et le
              serveur ne sait pas filtrer par abonnement. Cet échéancier peut donc être incomplet.
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
                      <td className="num">{euros(e.montantCentimes)}</td>
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
