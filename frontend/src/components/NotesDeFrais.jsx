import { Fragment, useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit, aUnDesDroits } from '../api/droits.js'
import { euros } from '../api/produit.js'
import { dateFr, jourLocal } from './Liste.jsx'

/**
 * NOTES DE FRAIS — module entier sans écran jusqu'ici.
 *
 * Six opérations serveur, aucune appelée : soumettre, rouvrir, finaliser une escalade, passer en
 * comptabilité, rembourser, extraire. Un salarié qui avance des frais n'avait aucun chemin, et un
 * remboursement qu'on ne peut pas tracer se règle de travers puis se discute après coup.
 *
 * ── DEUX FAMILLES DE CHEMINS, ET LES CONFONDRE REND 404 ─────────────────────────────────────────
 *
 * La ressource vit sous `/api/expense_reports` ; les GESTES vivent sous
 * `/api/finance/expense-reports/{id}/…`. C'est délibéré côté serveur.
 *
 * ── LES NATURES DE FRAIS NE SONT PAS UN ENUM ────────────────────────────────────────────────────
 *
 * `expenseNatureCode` est une liste OUVERTE et paramétrable, alimentée par les imputations de
 * dépense — que cet écran lit déjà pour les factures fournisseur. On propose donc les natures
 * réellement mappées : une nature absente du référentiel existerait quand même côté serveur, mais
 * sans imputation la note ne pourrait pas passer en comptabilité.
 */
