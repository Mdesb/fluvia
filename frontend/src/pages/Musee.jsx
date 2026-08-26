import { useCallback, useEffect, useState } from 'react'
import Liste, { dateHeureFr } from '../components/Liste.jsx'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

// Musée — soixante-sept opérations exposées, deux appelées jusqu'ici.
//
// L'ÉCRAN COMMENCE PAR LES SALLES, ET CE N'EST PAS UN CHOIX ESTHÉTIQUE.
//
// Un musée se pilote sur une question : **combien de personnes y a-t-il en ce moment, et où ?** Le
// serveur calcule déjà `presents`, `seuil`, la pré-alerte, et un `messageAgent` — une phrase écrite
// pour la personne à l'accueil. Personne ne l'affichait.
//
// Un message destiné à un agent et qui n'atteint pas l'agent n'est pas un message. C'est le même
// motif que les ventes non comptabilisées trouvées dans la clôture : le serveur savait et le disait
// à personne.
//
// LES DEUX MÉTIERS RÉSERVÉS SONT ORGANISÉS AUTOUR DE LEUR CONFIRMATION.
//
// Une visite guidée et un dossier scolaire naissent en **option** et se confirment. Ce sont donc des
// listes de choses en attente, et D55 exige qu'elles s'affichent avec le geste qui les vide — sans
// quoi elles ne descendent jamais et on cesse de les regarder.
//
// Un dossier scolaire porte une `dateOption` : au-delà, l'option devrait tomber. L'écran le signale
// quand la date est passée plutôt que d'afficher une option périmée comme une réservation acquise.

