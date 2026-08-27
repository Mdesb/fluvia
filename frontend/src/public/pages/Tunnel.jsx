import { useEffect, useState } from 'react'
import { boutique, clientTokenStore, panierStore } from '../api/boutiqueClient.js'
import { libelleProduit, libelleCreneau, iriId, eurosCentimes, euros } from '../lib/format.js'
import { Etapes, Erreur, Chargement } from '../components/Etats.jsx'
import Qr from '../../components/Qr.jsx'

const ETAPES = ['Identification', 'Bénéficiaires', 'Consentement', 'Paiement', 'Confirmation']

// Tunnel d'achat : identification → bénéficiaires → consentement RGPD → paiement (PSP simulé) →
// confirmation. Chaque étape appelle un processor dédié du back et rafraîchit le panier.
export default function Tunnel({
  panier,
  vitrineId,
  metaProduits,
  metaCreneaux,
  langue,
  connecte,
  onPanierMaj,
  onConnexionClient,
  onCommandeConfirmee,
  onNaviguer,
}) {
  const [etape, setEtape] = useState(0)
  const [resultatPaiement, setResultatPaiement] = useState(null) // réponse de /payer
  // Contexte panier (id + jeton) mémorisé au moment du paiement : le panier est purgé du stockage
  // local par onCommandeConfirmee, mais on en a encore besoin pour récupérer les billets invité.
  const [infoBillets, setInfoBillets] = useState(null)

  const lignes = panier?.lignes || []

  // Panier vidé (ex. expiration) : on renvoie proprement vers le panier.
  useEffect(() => {
    if (etape < 4 && lignes.length === 0) onNaviguer({ vue: 'panier' })
  }, [lignes.length, etape])

  return (
    <section aria-labelledby="bq-tunnel-titre">
      <h1 id="bq-tunnel-titre" className="sr-only">
        Commande — {ETAPES[etape]}
      </h1>
      <Etapes etapes={ETAPES} courant={etape} />

      {etape === 0 && (
        <EtapeIdentification
          panier={panier}
          onConnexionClient={onConnexionClient}
          onOk={(p) => {
            onPanierMaj(p)
            setEtape(1)
          }}
        />
      )}
      {etape === 1 && (
        <EtapeBeneficiaires
          panier={panier}
          metaProduits={metaProduits}
          metaCreneaux={metaCreneaux}
          langue={langue}
          onRetour={() => setEtape(0)}
          onOk={(p) => {
            onPanierMaj(p)
            setEtape(2)
          }}
        />
      )}
      {etape === 2 && (
        <EtapeConsentement
          panier={panier}
          metaProduits={metaProduits}
          langue={langue}
          onRetour={() => setEtape(1)}
          onOk={(p) => {
            onPanierMaj(p)
            setEtape(3)
          }}
        />
      )}
      {etape === 3 && (
        <EtapePaiement
          panier={panier}
          resultat={resultatPaiement}
          setResultat={setResultatPaiement}
          onConfirme={() => {
            // On capture id + jeton AVANT la purge du panier, pour les billets invité.
            setInfoBillets({ panierId: panier?.id, panierToken: panierStore.getToken() })
            onCommandeConfirmee()
            setEtape(4)
          }}
        />
      )}
      {etape === 4 && (
        <EtapeConfirmation
          resultat={resultatPaiement}
          infoBillets={infoBillets}
          connecte={connecte}
          onNaviguer={onNaviguer}
        />
      )}
    </section>
  )
}

