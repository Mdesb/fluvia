import { useCallback, useEffect, useState } from 'react'
import Liste from '../components/Liste.jsx'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import ReversementsOta from '../components/ReversementsOta.jsx'
import { api, membres } from '../api/client.js'
import { allerA } from '../api/url.js'
import AudioguidesMusee from '../components/AudioguidesMusee.jsx'
import { aLeDroit, aUnDesDroits } from '../api/droits.js'
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
          ['audioguides', 'Audioguides'],
          ['reversements', 'Reversements'],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {onglet === 'salles' && <SallesSection etabActif={etabActif} droits={droits} />}
      {onglet === 'audioguides' && <AudioguidesMusee etabActif={etabActif} droits={droits} />}
      {onglet === 'visites' && <VisitesSection etabActif={etabActif} droits={droits} />}
      {onglet === 'groupes' && <GroupesRenvoi />}
      {onglet === 'reversements' && (
        <ReversementsOta etabActif={etabActif} droits={droits} />
      )}

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
function SallesSection({ etabActif, droits }) {
  const peutConfigurer = aLeDroit(droits, 'musee.configurer')
  const [creation, setCreation] = useState(false)
  // ⚠ `null` = PAS LU. << Aucune salle configuree >> est suivi d'une consequence
  // (<< rien ne dit combien de personnes se trouvent dans le musee ni ou >>) et d'un geste
  // (<< Declarez-en une >>). Sur une lecture refusee, on envoie declarer des salles qui existent.
  const [salles, setSalles] = useState(null)
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
      setSalles(null)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  // UNE ALERTE TOUJOURS ALLUMEE EST UNE ALERTE QUE PERSONNE NE LIT.
  //
  // `messageAgent` n'est PAS un événement : c'est le texte configuré de la politique de délestage
  // — la consigne à donner AU MOMENT où la salle sature. `SalleEtatLiveProvider` le rend
  // inconditionnellement, saturation ou non.
  //
  // L ecran le montrait donc en permanence, en bandeau d avertissement en tete de page. Constate
  // sur la preprod : << Salle des sarcophages -- Salle saturee : reguler l entree >> affiche sur
  // une salle a ZERO present pour un seuil de vingt. Le jour ou elle sature vraiment, le bandeau
  // est identique -- donc il ne dit plus rien.
  //
  // Le fournisseur calcule pourtant `seuilAtteint` et `preAlerteAtteinte`, que rien ne lisait.
  // Ce sont eux qui décident si la consigne s'applique MAINTENANT.
  const messages = (salles || [])
    .map((s) => ({ salle: s, etat: etats[s.id] }))
    .filter((x) => x.etat?.messageAgent && (x.etat.seuilAtteint || x.etat.preAlerteAtteinte))

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
            {peutConfigurer && (
              <button className="btn sm" type="button" onClick={() => setCreation(true)}>
                ＋ Déclarer une salle
              </button>
            )}
            <button className="btn ghost sm" type="button" onClick={recharger} disabled={chargement}>
              Actualiser
            </button>
          </div>
        </div>
        <SalleModal
          open={creation}
          onClose={() => setCreation(false)}
          onFait={() => { setCreation(false); recharger() }}
        />
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 100 }}><div className="spinner" /></div>
          ) : salles === null ? (
            <div className="banner banner-error">
              Les salles n’ont pas pu être lues. <b>N’en concluez pas qu’aucune n’est
              configurée</b>&nbsp;: les compteurs de présence existent peut-être, ils n’ont pas été
              obtenus.
            </div>
          ) : salles.length === 0 ? (
            <div className="empty">
              {/* LA PHRASE NE DISAIT MEME PAS OU ALLER. Ses deux voisines — patinoire et padel —
                  envoyaient « dans le paramétrage », qui ne porte rien de tel ; celle-ci ne
                  proposait rien du tout. Les deux se corrigent de la même façon : le geste vient
                  à l'écran qui constate le manque. */}
              Aucune salle configurée. Les salles portent les compteurs de présence : sans elles, rien
              ne dit combien de personnes se trouvent dans le musée ni où.
              {peutConfigurer ? ' Déclarez-en une avec le bouton ci-dessus.' : ''}
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
  const [visites, setVisites] = useState(null)
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
      setVisites(null)
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

  const planifiees = (visites || []).filter((v) => v.statut === 'planifiee')
  const autres = (visites || []).filter((v) => v.statut !== 'planifiee')

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h">
          <h3>Visites à confirmer</h3>
          <span className="sub">
            {visites === null
              ? 'état inconnu — la lecture n’a pas abouti'
              : planifiees.length === 0 ? 'aucune en attente' : `${planifiees.length} planifiée${planifiees.length > 1 ? 's' : ''}`}
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
          ) : visites === null ? (
            <div className="banner banner-error">
              Les visites guidées n’ont pas pu être lues&nbsp;: <b>ne concluez pas qu’aucune n’attend
              d’être confirmée</b>.
            </div>
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
        <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
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

        <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-normal)' }}>
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

        <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-normal)' }}>
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

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end', marginTop: 'var(--esp-large)' }}>
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
// Groupes scolaires — absorbés par le module transverse Groupes (renvoi, absorption Phase B).
// --------------------------------------------------------------------------------------------
function GroupesRenvoi() {
  return (
    <section className="card">
      <div className="card-h">
        <h3>Groupes scolaires</h3>
        <span className="sub">gérés dans le module Groupes</span>
      </div>
      <div className="card-b">
        <div className="empty">
          Les dossiers de groupes scolaires se gèrent désormais dans le module <b>Groupes</b> —
          transverse à tous les métiers. On y crée un groupe, on réserve un créneau au grain
          visiteur (un billet par personne), on accorde des gratuités depuis un contingent, et on facture.
        </div>
        <div className="r" style={{ marginTop: 'var(--esp-normal)' }}>
          <button className="btn primary" type="button" onClick={() => allerA('groupes')}>
            Ouvrir le module Groupes
          </button>
        </div>
      </div>
    </section>
  )
}

