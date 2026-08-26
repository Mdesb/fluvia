import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { dateHeureFr } from '../components/Liste.jsx'
import { api, membres } from '../api/client.js'
import { aUnDesDroits } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

// Padel — cinquante-six opérations exposées, une seule appelée jusqu'ici.
//
// UNE PARTIE OUVERTE EST LE PRODUIT, PAS UNE OPTION DE RÉSERVATION.
//
// Un terrain de padel se joue à quatre. Un club dont les terrains partent à deux joueurs perd la
// moitié de sa capacité — et un joueur seul ne réserve pas, faute de trouver trois partenaires.
// C'est la partie ouverte qui résout les deux : on réserve à deux et on publie le créneau.
//
// Elle est donc la première section, avec le nombre de places restantes, et non un drapeau perdu
// dans un formulaire de réservation.
//
// LES DEUX GESTES QUI DEMANDENT UN DROIT PLUS FORT SONT ÉCRITS COMME TELS (D54).
//
// `padel.acces_forcer` pour le repli manuel d'éclairage : allumer un terrain à la main, c'est passer
// outre l'automatisme, et l'oubli d'extinction se paie sur la facture d'électricité. Le motif est
// obligatoire.
//
// `padel.reserver` est distinct de `padel.reserver_soi` : réserver POUR quelqu'un d'autre n'est pas
// réserver pour soi. L'écran ne fait pas la différence à l'affichage — le serveur tranche — mais il
// ne prétend pas non plus que les deux sont la même chose.

