import { useEffect, useState, useCallback } from 'react'
import { api, membres } from '../api/client.js'
import { euros } from '../api/produit.js'
import { euroCentimes } from '../components/Liste.jsx'
import PretAVendre from '../components/PretAVendre.jsx'

function Kpi({ label, valeur, accent, sous }) {
  return (
    <div className="kpi">
      <div className="lbl">{label}</div>
      <div className="val" style={accent ? { color: accent } : undefined}>{valeur}</div>
      {sous && <div className="hint" style={{ marginTop: 2 }}>{sous}</div>}
    </div>
  )
}

// Tableau de bord d'arrivée pour un profil administrateur : agrège des indicateurs du jour et des
// alertes d'exploitation à partir des endpoints existants (Reporting / Compta / Caisse). Chaque
// source est isolée : un périmètre manquant (403) dégrade proprement la carte concernée sans casser
// le reste du tableau.

// Le nom de qui tient la caisse, ou l’aveu qu’on ne peut pas le lire — jamais un tiret, qui
// se lirait « aucun opérateur » alors que le champ est obligatoire en base.
function nomSession(session) {
  const u = session.operateur || session.regisseur
  if (!u) return '—'
  if (typeof u === 'object') {
    const nom = [u.prenom, u.nom].filter(Boolean).join(' ').trim()
    if (nom) return nom
  }
  return 'nom non transmis'
}