/* ----------------------------- Étape 1 : identification ----------------------------- */
function EtapeIdentification({ panier, onConnexionClient, onOk }) {
  const [mode, setMode] = useState('invite')
  const [email, setEmail] = useState(panier?.contactConnu || '')
  const [motDePasse, setMotDePasse] = useState('')
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setBusy(true)
    try {
      const corps =
        mode === 'compte'
          ? { mode, email: email.trim(), motDePasse }
          : mode === 'franceconnect'
            ? { mode, franceConnectCode: code.trim() }
            : { mode: 'invite', email: email.trim() || undefined }
      const p = await boutique.identifier(panier.id, corps)
      // Compte existant : on ouvre aussi une session client (JWT) pour que les billets QR
      // soient consultables dès la confirmation et dans « Mon compte ».
      if (mode === 'compte') {
        try {
          const auth = await boutique.login(email.trim(), motDePasse)
          if (auth?.token) {
            clientTokenStore.set(auth.token)
            onConnexionClient?.()
          }
        } catch {
          /* l'identification du panier a réussi ; la session JWT est un bonus non bloquant. */
        }
      }
      onOk(p)
    } catch (err) {
      setErreur(err?.status === 401 ? 'Identifiants invalides.' : err?.message || 'Identification impossible.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="card bq-etape" onSubmit={soumettre}>
      <div className="card-h">
        <h2>Comment souhaitez-vous continuer ?</h2>
      </div>
      <div className="card-b">
        <div className="seg bq-modes" role="tablist" aria-label="Mode d'identification">
          {[
            ['invite', 'Achat rapide'],
            ['compte', 'J\'ai un compte'],
            ['franceconnect', 'FranceConnect'],
          ].map(([cle, lib]) => (
            <button
              key={cle}
              type="button"
              role="tab"
              aria-selected={mode === cle}
              className={mode === cle ? 'on' : ''}
              onClick={() => {
                setMode(cle)
                setErreur(null)
              }}
            >
              {lib}
            </button>
          ))}
        </div>

        <Erreur message={erreur} id="bq-id-err" />

        {mode === 'invite' && (
          <div className="field">
            <label htmlFor="bq-id-email">Adresse e-mail (pour recevoir vos billets)</label>
            <input
              id="bq-id-email"
              className="input"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              aria-describedby={erreur ? 'bq-id-err' : undefined}
            />
          </div>
        )}

        {mode === 'compte' && (
          <>
            <div className="field">
              <label htmlFor="bq-id-email2">Adresse e-mail</label>
              <input
                id="bq-id-email2"
                className="input"
                type="email"
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>
            <div className="field">
              <label htmlFor="bq-id-mdp">Mot de passe</label>
              <input
                id="bq-id-mdp"
                className="input"
                type="password"
                autoComplete="current-password"
                value={motDePasse}
                onChange={(e) => setMotDePasse(e.target.value)}
                required
              />
            </div>
          </>
        )}

        {mode === 'franceconnect' && (
          <div className="field">
            <label htmlFor="bq-id-fc">Code FranceConnect (simulation)</label>
            <input
              id="bq-id-fc"
              className="input"
              type="text"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="code-demo"
            />
            <p className="hint">FranceConnect est simulé côté serveur pour cette démo.</p>
          </div>
        )}

        <button type="submit" className="btn primary lg" disabled={busy}>
          {busy ? 'Validation…' : 'Continuer'}
        </button>
      </div>
    </form>
  )
}

/* ----------------------------- Étape 2 : bénéficiaires ----------------------------- */
function EtapeBeneficiaires({ panier, metaProduits, metaCreneaux, langue, onRetour, onOk }) {
  const lignes = panier?.lignes || []
  const [valeurs, setValeurs] = useState(() =>
    Object.fromEntries(
      lignes.map((l) => [
        l.id,
        {
          nom: l.beneficiaireSimple?.nom || '',
          prenom: l.beneficiaireSimple?.prenom || '',
          dateNaissance: l.beneficiaireSimple?.dateNaissance || '',
        },
      ]),
    ),
  )
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  function maj(id, champ, val) {
    setValeurs((v) => ({ ...v, [id]: { ...v[id], [champ]: val } }))
  }

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    // Chaque article doit porter un bénéficiaire (RG-M4-02 / CA-7).
    for (const l of lignes) {
      const v = valeurs[l.id]
      if (!v?.nom?.trim() || !v?.prenom?.trim()) {
        setErreur('Merci de renseigner le nom et le prénom de chaque bénéficiaire.')
        return
      }
    }
    setBusy(true)
    try {
      const payload = lignes.map((l) => ({
        ligneId: l.id,
        beneficiaireSimple: {
          nom: valeurs[l.id].nom.trim(),
          prenom: valeurs[l.id].prenom.trim(),
          dateNaissance: valeurs[l.id].dateNaissance || undefined,
        },
      }))
      const p = await boutique.beneficiaires(panier.id, payload)
      onOk(p)
    } catch (err) {
      setErreur(err?.message || "L'enregistrement des bénéficiaires a échoué.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="card bq-etape" onSubmit={soumettre}>
      <div className="card-h">
        <h2>À qui sont destinés les billets ?</h2>
      </div>
      <div className="card-b">
        <Erreur message={erreur} id="bq-benef-err" />
        <ul className="bq-benef-list">
          {lignes.map((l, i) => {
            const meta = metaProduits?.[iriId(l.produit)]
            const nom = meta ? libelleProduit(meta, langue) : 'Billet'
            const cr = l.creneau ? metaCreneaux?.[iriId(l.creneau)] : null
            return (
              <li key={l.id} className="bq-benef">
                <p className="bq-benef-t">
                  {nom}
                  {(l.quantite || 1) > 1 ? ` ×${l.quantite}` : ''}
                  {cr && <span className="bq-benef-cr"> · {libelleCreneau(cr.debut, cr.fin)}</span>}
                  {l.montantLigne != null && (
                    <span className="bq-benef-montant"> · {euros(l.montantLigne)}</span>
                  )}
                </p>
                <div className="bq-benef-grid">
                  <div className="field">
                    <label htmlFor={`bq-b-prenom-${i}`}>Prénom</label>
                    <input
                      id={`bq-b-prenom-${i}`}
                      className="input"
                      value={valeurs[l.id]?.prenom || ''}
                      onChange={(e) => maj(l.id, 'prenom', e.target.value)}
                      autoComplete="given-name"
                      required
                    />
                  </div>
                  <div className="field">
                    <label htmlFor={`bq-b-nom-${i}`}>Nom</label>
                    <input
                      id={`bq-b-nom-${i}`}
                      className="input"
                      value={valeurs[l.id]?.nom || ''}
                      onChange={(e) => maj(l.id, 'nom', e.target.value)}
                      autoComplete="family-name"
                      required
                    />
                  </div>
                  <div className="field">
                    <label htmlFor={`bq-b-dn-${i}`}>Date de naissance</label>
                    <input
                      id={`bq-b-dn-${i}`}
                      className="input"
                      type="date"
                      value={valeurs[l.id]?.dateNaissance || ''}
                      onChange={(e) => maj(l.id, 'dateNaissance', e.target.value)}
                      autoComplete="bday"
                    />
                    <p className="hint">Requise pour les mineurs (autorisation parentale à l'étape suivante).</p>
                  </div>
                </div>
              </li>
            )
          })}
        </ul>
        {panier?.total != null && (
          <div className="bq-recap-row bq-recap-total" style={{ marginTop: 12 }}>
            <span>Total</span>
            <strong>{euros(panier.total)}</strong>
          </div>
        )}
        <div className="bq-etape-actions">
          <button type="button" className="btn" onClick={onRetour}>
            Retour
          </button>
          <button type="submit" className="btn primary" disabled={busy}>
            {busy ? 'Enregistrement…' : 'Continuer'}
          </button>
        </div>
      </div>
    </form>
  )
}

/* ----------------------------- Étape 3 : consentement ----------------------------- */
function EtapeConsentement({ panier, metaProduits, langue, onRetour, onOk }) {
  const lignes = panier?.lignes || []
  const lignesMineurs = lignes.filter((l) => l.autorisationParentaleRequise)
  const [rgpd, setRgpd] = useState(false)
  const [parentales, setParentales] = useState({})
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    if (!rgpd) {
      setErreur('Vous devez accepter le traitement de vos données pour continuer.')
      return
    }
    for (const l of lignesMineurs) {
      if (!parentales[l.id]) {
        setErreur('Une autorisation parentale est requise pour chaque bénéficiaire mineur.')
        return
      }
    }
    setBusy(true)
    try {
      const p = await boutique.consentement(panier.id, {
        rgpd: true,
        autorisationsParentales: Object.fromEntries(lignesMineurs.map((l) => [l.id, !!parentales[l.id]])),
      })
      onOk(p)
    } catch (err) {
      setErreur(err?.message || "L'enregistrement du consentement a échoué.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="card bq-etape" onSubmit={soumettre}>
      <div className="card-h">
        <h2>Consentement</h2>
      </div>
      <div className="card-b">
        <Erreur message={erreur} id="bq-cons-err" />

        <label className="bq-consent">
          <input
            type="checkbox"
            checked={rgpd}
            onChange={(e) => setRgpd(e.target.checked)}
            aria-describedby={erreur ? 'bq-cons-err' : undefined}
            required
          />
          <span>
            J'accepte que mes données personnelles soient traitées pour la gestion de ma commande,
            conformément au RGPD.
          </span>
        </label>

        {lignesMineurs.length > 0 && (
          <fieldset className="bq-fieldset">
            <legend>Autorisation parentale (bénéficiaires mineurs)</legend>
            {lignesMineurs.map((l) => {
              const meta = metaProduits?.[iriId(l.produit)]
              const nom = meta ? libelleProduit(meta, langue) : 'Billet'
              const b = l.beneficiaireSimple
              const qui = b ? `${b.prenom || ''} ${b.nom || ''}`.trim() : nom
              return (
                <label key={l.id} className="bq-consent">
                  <input
                    type="checkbox"
                    checked={!!parentales[l.id]}
                    onChange={(e) => setParentales((p) => ({ ...p, [l.id]: e.target.checked }))}
                  />
                  <span>
                    J'autorise la participation de <strong>{qui || 'ce mineur'}</strong> ({nom}).
                  </span>
                </label>
              )
            })}
          </fieldset>
        )}

        <div className="bq-etape-actions">
          <button type="button" className="btn" onClick={onRetour}>
            Retour
          </button>
          <button type="submit" className="btn primary" disabled={busy}>
            {busy ? 'Validation…' : 'Aller au paiement'}
          </button>
        </div>
      </div>
    </form>
  )
}

/* ----------------------------- Étape 4 : paiement (PSP simulé) ----------------------------- */
function EtapePaiement({ panier, resultat, setResultat, onConfirme }) {
  const [phase, setPhase] = useState('init') // init | pret | traitement | echec
  const [erreur, setErreur] = useState(null)

  // Initie le paiement en arrivant sur l'étape (crée la vente, renvoie la référence + montant).
  useEffect(() => {
    if (resultat) {
      setPhase('pret')
      return
    }
    let annule = false
    setPhase('init')
    setErreur(null)
    boutique
      .payer(panier.id)
      .then((r) => {
        if (annule) return
        setResultat(r)
        setPhase('pret')
      })
      .catch((e) => {
        if (annule) return
        setErreur(e?.message || "L'initiation du paiement a échoué.")
        setPhase('echec')
      })
    return () => {
      annule = true
    }
  }, [])

  async function retour(statutTpe) {
    setErreur(null)
    setPhase('traitement')
    try {
      const r = await boutique.retourPaiement(panier.id, {
        referenceTransaction: resultat.referenceTransaction,
        statut: statutTpe,
        montantCentimes: resultat.montantCentimes,
      })
      if (r?.statut === 'confirme' || r?.statut === 'conflit_inventaire') {
        // On mémorise l'éventuel conflit d'inventaire pour l'écran de confirmation.
        setResultat({ ...resultat, retour: r })
        onConfirme()
      } else {
        setErreur('Le paiement a échoué. Vous pouvez réessayer.')
        setPhase('pret')
      }
    } catch (e) {
      setErreur(e?.message || 'Le retour de paiement a échoué.')
      setPhase('pret')
    }
  }

  if (phase === 'init') return <Chargement texte="Préparation du paiement…" />

  if (phase === 'echec' && !resultat) {
    return (
      <div className="card bq-etape">
        <div className="card-b">
          <Erreur message={erreur} />
          <p className="empty" style={{ textAlign: 'left', padding: 0 }}>
            Le paiement n'a pas pu être initié. Vérifiez votre panier et réessayez.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="card bq-etape">
      <div className="card-h">
        <h2>Paiement sécurisé</h2>
      </div>
      <div className="card-b">
        <div className="bq-pay-montant">
          <span>Montant à régler</span>
          <strong>{eurosCentimes(resultat?.montantCentimes)}</strong>
        </div>

        <div className="demo-note" style={{ marginTop: 14 }}>
          ⓘ Environnement de démonstration — le prestataire de paiement est simulé.
        </div>

        {resultat?.urlRedirection && (
          <p className="hint" style={{ wordBreak: 'break-all' }}>
            Redirection prestataire : <span className="mono">{resultat.urlRedirection}</span>
          </p>
        )}
        {resultat?.referenceTransaction && (
          <p className="hint">
            Référence : <span className="mono">{resultat.referenceTransaction}</span>
          </p>
        )}

        <Erreur message={erreur} />

        {phase === 'traitement' ? (
          <Chargement texte="Traitement du paiement…" />
        ) : (
          <div className="bq-etape-actions" style={{ marginTop: 16 }}>
            <button type="button" className="btn primary lg" onClick={() => retour('accepte')}>
              Payer {eurosCentimes(resultat?.montantCentimes)}
            </button>
            <button type="button" className="btn lg" onClick={() => retour('refuse')}>
              Simuler un échec
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

/* ----------------------------- Étape 5 : confirmation ----------------------------- */
function EtapeConfirmation({ resultat, infoBillets, connecte, onNaviguer }) {
  const [billets, setBillets] = useState(null)
  const [chargement, setChargement] = useState(!!infoBillets?.panierId)
  const conflit = resultat?.retour?.statut === 'conflit_inventaire'

  useEffect(() => {
    // Les billets à QR sont désormais accessibles à l'invité via X-Panier-Token (GET
    // /boutique/paniers/{id}/billets) — plus besoin de compte. En cas d'échec ou de liste vide,
    // le rendu bascule sur le repli « billets envoyés par e-mail ».
    if (!infoBillets?.panierId) return
    let annule = false
    setChargement(true)
    boutique
      .panierBillets(infoBillets.panierId, infoBillets.panierToken)
      .then((r) => {
        if (!annule) setBillets(r?.billets || [])
      })
      .catch(() => {
        if (!annule) setBillets([])
      })
      .finally(() => {
        if (!annule) setChargement(false)
      })
    return () => {
      annule = true
    }
  }, [infoBillets])

  const aDesBillets = (billets || []).length > 0

  return (
    <div className="bq-conf">
      <div className="bq-conf-hero">
        <span className="bq-conf-check" aria-hidden="true">
          ✓
        </span>
        <h2>Commande confirmée</h2>
        <p className="bq-sub">
          Merci ! Votre paiement a été accepté
          {resultat?.montantCentimes != null ? ` (${eurosCentimes(resultat.montantCentimes)})` : ''}.
        </p>
      </div>

      {conflit && (
        <div className="banner banner-error" role="alert" style={{ marginTop: 16 }}>
          Un conflit d'inventaire est survenu sur un article. Nos services vous recontacteront ;
          aucun débit indu ne sera conservé.
        </div>
      )}

      <section aria-label="Vos billets" style={{ marginTop: 20 }}>
        <h3 className="bq-conf-sec">Vos billets</h3>
        {chargement ? (
          <Chargement texte="Chargement de vos billets…" />
        ) : aDesBillets ? (
          <ul className="bq-billets">
            {billets.map((b) => (
              <li key={b.billetSupport || b.identifiantSupport} className="bq-billet card">
                <div className="card-b bq-billet-b">
                  <Qr value={b.qrDynamique || b.identifiantSupport} size={110} title="QR du billet" />
                  <div>
                    <p className="bq-billet-id mono">{b.identifiantSupport}</p>
                    {b.passWalletDisponible && <span className="badge info">Wallet disponible</span>}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <div className="card">
            <div className="card-b">
              <p style={{ margin: 0 }}>
                Vos billets à présenter (QR) vous ont été envoyés par e-mail.
                {!connecte && ' Créez un compte pour les retrouver à tout moment dans votre espace.'}
              </p>
            </div>
          </div>
        )}
      </section>

      <div className="bq-etape-actions" style={{ marginTop: 20 }}>
        <button type="button" className="btn primary" onClick={() => onNaviguer({ vue: 'vitrine' })}>
          Retour à la boutique
        </button>
        {connecte && (
          <button type="button" className="btn" onClick={() => onNaviguer({ vue: 'compte' })}>
            Voir mon compte
          </button>
        )}
      </div>
    </div>
  )
}
