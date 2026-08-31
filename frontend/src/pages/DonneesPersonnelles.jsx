import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import ClientPicker, { nomClient } from '../components/ClientPicker.jsx'
import { dateFr, dateHeureFr } from '../components/Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { allerA, useEtatUrl } from '../api/url.js'

// LE DROIT À L'EFFACEMENT — une obligation légale qui n'avait aucun chemin.
//
// Le moteur existait en entier et n'était appelé de nulle part : `GET/POST /api/demande_rgpds`,
// `POST /api/demandes-rgpd/{id}/traiter`, un handler qui anonymise, un listener qui rend la demande
// traitée immuable, et une commande de conservation. Zéro occurrence dans `api/client.js`.
//
// Conséquence : une personne qui demandait l'effacement de ses données n'avait nulle part où le
// faire, et l'exploitant aucun écran pour y répondre dans le mois que la loi lui laisse. Sur un
// produit qui s'adresse à des collectivités — celles qui ont un délégué à la protection des données
// et qui posent la question en appel d'offres — c'est le manque le plus cher de l'application.
//
// TROIS CHOSES QUE CET ÉCRAN DOIT DIRE, ET QU'IL SERAIT FACILE DE TAIRE.
//
// 1. « ANONYMISÉ » N'EST PAS « SUPPRIMÉ ». `EffacementRgpdHandler` vide les champs nominatifs et
//    pose `StatutClient::Anonymise` ; la fiche reste en base, et les ventes passées y restent
//    rattachées — sans clé étrangère dure, pour ne pas rompre l'inaltérabilité NF525. Un écran qui
//    promettrait « supprimer définitivement » mentirait dans le sens qui fait perdre un contentieux.
//
// 2. LE TRAITEMENT NE SE DÉFAIT PAS, ET NE SE REJOUE PAS. Une demande réalisée est refusée en 409
//    par le handler, et `InalterabiliteCrmListener` la verrouille au niveau de l'ORM. C'est le seul
//    geste de l'application qu'on ne peut ni annuler ni recommencer : il se dit AVANT le clic, pas
//    au moment du refus.
//
// 3. LES DEUX NATURES DE DEMANDE PRODUISENT LE MÊME EFFET. `traiter()` ne lit jamais `getType()` :
//    « effacement » et « anonymisation » passent par le même handler et donnent le même résultat.
//    La nature enregistre ce que la PERSONNE a demandé — ce qui compte pour la traçabilité — et
//    l'écran dit, lui, ce que le traitement fait réellement. Présenter deux choix comme deux effets
//    différents ferait attendre une suppression qui n'arrive jamais.
//
// CE QUE L'ÉCRAN N'OFFRE PAS, ET POURQUOI.
//
// Pas de bouton « Refuser ». `StatutDemandeRgpd::Refusee` est lu par le handler mais posé par AUCUN
// code du serveur : la route n'existe pas. Un bouton qui ne mène à rien vaut moins que son absence
// — signalé pour le moteur, pas contourné ici.
export default function DonneesPersonnelles({ etabActif, droits }) {
  const [demandes, setDemandes] = useState([])
  // LES COMPTEURS SE LISENT SUR LA FILE ENTIÈRE, PAS SUR LA PAGE AFFICHÉE.
  //
  // Première version : les trois tuiles comptaient `demandes`, c'est-à-dire le résultat FILTRÉ.
  // Choisir « Traitées » dans le filtre affichait donc « À traiter : 0 » — alors que deux demandes
  // attendaient. Un zéro qui dit « vous n'avez rien à faire » est exactement le genre de certitude
  // fausse que cet écran ne peut pas se permettre : il porte un délai légal.
  const [toutes, setToutes] = useState([])
  const [total, setTotal] = useState(null)
  const [fiches, setFiches] = useState({})
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [choixClient, setChoixClient] = useState(false)
  const [creation, setCreation] = useState(null)
  const [traitement, setTraitement] = useState(null)

  const [params, majParams] = useEtatUrl('rgpd', DEFAUTS)
  const peutGerer = aLeDroit(droits, 'crm.rgpd_gerer')
  const peutDemander = peutGerer || aLeDroit(droits, 'crm.rgpd_demander')

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const requete = {}
      if (params.statut) requete.statut = params.statut
      if (params.client) requete.client = params.client
      // Deux lectures quand un filtre est actif, une seule sinon : la seconde n'existe que pour
      // rendre les compteurs vrais, et il serait absurde de la payer quand la page EST la file.
      const [reponse, entiere] = await Promise.all([
        api.demandesRgpd(requete),
        params.statut ? api.demandesRgpd(params.client ? { client: params.client } : {}) : null,
      ])
      const liste = membres(reponse)
      setDemandes(liste)
      setToutes(params.statut ? membres(entiere) : liste)
      const t = (params.statut ? entiere : reponse)?.totalItems
        ?? (params.statut ? entiere : reponse)?.['hydra:totalItems']
      setTotal(typeof t === 'number' ? t : null)

      // LA RELATION `client` REVIENT MUETTE, ET C'EST LA CONTRAINTE QUI FAÇONNE CE TABLEAU.
      //
      // `Client` n'expose aucune propriété dans le groupe `rgpd:read` : la collection rend
      // `{"@id": "/api/clients/…", "id": "…"}` et rien d'autre. Affichée telle quelle, la file
      // serait une liste d'UUID — impossible de savoir QUI a demandé quoi, sur le seul écran où se
      // tromper de personne est irréparable.
      //
      // On relit donc chaque fiche. C'est un appel par ligne, assumé : ces demandes se comptent par
      // unités, pas par milliers, et une lecture qui échoue laisse la ligne lisible plutôt que de
      // faire tomber le tableau.
      const ids = [...new Set(liste.map((d) => idDeClient(d)).filter(Boolean))]
      const lues = await Promise.allSettled(ids.map((id) => api.client(id)))
      const table = {}
      ids.forEach((id, i) => {
        table[id] = lues[i].status === 'fulfilled' ? lues[i].value : null
      })
      setFiches(table)
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
  }, [etabActif, params.statut])

  useEffect(() => { recharger() }, [recharger])

  // LE FILTRE PAR PERSONNE A ÉTÉ CASSÉ UNE JOURNÉE, ET L'HISTOIRE MÉRITE D'ÊTRE GARDÉE.
  //
  // Le contrat annoncé était `GET /api/demande_rgpds?client={uuid}`. Éprouvé contre la préprod le
  // 29/08 avant d'écrire une ligne, sur une collection contenant bien deux demandes du même
  // client : `?statut=recue` rendait 2, et `?client=` rendait 0 — sur l'IRI, sur l'identifiant nu,
  // encodé, non encodé, et jusque sur une valeur absurde. Toujours 200, jamais de 400, toujours
  // vide. Les deux filtres étaient pourtant déclarés dans la même annotation.
  //
  // Cru sur parole, cet écran aurait affirmé « cette personne n'a jamais demandé l'effacement de
  // ses données » à quelqu'un qui en avait deux en cours — sur le seul écran de l'application qui
  // porte un délai légal d'un mois.
  //
  // Signalé, corrigé le jour même par claude-A (`SearchFilter` remplacé par `UuidReferenceFilter`),
  // et REMESURÉ ici avant de rebrancher : les trois formes rendent 2. La restriction repart donc
  // au serveur, ce qui la rend juste au-delà d'une page — ce que le tri local ne pouvait pas être.
  //
  // Ce qu'il faut en retenir tient en une phrase : un filtre déclaré n'est pas un filtre qui
  // répond, et cela se voit en une requête.
  const affichees = demandes

  const compteurs = useMemo(() => {
    const ouvertes = toutes.filter((d) => d.statut === 'recue' || d.statut === 'en_cours')
    return {
      ouvertes: ouvertes.length,
      horsDelai: ouvertes.filter((d) => joursRestants(d.dateDemande) < 0).length,
      traitees: toutes.filter((d) => d.statut === 'realisee').length,
      // Le serveur pagine. Au-delà d'une page, ces trois nombres comptent une PAGE et non la file
      // — on le dit plutôt que d'afficher un total qu'on n'a pas mesuré. La comparaison, et non un
      // plafond appris par cœur : celui-ci a changé une fois déjà.
      partiels: total != null && total > toutes.length,
    }
  }, [toutes, total])

  return (
    <div className="view large">
      {/* RETOUR EXPLICITE — arbitré par Maxime le 29/08 : « boutons retour », pas seulement le
          « Précédent » du navigateur. Il n'apparaît que si l'on vient d'une fiche, parce qu'un
          bouton retour qui ne sait pas d'où l'on vient renvoie ailleurs que là où l'on était :
          arrivé par le menu, celui-ci n'aurait aucune destination honnête à proposer. */}
      {params.client && (
        <button
          className="btn ghost sm"
          type="button"
          style={{ alignSelf: 'flex-start', marginBottom: 4 }}
          onClick={() => allerA('clients', { fiche: params.client })}
        >
          ← Retour à la fiche
        </button>
      )}

      <div className="view-head">
        <div className="ttl">
          <h1>Données personnelles</h1>
          <p>Les demandes d’effacement, et le mois dont vous disposez pour y répondre</p>
        </div>
        {peutDemander && (
          <div className="actions">
            <button
              className="btn"
              type="button"
              onClick={() => {
                setSucces(null)
                // Venu de la fiche de quelqu'un, on sait DÉJÀ de qui il s'agit : rouvrir un
                // sélecteur reviendrait à lui demander de retrouver la personne qu'il regardait.
                const connue = params.client ? fiches[params.client] : null
                if (connue) setCreation(connue)
                else setChoixClient(true)
              }}
            >
              ＋ Enregistrer une demande
            </button>
            <button title="Actualiser" className="btn ghost" type="button" onClick={recharger} disabled={chargement}>↻</button>
          </div>
        )}
      </div>

      {/* Placé au-dessus de la file et jamais masquable : ce n'est pas un avertissement ponctuel,
          c'est la définition de ce que fait le bouton du bas. */}
      <div className="banner banner-info">
        <b>Traiter une demande anonymise la fiche ; cela ne la supprime pas.</b> Les données
        personnelles — nom, coordonnées, date de naissance — sont effacées définitivement. La fiche,
        elle, subsiste sans identité, et les ventes passées y restent rattachées : c’est ce qui
        permet à la comptabilité de rester juste sans conserver qui que ce soit.
      </div>

      {/* UNE RESTRICTION QUI NE SE VOIT PAS EST UN MENSONGE PAR OMISSION : sans cette ligne,
          l'écran affiche « aucune demande » alors qu'il en cache peut-être douze. */}
      {params.client && (
        <div className="banner banner-info">
          Cet écran ne montre que les demandes de{' '}
          <b>{fiches[params.client] ? nomClient(fiches[params.client]) : 'cette personne'}</b>.{' '}
          <button
            className="btn ghost sm"
            type="button"
            onClick={() => { setSucces(null); majParams({ client: '' }) }}
          >
            Voir toutes les demandes
          </button>
        </div>
      )}

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {/* LE COMPTEUR << HORS DÉLAI >> EST LA RAISON POUR LAQUELLE CES TUILES EXISTENT.
          Les deux autres se déduisent du tableau ; celui-là non, parce qu'il faut calculer une
          échéance par ligne pour le voir. C'est aussi le seul qui coûte quelque chose : au-delà d'un
          mois sans réponse, l'exploitant est en faute, et il ne l'apprendra pas autrement. */}
      <div className="fiche-stats" style={{ marginBottom: 16 }}>
        <div className="stat-tile">
          <div className="st-val num">{compteurs.ouvertes}</div>
          <div className="st-lbl">À traiter</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num" style={compteurs.horsDelai > 0 ? { color: 'var(--crit)' } : undefined}>
            {compteurs.horsDelai}
          </div>
          <div className="st-lbl">Hors délai — la loi laisse un mois</div>
        </div>
        <div className="stat-tile">
          <div className="st-val num">{compteurs.traitees}</div>
          <div className="st-lbl">Traitées</div>
        </div>
      </div>

      {compteurs.partiels && (
        <p className="hint" style={{ marginTop: -8 }}>
          {total} demandes en tout : ces trois nombres ne portent que sur les {toutes.length}
          {' '}premières, le serveur ne rendant pas davantage en une fois.
        </p>
      )}

      <section className="card">
        <div className="card-h">
          <h3>Demandes</h3>
          <div className="actions" style={{ marginLeft: 'auto' }}>
            <select
              className="input sm"
              value={params.statut}
              aria-label="Filtrer par état"
              onChange={(e) => { setSucces(null); majParams({ statut: e.target.value }) }}
            >
              <option value="">Toutes</option>
              <option value="recue">Reçues</option>
              <option value="en_cours">En cours</option>
              <option value="realisee">Traitées</option>
              <option value="refusee">Refusées</option>
            </select>
          </div>
        </div>
        <div className="card-b" style={{ overflowX: 'auto' }}>
          {chargement ? (
            <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
          ) : affichees.length === 0 ? (
            <div className="empty">
              {params.statut
                ? 'Aucune demande dans cet état.'
                : params.client
                ? 'Cette personne n’a jamais demandé l’effacement de ses données.'
                : 'Aucune demande enregistrée. Quand une personne réclame l’effacement de ses '
                  + 'données — par courrier, par courriel, au guichet — enregistrez-la ici : c’est '
                  + 'ce qui fait courir le délai, et ce qui prouve que vous y avez répondu.'}
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Personne</th>
                  <th>Nature de la demande</th>
                  <th>Reçue le</th>
                  <th>Délai</th>
                  <th>État</th>
                  <th>Traitée</th>
                  {peutGerer && <th className="num">Action</th>}
                </tr>
              </thead>
              <tbody>
                {affichees.map((d) => {
                  const id = idDeClient(d)
                  const fiche = fiches[id]
                  return (
                    <tr key={d.id}>
                      <td><Personne fiche={fiche} id={id} /></td>
                      <td>{NATURE[d.type] || d.type || '—'}</td>
                      <td>{dateFr(d.dateDemande)}</td>
                      <td><Delai demande={d} /></td>
                      <td>
                        <span className={'badge ' + (d.statut === 'realisee' ? 'mut' : 'warn')}>
                          {ETAT[d.statut] || d.statut}
                        </span>
                      </td>
                      <td>
                        <span className="sub">
                          {d.dateTraitement ? dateHeureFr(d.dateTraitement) : '—'}
                        </span>
                      </td>
                      {peutGerer && (
                        <td className="num">
                          {d.statut === 'recue' || d.statut === 'en_cours' ? (
                            <button
                              className="btn sm"
                              type="button"
                              onClick={() => { setSucces(null); setErreur(null); setTraitement({ demande: d, fiche }) }}
                            >
                              Traiter
                            </button>
                          ) : (
                            <span className="sub">demande close</span>
                          )}
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          )}

          {!params.statut && total != null && total > demandes.length && (
            <p className="hint">
              {total} demandes au total, {demandes.length} affichées — le serveur ne rend que trente
              lignes par page. Filtrez par état pour atteindre les autres.
            </p>
          )}
        </div>
      </section>

      {/* Pas d'onglet de creation ici : creer une fiche sur l'ecran qui sert a les effacer
          collecterait de la donnee personnelle pour l'anonymiser dans la foulee. */}
      <ClientPicker
        open={choixClient}
        titre="De quelle personne s’agit-il ?"
        avecCreation={false}
        onClose={() => setChoixClient(false)}
        onSelect={(c) => { setChoixClient(false); setCreation(c) }}
      />

      <NouvelleDemandeModal
        client={creation}
        onClose={() => setCreation(null)}
        onEnregistre={async (type) => {
          await api.creerDemandeRgpd({ client: '/api/clients/' + creation.id, type })
          setCreation(null)
          setSucces(
            'Demande enregistrée. Le délai d’un mois court à partir d’aujourd’hui ; rien n’a encore '
            + 'été effacé.',
          )
          await recharger()
        }}
      />

      <TraitementModal
        cible={traitement}
        onClose={() => setTraitement(null)}
        onTraite={async () => {
          await api.traiterDemandeRgpd(traitement.demande.id)
          setTraitement(null)
          setSucces('Fiche anonymisée. La demande est close et ne peut plus être rejouée.')
          await recharger()
        }}
      />
    </div>
  )
}

const DEFAUTS = { statut: '', client: '' }
const NATURE = { effacement: 'Effacement des données', anonymisation: 'Anonymisation' }
const ETAT = { recue: 'reçue', en_cours: 'en cours', realisee: 'traitée', refusee: 'refusée' }

// La relation muette rend soit un IRI nu, soit un objet réduit à son `@id`. Les deux formes se
// présentent selon l'opération : on lit l'une et l'autre plutôt que de parier.
function idDeClient(demande) {
  const c = demande?.client
  if (!c) return null
  if (typeof c === 'string') return c.split('/').pop()
  return c.id || String(c['@id'] || '').split('/').pop() || null
}

// Une fiche anonymisée n'a plus de nom, et c'est le résultat attendu — pas une erreur de lecture.
// L'écran le dit, plutôt que d'afficher un tiret qu'on prendrait pour un défaut d'affichage.
function Personne({ fiche, id }) {
  if (fiche && fiche.statut === 'anonymise') {
    return (
      <>
        <span className="nm">Fiche anonymisée</span>
        <div className="sub">plus aucune donnée personnelle</div>
      </>
    )
  }
  if (fiche) {
    return (
      <>
        <span className="nm">{nomClient(fiche)}</span>
        {fiche.statut && fiche.statut !== 'actif' && <div className="sub">{fiche.statut}</div>}
      </>
    )
  }
  return (
    <>
      <span className="sub">Fiche illisible</span>
      <div className="sub" style={{ fontSize: 11 }}>{id || '—'}</div>
    </>
  )
}

// LE DÉLAI EST LA RAISON D'ÊTRE DE CET ÉCRAN — il se lit d'un coup d'œil ou il ne sert à rien.
function Delai({ demande }) {
  if (demande.statut === 'realisee' || demande.statut === 'refusee') {
    return <span className="sub">—</span>
  }
  const reste = joursRestants(demande.dateDemande)
  if (reste < 0) return <span className="badge crit">hors délai depuis {Math.abs(reste)} j</span>
  if (reste <= 7) return <span className="badge warn">{reste} j restants</span>
  return <span className="sub">{reste} j restants</span>
}

// « Un mois », pas « trente jours » : c'est le terme de l'article 12.3 du RGPD, et le décalage se
// voit en février. On ajoute donc un mois de calendrier à la date de réception.
//
// ET ON COMPTE EN JOURS DE CALENDRIER, PAS EN HEURES ÉCOULÉES.
//
// Première version : `échéance - Date.now()` arrondi au jour supérieur. Deux demandes enregistrées
// LE MÊME JOUR affichaient « 31 j restants » et « 32 j restants » — l'une reçue à 8 h, l'autre à
// 9 h. Vu à l'écran l'une sous l'autre, avec la même date de réception dans la colonne d'à côté.
//
// Personne ne compte un délai légal en heures : on regarde une date sur un courrier et une date au
// calendrier. Les deux bornes sont donc ramenées à minuit local avant la soustraction, ce qui rend
// le nombre stable toute la journée et identique pour deux demandes du même jour.
function joursRestants(dateDemande) {
  if (!dateDemande) return 0
  const recue = new Date(dateDemande)
  if (Number.isNaN(recue.getTime())) return 0
  const echeance = new Date(recue.getFullYear(), recue.getMonth() + 1, recue.getDate())
  const maintenant = new Date()
  const aujourdhui = new Date(maintenant.getFullYear(), maintenant.getMonth(), maintenant.getDate())
  return Math.round((echeance.getTime() - aujourdhui.getTime()) / 86400000)
}

function NouvelleDemandeModal({ client, onClose, onEnregistre }) {
  const [type, setType] = useState('effacement')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!client) return
    setType('effacement')
    setErreur(null)
  }, [client])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await onEnregistre(type)
    } catch (err) {
      setErreur(err.message || "La demande n'a pas pu être enregistrée.")
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={!!client} onClose={onClose} titre="Enregistrer une demande">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 12 }}>{erreur}</div>}

        <div className="field">
          <label>Personne concernée</label>
          <div className="nm">{client ? nomClient(client) : ''}</div>
        </div>

        <div className="field">
          <label htmlFor="rgpd-type">Ce que la personne a demandé *</label>
          <select id="rgpd-type" className="input" value={type} onChange={(e) => setType(e.target.value)}>
            <option value="effacement">L’effacement de ses données</option>
            <option value="anonymisation">L’anonymisation de sa fiche</option>
          </select>
          {/* La nature enregistre la demande reçue ; elle ne change pas le traitement. Le dire ici
              évite de choisir « effacement » en attendant une suppression qui n'existe pas. */}
          <p className="hint">
            Enregistrez ce qui vous a été demandé : c’est la trace de la demande. Dans les deux cas,
            le traitement appliqué est le même — la fiche est anonymisée, elle n’est pas supprimée.
          </p>
        </div>

        <p className="hint">
          Enregistrer n’efface rien. Cela ouvre la demande, fait courir le délai d’un mois, et vous
          laisse la traiter quand vous aurez vérifié l’identité du demandeur.
        </p>

        <div className="r" style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi}>
            {envoi ? 'Enregistrement…' : 'Enregistrer la demande'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// LA SEULE MODALE DE L'APPLICATION QUI DEMANDE UNE CASE À COCHER, ET LA SEULE QUI LA MÉRITE.
//
// Partout ailleurs, une confirmation superflue apprend à cliquer sans lire. Ici le geste ne se
// défait pas, ne se rejoue pas, et porte sur les données d'une personne réelle : la case n'est pas
// une politesse d'interface, c'est le temps d'arrêt qui manque au clic.
function TraitementModal({ cible, onClose, onTraite }) {
  const [compris, setCompris] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!cible) return
    setCompris(false)
    setErreur(null)
  }, [cible])

  async function traiter() {
    setErreur(null)
    setEnvoi(true)
    try {
      await onTraite()
    } catch (err) {
      // L'ERREUR RESTE DANS LA MODALE. Renvoyée au bandeau de l'écran, elle serait masquée par la
      // modale restée ouverte — le défaut déjà payé sur l'écran des accès, où l'on relançait un
      // appairage en boucle sans jamais voir le refus.
      setErreur(err.message || "Le traitement n'a pas abouti.")
    } finally {
      setEnvoi(false)
    }
  }

  const nom = cible?.fiche ? nomClient(cible.fiche) : 'cette personne'

  return (
    <Modal open={!!cible} onClose={onClose} titre="Traiter la demande">
      {erreur && <div className="banner banner-error" style={{ marginBottom: 12 }}>{erreur}</div>}

      <p>Vous allez anonymiser la fiche de <b>{nom}</b>.</p>

      <div className="banner banner-warn">
        <b>Ce geste ne peut pas être annulé, et ne peut pas être rejoué.</b> Une fois la demande
        traitée, elle est verrouillée : ni vous ni personne ne pourra revenir en arrière ni relancer
        le traitement.
      </div>

      <div className="fiche-sec">Ce qui va se passer</div>
      <ul>
        <li>
          Le nom, le prénom, la civilité, le courriel, le téléphone, l’adresse, la date de naissance,
          la raison sociale et le SIRET sont <b>effacés</b> de la fiche.
        </li>
        <li>La fiche <b>subsiste</b>, sans identité, avec l’état « anonymisé ».</li>
        <li>
          Les ventes, les billets et les écritures comptables passés <b>restent rattachés</b> à cette
          fiche devenue anonyme : les comptes restent justes, sans conserver qui que ce soit.
        </li>
        <li>La demande est datée et signée de votre nom — c’est la preuve que vous y avez répondu.</li>
      </ul>

      <p className="hint">
        Vérifiez avant de continuer que le demandeur est bien la personne concernée. Une
        anonymisation faite sur la mauvaise fiche détruit les données de quelqu’un qui n’a rien
        demandé, et rien ne les rendra.
      </p>

      <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', margin: '12px 0' }}>
        <input type="checkbox" checked={compris} onChange={(e) => setCompris(e.target.checked)} />
        <span>J’ai vérifié l’identité du demandeur et je comprends que ce geste est définitif.</span>
      </label>

      <div className="r" style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
        <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
        <button type="button" className="btn danger" disabled={!compris || envoi} onClick={traiter}>
          {envoi ? 'Traitement…' : 'Anonymiser définitivement'}
        </button>
      </div>
    </Modal>
  )
}