export default function Dashboard({ etabActif, etablissements, droits = [], onNav }) {
  const [dash, setDash] = useState(null)
  const [dashInfo, setDashInfo] = useState(null)
  // ⚠ `null` = PAS LU. Il ne sort pas d'ici : tout l'aval lit un tableau.
  const [sessionsLu, setSessionsLu] = useState(null)
  const sessions = sessionsLu || []
  const [regies, setRegies] = useState([])
  const [regieInfo, setRegieInfo] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const nomEtab = etablissements?.find((e) => e.id === etabActif)?.nom || 'Établissement'

  const charger = useCallback(async () => {
    if (!etabActif) return
    setChargement(true)
    setErreur(null)
    setDashInfo(null)
    setRegieInfo(null)

    // Dashboard Reporting (CA jour, fréquentation, jauges FMI, fond de caisse) — peut être 403 si
    // aucun périmètre Reporting n'est rattaché au compte.
    const pDash = api
      .dashboardEtablissement(etabActif)
      .then((d) => setDash(d))
      .catch((e) => {
        setDash(null)
        if (e.status === 403) setDashInfo("Aucun périmètre Reporting rattaché à ce compte : CA et fréquentation indisponibles.")
        else if (e.status === 404) setDashInfo('Tableau de bord Reporting indisponible pour cet établissement.')
        else setErreur(e.message || 'Chargement du tableau de bord impossible.')
      })

    // Sessions de caisse (état ouverte).
    const pSessions = api
      .sessionsCaisse()
      .then((r) => setSessionsLu(membres(r)))
      // ⚠ `[]` faisait dire « aucune session ouverte » a un ecran qui n'avait pas pu regarder.
      // C'est ce qu'on vient verifier en fin de journee.
      .catch(() => setSessionsLu(null))

    // Régies (solde vs plafond d'encaisse) — nécessite compta.lire.
    const pRegies = api
      .regieRecettes()
      .then((r) => setRegies(membres(r)))
      .catch((e) => {
        setRegies([])
        if (e.status === 403) setRegieInfo('Régies indisponibles (droit compta.lire requis).')
      })

    await Promise.all([pDash, pSessions, pRegies])
    setChargement(false)
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  const sessionsOuvertes = sessions.filter((s) => s.etat === 'ouverte' || s.etat === 'Ouverte')
  const jauges = dash?.jaugesFmi || []
  const jaugesAlerte = jauges.filter((j) => j.seuil > 0 && j.valeurCourante >= j.seuil)
  const regiesAuPlafond = regies.filter(
    (r) => (r.plafondEncaisseCentimes || 0) > 0 && (r.soldeEncaisseCentimes || 0) >= (r.plafondEncaisseCentimes || 0),
  )
  const nbAlertes = jaugesAlerte.length + regiesAuPlafond.length
  // Les deux sources d'alerte manquent separement : `dash` porte les jauges FMI, `regies` les
  // plafonds d'encaisse. Un 403 sur l'une ne dit rien de l'autre — on nomme celle qui manque
  // plutot que d'additionner un zero qu'on n'a pas mesure.
  const sourcesManquantes = [!dash && 'jauges', regieInfo && 'régies'].filter(Boolean)

  if (chargement) {
    return (
      <div className="view">
        <div className="view-head"><div className="ttl"><h1>Tableau de bord</h1><p>{nomEtab}</p></div></div>
        <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
      </div>
    )
  }

  return (
    <div className="view">
      {/* Avant tout le reste : quelqu'un qui ne peut pas encore vendre doit l'apprendre ici, pas en
          cherchant dans les Parametres qu'il n'a aucune raison d'ouvrir. Disparait des que les trois
          conditions sont remplies. */}
      <PretAVendre etabActif={etabActif} droits={droits} onAller={() => onNav?.('parametres')} masquerSiComplet />
      <div className="view-head">
        <div className="ttl">
          <h1>Tableau de bord</h1>
          <p>{dash?.etablissementNom || nomEtab} · indicateurs du jour</p>
        </div>
        <div className="actions">
          <button className="btn" onClick={charger}>↻ Rafraîchir</button>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {dashInfo && <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>{dashInfo}</div>}

      <div className="grid g4" style={{ marginBottom: 16 }}>
        <Kpi label="CA encaissé (jour)" valeur={dash ? euros(dash.caJour) : 'n/d'} accent="var(--accent-2)" />
        <Kpi
          label="Fréquentation (jour)"
          valeur={dash ? Number(dash.entreesJour || 0).toLocaleString('fr-FR') : 'n/d'}
        />
        {/* ⚠ AUCUNE COULEUR ICI, ET C'EST DELIBERE. Cette carte portait `--good` des qu'une
            session etait ouverte. Or le vert sert deja, sur la carte voisine, a dire « rien ne
            reclame d'attention » : il disait donc deux choses sur une meme rangee, et un lecteur
            ne pouvait se fier a aucune des deux.

            Une session ouverte n'est ni bonne ni mauvaise — c'est un fait, et son compte le dit
            entierement. Le vert ne veut plus qu'une chose sur cet ecran. */}
        <Kpi
          label="Sessions de caisse ouvertes"
          valeur={sessionsLu === null ? 'n/d' : sessionsOuvertes.length}
          sous={sessionsLu === null ? 'liste non lue' : undefined}
        />
        {/* ⚠ LE VERT DISPARAIT DES QU'UNE SOURCE MANQUE, ET C'EST LUI LE VRAI DEFAUT. Le
            commentaire ci-dessus dit ce qu'il signifie ici : « rien ne reclame d'attention ». Un
            zero se lit ; une couleur se voit sans etre lue. Sur une lecture refusee, cette carte
            montrait le signe du « tout va bien » a quelqu'un qui n'avait rien mesure.
            Les deux sources manquent separement, donc on ne dit ni « 0 » ni « alerte » : on dit
            le compte de ce qu'on a lu, et le nom de ce qui manque. */}
        <Kpi
          label="Alertes"
          valeur={sourcesManquantes.length && !nbAlertes ? 'n/d' : nbAlertes}
          accent={nbAlertes ? 'var(--crit)' : sourcesManquantes.length ? undefined : 'var(--good)'}
          sous={sourcesManquantes.length
            ? `non lu : ${sourcesManquantes.join(' · ')}`
            : nbAlertes ? `${jaugesAlerte.length} jauge(s) · ${regiesAuPlafond.length} régie(s)` : 'aucune'}
        />
      </div>

      {/* ⚠ `g4` ET NON `g2` : ces deux indicateurs sont SECONDAIRES, et une rangee de deux
            colonnes leur donnait 563 px chacun contre 274 aux quatre principaux — deux fois la
            surface, donc deux fois le poids lu. Meme module, deux cellules vides a droite. */}
      <div className="grid g4" style={{ marginBottom: 16 }}>
        <Kpi label="Fond de caisse théorique" valeur={dash ? euros(dash.fondDeCaisse) : 'n/d'} />
        <Kpi label="Espaces suivis (FMI)" valeur={dash ? jauges.length : 'n/d'} />
      </div>

      <div className="resa-grid">
        {/* Alertes d'exploitation */}
        <section className="card">
          <div className="card-h"><h3>Alertes</h3><span className="sub">jauges FMI &amp; régies</span></div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            {nbAlertes === 0 ? (
              <div className="empty">
                {sourcesManquantes.length
                  ? <b>Les alertes n’ont pas pu être lues ({sourcesManquantes.join(' · ')}) :
                      ce n’est pas « aucune alerte », c’est « on n’a pas pu regarder ».</b>
                  : 'Aucune alerte en cours.'}
              </div>
            ) : (
              <table className="tbl">
                <thead>
                  <tr><th>Source</th><th>Objet</th><th className="num">Valeur</th><th className="num">Seuil / plafond</th></tr>
                </thead>
                <tbody>
                  {jaugesAlerte.map((j, i) => (
                    <tr key={`j-${j.espace || j.libelle || i}`}>
                      <td><span className="badge crit">Jauge FMI</span></td>
                      <td><span className="nm">{j.libelle || '—'}</span></td>
                      <td className="num">{j.valeurCourante ?? 0}</td>
                      <td className="num">{j.seuil || '—'}</td>
                    </tr>
                  ))}
                  {regiesAuPlafond.map((r) => (
                    <tr key={`r-${r.id}`}>
                      <td><span className="badge crit">Régie</span></td>
                      <td><span className="nm">{r.libelle || '—'}</span></td>
                      <td className="num">{euroCentimes(r.soldeEncaisseCentimes)}</td>
                      <td className="num">{euroCentimes(r.plafondEncaisseCentimes)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>

        {/* Sessions de caisse ouvertes */}
        <section className="card">
          <div className="card-h"><h3>Sessions de caisse ouvertes</h3></div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            {sessionsOuvertes.length === 0 ? (
              <div className="empty">
                {sessionsLu === null
                  ? <b>La liste des sessions de caisse n’a pas pu être lue : elle est vide parce que
                      la lecture a échoué, pas parce qu’aucune caisse n’est ouverte.</b>
                  : 'Aucune session ouverte.'}
              </div>
            ) : (
              <table className="tbl">
                <thead>
                  <tr><th>N°</th><th>Point de vente</th><th>Caisse</th><th>Opérateur</th></tr>
                </thead>
                <tbody>
                  {sessionsOuvertes.map((s) => (
                    <tr key={s.id}>
                      <td><span className="mono">{s.numero || '—'}</span></td>
                      <td>{s.pointDeVente?.libelle || '—'}</td>
                      <td>{s.caisse?.libelle || '—'}</td>
                      {/* QUI TIENT CETTE CAISSE : la colonne était vide sur CHAQUE ligne.
                          `SessionCaisse.operateur` et `.regisseur` pointent `Utilisateur`, qui
                          n'expose aucune propriété dans le groupe `session:read` — le champ revient
                          en IRI nue. Or la colonne est `nullable: false` en base : il y a TOUJOURS
                          quelqu'un. Un tiret disait donc « personne » là où la réponse est « je ne
                          sais pas le lire », sur le tableau qui sert justement à savoir qui a une
                          caisse ouverte. */}
                      <td>{nomSession(s)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>
      </div>

      {regieInfo && (
        <p className="hint" style={{ marginTop: 12 }}>{regieInfo}</p>
      )}
    </div>
  )
}
