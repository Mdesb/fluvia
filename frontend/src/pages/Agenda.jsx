import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import CalendrierAgenda, { bornes, iso } from '../components/CalendrierAgenda.jsx'

/**
 * AGENDA — ce qui se passe ici, ce que j'ai à faire, et quand la porte est ouverte.
 *
 * **Trois onglets, et Maxime a tranché lesquels.** Le 28/08 : « les deux, deux onglets » pour le
 * site et pour soi, plus le planning d'ouverture demandé dans le même message. Les réunir ici
 * plutôt que de les disperser tient à une raison simple : ce sont trois façons de regarder le même
 * axe. Un exploitant qui cherche « à quelle heure on ouvre le 15 août » et un qui cherche « qui
 * travaille le 15 août » ouvrent le même écran.
 *
 * **Ce que l'agenda ne possède pas.** Les créneaux de réservation, les créneaux de travail et les
 * plages d'ouverture appartiennent à leurs modules ; l'agenda les LIT. Les recopier créerait une
 * seconde vérité qui dériverait dès la première annulation — un cours annulé la veille resterait
 * affiché, et l'exploitant croirait le logiciel plutôt que son planning.
 *
 * **Une seule requête par période.** `/agenda/journal` agrège côté serveur. Un appel par source,
 * assemblé ici, ferait dépendre l'affichage de l'ordre d'arrivée des réponses.
 */

const VUES = [['mois', 'Mois'], ['semaine', 'Semaine'], ['jour', 'Jour']]

const TYPES = [
  ['meeting', 'Réunion'],
  ['maintenance', 'Intervention'],
  ['training', 'Formation'],
  ['unavailability', 'Indisponibilité'],
  ['other', 'Autre'],
]

function titrePeriode(vue, ancre) {
  if (vue === 'jour') {
    return ancre.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  }
  if (vue === 'semaine') {
    const [d, f] = bornes('semaine', ancre)
    const memeMois = d.getMonth() === f.getMonth()
    return `${d.toLocaleDateString('fr-FR', { day: 'numeric', ...(memeMois ? {} : { month: 'long' }) })} – ${f.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}`
  }
  return ancre.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
}

function decaler(vue, ancre, sens) {
  const d = new Date(ancre)
  if (vue === 'jour') d.setDate(d.getDate() + sens)
  else if (vue === 'semaine') d.setDate(d.getDate() + 7 * sens)
  else d.setMonth(d.getMonth() + sens)
  return d
}

