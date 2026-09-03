import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { api, membres } from '../api/client.js'
import { mot } from '../api/vocabulaire.js'

// TERMINAUX D'ACCÈS — LES BOÎTIERS QUI INTERROGENT LE SERVEUR POUR DÉCIDER D'OUVRIR.
//
// ⚠ CE BLOC VIVAIT DANS « BADGES & TERMINAUX », ET MAXIME A DIT POURQUOI IL N'Y ÉTAIT PAS BIEN
// (R20) : « la partie terminal doit aller dans topologie et passage ». Un terminal n'est pas une
// entité à part — c'est le PREMIER MAILLON de la chaîne que la topologie décrit déjà : le boîtier
// héberge des contrôleurs, qui commandent des équipements. Et l'enrôler est une INSTALLATION :
// celui qui attribue un badge le matin n'enrôle pas un boîtier.
//
// Composant AUTONOME : il porte son état, son chargement, ses modales et ses bandeaux. Ni son
// ancien parent ni son nouveau ne lui prête d'aide — c'est ce qui permet de le déplacer sans
// reconstruire `agir()` et `agirDepuisModale()` de `Acces.jsx`.
//
// ⚠ LA SÉMANTIQUE D'ERREUR DES MODALES EST REPRISE TELLE QUELLE, et `Acces.jsx` explique pourquoi :
// un geste lancé DEPUIS une modale doit RELANCER, pour que l'erreur s'affiche dans la modale et
// non dans un bandeau caché DERRIÈRE elle. Éprouvé contre la préprod sur un 409 de réappairage.
export default function TerminauxAcces({ etabActif, peutGerer }) {
  const [terminaux, setTerminaux] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [enroler, setEnroler] = useState(false)
  const [secret, setSecret] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      setTerminaux(membres(await api.terminauxAcces()))
      setErreur(null)
    } catch (e) {
      // ⚠ ON NE VIDE PAS LA LISTE SUR UN ÉCHEC. Une liste vidée se lit « aucun terminal enrôlé »,
      // c'est-à-dire le contraire de « je n'ai pas pu lire ». Le bandeau dit ce qui s'est passé.
      setErreur(e.message || 'Terminaux non lus.')
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => { recharger() }, [recharger])

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

  // Voir l'avertissement en tête : celle-ci NE RATTRAPE PAS, pour que la modale montre l'erreur.
  async function agirDepuisModale(fn, message) {
    setErreur(null)
    setSucces(null)
    const r = await fn()
    setSucces(message)
    await recharger()
    return r
  }

  if (chargement) {
    return <div className="center"><div className="spinner" /></div>
  }

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

        <section className="card">
          <div className="card-h">
            <h3>Terminaux</h3>
            <span className="sub">les lecteurs qui interrogent le serveur</span>
            {peutGerer && (
              <div className="actions" style={{ marginLeft: 'auto' }}>
                <button className="btn primary" onClick={() => setEnroler(true)}>Enrôler un terminal d’accès</button>
              </div>
            )}
          </div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Terminal d’accès</th>
                  <th>Référence ITBOX</th>
                  <th>État</th>
                  <th>Dernier appel</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {terminaux.map((t) => (
                  <tr key={t.id}>
                    <td><span className="nm">{t.nom}</span></td>
                    <td className="mono">{t.itboxRef}</td>
                    <td>
                      <span className={`badge ${t.statut === 'actif' ? 'good' : 'crit'}`}>{mot(t.statut)}</span>
                    </td>
                    <td>
                      {t.dernierAppel ? (
                        <>
                          {dateHeure(t.dernierAppel)}
                          {t.dernierAppelReussi === false && (
                            <div className="sub" style={{ color: 'var(--crit)' }}>en échec</div>
                          )}
                        </>
                      ) : (
                        <span className="sub">jamais appelé</span>
                      )}
                    </td>
                    <td className="row actions" style={{ justifyContent: 'flex-end', gap: 6 }}>
                      {peutGerer && t.statut === 'actif' && (
                        <>
                          <button
                            className="btn sm"
                            title="Émet un nouveau jeton et révoque l'ancien. Le terminal doit être reconfiguré avec le nouveau secret, sinon il cesse d'appeler."
                            onClick={async () => {
                              const r = await agir(
                                () => api.rotationJetonTerminal(t.id),
                                `Nouveau jeton émis pour ${t.nom}.`,
                              )
                              if (r?.secret) setSecret({ nom: t.nom, secret: r.secret, rotation: true })
                            }}
                          >
                            Nouveau jeton
                          </button>
                          <button
                            className="btn sm"
                            title="Coupe l'accès de ce terminal au serveur. Irréversible : réutiliser ce matériel demande un nouvel enrôlement."
                            onClick={() =>
                              agir(() => api.revoquerTerminal(t.id), `Terminal ${t.nom} révoqué.`)
                            }
                          >
                            Révoquer
                          </button>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
                {terminaux.length === 0 && (
                  <tr>
                    <td colSpan={5} className="empty">
                      Aucun terminal enrôlé{peutGerer ? '' : ", ou vous n'avez pas le droit de les voir"}.
                      Un terminal est le boîtier qui interroge le serveur pour décider d’ouvrir ; il
                      reçoit à l’enrôlement un secret qui l’identifie.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

      <EnrolerModal
        open={enroler}
        onClose={() => setEnroler(false)}
        onFait={async (corps) => {
          const r = await agirDepuisModale(() => api.enrolerTerminal(corps), `Terminal ${corps.nom} enrôlé.`)
          setEnroler(false)
          if (r?.secret) setSecret({ nom: r.nom || corps.nom, secret: r.secret, rotation: false })
        }}
      />
      <SecretModal secret={secret} onClose={() => setSecret(null)} />
    </>
  )
}

// ---------------------------------------------------------------------------------------------
function EnrolerModal({ open, onClose, onFait }) {
  const [nom, setNom] = useState('')
  const [itboxRef, setItboxRef] = useState('')
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!open) return
    setNom('')
    setItboxRef('')
    setErreur(null)
  }, [open])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    if (!nom.trim() || !itboxRef.trim()) {
      setErreur('Le nom et la référence ITBOX sont obligatoires.')
      return
    }
    setEnCours(true)
    try {
      await onFait({ nom: nom.trim(), itboxRef: itboxRef.trim() })
    } catch (err) {
      setErreur(err.message || "L'enrôlement n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Enrôler un terminal" taille="md">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="field">
          <label htmlFor="en-nom">Nom du terminal *</label>
          <input
            id="en-nom"
            className="input"
            value={nom}
            placeholder="Entrée principale, Tourniquet piscine, …"
            onChange={(e) => setNom(e.target.value)}
          />
          <p className="hint">Le nom que vous lirez dans la supervision. Nommez l’endroit, pas le modèle.</p>
        </div>

        <div className="field">
          <label htmlFor="en-ref">Référence ITBOX *</label>
          <input
            id="en-ref"
            className="input"
            value={itboxRef}
            placeholder="ITBOX-A1"
            onChange={(e) => setItboxRef(e.target.value)}
          />
          <p className="hint">
            La référence du boîtier, telle qu’elle est inscrite dessus. Un même boîtier ne peut être
            enrôlé qu’une fois par établissement : le serveur refuse le second, parce que révoquer
            l’un ne couperait pas l’autre.
          </p>
        </div>

        <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
          <button type="button" className="btn" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn primary" disabled={enCours}>
            {enCours ? 'Enrôlement…' : 'Enrôler'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// ---------------------------------------------------------------------------------------------
// LE SECRET N'EST MONTRÉ QU'UNE FOIS, ET CE N'EST PAS UNE PRÉCAUTION D'AFFICHAGE.
//
// Le serveur ne stocke que son empreinte (RG-SOCLE-06) : personne, pas même un administrateur, ne
// peut le relire ensuite. D'où la case à cocher qui retient le bouton de fermeture : elle oblige à
// LIRE avant de cliquer, sur la seule fenêtre de l'application dont le contenu disparaît.
//
// LA CROIX ET ÉCHAP FERMENT QUAND MÊME, ET C'EST DÉLIBÉRÉ.
//
// Ma première version passait `onClose={() => {}}` pour empêcher toute sortie non confirmée. Éprouvé
// à l'écran : la croix restait affichée et ne faisait plus rien — un bouton mort, exactement le
// défaut que cet écran existe pour supprimer ailleurs.
//
// Et la perte est réparable : « Nouveau jeton » en réémet un. Verrouiller une sortie pour protéger
// d'un incident qui se répare en un clic coûte plus qu'il ne protège. On le dit donc, au lieu de
// l'interdire.
function SecretModal({ secret, onClose }) {
  const [copie, setCopie] = useState(false)

  useEffect(() => { setCopie(false) }, [secret])

  if (!secret) return null

  return (
    <Modal open onClose={onClose} titre="Secret du terminal — à recopier maintenant" taille="md">
      <div className="banner banner-warn">
        Ce secret ne sera plus jamais affiché. Il n’est pas conservé en clair sur le serveur. Recopiez-le
        dans la configuration du terminal <b>{secret.nom}</b> avant de fermer cette fenêtre.
      </div>

      <div className="field">
        <label htmlFor="sec-val">Secret</label>
        <input id="sec-val" className="input mono" readOnly value={secret.secret} onFocus={(e) => e.target.select()} />
      </div>

      {secret.rotation && (
        <p className="hint">
          L’ancien jeton vient d’être révoqué. Tant que le terminal n’est pas reconfiguré avec ce
          secret, il ne peut plus interroger le serveur.
        </p>
      )}

      <p className="hint">
        Si vous fermez sans l’avoir noté : « Nouveau jeton » en émet un autre. Le précédent est alors
        révoqué, et le terminal reste muet tant qu’il n’a pas reçu le nouveau.
      </p>

      <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
        <label className="row" style={{ gap: 6, marginRight: 'auto' }}>
          <input type="checkbox" checked={copie} onChange={(e) => setCopie(e.target.checked)} />
          <span>Je l’ai recopié</span>
        </label>
        <button className="btn primary" disabled={!copie} onClick={onClose}>Fermer</button>
      </div>
    </Modal>
  )
}

// La date et l'heure d'un dernier appel, dans le fuseau de celui qui regarde. Copiée de
// `Acces.jsx`, qui s'en sert encore pour ses propres colonnes.
function dateHeure(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR')
}
