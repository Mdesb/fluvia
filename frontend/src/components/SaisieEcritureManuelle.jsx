import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { centimes as afficherCentimes } from '../api/produit.js'

// SAISIR UNE ÉCRITURE MANUELLE — la seconde moitié de T28, et la plus contrainte.
//
// ── CE QUE C'EST ────────────────────────────────────────────────────────────────────────────────
//
// Le moteur comptable génère les écritures de vente tout seul. Tout le reste — une régularisation,
// un abonnement constaté d'avance, une correction, une charge qu'aucune vente ne porte — se saisit
// à la main. `POST /compta/journal-entries/manual` existait, testé, appelé par personne : le module
// savait enregistrer ce que la caisse produit, et rien de ce qu'un comptable décide.
//
// ── ⚠ ICI L'ÉQUILIBRE EST IMPOSÉ, ET C'EST L'INVERSE DU LETTRAGE ───────────────────────────────
//
// L'onglet voisin affiche l'écart sans jamais bloquer, parce que `LettrerGroupeProcessor` accepte un
// lettrage partiel. Ici `SaisirEcritureManuelleHandler` refuse en 422 dès que débit ≠ crédit —
// mesuré en le lisant, pas déduit de l'autre écran. Le bouton reste donc désactivé tant que la
// balance n'est pas nulle : le comptable voit l'écart pendant qu'il saisit, au lieu de le découvrir
// au refus du serveur.
//
// ── ⚠ TROIS REFUS QUE LE SERVEUR OPPOSE, ET QUE L'ÉCRAN ANTICIPE ───────────────────────────────
//
// 1. **Un compte inactif** est refusé (422). `actif` est lisible sur `/api/compte_comptables` —
//    vérifié — donc on ne propose que les comptes actifs plutôt que d'offrir un choix qui échoue.
// 2. **Le taux de TVA est obligatoire.** `resoudreTauxTva` lève dès que la référence est nulle : il
//    n'y a pas de ligne sans taux. Pour une OD, c'est le taux « hors champ ».
// 3. **La période doit exister ET être ouverte.** Ce n'est pas dans le handler : c'est
//    `DirectLedgerEntryBuilder` qui refuse via `estOuverte()`. Chercher le mot « Cloturee » ne le
//    trouve pas — le garde-fou est écrit à l'endroit, pas à l'envers. `statut` étant lisible sur
//    `periode:read`, l'écran le dit avant le clic.
//
// ── ⚠ AUCUN IDENTIFIANT N'EST LU EN PROFONDEUR ─────────────────────────────────────────────────
//
// Les relations arrivent tantôt en IRI nue, tantôt en objet, selon les groupes qui les portent.
// `idDe()` accepte les deux et ne descend jamais dans une sous-propriété : c'est ce que surveille le
// garde-fou n°32, et c'est ce qui rend un rapprochement silencieusement vide.
export default function SaisieEcritureManuelle({ etabActif, droits = [] }) {
  const peutSaisir = aLeDroit(droits, 'compta.record_manual_entry')

  const [profils, setProfils] = useState([])
  const [journaux, setJournaux] = useState([])
  const [comptes, setComptes] = useState([])
  const [taux, setTaux] = useState([])
  const [periodes, setPeriodes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreurCharge, setErreurCharge] = useState(null)

  const [profil, setProfil] = useState('')
  const [journal, setJournal] = useState('')
  const [date, setDate] = useState(aujourdHui)
  const [libelle, setLibelle] = useState('')
  const [lignes, setLignes] = useState(deuxLignesVides)

  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const charger = useCallback(() => {
    setChargement(true)
    setErreurCharge(null)
    Promise.allSettled([
      api.profilsExploitant(),
      api.journaux(),
      api.comptesComptables(),
      api.tauxTvas(),
      api.periodesComptables(),
    ]).then(([p, j, c, t, pe]) => {
      // ⚠ SANS PROFIL, SANS JOURNAL OU SANS COMPTE, IL N'Y A PAS D'ÉCRAN. Un formulaire aux listes
      // vides laisserait croire qu'il n'y a rien à saisir, alors que c'est la lecture qui a échoué.
      const manquants = []
      if (p.status === 'rejected') manquants.push('les profils exploitants')
      if (j.status === 'rejected') manquants.push('les journaux')
      if (c.status === 'rejected') manquants.push('le plan comptable')
      if (t.status === 'rejected') manquants.push('les taux de TVA')
      if (manquants.length > 0) {
        setErreurCharge(`Impossible de lire ${manquants.join(', ')} : la saisie est indisponible.`)
        setChargement(false)
        return
      }
      const lesProfils = membres(p.value)
      setProfils(lesProfils)
      setJournaux(membres(j.value))
      setComptes(membres(c.value).filter((x) => x.actif !== false))
      setTaux(membres(t.value).filter((x) => x.actif !== false))
      // ⚠ LES PÉRIODES SONT LE SEUL CHARGEMENT DONT L'ÉCHEC N'EST PAS FATAL : sans elles on ne peut
      // pas prévenir, mais le serveur, lui, refusera quand même. On dégrade l'avertissement, pas la
      // saisie — et on ne prétend pas que la période est bonne.
      setPeriodes(pe.status === 'fulfilled' ? membres(pe.value) : null)
      if (lesProfils.length === 1) setProfil(lesProfils[0]['@id'] || `/api/profil_exploitants/${lesProfils[0].id}`)
      setChargement(false)
    })
  }, [])

  useEffect(() => { charger() }, [etabActif, charger])

  const idProfil = idDe(profil)

  const journauxDuProfil = useMemo(
    () => journaux.filter((j) => idDe(j.profilExploitant) === idProfil),
    [journaux, idProfil],
  )
  const comptesDuProfil = useMemo(
    () => comptes.filter((c) => idDe(c.profilExploitant) === idProfil),
    [comptes, idProfil],
  )
  const tauxDuProfil = useMemo(
    () => taux.filter((t) => idDe(t.profilExploitant) === idProfil),
    [taux, idProfil],
  )

  // ⚠ COMPARAISON DE CHAÎNES `YYYY-MM-DD`, JAMAIS DE `Date`. Un `toISOString()` décalerait la date
  // d'un jour selon le fuseau, et une écriture datée du 1er tomberait dans le mois précédent.
  const periode = useMemo(() => {
    if (periodes === null || !idProfil || !date) return undefined
    return periodes.find(
      (p) =>
        idDe(p.profilExploitant) === idProfil
        && String(p.dateDebut).slice(0, 10) <= date
        && String(p.dateFin).slice(0, 10) >= date,
    ) ?? null
  }, [periodes, idProfil, date])

  const totalDebit = lignes.reduce((s, l) => s + (l.sens === 'debit' ? centimes(l.montant) : 0), 0)
  const totalCredit = lignes.reduce((s, l) => s + (l.sens === 'credit' ? centimes(l.montant) : 0), 0)
  const ecart = totalDebit - totalCredit

  const lignesCompletes = lignes.filter((l) => l.compte && l.tauxTva && centimes(l.montant) > 0)
  const pretASoumettre =
    !!profil && !!journal && !!date
    && lignesCompletes.length === lignes.length && lignes.length >= 2
    && ecart === 0 && totalDebit > 0
    && periode !== null

  function majLigne(i, champ, valeur) {
    setLignes((ls) => ls.map((l, k) => (k === i ? { ...l, [champ]: valeur } : l)))
  }

  async function enregistrer() {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.saisirEcritureManuelle({
        businessProfile: profil,
        journal,
        date,
        label: libelle,
        lines: lignes.map((l) => ({
          account: l.compte,
          vatRate: l.tauxTva,
          ...(l.sens === 'debit' ? { debit: l.montant } : { credit: l.montant }),
          ...(l.libelle.trim() ? { label: l.libelle.trim() } : {}),
        })),
      })
      setSucces('Écriture enregistrée. Elle est scellée comme toute autre écriture du journal.')
      setLibelle('')
      setLignes(deuxLignesVides())
    } catch (e) {
      setErreur(e.message || "L'écriture n'a pas été enregistrée.")
    } finally {
      setBusy(false)
    }
  }

  if (chargement) return <div className="empty">Chargement…</div>
  if (erreurCharge) return <div className="banner banner-error">{erreurCharge}</div>

  return (
    <section className="card">
      <div className="card-h">
        <h3>Saisie d’une écriture</h3>
        <span className="sub">opérations diverses, régularisations, corrections</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}
        {!peutSaisir && (
          <div className="banner">
            Vous pouvez consulter ce formulaire, mais l’enregistrement demande le droit
            « saisie manuelle ».
          </div>
        )}

        <div className="resa-part-form">
          <label className="field">
            <span className="field-lbl">Profil exploitant</span>
            <select
              className="input"
              value={profil}
              onChange={(e) => { setProfil(e.target.value); setJournal('') }}
            >
              <option value="">—</option>
              {profils.map((p) => (
                <option key={p.id} value={p['@id'] || `/api/profil_exploitants/${p.id}`}>
                  {p.libelle || p.raisonSociale || p.id}
                </option>
              ))}
            </select>
          </label>

          <label className="field">
            <span className="field-lbl">Journal</span>
            <select className="input" value={journal} onChange={(e) => setJournal(e.target.value)} disabled={!profil}>
              <option value="">—</option>
              {journauxDuProfil.map((j) => (
                <option key={j.id} value={j['@id'] || `/api/journals/${j.id}`}>
                  {j.code} — {j.libelle}
                </option>
              ))}
            </select>
          </label>

          <label className="field">
            <span className="field-lbl">Date</span>
            <input type="date" className="input" value={date} onChange={(e) => setDate(e.target.value)} />
          </label>
        </div>

        {/* ⚠ LA PÉRIODE SE DIT AVANT LE CLIC. Le serveur refuse une date qu'aucune période ne couvre,
            et refuse aussi une période clôturée — ce second refus vient de `DirectLedgerEntryBuilder`,
            pas du handler. Sans cet avertissement, le comptable saisirait douze lignes pour recevoir
            un 422 sur la date, et n'aurait aucun moyen de savoir qu'il faut ouvrir un exercice. */}
        {profil && date && periodes === null && (
          <p className="hint">
            Les périodes comptables n’ont pas pu être lues : impossible de vérifier ici que cette
            date est ouverte. Le serveur, lui, refusera si elle ne l’est pas.
          </p>
        )}
        {profil && date && periode === null && (
          <div className="banner banner-error">
            Aucune période comptable ne couvre le {dateFr(date)}. Ouvrez d’abord l’exercice ou le
            mois concerné : la saisie manuelle n’en crée jamais une au passage.
          </div>
        )}
        {periode && periode.statut !== 'ouverte' && (
          <div className="banner banner-error">
            La période du {dateFr(periode.dateDebut)} au {dateFr(periode.dateFin)} est clôturée :
            aucune écriture ne peut plus y être ajoutée. Passez par une extourne sur une période
            ouverte.
          </div>
        )}

        <label className="field">
          <span className="field-lbl">Libellé de l’écriture</span>
          <input
            className="input"
            value={libelle}
            onChange={(e) => setLibelle(e.target.value)}
            placeholder="Régularisation cotisations août"
          />
        </label>

        <div className="resa-attente">
          {lignes.map((l, i) => (
            <div key={i} className="resa-part">
              <select
                className="input"
                value={l.compte}
                onChange={(e) => majLigne(i, 'compte', e.target.value)}
                disabled={!profil}
                aria-label={`Compte de la ligne ${i + 1}`}
              >
                <option value="">Compte…</option>
                {comptesDuProfil.map((c) => (
                  <option key={c.id} value={c['@id'] || `/api/compte_comptables/${c.id}`}>
                    {c.numero} — {c.libelle}
                  </option>
                ))}
              </select>

              <select
                className="input"
                value={l.sens}
                onChange={(e) => majLigne(i, 'sens', e.target.value)}
                aria-label={`Sens de la ligne ${i + 1}`}
              >
                <option value="debit">Débit</option>
                <option value="credit">Crédit</option>
              </select>

              <input
                className="input num"
                type="number"
                step="0.01"
                min="0"
                value={l.montant}
                onChange={(e) => majLigne(i, 'montant', e.target.value)}
                placeholder="0,00"
                aria-label={`Montant de la ligne ${i + 1}`}
              />

              {/* ⚠ OBLIGATOIRE : `resoudreTauxTva` lève dès que la référence est nulle. Une ligne
                  sans taux fait échouer toute l'écriture, pas seulement la ligne. */}
              <select
                className="input"
                value={l.tauxTva}
                onChange={(e) => majLigne(i, 'tauxTva', e.target.value)}
                disabled={!profil}
                aria-label={`Taux de TVA de la ligne ${i + 1}`}
              >
                <option value="">TVA…</option>
                {tauxDuProfil.map((t) => (
                  <option key={t.id} value={t['@id'] || `/api/taux_tvas/${t.id}`}>
                    {t.libelle} ({t.taux} %)
                  </option>
                ))}
              </select>

              <input
                className="input"
                value={l.libelle}
                onChange={(e) => majLigne(i, 'libelle', e.target.value)}
                placeholder="Libellé de la ligne"
                aria-label={`Libellé de la ligne ${i + 1}`}
              />

              {lignes.length > 2 && (
                <button
                  type="button"
                  className="btn ghost sm"
                  onClick={() => setLignes((ls) => ls.filter((_, k) => k !== i))}
                >
                  Retirer la ligne {i + 1}
                </button>
              )}
            </div>
          ))}
        </div>

        <button
          type="button"
          className="btn"
          onClick={() => setLignes((ls) => [...ls, ligneVide()])}
        >
          Ajouter une ligne
        </button>

        {/* ⚠ L'ÉCART EST BLOQUANT ICI, CONTRAIREMENT AU LETTRAGE. Le serveur refuse en 422 dès que
            débit ≠ crédit, et un formulaire qui laisserait cliquer transformerait une erreur de
            saisie visible en un refus incompréhensible après coup. */}
        <div className="resa-part-form">
          <span className="mono num">Débit {afficherCentimes(totalDebit)}</span>
          <span className="mono num">Crédit {afficherCentimes(totalCredit)}</span>
          <span className={ecart === 0 ? 'hint' : 'nm'}>
            {ecart === 0
              ? totalDebit > 0 ? 'Écriture équilibrée.' : 'Saisissez les montants.'
              : `Écart de ${afficherCentimes(Math.abs(ecart))} — une écriture déséquilibrée est refusée.`}
          </span>
          {peutSaisir && (
            <button
              type="button"
              className="btn primary"
              disabled={busy || !pretASoumettre}
              onClick={enregistrer}
            >
              {busy ? 'Enregistrement…' : 'Enregistrer l’écriture'}
            </button>
          )}
        </div>
      </div>
    </section>
  )
}