export default function Agenda({ droits = [], etabActif = null }) {
  const [onglet, setOnglet] = useState('site')
  const [vue, setVue] = useState('semaine')
  const [ancre, setAncre] = useState(() => new Date())
  // ⚠ `null` = PAS LU, `[]` = LU ET VIDE. « Rien de programme sur cette periode » sur une
  // lecture refusee annonce une journee libre a quelqu'un qui a peut-etre des creneaux.
  const [evenementsLu, setEvenementsLu] = useState(null)
  // ⚠ `null` NE SORT PAS D'ICI. Il dit « pas lu » et rien d'autre ; tout l'aval — y
  // compris ce qui part en prop vers un enfant — lit un tableau. Sans cette ligne il faut
  // trouver chaque usage, et un usage manque ne se signale que par un ecran mort.
  const evenements = evenementsLu || []
  const [vacances, setVacances] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [ajout, setAjout] = useState(false)
  const [detail, setDetail] = useState(null)

  const portee = onglet === 'moi' ? 'mine' : 'site'
  const [du, au] = useMemo(() => bornes(vue, ancre), [vue, ancre])

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      // Les vacances scolaires sont un CONFORT : elles échouent en silence. Si le ministère ne
      // répond pas, l'agenda doit s'afficher quand même — un fond manquant n'empêche personne de
      // lire sa semaine.
      const [journal, indices] = await Promise.all([
        api.journalAgenda(iso(du), iso(au), portee),
        api.indicesOuverture(iso(du), iso(au)).catch(() => null),
      ])
      setEvenementsLu(journal.events || [])
      setVacances(indices?.schoolHolidays || [])
    } catch (e) {
      setErreur(e.message || 'L’agenda n’a pas pu être chargé.')
      // ⚠ `[]` ici rasait le `null` de l'initialisation, et l'ecran repartait dire « Rien de
      // programme sur cette periode » sur une lecture refusee. Un etat initial honnete ne suffit
      // pas : c'est le chemin d'erreur qui decide de ce qui s'affiche.
      setEvenementsLu(null)
    } finally {
      setChargement(false)
    }
    // ⚠ `etabActif` FIGURE ICI SANS ETRE LU DANS LE CORPS, ET C'EST VOULU. La valeur voyage dans
    // l'en-tête `X-Etablissement` posé par le client HTTP ; ce qui manquait à React, c'est le SIGNAL
    // que la réponse précédente n'est plus valable. Sans lui, changer d'établissement laissait
    // l'agenda du site précédent à l'écran — pas une erreur, des données du mauvais site.
  }, [du, au, portee, etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Agenda</h1>
          <p>Ce qui se passe sur le site, et ce que vous avez à faire</p>
        </div>
        <div className="actions">
          <button className="btn primary" type="button" onClick={() => setAjout(true)}>
            + Ajouter
          </button>
        </div>
      </div>

      {/* DEUX ONGLETS, PLUS TROIS. Le planning d'ouverture est parti dans Paramètres : un agenda
          se consulte plusieurs fois par jour, des horaires se configurent deux fois par an — et la
          case qui peut refuser du monde à la porte n'a rien à faire à un clic d'un écran qu'on
          ouvre pour regarder sa semaine. L'agenda continue de LES LIRE, en fond de vue semaine. */}
      <Tabs
        onglets={[['site', 'Le site'], ['moi', 'Moi']]}
        actif={onglet}
        onChange={setOnglet}
      />

      {erreur && <div className="banner banner-error">{erreur}</div>}

      {(
        <>
          <div className="cal-barre">
            <Tabs onglets={VUES} actif={vue} onChange={setVue} style={{ marginBottom: 0 }} />
            <button className="btn sm" type="button" onClick={() => setAncre(decaler(vue, ancre, -1))} aria-label="Période précédente">‹</button>
            <button className="btn sm" type="button" onClick={() => setAncre(new Date())}>Aujourd’hui</button>
            <button className="btn sm" type="button" onClick={() => setAncre(decaler(vue, ancre, 1))} aria-label="Période suivante">›</button>
            <span className="cal-titre">{titrePeriode(vue, ancre)}</span>
          </div>

          {chargement ? (
            <div className="center" style={{ minHeight: 240 }}><div className="spinner" /></div>
          ) : (
            <div className="card">
              <div className="card-b">
                <CalendrierAgenda vue={vue} ancre={ancre} evenements={evenements} vacances={vacances} onOuvrir={setDetail} />
              </div>
            </div>
          )}

          <AVenir evenements={evenements} portee={portee} nonLu={evenementsLu === null} />
          <AbonnementIcs etabActif={etabActif} />
        </>
      )}

      <AjouterEvenement
        open={ajout}
        portee={portee}
        droits={droits}
        onFermer={() => setAjout(false)}
        onCree={() => {
          setAjout(false)
          recharger()
        }}
      />

      <DetailEvenement
        evenement={detail}
        onFermer={() => setDetail(null)}
        onSupprime={() => {
          setDetail(null)
          recharger()
        }}
      />
    </div>
  )
}

/**
 * « À VENIR » SOUS LE CALENDRIER, ET PAS À LA PLACE.
 *
 * Une grille montre la forme d'une semaine ; elle ne dit pas ce qui vient dans l'heure. Les deux
 * répondent à des questions différentes, et l'une ne remplace pas l'autre — c'est pourquoi Vespera
 * garde aussi sa liste « À venir » sous son calendrier.
 */
