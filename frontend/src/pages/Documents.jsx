import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, membres, tokenStore, etablissementStore } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'
import { nomOuAbsence } from '../components/Liste.jsx'

/**
 * DOCUMENTS — quinze opérations serveur, aucun écran jusqu'ici.
 *
 * Le module gère des documents **versionnés**, avec politique de conservation, liens publics
 * révocables et suppression réversible. Rien de tout ça n'était atteignable : un contrat déposé par
 * l'API existait, et personne ne pouvait le relire.
 *
 * **Le téléchargement ne s'ouvre pas dans un onglet, et c'est structurel.** `GET
 * /dms/documents/{id}/download` exige un jeton porteur ; une navigation ne transporte pas d'en-tête
 * `Authorization`. On récupère donc le flux avec le jeton, on en fait un objet local, et on déclenche
 * l'enregistrement — puis **on libère l'objet**, sans quoi chaque téléchargement laisserait le fichier
 * entier en mémoire pour la durée de la session.
 *
 * > **Un lien qui a l'air de marcher et rend une page de connexion est pire qu'un bouton absent.**
 *
 * **La version courante est affichée avec son numéro.** Un document versionné dont l'écran ne montre
 * pas la version laisse croire qu'il n'y en a qu'une — et quelqu'un remplace un fichier en pensant le
 * corriger, alors qu'il en crée une nouvelle qui ne sera pas celle qu'un lien public sert.
 */

const CATEGORIES = [
  ['accounting_piece', 'Pièce comptable'],
  ['expense_receipt', 'Justificatif de frais'],
  ['contract', 'Contrat'],
  ['intervention_report', 'Rapport d’intervention'],
  ['hr_document', 'Document RH'],
  ['quote_attachment', 'Pièce jointe de devis'],
  ['marketing_asset', 'Visuel'],
  ['other', 'Autre'],
]

function libelleCategorie(v) {
  return CATEGORIES.find(([c]) => c === v)?.[1] || v || '—'
}

