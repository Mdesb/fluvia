import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import Tabs from './Tabs.jsx'
import { euroCentimes, dateHeureFr } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

// LES CAUTIONS : DE L'ARGENT QUI N'EST PAS À NOUS, ET QU'ON DOIT RENDRE.
//
// L'onglet « Cautions » de la comptabilité affichait cinq colonnes : type, référence, montant,
// montant retenu, statut. Il manquait tout ce qui permet de RÉPONDRE À UNE CONTESTATION — et une
// caution retenue se conteste presque toujours.
//
// Les deux questions du client au guichet sont « pourquoi vous avez gardé mon argent ? » et « qui a
// décidé ça ? ». Les deux réponses existaient côté serveur et n'étaient sur aucun écran :
//
//   /api/caution_mouvements       le journal : qui, quand, combien, pourquoi, et si c'était forcé
//   /api/caution_grille_retenues  le barème : la règle publique qui chiffre chaque retenue
//
// LE BARÈME EST MODIFIABLE ICI, LES CAUTIONS NE LE SONT PAS, ET CE N'EST PAS UNE INCOHÉRENCE.
//
// `App\Caution` est un module socle : il n'expose que des lectures. Consigner, retenir et restituer
// sont des gestes des VERTICALES — un casier rendu à la piscine, une raquette au padel, une paire de
// patins à la patinoire — parce que c'est là qu'on constate l'état du matériel. Cet écran est donc
// le registre central : il montre tout, il totalise, il explique, et il renvoie à la verticale pour
// agir. Ne pas y mettre de bouton « retenir » est délibéré : un bouton qui court-circuiterait la
// verticale créerait une retenue sans constat.
//
// Le barème, lui, est bien un paramétrage central : la même casquette perdue vaut le même prix
// partout dans l'établissement, sinon ce n'est pas un barème.
export default function CautionsGestion({ etabActif, droits }) {
  const [onglet, setOnglet] = useState('cautions')
  const [cautions, setCautions] = useState([])
  const [mouvements, setMouvements] = useState([])
  const [grilles, setGrilles] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [grilleEditee, setGrilleEditee] = useState(null)
  const [cautionOuverte, setCautionOuverte] = useState(null)

  const peutParametrer = aLeDroit(droits, 'caution.parametrer')

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    // Trois lectures, trois droits différents (`caution.piloter` pour les cautions et le journal,
    // `caution.lire` pour le barème). Un `Promise.all` aurait fait disparaître le barème d'un profil
    // qui n'a que `caution.lire` — et l'écran entier avec.
    const [c, m, g] = await Promise.allSettled([
      api.cautions(),
      api.cautionMouvements(),
      api.grillesRetenue(),
    ])
    setCautions(c.status === 'fulfilled' ? membres(c.value) : [])
    setMouvements(m.status === 'fulfilled' ? membres(m.value) : [])
    setGrilles(g.status === 'fulfilled' ? membres(g.value) : [])
    if (c.status === 'rejected') setErreur(c.reason?.message || 'Lecture des cautions impossible.')
    setChargement(false)
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

  // LE BARÈME CITÉ PAR UN MOUVEMENT ARRIVE EN IRI, PAS EMBARQUÉ — ET C'EST VÉRIFIÉ, PAS SUPPOSÉ.
  //
  // `GrilleRetenue` ne déclare ses propriétés que dans les groupes `caution_grille:read` et
  // `retenue:read` ; aucune n'est dans `caution_mouvement:read`. Le champ `grilleAppliquee` d'un
  // mouvement est donc la chaîne `/api/caution_grille_retenues/{id}`, et un `m.grilleAppliquee.motif`
  // écrit spontanément aurait valu `undefined` sur CHAQUE ligne — une colonne vide, sans erreur,
  // qu'on aurait lue comme « aucune retenue n'applique de barème ». On résout donc l'IRI contre la
  // liste des barèmes qu'on a déjà chargée.
  const grillesParId = useMemo(() => new Map(grilles.map((g) => [g.id, g])), [grilles])

  const mouvementsParCaution = useMemo(() => {
    const carte = new Map()
    for (const m of mouvements) {
      const id = idDe(m.caution)
      if (!id) continue
      if (!carte.has(id)) carte.set(id, [])
      carte.get(id).push(m)
    }
    return carte
  }, [mouvements])

  // LES TROIS CHIFFRES QUI DISENT L'ÉTAT DE LA CAISSE DES CAUTIONS.
  //
  // « Consigné » est une DETTE : de l'argent encaissé qu'on devra rendre. Le montrer à côté de ce
  // qui a été retenu (donc acquis) évite la lecture la plus coûteuse — prendre le solde des cautions
  // pour une recette.
  const totaux = useMemo(() => {
    let consigne = 0
    let retenu = 0
    let nbOuvertes = 0
    for (const c of cautions) {
      if (c.statut !== 'restituee') {
        consigne += c.montantCentimes || 0
        nbOuvertes += 1
      }
      retenu += c.montantRetenuCentimes || 0
    }
    return { consigne, retenu, nbOuvertes }
  }, [cautions])

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <Tabs
        onglets={[
          ['cautions', `Cautions${cautions.length ? ` (${cautions.length})` : ''}`],
          ['journal', 'Journal'],
          ['bareme', `Barème${grilles.length ? ` (${grilles.length})` : ''}`],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
      ) : (
        <>
          {onglet === 'cautions' && (
            <Cautions
              cautions={cautions}
              totaux={totaux}
              mouvementsParCaution={mouvementsParCaution}
              grillesParId={grillesParId}
              ouverte={cautionOuverte}
              onOuvrir={setCautionOuverte}
            />
          )}

          {onglet === 'journal' && <Journal mouvements={mouvements} grillesParId={grillesParId} />}

          {onglet === 'bareme' && (
            <Bareme
              grilles={grilles}
              peutParametrer={peutParametrer}
              onEditer={setGrilleEditee}
            />
          )}
        </>
      )}

      <GrilleModal
        grille={grilleEditee}
        etabActif={etabActif}
        onClose={() => setGrilleEditee(null)}
        onFait={(m) => {
          setGrilleEditee(null)
          setSucces(m)
          setErreur(null)
          recharger()
        }}
      />
    </>
  )
}

