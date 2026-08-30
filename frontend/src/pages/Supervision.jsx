import { useEffect, useRef, useState, useCallback } from 'react'
import { api, membres } from '../api/client.js'
import { GLOSSAIRE } from '../api/vocabulaire.js'
import { aLeDroit, aUnDesDroits } from '../api/droits.js'
import Modal from '../components/Modal.jsx'
import {
  ETAT_CLS,
  ETAT_CONTROLEUR,
  MOTIF_REFUS,
  RESULTAT_CLS,
  RESULTAT_PASSAGE,
  SENS_PASSAGE,
  signeDeVie,
} from '../api/acces.js'
import RechercheBilletModal from '../components/RechercheBilletModal.jsx'

function heure(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function libelleDe(v) {
  if (!v) return '—'
  return typeof v === 'object' ? v.libelle || v.id || '—' : v
}

// Niveau d'alerte d'une jauge FMI vis-à-vis de son seuil.
function niveauJauge(valeur, seuil) {
  if (!seuil || seuil <= 0) return { cls: 'mut', txt: 'sans seuil', pct: 0 }
  const pct = Math.round((valeur / seuil) * 100)
  if (valeur >= seuil) return { cls: 'crit', txt: 'seuil atteint', pct: Math.min(100, pct) }
  if (pct >= 80) return { cls: 'warn', txt: 'proche seuil', pct }
  return { cls: 'good', txt: 'normal', pct }
}

// UN INCIDENT SE LIT, IL NE SE COMPTE PAS.
//
// Le serveur rend trois formes d'incident dans la même liste, et elles n'ont pas les mêmes clés :
// un dépassement de jauge porte `message` + `espace`, un contrôleur muet porte `message` +
// `controleur`, un refus porte `motif`, `codeMotif` et `horodatage` — et NI `message` NI `libelle`.
//
// L'écran affichait `i.libelle || i.message || 'incident'`. Sur des refus, les deux premiers étant
// absents, la ligne se lisait donc : « 10 incident(s) en cours : incident, incident, incident… ».
// Dix fois le mot « incident » et aucune information — juste assez pour inquiéter, jamais assez pour
// agir. Trouvé en ouvrant l'écran, pas en le relisant.
function phraseIncident(i) {
  if (i.type === 'refus') {
    const connu = i.codeMotif ? MOTIF_REFUS[i.codeMotif] : null
    const quoi = connu ? connu.libelle : i.motif || 'refus'
    return `${heure(i.horodatage)} — ${quoi}`
  }
  const ou = i.espace || i.controleur
  return ou ? `${i.message || i.type} (${ou})` : i.message || i.libelle || i.type || 'incident'
}

// Écran Supervision accès (FMI, M3, écran A-03) : état temps réel des jauges, contrôleurs,
// incidents et derniers passages.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// REPRISE DU 29/08 — CE QUE CET ÉCRAN AFFIRMAIT SANS L'AVOIR VÉRIFIÉ
//
// 1. IL COMPTAIT DES ZÉROS QUAND L'APPEL AVAIT ÉCHOUÉ. Sur un 500, les quatre indicateurs
//    affichaient « 0 jauge, 0 contrôleur en ligne, 0 fréquentation » au-dessus du bandeau d'erreur.
//    Zéro contrôleur en ligne et « je n'ai pas pu demander » sont deux affirmations opposées : la
//    première dit qu'on a regardé. Les indicateurs se taisent maintenant quand la lecture a échoué.
//
// 2. IL DISAIT « EN LIGNE » SUR DU MATÉRIEL QUI N'A JAMAIS PARLÉ. L'état vient de la base, le
//    heartbeat du terrain. Voir `signeDeVie()` : jamais vu / vu à l'instant / silencieux depuis N.
//
// 3. IL INTERROGEAIT L'API TOUTES LES 12 SECONDES, ONGLET CACHÉ COMPRIS, et continuait après une
//    session expirée — c'est-à-dire à taper dans le vide en affichant des données périmées comme si
//    elles étaient fraîches. Le battement s'arrête maintenant quand l'onglet est caché et quand le
//    jeton n'est plus valide, et l'écran DIT pourquoi il s'est figé.
//
// 4. IL LISTAIT DES REFUS QUI NE SONT PAS FORCÉMENT DE CE SITE. `SupervisionProvider` filtre bien
//    les jauges et les contrôleurs par établissement actif, mais sa requête des dix derniers refus
//    ne porte aucun filtre. Rien dans la charge utile ne permet de les rattacher à un site : on ne
//    peut donc pas les écarter ici. On le dit, faute de pouvoir le corriger d'ici.
export default function Supervision({ etabActif, droits = [] }) {
  const [sup, setSup] = useState(null)
  const [verifBillet, setVerifBillet] = useState(false)
  const [passages, setPassages] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [sessionPerdue, setSessionPerdue] = useState(false)
  const [maj, setMaj] = useState(null)
  const [auto, setAuto] = useState(true)
  const [equipements, setEquipements] = useState([])
  // { genre: 'manuel'|'comptage', equipement, sens, motif, erreur, enCours }
  const [geste, setGeste] = useState(null)
  const [succes, setSucces] = useState(null)
  const timer = useRef(null)

  const peutOuvrir = aLeDroit(droits, 'acces.ouvrir_manuel')
  const peutCompter = aUnDesDroits(droits, ['acces.superviser', 'acces.controler'])

  const recharger = useCallback(async (silencieux = false) => {
    if (!silencieux) setChargement(true)
    try {
      // Les équipements ne servent qu'aux deux gestes ci-dessous : leur échec (droits, panne) ne
      // doit pas emporter la supervision elle-même, d'où le `catch` séparé.
      const [s, p, e] = await Promise.all([
        api.supervisionAcces(),
        api.passages().catch(() => null),
        api.equipementsAcces().catch(() => null),
      ])
      setSup(s)
      if (e) setEquipements(membres(e))
      // ⚠ CE TRI EST UN FILET, PLUS UN CORRECTIF — ET LA DIFFÉRENCE A UNE DATE.
      //
      // Il a été posé le 29/08 à 01h32 parce que `Passage` n'avait aucun `OrderFilter` : le
      // `order[horodatage]=desc` demandé était ignoré en silence, et cette carte montrait les
      // PREMIERS passages du site sous le titre « Derniers passages ». Le filtre serveur est arrivé
      // à 01h35 — deux minutes et vingt-sept secondes plus tard.
      //
      // On garde le tri : il ne coûte rien sur vingt lignes et il protège d'une régression du
      // filtre. Mais la LÉGENDE qui l'expliquait à l'exploitant, elle, a été retirée : elle
      // annonçait un défaut serveur corrigé le jour même, et elle est restée fausse à l'écran
      // vingt-trois heures. **Une phrase qui décrit un défaut doit mourir avec le défaut** — le
      // commentaire vieillit dans le dépôt où seul un développeur le lit, la légende vieillit sous
      // les yeux de celui qui s'en sert.
      if (p) setPassages(membres(p).sort((a, b) => (a.horodatage < b.horodatage ? 1 : -1)))
      setMaj(new Date())
      setErreur(null)
      setSessionPerdue(false)
    } catch (e) {
      // Une lecture qui échoue n'efface pas la précédente : sur un écran de surveillance, l'état
      // d'il y a douze secondes reste plus utile qu'un écran vide — à condition de dire son âge.
      if (e.status === 401) setSessionPerdue(true)
      // UN REFUS DE DROITS N'EST PAS UNE PANNE, ET « Access Denied » NE LE DIT À PERSONNE.
      //
      // Mesuré le 29/08 sur l'ensemble du front : quatorze écrans ne traitent pas le 403 et ne
      // passent pas par `Liste.jsx`, qui l'explique. Celui-ci en faisait partie — il affichait le
      // message brut du serveur, qui envoie chercher une panne là où il manque un droit.
      setErreur(
        e.status === 403
          ? 'Ce compte n’a pas le droit de superviser les accès sur cet établissement. '
            + 'Ce n’est pas une panne : demandez la permission `acces.superviser` à un administrateur.'
          : e.message || 'Supervision indisponible.',
      )
    } finally {
      if (!silencieux) setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [etabActif, recharger])

  // Poll toutes les 12 s, MAIS : jamais quand l'onglet est caché, jamais après une session expirée.
  //
  // La supervision est l'écran qu'on laisse au mur toute la journée. Sans la pause, c'est de l'ordre
  // de sept mille requêtes par jour et par poste, dont la plupart pendant que personne ne regarde ;
  // et après l'expiration du jeton, c'est autant de 401 servis à un écran qui continue d'afficher
  // les chiffres du matin.
  useEffect(() => {
    if (!auto || sessionPerdue) return undefined
    const battre = () => {
      if (document.visibilityState === 'visible') recharger(true)
    }
    timer.current = setInterval(battre, 12000)
    const auRetour = () => {
      if (document.visibilityState === 'visible') recharger(true)
    }
    document.addEventListener('visibilitychange', auRetour)
    return () => {
      clearInterval(timer.current)
      document.removeEventListener('visibilitychange', auRetour)
    }
  }, [auto, sessionPerdue, recharger])

  function ouvrirGeste(genre) {
    setSucces(null)
    setGeste({
      genre,
      equipement: equipements[0]?.id || '',
      sens: 'entree',
      motif: '',
      erreur: null,
      enCours: false,
    })
  }

  // LE MOTIF EST OBLIGATOIRE, ET CE N'EST PAS UNE FORMALITÉ.
  //
  // Le serveur le refuse vide, mais la vraie raison est ailleurs : un franchissement forcé sans
  // motif est indiscernable d'une fraude au moment où on relit le journal, six mois plus tard. La
  // question n'est pas « qui a ouvert » — l'agent est déjà tracé — c'est « pourquoi il a fallu
  // ouvrir ». C'est cette phrase qui dira si un lecteur est à changer ou une règle à corriger.
  async function envoyerGeste(e) {
    e.preventDefault()
    setGeste((g) => ({ ...g, erreur: null, enCours: true }))
    try {
      const corps = { equipement: geste.equipement, motif: geste.motif.trim(), sens: geste.sens }
      if (geste.genre === 'manuel') await api.ouvertureManuelle(corps)
      else await api.comptageNonNominatif(corps)
      setGeste(null)
      setSucces(
        geste.genre === 'manuel'
          ? 'Ouverture enregistrée : elle figure au journal, avec son motif et votre nom.'
          : 'Passage compté : la jauge et le journal en tiennent compte.',
      )
      await recharger(true)
    } catch (err) {
      // Dans la modale, jamais derrière : un 422 sur le motif se lit là où on vient de le saisir.
      setGeste((g) => ({ ...g, erreur: err.message || "Le geste n'a pas abouti.", enCours: false }))
    }
  }

  const jauges = sup?.jauges || []
  const controleurs = sup?.controleurs || []
  const incidents = sup?.incidents || []
  const enAlerte = jauges.filter((j) => j.seuil > 0 && j.valeurCourante >= j.seuil).length
  const enLigne = controleurs.filter((c) => c.etat === 'en_ligne').length
  const muets = controleurs.filter((c) => signeDeVie(c).suspect).length
  const frequentation = jauges.reduce((s, j) => s + (j.cumulJour || 0), 0)
  const refus = incidents.filter((i) => i.type === 'refus')
  // « Rien n'a été lu » et « rien n'a été trouvé » ne s'écrivent pas pareil.
  const lectureManquee = !sup

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Supervision accès</h1>
          <p>Jauges FMI en temps réel · fréquentation &amp; passages</p>
        </div>
        <div className="actions">
          <span className="hint" style={{ margin: 0 }}>
            {maj ? `Actualisé à ${heure(maj)}` : ''}
          </span>
          <button className={`btn${auto ? ' primary' : ''}`} onClick={() => setAuto((v) => !v)} disabled={sessionPerdue}>
            {auto ? '⏸ Auto' : '▶ Auto'}
          </button>
          <button className="btn" onClick={() => setVerifBillet(true)}>Vérifier un billet</button>
          {peutOuvrir && (
            <button className="btn" onClick={() => ouvrirGeste('manuel')} disabled={equipements.length === 0}>
              Ouvrir manuellement
            </button>
          )}
          {peutCompter && (
            <button className="btn" onClick={() => ouvrirGeste('comptage')} disabled={equipements.length === 0}>
              +1 sans support
            </button>
          )}
          <button className="btn" onClick={() => recharger()}>↻ Rafraîchir</button>
        </div>
      </div>

      {sessionPerdue ? (
        <div className="banner banner-error">
          Votre session a expiré : l’actualisation est arrêtée et rien n’est plus à jour depuis{' '}
          {maj ? heure(maj) : 'la dernière lecture'}. Reconnectez-vous pour reprendre la surveillance.
        </div>
      ) : erreur ? (
        <div className="banner banner-error">
          {erreur}
          {sup && maj ? ` — les chiffres ci-dessous datent de ${heure(maj)}.` : ''}
        </div>
      ) : null}

      {succes && <div className="banner banner-ok">{succes}</div>}

      {chargement && !sup ? (
        <div className="center" style={{ minHeight: 200 }}><div className="spinner" /></div>
      ) : lectureManquee ? (
        // Aucun chiffre : afficher « 0 en ligne » ici affirmerait qu'on a regardé.
        <div className="empty" style={{ padding: 24 }}>
          La supervision n’a pas pu être lue. Rien ne dit que l’installation va mal — seulement qu’on
          ne l’a pas vue. Réessayez, ou vérifiez que le contrôle d’accès est en service sur ce site.
        </div>
      ) : (
        <>
          {/* Indicateurs */}
          <div className="grid g4" style={{ marginBottom: 16 }}>
            <div className="kpi">
              <div className="lbl">Jauges suivies</div>
              <div className="val">{jauges.length}</div>
            </div>
            <div className="kpi">
              <div className="lbl">En alerte</div>
              <div className="val" style={{ color: enAlerte ? 'var(--crit)' : 'var(--good)' }}>{enAlerte}</div>
            </div>
            <div className="kpi">
              <div className="lbl">Contrôleurs en ligne</div>
              <div className="val">{enLigne}<span style={{ fontSize: 15, color: 'var(--ink-faint)' }}> / {controleurs.length}</span></div>
              {/* Le compteur seul est le mensonge le plus commode de cet écran. */}
              {muets > 0 && <div style={{ color: 'var(--warn)' }}>dont {muets} sans signe de vie récent</div>}
            </div>
            <div className="kpi">
              <div className="lbl">Fréquentation (jour)</div>
              <div className="val">{frequentation.toLocaleString('fr-FR')}</div>
            </div>
          </div>

          {incidents.length > 0 && (
            <div className="banner banner-error">
              <div>
                {incidents.length} incident(s) : {incidents.slice(0, 6).map(phraseIncident).join(' · ')}
                {incidents.length > 6 ? ` … et ${incidents.length - 6} autre(s)` : ''}
              </div>
              {refus.length > 0 && (
                <div className="hint" style={{ margin: '6px 0 0' }}>
                  Les {refus.length} refus listés ici ne sont pas cloisonnés par établissement côté
                  serveur : ils peuvent venir d’un autre site. Le journal de Topologie &amp; passages,
                  lui, ne montre que celui-ci.
                </div>
              )}
            </div>
          )}

          <div className="resa-grid">
            {/* Jauges FMI */}
            <section className="card">
              <div className="card-h"><h3>Jauges FMI</h3><span className="sub">valeur / seuil</span></div>
              <div className="card-b">
                {jauges.length === 0 ? (
                  <div className="empty">
                    Aucune jauge configurée. Une jauge se règle en posant un seuil de fréquentation sur
                    un espace, dans Topologie &amp; passages.
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                    {jauges.map((j) => {
                      const n = niveauJauge(j.valeurCourante, j.seuil)
                      return (
                        <div key={j.espace || j.libelle}>
                          <div className="jauge-h">
                            <span className="nm">{j.libelle}</span>
                            <span className={`badge ${n.cls}`}>{n.txt}</span>
                          </div>
                          <div className="bar" style={{ height: 12 }}>
                            <i style={{ width: `${n.pct}%`, background: `var(--${n.cls === 'mut' ? 'accent' : n.cls})` }} />
                          </div>
                          <div className="jauge-f">
                            <span><b style={{ color: 'var(--ink)' }}>{j.valeurCourante}</b> / {j.seuil || '∞'}</span>
                            <span>cumul jour : {j.cumulJour ?? 0}</span>
                            <span className="badge mut">{j.mode === 'blocage' ? 'blocage au seuil' : 'alerte seule'}</span>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>
            </section>

            {/* Contrôleurs */}
            <section className="card">
              <div className="card-h"><h3>Contrôleurs</h3></div>
              <div className="card-b" style={{ overflowX: 'auto' }}>
                <table className="tbl">
                  <thead><tr>
                    <th>Contrôleur</th>
                    <th>État déclaré</th>
                    <th title={GLOSSAIRE.heartbeat}>Signe de vie</th>
                  </tr></thead>
                  <tbody>
                    {controleurs.map((c) => {
                      const vie = signeDeVie(c)
                      return (
                        <tr key={c.id}>
                          <td><span className="nm">{c.libelle}</span></td>
                          <td>
                            <span className={`badge ${ETAT_CLS[c.etat] || 'mut'}`}>
                              {ETAT_CONTROLEUR[c.etat] || c.etat}
                            </span>
                          </td>
                          {/* L'état est déclaré, le signe de vie est constaté. Côte à côte, ils se
                              contredisent au lieu de se confirmer — et c'est ce qu'il faut voir. */}
                          <td className={vie.suspect ? undefined : 'mut'} title={c.dernierHeartbeat || ''}>
                            {vie.texte}
                          </td>
                        </tr>
                      )
                    })}
                    {controleurs.length === 0 && (
                      <tr><td colSpan={3} className="empty">
                        Aucun contrôleur déclaré sur ce site. Rien ne décide donc d’un passage : la
                        déclaration se fait dans Topologie &amp; passages.
                      </td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            </section>
          </div>

          {/* Derniers passages */}
          <section className="card" style={{ marginTop: 16 }}>
            <div className="card-h"><h3>Derniers passages</h3>
              <span className="sub">{passages.length} récents</span></div>
            <div className="card-b" style={{ overflowX: 'auto' }}>
              <table className="tbl">
                <thead>
                  <tr><th>Heure</th><th>Résultat</th><th>Sens</th><th>Espace</th><th>Contrôleur</th><th>Pourquoi</th></tr>
                </thead>
                <tbody>
                  {passages.map((p) => (
                    <tr key={p.id}>
                      <td className="mono">{heure(p.horodatage)}</td>
                      <td><span className={`badge ${RESULTAT_CLS[p.resultat] || 'mut'}`}>{RESULTAT_PASSAGE[p.resultat] || p.resultat}</span></td>
                      <td>{SENS_PASSAGE[p.sens] || p.sens}</td>
                      <td>{libelleDe(p.espace)}</td>
                      <td>{libelleDe(p.controleur)}</td>
                      <td>
                        {p.codeMotif && MOTIF_REFUS[p.codeMotif]
                          ? MOTIF_REFUS[p.codeMotif].libelle
                          : p.motif || p.codeMotif || '—'}
                      </td>
                    </tr>
                  ))}
                  {passages.length === 0 && (
                    <tr><td colSpan={6} className="empty">Aucun passage enregistré pour le moment.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}

      <Modal
        open={!!geste}
        onClose={() => setGeste(null)}
        titre={geste?.genre === 'manuel' ? 'Ouvrir manuellement' : 'Compter un passage sans support'}
      >
        {geste && (
          <form onSubmit={envoyerGeste}>
            <p className="hint" style={{ marginTop: 0 }}>
              {geste.genre === 'manuel'
                ? 'Le franchissement est forcé et tracé : votre nom, l’heure et le motif figureront au journal.'
                : 'Pour qui entre sans badge — un groupe scolaire, un accompagnant. Le passage compte dans la jauge sans être rattaché à personne.'}
            </p>
            {geste.erreur && <div className="banner banner-error">{geste.erreur}</div>}

            <div className="field">
              <label htmlFor="geste-eq">Équipement *</label>
              <select
                id="geste-eq"
                className="select"
                value={geste.equipement}
                onChange={(ev) => setGeste((g) => ({ ...g, equipement: ev.target.value }))}
                required
              >
                {equipements.map((q) => (
                  <option key={q.id} value={q.id}>{q.libelle}</option>
                ))}
              </select>
            </div>

            <div className="field">
              <label htmlFor="geste-sens">Sens</label>
              <select
                id="geste-sens"
                className="select"
                value={geste.sens}
                onChange={(ev) => setGeste((g) => ({ ...g, sens: ev.target.value }))}
              >
                <option value="entree">Entrée</option>
                <option value="sortie">Sortie</option>
              </select>
            </div>

            <div className="field">
              <label htmlFor="geste-motif">Motif *</label>
              <input
                id="geste-motif"
                className="input"
                value={geste.motif}
                onChange={(ev) => setGeste((g) => ({ ...g, motif: ev.target.value }))}
                placeholder={geste.genre === 'manuel' ? 'Badge illisible, porteur identifié au guichet' : 'Groupe scolaire, 24 élèves'}
                required
              />
              <div className="hint" style={{ marginTop: 4 }}>
                Ce qui sera relu dans six mois. « Ouverture » n’explique rien ; « lecteur en panne,
                porteur vérifié au guichet » explique tout.
              </div>
            </div>

            <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
              <button className="btn" type="button" onClick={() => setGeste(null)} disabled={geste.enCours}>Annuler</button>
              <button className="btn primary" type="submit" disabled={geste.enCours || !geste.motif.trim()}>
                {geste.enCours ? 'Enregistrement…' : 'Enregistrer'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      <RechercheBilletModal open={verifBillet} onClose={() => setVerifBillet(false)} droits={droits} />
    </div>
  )
}