export default function Padel({ etabActif, droits }) {
  const [onglet, setOnglet] = useState('terrains')

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Padel</h1>
          <p>Terrains, parties ouvertes &amp; prêt de matériel</p>
        </div>
      </div>

      <Tabs
        onglets={[
          ['terrains', 'Terrains & parties'],
          ['materiel', 'Matériel prêté'],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {onglet === 'terrains' && <TerrainsSection etabActif={etabActif} droits={droits} />}
      {onglet === 'materiel' && <MaterielSection etabActif={etabActif} droits={droits} />}
    </div>
  )
}

// --------------------------------------------------------------------------------------------
// Les terrains, les parties ouvertes, et l'éclairage.
// --------------------------------------------------------------------------------------------
function TerrainsSection({ etabActif, droits }) {
  const [terrains, setTerrains] = useState([])
  const [reservations, setReservations] = useState([])
  const [beneficiaires, setBeneficiaires] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [reservation, setReservation] = useState(null)
  const [eclairage, setEclairage] = useState(null)
  const [rejoindre, setRejoindre] = useState(null)

  const peutReserver = aUnDesDroits(droits, ['padel.reserver', 'padel.reserver_soi', 'padel.gerer'])
  const peutForcerEclairage = aUnDesDroits(droits, ['padel.acces_forcer', 'padel.gerer'])

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const [t, r, b] = await Promise.all([
        api.padelTerrains(),
        api.padelReservations(),
        api.beneficiaires(),
      ])
      setTerrains(membres(t))
      setReservations(membres(r))
      setBeneficiaires(membres(b))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  const ouvertes = useMemo(
    () => reservations.filter((r) => r.ouverte && r.statutPartie !== 'complete'),
    [reservations],
  )

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h">
          <h3>Parties ouvertes</h3>
          <span className="sub">
            {ouvertes.length === 0 ? 'aucune partie cherche des joueurs' : `${ouvertes.length} cherche${ouvertes.length > 1 ? 'nt' : ''} des joueurs`}
          </span>
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : ouvertes.length === 0 ? (
            <div className="empty">
              Aucune partie ouverte. Un terrain de padel se joue à quatre : une partie ouverte permet
              de réserver à deux et de publier le créneau pour que d'autres joueurs le complètent.
              Cochez « partie ouverte » en réservant.
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Terrain</th>
                  <th>Quand</th>
                  <th>Niveau recherché</th>
                  <th>État</th>
                  {peutReserver && <th />}
                </tr>
              </thead>
              <tbody>
                {ouvertes.map((r) => (
                  <tr key={r.id}>
                    <td><span className="nm">{nomTerrain(r.terrain)}</span></td>
                    <td>{r.reservation?.debut ? dateHeureFr(r.reservation.debut) : '—'}</td>
                    <td>
                      {r.niveauViseMin != null || r.niveauViseMax != null ? (
                        `${r.niveauViseMin ?? '?'} à ${r.niveauViseMax ?? '?'}`
                      ) : (
                        <span className="sub">tous niveaux</span>
                      )}
                    </td>
                    <td>
                      <span className={`badge ${r.statutPartie === 'complete' ? 'good' : 'warn'}`}>
                        {r.statutPartie ? mot(r.statutPartie) : 'ouverte'}
                      </span>
                    </td>
                    {peutReserver && (
                      <td className="num">
                        <button className="btn primary sm" type="button" onClick={() => setRejoindre(r)}>
                          Inscrire un joueur
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

      <section className="card" style={{ marginTop: 16 }}>
        <div className="card-h">
          <h3>Terrains</h3>
          <span className="sub">{terrains.length} terrain{terrains.length > 1 ? 's' : ''}</span>
        </div>
        <div className="card-b">
          {terrains.length === 0 ? (
            <div className="empty">
              Aucun terrain déclaré. Sans terrain, aucune réservation n'est possible : ils se créent
              dans le paramétrage, avec leur type et les durées de partie autorisées.
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Terrain</th>
                  <th>Type</th>
                  <th>Durées</th>
                  {(peutReserver || peutForcerEclairage) && <th />}
                </tr>
              </thead>
              <tbody>
                {terrains.map((t) => (
                  <tr key={t.id}>
                    <td><span className="nm">{nomTerrain(t)}</span></td>
                    <td>{t.type ? mot(t.type) : '—'}</td>
                    <td>
                      {(t.dureesAutoriseesMinutes || []).map((d) => `${d} min`).join(' · ') || '—'}
                    </td>
                    {(peutReserver || peutForcerEclairage) && (
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          {peutReserver && (
                            <button className="btn primary sm" type="button" onClick={() => setReservation(t)}>
                              Réserver
                            </button>
                          )}
                          {peutForcerEclairage && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              title="Passer outre l'automatisme d'éclairage."
                              onClick={() => setEclairage(t)}
                            >
                              Éclairage
                            </button>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>

      <ReservationModal
        terrain={reservation}
        beneficiaires={beneficiaires}
        onClose={() => setReservation(null)}
        onFait={(m) => { setReservation(null); setSucces(m); recharger() }}
        onErreur={setErreur}
      />

      <RejoindreModal
        partie={rejoindre}
        beneficiaires={beneficiaires}
        onClose={() => setRejoindre(null)}
        onFait={(m) => { setRejoindre(null); setSucces(m); recharger() }}
        onErreur={setErreur}
      />

      <EclairageModal
        terrain={eclairage}
        onClose={() => setEclairage(null)}
        onFait={(m) => { setEclairage(null); setSucces(m) }}
        onErreur={setErreur}
      />
    </>
  )
}

function ReservationModal({ terrain, beneficiaires, onClose, onFait, onErreur }) {
  const [debut, setDebut] = useState('')
  const [duree, setDuree] = useState('')
  const [organisateur, setOrganisateur] = useState('')
  const [ouverte, setOuverte] = useState(false)
  const [niveauMin, setNiveauMin] = useState('')
  const [niveauMax, setNiveauMax] = useState('')
  const [enCours, setEnCours] = useState(false)

  const durees = terrain?.dureesAutoriseesMinutes || [60, 90]

  useEffect(() => {
    if (!terrain) return
    setDebut('')
    setDuree(String(durees[0] || 60))
    setOrganisateur('')
    setOuverte(false)
    setNiveauMin('')
    setNiveauMax('')
  }, [terrain])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.padelReserverTerrain(terrain.id, {
        debut,
        dureeMinutes: Number(duree),
        organisateur,
        ouverte,
        ...(ouverte && niveauMin !== '' ? { niveauViseMin: Number(niveauMin) } : {}),
        ...(ouverte && niveauMax !== '' ? { niveauViseMax: Number(niveauMax) } : {}),
      })
      onFait(
        ouverte
          ? 'Terrain réservé et partie publiée : d’autres joueurs peuvent la compléter.'
          : 'Terrain réservé.',
      )
    } catch (err) {
      onErreur(err.message || "La réservation n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!terrain} onClose={onClose} titre={terrain ? `Réserver — ${nomTerrain(terrain)}` : ''}>
      {terrain && (
        <form onSubmit={envoyer}>
          <div className="grid" style={{ gridTemplateColumns: '2fr 1fr', gap: 10 }}>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="pd-debut">Début *</label>
              <input id="pd-debut" className="input" type="datetime-local" required value={debut} onChange={(e) => setDebut(e.target.value)} />
            </div>
            <div className="field" style={{ margin: 0 }}>
              <label htmlFor="pd-duree">Durée</label>
              <select id="pd-duree" className="input" value={duree} onChange={(e) => setDuree(e.target.value)}>
                {durees.map((d) => (
                  <option key={d} value={d}>{d} minutes</option>
                ))}
              </select>
              <div className="hint">Les durées proposées sont celles autorisées sur ce terrain.</div>
            </div>
          </div>

          <div className="field">
            <label htmlFor="pd-orga">Organisateur *</label>
            <select id="pd-orga" className="input" required value={organisateur} onChange={(e) => setOrganisateur(e.target.value)}>
              <option value="">Choisir…</option>
              {beneficiaires.map((b) => (
                <option key={b.id} value={b.id}>
                  {[b.client?.prenom, b.client?.nom].filter(Boolean).join(' ').trim()
                    || `Joueur ${String(b.id).slice(0, 8)}`}
                </option>
              ))}
            </select>
            <div className="hint">
              Celui qui réserve et à qui l'on s'adressera. Le prix du terrain est ensuite réparti entre
              les joueurs présents.
            </div>
          </div>

          <div className="field">
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 400 }}>
              <input type="checkbox" checked={ouverte} onChange={(e) => setOuverte(e.target.checked)} />
              Partie ouverte — publier le créneau pour trouver des joueurs
            </label>
            <div className="hint">
              Un terrain se joue à quatre. Publier la partie permet à d'autres joueurs de la compléter,
              plutôt que de laisser deux places vides.
            </div>
          </div>

          {ouverte && (
            <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="pd-nmin">Niveau minimum</label>
                <input id="pd-nmin" className="input" type="number" value={niveauMin} onChange={(e) => setNiveauMin(e.target.value)} />
              </div>
              <div className="field" style={{ margin: 0 }}>
                <label htmlFor="pd-nmax">Niveau maximum</label>
                <input id="pd-nmax" className="input" type="number" value={niveauMax} onChange={(e) => setNiveauMax(e.target.value)} />
              </div>
            </div>
          )}

          {ouverte && (
            <div className="hint" style={{ marginTop: 0 }}>
              Laisser les deux niveaux vides accepte tous les joueurs. Les renseigner évite les parties
              déséquilibrées, qui font arrêter le padel plus sûrement qu'un terrain indisponible.
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !debut || !organisateur}>
              {enCours ? 'Réservation…' : 'Réserver'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

function RejoindreModal({ partie, beneficiaires, onClose, onFait, onErreur }) {
  const [joueur, setJoueur] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (partie) setJoueur('')
  }, [partie])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.padelRejoindrePartie(partie.id, { joueur })
      onFait('Joueur inscrit à la partie.')
    } catch (err) {
      onErreur(err.message || "L'inscription n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!partie} onClose={onClose} titre="Inscrire un joueur à une partie ouverte">
      {partie && (
        <form onSubmit={envoyer}>
          <p style={{ marginTop: 0 }}>
            {nomTerrain(partie.terrain)}
            {partie.reservation?.debut ? ` — ${dateHeureFr(partie.reservation.debut)}` : ''}.
          </p>

          <div className="field">
            <label htmlFor="pd-joueur">Joueur *</label>
            <select id="pd-joueur" className="input" required value={joueur} onChange={(e) => setJoueur(e.target.value)}>
              <option value="">Choisir…</option>
              {beneficiaires.map((b) => (
                <option key={b.id} value={b.id}>
                  {[b.client?.prenom, b.client?.nom].filter(Boolean).join(' ').trim()
                    || `Joueur ${String(b.id).slice(0, 8)}`}
                </option>
              ))}
            </select>
            <div className="hint">
              Sa part du terrain lui sera facturée : le prix se répartit entre les joueurs inscrits.
            </div>
          </div>

          {(partie.niveauViseMin != null || partie.niveauViseMax != null) && (
            <div className="banner banner-warn">
              Cette partie vise un niveau entre <b>{partie.niveauViseMin ?? '?'}</b> et{' '}
              <b>{partie.niveauViseMax ?? '?'}</b>. Le serveur refusera un joueur hors de cette
              fourchette — c'est ce qui évite les parties déséquilibrées.
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !joueur}>
              {enCours ? 'Inscription…' : 'Inscrire'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// Le repli manuel d'éclairage.
//
// Ce n'est pas un interrupteur : c'est le fait de passer outre l'automatisme. On le fait quand la
// commande automatique n'a pas fonctionné, ou pour une partie qui se prolonge — et l'oubli
// d'extinction se lit sur la facture d'électricité, pas sur un écran.
function EclairageModal({ terrain, onClose, onFait, onErreur }) {
  const [action, setAction] = useState('allumage')
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (terrain) { setAction('allumage'); setMotif('') }
  }, [terrain])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.padelEclairageManuel(terrain.id, { action, motif: motif.trim() })
      onFait(action === 'allumage' ? 'Éclairage allumé à la main.' : 'Éclairage éteint à la main.')
    } catch (err) {
      onErreur(err.message || "La commande n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!terrain} onClose={onClose} titre={terrain ? `Éclairage — ${nomTerrain(terrain)}` : ''}>
      {terrain && (
        <form onSubmit={envoyer}>
          <div className="banner banner-warn">
            Vous passez outre l'automatisme. Un allumage manuel <b>ne s'éteint pas tout seul</b> :
            c'est à vous d'éteindre, et l'oubli se lit sur la facture d'électricité.
          </div>

          <div className="fiche-sec" style={{ marginTop: 0 }}>Que faire ?</div>
          {[
            ['allumage', 'Allumer', "La commande automatique n'a pas pris, ou la partie se prolonge."],
            ['extinction', 'Éteindre', 'Le terrain est libéré avant la fin prévue.'],
          ].map(([v, titre, aide]) => (
            <label key={v} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 0', fontWeight: 400 }}>
              <input type="radio" name="ecl" checked={action === v} onChange={() => setAction(v)} style={{ marginTop: 3 }} />
              <span>
                <b>{titre}</b>
                <div className="sub">{aide}</div>
              </span>
            </label>
          ))}

          <div className="field">
            <label htmlFor="pd-ecl-motif">Motif *</label>
            <input
              id="pd-ecl-motif"
              className="input"
              required
              value={motif}
              placeholder="Relais injoignable, partie de 20 h maintenue"
              onChange={(e) => setMotif(e.target.value)}
            />
            <div className="hint">
              Obligatoire et enregistré à votre nom. C'est ce qui permettra de comprendre une
              consommation anormale plutôt que de la découvrir sans explication.
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !motif.trim()}>
              {enCours ? 'Commande…' : action === 'allumage' ? 'Allumer' : 'Éteindre'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// --------------------------------------------------------------------------------------------
// Le matériel prêté.
// --------------------------------------------------------------------------------------------
function MaterielSection({ etabActif, droits }) {
  const [locations, setLocations] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [retour, setRetour] = useState(null)

  const peutGerer = aUnDesDroits(droits, ['padel.gerer_materiel', 'padel.gerer'])

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      setLocations(membres(await api.padelLocationsMateriel()))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  const dehors = locations.filter((l) => !l.dateRetour)

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h">
          <h3>Matériel sorti</h3>
          <span className="sub">{dehors.length} prêt{dehors.length > 1 ? 's' : ''} en cours</span>
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : dehors.length === 0 ? (
            <div className="empty">
              Aucun matériel sorti. Raquettes et balles prêtées avec une réservation apparaissent ici
              jusqu'à leur retour.
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Article</th>
                  <th className="num">Quantité</th>
                  <th>Sorti le</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {dehors.map((l) => (
                  <tr key={l.id}>
                    <td><span className="nm">{l.typeArticle || l.libelle || '—'}</span></td>
                    <td className="num">{l.quantite ?? 1}</td>
                    <td>{l.dateSortie ? dateHeureFr(l.dateSortie) : '—'}</td>
                    {peutGerer && (
                      <td className="num">
                        <button className="btn ghost sm" type="button" onClick={() => setRetour(l)}>
                          Retour
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

      <RetourMaterielModal
        location={retour}
        onClose={() => setRetour(null)}
        onFait={(m) => { setRetour(null); setSucces(m); recharger() }}
        onErreur={setErreur}
      />
    </>
  )
}

function RetourMaterielModal({ location, onClose, onFait, onErreur }) {
  const [statut, setStatut] = useState('rendu')
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (location) { setStatut('rendu'); setMotif('') }
  }, [location])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.padelRetournerMateriel(location.id, {
        statutRetour: statut,
        ...(motif.trim() ? { motif: motif.trim() } : {}),
      })
      onFait(statut === 'rendu' ? 'Matériel rendu.' : 'Matériel déclaré non rendu.')
    } catch (err) {
      onErreur(err.message || "Le retour n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!location} onClose={onClose} titre="Retour de matériel">
      {location && (
        <form onSubmit={envoyer}>
          <div className="fiche-sec" style={{ marginTop: 0 }}>Le matériel est-il revenu ?</div>
          {[
            ['rendu', 'Rendu', 'Le matériel revient au parc et la caution est restituée.'],
            ['non_rendu', 'Non rendu', 'Le matériel est retiré du parc et la caution est retenue.'],
          ].map(([v, titre, effet]) => (
            <label key={v} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 0', fontWeight: 400 }}>
              <input type="radio" name="ret-mat" checked={statut === v} onChange={() => setStatut(v)} style={{ marginTop: 3 }} />
              <span>
                <b>{titre}</b>
                <div className="sub">{effet}</div>
              </span>
            </label>
          ))}

          {statut === 'non_rendu' && (
            <div className="field">
              <label htmlFor="pd-ret-motif">Précision</label>
              <input id="pd-ret-motif" className="input" value={motif} placeholder="Raquette cassée pendant la partie" onChange={(e) => setMotif(e.target.value)} />
              <div className="hint">
                Facultatif, mais c'est ce que lira celui qui traitera la caution — et le client s'il
                conteste.
              </div>
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours}>
              {enCours ? 'Enregistrement…' : 'Enregistrer le retour'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// Un terrain n'a pas de nom propre : il porte une ressource. Le repli sur l'identifiant court évite
// une ligne vide dans un tableau où chaque ligne est un lieu physique.
function nomTerrain(t) {
  return t?.ressource?.libelle || t?.ressource?.nom || t?.libelle || `Terrain ${String(t?.id || '').slice(0, 8)}`
}
