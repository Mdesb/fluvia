import { useEffect, useMemo, useState, useRef } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from './Modal.jsx'
import { libelleProduit } from '../api/produit.js'
import { idDe } from '../api/iri.js'

/**
 * LES PARTENAIRES DE REVENTE EN LIGNE — quatre routes, aucun écran.
 *
 * Un partenaire OTA revend vos billets sur sa propre plateforme. Il achète à un TARIF NET et vous
 * lui devez une COMMISSION : ce sont ces deux nombres qui décident de ce que vous encaissez, et
 * aucun écran ne permettait ni de les poser, ni même de les lire.
 *
 * ⚠ SANS CET ÉCRAN, L'ÉCRAN DES REVERSEMENTS RESTAIT VIDE À JAMAIS. On ne peut pas devoir de
 * l'argent à un partenaire qui n'existe pas. Construire le second sans le premier aurait produit
 * une page qui n'aurait jamais rien affiché — et personne n'aurait su pourquoi.
 */
// ⚠ UNE CONSTANTE DE MODULE : le formulaire recharge ses champs à chaque nouvel objet `valeurs`.
const PARTENAIRE_NOUVEAU = Object.freeze({ nom: '', vitrine: '', tarifNet: '0.00', commission: '0.00', codeConnecteur: '', actif: true })