export default function NotesDeFrais({ etabActif, droits = [] }) {
  // La note dont on a deplie les lignes. `null` = aucune, et c'est l'etat normal : une liste de
  // notes se lit d'abord en survol, le detail se demande.
  const [depliee, setDepliee] = useState(null)
  // Le référentiel nécessaire à la CRÉATION, distinct de la liste : sans salarié déclaré,
  // personne ne peut avoir de note de frais.
  const [employes, setEmployes] = useState(null)
  const [profils, setProfils] = useState([])
  // ⚠ `null` = PAS LU · `[]` = LU ET VIDE. « Aucune note de frais » est une affirmation sur ce
  // qu'un salarié attend d'être remboursé : elle ne se dit pas sur une lecture échouée.
  const [notes, setNotes] = useState(null)
  const [natures, setNatures] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [busy, setBusy] = useState(false)
  const [creation, setCreation] = useState(false)
  const [lignePour, setLignePour] = useState(null)
  const [remboursePour, setRemboursePour] = useState(null)

  const peutSoumettre = aLeDroit(droits, 'finance.expense_report_submit')
  const peutComptabiliser = aLeDroit(droits, 'finance.expense_report_post_to_ledger')
  const peutLire = aUnDesDroits(droits, [
    'finance.read', 'finance.expense_report_read_own',
    'finance.expense_report_post_to_ledger', 'finance.manage',
  ])

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const r = await api.notesDeFrais()
      setNotes(membres(r))
    } catch (e) {
      setErreur(e.message || 'Les notes de frais n’ont pas pu être lues.')
      setNotes(null)
    } finally {
      setChargement(false)
    }
    // Le référentiel des natures est un CONFORT : son échec ne doit pas priver de la liste. Mais
    // il est retenu — sans natures, la saisie d'une ligne est impossible, et l'écran le dira.
    api.mappingsDepense()
      .then((m) => setNatures(membres(m).filter((x) => x.active !== false)))
      .catch(() => setNatures([]))
    // ⚠ `null` = pas lu. Distinct de « aucun salarié déclaré », qui est une affirmation et qui
    // se dit dans la modale.
    api.employes()
      .then((r) => setEmployes(membres(r)))
      .catch(() => setEmployes(null))
    api.profilsExploitant()
      .then((r) => setProfils(membres(r)))
      .catch(() => setProfils([]))
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

  async function agir(fn, message) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      await fn()
      setSucces(message)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  if (!peutLire) {
    return (
      <div className="empty">
        Vous n’avez pas le droit de lire les notes de frais sur cet établissement.
      </div>
    )
  }

  if (chargement && notes === null && !erreur) {
    return <div className="center"><div className="spinner" /></div>
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h">
          <h3>Notes de frais</h3>
          <span className="sub">
            {notes === null
              ? 'état inconnu — la lecture n’a pas abouti'
              : `${notes.length} note${notes.length > 1 ? 's' : ''}`}
          </span>
          {peutSoumettre && (
            <div className="r">
              <button className="btn sm" type="button" onClick={() => setCreation(true)}>
                ＋ Nouvelle note de frais
              </button>
            </div>
          )}
        </div>
        <div className="card-b">
          {notes === null ? (
            <div className="banner banner-error">
              Les notes de frais n’ont pas pu être lues. <b>N’en concluez pas que personne
              n’attend un remboursement</b>&nbsp;: cette liste n’a pas été obtenue.
            </div>
          ) : notes.length === 0 ? (
            <div className="empty">
              Aucune note de frais. Une note regroupe les dépenses qu’un salarié a avancées&nbsp;:
              elle se saisit ligne par ligne, se soumet, puis se rembourse une fois approuvée.
            </div>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Salarié</th>
                    <th>État</th>
                    <th className="num">Total</th>
                    <th>Soumise</th>
                    <th>Remboursée</th>
                    <th className="num">Lignes</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {notes.map((n) => {
                    const st = ETATS[n.status] || { libelle: n.status, ton: 'mut' }
                    return (
                      <Fragment key={n.id}>
                      <tr>
                        <td>{nomEmploye(n.employee)}</td>
                        <td>
                          <span className={`badge ${st.ton}`}>{st.libelle}</span>
                          {n.rejectionReason ? (
                            <div className="sub">{n.rejectionReason}</div>
                          ) : null}
                        </td>
                        <td className="num">{euros(n.totalAmount)}</td>
                        <td>{n.submittedAt ? dateFr(n.submittedAt) : '—'}</td>
                        <td>{n.reimbursedAt ? dateFr(n.reimbursedAt) : '—'}</td>
                        <td className="num">
                          {/* ⚠ LE COMPTE EST UNE INFORMATION EN SOI : une note à zéro ligne se
                              soumet sans que rien ne l'empêche, et personne ne le voyait. */}
                          {(n.lines || []).length === 0 ? (
                            <span className="badge warn">aucune</span>
                          ) : (
                            <button
                              type="button"
                              className="lnk"
                              onClick={() => setDepliee(depliee === n.id ? null : n.id)}
                            >
                              {(n.lines || []).length}
                            </button>
                          )}
                        </td>
                        <td className="num">
                          {/* LES GESTES SUIVENT L'ÉTAT, ET UN GESTE QUI N'A PAS DE SENS EST ABSENT,
                              jamais grisé : on ne propose pas « soumettre » sur une note déjà
                              soumise pour la refuser ensuite. */}
                          {peutSoumettre && n.status === 'draft' && (
                            <>
                              <button
                                className="btn sm"
                                type="button"
                                disabled={busy}
                                onClick={() => setLignePour(n)}
                              >
                                Ajouter une dépense
                              </button>
                              <button
                                className="btn sm"
                                type="button"
                                disabled={busy}
                                title="Envoie la note à l'approbation. Les lignes ne se modifient plus ensuite."
                                onClick={() => agir(
                                  () => api.soumettreNoteDeFrais(n.id),
                                  'Note de frais soumise.',
                                )}
                              >
                                Soumettre
                              </button>
                            </>
                          )}
                          {peutSoumettre && n.status === 'rejected' && (
                            <button
                              className="btn sm"
                              type="button"
                              disabled={busy}
                              title="Repasse la note en brouillon pour la corriger."
                              onClick={() => agir(
                                () => api.rouvrirNoteDeFrais(n.id),
                                'Note rouverte en brouillon.',
                              )}
                            >
                              Rouvrir
                            </button>
                          )}
                          {n.escalationRequest && (
                            <button
                              className="btn sm"
                              type="button"
                              disabled={busy}
                              title="Applique la décision de l'escalade à la note."
                              onClick={() => agir(
                                () => api.finaliserEscaladeNoteDeFrais(n.id),
                                'Escalade finalisée.',
                              )}
                            >
                              Finaliser l’escalade
                            </button>
                          )}
                          {peutComptabiliser && n.status === 'approved' && !n.ledgerEntry && (
                            <button
                              className="btn sm"
                              type="button"
                              disabled={busy}
                              title="Génère l'écriture comptable de la note."
                              onClick={() => agir(
                                () => api.passerEnComptaNoteDeFrais(n.id),
                                'Note passée en comptabilité.',
                              )}
                            >
                              Comptabiliser
                            </button>
                          )}
                          {peutComptabiliser && n.status === 'approved' && (
                            <button
                              className="btn sm"
                              type="button"
                              disabled={busy}
                              onClick={() => setRemboursePour(n)}
                            >
                              Rembourser
                            </button>
                          )}
                        </td>
                      </tr>

                      {depliee === n.id && (
                        <tr>
                          <td colSpan={7} style={{ background: 'var(--panel-2)' }}>
                            <LignesNote
                              note={n}
                              peutModifier={peutSoumettre && n.status === 'draft'}
                              onSupprime={recharger}
                              onErreur={setErreur}
                            />
                          </td>
                        </tr>
                      )}
                      </Fragment>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}

          {/* ⚠ CE BANDEAU DISAIT QUE L'OCR N'ÉTAIT PAS BRANCHÉ. Il l'est, dans `LigneModal`,
              avec une règle soignée — il ne remplit que les champs vides. La phrase décrivait un
              défaut corrigé depuis, et rien ne reliait les deux : elle est remplacée par ce qui
              reste vrai. */}
          <div className="hint">
            Une ligne ne se modifie que tant que la note est en brouillon&nbsp;: dès qu’elle est
            soumise, le serveur la scelle. Dépliez le compte de lignes pour les voir et en retirer
            une.
          </div>
        </div>
      </section>

      <NoteModal
        open={creation}
        etabActif={etabActif}
        employes={employes}
        profils={profils}
        onClose={() => setCreation(false)}
        onFait={() => { setCreation(false); setSucces('Note de frais créée.'); recharger() }}
      />

      <LigneModal
        note={lignePour}
        natures={natures}
        onClose={() => setLignePour(null)}
        onFait={() => { setLignePour(null); setSucces('Dépense ajoutée.'); recharger() }}
      />

      <RemboursementModal
        note={remboursePour}
        onClose={() => setRemboursePour(null)}
        onFait={() => { setRemboursePour(null); setSucces('Remboursement enregistré.'); recharger() }}
      />
    </>
  )
}

// Les cinq états du serveur, en toutes lettres. « approved » et « reimbursed » ne se devinent pas
// depuis un code, et la différence décide de ce qu'il reste à faire.
const ETATS = {
  draft: { libelle: 'Brouillon', ton: 'mut' },
  submitted: { libelle: 'Soumise', ton: 'warn' },
  approved: { libelle: 'Approuvée', ton: 'good' },
  rejected: { libelle: 'Refusée', ton: 'crit' },
  reimbursed: { libelle: 'Remboursée', ton: 'good' },
}

// L'employé revient en IRI nue quand il n'expose rien dans le groupe courant : on affiche alors
// une absence honnête plutôt qu'un « [object Object] ».
function nomEmploye(e) {
  if (!e) return '—'
  if (typeof e === 'string') return 'employé non résolu'
  const nom = [e.prenom, e.nom].filter(Boolean).join(' ')
  return nom || e.matricule || '—'
}

// ⚠ TROIS RELATIONS REQUISES, ET J'AVAIS ÉCRIT LE CONTRAIRE.
//
// La première version de cette modale envoyait `{}` avec ce commentaire : « ni l'établissement ni
// l'employé ne partent dans le corps, le serveur les estampille ». C'était une INVENTION — je
// l'avais déduite de l'expression de sécurité `EMPLOYE_SOI` au lieu de lire le groupe d'écriture.
//
// Lu dans l'entité : `establishment`, `businessProfile` et `employee` portent tous les trois
// `expense_report:write` ET `JoinColumn(nullable: false)`. Ils sont donc REQUIS et c'est au client
// de les envoyer. Le corps vide aurait échoué au premier clic.
//
// ⚠ ET `ExpenseReport` N'EXPOSE AUCUN `Delete` : une note créée par erreur reste. La modale le dit.
function NoteModal({ open, etabActif, employes, profils, onClose, onFait }) {
  const [employe, setEmploye] = useState('')
  const [profil, setProfil] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return
    setEmploye((employes || [])[0]?.id || '')
    setProfil(profils[0]?.id || '')
    setErreur(null)
  }, [open, employes, profils])

  const sansSalarie = employes !== null && employes.length === 0
  const pret = Boolean(employe) && Boolean(profil) && Boolean(etabActif)

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.creerNoteDeFrais({
        establishment: `/api/etablissements/${etabActif}`,
        businessProfile: `/api/profil_exploitants/${profil}`,
        employee: `/api/employes/${employe}`,
      })
      onFait()
    } catch (err) {
      setErreur(err.message || 'La note n’a pas pu être créée.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Nouvelle note de frais">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {employes === null && (
          <div className="banner banner-error">
            La liste des salariés n’a pas pu être lue&nbsp;: impossible de rattacher la note à
            quelqu’un. <b>N’en concluez pas qu’il n’y a pas de salarié.</b>
          </div>
        )}

        {sansSalarie && (
          <div className="banner banner-warn">
            <b>Aucun salarié n’est déclaré sur cet établissement.</b> Une note de frais appartient
            à un salarié&nbsp;: déclarez-en un dans <b>Personnel</b> avant de saisir des frais.
          </div>
        )}

        <div className="field">
          <label htmlFor="nf-employe">Salarié *</label>
          <select
            id="nf-employe"
            className="input"
            value={employe}
            onChange={(ev) => setEmploye(ev.target.value)}
          >
            {(employes || []).map((e) => (
              <option key={e.id} value={e.id}>
                {[e.prenom, e.nom].filter(Boolean).join(' ') || e.matricule || e.id}
              </option>
            ))}
          </select>
          <span className="hint">
            Celui qui a avancé l’argent. Le serveur n’autorise ensuite la modification et la
            soumission qu’à ce salarié.
          </span>
        </div>

        {/* ⚠ MÊME LIBELLÉ ET MÊME REPLI QUE L'ÉCRAN VOISIN, PAS UN SECOND VOCABULAIRE.
            `FacturesFournisseur` appelle ce champ « Profil comptable », l'étiquette par
            `referentielComptable || type` et le MASQUE quand il n'y en a qu'un — un choix unique
            n'est pas un choix. Ma première version affichait « Profil d'exploitant » et un UUID
            brut, parce que ce profil-ci n'a ni libellé ni nom : il porte `type: regie_directe`,
            `referentielComptable: M57` et un SIREN. Deux écrans qui nomment différemment la même
            chose obligent l'exploitant à faire la traduction. */}
        {profils.length > 1 && (
          <div className="field">
            <label htmlFor="nf-profil">Profil comptable *</label>
            <select
              id="nf-profil"
              className="input"
              value={profil}
              onChange={(ev) => setProfil(ev.target.value)}
            >
              {profils.map((p) => (
                <option key={p.id} value={p.id}>{p.referentielComptable || p.type || p.id}</option>
              ))}
            </select>
            <span className="hint">
              C’est lui qui porte le plan de comptes&nbsp;: il décide sur quel compte les dépenses
              s’imputeront.
            </span>
          </div>
        )}

        <p className="hint">
          La note est créée <b>vide et en brouillon</b>. Vous y ajouterez les dépenses une par une,
          puis vous la soumettrez à l’approbation. ⚠ <b>Une note ne se supprime pas</b> — le serveur
          n’offre pas cette opération. Une note créée par erreur se laisse en brouillon.
        </p>

        <div className="r">
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn primary" disabled={envoi || !pret}>
            {envoi ? 'Création…' : 'Créer la note'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function LigneModal({ note, natures, onClose, onFait }) {
  const [nature, setNature] = useState('')
  const [date, setDate] = useState('')
  const [montant, setMontant] = useState('')
  const [description, setDescription] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  // `null` = rien lu ; un objet = le serveur a repondu (y compris `status: failed`).
  const [lecture, setLecture] = useState(null)
  const [lectureEnCours, setLectureEnCours] = useState(false)

  /**
   * ⚠ `readAsDataURL` REND « data:<mime>;base64,<contenu> » — LE SERVEUR ATTEND LE CONTENU SEUL.
   * Envoyer le préfixe rend 422, et c'est un défaut qui ne se voit qu'à l'exécution. Même découpe
   * que l'extraction de facture fournisseur, pour la même raison.
   */
  async function lireJustificatif(fichier) {
    setLectureEnCours(true)
    setLecture(null)
    setErreur(null)
    try {
      const base64 = await new Promise((resoudre, rejeter) => {
        const lecteur = new FileReader()
        lecteur.onerror = () => rejeter(new Error('Le fichier n’a pas pu être lu.'))
        lecteur.onload = () => resoudre(String(lecteur.result).split(',')[1] ?? '')
        lecteur.readAsDataURL(fichier)
      })
      const r = await api.extraireJustificatifFrais(base64, fichier.type || 'application/pdf')
      setLecture(r)
      // ⚠ ON NE REMPLIT QUE LE VIDE. Un champ déjà saisi l'a été exprès : l'écraser par une lecture
      // automatique ferait perdre une correction sans que personne ne le voie.
      if (!date && r.documentDate) setDate(r.documentDate)
      if (!montant && r.amountInclTax) setMontant(String(r.amountInclTax))
      if (!description && r.supplierName) setDescription(r.supplierName)
    } catch (err) {
      setErreur(err.message || 'Le justificatif n’a pas pu être lu.')
    } finally {
      setLectureEnCours(false)
    }
  }

  useEffect(() => {
    if (!note) return
    setNature(natures[0]?.expenseNatureCode || '')
    setDate(jourLocal())
    setMontant('')
    setDescription('')
    setErreur(null)
  }, [note, natures])

  const valeur = Number(String(montant).replace(',', '.'))
  const pret = Boolean(nature) && Boolean(date) && Number.isFinite(valeur) && valeur > 0

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.creerLigneFrais({
        expenseReport: `/api/expense_reports/${note.id}`,
        expenseNatureCode: nature,
        expenseDate: date,
        amountInclTax: valeur.toFixed(2),
        ...(description.trim() ? { description: description.trim() } : {}),
      })
      onFait()
    } catch (err) {
      setErreur(err.message || 'La dépense n’a pas pu être ajoutée.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={Boolean(note)} onClose={onClose} titre="Ajouter une dépense">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {natures.length === 0 && (
          <div className="banner banner-warn">
            <b>Aucune nature de dépense n’est paramétrée</b> (ou leur référentiel n’a pas pu être
            lu). Sans nature imputable, la note ne pourra pas passer en comptabilité.
          </div>
        )}

        {/* ⚠ ON PRE-REMPLIT, ON NE SOUMET PAS — même discipline que l'extraction de facture
            fournisseur, et le serveur la confirme (RG-EXP-03 : aucune création automatique de
            ligne). Un champ déjà saisi n'est jamais écrasé : il a été saisi exprès. */}
        <div className="field">
          <label htmlFor="nf-justif">Lire un justificatif</label>
          <input
            id="nf-justif"
            className="input"
            type="file"
            accept="image/*,application/pdf"
            disabled={lectureEnCours || envoi}
            onChange={(e) => {
              const f = e.target.files?.[0]
              e.target.value = ''
              if (f) lireJustificatif(f)
            }}
          />
          <span className="hint">
            Photo du ticket ou PDF. Les valeurs lues remplissent les champs vides ci-dessous&nbsp;;
            relisez-les, elles ne sont pas enregistrées telles quelles.
          </span>
        </div>

        {lectureEnCours && <div className="banner">Lecture du justificatif…</div>}

        {lecture && lecture.status === 'failed' && (
          <div className="banner banner-warn">
            <b>Le justificatif n’a pas pu être lu.</b> La lecture automatique n’est pas disponible
            sur cette installation, ou ce document lui résiste. La saisie à la main ci-dessous
            fonctionne normalement — ce n’est pas une panne de l’écran.
          </div>
        )}

        {lecture && lecture.status !== 'failed' && (
          <div className="banner">
            Justificatif lu{lecture.supplierName ? <> — <b>{lecture.supplierName}</b></> : null}
            {lecture.confidenceScore != null && (
              <> · fiabilité annoncée&nbsp;: {Math.round(Number(lecture.confidenceScore) * 100)}%</>
            )}
            .{' '}
            {/* ⚠ LA NATURE N'EST JAMAIS EXTRAITE, et ce n'est pas un manque de l'OCR : c'est le
                référentiel d'imputation qui décide sur quel compte la dépense tombe. Sans cette
                phrase, on croit que tout a été rempli et on valide une ligne sans imputation. */}
            <b>La nature de la dépense n’est pas lue</b> : choisissez-la ci-dessous.
          </div>
        )}

        <div className="field">
          <label htmlFor="nf-nature">Nature de la dépense *</label>
          <select id="nf-nature" className="input" value={nature} onChange={(e) => setNature(e.target.value)}>
            {natures.map((n) => (
              <option key={n.id} value={n.expenseNatureCode}>{n.expenseNatureCode}</option>
            ))}
          </select>
          <span className="hint">
            La liste vient du référentiel d’imputation : c’est lui qui dit sur quel compte la
            dépense s’impute.
          </span>
        </div>

        <div className="field">
          <label htmlFor="nf-date">Date de la dépense *</label>
          <input id="nf-date" className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </div>

        <div className="field">
          <label htmlFor="nf-montant">Montant TTC *</label>
          <input
            id="nf-montant"
            className="input"
            type="number"
            step="0.01"
            min="0.01"
            value={montant}
            onChange={(e) => setMontant(e.target.value)}
          />
          <span className="hint">
            Le montant réellement payé. La TVA se déduit du taux associé à la nature&nbsp;: on ne
            la saisit pas ici, et l’écran ne la recalcule pas.
          </span>
        </div>

        <div className="field">
          <label htmlFor="nf-desc">Description</label>
          <input
            id="nf-desc"
            className="input"
            value={description}
            maxLength={200}
            placeholder="Déplacement Paris — réunion fournisseur"
            onChange={(e) => setDescription(e.target.value)}
          />
        </div>

        <div className="r">
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn primary" disabled={envoi || !pret}>
            {envoi ? 'Ajout…' : 'Ajouter'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function RemboursementModal({ note, onClose, onFait }) {
  const [date, setDate] = useState('')
  const [montant, setMontant] = useState('')
  const [reference, setReference] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!note) return
    setDate(jourLocal())
    setMontant(String(note.totalAmount || ''))
    setReference('')
    setErreur(null)
  }, [note])

  const valeur = Number(String(montant).replace(',', '.'))
  const pret = Boolean(date) && Number.isFinite(valeur) && valeur > 0

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.rembourserNoteDeFrais(note.id, {
        date,
        amount: valeur.toFixed(2),
        ...(reference.trim() ? { reference: reference.trim() } : {}),
      })
      onFait()
    } catch (err) {
      setErreur(err.message || 'Le remboursement n’a pas pu être enregistré.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={Boolean(note)} onClose={onClose} titre="Enregistrer un remboursement">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <p className="hint" style={{ marginTop: 0 }}>
          ⚠ Cet enregistrement <b>constate</b> un remboursement déjà effectué&nbsp;: il ne
          déclenche aucun virement. La référence sert à le retrouver sur le relevé bancaire.
        </p>

        <div className="field">
          <label htmlFor="nf-rb-date">Date du remboursement *</label>
          <input id="nf-rb-date" className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </div>

        <div className="field">
          <label htmlFor="nf-rb-montant">Montant *</label>
          <input
            id="nf-rb-montant"
            className="input"
            type="number"
            step="0.01"
            min="0.01"
            value={montant}
            onChange={(e) => setMontant(e.target.value)}
          />
          <span className="hint">
            Pré-rempli au total de la note. Un montant différent se justifie&nbsp;: acompte,
            retenue, ou remboursement partiel.
          </span>
        </div>

        <div className="field">
          <label htmlFor="nf-rb-ref">Référence</label>
          <input
            id="nf-rb-ref"
            className="input"
            value={reference}
            maxLength={80}
            placeholder="Numéro de virement, référence bancaire…"
            onChange={(e) => setReference(e.target.value)}
          />
        </div>

        <div className="r">
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn primary" disabled={envoi || !pret}>
            {envoi ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}


/**
 * LES LIGNES D'UNE NOTE — déjà dans la charge utile, jamais affichées jusqu'ici.
 *
 * ⚠ ON NE PROPOSE LE RETRAIT QUE SUR UN BROUILLON. `ExpenseLineProcessor` refuse toute écriture dès
 * que la note quitte `draft` (RG-EXP-01.1) : le bouton est donc ABSENT ailleurs, jamais grisé.
 * Proposer un geste pour le refuser ensuite est exactement ce que cet écran évite déjà pour
 * « soumettre ».
 *
 * ⚠ ET LE RETRAIT NE SE CONFIRME PAS. Une ligne de brouillon se resaisit en trente secondes, et la
 * note n'est encore engagée nulle part — une confirmation ici serait une cérémonie. Le jour où le
 * geste porterait sur une note scellée, il faudrait l'inverse ; c'est précisément pour ça qu'il
 * n'existe pas dans ce cas.
 */
function LignesNote({ note, peutModifier, onSupprime, onErreur }) {
  const [busy, setBusy] = useState(null)
  const lignes = note.lines || []

  async function supprimer(l) {
    setBusy(l.id)
    onErreur(null)
    try {
      await api.supprimerLigneFrais(l.id)
      await onSupprime()
    } catch (e) {
      onErreur(e.message || 'La ligne n’a pas pu être retirée.')
    } finally {
      setBusy(null)
    }
  }

  if (lignes.length === 0) {
    return <div className="sub">Aucune ligne sur cette note.</div>
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="tbl">
        <thead>
          <tr>
            <th>Date</th>
            <th>Nature</th>
            <th className="num">TTC</th>
            <th className="num">HT</th>
            <th className="num">TVA</th>
            <th>Justificatif</th>
            {peutModifier && <th />}
          </tr>
        </thead>
        <tbody>
          {lignes.map((l) => (
            <tr key={l.id}>
              <td>{l.expenseDate ? dateFr(l.expenseDate) : '—'}</td>
              <td>{l.expenseNatureCode || <span className="sub">—</span>}</td>
              <td className="num">{euros(l.amountInclTax)}</td>
              <td className="num">{euros(l.amountExclTax)}</td>
              <td className="num">{euros(l.vatAmount)}</td>
              <td>
                {l.receiptFileName || <span className="sub">aucun</span>}
              </td>
              {peutModifier && (
                <td>
                  <button
                    type="button"
                    className="btn ghost sm"
                    disabled={busy === l.id}
                    onClick={() => supprimer(l)}
                  >
                    {busy === l.id ? 'Retrait…' : 'Retirer'}
                  </button>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
