import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euroCentimes } from './Liste.jsx'

// LETTRER DES ÉCRITURES — le geste quotidien du comptable, qu'aucun écran ne permettait.
//
// ── CE QUE C'EST, POUR QUI NE FAIT PAS DE COMPTABILITÉ ─────────────────────────────────────────
//
// Lettrer, c'est déclarer qu'un ensemble de lignes se solde : la facture et son règlement, le
// débit et le crédit qui s'annulent. Ce qui reste **non lettré** est ce qui reste dû ou à
// recevoir — c'est la liste qu'on relance, et celle qu'on présente au commissaire aux comptes.
//
// `POST /compta/lettrages/groupe` existait, testé, appelé par personne. Sans écran, le lettrage
// n'existait que dans les écritures générées automatiquement : tout ce qui demandait un jugement
// humain restait en suspens indéfiniment.
//
// ── ⚠ LE SOLDE DE LA SÉLECTION EST AFFICHÉ, ET LE SERVEUR NE LE VÉRIFIE PAS ───────────────────
//
// `LettrerGroupeProcessor` exige au moins deux lignes et un profil exploitant commun. Il ne vérifie
// **pas** que débit et crédit s'équilibrent — mesuré en le lisant, pas supposé.
//
// C'est défendable : un lettrage partiel est un geste comptable légitime, et un serveur qui
// l'interdirait empêcherait de solder un règlement en plusieurs fois. Mais un écran qui ne montre
// pas l'écart laisserait lettrer par erreur deux lignes qui n'ont rien à voir. On affiche donc le
// solde en permanence, sans jamais bloquer : c'est au comptable de savoir s'il solde ou s'il
// acompte.
//
// ── ⚠ LE NUMÉRO DE COMPTE VIENT D'AILLEURS, ET CE N'EST PAS UN DÉTOUR GRATUIT ─────────────────
//
// `CompteComptable::$numero` et `$libelle` portent le groupe `ligne:read` — **pas** `ecriture:read`.
// Les lignes n'arrivant qu'imbriquées dans une écriture, lire `ligne.compte.numero` rendrait
// `undefined` : le tableau afficherait des comptes vides sur l'écran qui sert à les rapprocher.
//
// Vérifié avant d'écrire la première ligne, et c'est exactement ce que surveille le garde-fou n°32.
// On charge donc le plan comptable à part et on résout localement — sans élargir la charge utile de
// toutes les lectures d'écritures pour un seul écran.
export default function LettrageEcritures({ etabActif, droits = [] }) {
  const peutLettrer = aLeDroit(droits, 'compta.lettrer')

  const [ecritures, setEcritures] = useState(null)
  const [lettrages, setLettrages] = useState([])
  const [comptes, setComptes] = useState([])
  const [selection, setSelection] = useState({})
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState(null)

  const charger = useCallback(() => {
    setErreur(null)
    Promise.allSettled([api.ecrituresComptables(), api.lettragesEcritures(), api.comptesComptables()])
      .then(([e, l, c]) => {
        if (e.status === 'rejected') {
          setErreur(e.reason?.message || 'Les écritures n’ont pas pu être lues.')
          setEcritures([])
          return
        }
        setEcritures(membres(e.value))
        // ⚠ UN LETTRAGE ILLISIBLE N'EST PAS UN LETTRAGE ABSENT. Si cette liste échoue, on ne peut
        // pas savoir ce qui est déjà soldé — et proposer de tout lettrer serait pire que ne rien
        // proposer. On le dit et on s'arrête.
        if (l.status === 'rejected') {
          setErreur('Les lettrages existants n’ont pas pu être lus : impossible de distinguer ce qui reste à solder.')
          setLettrages(null)
        } else {
          setLettrages(membres(l.value))
        }
        setComptes(c.status === 'fulfilled' ? membres(c.value) : [])
      })
  }, [])

  useEffect(() => { setSelection({}); charger() }, [etabActif, charger])

  const libelleCompte = useCallback(
    (reference) => {
      const id = typeof reference === 'string' ? reference.split('/').pop() : reference?.id
      const c = comptes.find((x) => x.id === id)
      return c ? `${c.numero} — ${c.libelle}` : '(compte inconnu)'
    },
    [comptes],
  )

  // Les lignes non lettrées, à plat, avec leur écriture d'origine.
  const lignes = useMemo(() => {
    if (!ecritures || lettrages === null) return []
    const soldees = new Set(
      lettrages.map((l) => (typeof l.ligne === 'string' ? l.ligne.split('/').pop() : l.ligne?.id)).filter(Boolean),
    )
    const out = []
    for (const e of ecritures) {
      for (const l of e.lignes ?? []) {
        if (soldees.has(l.id)) continue
        out.push({ ...l, ecriture: e })
      }
    }
    return out
  }, [ecritures, lettrages])

  const choisies = lignes.filter((l) => selection[l.id])
  const solde = choisies.reduce((s, l) => s + (l.debitCentimes || 0) - (l.creditCentimes || 0), 0)

  async function lettrer() {
    setBusy(true)
    setErreur(null)
    setMessage(null)
    try {
      await api.lettrerGroupe(choisies.map((l) => l.id))
      setSelection({})
      setMessage(`${choisies.length} lignes lettrées.`)
      charger()
    } catch (e) {
      setErreur(e.message || 'Le lettrage n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  if (ecritures === null) return <div className="empty">Chargement…</div>

  return (
    <section className="card">
      <div className="card-h">
        <h3>Lettrage</h3>
        <span className="sub">ce qui reste à solder</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {message && <div className="banner banner-ok">{message}</div>}

        {lignes.length === 0 && !erreur ? (
          <div className="empty">
            <p>Aucune ligne à lettrer.</p>
            <p className="hint">
              Toutes les lignes d’écriture connues sont soldées. Ce qui apparaîtrait ici, ce sont les
              factures sans règlement et les règlements sans facture.
            </p>
          </div>
        ) : (
          <>
            <div className="resa-attente">
              {lignes.map((l) => (
                <label key={l.id} className="resa-part">
                  <input
                    type="checkbox"
                    checked={!!selection[l.id]}
                    onChange={(e) => setSelection((s) => ({ ...s, [l.id]: e.target.checked }))}
                    disabled={!peutLettrer}
                  />
                  <span className="mono">{libelleCompte(l.compte)}</span>
                  <span className="nm">{l.libelle || l.ecriture?.libelle || '—'}</span>
                  <span className="num mono">
                    {l.debitCentimes ? `D ${euroCentimes(l.debitCentimes)}` : `C ${euroCentimes(l.creditCentimes)}`}
                  </span>
                </label>
              ))}
            </div>

            {/* ⚠ LE SOLDE EST MONTRÉ, JAMAIS IMPOSÉ. Un lettrage partiel est légitime — solder un
                règlement en plusieurs fois — et le serveur ne l'interdit pas. Mais lettrer deux
                lignes qui n'ont rien à voir doit sauter aux yeux avant le clic, pas se découvrir à
                la révision. */}
            <div className="resa-part-form">
              <span className="hint">
                {choisies.length < 2
                  ? 'Sélectionnez au moins deux lignes.'
                  : solde === 0
                    ? `${choisies.length} lignes sélectionnées — la sélection est équilibrée.`
                    : `${choisies.length} lignes sélectionnées — écart de ${euroCentimes(Math.abs(solde))}. Un lettrage partiel reste possible.`}
              </span>
              {peutLettrer && (
                <button
                  type="button"
                  className="btn primary"
                  disabled={busy || choisies.length < 2}
                  onClick={lettrer}
                >
                  {busy ? 'Lettrage…' : 'Lettrer la sélection'}
                </button>
              )}
            </div>
          </>
        )}
      </div>
    </section>
  )
}