function nomGuide(g) {
  const u = g?.utilisateur
  return u?.nomComplet || u?.email || `Guide ${String(g?.id || '').slice(0, 8)}`
}

// DÉCLARER UNE SALLE — même forme que le bassin, et pour la même raison.
//
// `Salle::$espace` est requis (`JoinColumn(nullable: false)`) : une salle de musée s'appuie sur un
// espace du socle, celui qui porte les accès. On CHOISIT donc un espace existant au lieu d'en
// créer un — sinon le musée aurait sa liste de lieux, la piscine la sienne, et personne ne saurait
// laquelle fait foi devant un tourniquet.
//
// Contrat sondé, pas déduit : un POST au corps vide rend 422 et n'écrit rien —
//     nom      This value should not be blank.
//     espace   This value should not be null.
function SalleModal({ open, onClose, onFait }) {
  const [espaces, setEspaces] = useState([])
  const [nom, setNom] = useState('')
  const [espace, setEspace] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return
    setNom(''); setErreur('')
    api.espaces()
      .then((r) => {
        const liste = membres(r)
        setEspaces(liste)
        setEspace(liste[0]?.id || '')
      })
      .catch(() => setEspaces([]))
  }, [open])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.creerSalleMusee({ nom: nom.trim(), espace: `/api/espaces/${espace}` })
      onFait()
    } catch (err) {
      setErreur(err.message || 'La salle n’a pas pu être déclarée.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Déclarer une salle">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        {espaces.length === 0 ? (
          <div className="banner banner-warn">
            Aucun espace n’est déclaré sur cet établissement. Une salle s’appuie sur un espace du
            socle — celui qui porte les accès et les tourniquets. Créez-le d’abord dans
            <b> Paramètres › Espaces</b>, puis revenez ici.
          </div>
        ) : (
          <>
            <div className="field">
              <label htmlFor="sa-nom">Nom de la salle *</label>
              <input id="sa-nom" className="input" value={nom} maxLength={120}
                placeholder="Salle des sarcophages, galerie nord…"
                onChange={(e) => setNom(e.target.value)} />
              <p className="hint">Ce que l’agent lit sur son écran de fréquentation.</p>
            </div>
            <div className="field">
              <label htmlFor="sa-espace">Espace *</label>
              <select id="sa-espace" className="input" value={espace} onChange={(e) => setEspace(e.target.value)}>
                {espaces.map((x) => <option key={x.id} value={x.id}>{x.nom}</option>)}
              </select>
              <p className="hint">
                Le lieu tel qu’il est déclaré dans <b>Paramètres › Espaces</b> : c’est lui qui porte
                les accès, la salle y ajoute le comptage des présents.
              </p>
            </div>
          </>
        )}

        <div className="r" style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi || !nom.trim() || !espace}>
            {envoi ? 'Déclaration…' : 'Déclarer la salle'}
          </button>
        </div>
      </form>
    </Modal>
  )
}
