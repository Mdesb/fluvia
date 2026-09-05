import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { libelleProduit } from '../api/produit.js'
import { mot } from '../api/vocabulaire.js'
import { idDe } from '../api/iri'

// BADGES & TERMINAUX — LE MODULE DE CONTRÔLE D'ACCÈS N'AVAIT QUE SES YEUX.
//
// L'écran « Supervision » montrait les jauges, les contrôleurs et les derniers passages : on
// REGARDAIT le contrôle d'accès en temps réel, on n'AGISSAIT jamais dessus. Dix-huit opérations
// serveur, deux atteignables.
//
// Les deux gestes que fait un exploitant, et qu'aucun écran ne permettait :
//
//   « un adhérent a perdu sa carte »   POST /acces/supports/{id}/bloquer
//   « je lui en donne une autre »      POST /acces/appairages
//
// Le premier est le plus grave. Tant qu'il n'existe pas, une carte perdue reste valide : celui qui
// la ramasse entre. Le serveur savait la bloquer depuis le début — immédiatement en ligne, et hors
// ligne aussi (nouvelle version de liste de révocation poussée à chaque contrôleur, embarquée à leur
// prochaine synchronisation). Il manquait le bouton.
//
// TROIS ONGLETS, PARCE QUE CE SONT TROIS MÉTIERS ET TROIS DROITS.
//
//   Badges        `acces.lire` pour voir, `acces.appairer` / `acces.bloquer_support` pour agir
//   Pertes & vols le registre des blocages — qui a bloqué, quand, pourquoi, et qui a débloqué
//   Terminaux     `acces.gerer` — l'enrôlement du matériel, geste d'installation, pas de comptoir
//
// LE VOCABULAIRE GLOBAL NE PEUT PAS SERVIR ICI, ET CE N'EST PAS UN OUBLI.
//
// `mot('caisse')` rend « Espèces au guichet » — c'est un moyen de paiement. Le mode d'appairage
// `caisse` désigne tout autre chose : un agent qui appaire au comptoir, par opposition à une borne
// en libre-service (`autonome`). De même `mot('valide')` rend « Accepté », qui qualifie un PASSAGE ;
// appliqué au statut de projection d'un droit, il ferait lire « Accepté » là où le serveur dit « la
// projection locale est à jour ».
//
// Même collision que les motifs de retenue de la patinoire : un code, deux sens, et une carte
// globale qui ne peut pas porter les deux. D'où les trois tables locales ci-dessous — à ne pas
// « simplifier » en les renvoyant vers `mot()`.
const TYPE_SUPPORT = { QR: 'QR', RFID: 'RFID', wallet: 'Wallet' }
const MODE_APPAIRAGE = { caisse: 'Au comptoir', autonome: 'Borne libre-service' }
const STATUT_PROJECTION = { valide: 'À jour', devalide: 'Dévalidé' }

const ONGLETS = [
  ['badges', 'Badges'],
  ['pertes', 'Pertes & vols'],
]

