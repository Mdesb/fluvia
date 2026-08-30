import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import Tabs from './Tabs.jsx'
import ClientPicker from './ClientPicker.jsx'
import { euroCentimes, dateFr, dateHeureFr } from './Liste.jsx'
import { api, membres, tokenStore, etablissementStore } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

// LE PRÉLÈVEMENT SEPA, DE BOUT EN BOUT — ET IL N'EN EXISTAIT QUE LE MILIEU.
//
// L'onglet « SEPA » de la comptabilité montrait trois tableaux : les remises envoyées, les mandats
// qui les autorisent, les rejets que la banque renvoie. Trois lectures, aucune écriture. Or les
// quatre opérations d'écriture existent côté serveur DEPUIS LE DÉBUT et n'avaient aucun appelant :
//
//   POST /api/sepa/mandats            signer un mandat
//   POST /api/sepa/remises/generer    composer la remise du jour et son fichier pain.008
//   POST /api/rejet_sepas             enregistrer un retour de la banque
//   POST/PATCH /api/config_creancier_sepas   déclarer le créancier
//
// Un exploitant pouvait donc REGARDER sa chaîne de prélèvement et n'en actionner aucun maillon.
//
// L'ORDRE DES ONGLETS EST L'ORDRE DU MÉTIER, et il commence par le créancier.
//
// Sans configuration créancier (ICS, nom, IBAN de collecte), `GenerationRemiseHandler` n'a rien avec
// quoi composer un pain.008 : aucune remise n'est possible. C'est donc la première chose à remplir,
// et la seule dont l'absence rend tout le reste inerte — d'où le bandeau en haut de l'écran, qui ne
// se contente pas de le dire mais emmène sur l'onglet où le corriger.
//
// Les mandats viennent ensuite (on ne prélève personne sans son autorisation signée), puis les
// remises (ce qu'on envoie à la banque), puis les rejets (ce qu'elle renvoie). Un rejet ouvre un
// impayé : c'est la porte de sortie de cet écran vers « Recouvrement », et elle est écrite en toutes
// lettres au bas de l'onglet des rejets, parce que rien dans le mot « rejet » ne dit qu'un accès va
// se fermer.
export default function PrelevementsSepa({ etabActif, droits }) {
  const [onglet, setOnglet] = useState('mandats')
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const [mandats, setMandats] = useState([])
  const [remises, setRemises] = useState([])
  const [lignes, setLignes] = useState([])
  const [rejets, setRejets] = useState([])
  const [config, setConfig] = useState(null)
  const [chargement, setChargement] = useState(true)
  // Les collections dont le serveur a rendu MOINS de lignes qu'il n'en existe. Voir le bandeau et
  // le commentaire de `tronquee()`, en bas de fichier : ici, une liste incomplète ne rend pas
  // l'écran incomplet, elle le rend FAUX.
  const [tronquees, setTronquees] = useState([])

  const [creationMandat, setCreationMandat] = useState(false)
  const [generation, setGeneration] = useState(false)
  const [rejetSur, setRejetSur] = useState(null)
  const [editionConfig, setEditionConfig] = useState(false)

  const peutGerer = aLeDroit(droits, 'sepa.gerer') || aLeDroit(droits, 'compta.gerer')

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    // LES CINQ LECTURES SONT INDÉPENDANTES, ET AUCUNE NE DOIT EN CACHER UNE AUTRE.
    //
    // `Promise.all` aurait rejeté au premier 403 : un profil qui peut lire les mandats mais pas la
    // configuration créancier se serait retrouvé devant un écran entièrement vide, avec une erreur
    // qui accuse le mauvais tableau. On récupère donc chaque liste séparément, et une lecture
    // refusée ne vide que la sienne.
    const [m, r, l, j, c] = await Promise.allSettled([
      api.mandatsSepa(),
      api.remisesSepa(),
      api.lignesRemiseSepa(),
      api.rejetsSepa(),
      api.configsCreancierSepa(),
    ])
    setMandats(m.status === 'fulfilled' ? membres(m.value) : [])
    setRemises(r.status === 'fulfilled' ? membres(r.value) : [])
    setLignes(l.status === 'fulfilled' ? membres(l.value) : [])
    setRejets(j.status === 'fulfilled' ? membres(j.value) : [])
    // LA CONFIGURATION DE **CET** ÉTABLISSEMENT, PAS LA PREMIÈRE DE LA LISTE.
    //
    // Constaté contre la préprod le 28/08 : `/api/config_creancier_sepas` a rendu DEUX
    // configurations — celle de Piscine A et celle de Patinoire B — sur une requête portant
    // `X-Etablissement: Piscine A`. Prendre `[0]` marchait par chance ce jour-là, et aurait affiché
    // l'ICS et l'IBAN de collecte d'un AUTRE site le jour où l'ordre change. Sur un écran dont tout
    // l'objet est « sur quel compte l'argent arrive », c'est le pire endroit pour se tromper.
    //
    // Le cloisonnement par établissement actif est en cours de bascule côté serveur ; quand il sera
    // en place la liste n'en rendra qu'une, et ce filtre deviendra une redondance inoffensive. Il
    // reste écrit : un écran ne doit pas dépendre d'un filtrage qu'il ne fait pas lui-même.
    setConfig(c.status === 'fulfilled' ? configDeLEtablissement(membres(c.value), etabActif) : null)

    setTronquees(
      [
        tronquee(m, 'les mandats'),
        tronquee(l, 'le détail des remises'),
        tronquee(j, 'les rejets'),
      ].filter(Boolean),
    )

    // On ne signale que l'échec de la lecture PRINCIPALE de l'onglet le plus consulté : signaler les
    // cinq ferait cinq bandeaux pour un seul incident réseau.
    if (m.status === 'rejected') setErreur(m.reason?.message || 'Lecture des mandats impossible.')
    setChargement(false)
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

  // Les lignes d'une remise, regroupées une fois pour toutes plutôt qu'à chaque rendu de ligne.
  const lignesParRemise = useMemo(() => {
    const carte = new Map()
    for (const l of lignes) {
      const id = idDe(l.remise)
      if (!id) continue
      if (!carte.has(id)) carte.set(id, [])
      carte.get(id).push(l)
    }
    return carte
  }, [lignes])

  // Les mandats indexés, pour résoudre le `mandat` d'une ligne de remise : il arrive en IRI nue.
  const mandatsParId = useMemo(() => new Map(mandats.map((m) => [m.id, m])), [mandats])

  // Une ligne déjà rejetée ne doit pas pouvoir l'être une seconde fois : le serveur créerait un
  // second incident d'impayé pour la même échéance, et l'accès du redevable serait bloqué deux fois.
  const lignesRejetees = useMemo(
    () => new Set(rejets.map((r) => idDe(r.ligne)).filter(Boolean)),
    [rejets],
  )

  function apresEcriture(message) {
    setSucces(message)
    setErreur(null)
    recharger()
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {/* UNE LISTE COUPÉE NE REND PAS CET ÉCRAN INCOMPLET : ELLE LE REND FAUX.
          Trois affichages se calculent en recoupant deux listes chargées séparément — le nom du
          débiteur d'une ligne de remise vient des mandats, et surtout le marquage « déjà rejetée »
          vient des rejets. Au-delà d'une page, une ligne pourtant rejetée cesse d'être reconnue
          comme telle : l'écran rouvre le bouton « Rejet reçu » dessus, et un second clic ouvrirait
          un SECOND impayé sur la même échéance — donc un accès bloqué deux fois.
          Le serveur ne pagine pas au-delà de ce qu'on demande, mais il ne prévient pas non plus
          qu'il a coupé : `totalItems` le dit, encore faut-il le lire. */}
      {tronquees.length > 0 && (
        <div className="banner banner-warn">
          <b>Toutes les données ne sont pas affichées.</b> Le serveur a renvoyé{' '}
          {tronquees.join(', ')} en partie seulement. Les noms de débiteur peuvent manquer, et
          surtout une ligne <b>déjà rejetée</b> peut ne pas être reconnue comme telle : n&rsquo;
          enregistrez un rejet que si vous êtes certain qu&rsquo;il n&rsquo;a pas déjà été saisi.
        </div>
      )}

      {/* LE BANDEAU QUI EMMÈNE, PLUTÔT QUE CELUI QUI CONSTATE.
          Sans créancier déclaré, « Générer une remise » échoue côté serveur avec un message
          technique. Le dire ici, avec le bouton qui y mène, épargne l'aller-retour. */}
      {!chargement && !config && (
        <div className="banner banner-warn">
          <b>Aucun créancier SEPA déclaré pour cet établissement.</b> Tant que l&rsquo;ICS, le nom et
          l&rsquo;IBAN de collecte ne sont pas renseignés, aucune remise ne peut être composée — les
          mandats se signent, mais rien ne part à la banque.{' '}
          <button className="btn sm" type="button" onClick={() => setOnglet('creancier')}>
            Déclarer le créancier
          </button>
        </div>
      )}

      <Tabs
        onglets={[
          ['mandats', `Mandats${mandats.length ? ` (${mandats.length})` : ''}`],
          ['remises', `Remises${remises.length ? ` (${remises.length})` : ''}`],
          ['rejets', `Rejets${rejets.length ? ` (${rejets.length})` : ''}`],
          ['creancier', 'Créancier'],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
      ) : (
        <>
          {onglet === 'mandats' && (
            <Mandats
              mandats={mandats}
              peutGerer={peutGerer}
              onCreer={() => setCreationMandat(true)}
            />
          )}

          {onglet === 'remises' && (
            <Remises
              remises={remises}
              lignesParRemise={lignesParRemise}
              mandatsParId={mandatsParId}
              lignesRejetees={lignesRejetees}
              peutGerer={peutGerer}
              onGenerer={() => setGeneration(true)}
              onRejeter={setRejetSur}
              onErreur={setErreur}
            />
          )}

          {onglet === 'rejets' && (
            <Rejets
              rejets={rejets}
              lignes={lignes}
              lignesRejetees={lignesRejetees}
              peutGerer={peutGerer}
              onRejeter={setRejetSur}
            />
          )}

          {onglet === 'creancier' && (
            <Creancier config={config} peutGerer={peutGerer} onEditer={() => setEditionConfig(true)} />
          )}
        </>
      )}

      <CreationMandatModal
        open={creationMandat}
        etabActif={etabActif}
        onClose={() => setCreationMandat(false)}
        onFait={(m) => { setCreationMandat(false); apresEcriture(m) }}
      />

      <GenerationRemiseModal
        open={generation}
        onClose={() => setGeneration(false)}
        onFait={(m) => { setGeneration(false); apresEcriture(m) }}
      />

      <DeclarationRejetModal
        cible={rejetSur}
        lignes={lignes}
        mandatsParId={mandatsParId}
        lignesRejetees={lignesRejetees}
        onClose={() => setRejetSur(null)}
        onFait={(m) => { setRejetSur(null); apresEcriture(m) }}
      />

      <ConfigCreancierModal
        open={editionConfig}
        config={config}
        etabActif={etabActif}
        onClose={() => setEditionConfig(false)}
        onFait={(m) => { setEditionConfig(false); apresEcriture(m) }}
      />
    </>
  )
}

