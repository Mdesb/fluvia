import { useCallback, useEffect, useState } from 'react'
import { api, membres, ApiError } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros } from '../api/produit.js'

/**
 * COMPTES BANCAIRES (T26) — la première porte de la trésorerie.
 *
 * Un compte porte un IBAN (jamais rendu en clair par le serveur : seuls les quatre derniers chiffres
 * reviennent, CA-1) et, facultativement, le compte comptable de classe 512 auquel ses mouvements se
 * rapprochent. Sans ce rattachement 512, l'écran de rapprochement ne peut rien suggérer — on le dit
 * ici plutôt que de laisser l'exploitant le découvrir deux onglets plus loin.
 */
// ⚠ UNE CONSTANTE DE MODULE : le formulaire s'initialise depuis l'objet reçu, une fois.
const COMPTE_NEUF = Object.freeze({})

export default function ComptesBancaires({ etabActif, droits, params = {}, majParams }) {
  // `nouveau`, ou l'identifiant du compte qu'on modifie.
  const ouvert = params.compte || ''
  const fermer = () => majParams({ compte: '' }, { pousser: true })
  const [comptes, setComptes] = useState([])
  const [comptesComptables, setComptesComptables] = useState([])
  // ⚠ « LU » N'EST PAS « VIDE ». Sans ce témoin, un refus laissait la liste à [] et l'écran
  // affirmait « Aucun compte bancaire, créez-en un » JUSTE SOUS le bandeau disant qu'il n'avait
  // pas pu lire. Deux affirmations opposées à l'écran en même temps ; observé en forçant un 403.
  const [comptesLus, setComptesLus] = useState(false)
  const [planLu, setPlanLu] = useState(false)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const peutGerer = aLeDroit(droits, 'finance.treasury_manage_account')

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const [c, cc] = await Promise.all([
        api.comptesBancaires(),
        api.comptesComptables().catch(() => null),
      ])
      setComptes(membres(c))
      setComptesLus(true)
      setComptesComptables(cc ? membres(cc) : [])
      setPlanLu(cc !== null)
    } catch (e) {
      setErreur(e.message)
      setComptesLus(false)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  const nomCompteComptable = (iri) => {
    if (!iri) return null
    const id = String(iri).split('/').pop()
    const cc = comptesComptables.find((x) => x.id === id)
    if (!cc) return null
    return cc.numero ? `${cc.numero} — ${cc.libelle}` : cc.libelle || id
  }

  if (chargement) {
    return (
      <section className="card">
        <div className="card-b center"><div className="spinner" /></div>
      </section>
    )
  }

  // ── LE COMPTE BANCAIRE, EN ÉCRAN ────────────────────────────────────────────────────────
  //
  // Posé APRÈS la garde de chargement, qui répond déjà à « on n'a pas encore lu ».
  //
  // ⚠ `comptes` part à [] : c'est `comptesLus` qui dit si on a lu, pas la longueur — une lecture
  // refusée laisse [] et n'autorise PAS à conclure « ce compte n'existe pas ».
  //
  // ⚠ `FormulaireCompte` s'initialise PARESSEUSEMENT depuis `compte` et ne se resynchronise
  // jamais : d'où la `key`, et `COMPTE_NEUF` plutôt qu'un `{}` écrit en ligne.
  if (ouvert) {
    const creation = ouvert === 'nouveau'
    const retour = (
      <button className="btn ghost sm" type="button" onClick={fermer}
        style={{ marginBottom: 'var(--esp-large)' }}>
        ← Retour aux comptes bancaires
      </button>
    )
    const compte = creation ? COMPTE_NEUF : comptes.find((c) => String(c.id) === String(ouvert))
    if (!creation && !compte) {
      return (
        <>
          {retour}
          <div className="banner banner-warn">
            {!comptesLus
              ? 'Les comptes bancaires n’ont pas pu être lus, donc celui-ci non plus. Ce n’est pas la même chose que « il n’existe pas ».'
              : 'Ce compte bancaire n’est plus dans la liste — il a sans doute été retiré depuis que ce lien a été copié.'}
          </div>
        </>
      )
    }
    return (
      <>
        {retour}
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <FormulaireCompte
          key={ouvert}
          compte={compte}
          etabActif={etabActif}
          comptesComptables={comptesComptables}
          planLu={planLu}
          onAnnuler={fermer}
          onEnregistre={async (msg) => { fermer(); setSucces(msg); setErreur(null); await recharger() }}
        />
      </>
    )
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {peutGerer && (
        <div className="row" style={{ justifyContent: 'flex-end', marginBottom: 'var(--esp-large)' }}>
          <button className="btn primary" type="button" onClick={() => majParams({ compte: 'nouveau' }, { pousser: true })}>
            Nouveau compte
          </button>
        </div>
      )}

      <section className="card">
        <div className="card-h"><h3>Comptes bancaires</h3><span className="sub">{comptesLus ? `${comptes.length} compte${comptes.length > 1 ? 's' : ''}` : 'non lu'}</span></div>
        <div className="card-b">
          {!comptesLus ? (
            <div className="empty">
              La liste des comptes bancaires n’a pas pu être lue. Ce qui existe n’est pas affiché
              ici — n’en concluez pas qu’il n’y a aucun compte.
            </div>
          ) : comptes.length === 0 ? (
            <div className="empty">Aucun compte bancaire. {peutGerer ? 'Créez-en un pour importer des relevés.' : ''}</div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Libellé</th>
                  <th>IBAN</th>
                  <th>BIC</th>
                  <th>Compte 512</th>
                  <th className="num">Solde d’ouverture</th>
                  <th>État</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {comptes.map((c) => (
                  <tr key={c.id}>
                    <td><span className="nm">{c.label}</span></td>
                    <td className="mono">{c.ibanLast4 ? `•••• ${c.ibanLast4}` : '—'}</td>
                    <td className="mono">{c.bic || '—'}</td>
                    <td>{nomCompteComptable(c.ledgerAccount) || <span className="sub">non rattaché</span>}</td>
                    <td className="num">{euros(c.openingBalance)}</td>
                    <td>{c.active ? <span className="badge good">actif</span> : <span className="badge mut">inactif</span>}</td>
                    {peutGerer && (
                      <td className="num">
                        <button className="btn ghost sm" type="button" onClick={() => majParams({ compte: String(c.id) }, { pousser: true })}>Modifier</button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>
    </>
  )
}

function FormulaireCompte({ compte, etabActif, comptesComptables, planLu, onAnnuler, onEnregistre }) {
  const creation = !compte.id
  const [label, setLabel] = useState(compte.label || '')
  const [iban, setIban] = useState('')
  const [bic, setBic] = useState(compte.bic || '')
  const [ledger, setLedger] = useState(compte.ledgerAccount || '')
  const [solde, setSolde] = useState(compte.openingBalance || '0.00')
  const [dateSolde, setDateSolde] = useState(compte.openingBalanceDate ? String(compte.openingBalanceDate).slice(0, 10) : '')
  const [actif, setActif] = useState(compte.active !== false)
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  async function enregistrer(e) {
    e.preventDefault()
    setBusy(true)
    setErreur(null)
    try {
      if (creation) {
        const corps = {
          establishment: `/api/etablissements/${etabActif}`,
          label,
          bic,
          openingBalance: solde || '0.00',
          openingBalanceDate: dateSolde,
          active: actif,
        }
        if (ledger) corps.ledgerAccount = ledger
        if (iban.trim()) corps.ibanClear = iban.trim()
        await api.creerCompteBancaire(corps)
        await onEnregistre('Compte bancaire créé.')
      } else {
        // PATCH : on n'envoie que ce qui se modifie. L'IBAN n'est renvoyé que si l'exploitant en
        // saisit un nouveau — laissé vide, le serveur conserve celui déjà chiffré.
        const corps = { label, bic, active: actif, ledgerAccount: ledger || null }
        if (iban.trim()) corps.ibanClear = iban.trim()
        await api.majCompteBancaire(compte.id, corps)
        await onEnregistre('Compte bancaire modifié.')
      }
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : (err.message || 'Enregistrement impossible.'))
      setBusy(false)
    }
  }

  return (
    <>
      <h2>{creation ? 'Nouveau compte bancaire' : 'Modifier le compte'}</h2>
      <form onSubmit={enregistrer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="field">
          <label htmlFor="ba-label">Libellé</label>
          <input id="ba-label" className="input" value={label} onChange={(e) => setLabel(e.target.value)} required placeholder="Compte courant BNP" />
        </div>
        <div className="field">
          <label htmlFor="ba-iban">IBAN {creation ? '' : '(laisser vide pour conserver l’actuel)'}</label>
          <input id="ba-iban" className="input" value={iban} onChange={(e) => setIban(e.target.value)} placeholder="FR76 3000 6000 0112 3456 7890 189" autoComplete="off" />
        </div>
        <div className="field">
          <label htmlFor="ba-bic">BIC</label>
          <input id="ba-bic" className="input" value={bic} onChange={(e) => setBic(e.target.value)} placeholder="BNPAFRPP" />
        </div>
        <div className="field">
          <label htmlFor="ba-ledger">Compte comptable 512 (rapprochement)</label>
          {/* ⚠ LE LIBELLÉ DIT 512, LA LISTE DOIT DIRE 512. Elle proposait les dix-sept comptes du
              plan — 401000 Fournisseurs, 706100 Redevances, 445710 TVA. Ce n'était pas cosmétique :
              le compte choisi sert à désigner, dans une écriture déjà scellée, quelle ligne est la
              ligne de banque. Avec un compte de produits, les suggestions ET la confirmation
              s'accordaient sur le mauvais compte, et la ligne de relevé se serait lettrée contre
              une recette. Le serveur le refuse désormais aussi — ici on ne le propose plus. */}
          <select id="ba-ledger" className="input" value={ledger} onChange={(e) => setLedger(e.target.value)}>
            <option value="">— aucun (rapprochement indisponible) —</option>
            {comptesComptables
              .filter((cc) => String(cc.numero || '').startsWith('512'))
              .map((cc) => (
                <option key={cc.id} value={`/api/compte_comptables/${cc.id}`}>
                  {cc.numero ? `${cc.numero} — ${cc.libelle}` : cc.libelle || cc.id}
                </option>
              ))}
          </select>
          {!planLu ? (
            <span className="hint">
              Le plan comptable n’a pas pu être lu : la liste est vide parce que la lecture a
              échoué, pas parce qu’il n’existe aucun compte 512.
            </span>
          ) : comptesComptables.filter((cc) => String(cc.numero || '').startsWith('512')).length === 0 && (
            <span className="hint">
              Aucun compte de classe 512 au plan comptable. Sans lui le rapprochement ne peut rien
              suggérer — créez-le en comptabilité.
            </span>
          )}
        </div>
        {creation && (
          <div className="row row-champs" style={{ gap: 'var(--esp-large)' }}>
            <div className="field" style={{ flex: 1 }}>
              <label htmlFor="ba-solde">Solde d’ouverture</label>
              <input id="ba-solde" className="input" value={solde} onChange={(e) => setSolde(e.target.value)} inputMode="decimal" placeholder="0.00" />
            </div>
            <div className="field" style={{ flex: 1 }}>
              <label htmlFor="ba-date">Date du solde</label>
              <input id="ba-date" className="input" type="date" value={dateSolde} onChange={(e) => setDateSolde(e.target.value)} required />
            </div>
          </div>
        )}
        <label className="row" style={{ gap: 'var(--esp-normal)', alignItems: 'center' }}>
          <input type="checkbox" checked={actif} onChange={(e) => setActif(e.target.checked)} />
          <span>Compte actif</span>
        </label>
        <div className="row" style={{ justifyContent: 'flex-end', gap: 'var(--esp-normal)' }}>
          <button className="btn ghost" type="button" onClick={onAnnuler} disabled={busy}>Annuler</button>
          <button className="btn primary" type="submit" disabled={busy || !label}>{busy ? 'Enregistrement…' : 'Enregistrer'}</button>
        </div>
      </form>
    </>
  )
}