export default function Acces({ etabActif, droits }) {
  const [onglet, setOnglet] = useState('badges')
  // ⚠ `null` = PAS LU. << Aucun badge sur cet etablissement >> est suivi d'une consigne
  // (<< Un badge nait de son premier appairage >>) : sur une lecture refusee, on envoie appairer
  // une carte qui existe deja. L'ecran lui-meme dit, quinze lignes plus bas, que le pire sens
  // d'erreur ici est d'affirmer qu'une carte n'ouvre rien.
  const [supports, setSupports] = useState(null)
  const [appairages, setAppairages] = useState([])
  const [droitsAcces, setDroitsAcces] = useState([])
  const [declarations, setDeclarations] = useState([])
  const [produits, setProduits] = useState([])
  const [billetsVendus, setBilletsVendus] = useState([])
  const [utilisateurs, setUtilisateurs] = useState([])
  const [listesPartielles, setListesPartielles] = useState(false)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [recherche, setRecherche] = useState('')

  const [appairer, setAppairer] = useState(false)
  const [aBloquer, setABloquer] = useState(null)

  const peutAppairer = aLeDroit(droits, 'acces.appairer')
  const peutBloquer = aLeDroit(droits, 'acces.bloquer_support')
  const peutGerer = aLeDroit(droits, 'acces.gerer')

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    // Sept lectures, trois droits différents : `acces.lire` pour les badges, appairages, droits et
    // déclarations ; `offre.lire` pour les libellés de produit ; `vente:read` pour les billets
    // vendus. Un `Promise.all` aurait vidé l'écran entier pour un agent de comptoir qui n'a pas
    // tous les droits.
    //
    // ⚠ LA HUITIÈME LECTURE — LES TERMINAUX — EST PARTIE AVEC EUX (R20). Elle exigeait
    // `acces.superviser` OU `acces.gerer`, et c'était le seul droit de ce bloc qu'un agent de
    // comptoir n'a pas. Retirer un appel d'un `Promise.allSettled` DÉCALE toute la
    // destructuration qui suit : `t` a disparu de la liste des liaisons en même temps que l'appel.
    const [s, a, d, p, pr, v, u] = await Promise.allSettled([
      api.supports({ itemsPerPage: 200 }),
      api.appairages(),
      api.droitsAcces(),
      api.declarationsPerteVol(),
      api.produits(),
      api.ventes({ itemsPerPage: 100, 'order[date]': 'desc' }),
      api.utilisateurs(),
    ])
    // ⚠ `null` SUR ECHEC, PAS `[]` — SINON LES DEUX MESSAGES CI-DESSOUS SONT MORTS.
    // Le rendu distingue « pas lu » de « vide » a deux endroits (« badges non lus », et « ce
    // tableau est vide parce que la lecture a echoue, pas parce que cet etablissement n'a pas de
    // badge »). Ecrire `[]` ici rendait ces deux branches inatteignables des la premiere lecture
    // refusee, et l'ecran affirmait « Aucun badge sur cet etablissement » — la phrase que le
    // commentaire de la declaration interdit explicitement.
    setSupports(s.status === 'fulfilled' ? membres(s.value) : null)
    setAppairages(a.status === 'fulfilled' ? membres(a.value) : [])
    setDroitsAcces(d.status === 'fulfilled' ? membres(d.value) : [])
    setDeclarations(p.status === 'fulfilled' ? membres(p.value) : [])
    setProduits(pr.status === 'fulfilled' ? membres(pr.value) : [])
    setUtilisateurs(u.status === 'fulfilled' ? membres(u.value) : [])

    // Les supports d'accès ÉMIS À LA VENTE (M2) : c'est par eux qu'un droit d'accès entre dans le
    // module. Ils arrivent embarqués dans `/api/ventes` — `BilletSupport` n'a pas de collection à
    // lui. Voir le commentaire de `AppairerModal` pour ce que ça permet.
    setBilletsVendus(v.status === 'fulfilled' ? billetsDesVentes(membres(v.value)) : [])

    // UNE LISTE COUPÉE NE REND PAS CET ÉCRAN INCOMPLET, ELLE LUI FAIT DIRE LE CONTRAIRE DU VRAI.
    //
    // Le serveur pagine chaque collection (l'explication est dans `api/client.js`).
    // Cet écran recoupe QUATRE listes : un badge affiche son droit en croisant les appairages, un
    // appairage affiche sa nature en croisant les droits, un badge bloqué retrouve son motif en
    // croisant les déclarations. Quand le croisement échoue, la ligne n'est pas vide — elle affirme
    // « aucun droit rattaché », c'est-à-dire « cette carte n'ouvre rien ». Sur un badge qui ouvre en
    // réalité toutes les portes, c'est le pire des deux sens d'erreur.
    setListesPartielles([s, a, d, p].some(partielle))

    if (s.status === 'rejected') {
      setErreur(s.reason?.message || 'Lecture des badges impossible.')
      setSupports(null)
    }
    setChargement(false)
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

  const produitsParId = useMemo(
    () => new Map(produits.map((p) => [String(p.id), libelleProduit(p)])),
    [produits],
  )
  const droitsParId = useMemo(() => new Map(droitsAcces.map((d) => [String(d.id), d])), [droitsAcces])
  const utilisateursParId = useMemo(
    () => new Map(utilisateurs.map((u) => [String(u.id), u])),
    [utilisateurs],
  )

  // L'appairage ACTIF de chaque badge. `Appairage` n'expose aucun filtre côté serveur (signalé à
  // l'intégrateur) : on charge et on croise ici.
  const appairageParSupport = useMemo(() => {
    const carte = new Map()
    for (const a of appairages) {
      if (a.actif === false) continue
      const id = idDe(a.support)
      if (id) carte.set(id, a)
    }
    return carte
  }, [appairages])

  // La déclaration de perte/vol ENCORE ACTIVE d'un badge : c'est elle qui porte le déblocage.
  // Le serveur ne débloque pas un support, il annule sa déclaration — la réversibilité est tracée.
  const declarationActiveParSupport = useMemo(() => {
    const carte = new Map()
    for (const d of declarations) {
      if (d.annulee) continue
      const id = idDe(d.support)
      if (id) carte.set(id, d)
    }
    return carte
  }, [declarations])

  const droitsLibres = useMemo(() => {
    const pris = new Set()
    for (const a of appairages) {
      if (a.actif === false) continue
      const id = idDe(a.droit)
      if (id) pris.add(id)
    }
    return droitsAcces.filter((d) => !pris.has(String(d.id)))
  }, [droitsAcces, appairages])

  const badgesFiltres = useMemo(() => {
    const q = recherche.trim().toLowerCase()
    if (!q) return supports || []
    return (supports || []).filter((s) => (s.identifiant || '').toLowerCase().includes(q))
  }, [supports, recherche])

  const bloques = (supports || []).filter((s) => s.statut === 'bloque').length

  // Les gestes déclenchés DEPUIS LE TABLEAU : bloquer, débloquer, détacher, révoquer. Il n'y a pas
  // de fenêtre ouverte devant, le bandeau de l'écran est le bon endroit.
  async function agir(fn, message) {
    setErreur(null)
    setSucces(null)
    try {
      const r = await fn()
      setSucces(message)
      await recharger()
      return r
    } catch (e) {
      setErreur(e.message || "L'opération n'a pas abouti.")
      return null
    }
  }

  // LES GESTES DÉCLENCHÉS DEPUIS UNE MODALE RELANCENT L'ERREUR, ET CE N'EST PAS UN DÉTAIL DE STYLE.
  //
  // J'avais d'abord fait passer les trois modales par `agir()`. Éprouvé contre la préprod, en
  // réappairant deux fois le même numéro : le serveur répond 409 « Support déjà appairé à un droit
  // actif : révocation préalable requise », l'écran l'écrit dans SON bandeau — c'est-à-dire SOUS la
  // fenêtre restée ouverte. On clique « Appairer », rien ne bouge, et l'explication est cachée
  // dessous.
  //
  // C'est le défaut que je venais de corriger le matin même dans la modale des rôles, réécrit de mes
  // propres mains une heure plus tard. Une modale doit porter l'erreur qui l'empêche de se fermer,
  // sinon il faut fermer la fenêtre pour lire pourquoi on n'a pas pu la valider.
  //
  // Les trois refus concernés sont tous des messages qui DÉBLOQUENT : « déjà appairé, révoquez
  // d'abord », « support bloqué », « un terminal est déjà enrôlé pour cette référence ITBOX ». Ils
  // disent quoi faire ensuite ; les cacher, c'est ne laisser qu'un bouton inerte.
  async function agirDepuisModale(fn, message) {
    setErreur(null)
    setSucces(null)
    const r = await fn() // l'erreur remonte à la modale, qui l'affiche chez elle
    setSucces(message)
    await recharger()
    return r
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          {/* ⚠ « TERMINAUX D'ACCÈS », COMME DANS LE MENU. « Support » ne désigne que la carte ;
              l'écran enrôle aussi les lecteurs, ce que le sous-titre dit déjà. Et le menu porte le
              qualificatif « d'accès » à dessein (voir AppShell) : « terminal » désigne aussi le TPE
              bancaire, réglé ailleurs. Le titre doit répéter le mot sur lequel on a cliqué. */}
          <h1>Badges &amp; terminaux d’accès</h1>
          <p>Appairer une carte, bloquer un badge perdu, enrôler un lecteur</p>
        </div>
        <div className="actions">
          <button className="btn" onClick={recharger}>↻ Actualiser</button>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}
      {listesPartielles && (
        <div className="banner banner-warn">
          Le serveur n’a rendu qu’une partie des listes. Un badge dont le
          droit ou la déclaration n’est pas dans la page affichera « aucun droit rattaché » alors
          qu’il en porte un. Utilisez la recherche par numéro plutôt que le tableau pour trancher.
        </div>
      )}

      <Tabs onglets={ONGLETS} actif={onglet} onChange={setOnglet} />

      {chargement ? (
        <div className="center" style={{ minHeight: 220 }}><div className="spinner" /></div>
      ) : onglet === 'badges' ? (
        <section className="card">
          <div className="card-h">
            <h3>Badges</h3>
            <span className="sub">
              {supports === null
                ? 'badges non lus'
                : `${supports.length} support(s)${bloques > 0 ? ` · ${bloques} bloqué(s)` : ''}`}
            </span>
            <div className="actions" style={{ marginLeft: 'auto' }}>
              <input
                className="input sm"
                value={recherche}
                placeholder="Numéro du badge…"
                aria-label="Rechercher un badge par son numéro"
                onChange={(e) => setRecherche(e.target.value)}
              />
              {peutAppairer && (
                <button className="btn primary" onClick={() => setAppairer(true)}>Appairer une carte</button>
              )}
            </div>
          </div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Numéro</th>
                  <th>Type</th>
                  <th>État</th>
                  <th>Droit rattaché</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {badgesFiltres.map((s) => {
                  const ap = appairageParSupport.get(String(s.id))
                  const dec = declarationActiveParSupport.get(String(s.id))
                  return (
                    <tr key={s.id}>
                      <td className="mono">{s.identifiant}</td>
                      <td>{TYPE_SUPPORT[s.type] || s.type || '—'}</td>
                      <td>
                        <span
                          className={`badge ${s.statut === 'actif' ? 'good' : 'crit'}`}
                          title={
                            s.statut === 'actif'
                              ? 'Ce badge est accepté au contrôle.'
                              : 'Ce badge est refusé au contrôle, y compris hors ligne.'
                          }
                        >
                          {mot(s.statut)}
                        </span>
                        {dec && <div className="sub">{dec.motif}</div>}
                      </td>
                      <td>
                        <LibelleDroit
                          appairage={ap}
                          droitsParId={droitsParId}
                          produitsParId={produitsParId}
                        />
                      </td>
                      <td className="row actions" style={{ justifyContent: 'flex-end', gap: 6 }}>
                        {peutBloquer && s.statut === 'actif' && (
                          <button className="btn sm" onClick={() => setABloquer(s)}>Bloquer</button>
                        )}
                        {peutBloquer && s.statut === 'bloque' && dec && (
                          <button
                            className="btn sm"
                            onClick={() =>
                              agir(
                                () => api.annulerDeclarationPerteVol(dec.id),
                                `Badge ${s.identifiant} débloqué.`,
                              )
                            }
                          >
                            Débloquer
                          </button>
                        )}
                        {peutAppairer && ap && (
                          <button
                            className="btn sm"
                            title="Détache le droit de ce badge. Le droit redevient appairable sur une autre carte."
                            onClick={() =>
                              agir(
                                () => api.revoquerAppairage(ap.id),
                                `Droit détaché du badge ${s.identifiant}.`,
                              )
                            }
                          >
                            Détacher le droit
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
                {badgesFiltres.length === 0 && (
                  <tr>
                    <td colSpan={5} className="empty">
                      {supports === null
                        ? 'La liste des badges n’a pas pu être lue : ce tableau est vide parce que la lecture a échoué, pas parce que cet établissement n’a pas de badge.'
                        : supports.length === 0
                          ? 'Aucun badge sur cet établissement. Un badge naît de son premier appairage : « Appairer une carte » crée le support et lui rattache un droit.'
                          : `Aucun badge ne porte « ${recherche.trim()} ».`}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      ) : (
        <section className="card">
          <div className="card-h">
            <h3>Pertes &amp; vols</h3>
            <span className="sub">le registre des blocages</span>
          </div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Déclaré le</th>
                  <th>Badge</th>
                  <th>Motif</th>
                  <th>Par</th>
                  <th>État</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {declarations.map((d) => (
                  <tr key={d.id}>
                    <td>{dateHeure(d.horodatage)}</td>
                    <td className="mono">{d.support?.identifiant || '—'}</td>
                    <td>{d.motif || '—'}</td>
                    <td>{nomUtilisateur(d.agent, utilisateursParId)}</td>
                    <td>
                      {d.annulee ? (
                        <>
                          <span className="badge mut">Levée</span>
                          <div className="sub">
                            {nomUtilisateur(d.annuleePar, utilisateursParId)} · {dateHeure(d.annuleeLe)}
                          </div>
                        </>
                      ) : (
                        <span className="badge crit">Badge bloqué</span>
                      )}
                    </td>
                    <td className="row actions" style={{ justifyContent: 'flex-end' }}>
                      {peutBloquer && !d.annulee && (
                        <button
                          className="btn sm"
                          onClick={() =>
                            agir(
                              () => api.annulerDeclarationPerteVol(d.id),
                              `Badge ${d.support?.identifiant || ''} débloqué.`,
                            )
                          }
                        >
                          Débloquer
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {declarations.length === 0 && (
                  <tr>
                    <td colSpan={6} className="empty">
                      Aucune perte ni vol déclarés. C’est le registre qu’on consulte quand un porteur
                      dit « ma carte ne passe plus » : il dit qui l’a bloquée et pourquoi.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <AppairerModal
        open={appairer}
        onClose={() => setAppairer(false)}
        droitsLibres={droitsLibres}
        billetsVendus={billetsVendus}
        produitsParId={produitsParId}
        onFait={async (corps) => {
          await agirDepuisModale(() => api.appairerSupport(corps), `Carte ${corps.identifiantSupport} appairée.`)
          setAppairer(false)
        }}
      />

      <BloquerModal
        support={aBloquer}
        onClose={() => setABloquer(null)}
        onFait={async (motif) => {
          await agirDepuisModale(
            () => api.bloquerSupport(aBloquer.id, motif),
            `Badge ${aBloquer.identifiant} bloqué.`,
          )
          setABloquer(null)
        }}
      />

    </div>
  )
}

// ---------------------------------------------------------------------------------------------
// Appairer une carte.
//
// DEUX FAÇONS DE DÉSIGNER LE DROIT, PARCE QUE LE SERVEUR EN ACCEPTE DEUX ET QUE L'UNE SEULE NE
// SUFFIT PAS.
//
// `AppairageProcessor` accepte soit `droit` (une projection `DroitAcces` qui existe déjà), soit
// `billetSupportRef` (l'identifiant d'un support vendu en caisse, que le serveur PROJETTE à la volée).
//
// N'offrir que la première aurait donné un bouton mort partout : la projection n'est déclenchée par
// aucune vente — elle l'est par cet appel-ci. Sur la préprod, trois établissements sur quatre n'ont
// aucun `DroitAcces` ; le menu déroulant y serait resté vide pour toujours, sans que rien n'explique
// pourquoi. C'est la seconde voie qui fait exister le premier appairage.
//
// Vérifié en interrogeant le serveur, pas déduit : `/api/droit_acces` rend 0 sur GI-ONE FITNESS,
// Patinoire B et Gione fitness, 2 sur Piscine A.
//
// LE VOCABULAIRE EST CELUI DU COMPTOIR. « Un droit déjà projeté » ne veut rien dire pour un agent :
// il a devant lui soit un client qui vient d'acheter (son billet est dans la liste des ventes), soit
// un client dont la carte a été perdue et dont le droit est resté (il est dans l'autre liste).
function AppairerModal({ open, onClose, droitsLibres, billetsVendus, produitsParId, onFait }) {
  const [source, setSource] = useState('vente')
  const [identifiant, setIdentifiant] = useState('')
  const [type, setType] = useState('RFID')
  const [droit, setDroit] = useState('')
  const [billet, setBillet] = useState('')
  const [mode, setMode] = useState('caisse')
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!open) return
    setSource(billetsVendus.length > 0 || droitsLibres.length === 0 ? 'vente' : 'droit')
    setIdentifiant('')
    setType('RFID')
    setDroit('')
    setBillet('')
    setMode('caisse')
    setErreur(null)
  }, [open, billetsVendus.length, droitsLibres.length])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    const num = identifiant.trim()
    if (!num) {
      setErreur('Le numéro de la carte est obligatoire : c’est lui que le lecteur lit.')
      return
    }
    const corps = { identifiantSupport: num, typeSupport: type, mode }
    if (source === 'vente') {
      if (!billet) {
        setErreur('Choisissez le billet ou la carte vendus à rattacher.')
        return
      }
      corps.billetSupportRef = billet
    } else {
      if (!droit) {
        setErreur('Choisissez le droit à rattacher.')
        return
      }
      corps.droit = droit
    }
    setEnCours(true)
    try {
      await onFait(corps)
    } catch (err) {
      setErreur(err.message || "L'appairage n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Appairer une carte" taille="md">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="field">
          <label htmlFor="ap-num">Numéro de la carte *</label>
          <input
            id="ap-num"
            className="input"
            value={identifiant}
            placeholder="Tel qu’il est gravé ou imprimé dessus"
            onChange={(e) => setIdentifiant(e.target.value)}
          />
          <p className="hint">
            C’est ce numéro que le lecteur lit. S’il ne correspond à aucun badge connu, le badge est
            créé ; s’il en désigne un déjà rattaché à un droit actif, le serveur refuse — il faut
            d’abord détacher.
          </p>
        </div>

        <div className="field">
          <label htmlFor="ap-type">Nature de la carte *</label>
          <select id="ap-type" className="input" value={type} onChange={(e) => setType(e.target.value)}>
            {Object.entries(TYPE_SUPPORT).map(([v, l]) => (
              <option key={v} value={v}>{l}</option>
            ))}
          </select>
        </div>

        <div className="field">
          <label htmlFor="ap-source">Ce que la carte doit ouvrir *</label>
          <select id="ap-source" className="input" value={source} onChange={(e) => setSource(e.target.value)}>
            <option value="vente">Un billet ou une carte qui viennent d’être vendus</option>
            <option value="droit">Un droit déjà enregistré (réémission après perte)</option>
          </select>
        </div>

        {source === 'vente' ? (
          <div className="field">
            <label htmlFor="ap-billet">Billet vendu *</label>
            <select id="ap-billet" className="input" value={billet} onChange={(e) => setBillet(e.target.value)}>
              <option value="">— choisir —</option>
              {billetsVendus.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.libelle} · {b.numero} · {b.date}
                </option>
              ))}
            </select>
            <p className="hint">
              {billetsVendus.length === 0
                ? 'Aucune vente récente n’a émis de billet ou de carte sur cet établissement. Il faut une vente validée avant de pouvoir appairer.'
                : 'La liste reprend les supports émis par les ventes récentes. Le droit d’accès est créé au moment de l’appairage, à partir du produit vendu.'}
            </p>
          </div>
        ) : (
          <div className="field">
            <label htmlFor="ap-droit">Droit à rattacher *</label>
            <select id="ap-droit" className="input" value={droit} onChange={(e) => setDroit(e.target.value)}>
              <option value="">— choisir —</option>
              {droitsLibres.map((d) => (
                <option key={d.id} value={d.id}>{descriptionDroit(d, produitsParId)}</option>
              ))}
            </select>
            <p className="hint">
              {droitsLibres.length === 0
                ? 'Aucun droit libre : tous ceux qui existent sont déjà portés par une carte. Détachez-le de l’ancienne carte avant de le poser sur la nouvelle.'
                : 'Ces droits existent déjà et ne sont portés par aucune carte — typiquement après le détachement d’une carte perdue.'}
            </p>
          </div>
        )}

        <div className="field">
          <label htmlFor="ap-mode">Où se fait l’appairage</label>
          <select id="ap-mode" className="input" value={mode} onChange={(e) => setMode(e.target.value)}>
            {Object.entries(MODE_APPAIRAGE).map(([v, l]) => (
              <option key={v} value={v}>{l}</option>
            ))}
          </select>
          <p className="hint">Trace comment la carte a été remise. Sans effet sur les droits ouverts.</p>
        </div>

        <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
          <button type="button" className="btn" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn primary" disabled={enCours}>
            {enCours ? 'Appairage…' : 'Appairer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// ---------------------------------------------------------------------------------------------
// Bloquer un badge perdu ou volé.
//
// Le motif est OBLIGATOIRE côté serveur (`BlocageSupportHandler` refuse une chaîne vide en 422) et
// c'est justifié : la déclaration est la seule pièce qui explique, six mois plus tard, pourquoi ce
// badge a cessé de fonctionner. On le demande donc ici plutôt que de laisser le serveur refuser.
function BloquerModal({ support, onClose, onFait }) {
  const [motif, setMotif] = useState('')
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!support) return
    setMotif('')
    setErreur(null)
  }, [support])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    if (!motif.trim()) {
      setErreur('Le motif est obligatoire : c’est lui qui expliquera ce blocage plus tard.')
      return
    }
    setEnCours(true)
    try {
      await onFait(motif.trim())
    } catch (err) {
      setErreur(err.message || "Le blocage n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!support} onClose={onClose} titre="Bloquer un badge" taille="md">
      {support && (
        <form onSubmit={soumettre}>
          {erreur && <div className="banner banner-error">{erreur}</div>}

          <div className="banner banner-info">
            Le badge <b className="mono">{support.identifiant}</b> sera refusé immédiatement au
            contrôle. Le refus descend aussi aux lecteurs hors ligne, à leur prochaine synchronisation.
            Le blocage est réversible : il se lève depuis l’onglet « Pertes &amp; vols ».
          </div>

          <div className="field">
            <label htmlFor="bl-motif">Motif *</label>
            <input
              id="bl-motif"
              className="input"
              value={motif}
              placeholder="Carte perdue par l’adhérent, vol déclaré, …"
              onChange={(e) => setMotif(e.target.value)}
            />
            <p className="hint">
              Écrivez ce que vous diriez au porteur s’il revenait demander pourquoi sa carte ne passe
              plus.
            </p>
          </div>

          <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
            <button type="button" className="btn" onClick={onClose}>Annuler</button>
            <button type="submit" className="btn primary" disabled={enCours}>
              {enCours ? 'Blocage…' : 'Bloquer le badge'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// ---------------------------------------------------------------------------------------------
// LE DROIT D'UN APPAIRAGE ARRIVE EN OBJET CREUX, ET C'EST VÉRIFIÉ, PAS SUPPOSÉ.
//
// `DroitAcces` ne déclare que `id` et `sourceType` dans le groupe `appairage:read` : tout le reste —
// fenêtre de validité, crédit restant, produit — n'y est pas. Un `a.droit.creditRestant` écrit
// spontanément aurait valu `undefined` sur CHAQUE ligne, c'est-à-dire une colonne « entrées
// restantes » vide sur des cartes qui en portent douze.
//
// On résout donc contre `/api/droit_acces`, chargé à part. Quand la résolution échoue (liste
// tronquée), on dit ce qu'on sait — la nature du droit — plutôt que d'inventer un blanc.
function LibelleDroit({ appairage, droitsParId, produitsParId }) {
  if (!appairage) return <span className="sub">aucun droit rattaché</span>

  const partiel = appairage.droit
  const complet = droitsParId.get(idDe(partiel)) || null
  const nature = complet?.sourceType || partiel?.sourceType

  return (
    <>
      <span className="badge info">{mot(nature)}</span>{' '}
      {complet ? (
        <span>{descriptionDroit(complet, produitsParId, true)}</span>
      ) : (
        <span className="sub">détail non chargé</span>
      )}
      {complet?.statutProjection === 'devalide' && (
        <div className="sub" style={{ color: 'var(--crit)' }}>{STATUT_PROJECTION.devalide}</div>
      )}
    </>
  )
}

// Ce qui identifie un droit pour un agent : le produit vendu, puis ce qui en reste. Un quota se juge
// sur ses entrées, un billet ou un abonnement sur ses dates — même arbitrage que la fiche billet.
function descriptionDroit(d, produitsParId, court = false) {
  const produit = d.produitRef ? produitsParId.get(String(d.produitRef)) : null
  const morceaux = []
  if (!court) morceaux.push(mot(d.sourceType))
  if (produit) morceaux.push(produit)
  if (d.creditRestant != null) morceaux.push(`${d.creditRestant} entrée(s)`)
  if (d.fenetreFin) morceaux.push(`jusqu’au ${dateCourte(d.fenetreFin)}`)
  else if (d.fenetreDebut) morceaux.push(`depuis le ${dateCourte(d.fenetreDebut)}`)
  if (morceaux.length === 0) morceaux.push(court ? '—' : mot(d.sourceType))
  return morceaux.join(' · ')
}

// Les supports d'accès émis par les ventes validées, aplatis et libellés pour un menu déroulant.
// `BilletSupport` n'a pas de collection à lui : il n'existe qu'embarqué dans `/api/ventes`.
function billetsDesVentes(ventes) {
  const out = []
  for (const v of ventes) {
    for (const s of v.supports || []) {
      if (!s?.id) continue
      out.push({
        id: String(s.id),
        numero: s.identifiantSupport || String(s.id).slice(0, 8),
        libelle: libelleProduit({ libelle: s.ligne?.libelleProduit }),
        date: dateCourte(v.date),
      })
    }
  }
  return out
}

function partielle(resultat) {
  if (resultat.status !== 'fulfilled') return false
  const total = resultat.value?.totalItems ?? resultat.value?.['hydra:totalItems']
  return typeof total === 'number' && total > membres(resultat.value).length
}

// `agent` et `annuleePar` arrivent en IRI nue : `Utilisateur` ne déclare aucune propriété dans le
// groupe `pertevol:read` (vérifié dans l'entité, pas supposé). Sans cette résolution, le registre
// des blocages afficherait une URL à la place d'un nom — sur la pièce dont l'unique raison d'être
// est de dire QUI a bloqué.
function nomUtilisateur(ref, utilisateursParId) {
  const id = idDe(ref)
  if (!id) return <span className="sub">—</span>
  const u = utilisateursParId.get(id)
  if (!u) return <span className="sub">agent inconnu</span>
  return [u.prenom, u.nom].filter(Boolean).join(' ').trim() || u.email || <span className="sub">agent inconnu</span>
}

function dateHeure(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
}

function dateCourte(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('fr-FR')
}