// --- Mandats -----------------------------------------------------------------------------------

function Mandats({ mandats, peutGerer, onCreer }) {
  const actifs = mandats.filter((m) => m.statut !== 'revoque')

  return (
    <section className="card">
      <div className="card-h">
        <h3>Mandats de prélèvement</h3>
        <span className="sub">
          {actifs.length === 0 ? 'aucun mandat actif' : `${actifs.length} actif${actifs.length > 1 ? 's' : ''}`}
        </span>
        {peutGerer && (
          <div className="r" style={{ marginLeft: 'auto' }}>
            <button className="btn primary sm" type="button" onClick={onCreer}>Signer un mandat</button>
          </div>
        )}
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {mandats.length === 0 ? (
          <div className="empty">
            Aucun mandat. Un mandat est l&rsquo;autorisation écrite du client de prélever son compte :
            sans lui, aucune de ses échéances n&rsquo;entrera dans une remise.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>RUM</th>
                <th>Débiteur</th>
                <th>Compte</th>
                <th>Signé le</th>
                <th>Prochaine séquence</th>
                <th className="num">Collectes</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              {mandats.map((m) => (
                <tr key={m.id}>
                  <td><span className="mono">{m.rum || '—'}</span></td>
                  {/* `client` arrive en IRI nue (vérifié contre la préprod) : le nom du client n'est
                      pas dans la réponse, seul celui du TITULAIRE DU COMPTE l'est. C'est de toute
                      façon celui qui compte ici — c'est lui que la banque lira. */}
                  <td><span className="nm">{m.debiteurNom || '—'}</span></td>
                  <td>
                    {/* QUATRE CHIFFRES, ET C'EST TOUT CE QUE LE SERVEUR REND.
                        L'IBAN complet est tokenisé (non réversible) et chiffré au coffre : il ne
                        revient JAMAIS dans une réponse d'API. Afficher « ••••1234 » n'est donc pas
                        un masquage de politesse, c'est la totalité de ce qui existe côté client. */}
                    <span className="mono">{m.iban4Derniers ? `•••• ${m.iban4Derniers}` : '—'}</span>
                    {m.bicDebiteur && <div className="sub mono">{m.bicDebiteur}</div>}
                  </td>
                  <td>{dateFr(m.dateSignature)}</td>
                  <td>
                    {m.sequenceCourante ? (
                      <>
                        {mot(m.sequenceCourante)}
                        <div className="sub mono">{m.sequenceCourante}</div>
                      </>
                    ) : (
                      <span className="sub">jamais prélevé</span>
                    )}
                  </td>
                  <td className="num">{m.nbCollectesReussies ?? 0}</td>
                  <td>
                    <span className={`badge ${m.statut === 'actif' ? 'good' : 'mut'}`}>{mot(m.statut)}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {mandats.length > 0 && (
          <div className="hint">
            La « prochaine séquence » est ce que la banque lira dans le fichier : une première
            collecte (FRST) et une suivante (RCUR) ne suivent pas le même circuit de contrôle, et
            c&rsquo;est le serveur qui la calcule — elle ne se corrige pas à la main.
          </div>
        )}
      </div>
    </section>
  )
}

// --- Remises -----------------------------------------------------------------------------------

function Remises({ remises, lignesParRemise, mandatsParId, lignesRejetees, peutGerer, onGenerer, onRejeter, onErreur }) {
  const [ouverte, setOuverte] = useState(null)

  // LE TÉLÉCHARGEMENT PASSE PAR UN `fetch`, PAS PAR UN LIEN, ET C'EST OBLIGATOIRE.
  //
  // `/sepa/remises/{id}/pain008` exige le jeton porteur. Une navigation (un `<a href>`) ne
  // transporte pas d'en-tête `Authorization` : le serveur répondrait 401, et le navigateur
  // enregistrerait la page d'erreur sous le nom du fichier. Même patron que le téléchargement des
  // documents (`Documents.jsx`).
  async function telecharger(remise) {
    try {
      const reponse = await fetch(api.urlPain008(remise.id), {
        headers: {
          Authorization: `Bearer ${tokenStore.get()}`,
          'X-Etablissement': etablissementStore.get() || '',
        },
      })
      if (!reponse.ok) throw new Error(`Téléchargement refusé (${reponse.status}).`)

      // UNE GARDE QUI N'EST PAS DE LA PARANOÏA : elle a déjà attrapé le défaut du module Documents.
      //
      // Cette route est hors `/api`. Si elle n'est pas proxifiée (dev) ou pas dans le bloc nginx
      // (préprod), le SPA rend son propre `index.html` avec un **200**. Sans ce contrôle, le
      // navigateur enregistre une page HTML sous le nom `remise-xxx.xml`, et le défaut ne se voit
      // qu'à l'ouverture du fichier — ou pire, à son rejet par la banque.
      const type = reponse.headers.get('Content-Type') || ''
      if (!type.includes('xml')) {
        throw new Error(
          "Le serveur n'a pas rendu le fichier pain.008 mais une page HTML : la route "
            + '/sepa/... n\'est pas routée vers l\'API (proxy de dev ou bloc nginx). '
            + 'Le fichier n\'a pas été enregistré.',
        )
      }

      const blob = await reponse.blob()
      const url = URL.createObjectURL(blob)
      const lien = document.createElement('a')
      lien.href = url
      lien.download = `${remise.messageId || `remise-${remise.id}`}.xml`
      lien.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      onErreur(e.message || 'Le téléchargement a échoué.')
    }
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>Remises de prélèvement</h3>
        <span className="sub">ce qui part à la banque, au format pain.008</span>
        {peutGerer && (
          <div className="r" style={{ marginLeft: 'auto' }}>
            <button className="btn primary sm" type="button" onClick={onGenerer}>Générer une remise</button>
          </div>
        )}
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {remises.length === 0 ? (
          <div className="empty">
            Aucune remise. Générer une remise rassemble toutes les échéances dues à une date donnée,
            compose le fichier pain.008 et le tient prêt à être remis à la banque.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Message</th>
                <th>Collecte</th>
                <th>Séquence</th>
                <th className="num">Transactions</th>
                <th className="num">Total</th>
                <th>Statut</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {remises.map((r) => {
                const sesLignes = lignesParRemise.get(r.id) || []
                const deployee = ouverte === r.id
                return [
                  <tr
                    key={r.id}
                    className="row-click"
                    onClick={() => setOuverte(deployee ? null : r.id)}
                  >
                    <td>
                      <span className="mono">{r.messageId || '—'}</span>
                      <div className="sub">créée le {dateFr(r.dateCreation)}</div>
                    </td>
                    <td>{dateFr(r.dateCollecte)}</td>
                    <td>
                      {r.seqTp ? (
                        <>
                          {mot(r.seqTp)}
                          <div className="sub mono">{r.seqTp}</div>
                        </>
                      ) : '—'}
                    </td>
                    {/* LES ÉCHÉANCES ÉCARTÉES, LE CHIFFRE QU'ON VIENT CHERCHER APRÈS COUP.
                        `nbExclues` compte les échéances dues qui n'ont PAS pu entrer dans la remise.
                        Le docblock de l'entité dit pourquoi ça compte : « une remise à zéro ligne
                        parce que tout a été exclu n'est pas une remise à zéro ligne parce qu'il n'y
                        avait rien à collecter ». Une remise de 40 lignes sur 47 échéances dues, ce
                        sont sept clients qui ne seront pas prélevés ce mois-ci, et personne ne s'en
                        apercevra avant la relance.
                        La propriété n'avait aucun `#[Groups]` le 28/08 au matin : elle ne sortait pas
                        de l'API. Signalé, et ajouté depuis côté serveur. Le test `> 0` fait que
                        l'affichage reste muet tant que le champ est absent — jamais un `undefined`
                        déguisé en donnée. */}
                    <td className="num">
                      {r.nbTxs ?? 0}
                      {r.nbExclues > 0 && (
                        <div className="sub" style={{ color: 'var(--warn)' }}>
                          {r.nbExclues} exclue{r.nbExclues > 1 ? 's' : ''}
                        </div>
                      )}
                    </td>
                    <td className="num">{euroCentimes(r.ctrlSumCentimes)}</td>
                    <td><span className={`badge ${r.statut === 'transmise' ? 'good' : 'mut'}`}>{mot(r.statut)}</span></td>
                    <td className="num">
                      {r.statut !== 'brouillon' && (
                        <button
                          className="btn ghost sm"
                          type="button"
                          onClick={(e) => { e.stopPropagation(); telecharger(r) }}
                        >
                          pain.008
                        </button>
                      )}
                    </td>
                  </tr>,
                  deployee && (
                    <tr key={`${r.id}-detail`}>
                      <td colSpan={7} style={{ background: 'var(--panel-2)' }}>
                        {r.nbExclues > 0 && (
                          <div className="banner banner-warn" style={{ margin: '0 0 8px' }}>
                            <b>
                              {r.nbExclues} échéance{r.nbExclues > 1 ? 's' : ''} écartée
                              {r.nbExclues > 1 ? 's' : ''} de cette remise
                            </b>
                            {r.motifExclusion ? ` — ${r.motifExclusion}` : '.'} Ces clients ne seront
                            pas prélevés à cette date.
                          </div>
                        )}
                        <LignesRemise
                          lignes={sesLignes}
                          mandatsParId={mandatsParId}
                          lignesRejetees={lignesRejetees}
                          peutGerer={peutGerer}
                          onRejeter={onRejeter}
                        />
                      </td>
                    </tr>
                  ),
                ]
              })}
            </tbody>
          </table>
        )}
        {remises.length > 0 && (
          <div className="hint">
            Cliquez une remise pour voir les prélèvements qu&rsquo;elle contient.
            {peutGerer && ' C’est de là qu’on enregistre le rejet d’une ligne précise.'}
          </div>
        )}
      </div>
    </section>
  )
}

function LignesRemise({ lignes, mandatsParId, lignesRejetees, peutGerer, onRejeter }) {
  if (lignes.length === 0) {
    return (
      <div className="empty">
        Le détail des prélèvements de cette remise n&rsquo;a pas été chargé (ou la remise est vide).
      </div>
    )
  }

  return (
    <table className="tbl">
      <thead>
        <tr>
          <th>Référence de bout en bout</th>
          <th>Débiteur</th>
          <th>Libellé</th>
          <th className="num">Montant</th>
          <th>Séquence</th>
          {peutGerer && <th />}
        </tr>
      </thead>
      <tbody>
        {lignes.map((l) => {
          const rejetee = lignesRejetees.has(l.id)
          const mandat = mandatDe(l, mandatsParId)
          return (
            <tr key={l.id}>
              <td><span className="mono">{l.endToEndId || '—'}</span></td>
              <td>
                {mandat?.debiteurNom || '—'}
                {mandat?.rum && <div className="sub mono">{mandat.rum}</div>}
              </td>
              <td>
                {l.libelle || '—'}
                {l.referenceOrigine && <div className="sub mono">{l.referenceOrigine}</div>}
              </td>
              <td className="num">{euroCentimes(l.montantCentimes)}</td>
              <td>
                {l.seqTp ? (
                  <>
                    {mot(l.seqTp)}
                    <div className="sub mono">{l.seqTp}</div>
                  </>
                ) : '—'}
              </td>
              {peutGerer && (
                <td className="num">
                  {rejetee ? (
                    <span className="badge crit">rejetée</span>
                  ) : (
                    <button className="btn ghost sm" type="button" onClick={() => onRejeter(l)}>
                      Rejet reçu
                    </button>
                  )}
                </td>
              )}
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}

// --- Rejets ------------------------------------------------------------------------------------

function Rejets({ rejets, lignes, lignesRejetees, peutGerer, onRejeter }) {
  const rejetables = lignes.filter((l) => !lignesRejetees.has(l.id))

  return (
    <section className="card">
      <div className="card-h">
        <h3>Rejets bancaires</h3>
        <span className="sub">ce que la banque renvoie</span>
        {peutGerer && rejetables.length > 0 && (
          <div className="r" style={{ marginLeft: 'auto' }}>
            <button className="btn sm" type="button" onClick={() => onRejeter('choisir')}>
              Enregistrer un rejet
            </button>
          </div>
        )}
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {rejets.length === 0 ? (
          <div className="empty">
            Aucun rejet. Les prélèvements refusés par la banque apparaissent ici, et chacun ouvre un
            impayé qui peut fermer l&rsquo;accès du redevable.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Rejeté le</th>
                <th>Mandat</th>
                <th>Motif</th>
                <th>Référence</th>
                <th>Saisi le</th>
              </tr>
            </thead>
            <tbody>
              {rejets.map((r) => (
                <tr key={r.id}>
                  <td>{dateFr(r.dateRejet)}</td>
                  <td><span className="mono">{r.mndtId || '—'}</span></td>
                  <td>
                    {r.libelleMotif || '—'}
                    {/* LE CODE BRUT RESTE VISIBLE À CÔTÉ DU LIBELLÉ.
                        C'est lui qu'on cite au téléphone quand on rappelle sa banque ; le libellé
                        traduit ne suffit pas à faire retrouver l'opération. */}
                    {r.codeMotif && <div className="sub mono">{r.codeMotif}</div>}
                  </td>
                  <td><span className="mono">{r.endToEndId || '—'}</span></td>
                  <td>{dateHeureFr(r.dateSaisie)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <div className="hint">
          <b>Ces rejets sont saisis à la main.</b> Le module ne sait pas encore lire les retours
          pain.002 de la banque : rien ne remonte tout seul, et un rejet non saisi ici est un impayé
          qui n&rsquo;existe pour personne. Chaque rejet enregistré ouvre un incident consultable
          dans l&rsquo;écran <b>Recouvrement</b>.
        </div>
      </div>
    </section>
  )
}

// --- Créancier ---------------------------------------------------------------------------------

function Creancier({ config, peutGerer, onEditer }) {
  return (
    <section className="card">
      <div className="card-h">
        <h3>Créancier SEPA</h3>
        <span className="sub">qui prélève, et sur quel compte l&rsquo;argent arrive</span>
        {peutGerer && (
          <div className="r" style={{ marginLeft: 'auto' }}>
            <button className="btn primary sm" type="button" onClick={onEditer}>
              {config ? 'Modifier' : 'Déclarer le créancier'}
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {!config ? (
          <div className="empty">
            Rien n&rsquo;est déclaré. L&rsquo;ICS (Identifiant Créancier SEPA) est délivré par votre
            banque : c&rsquo;est lui qui vous autorise à prélever, et il figure sur chaque fichier
            envoyé.
          </div>
        ) : (
          <div className="deflist">
            <div><span>ICS</span><span className="num mono">{config.ics || '—'}</span></div>
            <div><span>Nom du créancier</span><span className="num">{config.creancierNom || '—'}</span></div>
            <div>
              <span>Compte de collecte</span>
              <span className="num mono">
                {config.creancierIban4Derniers ? `•••• ${config.creancierIban4Derniers}` : '—'}
                {config.creancierBic ? ` · ${config.creancierBic}` : ''}
              </span>
            </div>
            <div>
              <span>Variante</span>
              <span className="num">
                {config.variante === 'regie' ? 'Régie (collectivité)' : 'Privée'}
              </span>
            </div>
            {/* LES TROIS CHAMPS DE RÉGIE NE S'AFFICHENT QUE POUR UNE RÉGIE.
                En variante privée ils sont vides par construction : les montrer vides ferait croire
                à trois informations manquantes qu'il faudrait aller remplir. */}
            {config.variante === 'regie' && (
              <>
                <div><span>Collectivité</span><span className="num">{config.collectiviteNom || '—'}</span></div>
                <div><span>Régie</span><span className="num">{config.ultimateCreancierNom || '—'}</span></div>
                <div>
                  <span>Identifiant de la régie</span>
                  <span className="num mono">{config.ultimateCreancierOrgId || '—'}</span>
                </div>
              </>
            )}
            <div><span>Modifié le</span><span className="num">{dateHeureFr(config.modifieLe)}</span></div>
          </div>
        )}
      </div>
    </section>
  )
}

// --- Modales -----------------------------------------------------------------------------------

function CreationMandatModal({ open, etabActif, onClose, onFait }) {
  const [client, setClient] = useState(null)
  const [pickerOuvert, setPickerOuvert] = useState(false)
  const [debiteurNom, setDebiteurNom] = useState('')
  const [iban, setIban] = useState('')
  const [bic, setBic] = useState('')
  const [dateSignature, setDateSignature] = useState(() => new Date().toISOString().slice(0, 10))
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!open) return
    setClient(null); setDebiteurNom(''); setIban(''); setBic('')
    setDateSignature(new Date().toISOString().slice(0, 10))
    setErreur(null)
  }, [open])

  function choisirClient(c) {
    setClient(c)
    setPickerOuvert(false)
    // Le titulaire du compte est LE PLUS SOUVENT le client, pas toujours : un parent règle pour son
    // enfant, une entreprise pour son salarié. On pré-remplit, on ne verrouille pas.
    if (!debiteurNom.trim()) setDebiteurNom([c.prenom, c.nom].filter(Boolean).join(' ').trim())
  }

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    setErreur(null)
    try {
      await api.creerMandatSepa({
        client: `/api/clients/${client.id}`,
        etablissement: etabActif ? `/api/etablissements/${etabActif}` : undefined,
        iban: iban.replace(/\s+/g, '').toUpperCase(),
        bicDebiteur: bic.trim().toUpperCase(),
        debiteurNom: debiteurNom.trim(),
        dateSignature,
      })
      onFait('Mandat signé. Les échéances de ce client entreront dans la prochaine remise.')
    } catch (err) {
      setErreur(err.message || "Le mandat n'a pas pu être créé.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <>
      <Modal open={open} onClose={onClose} titre="Signer un mandat de prélèvement">
        <form onSubmit={envoyer}>
          {erreur && <div className="banner banner-error">{erreur}</div>}

          <div className="field">
            <label>Client *</label>
            {client ? (
              <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <span className="nm">{[client.prenom, client.nom].filter(Boolean).join(' ')}</span>
                <button className="btn ghost sm" type="button" onClick={() => setPickerOuvert(true)}>
                  Changer
                </button>
              </div>
            ) : (
              <button className="btn" type="button" onClick={() => setPickerOuvert(true)}>
                Choisir un client…
              </button>
            )}
          </div>

          <div className="field">
            <label htmlFor="sm-nom">Titulaire du compte *</label>
            <input
              id="sm-nom"
              className="input"
              required
              value={debiteurNom}
              onChange={(e) => setDebiteurNom(e.target.value)}
            />
            <div className="hint">
              Le nom tel qu&rsquo;il figure sur le compte bancaire. Ce n&rsquo;est pas toujours celui
              du client : un parent règle pour son enfant, une entreprise pour son salarié.
            </div>
          </div>

          <div className="field">
            <label htmlFor="sm-iban">IBAN *</label>
            <input
              id="sm-iban"
              className="input mono"
              required
              autoComplete="off"
              placeholder="FR76 3000 1007 9412 3456 7890 185"
              value={iban}
              onChange={(e) => setIban(e.target.value)}
            />
            {/* CE QUE DEVIENT L'IBAN, DIT AVANT LA SAISIE ET NON APRÈS.
                Il ne transite qu'une fois : le serveur le tokenise (empreinte non réversible) et le
                chiffre au coffre. Il ne ressort JAMAIS d'une réponse d'API — c'est pour ça que
                l'écran n'affichera plus que quatre chiffres, et qu'une faute de frappe se corrige
                en signant un nouveau mandat, pas en modifiant celui-ci. */}
            <div className="hint">
              Saisi une seule fois. Le serveur le chiffre immédiatement et ne le rendra plus jamais :
              seuls les quatre derniers chiffres resteront lisibles. Une erreur de saisie se corrige
              en signant un nouveau mandat.
            </div>
          </div>

          <div className="field">
            <label htmlFor="sm-bic">BIC</label>
            <input
              id="sm-bic"
              className="input mono"
              autoComplete="off"
              placeholder="BNPAFRPP"
              value={bic}
              onChange={(e) => setBic(e.target.value)}
            />
          </div>

          <div className="field">
            <label htmlFor="sm-date">Date de signature</label>
            <input
              id="sm-date"
              className="input"
              type="date"
              value={dateSignature}
              onChange={(e) => setDateSignature(e.target.value)}
            />
            <div className="hint">
              La date du mandat papier ou du consentement en ligne — pas celle de la saisie, si elles
              diffèrent. C&rsquo;est elle qui fait foi en cas de contestation.
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button
              className="btn primary"
              type="submit"
              disabled={enCours || !client || !iban.trim() || !debiteurNom.trim()}
            >
              {enCours ? 'Signature…' : 'Signer le mandat'}
            </button>
          </div>
        </form>
      </Modal>

      <ClientPicker
        open={pickerOuvert}
        onClose={() => setPickerOuvert(false)}
        onSelect={choisirClient}
      />
    </>
  )
}

function GenerationRemiseModal({ open, onClose, onFait }) {
  const [dateExecution, setDateExecution] = useState(() => new Date().toISOString().slice(0, 10))
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (open) {
      setDateExecution(new Date().toISOString().slice(0, 10))
      setErreur(null)
    }
  }, [open])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    setErreur(null)
    try {
      const remise = await api.genererRemiseSepa(dateExecution)
      // ⚠ ON ANNONCE AUSSI CE QUI A ÉTÉ ÉCARTÉ, ET C'EST LA MOITIÉ QUI MANQUAIT.
      //
      // Le cas « tout est écarté » lève un 422 qui nomme la raison. Le cas « une partie est
      // écartée » réussissait en silence : on composait 40 lignes là où l'exploitant en attendait
      // 60, et le message de succès ne disait rien des 20 autres. Le cas total échoue bruyamment ;
      // le partiel avait l'air d'avoir marché.
      //
      // Le commentaire qui vivait ici affirmait que `nbExclues` « ne sort pas de l'API ». C'était
      // vrai quand il a été écrit ; les groupes de sérialisation ont été posés depuis, et personne
      // n'a relu la phrase. Mesuré avant de la remplacer, et cloué par un test.
      const exclues = Number(remise?.nbExclues ?? 0)
      const motif = remise?.motifExclusion
      onFait(
        `Remise composée : ${remise?.nbTxs ?? 0} prélèvement(s) pour ${euroCentimes(remise?.ctrlSumCentimes)}.`
          + (exclues > 0
            ? ` ⚠ ${exclues} échéance(s) ont été ÉCARTÉES${motif ? ` : ${motif}` : ''}.`
              + ' Elles ne seront pas prélevées ; corrigez la cause puis recomposez.'
            : ' Vérifiez ce nombre contre les échéances que vous attendiez.'),
      )
    } catch (err) {
      setErreur(err.message || "La remise n'a pas pu être composée.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Générer une remise de prélèvement" taille="sm">
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="field">
          <label htmlFor="gr-date">Date d&rsquo;exécution</label>
          <input
            id="gr-date"
            className="input"
            type="date"
            value={dateExecution}
            onChange={(e) => setDateExecution(e.target.value)}
          />
          <div className="hint">
            La date à laquelle la banque doit débiter les comptes. Toutes les échéances dues à cette
            date entrent dans la remise ; celles dont le mandat manque ou n&rsquo;est plus actif en
            sont exclues, et la remise vous dira combien.
          </div>
        </div>

        <div className="banner banner-warn">
          Générer ne transmet rien à la banque : la remise est composée et son fichier pain.008 tenu
          prêt au téléchargement. La remise à la banque reste un geste manuel — aucun canal
          d&rsquo;envoi n&rsquo;est raccordé.
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours}>
            {enCours ? 'Composition…' : 'Générer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function DeclarationRejetModal({ cible, lignes, mandatsParId, lignesRejetees, onClose, onFait }) {
  // `cible` vaut soit une ligne (venue du détail d'une remise), soit la chaîne 'choisir' (venue de
  // l'onglet Rejets, où l'on n'a pas encore désigné laquelle).
  const ligneImposee = cible && cible !== 'choisir' ? cible : null
  const [ligneId, setLigneId] = useState('')
  const [codeMotif, setCodeMotif] = useState('')
  const [libelleMotif, setLibelleMotif] = useState('')
  const [dateRejet, setDateRejet] = useState(() => new Date().toISOString().slice(0, 10))
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!cible) return
    setLigneId(ligneImposee?.id || '')
    setCodeMotif(''); setLibelleMotif('')
    setDateRejet(new Date().toISOString().slice(0, 10))
    setErreur(null)
  }, [cible, ligneImposee])

  // Les motifs de retour SEPA les plus courants. La liste n'est pas fermée : la banque peut en
  // renvoyer d'autres, et le champ reste libre — un code inconnu qu'on ne peut pas saisir, c'est un
  // impayé qu'on ne peut pas ouvrir.
  const MOTIFS = [
    ['AM04', 'Provision insuffisante'],
    ['MS03', 'Refus du débiteur, motif non communiqué'],
    ['MD01', 'Mandat inexistant ou non valide'],
    ['MD06', 'Contestation du débiteur'],
    ['AC04', 'Compte clôturé'],
    ['AC06', 'Compte bloqué'],
    ['MD07', 'Débiteur décédé'],
  ]

  const rejetables = lignes.filter((l) => !lignesRejetees.has(l.id))
  const ligne = ligneImposee || rejetables.find((l) => l.id === ligneId) || null

  function choisirMotif(code) {
    setCodeMotif(code)
    const connu = MOTIFS.find(([c]) => c === code)
    if (connu) setLibelleMotif(connu[1])
  }

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    setErreur(null)
    try {
      await api.declarerRejetSepa({
        ligne: `/api/ligne_remise_sepas/${ligne.id}`,
        codeMotif: codeMotif.trim().toUpperCase(),
        libelleMotif: libelleMotif.trim() || undefined,
        dateRejet,
      })
      // CE MESSAGE ANNONCAIT UN IMPAYE QUI NE S'OUVRE PAS. Eprouve le 29/08 avec l'accord de
      // Maxime, sur un mandat de demonstration : le rejet part en 201 et ne cree AUCUN incident --
      // tableau de bord du recouvrement inchange, mandat toujours actif, aucun acces bloque.
      // `DeclarerRejetSepaProcessor` n'emet aucun evenement et n'appelle pas le moteur de
      // recouvrement ; ses deux seuls declencheurs sont la simulation d'echeance du module Sport et
      // le resultat d'une representation.
      // Annoncer la prise en charge fait fermer l'ecran en croyant l'affaire suivie. On dit ce qui
      // s'est reellement passe, et ce qu'il reste a faire.
      onFait(
        'Rejet enregistré au journal. ⚠ Aucun impayé n’est ouvert automatiquement : le suivi du '
        + 'recouvrement doit être déclenché à part.',
      )
    } catch (err) {
      setErreur(err.message || "Le rejet n'a pas pu être enregistré.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!cible} onClose={onClose} titre="Enregistrer un rejet de la banque">
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {/* CE QUE CE GESTE DÉCLENCHE, ÉCRIT AVANT DE LE FAIRE.
            « Enregistrer un rejet » sonne comme une saisie comptable anodine. En réalité le serveur
            ouvre un incident d'impayé, programme les représentations bancaires et peut FERMER
            L'ACCÈS du redevable selon la politique de recouvrement. Quelqu'un se présentera au
            guichet sans comprendre : autant que celui qui clique le sache. */}
        <div className="banner banner-warn">
          <b>Ce n&rsquo;est pas qu&rsquo;une écriture.</b> Le rejet ouvre un impayé, programme les
          représentations bancaires, et peut fermer l&rsquo;accès du redevable selon la règle de
          recouvrement de l&rsquo;établissement.
        </div>

        {ligneImposee ? (
          <div className="deflist">
            <div>
              <span>Prélèvement</span>
              <span className="num mono">{ligneImposee.endToEndId || ligneImposee.id}</span>
            </div>
            <div>
              <span>Débiteur</span>
              <span className="num">{mandatDe(ligneImposee, mandatsParId)?.debiteurNom || '—'}</span>
            </div>
            <div>
              <span>Montant</span>
              <span className="num">{euroCentimes(ligneImposee.montantCentimes)}</span>
            </div>
          </div>
        ) : (
          <div className="field">
            <label htmlFor="dr-ligne">Prélèvement rejeté *</label>
            <select
              id="dr-ligne"
              className="input"
              required
              value={ligneId}
              onChange={(e) => setLigneId(e.target.value)}
            >
              <option value="">Choisir…</option>
              {rejetables.map((l) => (
                <option key={l.id} value={l.id}>
                  {[mandatDe(l, mandatsParId)?.debiteurNom, euroCentimes(l.montantCentimes), l.endToEndId]
                    .filter(Boolean)
                    .join(' — ')}
                </option>
              ))}
            </select>
            {rejetables.length === 0 && (
              <div className="hint">
                Aucun prélèvement à rejeter : toutes les lignes des remises chargées portent déjà un
                rejet.
              </div>
            )}
          </div>
        )}

        <div className="field">
          <label htmlFor="dr-code">Code motif de la banque *</label>
          <div style={{ display: 'flex', gap: 8 }}>
            <input
              id="dr-code"
              className="input mono"
              required
              style={{ maxWidth: 120 }}
              placeholder="AM04"
              value={codeMotif}
              onChange={(e) => setCodeMotif(e.target.value)}
            />
            <select
              className="input"
              value={MOTIFS.some(([c]) => c === codeMotif) ? codeMotif : ''}
              onChange={(e) => e.target.value && choisirMotif(e.target.value)}
            >
              <option value="">Motifs courants…</option>
              {MOTIFS.map(([c, l]) => (
                <option key={c} value={c}>{c} — {l}</option>
              ))}
            </select>
          </div>
          <div className="hint">
            Le code figure sur le relevé de rejet de la banque. C&rsquo;est lui qu&rsquo;on cite au
            téléphone : il reste affiché tel quel à côté du libellé traduit.
          </div>
        </div>

        <div className="field">
          <label htmlFor="dr-libelle">Libellé</label>
          <input
            id="dr-libelle"
            className="input"
            value={libelleMotif}
            onChange={(e) => setLibelleMotif(e.target.value)}
          />
        </div>

        <div className="field">
          <label htmlFor="dr-date">Date du rejet</label>
          <input
            id="dr-date"
            className="input"
            type="date"
            value={dateRejet}
            onChange={(e) => setDateRejet(e.target.value)}
          />
          <div className="hint">
            La date portée par la banque, pas celle de la saisie : c&rsquo;est elle qui fait courir
            le calendrier des représentations.
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours || !ligne || !codeMotif.trim()}>
            {enCours ? 'Enregistrement…' : 'Enregistrer le rejet'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function ConfigCreancierModal({ open, config, etabActif, onClose, onFait }) {
  const [variante, setVariante] = useState('prive')
  const [ics, setIcs] = useState('')
  const [creancierNom, setCreancierNom] = useState('')
  const [creancierBic, setCreancierBic] = useState('')
  const [ibanClair, setIbanClair] = useState('')
  const [collectiviteNom, setCollectiviteNom] = useState('')
  const [ultimateCreancierNom, setUltimateCreancierNom] = useState('')
  const [ultimateCreancierOrgId, setUltimateCreancierOrgId] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!open) return
    setVariante(config?.variante || 'prive')
    setIcs(config?.ics || '')
    setCreancierNom(config?.creancierNom || '')
    setCreancierBic(config?.creancierBic || '')
    setIbanClair('')
    setCollectiviteNom(config?.collectiviteNom || '')
    setUltimateCreancierNom(config?.ultimateCreancierNom || '')
    setUltimateCreancierOrgId(config?.ultimateCreancierOrgId || '')
    setErreur(null)
  }, [open, config])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    setErreur(null)
    try {
      const corps = {
        variante,
        ics: ics.trim(),
        creancierNom: creancierNom.trim(),
        creancierBic: creancierBic.trim().toUpperCase(),
        collectiviteNom: variante === 'regie' ? (collectiviteNom.trim() || null) : null,
        ultimateCreancierNom: variante === 'regie' ? (ultimateCreancierNom.trim() || null) : null,
        ultimateCreancierOrgId: variante === 'regie' ? (ultimateCreancierOrgId.trim() || null) : null,
      }
      // L'IBAN n'est envoyé QUE s'il a été saisi : sur une modification, un champ laissé vide veut
      // dire « ne change pas le compte », pas « efface-le ». Le processor ne retouche le coffre que
      // si `creancierIbanClair` est non vide — la garde est des deux côtés.
      if (ibanClair.trim()) corps.creancierIbanClair = ibanClair.replace(/\s+/g, '').toUpperCase()

      if (config) {
        await api.majConfigCreancierSepa(config.id, corps)
      } else {
        await api.creerConfigCreancierSepa({
          ...corps,
          etablissement: `/api/etablissements/${etabActif}`,
        })
      }
      onFait('Créancier enregistré. Les prochaines remises seront composées avec ces informations.')
    } catch (err) {
      setErreur(err.message || "La configuration n'a pas pu être enregistrée.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre={config ? 'Modifier le créancier SEPA' : 'Déclarer le créancier SEPA'}>
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="field">
          <label htmlFor="cc-variante">Nature du créancier</label>
          <select
            id="cc-variante"
            className="input"
            value={variante}
            onChange={(e) => setVariante(e.target.value)}
          >
            <option value="prive">Privé (société, association)</option>
            <option value="regie">Régie d&rsquo;une collectivité</option>
          </select>
          <div className="hint">
            Une régie ne remplit pas le fichier comme une société privée : la collectivité y figure
            comme créancier et la régie comme donneur d&rsquo;ordre. Trois champs supplémentaires
            apparaissent si vous choisissez « régie ».
          </div>
        </div>

        <div className="field">
          <label htmlFor="cc-ics">ICS — Identifiant Créancier SEPA *</label>
          <input
            id="cc-ics"
            className="input mono"
            required
            placeholder="FR00ZZZ000000"
            value={ics}
            onChange={(e) => setIcs(e.target.value)}
          />
          <div className="hint">
            Délivré par votre banque. C&rsquo;est lui qui vous autorise à prélever, et il figure sur
            chaque fichier envoyé — une erreur ici fait rejeter la remise entière.
          </div>
        </div>

        <div className="field">
          <label htmlFor="cc-nom">Nom du créancier *</label>
          <input
            id="cc-nom"
            className="input"
            required
            value={creancierNom}
            onChange={(e) => setCreancierNom(e.target.value)}
          />
          <div className="hint">
            Le nom que le client verra sur son relevé bancaire. S&rsquo;il ne le reconnaît pas, il
            conteste — et une contestation coûte plus cher que le prélèvement.
          </div>
        </div>

        <div className="field">
          <label htmlFor="cc-iban">IBAN de collecte {config ? '' : '*'}</label>
          <input
            id="cc-iban"
            className="input mono"
            required={!config}
            autoComplete="off"
            placeholder={
              config && config.creancierIban4Derniers
                ? `Inchangé (•••• ${config.creancierIban4Derniers})`
                : 'FR76 3000 1007 9412 3456 7890 185'
            }
            value={ibanClair}
            onChange={(e) => setIbanClair(e.target.value)}
          />
          <div className="hint">
            Le compte sur lequel l&rsquo;argent prélevé arrive. Chiffré au coffre à l&rsquo;envoi et
            jamais rendu : seuls les quatre derniers chiffres resteront lisibles.
            {config && ' Laissez vide pour conserver le compte actuel.'}
          </div>
        </div>

        <div className="field">
          <label htmlFor="cc-bic">BIC de collecte *</label>
          <input
            id="cc-bic"
            className="input mono"
            required
            placeholder="BNPAFRPP"
            value={creancierBic}
            onChange={(e) => setCreancierBic(e.target.value)}
          />
        </div>

        {variante === 'regie' && (
          <>
            <div className="field">
              <label htmlFor="cc-collectivite">Nom de la collectivité</label>
              <input
                id="cc-collectivite"
                className="input"
                value={collectiviteNom}
                onChange={(e) => setCollectiviteNom(e.target.value)}
              />
            </div>
            <div className="field">
              <label htmlFor="cc-regie">Nom de la régie</label>
              <input
                id="cc-regie"
                className="input"
                value={ultimateCreancierNom}
                onChange={(e) => setUltimateCreancierNom(e.target.value)}
              />
            </div>
            <div className="field">
              <label htmlFor="cc-orgid">Identifiant organisation de la régie</label>
              <input
                id="cc-orgid"
                className="input mono"
                value={ultimateCreancierOrgId}
                onChange={(e) => setUltimateCreancierOrgId(e.target.value)}
              />
            </div>
          </>
        )}

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button
            className="btn primary"
            type="submit"
            disabled={enCours || !ics.trim() || !creancierNom.trim() || !creancierBic.trim() || (!config && !ibanClair.trim())}
          >
            {enCours ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// --- Utilitaires ---------------------------------------------------------------------------------

// UNE RELATION D'API PLATFORM ARRIVE SOUS DEUX FORMES, ET LES DEUX SONT NORMALES.
//
// Si l'entité liée a au moins une propriété dans le groupe de sérialisation courant, elle arrive
// EMBARQUÉE (`{ '@id': '/api/…/x', id: 'x' }`) ; sinon elle arrive comme simple IRI (`'/api/…/x'`).
// Cela dépend des groupes côté serveur, qui bougent — un écran qui n'accepte qu'une des deux formes
// se met à afficher des tirets le jour où quelqu'un ajoute un `#[Groups]` ailleurs, sans erreur.
function idDe(relation) {
  if (!relation) return null
  if (typeof relation === 'string') return relation.split('/').pop()
  return relation.id || (relation['@id'] ? String(relation['@id']).split('/').pop() : null)
}

// `totalItems` CONTRE LE NOMBRE DE LIGNES REÇUES : LE SEUL MOYEN DE SAVOIR QU'ON A ÉTÉ COUPÉ.
//
// API Platform rend une collection Hydra qui porte `totalItems` — le total RÉEL, pas la taille de la
// page. Une réponse tronquée est donc parfaitement reconnaissable, et parfaitement silencieuse si
// personne ne regarde : même code 200, même forme, juste moins de lignes.
//
// Rend le libellé de la collection quand elle est coupée, `null` sinon.
function tronquee(resultat, libelle) {
  if (resultat.status !== 'fulfilled') return null
  const total = resultat.value?.totalItems ?? resultat.value?.['hydra:totalItems']
  if (typeof total !== 'number') return null
  return membres(resultat.value).length < total ? libelle : null
}

// LES DEUX FORMES, OBSERVÉES CÔTE À CÔTE DANS LA MÊME RÉPONSE.
//
// Sur `/api/ligne_remise_sepas`, la préprod rend `remise` EMBARQUÉE (`{ '@id', '@type', id }`) et
// `mandat` en IRI NUE (`"/api/mandat_sepas/…"`). Deux relations de la même entité, deux formes,
// parce que `RemiseSepa::$id` porte le groupe `ligne_remise_sepa:read` et qu'aucune propriété de
// `MandatSepa` ne le porte. Rien dans le code de l'écran ne le laissait deviner.
//
// C'est ce qui rend cette famille de défauts coûteuse : `l.mandat?.debiteurNom` ne lève pas, il vaut
// `undefined`, et la colonne « Débiteur » sort vide sur toutes les lignes. Un tableau de
// prélèvements sans nom de débiteur a l'air d'un tableau de prélèvements.
function mandatDe(ligne, mandatsParId) {
  const ref = ligne.mandat
  if (!ref) return null
  if (typeof ref === 'object' && ref.debiteurNom) return ref
  return mandatsParId.get(idDe(ref)) || null
}

// La configuration créancier qui porte l'établissement demandé. Repli sur l'unique élément quand la
// liste n'en compte qu'un : le serveur a alors déjà filtré, et exiger la correspondance ferait
// disparaître une configuration parfaitement valable.
function configDeLEtablissement(configs, etabActif) {
  if (configs.length === 0) return null
  const sienne = configs.find((c) => idDe(c.etablissement) === etabActif)
  if (sienne) return sienne
  return configs.length === 1 ? configs[0] : null
}