function poids(octets) {
  const n = Number(octets)
  if (!Number.isFinite(n)) return '—'
  if (n < 1024) return `${n} o`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} Ko`
  return `${(n / (1024 * 1024)).toFixed(1)} Mo`
}

function quand(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

export default function Documents({ etabActif, droits = [] }) {
  const peutEcrire = aLeDroit(droits, 'dms.write')
  // Trois droits DISTINCTS, et pas `dms.write` : poser une conservation, publier un lien vers
  // l'exterieur et supprimer une piece ne sont pas le meme pouvoir. Un bouton absent ne pose aucune
  // question ; un bouton grise en pose une a qui ne peut pas y repondre.
  const peutConserver = aLeDroit(droits, 'dms.manage_retention')
  const peutPartager = aLeDroit(droits, 'dms.manage_public_link')
  const peutSupprimer = aLeDroit(droits, 'dms.delete')

  // ⚠ `null` = PAS LU.
  const [documents, setDocuments] = useState(null)
  const [versions, setVersions] = useState([])
  const [historique, setHistorique] = useState(null)
  const [aRenommer, setARenommer] = useState(null)
  const [aConserver, setAConserver] = useState(null)
  const [aPartager, setAPartager] = useState(null)
  const [aSupprimer, setASupprimer] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [categorie, setCategorie] = useState('')
  const [recherche, setRecherche] = useState('')
  const [busy, setBusy] = useState(false)

  const champFichier = useRef(null)
  const champRemplacement = useRef(null)
  const [aRemplacer, setARemplacer] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [docs, vers] = await Promise.all([
        api.documentsDms(),
        // L'HISTORIQUE ECHOUE EN SILENCE. C'est un complement de lecture : si le service de versions
        // ne repond pas, la bibliotheque doit rester consultable et telechargeable. Un ecran qui
        // refuse de s'ouvrir parce qu'un detail secondaire manque punit l'utilisateur pour une panne
        // qui ne le concerne pas.
        api.versionsDocument().catch(() => null),
      ])
      setDocuments(membres(docs))
      setVersions(vers ? membres(vers) : [])
    } catch (e) {
      setErreur(e.message || 'Les documents n’ont pas pu être chargés.')
      setDocuments(null)
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  const visibles = useMemo(() => {
    const q = recherche.trim().toLowerCase()
    return (documents || [])
      .filter((d) => d.status !== 'deleted')
      .filter((d) => !categorie || d.category === categorie)
      .filter((d) => !q || (d.title || '').toLowerCase().includes(q))
  }, [documents, categorie, recherche])

  async function telecharger(doc) {
    setErreur(null)
    try {
      // Le jeton voyage dans l'en-tete : une navigation ne le porterait pas, et le serveur rendrait
      // la page de connexion a la place du fichier.
      const reponse = await fetch(api.urlTelechargementDocument(doc.id), {
        headers: {
          Authorization: `Bearer ${tokenStore.get()}`,
          'X-Etablissement': etablissementStore.get() || '',
        },
      })
      if (!reponse.ok) throw new Error(`Téléchargement refusé (${reponse.status}).`)

      const blob = await reponse.blob()
      const url = URL.createObjectURL(blob)
      const lien = document.createElement('a')
      lien.href = url
      lien.download = doc.currentVersion?.originalFilename || doc.title || 'document'
      lien.click()
      // Sans cette liberation, chaque telechargement garde le fichier entier en memoire jusqu'a la
      // fermeture de l'onglet. Sur des contrats scannes, ca se compte en centaines de megaoctets.
      URL.revokeObjectURL(url)
    } catch (e) {
      setErreur(e.message || 'Le téléchargement a échoué.')
    }
  }

  async function televerser(fichier, titre, cat) {
    setBusy(true)
    setErreur(null)
    try {
      const fd = new FormData()
      fd.append('file', fichier)
      fd.append('title', titre || fichier.name)
      fd.append('category', cat || 'other')
      await api.televerserDocument(fd)
      setSucces(`« ${titre || fichier.name} » déposé.`)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Le dépôt a échoué.')
    } finally {
      setBusy(false)
    }
  }

  async function remplacer(doc, fichier) {
    setBusy(true)
    setErreur(null)
    try {
      const fd = new FormData()
      fd.append('file', fichier)
      await api.remplacerVersionDocument(doc.id, fd)
      setSucces(`Nouvelle version déposée pour « ${doc.title} ».`)
      await recharger()
    } catch (e) {
      setErreur(e.message || 'Le remplacement a échoué.')
    } finally {
      setBusy(false)
      setARemplacer(null)
    }
  }

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Documents</h1>
          <div className="sub">
            {documents === null
              ? 'bibliothèque non lue — la lecture n’a pas abouti'
              : `${visibles.length} document${visibles.length > 1 ? 's' : ''}`}
          </div>
        </div>
        {peutEcrire && (
          <>
            <input
              ref={champFichier}
              type="file"
              style={{ display: 'none' }}
              onChange={(e) => {
                const f = e.target.files?.[0]
                // On remet le champ a zero : sans ca, redeposer LE MEME fichier ne declencherait
                // aucun evenement, et l'utilisateur conclurait que le bouton ne marche plus.
                e.target.value = ''
                if (f) televerser(f, f.name, categorie || 'other')
              }}
            />
            <button className="btn primary" type="button" disabled={busy} onClick={() => champFichier.current?.click()}>
              + Déposer un document
            </button>
          </>
        )}
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <div className="card">
        <div className="card-h" style={{ gap: 10, flexWrap: 'wrap' }}>
          <span>Bibliothèque</span>
          <select className="select sm" style={{ width: 220 }} value={categorie} onChange={(e) => setCategorie(e.target.value)}>
            <option value="">Toutes les catégories</option>
            {CATEGORIES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          <input
            className="input sm"
            style={{ marginLeft: 'auto', width: 260 }}
            placeholder="Rechercher un titre…"
            value={recherche}
            onChange={(e) => setRecherche(e.target.value)}
          />
        </div>

        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : visibles.length === 0 ? (
          <div className="empty">
            {documents === null
              ? 'La bibliothèque n’a pas pu être lue : ce tableau est vide parce que la lecture a échoué, pas parce qu’aucun document n’a été déposé.'
              : documents.length === 0
                ? 'Aucun document. Contrats, rapports d’intervention et pièces comptables se déposent ici.'
                : 'Aucun document ne correspond à ce filtre.'}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Titre</th>
                  <th>Catégorie</th>
                  <th className="num">Version</th>
                  <th className="num">Taille</th>
                  <th className="num">Déposé le</th>
                  <th>Conservation</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {visibles.map((d) => (
                  <tr key={d.id}>
                    <td>
                      <span className="nm">{d.title}</span>
                      {d.currentVersion?.originalFilename && (
                        <div className="sub">{d.currentVersion.originalFilename}</div>
                      )}
                    </td>
                    <td>{libelleCategorie(d.category)}</td>
                    <td className="num">
                      {/* Un document versionne dont l'ecran ne montre pas la version laisse croire
                          qu'il n'y en a qu'une -- et quelqu'un << corrige >> un fichier en creant en
                          realite une version de plus. */}
                      {d.currentVersion?.versionNumber ? (
                        (d.currentVersion.versionNumber > 1)
                          ? (
                            <button
                              type="button"
                              className="btn ghost sm"
                              style={{ padding: '1px 8px', fontSize: 11.5 }}
                              onClick={() => setHistorique(d)}
                            >
                              v{d.currentVersion.versionNumber}
                            </button>
                          )
                          : `v${d.currentVersion.versionNumber}`
                      ) : '—'}
                    </td>
                    <td className="num">{poids(d.currentVersion?.sizeBytes)}</td>
                    <td className="num">{quand(d.createdAt)}</td>
                    <td>
                      {d.retainUntil
                        ? <span className="sub">jusqu’au {quand(d.retainUntil)}</span>
                        : <span className="sub">sans limite</span>}
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                        <button className="btn ghost sm" type="button" onClick={() => telecharger(d)}>
                          Télécharger
                        </button>
                        {peutEcrire && (
                          <>
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={busy}
                              onClick={() => setARenommer(d)}
                            >
                              Renommer
                            </button>
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={busy}
                              onClick={() => {
                                setARemplacer(d)
                                champRemplacement.current?.click()
                              }}
                            >
                              Nouvelle version
                            </button>
                          </>
                        )}
                        {peutConserver && (
                          <button
                            className="btn ghost sm"
                            type="button"
                            disabled={busy}
                            onClick={() => setAConserver(d)}
                          >
                            Conservation
                          </button>
                        )}
                        {peutPartager && (
                          <button
                            className="btn ghost sm"
                            type="button"
                            disabled={busy}
                            onClick={() => setAPartager(d)}
                          >
                            Lien public
                          </button>
                        )}
                        {peutSupprimer && (
                          <button
                            className="btn ghost sm"
                            type="button"
                            disabled={busy}
                            style={{ color: 'var(--crit)' }}
                            onClick={() => setASupprimer(d)}
                          >
                            Supprimer
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <RenommerDocument
        document={aRenommer}
        onFermer={() => setARenommer(null)}
        onRenomme={async (message) => {
          setARenommer(null)
          setSucces(message)
          // ⚠ `recharger`, pas `charger` : renommage inachevé. Le document était bien renommé, et
          //    l'écran mourait juste après, en se rafraîchissant.
          await recharger()
        }}
        onErreur={setErreur}
      />

      <ConservationDocument
        document={aConserver}
        onFermer={() => setAConserver(null)}
        onFait={async (message) => { setAConserver(null); setSucces(message); await recharger() }}
        onErreur={setErreur}
      />

      <LienPublicDocument
        document={aPartager}
        onFermer={() => setAPartager(null)}
        onFait={(message) => { setSucces(message) }}
        onErreur={setErreur}
      />

      <SuppressionDocument
        document={aSupprimer}
        onFermer={() => setASupprimer(null)}
        onFait={async (message) => { setASupprimer(null); setSucces(message); await recharger() }}
        onErreur={setErreur}
      />

      <HistoriqueVersions
        document={historique}
        versions={versions}
        onFermer={() => setHistorique(null)}
      />

      <input
        ref={champRemplacement}
        type="file"
        style={{ display: 'none' }}
        onChange={(e) => {
          const f = e.target.files?.[0]
          e.target.value = ''
          if (f && aRemplacer) remplacer(aRemplacer, f)
        }}
      />
    </div>
  )
}

/**
 * L'HISTORIQUE D'UN DOCUMENT VERSIONNÉ.
 *
 * **Le numéro de version disait déjà qu'il y en avait plusieurs ; il ne disait pas lesquelles.**
 * « v4 » sans historique pose exactement la question à laquelle il faut répondre : qu'est-ce qui a
 * changé, quand, et par qui. Sans réponse, la seule façon de retrouver une version précédente est
 * de demander à quelqu'un qui s'en souvient.
 *
 * **L'empreinte du fichier est affichée**, tronquée. C'est ce qui distingue un vrai remplacement
 * d'un redépôt du même fichier — et un document déposé deux fois à l'identique n'est pas une
 * correction, c'est une fausse manœuvre qu'il vaut mieux voir.
 *
 * ⚠ La liste des versions est chargée entière puis filtrée ici. `DocumentVersion` n'expose pas de
 * filtre par document, et lui en ajouter un ferait entrer une référence dans la famille D58 — où un
 * filtre rend soit tout, soit rien, sans jamais lever.
 */
function HistoriqueVersions({ document: doc, versions, onFermer }) {
  const miennes = (versions || [])
    .filter((v) => {
      const ref = v.document
      const id = typeof ref === 'string' ? ref.split('/').pop() : ref?.id
      return doc && String(id) === String(doc.id)
    })
    .sort((a, b) => (b.versionNumber || 0) - (a.versionNumber || 0))

  return (
    <Modal open={!!doc} onClose={onFermer} titre={doc ? `Versions — ${doc.title}` : ''} taille="md">
      {miennes.length === 0 ? (
        <div className="sub">
          L’historique n’a pas pu être chargé, ou ce document n’a qu’une seule version.
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th className="num">Version</th>
                <th>Fichier</th>
                <th className="num">Taille</th>
                <th className="num">Déposée le</th>
                <th>Par</th>
                <th>Empreinte</th>
              </tr>
            </thead>
            <tbody>
              {miennes.map((v) => (
                <tr key={v.id}>
                  <td className="num">v{v.versionNumber}</td>
                  <td>{v.originalFilename || '—'}</td>
                  <td className="num">{poids(v.sizeBytes)}</td>
                  <td className="num">{quand(v.createdAt)}</td>
                  <td>{nomOuAbsence(v.uploadedBy, '—')}</td>
                  <td><span className="mono sub">{String(v.fileHash || '').slice(0, 12) || '—'}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  )
}

/**
 * RENOMMER ET RECLASSER UN DOCUMENT.
 *
 * **Le titre vient du nom du fichier déposé, et le nom d'un fichier est rarement un titre.**
 * « scan_2026-08-27_001.pdf » est ce qu'écrit un scanner, pas ce qu'un collègue cherchera dans six
 * mois. Sans moyen de le corriger, la bibliothèque se remplit de noms de machine — et une
 * bibliothèque qu'on ne peut pas parcourir sert autant qu'une pile.
 *
 * **La catégorie est modifiable pour la même raison** : elle est choisie au dépôt, souvent au
 * jugé, et c'est elle qui décide du filtre où le document réapparaîtra.
 *
 * **L'établissement, lui, ne bouge pas** (RG-DMS-04) : il n'est pas dans le groupe d'écriture du
 * serveur, et le déplacer reviendrait à faire passer une pièce comptable d'un exploitant à un autre.
 */
function RenommerDocument({ document: doc, onFermer, onRenomme, onErreur }) {
  const [titre, setTitre] = useState('')
  const [categorie, setCategorie] = useState('other')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!doc) return
    setTitre(doc.title || '')
    setCategorie(doc.category || 'other')
  }, [doc])

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      await api.majDocumentDms(doc.id, { title: titre.trim(), category: categorie })
      await onRenomme('Document renommé.')
    } catch (e) {
      onErreur(e.message || 'Le document n’a pas pu être renommé.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={!!doc} onClose={onFermer} titre="Renommer le document" taille="sm">
      <div style={{ display: 'grid', gap: 12 }}>
        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Titre</span>
          <input className="input" value={titre} onChange={(e) => setTitre(e.target.value)} maxLength={255} />
        </label>

        <label style={{ display: 'grid', gap: 4 }}>
          <span className="sub">Catégorie</span>
          <select className="select" value={categorie} onChange={(e) => setCategorie(e.target.value)}>
            {CATEGORIES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </label>

        {doc?.currentVersion?.originalFilename && (
          <div className="sub">
            Fichier déposé : <span className="mono">{doc.currentVersion.originalFilename}</span> — il
            ne change pas, seul le titre affiché change.
          </div>
        )}

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer} disabled={busy}>Annuler</button>
          <button className="btn primary" type="button" onClick={enregistrer} disabled={busy || titre.trim() === ''}>
            Enregistrer
          </button>
        </div>
      </div>
    </Modal>
  )
}


/**
 * LA CONSERVATION - attacher, prolonger ou LEVER une politique (RG-DMS-16).
 *
 * Deux facons de la poser, et le processeur refuse qu'on ne dise ni l'une ni l'autre :
 *   - un CODE de politique : la date se calcule (aujourd'hui + duree de la politique) ;
 *   - une DATE choisie a la main, qui prend le pas.
 *
 * ⚠ ET LEVER UNE CONSERVATION SE DIT EN ENVOYANT LES DEUX A `null`. Ce n'est pas un champ vide
 * qu'on oublie : c'est une demande explicite, et l'ecran doit l'offrir - sans elle, une piece
 * placee par erreur sous conservation devient indestructible et personne ne sait pourquoi.
 */
function ConservationDocument({ document: doc, onFermer, onFait, onErreur }) {
  const [politiques, setPolitiques] = useState([])
  const [code, setCode] = useState('')
  const [dateLimite, setDateLimite] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!doc) return
    setCode('')
    setDateLimite(doc.retainUntil ? String(doc.retainUntil).slice(0, 10) : '')
    let annule = false
    api.politiquesRetention()
      .then((r) => { if (!annule) setPolitiques(membres(r)) })
      // Le catalogue n'est pas indispensable : sans lui on garde la date a la main. On n'affiche
      // donc pas d'erreur - on retire l'option qui ne peut pas marcher.
      .catch(() => { if (!annule) setPolitiques([]) })
    return () => { annule = true }
  }, [doc])

  async function poser(lever) {
    setBusy(true)
    onErreur(null)
    try {
      await api.poserRetentionDocument(doc.id, lever
        ? { retentionPolicyCode: null, retainUntilOverride: null }
        : { retentionPolicyCode: code || null, retainUntilOverride: dateLimite || null })
      await onFait(lever ? 'Conservation levée.' : 'Conservation enregistrée.')
    } catch (e) {
      onErreur(e.message || 'La conservation n’a pas pu être enregistrée.')
    } finally {
      setBusy(false)
    }
  }

  const rien = !code && !dateLimite

  return (
    <Modal open={!!doc} onClose={onFermer} titre="Conservation du document" taille="sm">
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div className="sub">
          {doc?.retainUntil
            ? <>Ce document est conservé jusqu’au <b>{quand(doc.retainUntil)}</b> : il ne peut pas être supprimé d’ici là.</>
            : <>Ce document n’est sous aucune conservation : il peut être supprimé.</>}
        </div>

        {politiques.length > 0 && (
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Politique</span>
            <select className="select" value={code} onChange={(e) => setCode(e.target.value)}>
              <option value="">— choisir —</option>
              {politiques.map((p) => (
                <option key={p.id} value={p.code}>{p.code} · {p.durationMonths} mois</option>
              ))}
            </select>
          </label>
        )}

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Ou une date précise</span>
          <input
            className="input"
            type="date"
            value={dateLimite}
            onChange={(e) => setDateLimite(e.target.value)}
          />
          <span className="sub">Si les deux sont renseignés, c’est la date qui compte.</span>
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'space-between', marginTop: 'var(--esp-serre)' }}>
          {doc?.retainUntil ? (
            <button className="btn ghost" type="button" disabled={busy} onClick={() => poser(true)}>
              Lever la conservation
            </button>
          ) : <span />}
          <button className="btn" type="button" disabled={busy || rien} onClick={() => poser(false)}>
            Enregistrer
          </button>
        </div>
      </div>
    </Modal>
  )
}

/**
 * LE LIEN PUBLIC - une adresse qui sort de l'application et que personne n'aura a authentifier.
 *
 * ⚠ ON L'AFFICHE UNE FOIS, ET ON LE DIT. Le jeton n'est pas relisible : la reponse le porte, et
 * l'ecran est le seul endroit ou il passera. Fermer la fenetre sans le copier oblige a en emettre
 * un second - ce qui est sans gravite, a condition de le savoir AVANT de fermer.
 */
function LienPublicDocument({ document: doc, onFermer, onFait, onErreur }) {
  const [jours, setJours] = useState('7')
  const [busy, setBusy] = useState(false)
  const [lien, setLien] = useState(null)

  useEffect(() => { setJours('7'); setLien(null) }, [doc])

  async function emettre() {
    setBusy(true)
    onErreur(null)
    try {
      const r = await api.emettreLienPublicDocument(doc.id, {
        documentId: String(doc.id),
        expiresInDays: Number(jours),
        // La version courante est celle que l'ecran montre. Ne rien envoyer laisserait le serveur
        // choisir, et « le lien ne pointe pas sur ce que je regardais » est un defaut invisible.
        versionId: doc.currentVersion?.id ? String(doc.currentVersion.id) : null,
      })
      // ⚠ On ne devine pas le nom du champ : on prend le premier qui ressemble a une adresse, et on
      // le DIT quand il n'y en a pas, plutot que d'afficher une chaine vide victorieuse.
      const url = r?.url || r?.publicUrl || r?.link || r?.token || null
      setLien(url)
      onFait(url ? 'Lien émis.' : 'Lien émis — le serveur n’en a pas rendu l’adresse.')
    } catch (e) {
      onErreur(e.message || 'Le lien n’a pas pu être émis.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={!!doc} onClose={onFermer} titre="Émettre un lien public" taille="sm">
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div className="sub">
          Ce lien donne accès au document <b>sans connexion</b>, à qui le possède. Il expire tout
          seul, et peut être révoqué avant.
        </div>

        {lien === null ? (
          <>
            <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
              <span className="sub">Valable</span>
              <select className="select" value={jours} onChange={(e) => setJours(e.target.value)}>
                <option value="1">1 jour</option>
                <option value="7">7 jours</option>
                <option value="30">30 jours</option>
                <option value="90">90 jours</option>
              </select>
            </label>
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <button className="btn" type="button" disabled={busy} onClick={emettre}>Émettre</button>
            </div>
          </>
        ) : (
          <>
            <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
              <span className="sub">L’adresse — copiez-la maintenant, elle ne se relit pas</span>
              <input className="input" readOnly value={lien} onFocus={(e) => e.target.select()} />
            </label>
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <button className="btn ghost" type="button" onClick={onFermer}>Fermer</button>
            </div>
          </>
        )}
      </div>
    </Modal>
  )
}

/**
 * LA SUPPRESSION - logique, et refusee si une conservation court (RG-DMS-13).
 *
 * La fenetre dit CE QUI VA SE PASSER plutot que "etes-vous sur", patron repris de
 * `ImpayesRecouvrement`. Et elle nomme d'avance le refus possible : un 409 sur un document sous
 * conservation n'est pas une panne, c'est la regle qui fonctionne.
 */
function SuppressionDocument({ document: doc, onFermer, onFait, onErreur }) {
  const [busy, setBusy] = useState(false)
  const retenu = !!doc?.retainUntil

  async function supprimer() {
    setBusy(true)
    onErreur(null)
    try {
      await api.supprimerDocumentDms(doc.id)
      await onFait('Document supprimé.')
    } catch (e) {
      onErreur(e.message || 'Le document n’a pas pu être supprimé.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={!!doc} onClose={onFermer} titre="Supprimer le document" taille="sm">
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <div>
          <b>{doc?.title}</b> sortira des listes et ne sera plus téléchargeable. Ses versions restent
          en base : la suppression est réversible par l’administrateur, elle n’efface rien du disque.
        </div>
        {retenu && (
          <div className="banner banner-warn">
            Ce document est conservé jusqu’au <b>{quand(doc.retainUntil)}</b>. Le serveur refusera la
            suppression tant que cette date n’est pas passée — levez d’abord la conservation.
          </div>
        )}
        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn" type="button" disabled={busy} onClick={supprimer}>Supprimer</button>
        </div>
      </div>
    </Modal>
  )
}
