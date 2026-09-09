import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euroCentimes } from './Liste.jsx'

// VERSER UNE RÉGIE — et ce n'est pas un écran manquant, c'est une clôture bloquée.
//
// ── LE BLOCAGE, MESURÉ ET NON DÉDUIT ───────────────────────────────────────────────────────────
//
// `ClotureGuard` refuse la clôture d'une période tant qu'une régie `depassePlafond()`, c'est-à-dire
// `soldeEncaisseCentimes > plafondEncaisseCentimes` — condition lue dans l'entité, pas devinée
// depuis le message d'erreur. `ClotureComptable.jsx` affiche ce refus tel quel : « versement
// requis ».
//
// Et jusqu'ici **aucun écran ne versait**. `POST /compta/regies/{id}/versements` existait, l'onglet
// Régie se contentait de lister les bordereaux déjà émis, et le champ « versement » de
// `SessionCaisse` est la clôture Z d'une caisse — autre opération, vérifiée avant de conclure.
//
// Le comptable lisait donc « versement requis » sans aucun endroit où verser : la clôture, qui est
// une obligation légale, n'avait pas de sortie.
//
// ── ⚠ DEUX REFUS DU SERVEUR, ANTICIPÉS ICI ─────────────────────────────────────────────────────
//
// `RegieHandler::enregistrerVersement` refuse un montant nul ou négatif (422) et un montant qui
// excède le solde d'encaisse (409). Les deux sont vérifiables avant le clic, donc ils le sont.
//
// ── ET LE VERSEMENT ÉCRIT EN COMPTABILITÉ TOUT SEUL ────────────────────────────────────────────
//
// Le handler génère l'écriture de régie dans la foulée. L'écran le dit : sans cela, on croirait
// devoir la saisir à la main dans l'onglet voisin, et on la passerait deux fois.
export default function VersementRegie({ etabActif, droits = [], onVersement }) {
  const peutVerser = aLeDroit(droits, 'caisse.versement')

  const [regies, setRegies] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [ouvert, setOuvert] = useState(null)
  const [montant, setMontant] = useState('')
  const [justificatifs, setJustificatifs] = useState('')
  const [busy, setBusy] = useState(false)
  const [succes, setSucces] = useState(null)

  const charger = useCallback(() => {
    setErreur(null)
    api.regieRecettes()
      .then((r) => setRegies(membres(r)))
      .catch((e) => {
        // ⚠ UNE LECTURE QUI ÉCHOUE N'EST PAS UNE ABSENCE DE RÉGIE. Afficher « aucune régie »
        // laisserait croire qu'il n'y a rien à verser, alors que la clôture reste bloquée.
        setErreur(e.message || 'Les régies de recettes n’ont pas pu être lues.')
        setRegies([])
      })
  }, [])

  useEffect(() => { charger() }, [etabActif, charger])

  function ouvrir(regie) {
    setOuvert(regie.id)
    // Le geste courant est de vider l'encaisse. On propose donc le solde entier, et on rappelle
    // séparément le minimum qui suffit à débloquer la clôture.
    setMontant(((regie.soldeEncaisseCentimes || 0) / 100).toFixed(2))
    setJustificatifs('')
    setSucces(null)
    setErreur(null)
  }

  async function verser(regie) {
    setBusy(true)
    setErreur(null)
    setSucces(null)
    try {
      const refs = justificatifs.split(',').map((s) => s.trim()).filter(Boolean)
      await api.verserRegie(regie.id, {
        montant,
        ...(refs.length ? { justificatifs: refs } : {}),
      })
      setOuvert(null)
      setSucces(
        'Versement enregistré. Le bordereau est émis et son écriture comptable est générée '
        + 'automatiquement : ne la saisissez pas une seconde fois.',
      )
      charger()
      onVersement?.()
    } catch (e) {
      setErreur(e.message || 'Le versement n’a pas été enregistré.')
    } finally {
      setBusy(false)
    }
  }

  if (regies === null) return <div className="empty">Chargement…</div>

  return (
    <section className="card">
      <div className="card-h">
        <h3>Régies de recettes</h3>
        <span className="sub">encaisse détenue, et versements</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {/* ⚠ « AUCUNE RÉGIE. » ÉTAIT VRAI ET INUTILE — ET IL L'EST RESTÉ UN AN.
            Le point final disait « il n'y en a pas », jamais « vous pouvez en déclarer une », parce
            qu'on ne le pouvait effectivement pas : aucun écran n'appelait `POST /api/regie_recettes`
            et l'entité n'était instanciée que par les jeux d'essai. Un exploitant lisait donc une
            phrase close sur un onglet définitivement vide.
            Maintenant que Paramètres › Caisse porte la déclaration, l'absence a une suite. */}
        {regies.length === 0 && !erreur && (
          <div className="empty">
            Aucune régie de recettes n’est déclarée. Tant qu’il n’y en a pas, il n’y a rien à verser
            ici — on en déclare une dans <b>Paramètres › Caisse &amp; moyens de paiement</b>.
          </div>
        )}

        {regies.map((r) => {
          const solde = r.soldeEncaisseCentimes || 0
          const plafond = r.plafondEncaisseCentimes || 0
          const depasse = solde > plafond
          const minimum = depasse ? solde - plafond : 0
          return (
            <div key={r.id} className="sup-acces">
              <div className="sup-acces-t">
                <span className="nm">{r.libelle || '—'}</span>
                {depasse
                  ? <span className="badge crit">au-dessus du plafond</span>
                  : <span className="badge good">sous le plafond</span>}
              </div>
              <div className="hint">
                Encaisse <span className="mono">{euroCentimes(solde)}</span> · plafond{' '}
                <span className="mono">{euroCentimes(plafond)}</span>
              </div>

              {/* ⚠ ON NOMME LA CONSÉQUENCE, PAS SEULEMENT L'ÉTAT. « Au-dessus du plafond » ne dit
                  pas au comptable que sa clôture est refusée à cause de ça — et c'est pourtant le
                  seul endroit où il peut y remédier. */}
              {depasse && (
                <div className="hint">
                  La clôture comptable de la période est refusée tant que cette régie dépasse son
                  plafond. Il faut verser au moins <span className="mono">{euroCentimes(minimum)}</span>.
                </div>
              )}

              {peutVerser && ouvert !== r.id && solde > 0 && (
                <button type="button" className="btn sm" onClick={() => ouvrir(r)}>
                  Verser depuis {r.libelle || 'cette régie'}
                </button>
              )}

              {ouvert === r.id && (
                <div className="resa-part-form">
                  <label className="field">
                    <span className="field-lbl">Montant versé</span>
                    <input
                      className="input num"
                      type="number"
                      step="0.01"
                      min="0"
                      max={(solde / 100).toFixed(2)}
                      value={montant}
                      onChange={(e) => setMontant(e.target.value)}
                    />
                  </label>
                  <label className="field">
                    <span className="field-lbl">Justificatifs</span>
                    <input
                      className="input"
                      value={justificatifs}
                      onChange={(e) => setJustificatifs(e.target.value)}
                      placeholder="BQ-2026-08-31, sac scellé n°412"
                    />
                    <span className="hint">Séparés par des virgules. Facultatif.</span>
                  </label>

                  {/* ⚠ LES DEUX REFUS DU SERVEUR SE DISENT ICI. Un montant nul est rejeté en 422,
                      un montant supérieur à l'encaisse en 409 : les laisser cliquer transformerait
                      une erreur de saisie visible en un refus après coup. */}
                  <span className="hint">
                    {centimes(montant) <= 0
                      ? 'Saisissez un montant strictement positif.'
                      : centimes(montant) > solde
                        ? `Le versement ne peut pas excéder l’encaisse (${euroCentimes(solde)}).`
                        : centimes(montant) < minimum
                          ? `Ce montant laisse la régie au-dessus de son plafond : la clôture restera refusée.`
                          : 'L’écriture comptable du versement sera générée automatiquement.'}
                  </span>

                  <button
                    type="button"
                    className="btn primary"
                    disabled={busy || centimes(montant) <= 0 || centimes(montant) > solde}
                    onClick={() => verser(r)}
                  >
                    {busy ? 'Enregistrement…' : 'Enregistrer le versement'}
                  </button>
                  <button type="button" className="btn ghost" onClick={() => setOuvert(null)}>
                    Annuler
                  </button>
                </div>
              )}
            </div>
          )
        })}
      </div>
    </section>
  )
}

function centimes(valeur) {
  const n = Number.parseFloat(String(valeur).replace(',', '.'))
  return Number.isFinite(n) ? Math.round(n * 100) : 0
}