// --- Cautions ------------------------------------------------------------------------------------

function Cautions({ cautions, totaux, mouvementsParCaution, grillesParId, ouverte, onOuvrir }) {
  const [filtre, setFiltre] = useState('ouvertes')

  const visibles = cautions.filter((c) => {
    if (filtre === 'ouvertes') return c.statut !== 'restituee'
    if (filtre === 'retenues') return c.statut === 'retenue_partielle' || c.statut === 'retenue_totale'
    if (filtre === 'restituees') return c.statut === 'restituee'
    return true
  })

  return (
    <>
      <div className="fiche-stats" style={{ marginBottom: 16 }}>
        <div className="stat-tile">
          <div className="st-val num">{euroCentimes(totaux.consigne)}</div>
          <div className="st-lbl">Consigné, à rendre</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">{totaux.nbOuvertes}</div>
          <div className="st-lbl">Cautions en cours</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">{euroCentimes(totaux.retenu)}</div>
          <div className="st-lbl">Retenu à ce jour</div>
        </div>
      </div>

      <section className="card">
        <div className="card-h">
          <h3>Cautions</h3>
          <span className="sub">dépôts et retenues, tous supports confondus</span>
          <div className="r" style={{ marginLeft: 'auto' }}>
            <Tabs
              onglets={[
                ['ouvertes', 'En cours'],
                ['retenues', 'Avec retenue'],
                ['restituees', 'Restituées'],
                ['toutes', 'Toutes'],
              ]}
              actif={filtre}
              onChange={setFiltre}
              style={{ marginBottom: 0 }}
            />
          </div>
        </div>
        <div className="card-b" style={{ overflowX: 'auto' }}>
          {visibles.length === 0 ? (
            <div className="empty">
              {filtre === 'ouvertes'
                ? "Aucune caution en cours. Une caution apparaît ici dès qu'un support est remis contre dépôt — un casier à la piscine, du matériel au padel, des patins à la patinoire."
                : 'Aucune caution dans cette sélection.'}
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Support</th>
                  <th className="num">Déposé</th>
                  <th className="num">Retenu</th>
                  <th className="num">À rendre</th>
                  <th>Encaissé par</th>
                  <th>Depuis</th>
                  <th>Statut</th>
                </tr>
              </thead>
              <tbody>
                {visibles.map((c) => {
                  const journal = mouvementsParCaution.get(c.id) || []
                  const deployee = ouverte === c.id
                  return [
                    <tr
                      key={c.id}
                      className="row-click"
                      onClick={() => onOuvrir(deployee ? null : c.id)}
                    >
                      <td>
                        {/* LE TYPE DE CIBLE EST UN COUPLE OPAQUE, PAS UNE RELATION.
                            `App\Caution` ne dépend d'aucune verticale : la cible est désignée par
                            `typeCible` + `referenceCible` (ex. `piscine.casier` + un UUID), sans
                            jointure. On ne peut donc PAS afficher « casier n°14 » — l'API ne le sait
                            pas. On montre le type en clair et la référence tronquée en `mono`. */}
                        <span className="nm">{mot(c.typeCible)}</span>
                        <div className="sub mono">{String(c.referenceCible || '').slice(0, 8) || '—'}</div>
                      </td>
                      <td className="num">{euroCentimes(c.montantCentimes)}</td>
                      <td className="num">
                        {c.montantRetenuCentimes ? euroCentimes(c.montantRetenuCentimes) : <span className="sub">—</span>}
                      </td>
                      <td className="num">
                        {/* LE CHIFFRE QU'ON VIENT CHERCHER, ET QUE PERSONNE NE CALCULAIT.
                            Le client ne demande ni le dépôt ni la retenue : il demande combien on
                            lui rend. Le laisser faire la soustraction au guichet, c'est la faire
                            faire à voix haute devant lui. */}
                        {c.statut === 'restituee' ? (
                          <span className="sub">rendu</span>
                        ) : (
                          <b>{euroCentimes((c.montantCentimes || 0) - (c.montantRetenuCentimes || 0))}</b>
                        )}
                      </td>
                      <td>{c.moyenEncaissement ? mot(c.moyenEncaissement) : '—'}</td>
                      <td>{c.dateConsignation ? dateHeureFr(c.dateConsignation) : '—'}</td>
                      <td><span className={`badge ${badgeStatut(c.statut)}`}>{mot(c.statut)}</span></td>
                    </tr>,
                    deployee && (
                      <tr key={`${c.id}-detail`}>
                        <td colSpan={7} style={{ background: 'var(--panel-2)' }}>
                          <JournalCaution mouvements={journal} grillesParId={grillesParId} />
                        </td>
                      </tr>
                    ),
                  ]
                })}
              </tbody>
            </table>
          )}
          <div className="hint">
            Cliquez une caution pour lire son journal : qui a retenu, quand, combien et pourquoi.
            <b> Consigner, retenir et restituer se font depuis la verticale</b> (Piscine, Padel,
            Patinoire), là où l&rsquo;état du support est constaté — cet écran est le registre, pas le
            guichet.
          </div>
        </div>
      </section>
    </>
  )
}

