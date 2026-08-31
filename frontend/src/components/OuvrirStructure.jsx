import { useEffect, useState } from 'react'
import { api } from '../api/client.js'
import Modal from './Modal.jsx'

/**
 * OUVRIR UNE STRUCTURE — le premier geste d'un nouveau client, en un écran.
 *
 * Avant le 28/08 il en fallait cinq, dont deux impossibles : créer une région (aucun écran), créer
 * un établissement (il n'apparaissait pas, faute d'affectation), retaper la dénomination, la forme
 * juridique, le SIRET et l'adresse depuis un extrait Kbis, puis créer un point de vente.
 *
 * Ici : on tape un nom ou un SIREN, l'annuaire officiel propose, on choisit, c'est ouvert.
 *
 * ── CE QUE L'ÉCRAN NE FAIT PAS, ET LE DIT ───────────────────────────────────────────────────────
 *
 * Si l'annuaire ne répond pas, la saisie manuelle reste possible et l'écran l'annonce. Un service
 * externe en panne ne doit pas fermer le produit — c'est la différence entre une aide et une
 * dépendance.
 *
 * ── POURQUOI ON N'AFFICHE NI GROUPE NI RÉGION ───────────────────────────────────────────────────
 *
 * Le modèle exige la chaîne `Groupe → Région → Établissement` : c'est par elle que le fichier client
 * est cloisonné. Elle est créée en silence, du nom de la structure. Un club indépendant n'a que
 * faire de « régions » ; elles apparaîtront le jour où il aura plusieurs sites.
 */
// Les cinq metiers du produit, et ce que chacun allume. Les libelles disent la CONSEQUENCE, pas la
// categorie : « salle de sport » n'apprend rien, « abonnements, controle d'acces, prelevement »
// permet de choisir.
const METIERS = [
  { valeur: 'sport', libelle: 'Salle de sport', allume: 'abonnements, contrôle d’accès, prélèvement SEPA, casiers' },
  { valeur: 'piscine', libelle: 'Piscine', allume: 'contrôle d’accès, cartes multi-entrées, casiers, encadrants' },
  { valeur: 'patinoire', libelle: 'Patinoire', allume: 'contrôle d’accès, location de matériel' },
  { valeur: 'padel', libelle: 'Padel / courts', allume: 'réservation de créneaux, no-show, location' },
  { valeur: 'musee', libelle: 'Musée / site', allume: 'billetterie horodatée, réservation, boutique en ligne' },
]

// Ce que le code NAF laisse deviner. On PROPOSE, on n'impose pas : un meme code couvre parfois deux
// metiers, et le greffe decrit l'activite declaree, pas ce que le logiciel doit faire.
const NAF_VERS_METIER = {
  '93.13Z': 'sport',
  '93.11Z': 'sport',
  '93.12Z': 'sport',
  '93.29Z': 'sport',
  '91.02Z': 'musee',
  '91.03Z': 'musee',
}