export default function PartenairesOta({ etabActif, droits = [], params = {}, majParams }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  // ⚠ UNE LISTE EST LUE POUR UN ÉTABLISSEMENT (même défaut qu'AudioguidesMusee) : elle garde
  // l'établissement pour lequel elle a été lue, une réponse périmée est ignorée, et une liste d'un
  // autre établissement compte comme « on lit ».
  const [lecturePartenaires, setLecturePartenaires] = useState({ etab: null, lignes: null })
  const [lectureVitrines, setLectureVitrines] = useState({ etab: null, lignes: null })
  const etabCourant = useRef(etabActif)
  etabCourant.current = etabActif
  const partenaires = lecturePartenaires.etab === etabActif ? lecturePartenaires.lignes : null
  const vitrines = lectureVitrines.etab === etabActif ? lectureVitrines.lignes : null
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [quotas, setQuotas] = useState(null)
  const [produitsCatalogue, setProduitsCatalogue] = useState(null)
  const [editionQuota, setEditionQuota] = useState(null)

  const peutGerer = aLeDroit(droits, 'boutique.gerer_connecteur_ota')

  function charger() {
    const etab = etabActif
    setLecturePartenaires({ etab, lignes: null })
    api.partenairesOta()
      .then((r) => { if (etabCourant.current === etab) setLecturePartenaires({ etab, lignes: membres(r) }) })
      .catch(() => { if (etabCourant.current === etab) setLecturePartenaires({ etab, lignes: undefined }) })
  }

  useEffect(charger, [etabActif])

  function chargerQuotas() {
    setQuotas(null)
    api.quotasOta().then((r) => setQuotas(membres(r))).catch(() => setQuotas(undefined))
  }

  useEffect(chargerQuotas, [etabActif])

  useEffect(() => {
    api.produits({ itemsPerPage: 200 })
      .then((r) => setProduitsCatalogue(membres(r)))
      .catch(() => setProduitsCatalogue(undefined))
  }, [etabActif])

  useEffect(() => {
    api.vitrines()
      .then((r) => { if (etabCourant.current === etabActif) setLectureVitrines({ etab: etabActif, lignes: membres(r) }) })
      .catch(() => { if (etabCourant.current === etabActif) setLectureVitrines({ etab: etabActif, lignes: undefined }) })
  }, [etabActif])

  // Le partenaire ouvert : la constante en création, un objet mémorisé sur la ligne lue en modification.
  const valeursPartenaire = useMemo(() => {
    if (!params.partenaire) return null
    if (params.partenaire === 'nouveau') return PARTENAIRE_NOUVEAU
    if (!Array.isArray(partenaires)) return null
    const p = partenaires.find((x) => String(x.id) === String(params.partenaire))
    if (!p) return null
    return {
      id: p.id,
      nom: p.nom || '',
      vitrine: p.vitrine
        ? (typeof p.vitrine === 'string' ? String(p.vitrine).split('/').pop() : String(p.vitrine.id || ''))
        : '',
      tarifNet: p.tarifNet || '0.00',
      commission: p.commission || '0.00',
      codeConnecteur: p.codeConnecteur || '',
      actif: p.actif !== false,
    }
  }, [params.partenaire, partenaires])

  function nomVitrine(ref) {
    if (!ref) return null
    const id = typeof ref === 'string' ? String(ref).split('/').pop() : String(ref.id || '')
    if (!Array.isArray(vitrines)) return undefined
    const v = vitrines.find((x) => String(x.id) === id)
    return v ? (v.slug || v.nom) : null
  }

  // ── NOUVEAU OU MODIFIER UN PARTENAIRE, EN ÉCRAN ────────────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LE DROIT DU BOUTON ET L'ÉCRAN LE REPREND. Une modification se fait sur la
  // ligne lue : illisible, on ne la réécrit pas, et « introuvable » ne se dit que sur une liste lue.
  if (params.partenaire) {
    const fermerPartenaire = () => majParams({ partenaire: '' }, { pousser: true })
    const creation = params.partenaire === 'nouveau'
    let contenu
    if (!peutGerer) {
      contenu = <div className="banner banner-warn">Gérer les partenaires de revente demande le droit de gérer les connecteurs de revente, que ce compte n’a pas.</div>
    } else if (vitrines === null || (!creation && partenaires === null)) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (!creation && partenaires === undefined) {
      contenu = <div className="banner banner-error">Les partenaires n’ont pas pu être lus : on ne modifie pas une fiche qu’on n’a pas lue.</div>
    } else if (!valeursPartenaire) {
      contenu = <div className="banner banner-warn">Ce partenaire n’existe pas, ou n’est pas visible depuis cet établissement.</div>
    } else {
      contenu = (
        <>
          {erreur && <div className="banner banner-error">{erreur}</div>}
          <EditionPartenaire
            key={params.partenaire}
            valeurs={valeursPartenaire}
            vitrines={vitrines}
            onFermer={fermerPartenaire}
            onFait={(m) => { fermerPartenaire(); setSucces(m); setErreur(null); charger() }}
            onErreur={setErreur}
          />
        </>
      )
    }
    return (
      <>
        <button className="btn ghost sm" type="button" onClick={fermerPartenaire}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour aux partenaires
        </button>
        {contenu}
      </>
    )
  }

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Partenaires de revente</h3>
        <span className="sub">
          {partenaires === null ? 'lecture…' : partenaires === undefined ? 'illisible' : `${partenaires.length}`}
        </span>
        {peutGerer && (
          <button
            className="btn primary sm"
            type="button"
            style={{ marginLeft: 'auto' }}
            onClick={() => { setErreur(null); setSucces(null); majParams({ partenaire: 'nouveau' }, { pousser: true }) }}
          >
            Nouveau partenaire
          </button>
        )}
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {partenaires === undefined && (
          <div className="banner banner-warn">
            Les partenaires n’ont pas pu être lus. Cet écran ne sait donc pas qui revend vos
            billets — ce n’est pas la même chose que « personne ».
          </div>
        )}

        {partenaires === null && <div className="empty">Lecture des partenaires…</div>}

        {Array.isArray(partenaires) && partenaires.length === 0 && (
          <div className="empty">
            Aucun partenaire. Un partenaire revend vos billets sur sa plateforme&nbsp;: il achète à
            un tarif net, vous lui devez une commission, et c’est de là que naissent les
            reversements.
          </div>
        )}

        {Array.isArray(partenaires) && partenaires.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Partenaire</th>
                  <th>Vitrine</th>
                  <th className="num">Tarif net</th>
                  <th className="num">Commission</th>
                  <th>Connecteur</th>
                  <th>État</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {partenaires.map((p) => (
                  <tr key={p.id}>
                    <td><span className="nm">{p.nom || '—'}</span></td>
                    <td>
                      {/* Trois cas, et le deuxième n'est pas le troisième. */}
                      {!p.vitrine
                        ? <span className="sub">—</span>
                        : nomVitrine(p.vitrine) === undefined
                          ? <span className="sub">vitrines non lues</span>
                          : nomVitrine(p.vitrine) || <span className="sub">vitrine inconnue</span>}
                    </td>
                    <td className="num">{p.tarifNet}</td>
                    <td className="num">{p.commission}</td>
                    <td>{p.codeConnecteur || <span className="sub">—</span>}</td>
                    <td>
                      {p.actif
                        ? <span className="badge good">actif</span>
                        : <span className="badge mut">inactif</span>}
                    </td>
                    {peutGerer && (
                      <td>
                        <button
                          className="btn ghost sm"
                          type="button"
                          onClick={() => { setErreur(null); setSucces(null); majParams({ partenaire: String(p.id) }, { pousser: true }) }}
                        >
                          Modifier
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <QuotasOta
        quotas={quotas}
        partenaires={partenaires}
        produits={produitsCatalogue}
        peutGerer={peutGerer}
        onEditer={setEditionQuota}
        onNouveau={() => setEditionQuota({ partenaire: '', produit: '', quotaAlloue: '0' })}
      />

      <EditionQuota
        valeurs={editionQuota}
        partenaires={partenaires}
        produits={produitsCatalogue}
        onFermer={() => setEditionQuota(null)}
        onFait={(m) => { setEditionQuota(null); setSucces(m); setErreur(null); chargerQuotas() }}
        onErreur={setErreur}
      />
    </section>
  )
}

/**
 * ⚠ TARIF NET ET COMMISSION SONT DEUX NOMBRES QUI DÉCIDENT DE CE QUE VOUS ENCAISSEZ.
 *
 * L'écran les nomme en clair plutôt que par leur nom technique : « ce que le partenaire vous paie »
 * et « ce que vous lui devez ». Un exploitant qui inverse les deux ne s'en aperçoit qu'au premier
 * reversement, quand le montant est faux — et rien dans le formulaire ne l'aura prévenu.
 */
function EditionPartenaire({ valeurs, vitrines, onFermer, onFait, onErreur }) {
  const [v, setV] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => { setV(valeurs) }, [valeurs])

  if (!valeurs || !v) return null

  function champ(nom, valeur) {
    setV((p) => ({ ...p, [nom]: valeur }))
  }

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = {
        nom: v.nom.trim(),
        tarifNet: String(v.tarifNet || '0.00'),
        commission: String(v.commission || '0.00'),
        codeConnecteur: v.codeConnecteur.trim() || null,
        actif: !!v.actif,
        // `null` détache explicitement : un champ absent laisserait la vitrine précédente, et on
        // n'aurait aucun moyen de retirer un rattachement posé par erreur.
        vitrine: v.vitrine ? `/api/boutique/vitrines/${v.vitrine}` : null,
      }
      if (v.id) await api.majPartenaireOta(v.id, corps)
      else await api.creerPartenaireOta(corps)
      await onFait(v.id ? 'Partenaire enregistré.' : 'Partenaire créé.')
    } catch (e) {
      onErreur(e.message || 'Le partenaire n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h2>{v.id ? 'Modifier le partenaire' : 'Nouveau partenaire de revente'}</h2>
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Nom du partenaire *</span>
          <input className="input" value={v.nom} onChange={(e) => champ('nom', e.target.value)} maxLength={120} />
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Ce que le partenaire vous paie par billet</span>
          <input
            className="input"
            inputMode="decimal"
            value={v.tarifNet}
            onChange={(e) => champ('tarifNet', e.target.value)}
          />
          <span className="sub">Le tarif net : votre recette, hors ce que le client lui a payé.</span>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Ce que vous lui devez</span>
          <input
            className="input"
            inputMode="decimal"
            value={v.commission}
            onChange={(e) => champ('commission', e.target.value)}
          />
          <span className="sub">La commission, reversée période par période.</span>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Vitrine rattachée</span>
          {vitrines === undefined ? (
            <span className="sub">Les vitrines n’ont pas pu être lues.</span>
          ) : (
            <select className="select" value={v.vitrine} onChange={(e) => champ('vitrine', e.target.value)}>
              <option value="">— aucune —</option>
              {(vitrines || []).map((x) => (
                <option key={x.id} value={x.id}>{x.slug || x.nom || x.id}</option>
              ))}
            </select>
          )}
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Code du connecteur — facultatif</span>
          <input
            className="input"
            value={v.codeConnecteur}
            onChange={(e) => champ('codeConnecteur', e.target.value)}
            maxLength={60}
          />
        </label>

        <label style={{ display: 'flex', gap: 'var(--esp-normal)', alignItems: 'baseline' }}>
          <input type="checkbox" checked={!!v.actif} onChange={(e) => champ('actif', e.target.checked)} />
          <span>Partenaire actif</span>
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button
            className="btn primary"
            type="button"
            disabled={busy || v.nom.trim() === ''}
            onClick={enregistrer}
          >
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </>
  )
}


/**
 * LES QUOTAS ALLOUES — ce qui rend la revente possible.
 *
 * Un partenaire ne vend que ce qu'on lui a alloue. Sans quota, il n'a rien a proposer, et le
 * reversement qu'on lui doit reste a zero sans que personne ne comprenne pourquoi.
 *
 * ⚠ LE CHIFFRE QUI COMPTE EST LE RESTANT. « 40 sur 50 » oblige a soustraire ; « il reste 10 » se
 * lit d'un coup, et un quota epuise est exactement ce qu'on cherche quand un partenaire cesse
 * brusquement de vendre.
 */
function QuotasOta({ quotas, partenaires, produits, peutGerer, onEditer, onNouveau }) {
  // `idDe` vient d'`api/iri.js` (§8.3, garde-fou n°53). Ce composant en avait posé une copie :
  // `.split('/').pop()` rend la chaîne VIDE sur une référence terminée par un slash, là où la
  // canonique filtre les segments vides. Même contrat de retour (`null` quand il n'y a rien).

  function nomDe(liste, ref, etiquette) {
    const id = idDe(ref)
    if (!id) return null
    if (!Array.isArray(liste)) return undefined
    const t = liste.find((x) => String(x.id) === id)
    return t ? (etiquette(t)) : null
  }

  const epuises = Array.isArray(quotas)
    ? quotas.filter((q) => (q.quotaAlloue || 0) - (q.quotaConsomme || 0) <= 0).length
    : 0

  return (
    <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Quotas alloués</h3>
        <span className="sub">
          {quotas === null ? 'lecture…' : quotas === undefined ? 'illisible' : `${quotas.length}`}
        </span>
        {peutGerer && (
          <button className="btn primary sm" type="button" style={{ marginLeft: 'auto' }} onClick={onNouveau}>
            Allouer un quota
          </button>
        )}
      </div>

      <div className="card-b">
        <div className="sub" style={{ marginBottom: 'var(--esp-normal)' }}>
          Un partenaire ne vend que ce qu’on lui alloue. Le consommé est tenu par le serveur, à
          partir des ventes que la plateforme partenaire remonte&nbsp;: il ne se corrige pas ici.
        </div>

        {quotas === undefined && (
          <div className="banner banner-warn">
            Les quotas n’ont pas pu être lus. Cet écran ne sait donc pas ce que vos partenaires
            peuvent encore vendre.
          </div>
        )}

        {/* ⚠ UN QUOTA EPUISE EST LA RAISON POUR LAQUELLE UN PARTENAIRE CESSE DE VENDRE. Sans ce
            compte en tête, on cherche la panne du côté du connecteur pendant des jours. */}
        {epuises > 0 && (
          <div className="banner banner-warn">
            <b>{epuises} quota{epuises > 1 ? 's' : ''} épuisé{epuises > 1 ? 's' : ''}.</b>{' '}
            Le partenaire concerné ne peut plus rien vendre sur ce produit tant que vous n’augmentez
            pas son allocation.
          </div>
        )}

        {quotas === null && <div className="empty">Lecture des quotas…</div>}

        {Array.isArray(quotas) && quotas.length === 0 && (
          <div className="empty">
            Aucun quota alloué. Vos partenaires n’ont donc rien à revendre.
          </div>
        )}

        {Array.isArray(quotas) && quotas.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Partenaire</th>
                  <th>Produit</th>
                  <th className="num">Alloué</th>
                  <th className="num">Vendu</th>
                  <th>Reste</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {quotas.map((q) => {
                  const alloue = q.quotaAlloue || 0
                  const vendu = q.quotaConsomme || 0
                  const reste = alloue - vendu
                  const nomP = nomDe(partenaires, q.partenaire, (x) => x.nom)
                  const nomProd = nomDe(produits, q.produit, (x) => libelleProduit(x))
                  return (
                    <tr key={q.id}>
                      <td>
                        {nomP === undefined
                          ? <span className="sub">partenaires non lus</span>
                          : nomP || <span className="sub">partenaire inconnu</span>}
                      </td>
                      <td>
                        {nomProd === undefined
                          ? <span className="sub">catalogue non lu</span>
                          : nomProd || <span className="sub">produit inconnu</span>}
                        {q.creneau && <div className="sub">sur un créneau précis</div>}
                      </td>
                      <td className="num">{alloue}</td>
                      <td className="num">{vendu}</td>
                      <td>
                        {reste <= 0
                          ? <span className="badge crit">épuisé</span>
                          : <span className={reste <= 5 ? 'badge warn' : ''}>il reste {reste}</span>}
                      </td>
                      {peutGerer && (
                        <td>
                          <button
                            className="btn ghost sm"
                            type="button"
                            onClick={() => onEditer({
                              id: q.id,
                              partenaire: idDe(q.partenaire) || '',
                              produit: idDe(q.produit) || '',
                              quotaAlloue: String(alloue),
                            })}
                          >
                            Modifier
                          </button>
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
    </section>
  )
}

/**
 * ⚠ ON N'ENVOIE JAMAIS `quotaConsomme`. C'est le compte du serveur, alimenté par les ventes que la
 * plateforme partenaire remonte sur `/boutique/ota/ventes`. Offrir de le corriger à la main ferait
 * diverger le registre de la réalité, et personne ne saurait plus lequel fait foi.
 *
 * Le créneau n'est pas proposé non plus : un quota peut viser une séance précise, mais l'écran ne
 * lit pas les créneaux — offrir un choix vide vaut moins que ne rien offrir. Les quotas posés par
 * l'API sur un créneau restent lisibles, et la ligne le signale.
 */
function EditionQuota({ valeurs, partenaires, produits, onFermer, onFait, onErreur }) {
  const [v, setV] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => { setV(valeurs) }, [valeurs])

  if (!valeurs || !v) return null

  const pret = v.partenaire !== '' && v.produit !== '' && Number(v.quotaAlloue) >= 0

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = {
        partenaire: `/api/boutique_partenaire_otas/${v.partenaire}`,
        produit: `/api/produits/${v.produit}`,
        quotaAlloue: Number(v.quotaAlloue) || 0,
      }
      if (v.id) await api.majQuotaOta(v.id, corps)
      else await api.creerQuotaOta(corps)
      await onFait(v.id ? 'Quota enregistré.' : 'Quota alloué.')
    } catch (e) {
      onErreur(e.message || 'Le quota n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre={v.id ? 'Modifier le quota' : 'Allouer un quota'} taille="sm">
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Partenaire *</span>
          {partenaires === undefined ? (
            <span className="sub">Les partenaires n’ont pas pu être lus.</span>
          ) : (
            <select
              className="select"
              value={v.partenaire}
              onChange={(e) => setV((p) => ({ ...p, partenaire: e.target.value }))}
            >
              <option value="">— choisir —</option>
              {(partenaires || []).map((x) => <option key={x.id} value={x.id}>{x.nom}</option>)}
            </select>
          )}
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Produit *</span>
          {produits === undefined ? (
            <span className="sub">Le catalogue n’a pas pu être lu.</span>
          ) : (
            <select
              className="select"
              value={v.produit}
              onChange={(e) => setV((p) => ({ ...p, produit: e.target.value }))}
            >
              <option value="">— choisir —</option>
              {(produits || []).map((x) => <option key={x.id} value={x.id}>{libelleProduit(x)}</option>)}
            </select>
          )}
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Quantité allouée *</span>
          <input
            className="input"
            type="number"
            min="0"
            value={v.quotaAlloue}
            onChange={(e) => setV((p) => ({ ...p, quotaAlloue: e.target.value }))}
          />
          <span className="sub">
            Le nombre déjà vendu n’est pas modifiable ici&nbsp;: il vient des ventes remontées par
            le partenaire.
          </span>
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="button" disabled={busy || !pret} onClick={enregistrer}>
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