// --- Journal -------------------------------------------------------------------------------------

function Journal({ mouvements, grillesParId }) {
  const [type, setType] = useState('tous')
  const visibles = type === 'tous' ? mouvements : mouvements.filter((m) => m.type === type)

  // Les forçages en tête de la conscience de l'exploitant : un mouvement forcé est une décision
  // prise contre la règle, et c'est le premier chiffre qu'un contrôle vient regarder.
  const forces = mouvements.filter((m) => m.forcee).length

  // LA COLONNE « AGENT » S'ALLUME QUAND LE SERVEUR LA REMPLIT, ET PAS AVANT.
  //
  // `MouvementCaution` porte l'agent auteur du geste depuis toujours ; `Utilisateur` ne déclarait
  // aucune propriété dans le groupe `caution_mouvement:read`, si bien que le champ revenait en IRI
  // nue — sans nom. Une colonne entière de tirets, sur un registre dont l'unique raison d'être est
  // de répondre à « qui a décidé de garder mon argent ? », ne dit pas « information indisponible » :
  // elle dit « personne n'a signé ». C'est un mensonge sur la seule question qui compte.
  //
  // Le groupe a été ajouté côté serveur le 28/08. Plutôt que de le supposer déployé partout, on
  // regarde ce qui arrive : si au moins un mouvement porte un nom lisible, la colonne apparaît ;
  // sinon on l'omet et on dit pourquoi sous le tableau.
  const montreAgent = mouvements.some((m) => nomAgent(m.agent))

  return (
    <section className="card">
      <div className="card-h">
        <h3>Journal des cautions</h3>
        <span className="sub">chaque geste, daté et signé</span>
        <div className="r" style={{ marginLeft: 'auto' }}>
          <Tabs
            onglets={[
              ['tous', 'Tous'],
              ['retenue', 'Retenues'],
              ['restitution', 'Restitutions'],
              ['forcage', 'Forçages'],
            ]}
            actif={type}
            onChange={setType}
            style={{ marginBottom: 0 }}
          />
        </div>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {forces > 0 && (
          <div className="banner banner-warn">
            <b>{forces} mouvement{forces > 1 ? 's' : ''} forcé{forces > 1 ? 's' : ''}.</b> Un forçage
            passe outre le barème ou le délai : c&rsquo;est une décision d&rsquo;agent, pas
            l&rsquo;application d&rsquo;une règle, et elle porte son nom.
          </div>
        )}
        {visibles.length === 0 ? (
          <div className="empty">
            Aucun mouvement dans cette sélection. Le journal se remplit depuis les verticales, à
            chaque consignation, retenue ou restitution.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Quand</th>
                <th>Geste</th>
                <th className="num">Montant</th>
                <th>Motif</th>
                <th>Barème appliqué</th>
                {montreAgent && <th>Agent</th>}
              </tr>
            </thead>
            <tbody>
              {visibles.map((m) => {
                const grille = grilleDe(m, grillesParId)
                return (
                  <tr key={m.id}>
                    <td>{dateHeureFr(m.horodatage)}</td>
                    <td>
                      <span className={`badge ${m.type === 'retenue' ? 'warn' : m.type === 'forcage' ? 'crit' : 'mut'}`}>
                        {mot(m.type)}
                      </span>
                      {m.forcee && <div className="sub">forcé</div>}
                    </td>
                    <td className="num">{m.montantCentimes != null ? euroCentimes(m.montantCentimes) : '—'}</td>
                    <td>
                      {m.motif || '—'}
                      {m.delaiForcageJours != null && (
                        <div className="sub">délai forcé de {m.delaiForcageJours} j</div>
                      )}
                    </td>
                    <td>
                      {grille ? (
                        <>
                          {grille.motif}
                          <div className="sub">
                            {mot(grille.mode)} · {euroCentimes(grille.montantCentimes)}
                          </div>
                        </>
                      ) : (
                        // « MONTANT LIBRE » EST UNE INFORMATION, PAS UN BLANC.
                        // Une retenue sans barème est une somme décidée à la main : c'est exactement
                        // ce qu'un client conteste, et ce qu'un contrôle demande à justifier. Un
                        // tiret l'aurait fait passer pour une donnée manquante.
                        <span className="sub">{m.type === 'retenue' ? 'montant libre' : '—'}</span>
                      )}
                    </td>
                    {montreAgent && <td>{nomAgent(m.agent) || <span className="sub">—</span>}</td>}
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
        {visibles.length > 0 && !montreAgent && (
          <div className="hint">
            Le nom de l&rsquo;agent qui a fait chaque geste <b>est enregistré</b> côté serveur, mais
            n&rsquo;est pas rendu par cette version de l&rsquo;API : la colonne serait vide, elle
            n&rsquo;est donc pas affichée plutôt que de faire croire que personne n&rsquo;a signé.
          </div>
        )}
      </div>
    </section>
  )
}

function JournalCaution({ mouvements, grillesParId }) {
  if (mouvements.length === 0) {
    return <div className="empty">Aucun mouvement enregistré sur cette caution.</div>
  }
  return (
    <table className="tbl">
      <thead>
        <tr>
          <th>Quand</th>
          <th>Geste</th>
          <th className="num">Montant</th>
          <th>Motif</th>
        </tr>
      </thead>
      <tbody>
        {mouvements.map((m) => {
          const grille = grilleDe(m, grillesParId)
          return (
            <tr key={m.id}>
              <td>{dateHeureFr(m.horodatage)}</td>
              <td>
                {mot(m.type)}
                {m.forcee && <div className="sub" style={{ color: 'var(--crit)' }}>forcé</div>}
              </td>
              <td className="num">{m.montantCentimes != null ? euroCentimes(m.montantCentimes) : '—'}</td>
              <td>
                {m.motif || '—'}
                {grille && <div className="sub">barème : {grille.motif}</div>}
              </td>
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}

// --- Barème --------------------------------------------------------------------------------------

function Bareme({ grilles, peutParametrer, onEditer }) {
  const actives = grilles.filter((g) => g.actif)
  const inactives = grilles.filter((g) => !g.actif)

  return (
    <section className="card">
      <div className="card-h">
        <h3>Barème de retenue</h3>
        <span className="sub">ce qu&rsquo;on retient, et pour quoi</span>
        {peutParametrer && (
          <div className="r" style={{ marginLeft: 'auto' }}>
            <button className="btn primary sm" type="button" onClick={() => onEditer({})}>
              Ajouter une ligne
            </button>
          </div>
        )}
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {grilles.length === 0 ? (
          <div className="empty">
            Aucun barème. Sans lui, chaque retenue est un montant décidé à la main au guichet — donc
            un montant qui se discute, et qui n&rsquo;est pas le même d&rsquo;un agent à l&rsquo;autre.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Support</th>
                <th>Motif</th>
                <th>Mode</th>
                <th className="num">Montant</th>
                <th>État</th>
                {peutParametrer && <th />}
              </tr>
            </thead>
            <tbody>
              {[...actives, ...inactives].map((g) => (
                <tr key={g.id} style={g.actif ? undefined : { opacity: 0.55 }}>
                  <td>
                    <span className="nm">{mot(g.typeCible)}</span>
                    {g.sousCible && <div className="sub">{g.sousCible}</div>}
                  </td>
                  <td>{g.motif || '—'}</td>
                  <td>
                    {mot(g.mode)}
                    {g.mode === 'valeur_remplacement' && (
                      <div className="sub">le prix de rachat du support</div>
                    )}
                  </td>
                  <td className="num">{euroCentimes(g.montantCentimes)}</td>
                  <td>
                    <span className={`badge ${g.actif ? 'good' : 'mut'}`}>
                      {g.actif ? 'Appliqué' : 'Suspendu'}
                    </span>
                  </td>
                  {peutParametrer && (
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => onEditer(g)}>
                        Modifier
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <div className="hint">
          {/* POURQUOI ON SUSPEND AU LIEU DE SUPPRIMER, ÉCRIT LÀ OÙ ON HÉSITE.
              Le serveur n'expose aucune suppression, et c'est bien ainsi : une ligne de barème est
              CITÉE par les mouvements passés (`grilleAppliquee`). La supprimer effacerait la
              justification de retenues déjà faites — donc la réponse à une contestation. */}
          Une ligne ne se supprime pas, elle se suspend : les retenues déjà faites la citent comme
          justification, et l&rsquo;effacer les rendrait inexplicables.
        </div>
      </div>
    </section>
  )
}

function GrilleModal({ grille, etabActif, onClose, onFait }) {
  const edition = grille && grille.id
  const [typeCible, setTypeCible] = useState('')
  const [sousCible, setSousCible] = useState('')
  const [motif, setMotif] = useState('')
  const [mode, setMode] = useState('forfait')
  const [euros, setEuros] = useState('')
  const [actif, setActif] = useState(true)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!grille) return
    setTypeCible(grille.typeCible || '')
    setSousCible(grille.sousCible || '')
    setMotif(grille.motif || '')
    setMode(grille.mode || 'forfait')
    setEuros(grille.montantCentimes != null ? (grille.montantCentimes / 100).toFixed(2) : '')
    setActif(grille.actif !== false)
    setErreur(null)
  }, [grille])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    setErreur(null)
    try {
      // LA CONVERSION EN CENTIMES SE FAIT ICI ET NULLE PART AILLEURS.
      // `Math.round` et non une troncature : `12.35 * 100` vaut 1234.9999999999998 en flottant, et
      // un `| 0` retiendrait 12,34 € au lieu de 12,35 €. Un centime, mais sur une retenue contestée
      // c'est le genre d'écart qui fait perdre la discussion.
      const montantCentimes = Math.round(parseFloat(String(euros).replace(',', '.')) * 100)
      if (!Number.isFinite(montantCentimes) || montantCentimes < 0) {
        throw new Error('Le montant doit être un nombre positif.')
      }
      const corps = {
        typeCible: typeCible.trim(),
        sousCible: sousCible.trim() || null,
        motif: motif.trim(),
        mode,
        montantCentimes,
        actif,
      }
      if (edition) {
        await api.majGrilleRetenue(grille.id, corps)
      } else {
        await api.creerGrilleRetenue({ ...corps, etablissement: `/api/etablissements/${etabActif}` })
      }
      onFait(edition ? 'Barème modifié.' : 'Ligne de barème ajoutée.')
    } catch (err) {
      setErreur(err.message || "Le barème n'a pas pu être enregistré.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal
      open={!!grille}
      onClose={onClose}
      titre={edition ? 'Modifier une ligne de barème' : 'Ajouter une ligne de barème'}
    >
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="field">
          <label htmlFor="gr-type">Support concerné *</label>
          <input
            id="gr-type"
            className="input mono"
            required
            placeholder="piscine.casier"
            value={typeCible}
            onChange={(e) => setTypeCible(e.target.value)}
          />
          <div className="hint">
            Le code du support tel que la verticale le nomme : <span className="mono">piscine.casier</span>,{' '}
            <span className="mono">padel.materiel</span>, <span className="mono">patinoire.patins</span>.
            Une faute de frappe ne lève aucune erreur — elle crée simplement un barème que rien
            n&rsquo;applique jamais.
          </div>
        </div>

        <div className="field">
          <label htmlFor="gr-sous">Précision (facultatif)</label>
          <input
            id="gr-sous"
            className="input"
            placeholder="grande taille, raquette junior…"
            value={sousCible}
            onChange={(e) => setSousCible(e.target.value)}
          />
        </div>

        <div className="field">
          <label htmlFor="gr-motif">Motif de la retenue *</label>
          <input
            id="gr-motif"
            className="input"
            required
            placeholder="Clé non rendue"
            value={motif}
            onChange={(e) => setMotif(e.target.value)}
          />
          <div className="hint">
            C&rsquo;est la phrase que le client lira sur son reçu et qu&rsquo;il contestera ou non.
            Écrivez-la comme vous la lui diriez.
          </div>
        </div>

        <div className="field">
          <label htmlFor="gr-mode">Mode de calcul</label>
          <select id="gr-mode" className="input" value={mode} onChange={(e) => setMode(e.target.value)}>
            <option value="forfait">Forfait — un montant fixe</option>
            <option value="valeur_remplacement">Valeur de remplacement — ce que coûte le rachat</option>
          </select>
        </div>

        <div className="field">
          <label htmlFor="gr-montant">Montant (€) *</label>
          <input
            id="gr-montant"
            className="input"
            required
            inputMode="decimal"
            placeholder="12,50"
            value={euros}
            onChange={(e) => setEuros(e.target.value)}
          />
        </div>

        <div className="field">
          <label>
            <input type="checkbox" checked={actif} onChange={(e) => setActif(e.target.checked)} />{' '}
            Ligne appliquée
          </label>
          <div className="hint">
            Décochez pour suspendre sans effacer : les retenues déjà faites continueront de la citer
            comme justification.
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button
            className="btn primary"
            type="submit"
            disabled={enCours || !typeCible.trim() || !motif.trim() || euros === ''}
          >
            {enCours ? 'Enregistrement…' : edition ? 'Enregistrer' : 'Ajouter'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// --- Utilitaires ---------------------------------------------------------------------------------

function badgeStatut(statut) {
  if (statut === 'restituee') return 'mut'
  if (statut === 'retenue_totale') return 'crit'
  if (statut === 'retenue_partielle') return 'warn'
  return 'good'
}

// Voir le commentaire jumeau dans `PrelevementsSepa.jsx` : une relation arrive embarquée ou en IRI
// selon les groupes de sérialisation du serveur, et les deux formes sont normales.
function idDe(relation) {
  if (!relation) return null
  if (typeof relation === 'string') return relation.split('/').pop()
  return relation.id || (relation['@id'] ? String(relation['@id']).split('/').pop() : null)
}

// Le barème cité par un mouvement, résolu contre la liste déjà chargée. On accepte aussi la forme
// embarquée : si quelqu'un ajoute un jour `caution_mouvement:read` aux propriétés de `GrilleRetenue`,
// cet écran se mettra à l'utiliser sans qu'on ait à y revenir.
// L'entité `Utilisateur` ne porte qu'un `nom` — pas de `prenom`, vérifié côté serveur. On accepte
// quand même `prenom` s'il apparaît un jour, et `email` en dernier recours : sur un registre de
// contestation, une adresse identifie encore quelqu'un, un tiret non.
function nomAgent(agent) {
  if (!agent || typeof agent === 'string') return null
  const nom = [agent.prenom, agent.nom].filter(Boolean).join(' ').trim()
  return nom || agent.email || null
}

function grilleDe(mouvement, grillesParId) {
  const ref = mouvement.grilleAppliquee
  if (!ref) return null
  if (typeof ref === 'object' && ref.motif) return ref
  return grillesParId.get(idDe(ref)) || null
}
