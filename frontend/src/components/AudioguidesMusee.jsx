import { useEffect, useMemo, useState, useRef } from 'react'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { libelleProduit } from '../api/produit.js'
import { idDe } from '../api/iri.js'

/**
 * LES AUDIOGUIDES, ET CE QU'ILS RÉVÈLENT — quatre routes servies, aucun écran.
 *
 * Un audioguide est une spécialisation musée d'un produit : il porte les LANGUES disponibles. Un
 * guide, lui, porte ses qualifications de langue. Et la **bascule** est le repli quand aucun guide
 * qualifié n'est disponible dans la langue demandée — avec une remise automatique sur le tarif.
 *
 * ⚠ LE CROISEMENT EST TOUT L'INTÉRÊT DE CET ÉCRAN, ET PERSONNE NE POUVAIT LE FAIRE. Une langue que
 * l'audioguide propose et qu'AUCUN guide ne parle est une bascule **certaine** : chaque visite
 * demandée dans cette langue partira en repli, et coûtera sa remise. Ce n'est pas un aléa
 * d'agenda, c'est une lacune de recrutement — et les deux ne se corrigent pas pareil.
 *
 * ⚠ ON COMPTE LES GUIDES, ON NE LES NOMME PAS. Le nom d'un guide vit deux sauts plus loin
 * (qualification → guide → utilisateur), et le second saut arrive en référence nue. Un écran qui
 * afficherait « guide #e8bba2be » n'aiderait personne ; le NOMBRE, lui, dit exactement quoi faire.
 */
// ⚠ UNE CONSTANTE DE MODULE : le formulaire recharge ses champs à chaque nouvel objet `valeurs`.
// Un `{ produit: '', langues: [] }` écrit en ligne effacerait la saisie à chaque rendu.
const AUDIOGUIDE_NOUVEAU = Object.freeze({ produit: '', langues: [] })

