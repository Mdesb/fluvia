import { useEffect, useState } from 'react'
import { boutique, clientTokenStore, panierStore } from '../api/boutiqueClient.js'
import { libelleProduit, libelleCreneau, iriId, eurosCentimes, euros } from '../lib/format.js'
import { Etapes, Erreur, Chargement } from '../components/Etats.jsx'
import Markdown from '../components/Markdown.jsx'
import Qr from '../../components/Qr.jsx'

const ETAPES = ['Vos billets', 'Paiement', 'Confirmation']

// LES VERSIONS DES DEUX TEXTES DE L'ÉCRAN 1, envoyées au serveur qui les garde comme preuve (#101) :
// la mention d'information sur le panier, la case marketing sur le consentement. Changer l'un de ces
// textes, c'est changer sa version — sinon la preuve désignerait un texte que personne n'a lu.
const VERSION_MENTION = 'mention-2026-10-04'
const VERSION_MARKETING = 'marketing-2026-10-04'
const COURRIEL_VALIDE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

// Tunnel d'achat en 3 étapes (#101) : « Vos billets » (qui, pour qui, information) → paiement
// (prestataire simulé) → confirmation. L'écran 1 enchaîne trois appels du back — identifier,
// bénéficiaires, consentement — dans cet ordre, parce que le consentement n'horodate une autorisation
// parentale que sur les lignes que l'enregistrement des bénéficiaires a marquées « mineur ».
export default function Tunnel({
  panier,
  vitrineId,
  metaProduits,
  metaCreneaux,
  langue,
  connecte,
  etablissementId,
  nomEtablissement,
  onPanierMaj,
  onDeconnexionClient,
  onCommandeConfirmee,
  onNaviguer,
}) {
  const [etape, setEtape] = useState(0)
  const [resultatPaiement, setResultatPaiement] = useState(null) // réponse de /payer
  // Contexte panier (id + jeton) mémorisé au moment du paiement : le panier est purgé du stockage
  // local par onCommandeConfirmee, mais on en a encore besoin pour récupérer les billets invité.
  const [infoBillets, setInfoBillets] = useState(null)

  const lignes = panier?.lignes || []

  // Panier vidé (ex. expiration) pendant qu'on remplit encore la commande : on renvoie proprement
  // vers le panier. ⚠ UNIQUEMENT avant l'étape Paiement (etape < 1). Au paiement réussi, le panier
  // est PURGÉ (transformé en commande), donc `lignes` devient vide : se fier à ce vide aux étapes
  // Paiement (1) et Confirmation (2) renverrait le client vers un panier vide au lieu de son billet.
  // L'étape Paiement gère elle-même un panier expiré (erreur d'initiation), pas besoin de rediriger.
  useEffect(() => {
    if (etape < 1 && lignes.length === 0) onNaviguer({ vue: 'panier' })
  }, [lignes.length, etape])

  return (
    <section aria-labelledby="bq-tunnel-titre">
      <h1 id="bq-tunnel-titre" className="sr-only">
        Commande — {ETAPES[etape]}
      </h1>
      <Etapes etapes={ETAPES} courant={etape} />

      {etape === 0 && (
        <EtapeVosBillets
          panier={panier}
          metaProduits={metaProduits}
          metaCreneaux={metaCreneaux}
          langue={langue}
          connecte={connecte}
          etablissementId={etablissementId}
          nomEtablissement={nomEtablissement}
          onDeconnexionClient={onDeconnexionClient}
          onNaviguer={onNaviguer}
          onOk={(p) => {
            onPanierMaj(p)
            setEtape(1)
          }}
        />
      )}
      {etape === 1 && (
        <EtapePaiement
          panier={panier}
          resultat={resultatPaiement}
          setResultat={setResultatPaiement}
          onConfirme={() => {
            // On capture id + jeton AVANT la purge du panier, pour les billets invité.
            setInfoBillets({ panierId: panier?.id, panierToken: panierStore.getToken() })
            onCommandeConfirmee()
            setEtape(2)
          }}
        />
      )}
      {etape === 2 && (
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

// L'adresse du client connecté, lue dans son jeton (revendication `username` = l'e-mail). Rien n'est
// vérifié ici — c'est le serveur qui authentifie ; on ne fait qu'afficher qui est connecté.
function courrielConnecte() {
  try {
    const charge = clientTokenStore.get()?.split('.')[1]
    if (!charge) return null
    const json = JSON.parse(atob(charge.replace(/-/g, '+').replace(/_/g, '/')))
    return typeof json?.username === 'string' ? json.username : null
  } catch {
    return null
  }
}

// Même règle que le serveur (`AjouterBeneficiairesPanierProcessor`) : mineur = né après aujourd'hui
// moins 18 ans. Le serveur reste l'autorité ; l'écran ne fait que poser la question au bon moment.
function estMineur(dateNaissance) {
  if (!dateNaissance) return false
  const naissance = new Date(`${dateNaissance}T00:00:00`)
  if (Number.isNaN(naissance.getTime())) return false
  const seuil = new Date()
  seuil.setFullYear(seuil.getFullYear() - 18)
  return naissance > seuil
}

/* ----------------------------- Étape 1 : vos billets ----------------------------- */
function EtapeVosBillets({
  panier,
  metaProduits,
  metaCreneaux,
  langue,
  connecte,
  etablissementId,
  nomEtablissement,
  onDeconnexionClient,
  onNaviguer,
  onOk,
}) {
  const lignes = panier?.lignes || []
  // Un compte refusé par cette boutique (autre groupe : 404) achète comme un invité, sans être
  // déconnecté de son compte ailleurs.
  const [compteRefuse, setCompteRefuse] = useState(false)
  const parCompte = connecte && !compteRefuse
  const [email, setEmail] = useState(panier?.contactConnu || '')
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
  const [parentales, setParentales] = useState({})
  const [marketing, setMarketing] = useState(false)
  const [politique, setPolitique] = useState(null) // null = fermée ; { chargement | texte | erreur }
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  function maj(id, champ, val) {
    setValeurs((v) => ({ ...v, [id]: { ...v[id], [champ]: val } }))
  }

  // La case parentale ne se pose que si LE PRODUIT l'exige et que le bénéficiaire est mineur.
  function autorisationDemandee(l) {
    const meta = metaProduits?.[iriId(l.produit)]
    return meta?.parentalConsentRequired === true && estMineur(valeurs[l.id]?.dateNaissance)
  }

  async function ouvrirPolitique() {
    if (politique) {
      setPolitique(null)
      return
    }
    setPolitique({ chargement: true })
    try {
      const r = await boutique.documentsLegaux(etablissementId)
      const page = (r?.documents || []).find((d) => d.slug === 'confidentialite')
      setPolitique(page ? { texte: page.contenu } : { erreur: 'La politique de confidentialité est indisponible.' })
    } catch (e) {
      setPolitique({ erreur: e?.message || 'La politique de confidentialité est indisponible.' })
    }
  }

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    if (!parCompte && !COURRIEL_VALIDE.test(email.trim())) {
      setErreur('Merci de saisir une adresse e-mail valide : c’est par elle que vous recevrez vos billets.')
      return
    }
    for (const l of lignes) {
      const v = valeurs[l.id]
      if (!v?.nom?.trim() || !v?.prenom?.trim()) {
        setErreur('Merci de renseigner le prénom et le nom de chaque bénéficiaire.')
        return
      }
      if (autorisationDemandee(l) && !parentales[l.id]) {
        setErreur(`L’autorisation est requise pour ${v.prenom.trim()} (bénéficiaire mineur).`)
        return
      }
    }

    setBusy(true)
    // Trois appels, dans l'ordre. Si l'un échoue, on reste ici et le client renvoie l'ensemble :
    // aucun ne change d'état de façon irréversible (spec #101 §4).
    try {
      try {
        await boutique.identifier(panier.id, parCompte ? { mode: 'session' } : { mode: 'invite', email: email.trim() })
      } catch (err) {
        if (parCompte && err?.status === 401) {
          clientTokenStore.clear()
          onDeconnexionClient?.()
          throw new Error('Votre session a expiré. Saisissez votre e-mail, ou reconnectez-vous.')
        }
        if (parCompte && err?.status === 404) {
          setCompteRefuse(true)
          throw new Error('Votre compte n’est pas valable sur cette boutique. Continuez avec votre adresse e-mail.')
        }
        throw err
      }
      await boutique.beneficiaires(
        panier.id,
        lignes.map((l) => ({
          ligneId: l.id,
          beneficiaireSimple: {
            nom: valeurs[l.id].nom.trim(),
            prenom: valeurs[l.id].prenom.trim(),
            dateNaissance: valeurs[l.id].dateNaissance || undefined,
          },
        })),
      )
      const p = await boutique.consentement(panier.id, {
        mentionVersion: VERSION_MENTION,
        marketing,
        marketingVersion: marketing ? VERSION_MARKETING : undefined,
        autorisationsParentales: Object.fromEntries(lignes.filter(autorisationDemandee).map((l) => [l.id, !!parentales[l.id]])),
      })
      onOk(p)
    } catch (err) {
      setErreur(err?.message || 'L’enregistrement a échoué. Vérifiez vos informations et réessayez.')
    } finally {
      setBusy(false)
    }
  }

  const etablissement = nomEtablissement || 'cet établissement'

  return (
    <form className="card bq-etape" onSubmit={soumettre} noValidate>
      <div className="card-h">
        <h2>Vos billets</h2>
      </div>
      <div className="card-b">
        <Erreur message={erreur} id="bq-vb-err" />

        {parCompte ? (
          <p className="bq-sub">
            Connecté en tant que <strong>{courrielConnecte() || 'votre compte'}</strong>.{' '}
            <button
              type="button"
              className="btn ghost sm"
              onClick={() => {
                clientTokenStore.clear()
                setEmail('')
                onDeconnexionClient?.()
              }}
            >
              Ce n’est pas moi
            </button>
          </p>
        ) : (
          <div className="field">
            <label htmlFor="bq-vb-email">Adresse e-mail</label>
            <input
              id="bq-vb-email"
              className="input"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              aria-describedby="bq-vb-email-aide"
              required
            />
            <p className="hint" id="bq-vb-email-aide">
              Pour recevoir vos billets.
              {!connecte && (
                <>
                  {' '}
                  <button
                    type="button"
                    className="btn ghost sm"
                    onClick={() => onNaviguer({ vue: 'compte', retour: 'tunnel' })}
                  >
                    J’ai déjà un compte
                  </button>
                </>
              )}
            </p>
          </div>
        )}

        <ul className="bq-benef-list">
          {lignes.map((l, i) => {
            const meta = metaProduits?.[iriId(l.produit)]
            const nom = meta ? libelleProduit(meta, langue) : 'Billet'
            const cr = l.creneau ? metaCreneaux?.[iriId(l.creneau)] : null
            const prenom = valeurs[l.id]?.prenom?.trim()
            return (
              <li key={l.id}>
                <p className="bq-benef-t">
                  {nom}
                  {(l.quantite || 1) > 1 ? ` ×${l.quantite}` : ''}
                  {cr && <span className="bq-benef-cr"> · {libelleCreneau(cr.debut, cr.fin)}</span>}
                  {l.montantLigne != null && <span className="bq-benef-montant"> · {euros(l.montantLigne)}</span>}
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
                  </div>
                </div>
                {autorisationDemandee(l) && (
                  <label className="bq-consent">
                    <input
                      type="checkbox"
                      checked={!!parentales[l.id]}
                      onChange={(e) => setParentales((p) => ({ ...p, [l.id]: e.target.checked }))}
                    />
                    <span>
                      J’autorise cet achat pour <strong>{prenom || 'ce bénéficiaire'}</strong> (je suis son parent ou
                      j’agis avec l’accord de ses parents).
                    </span>
                  </label>
                )}
              </li>
            )
          })}
        </ul>

        {panier?.total != null && (
          <div className="bq-recap-row bq-recap-total" style={{ marginTop: 'var(--esp-large)' }}>
            <span>Total</span>
            <strong>{euros(panier.total)}</strong>
          </div>
        )}

        {/* Une MENTION, pas une case : la commande se traite sur la base du contrat, on informe. */}
        <p className="hint" style={{ marginTop: 'var(--esp-bloc)' }}>
          Vos données servent à traiter votre commande et à vous envoyer vos billets.{' '}
          <button type="button" className="btn ghost sm" aria-expanded={!!politique} onClick={ouvrirPolitique}>
            Politique de confidentialité
          </button>
        </p>
        {politique && (
          <div className="bq-legal" style={{ marginBottom: 'var(--esp-bloc)' }}>
            {politique.chargement ? (
              <Chargement texte="Chargement de la politique de confidentialité…" />
            ) : politique.erreur ? (
              <Erreur message={politique.erreur} />
            ) : (
              <Markdown texte={politique.texte} />
            )}
          </div>
        )}

        {/* La seule case de consentement : facultative, décochée par défaut. */}
        <label className="bq-consent">
          <input type="checkbox" checked={marketing} onChange={(e) => setMarketing(e.target.checked)} />
          <span>Recevoir les nouveautés et offres de {etablissement} par e-mail.</span>
        </label>

        <div className="bq-etape-actions">
          <button type="button" className="btn" onClick={() => onNaviguer({ vue: 'panier' })}>
            Retour au panier
          </button>
          <button type="submit" className="btn primary" disabled={busy}>
            {busy ? 'Enregistrement…' : 'Continuer vers le paiement'}
          </button>
        </div>
      </div>
    </form>
  )
}

/* ----------------------------- Étape 2 : paiement (PSP simulé) ----------------------------- */
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
      // ⚠ ON N'ENVOIE PLUS DE STATUT (audit 06/09, constat 1) : le serveur ne le lirait plus. On
      //   envoie le REÇU que le prestataire simulé a remis à l'initiation pour cette issue — le bouton
      //   « Payer » joue le rôle de la page du prestataire. Sans reçu, le serveur répond 422.
      const r = await boutique.retourPaiement(panier.id, {
        referenceTransaction: resultat.referenceTransaction,
        recu: resultat?.simulation?.[statutTpe],
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

/* ----------------------------- Étape 3 : confirmation ----------------------------- */
function EtapeConfirmation({ resultat, infoBillets, connecte, onNaviguer }) {
  const [billets, setBillets] = useState(null)
  const [chargement, setChargement] = useState(!!infoBillets?.panierId)
  const conflit = resultat?.retour?.statut === 'conflit_inventaire'

  useEffect(() => {
    // Les billets à QR sont désormais accessibles à l'invité via X-Panier-Token (GET
    // /boutique/paniers/{id}/billets) — plus besoin de compte. En cas d'échec ou de liste vide,
    // le rendu bascule sur un repli qui dit l'échec et donne la référence à présenter à l'accueil.
    //
    // ⚠ CE REPLI RENVOYAIT VERS UN COURRIEL, jusqu'au 01/09. Il ne s'affiche que lorsque le seul
    // chemin qui marche vient d'échouer — c'est le pire endroit où envoyer quelqu'un vers une
    // boîte aux lettres qui ne recevra rien.
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
              <li key={b.billetSupport || b.identifiantSupport} className="card">
                <div className="card-b bq-billet-b">
                  <Qr value={b.qrDynamique || b.identifiantSupport} size={110} title="QR du billet" />
                  <div>
                    <p className="bq-billet-id mono">{b.identifiantSupport}</p>
                    {b.passWalletDisponible && <span className="badge info">Wallet disponible</span>}
                    {/* Le code de retrait click & collect, À L'ÉCRAN (#101) : il n'était que dans le
                        PDF envoyé par e-mail — et l'envoi n'est pas garanti (#190). */}
                    {b.codeRetrait && (
                      <p className="bq-sub" style={{ marginTop: 'var(--esp-normal)' }}>
                        Code de retrait : <strong className="mono">{b.codeRetrait}</strong>
                        <br />À présenter au guichet pour retirer votre support.
                      </p>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          /* ⚠ CETTE BRANCHE DISAIT « vos billets vous ont ete envoyes par e-mail », au passe et a
             l'affirmative. Elle ne s'affiche QUE lorsque l'affichage des billets vient d'echouer --
             donc elle renvoyait le client vers une boite aux lettres ou rien n'arrivera, au moment
             precis ou il n'a plus rien d'autre. On dit ce qui est vrai et ce qu'il peut faire. */
          <div className="banner banner-warn" role="alert">
            <p style={{ margin: 0 }}>
              <b>Vos billets n’ont pas pu s’afficher.</b> Votre paiement est bien enregistré — c’est
              l’affichage qui a échoué, pas la commande. Rechargez cette page&nbsp;: ils
              réapparaîtront.
            </p>
            {resultat?.referenceTransaction && (
              <p style={{ marginBottom: 0 }}>
                Si l’affichage échoue encore, présentez cette référence à l’accueil&nbsp;:{' '}
                <span className="mono">{resultat.referenceTransaction}</span>
              </p>
            )}
            {!connecte && (
              <p style={{ marginBottom: 0 }}>
                Créez un compte pour retrouver vos billets à tout moment dans votre espace.
              </p>
            )}
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