export default function OuvrirStructure({ ouvert, onFermer, onOuverte }) {
  const [terme, setTerme] = useState('')
  const [resultats, setResultats] = useState([])
  const [annuaire, setAnnuaire] = useState({ disponible: true, raison: null })
  const [cherche, setCherche] = useState(false)
  const [choisie, setChoisie] = useState(null)
  const [nomCommercial, setNomCommercial] = useState('')
  const [metier, setMetier] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  // Recherche différée : on interroge l'annuaire quand la frappe s'arrête, pas à chaque lettre.
  useEffect(() => {
    if (!ouvert || terme.trim().length < 3 || choisie) return undefined
    const t = setTimeout(async () => {
      setCherche(true)
      setErr(null)
      try {
        const r = await api.chercherEntreprise(terme.trim())
        setResultats(r.resultats || [])
        setAnnuaire({ disponible: r.disponible !== false, raison: r.raison || null })
      } catch (e) {
        setAnnuaire({ disponible: false, raison: 'L’annuaire n’a pas répondu.' })
        setResultats([])
      } finally {
        setCherche(false)
      }
    }, 400)

    return () => clearTimeout(t)
  }, [terme, ouvert, choisie])

  useEffect(() => {
    if (!ouvert) {
      setTerme(''); setResultats([]); setChoisie(null); setErr(null); setNomCommercial(''); setMetier('')
    }
  }, [ouvert])

  async function ouvrir(donnees) {
    setBusy(true)
    setErr(null)
    try {
      const r = await api.ouvrirStructure(donnees)
      onOuverte?.(r)
      onFermer?.()
    } catch (e) {
      setErr(e.message || 'La structure n’a pas pu être ouverte.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={ouvert} onClose={onFermer} titre="Ouvrir une structure" taille="md">
      {!choisie ? (
        <div style={{ display: 'grid', gap: 12 }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Nom de la société, ou son SIREN</label>
            <input
              className="input"
              value={terme}
              onChange={(e) => setTerme(e.target.value)}
              placeholder="GI-ONE FITNESS, ou 812403905"
              autoFocus
            />
            <div className="hint">
              Cherché dans l’annuaire officiel des entreprises. Rien n’est enregistré tant que vous
              n’avez pas choisi.
            </div>
          </div>

          {cherche && <div className="hint">Recherche…</div>}

          {!annuaire.disponible && (
            <div className="banner banner-warn" style={{ margin: 0 }}>
              {annuaire.raison} Vous pouvez ouvrir la structure au nom saisi :
              <button
                className="btn ghost sm"
                type="button"
                style={{ marginLeft: 8 }}
                disabled={busy || terme.trim().length < 2}
                onClick={() => ouvrir({ denomination: terme.trim() })}
              >
                Ouvrir « {terme.trim()} »
              </button>
            </div>
          )}

          {resultats.length > 0 && (
            <div style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr><th>Société</th><th>SIREN</th><th>Siège</th></tr>
                </thead>
                <tbody>
                  {resultats.map((e) => (
                    <tr
                      key={e.siren}
                      className="row-click"
                      onClick={() => { setChoisie(e); setNomCommercial(e.denomination); setMetier(NAF_VERS_METIER[e.codeNaf] || '') }}
                    >
                      <td>
                        {/* ⚠ UN VRAI BOUTON, PAS UN `role` SUR LA LIGNE. Le clic sur `<tr>` reste,
                            mais il ne peut pas être le seul chemin : au clavier, il n'existe pas.
                            Poser `role="button"` sur la ligne casserait la structure du tableau
                            qu'un lecteur d'écran annonce. */}
                        <button
                          type="button"
                          className="lnk nm"
                          onClick={() => { setChoisie(e); setNomCommercial(e.denomination); setMetier(NAF_VERS_METIER[e.codeNaf] || '') }}
                        >
                          {e.denomination}
                        </button>
                      </td>
                      <td className="num">{e.siren}</td>
                      <td>{e.adresse || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {!cherche && terme.trim().length >= 3 && resultats.length === 0 && annuaire.disponible && (
            <div className="hint">
              Aucune société trouvée sous ce nom. Vérifiez l’orthographe légale — elle diffère
              souvent du nom d’enseigne — ou saisissez le SIREN.
            </div>
          )}
        </div>
      ) : (
        <div style={{ display: 'grid', gap: 12 }}>
          <div>
            <div className="nm" style={{ fontSize: 17 }}>{choisie.denomination}</div>
            <div className="hint" style={{ marginTop: 2 }}>
              Raison sociale relevée dans l’annuaire officiel.
            </div>
          </div>

          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <tbody>
                <tr><td>SIREN</td><td className="num">{choisie.siren || '—'}</td></tr>
                <tr><td>SIRET du siège</td><td className="num">{choisie.siret || '—'}</td></tr>
                <tr><td>N° de TVA</td><td className="num">{choisie.numeroTva || '—'}</td></tr>
                <tr><td>Code NAF</td><td className="num">{choisie.codeNaf || '—'}</td></tr>
                <tr><td>Siège</td><td>{choisie.adresse || '—'}</td></tr>
              </tbody>
            </table>
          </div>

          <div className="field" style={{ marginBottom: 0 }}>
            <label>Secteur — décide de ce qui est activé d’office</label>
            <select className="select" value={metier} onChange={(e) => setMetier(e.target.value)}>
              <option value="">Aucune préconfiguration</option>
              {METIERS.map((m) => (
                <option key={m.valeur} value={m.valeur}>{m.libelle}</option>
              ))}
            </select>
            <div className="hint">
              {metier
                ? `Sera activé : ${METIERS.find((m) => m.valeur === metier)?.allume}.`
                : 'Rien ne sera activé d’office. Vous pourrez tout régler ensuite, un réglage à la fois.'}
              {NAF_VERS_METIER[choisie.codeNaf] && metier === NAF_VERS_METIER[choisie.codeNaf] && (
                <> Proposé d’après le code d’activité <strong>{choisie.codeNaf}</strong> déclaré au greffe.</>
              )}
            </div>
          </div>

          <div className="field" style={{ marginBottom: 0 }}>
            <label>Nom commercial — ce que verront vos équipes et vos clients</label>
            <input
              className="input"
              value={nomCommercial}
              onChange={(e) => setNomCommercial(e.target.value)}
            />
            <div className="hint">
              La <strong>raison sociale</strong> ci-dessus reste celle des documents légaux — factures,
              mentions légales, mandats SEPA. Le nom commercial n’y apparaît pas. Laissez-le tel quel
              s’il n’y a pas d’enseigne différente.
            </div>
          </div>

          <div className="hint">
            Un point de vente <strong>« Accueil »</strong> sera créé avec la structure : vous pourrez
            encaisser immédiatement.
          </div>

          {err && <div className="banner banner-error">{err}</div>}

          <div className="r" style={{ gap: 8 }}>
            <button className="btn ghost sm" type="button" onClick={() => setChoisie(null)}>
              Choisir une autre société
            </button>
            <button
              className="btn primary sm"
              type="button"
              disabled={busy}
              onClick={() => ouvrir({ ...choisie, nomCommercial: nomCommercial.trim(), metier })}
            >
              Ouvrir cette structure
            </button>
          </div>
        </div>
      )}
    </Modal>
  )
}