function AVenir({ evenements, portee, nonLu }) {
  const maintenant = Date.now()
  const suivants = evenements
    .filter((e) => e.source !== 'opening' && new Date(e.end).getTime() >= maintenant)
    .sort((a, b) => new Date(a.debut) - new Date(b.debut))
    .slice(0, 6)

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>À venir</h3>
        <span className="sub">{suivants.length === 0 ? 'rien de programmé' : `${suivants.length} prochain(s)`}</span>
      </div>
      <div className="card-b">
        {suivants.length === 0 ? (
          // Le message dit ce qui ferait apparaître une ligne, plutôt que « aucun élément ».
          <div className="empty">
            {nonLu
              ? <b>L’agenda n’a pas pu être lu : elle est vide parce que la lecture a échoué, pas parce qu’il n’y a rien à venir.</b>
              : portee === 'mine'
              ? 'Rien de programmé pour vous sur cette période. Vos créneaux de travail et vos événements personnels apparaîtront ici.'
              : 'Rien de programmé sur cette période. Les créneaux de réservation et les événements du site apparaîtront ici.'}
          </div>
        ) : (
          <div className="cal-avenir">
            {suivants.map((e) => (
              <div key={e.id} className="cal-avenir-l">
                <span className={`cal-pastille ${e.type}`} />
                <span className="cal-avenir-q">
                  {new Date(e.start).toLocaleString('fr-FR', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                </span>
                <span className="cal-avenir-t">{e.title}</span>
                {e.detail && <span className="sub">{e.detail}</span>}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

/**
 * L'ABONNEMENT ICS — l'agenda dans Google, Apple ou Outlook.
 *
 * **L'URL est le secret, et l'écran le dit.** Un lien qu'on peut coller n'importe où mérite qu'on
 * écrive ce qu'il donne et comment on le coupe ; sans cette phrase, personne ne saurait qu'il y a
 * quelque chose à protéger. « Régénérer » est présenté comme ce qu'il est : une révocation.
 */
function AbonnementIcs({ etabActif }) {
  const [chemin, setChemin] = useState(null)
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [copie, setCopie] = useState(false)

  const charger = useCallback(async () => {
    setErreur(null)
    try {
      const r = await api.abonnementIcs()
      setChemin(membres(r)[0]?.path || null)
    } catch (e) {
      setErreur(e.message || 'L’abonnement n’a pas pu être lu.')
    }
    // L'abonnement ICS est nominatif ET borné à un établissement : changer de site change l'URL.
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  const url = chemin ? `${window.location.origin}${chemin}` : ''

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Synchroniser avec Google, Apple ou Outlook</h3>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <p className="sub" style={{ marginBottom: 10 }}>
          Collez ce lien dans « S’abonner à un agenda ». Il est <strong>nominatif</strong> et donne à
          lire ce que vous voyez ici : traitez-le comme un mot de passe. Régénérer le coupe
          immédiatement, y compris sur les appareils déjà abonnés.
        </p>
        <div className="cal-ics">
          <input className="input mono" readOnly value={url} onFocus={(e) => e.target.select()} />
          <button
            className="btn sm"
            type="button"
            disabled={!url}
            onClick={async () => {
              try {
                await navigator.clipboard.writeText(url)
                setCopie(true)
                setTimeout(() => setCopie(false), 2000)
              } catch {
                // Le presse-papiers peut être refusé (contexte non sécurisé, permission) : le champ
                // reste sélectionnable à la main, et on ne prétend pas avoir copié.
                setErreur('Copie refusée par le navigateur. Sélectionnez le lien et copiez-le.')
              }
            }}
          >
            {copie ? 'Copié' : 'Copier'}
          </button>
          <button
            className="btn sm"
            type="button"
            disabled={busy}
            onClick={async () => {
              setBusy(true)
              setErreur(null)
              try {
                await api.regenererIcs()
                await charger()
              } catch (e) {
                setErreur(e.message || 'La régénération a échoué.')
              } finally {
                setBusy(false)
              }
            }}
          >
            Régénérer
          </button>
        </div>
      </div>
    </div>
  )
}

function AjouterEvenement({ open, portee, droits, onFermer, onCree }) {
  const [titre, setTitre] = useState('')
  const [debut, setDebut] = useState('')
  const [fin, setFin] = useState('')
  const [type, setType] = useState('meeting')
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  // Écrire dans l'agenda DU SITE est un geste d'exploitant. Le serveur le vérifie ; l'écran ne
  // propose donc pas la case à qui ne l'a pas — une action sans objet est absente, jamais grisée.
  const peutEcrirePourLeSite =
    aLeDroit(droits, 'organisation.gerer') ||
    aLeDroit(droits, 'personnel.gerer') ||
    aLeDroit(droits, 'reservation.gerer_creneau')
  const [duSite, setDuSite] = useState(false)

  useEffect(() => {
    if (!open) return
    const maintenant = new Date()
    maintenant.setMinutes(0, 0, 0)
    const dans1h = new Date(maintenant.getTime() + 3600000)
    const local = (d) => {
      const p = (n) => String(n).padStart(2, '0')
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
    }
    setTitre('')
    setDebut(local(maintenant))
    setFin(local(dans1h))
    setType('meeting')
    setNotes('')
    setErreur(null)
    // La case suit l'onglet d'où l'on vient : ouvrir « + Ajouter » depuis « Le site » propose un
    // événement du site. C'est ce que la personne était en train de regarder.
    setDuSite(portee === 'site' && peutEcrirePourLeSite)
  }, [open, portee, peutEcrirePourLeSite])

  async function envoyer(e) {
    e.preventDefault()
    setBusy(true)
    setErreur(null)
    try {
      await api.creerEvenementAgenda({
        title: titre.trim(),
        // `new Date(<datetime-local>)` interprète la saisie dans le fuseau du navigateur, puis
        // `toISOString()` la rend en UTC : c'est la conversion que le serveur attend, et la faire
        // ici évite d'envoyer une heure sans fuseau que chacun interpréterait à sa façon.
        start: new Date(debut).toISOString(),
        end: new Date(fin).toISOString(),
        type,
        ...(notes.trim() ? { notes: notes.trim() } : {}),
        ...(duSite ? { siteWide: true } : {}),
      })
      onCree()
    } catch (err) {
      setErreur(err.message || 'L’événement n’a pas pu être créé.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={open} onClose={onFermer} titre="Ajouter un événement">
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <div className="field">
          <label htmlFor="ag-titre">Titre *</label>
          <input id="ag-titre" className="input" required value={titre} onChange={(e) => setTitre(e.target.value)} />
          <div className="hint">Ce que quelqu’un doit comprendre en voyant la case, sans l’ouvrir.</div>
        </div>
        <div className="field">
          <label htmlFor="ag-debut">Début *</label>
          <input id="ag-debut" className="input" type="datetime-local" required value={debut} onChange={(e) => setDebut(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="ag-fin">Fin *</label>
          <input id="ag-fin" className="input" type="datetime-local" required value={fin} onChange={(e) => setFin(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="ag-type">Nature</label>
          <select id="ag-type" className="select" value={type} onChange={(e) => setType(e.target.value)}>
            {TYPES.map(([cle, libelle]) => (
              <option key={cle} value={cle}>{libelle}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="ag-notes">Notes</label>
          <textarea id="ag-notes" className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
        {peutEcrirePourLeSite && (
          <div className="field">
            <label className="msgr-note-b" htmlFor="ag-site">
              <input id="ag-site" type="checkbox" checked={duSite} onChange={(e) => setDuSite(e.target.checked)} />
              Événement du site — visible de toute l’équipe
            </label>
            <div className="hint">
              Décoché, l’événement n’appartient qu’à vous : personne d’autre ne le voit, pas même un
              administrateur.
            </div>
          </div>
        )}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="submit" disabled={busy || titre.trim() === ''}>Ajouter</button>
        </div>
      </form>
    </Modal>
  )
}

/**
 * Le détail d'un bloc. **La suppression n'est offerte que sur ce que l'agenda POSSÈDE** : un
 * créneau de réservation ou un créneau de travail se supprime dans son module, avec ses règles
 * (liste d'attente, remboursement, remplacement). Offrir un bouton ici laisserait croire qu'on peut
 * annuler un cours d'un clic sans prévenir les inscrits.
 */
function DetailEvenement({ evenement, onFermer, onSupprime }) {
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)
  const propre = evenement?.source === 'event'

  return (
    <Modal open={!!evenement} onClose={onFermer} titre={evenement?.titre || 'Événement'}>
      {evenement && (
        <div style={{ display: 'grid', gap: 10 }}>
          {erreur && <div className="banner banner-error">{erreur}</div>}
          <div className="sub">
            {new Date(evenement.start).toLocaleString('fr-FR', { dateStyle: 'full', timeStyle: 'short' })}
            {' → '}
            {new Date(evenement.end).toLocaleString('fr-FR', { timeStyle: 'short' })}
          </div>
          {evenement.detail && <div style={{ whiteSpace: 'pre-wrap' }}>{evenement.detail}</div>}
          <div className="sub">
            {evenement.source === 'reservation' && 'Créneau de réservation — se modifie dans Réservation.'}
            {evenement.source === 'shift' && 'Créneau de travail — se modifie dans Personnel.'}
            {evenement.source === 'opening' && 'Plage d’ouverture — se modifie dans l’onglet Ouverture.'}
            {propre && (evenement.scope === 'site' ? 'Événement du site.' : 'Événement personnel.')}
          </div>
          {propre && (
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <button
                className="btn sm"
                type="button"
                disabled={busy}
                onClick={async () => {
                  setBusy(true)
                  setErreur(null)
                  try {
                    await api.supprimerEvenementAgenda(evenement.id)
                    onSupprime()
                  } catch (e) {
                    setErreur(e.message || 'La suppression a échoué.')
                  } finally {
                    setBusy(false)
                  }
                }}
              >
                Supprimer
              </button>
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