function ligneVide() {
  return { compte: '', sens: 'debit', montant: '', tauxTva: '', libelle: '' }
}

function deuxLignesVides() {
  // Deux, parce qu'une écriture équilibrée en compte au moins deux : ouvrir le formulaire sur une
  // seule ligne ferait commencer par un état que le serveur refuse toujours.
  return [ligneVide(), ligneVide()]
}

function aujourdHui() {
  const d = new Date()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const j = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${j}`
}

// Une relation arrive tantôt en IRI nue, tantôt en objet, selon les groupes qui la portent. On
// n'accède jamais à une sous-propriété : c'est ce qui rend un rapprochement silencieusement vide.
function idDe(reference) {
  if (!reference) return null
  if (typeof reference === 'string') return reference.split('/').pop()
  if (typeof reference['@id'] === 'string') return reference['@id'].split('/').pop()
  return reference.id ?? null
}

/**
 * ⚠ CE `centimes()` CONVERTIT, IL N'AFFICHE PAS — et un homonyme partage fait l'inverse.
 *
 * Ici : des euros saisis a l'ecran vers des centimes entiers, pour additionner. Dans
 * `api/produit.js` : des centimes vers « 1 234,50 € », pour montrer. Les deux sont legitimes et le
 * mot est le meme.
 *
 * Le 03/09, un remplacement global de `euros(` par `centimes(` a fait appeler le convertisseur sur
 * trois sites d'affichage. Le build l'a refuse pour collision de symbole — par chance : sans elle,
 * l'ecran aurait montre un nombre nu la ou on attend un montant, sans erreur.
 *
 * L'affichage passe donc par `afficherCentimes`, importe sous un nom qui ne se confond pas.
 */
function centimes(valeur) {
  const n = Number.parseFloat(String(valeur).replace(',', '.'))
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) : 0
}


function dateFr(valeur) {
  const s = String(valeur).slice(0, 10)
  const [a, m, j] = s.split('-')
  return j ? `${j}/${m}/${a}` : s
}