export default function AudioguidesMusee({ etabActif, droits, params = {}, majParams }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  // ⚠ UNE LISTE EST LUE POUR UN ÉTABLISSEMENT. Remise à `null` dans l'effet, elle restait affichée
  // une trame après une bascule ; et une réponse partie avant la bascule arrivait après elle et
  // réaffichait, sous le nouvel établissement, l'audioguide de l'ancien. La lecture garde
  // l'établissement pour lequel elle a été faite, une réponse périmée est ignorée, et une liste
  // d'un autre établissement compte comme « on lit ».
  const [lectureAudioguides, setLectureAudioguides] = useState({ etab: null, lignes: null })
  const [qualifications, setQualifications] = useState(null)
  const [bascules, setBascules] = useState(null)
  const [lectureProduits, setLectureProduits] = useState({ etab: null, lignes: null })
  const etabCourant = useRef(etabActif)
  etabCourant.current = etabActif
  const audioguides = lectureAudioguides.etab === etabActif ? lectureAudioguides.lignes : null
  const produits = lectureProduits.etab === etabActif ? lectureProduits.lignes : null
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  const peutConfigurer = aUnDesDroits(droits, ['musee.configurer', 'musee.gerer'])

  function charger() {
    const etab = etabActif
    setLectureAudioguides({ etab, lignes: null })
    api.museeAudioguides()
      .then((r) => { if (etabCourant.current === etab) setLectureAudioguides({ etab, lignes: membres(r) }) })
      .catch(() => { if (etabCourant.current === etab) setLectureAudioguides({ etab, lignes: undefined }) })
  }

  useEffect(charger, [etabActif])

  useEffect(() => {
    api.museeQualificationsLangue()
      .then((r) => setQualifications(membres(r)))
      .catch(() => setQualifications(undefined))
    api.museeBasculesAudioguide()
      .then((r) => setBascules(membres(r)))
      .catch(() => setBascules(undefined))
    api.produits({ itemsPerPage: 200 })
      .then((r) => { if (etabCourant.current === etabActif) setLectureProduits({ etab: etabActif, lignes: membres(r) }) })
      .catch(() => { if (etabCourant.current === etabActif) setLectureProduits({ etab: etabActif, lignes: undefined }) })
  }, [etabActif])

  function nomProduit(ref) {
    const id = idDe(ref)
    if (!id) return null
    if (!Array.isArray(produits)) return undefined
    const p = produits.find((x) => String(x.id) === String(id))
    return p ? libelleProduit(p) : null
  }

  /**
   * ⚠ LA COUVERTURE NE SE CALCULE QUE SI LES DEUX LECTURES ONT ABOUTI. Croiser des langues lues
   * avec des qualifications illisibles déclarerait TOUTES les langues sans guide — l'alerte la plus
   * fausse possible, et la plus crédible : on courrait recruter des guides qui existent déjà.
   */
  const couverture = useMemo(() => {
    if (!Array.isArray(audioguides) || !Array.isArray(qualifications)) return null

    const guidesParLangue = {}
    for (const q of qualifications) {
      const l = (q.langue || '').toLowerCase()
      if (!l) continue
      if (!guidesParLangue[l]) guidesParLangue[l] = new Set()
      const gid = typeof q.guide === 'string' ? q.guide : q.guide?.id
      if (gid) guidesParLangue[l].add(String(gid))
    }

    const langues = new Set()
    for (const a of audioguides) for (const l of a.langues || []) langues.add(String(l).toLowerCase())
    for (const l of Object.keys(guidesParLangue)) langues.add(l)

    return [...langues].sort().map((l) => ({
      langue: l,
      audioguide: audioguides.some((a) => (a.langues || []).some((x) => String(x).toLowerCase() === l)),
      guides: guidesParLangue[l] ? guidesParLangue[l].size : 0,
    }))
  }, [audioguides, qualifications])

  const lacunes = useMemo(
    () => (couverture || []).filter((c) => c.audioguide && c.guides === 0),
    [couverture],
  )

  // L'audioguide ouvert : la constante en création, un objet mémorisé sur la ligne lue en modification.
  const valeursOuvertes = useMemo(() => {
    if (!params.audioguide) return null
    if (params.audioguide === 'nouveau') return AUDIOGUIDE_NOUVEAU
    if (!Array.isArray(audioguides)) return null
    const a = audioguides.find((x) => String(x.id) === String(params.audioguide))
    return a ? { id: a.id, produit: idDe(a.produit) || '', langues: [...(a.langues || [])] } : null
  }, [params.audioguide, audioguides])

  // ── DÉCLARER OU MODIFIER UN AUDIOGUIDE, EN ÉCRAN ────────────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LE DROIT DU BOUTON ET L'ÉCRAN LE REPREND. Une modification se fait sur la
  // ligne lue : illisible, on ne la réécrit pas. Une création attend le catalogue, qui dit déjà
  // lui-même son échec dans le formulaire.
  if (params.audioguide) {
    const fermerAudioguide = () => majParams({ audioguide: '' }, { pousser: true })
    const creation = params.audioguide === 'nouveau'
    let contenu
    if (!peutConfigurer) {
      contenu = <div className="banner banner-warn">Déclarer ou modifier un audioguide demande le droit de configurer le musée, que ce compte n’a pas.</div>
    } else if ((creation && produits === null) || (!creation && audioguides === null)) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (!creation && audioguides === undefined) {
      contenu = <div className="banner banner-error">Les audioguides n’ont pas pu être lus : on ne modifie pas une fiche qu’on n’a pas lue.</div>
    } else if (!valeursOuvertes) {
      contenu = <div className="banner banner-warn">Cet audioguide n’existe pas, ou n’est pas visible depuis cet établissement.</div>
    } else {
      contenu = (
        <>
          {erreur && <div className="banner banner-error">{erreur}</div>}
          <EditionAudioguide
            key={params.audioguide}
            valeurs={valeursOuvertes}
            produits={produits}
            onFermer={fermerAudioguide}
            onFait={(m) => { fermerAudioguide(); setSucces(m); setErreur(null); charger() }}
            onErreur={setErreur}
          />
        </>
      )
    }
    return (
      <>
        <button className="btn ghost sm" type="button" onClick={fermerAudioguide}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour aux audioguides
        </button>
        {contenu}
      </>
    )
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--esp-bloc)' }}>

      {/* ── LE BLOC QU'AUCUN ÉCRAN NE POUVAIT RENDRE ─────────────────────────────────────────── */}
      <section className="card">
        <div className="card-h">
          <h3>Couverture des langues</h3>
          <span className="sub">
            {couverture === null ? 'calcul impossible' : `${couverture.length} langue(s)`}
          </span>
        </div>
        <div className="card-b">
          {couverture === null ? (
            <div className="banner banner-warn">
              Il manque une des deux lectures — audioguides ou qualifications. Cet écran ne peut pas
              dire quelles langues sont couvertes, et il ne l’invente pas&nbsp;: un calcul sur une
              lecture ratée déclarerait toutes les langues sans guide.
            </div>
          ) : (
            <>
              {lacunes.length > 0 && (
                <div className="banner banner-warn">
                  <b>{lacunes.length} langue{lacunes.length > 1 ? 's' : ''} sans aucun guide
                  qualifié</b> ({lacunes.map((l) => l.langue).join(', ')}). Toute visite demandée
                  dans ces langues basculera sur l’audioguide, avec sa remise — à chaque fois. C’est
                  une lacune de recrutement, pas un aléa d’agenda.
                </div>
              )}

              {couverture.length === 0 ? (
                <div className="empty">
                  Aucune langue déclarée, ni sur un audioguide ni sur un guide.
                </div>
              ) : (
                <div style={{ overflowX: 'auto' }}>
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>Langue</th>
                        <th>Audioguide</th>
                        <th className="num">Guides qualifiés</th>
                        <th>Ce qui se passe</th>
                      </tr>
                    </thead>
                    <tbody>
                      {couverture.map((c) => (
                        <tr key={c.langue}>
                          <td><b>{c.langue}</b></td>
                          <td>{c.audioguide ? 'oui' : <span className="sub">non</span>}</td>
                          <td className="num">{c.guides}</td>
                          <td>
                            {c.guides === 0 && c.audioguide && (
                              <span className="badge warn">bascule certaine</span>
                            )}
                            {c.guides === 0 && !c.audioguide && (
                              <span className="badge crit">ni guide ni audioguide</span>
                            )}
                            {c.guides > 0 && c.audioguide && (
                              <span className="badge good">guide, repli possible</span>
                            )}
                            {c.guides > 0 && !c.audioguide && (
                              <span className="sub">guide seulement — aucun repli</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}
        </div>
      </section>

      {/* ── LA CONFIGURATION ─────────────────────────────────────────────────────────────────── */}
      <section className="card">
        <div className="card-h">
          <h3>Audioguides</h3>
          <span className="sub">
            {audioguides === null ? 'lecture…' : audioguides === undefined ? 'illisible' : `${audioguides.length}`}
          </span>
          {peutConfigurer && (
            <button
              type="button"
              className="btn primary sm"
              style={{ marginLeft: 'auto' }}
              onClick={() => { setErreur(null); setSucces(null); majParams({ audioguide: 'nouveau' }, { pousser: true }) }}
            >
              Déclarer un audioguide
            </button>
          )}
        </div>
        <div className="card-b">
          <div className="sub" style={{ marginBottom: 'var(--esp-normal)' }}>
            Un audioguide est un produit du catalogue — son tarif et sa TVA viennent de là. Ce qui se
            règle ici, ce sont les <b>langues</b> qu’il propose.
          </div>

          {succes && <div className="banner banner-ok">{succes}</div>}
          {erreur && <div className="banner banner-error">{erreur}</div>}

          {audioguides === undefined && (
            <div className="banner banner-warn">
              Les audioguides n’ont pas pu être lus. Ce n’est pas la même chose qu’« aucun ».
            </div>
          )}

          {audioguides === null && <div className="empty">Lecture…</div>}

          {Array.isArray(audioguides) && audioguides.length === 0 && (
            <div className="empty">
              Aucun audioguide déclaré. Sans lui, une visite sans guide disponible n’a pas de repli.
            </div>
          )}

          {Array.isArray(audioguides) && audioguides.length > 0 && (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Produit</th>
                    <th>Langues</th>
                    {peutConfigurer && <th />}
                  </tr>
                </thead>
                <tbody>
                  {audioguides.map((a) => {
                    const nom = nomProduit(a.produit)
                    return (
                      <tr key={a.id}>
                        <td>
                          {nom === undefined
                            ? <span className="sub">catalogue non lu</span>
                            : nom || <span className="sub">produit inconnu</span>}
                        </td>
                        <td>{(a.langues || []).join(', ') || <span className="sub">aucune</span>}</td>
                        {peutConfigurer && (
                          <td>
                            <button
                              type="button"
                              className="btn ghost sm"
                              onClick={() => { setErreur(null); setSucces(null); majParams({ audioguide: String(a.id) }, { pousser: true }) }}
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

      {/* ── CE QUE LA LACUNE A COÛTÉ ─────────────────────────────────────────────────────────── */}
      <section className="card">
        <div className="card-h">
          <h3>Bascules enregistrées</h3>
          <span className="sub">
            {bascules === null ? 'lecture…' : bascules === undefined ? 'illisible' : `${bascules.length}`}
          </span>
        </div>
        <div className="card-b">
          <div className="sub" style={{ marginBottom: 'var(--esp-normal)' }}>
            Une bascule est une visite guidée qui s’est repliée sur l’audioguide faute de guide dans
            la langue demandée. Elle applique une remise&nbsp;: chacune est une recette diminuée.
          </div>

          {bascules === undefined && (
            <div className="banner banner-warn">Les bascules n’ont pas pu être lues.</div>
          )}
          {bascules === null && <div className="empty">Lecture…</div>}
          {Array.isArray(bascules) && bascules.length === 0 && (
            <div className="empty">
              Aucune bascule enregistrée. Ce n’est pas la preuve que le mécanisme fonctionne&nbsp;:
              c’est seulement qu’aucune visite ne s’est encore repliée.
            </div>
          )}
          {Array.isArray(bascules) && bascules.length > 0 && (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th className="num">Remise appliquée</th>
                  </tr>
                </thead>
                <tbody>
                  {bascules.map((b) => (
                    <tr key={b.id}>
                      <td>{b.creeLe ? new Date(b.creeLe).toLocaleDateString('fr-FR') : <span className="sub">—</span>}</td>
                      <td className="num">{b.tauxRemise} %</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </section>
    </div>
  )
}

/**
 * ⚠ AU MOINS UNE LANGUE EST EXIGÉE PAR LE SERVEUR (`Assert\Count(min: 1)`). Le bouton reste donc
 * bloqué tant qu'aucune n'est saisie, et la raison est écrite là où l'on saisit — pas dans un
 * message d'erreur qui arriverait après la saisie.
 */
function EditionAudioguide({ valeurs, produits, onFermer, onFait, onErreur }) {
  const [v, setV] = useState(null)
  const [saisie, setSaisie] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => { setV(valeurs); setSaisie('') }, [valeurs])

  if (!valeurs || !v) return null

  const pret = v.produit !== '' && v.langues.length > 0

  function ajouterLangue() {
    const l = saisie.trim().toLowerCase()
    if (l === '' || v.langues.includes(l)) return
    setV((p) => ({ ...p, langues: [...p.langues, l] }))
    setSaisie('')
  }

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = { produit: `/api/produits/${v.produit}`, langues: v.langues }
      if (v.id) await api.museeMajAudioguide(v.id, { langues: v.langues })
      else await api.museeCreerAudioguide(corps)
      await onFait(v.id ? 'Audioguide enregistré.' : 'Audioguide déclaré.')
    } catch (e) {
      onErreur(e.message || 'L’audioguide n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h2>{v.id ? 'Modifier l’audioguide' : 'Déclarer un audioguide'}</h2>
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Produit *</span>
          {v.id ? (
            // ⚠ LE PRODUIT NE SE CHANGE PAS APRÈS COUP : la contrainte d'unicité le lie à
            // l'audioguide. Proposer de le modifier ferait échouer l'enregistrement sur une règle
            // que l'écran n'aurait pas annoncée.
            <span className="sub">Le produit ne peut pas être changé après création.</span>
          ) : produits === undefined ? (
            <span className="sub">Le catalogue n’a pas pu être lu.</span>
          ) : (
            <select
              className="select"
              value={v.produit}
              onChange={(e) => setV((p) => ({ ...p, produit: e.target.value }))}
            >
              <option value="">— choisir —</option>
              {(produits || []).map((p) => (
                <option key={p.id} value={p.id}>{libelleProduit(p)}</option>
              ))}
            </select>
          )}
        </label>

        <div style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Langues *</span>
          <div style={{ display: 'flex', gap: 'var(--esp-normal)' }}>
            <input
              className="input"
              value={saisie}
              placeholder="fr"
              aria-label="Code de langue à ajouter"
              onChange={(e) => setSaisie(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); ajouterLangue() } }}
            />
            <button type="button" className="btn ghost sm" onClick={ajouterLangue}>Ajouter</button>
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-serre)' }}>
            {v.langues.map((l) => (
              <button
                key={l}
                type="button"
                className="btn ghost sm"
                title="Retirer cette langue"
                onClick={() => setV((p) => ({ ...p, langues: p.langues.filter((x) => x !== l) }))}
              >
                {l} ✕
              </button>
            ))}
          </div>
          <span className="sub">
            Codes ISO à deux lettres (fr, en, es). Au moins une est exigée&nbsp;: un audioguide sans
            langue n’offre aucun repli.
          </span>
        </div>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="button" disabled={busy || !pret} onClick={enregistrer}>
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </>
  )
}