export default function Musee({ etabActif, droits }) {
  const [onglet, setOnglet] = useState('salles')

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Musée</h1>
          <p>Fréquentation des salles, visites guidées &amp; groupes scolaires</p>
        </div>
      </div>

      <Tabs
        onglets={[
          ['salles', 'Salles'],
          ['visites', 'Visites guidées'],
          ['groupes', 'Groupes scolaires'],
          ['expositions', 'Expositions'],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {onglet === 'salles' && <SallesSection etabActif={etabActif} />}
      {onglet === 'visites' && <VisitesSection etabActif={etabActif} droits={droits} />}
      {onglet === 'groupes' && <GroupesSection etabActif={etabActif} droits={droits} />}
      {onglet === 'expositions' && (
        <Liste
          titre="Expositions"
          sous="temporaires et permanentes"
          deps={[etabActif]}
          charger={api.museeExpositions}
          vide="Aucune exposition enregistrée."
          colonnes={[
            { cle: 'titre', entete: 'Exposition', rendu: (r) => <span className="nm">{r.titre || r.nom || '—'}</span> },
            { cle: 'dateDebut', entete: 'Du', rendu: (r) => (r.dateDebut ? String(r.dateDebut).slice(0, 10) : '—') },
            { cle: 'dateFin', entete: 'Au', rendu: (r) => (r.dateFin ? String(r.dateFin).slice(0, 10) : '—') },
          ]}
        />
      )}
    </div>
  )
}

// --------------------------------------------------------------------------------------------
// Les salles, et le message que le serveur écrit pour l'agent.
// --------------------------------------------------------------------------------------------
function SallesSection({ etabActif }) {
  const [salles, setSalles] = useState([])
  const [etats, setEtats] = useState({})
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const liste = membres(await api.museeSalles())
      setSalles(liste)
      // Un appel par salle : l'état est calculé salle par salle côté serveur, il n'existe pas de
      // vue d'ensemble. Acceptable parce qu'un musée compte des dizaines de salles, pas des milliers
      // — et l'échec d'une salle ne doit pas priver des autres.
      const resultats = await Promise.all(
        liste.map((s) => api.museeEtatSalle(s.id).then((e) => [s.id, e]).catch(() => [s.id, null])),
      )
      setEtats(Object.fromEntries(resultats))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  const messages = salles
    .map((s) => ({ salle: s, etat: etats[s.id] }))
    .filter((x) => x.etat?.messageAgent)

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}

      {messages.length > 0 && (
        <div className="banner banner-warn">
          {messages.map((x) => (
            <div key={x.salle.id}>
              <b>{x.salle.nom}</b> — {x.etat.messageAgent}
            </div>
          ))}
        </div>
      )}

      <section className="card">
        <div className="card-h">
          <h3>Fréquentation des salles</h3>
          <span className="sub">présents, seuils et délestage</span>
          <div className="r">
            <button className="btn ghost sm" type="button" onClick={recharger} disabled={chargement}>
              Actualiser
            </button>
          </div>
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 100 }}><div className="spinner" /></div>
          ) : salles.length === 0 ? (
            <div className="empty">
              Aucune salle configurée. Les salles portent les compteurs de présence : sans elles, rien
              ne dit combien de personnes se trouvent dans le musée ni où.
            </div>
          ) : (
            <>
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Salle</th>
                    <th className="num">Présents</th>
                    <th className="num">Seuil</th>
                    <th>État</th>
                    <th>Délestage</th>
                  </tr>
                </thead>
                <tbody>
                  {salles.map((s) => {
                    const e = etats[s.id]
                    return (
                      <tr key={s.id}>
                        <td>
                          <span className="nm">{s.nom || '—'}</span>
                          {s.exposition?.titre && <div className="sub">{s.exposition.titre}</div>}
                        </td>
                        <td className="num">
                          {e ? <b>{e.presents}</b> : <span className="sub">—</span>}
                        </td>
                        <td className="num">{e ? e.seuil || '—' : '—'}</td>
                        <td>
                          {!e ? (
                            <span className="sub">état indisponible</span>
                          ) : e.seuilAtteint ? (
                            <span className="badge crit">seuil atteint</span>
                          ) : e.preAlerteAtteinte ? (
                            <span className="badge warn">pré-alerte</span>
                          ) : (
                            <span className="badge good">normal</span>
                          )}
                        </td>
                        <td>
                          {e?.politiqueDelestageMode ? (
                            <span className="badge info">{mot(e.politiqueDelestageMode)}</span>
                          ) : (
                            <span className="sub">aucune</span>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
              <div className="hint">
                Les compteurs viennent du contrôle d'accès et se rafraîchissent au clic, pas tout
                seuls : un chiffre qui change sans qu'on l'ait demandé fait douter de celui qu'on
                vient de lire à voix haute.
              </div>
            </>
          )}
        </div>
      </section>
    </>
  )
}

// --------------------------------------------------------------------------------------------
// Les visites guidées.
// --------------------------------------------------------------------------------------------
function VisitesSection({ etabActif, droits }) {
  const [visites, setVisites] = useState([])
  const [guides, setGuides] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [nouvelle, setNouvelle] = useState(false)

  const peutGerer = aUnDesDroits(droits, ['musee.gerer_visite', 'musee.gerer'])

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const [v, g] = await Promise.all([api.museeVisitesGuidees(), api.museeGuides()])
      setVisites(membres(v))
      setGuides(membres(g))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function confirmer(v) {
    setErreur(null)
    try {
      await api.museeConfirmerVisite(v.id)
      await recharger()
      setSucces('Visite confirmée.')
    } catch (e) {
      setErreur(e.message || "La confirmation n'a pas abouti.")
    }
  }

  const planifiees = visites.filter((v) => v.statut === 'planifiee')
  const autres = visites.filter((v) => v.statut !== 'planifiee')

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h">
          <h3>Visites à confirmer</h3>
          <span className="sub">
            {planifiees.length === 0 ? 'aucune en attente' : `${planifiees.length} planifiée${planifiees.length > 1 ? 's' : ''}`}
          </span>
          {peutGerer && (
            <div className="r">
              <button className="btn primary sm" type="button" onClick={() => setNouvelle(true)}>
                ＋ Nouvelle visite
              </button>
            </div>
          )}
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : planifiees.length === 0 ? (
            <div className="empty">
              Aucune visite en attente de confirmation. Une visite se planifie avec son thème, sa
              langue et son point de rendez-vous, puis se confirme une fois le guide arrêté.
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Thème</th>
                  <th>Langue</th>
                  <th>Guide</th>
                  <th>Rendez-vous</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {planifiees.map((v) => (
                  <tr key={v.id}>
                    <td><span className="nm">{v.theme || '—'}</span></td>
                    <td>{v.langue || '—'}</td>
                    <td>
                      {v.guide ? (
                        nomGuide(v.guide)
                      ) : (
                        <span className="badge warn" title="Une visite sans guide ne peut pas avoir lieu.">
                          sans guide
                        </span>
                      )}
                    </td>
                    <td>{v.pointRDV || <span className="sub">non précisé</span>}</td>
                    {peutGerer && (
                      <td className="num">
                        <button className="btn primary sm" type="button" onClick={() => confirmer(v)}>
                          Confirmer
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>

      {autres.length > 0 && (
        <section className="card" style={{ marginTop: 16 }}>
          <div className="card-h">
            <h3>Visites confirmées et annulées</h3>
            <span className="sub">pour mémoire</span>
          </div>
          <div className="card-b">
            <table className="tbl">
              <thead>
                <tr><th>Thème</th><th>Langue</th><th>Guide</th><th>État</th></tr>
              </thead>
              <tbody>
                {autres.map((v) => (
                  <tr key={v.id}>
                    <td>{v.theme || '—'}</td>
                    <td>{v.langue || '—'}</td>
                    <td>{v.guide ? nomGuide(v.guide) : '—'}</td>
                    <td>
                      <span className={`badge ${v.statut === 'confirmee' ? 'good' : 'mut'}`}>{mot(v.statut)}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <NouvelleVisiteModal
        open={nouvelle}
        guides={guides}
        onClose={() => setNouvelle(false)}
        onFait={(m) => { setNouvelle(false); setSucces(m); recharger() }}
        onErreur={setErreur}
      />
    </>
  )
}

function NouvelleVisiteModal({ open, guides, onClose, onFait, onErreur }) {
  const [theme, setTheme] = useState('')
  const [langue, setLangue] = useState('fr')
  const [pointRDV, setPointRDV] = useState('')
  const [debut, setDebut] = useState('')
  const [fin, setFin] = useState('')
  const [capacite, setCapacite] = useState('10')
  const [guide, setGuide] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!open) return
    setTheme('')
    setLangue('fr')
    setPointRDV('')
    setDebut('')
    setFin('')
    setCapacite('10')
    setGuide('')
  }, [open])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.museeCreerVisite({
        theme: theme.trim(),
        langue,
        pointRDV: pointRDV.trim(),
        debut,
        fin,
        capacite: Number(capacite),
        ...(guide ? { guide } : {}),
      })
      onFait('Visite planifiée. Elle reste à confirmer.')
    } catch (err) {
      onErreur(err.message || "La visite n'a pas pu être planifiée.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Planifier une visite guidée">
      <form onSubmit={envoyer}>
        <div className="field">
          <label htmlFor="vg-theme">Thème *</label>
          <input id="vg-theme" className="input" required value={theme} placeholder="Les collections égyptiennes" onChange={(e) => setTheme(e.target.value)} />
        </div>

        <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="vg-langue">Langue</label>
            <select id="vg-langue" className="input" value={langue} onChange={(e) => setLangue(e.target.value)}>
              <option value="fr">Français</option>
              <option value="en">Anglais</option>
              <option value="es">Espagnol</option>
              <option value="de">Allemand</option>
              <option value="it">Italien</option>
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="vg-cap">Nombre de places</label>
            <input id="vg-cap" className="input" type="number" min="1" value={capacite} onChange={(e) => setCapacite(e.target.value)} />
          </div>
        </div>

        <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="vg-debut">Début *</label>
            <input id="vg-debut" className="input" type="datetime-local" required value={debut} onChange={(e) => setDebut(e.target.value)} />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="vg-fin">Fin *</label>
            <input id="vg-fin" className="input" type="datetime-local" required value={fin} onChange={(e) => setFin(e.target.value)} />
          </div>
        </div>

        <div className="field">
          <label htmlFor="vg-rdv">Point de rendez-vous</label>
          <input id="vg-rdv" className="input" value={pointRDV} placeholder="Hall d'accueil, sous l'horloge" onChange={(e) => setPointRDV(e.target.value)} />
          <div className="hint">C'est ce qu'on dira au visiteur au téléphone : mieux vaut trop précis que juste.</div>
        </div>

        <div className="field">
          <label htmlFor="vg-guide">Guide</label>
          <select id="vg-guide" className="input" value={guide} onChange={(e) => setGuide(e.target.value)}>
            <option value="">À désigner plus tard</option>
            {guides.map((g) => (
              <option key={g.id} value={g.id}>{nomGuide(g)}</option>
            ))}
          </select>
          <div className="hint">
            Facultatif à la planification. Une visite sans guide reste signalée dans la liste : elle ne
            peut pas avoir lieu en l'état.
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours || !theme.trim() || !debut || !fin}>
            {enCours ? 'Planification…' : 'Planifier'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// --------------------------------------------------------------------------------------------
// Les dossiers de groupes scolaires.
// --------------------------------------------------------------------------------------------
function GroupesSection({ etabActif, droits }) {
  const [dossiers, setDossiers] = useState([])
  const [creneaux, setCreneaux] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [nouveau, setNouveau] = useState(false)
  const [aConfirmer, setAConfirmer] = useState(null)

  const peutGerer = aUnDesDroits(droits, ['musee.gerer_dossier_groupe', 'musee.gerer'])

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const [d, c] = await Promise.all([api.museeDossiersGroupe(), api.reservationCreneaux()])
      setDossiers(membres(d))
      setCreneaux(membres(c))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  const enOption = dossiers.filter((d) => d.statutPaiement === 'en_option')
  const suite = dossiers.filter((d) => d.statutPaiement !== 'en_option')
  const aujourdHui = new Date().toISOString().slice(0, 10)

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h">
          <h3>Dossiers en option</h3>
          <span className="sub">
            {enOption.length === 0 ? 'aucune option en cours' : `${enOption.length} à confirmer`}
          </span>
          {peutGerer && (
            <div className="r">
              <button className="btn primary sm" type="button" onClick={() => setNouveau(true)}>
                ＋ Nouveau dossier
              </button>
            </div>
          )}
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : enOption.length === 0 ? (
            <div className="empty">
              Aucun dossier en option. Une classe réserve d'abord une option — un créneau tenu sans
              engagement — puis le dossier se confirme quand l'école s'engage.
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>École</th>
                  <th className="num">Élèves</th>
                  <th className="num">Accompagnateurs</th>
                  <th>Option jusqu'au</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {enOption.map((d) => {
                  const perimee = d.dateOption && String(d.dateOption).slice(0, 10) < aujourdHui
                  return (
                    <tr key={d.id}>
                      <td><span className="nm">{d.etablissementScolaire || '—'}</span></td>
                      <td className="num">{d.effectif}</td>
                      <td className="num">{d.accompagnateurs}</td>
                      <td>
                        {d.dateOption ? (
                          <>
                            {String(d.dateOption).slice(0, 10)}
                            {perimee && (
                              <div>
                                <span className="badge crit">option échue</span>
                              </div>
                            )}
                          </>
                        ) : (
                          <span className="sub">sans échéance</span>
                        )}
                      </td>
                      {peutGerer && (
                        <td className="num">
                          <button className="btn primary sm" type="button" onClick={() => setAConfirmer(d)}>
                            Confirmer
                          </button>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          )}

          {enOption.some((d) => d.dateOption && String(d.dateOption).slice(0, 10) < aujourdHui) && (
            <div className="banner banner-warn">
              Une ou plusieurs options ont dépassé leur échéance. Le créneau reste tenu tant que le
              dossier n'est pas annulé : vérifiez auprès de l'école avant de le libérer.
            </div>
          )}
        </div>
      </section>

      {suite.length > 0 && (
        <section className="card" style={{ marginTop: 16 }}>
          <div className="card-h">
            <h3>Dossiers engagés</h3>
            <span className="sub">bon de commande, mandat ou payés</span>
          </div>
          <div className="card-b">
            <table className="tbl">
              <thead>
                <tr><th>École</th><th className="num">Élèves</th><th>Paiement</th></tr>
              </thead>
              <tbody>
                {suite.map((d) => (
                  <tr key={d.id}>
                    <td>{d.etablissementScolaire || '—'}</td>
                    <td className="num">{d.effectif}</td>
                    <td>
                      <span className={`badge ${d.statutPaiement === 'paye' ? 'good' : 'info'}`}>
                        {mot(d.statutPaiement)}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <NouveauDossierModal
        open={nouveau}
        creneaux={creneaux}
        onClose={() => setNouveau(false)}
        onFait={(m) => { setNouveau(false); setSucces(m); recharger() }}
        onErreur={setErreur}
      />

      <ConfirmationDossierModal
        dossier={aConfirmer}
        onClose={() => setAConfirmer(null)}
        onFait={(m) => { setAConfirmer(null); setSucces(m); recharger() }}
        onErreur={setErreur}
      />
    </>
  )
}

// Confirmer un dossier : l'engagement de l'école, et le décompte des gratuités.
//
// LES GRATUITÉS SONT LE VRAI SUJET, ET ELLES SE DÉCIDENT ICI.
//
// Un musée accorde un nombre d'entrées gratuites par groupe — souvent aux accompagnateurs, parfois
// aux élèves d'un contingent. Ce décompte fixe ce que l'école paiera : c'est la ligne qui finit sur
// le bon de commande. On le saisit donc au moment où l'on s'engage, pas après.
//
// Les deux champs partent à zéro et non pré-remplis : une gratuité accordée par défaut est une
// gratuité que personne n'a décidée, et elle se découvre à la facturation.
function ConfirmationDossierModal({ dossier, onClose, onFait, onErreur }) {
  const [beneficiaires, setBeneficiaires] = useState([])
  const [contingents, setContingents] = useState([])
  const [responsable, setResponsable] = useState('')
  const [contingent, setContingent] = useState('')
  const [gratuitesEleve, setGratuitesEleve] = useState('0')
  const [gratuitesAccompagnateur, setGratuitesAccompagnateur] = useState('0')
  const [enCours, setEnCours] = useState(false)
  const [chargement, setChargement] = useState(false)

  useEffect(() => {
    if (!dossier) return
    setResponsable('')
    setContingent('')
    setGratuitesEleve('0')
    setGratuitesAccompagnateur('0')
    setChargement(true)
    Promise.all([api.beneficiaires(), api.museeContingentsGratuite()])
      .then(([b, c]) => {
        setBeneficiaires(membres(b))
        setContingents(membres(c))
      })
      .catch(() => {
        setBeneficiaires([])
        setContingents([])
      })
      .finally(() => setChargement(false))
  }, [dossier])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.museeConfirmerDossierGroupe(dossier.id, {
        responsable,
        ...(contingent ? { contingent } : {}),
        nbGratuitesEleve: Number(gratuitesEleve || 0),
        nbGratuitesAccompagnateur: Number(gratuitesAccompagnateur || 0),
      })
      onFait('Dossier confirmé. Il sort des options.')
    } catch (err) {
      onErreur(err.message || "La confirmation n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  const trop =
    Number(gratuitesEleve || 0) > (dossier?.effectif || 0)
    || Number(gratuitesAccompagnateur || 0) > (dossier?.accompagnateurs || 0)

  return (
    <Modal open={!!dossier} onClose={onClose} titre="Confirmer un dossier de groupe">
      {dossier && (
        <form onSubmit={envoyer}>
          <p style={{ marginTop: 0 }}>
            <b>{dossier.etablissementScolaire}</b> — {dossier.effectif} élève
            {dossier.effectif > 1 ? 's' : ''} et {dossier.accompagnateurs} accompagnateur
            {dossier.accompagnateurs > 1 ? 's' : ''}.
          </p>

          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : (
            <>
              <div className="field">
                <label htmlFor="cd-resp">Responsable du groupe *</label>
                <select id="cd-resp" className="input" required value={responsable} onChange={(e) => setResponsable(e.target.value)}>
                  <option value="">Choisir…</option>
                  {beneficiaires.map((b) => (
                    <option key={b.id} value={b.id}>
                      {[b.client?.prenom, b.client?.nom].filter(Boolean).join(' ').trim()
                        || `Bénéficiaire ${String(b.id).slice(0, 8)}`}
                    </option>
                  ))}
                </select>
                <div className="hint">
                  {beneficiaires.length === 0
                    ? "Aucun bénéficiaire enregistré : créez d'abord la fiche de l'enseignant référent."
                    : "L'enseignant qui accompagne : c'est à lui qu'on s'adressera le jour de la visite."}
                </div>
              </div>

              <div className="fiche-sec">Entrées gratuites accordées</div>

              <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                <div className="field" style={{ margin: 0 }}>
                  <label htmlFor="cd-ge">Élèves</label>
                  <input id="cd-ge" className="input" type="number" min="0" value={gratuitesEleve} onChange={(e) => setGratuitesEleve(e.target.value)} />
                </div>
                <div className="field" style={{ margin: 0 }}>
                  <label htmlFor="cd-ga">Accompagnateurs</label>
                  <input id="cd-ga" className="input" type="number" min="0" value={gratuitesAccompagnateur} onChange={(e) => setGratuitesAccompagnateur(e.target.value)} />
                </div>
              </div>

              {trop && (
                <div className="banner banner-warn">
                  Vous accordez plus de gratuités qu'il n'y a de personnes dans le groupe. Le serveur
                  le refusera probablement, et si ce n'est pas le cas, la facture sera fausse.
                </div>
              )}

              {contingents.length > 0 && (
                <div className="field">
                  <label htmlFor="cd-cont">Imputer sur un contingent</label>
                  <select id="cd-cont" className="input" value={contingent} onChange={(e) => setContingent(e.target.value)}>
                    <option value="">Aucun</option>
                    {contingents.map((c) => (
                      <option key={c.id} value={c.id}>{c.libelle || c.nom || String(c.id).slice(0, 8)}</option>
                    ))}
                  </select>
                  <div className="hint">
                    Facultatif. Un contingent est une enveloppe de gratuités décidée à l'année : l'y
                    imputer permet de savoir ce qu'il en reste.
                  </div>
                </div>
              )}

              <div className="hint" style={{ marginTop: 0 }}>
                Les deux compteurs partent à zéro volontairement : une gratuité accordée par défaut
                est une gratuité que personne n'a décidée, et elle se découvre à la facturation.
              </div>
            </>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || chargement || !responsable}>
              {enCours ? 'Confirmation…' : 'Confirmer le dossier'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

function NouveauDossierModal({ open, creneaux, onClose, onFait, onErreur }) {
  const [ecole, setEcole] = useState('')
  const [effectif, setEffectif] = useState('')
  const [accompagnateurs, setAccompagnateurs] = useState('')
  const [creneau, setCreneau] = useState('')
  const [dateOption, setDateOption] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!open) return
    setEcole('')
    setEffectif('')
    setAccompagnateurs('')
    setCreneau('')
    setDateOption('')
  }, [open])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.museeCreerDossierGroupe({
        etablissementScolaire: ecole.trim(),
        effectif: Number(effectif),
        accompagnateurs: Number(accompagnateurs || 0),
        creneauEntree: creneau,
        ...(dateOption ? { dateOption } : {}),
      })
      onFait('Dossier créé en option.')
    } catch (err) {
      onErreur(err.message || "Le dossier n'a pas pu être créé.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Nouveau dossier de groupe scolaire">
      <form onSubmit={envoyer}>
        <div className="field">
          <label htmlFor="dg-ecole">Établissement scolaire *</label>
          <input id="dg-ecole" className="input" required value={ecole} placeholder="Collège Jean-Moulin, Beauvais" onChange={(e) => setEcole(e.target.value)} />
        </div>

        <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="dg-eff">Nombre d'élèves *</label>
            <input id="dg-eff" className="input" type="number" min="1" required value={effectif} onChange={(e) => setEffectif(e.target.value)} />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="dg-acc">Accompagnateurs</label>
            <input id="dg-acc" className="input" type="number" min="0" value={accompagnateurs} onChange={(e) => setAccompagnateurs(e.target.value)} />
          </div>
        </div>

        <div className="field">
          <label htmlFor="dg-creneau">Créneau d'entrée *</label>
          <select id="dg-creneau" className="input" required value={creneau} onChange={(e) => setCreneau(e.target.value)}>
            <option value="">Choisir…</option>
            {creneaux.map((c) => (
              <option key={c.id} value={c.id}>
                {dateHeureFr(c.debut)}
                {c.libelle ? ` — ${c.libelle}` : ''}
              </option>
            ))}
          </select>
          <div className="hint">
            {creneaux.length === 0
              ? "Aucun créneau n'est ouvert : créez-en un dans la réservation avant de poser une option."
              : "L'heure à laquelle le groupe se présente à l'entrée."}
          </div>
        </div>

        <div className="field">
          <label htmlFor="dg-option">Option tenue jusqu'au</label>
          <input id="dg-option" className="input" type="date" value={dateOption} onChange={(e) => setDateOption(e.target.value)} />
          <div className="hint">
            Facultatif. Passée cette date, l'option est signalée comme échue — mais le créneau reste
            tenu tant que le dossier n'est pas annulé : c'est un rappel, pas une libération
            automatique.
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours || !ecole.trim() || !effectif || !creneau}>
            {enCours ? 'Création…' : "Créer l'option"}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// Un guide est un utilisateur : le nom lisible vient de là, et l'identifiant court sert de repli
// plutôt qu'une ligne vide dans un menu déroulant.
function nomGuide(g) {
  const u = g?.utilisateur
  return u?.nomComplet || u?.email || `Guide ${String(g?.id || '').slice(0, 8)}`
}
