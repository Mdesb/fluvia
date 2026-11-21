import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../api/client.js'
import { confirmer } from './Confirmation.jsx'
import {
  adresseNotification,
  corpsEnregistrement,
  libelleMode,
  ligneDuProfil,
  messageErreur,
  peutConfigurerPaiement,
  profilEligible,
  saisieEnvoyable,
  texteConfirmationRetrait,
} from './paiementStripe.js'

// SECTION « PAIEMENT PAR CARTE (STRIPE) » DE L'ÉCRAN DU PROFIL EXPLOITANT (lot D3-B de #365, G-6 et G-9).
//
// ⚠ LES DEUX CHAMPS SONT EN ÉCRITURE SEULE. Le serveur ne rend jamais la clé ni le secret : il rend
// « configurée / non configurée », le mode et des dates. Les champs ne sont donc JAMAIS pré-remplis
// (aucun `value` n'est lié à une donnée du serveur), et ils sont vidés dès l'enregistrement réussi.
// Un champ laissé vide n'est pas envoyé : la valeur déjà enregistrée reste intacte.
//
// ⚠ ELLE N'EST MONTÉE QUE POUR LE PROFIL DE L'ÉTABLISSEMENT ACTIF, avec le droit `payment.configure` :
// le serveur ne lit et n'écrit la configuration que depuis l'établissement principal du profil (404
// sinon). Le droit est reçu en propriété (`droits`), jamais deviné.
//
// ⚠ L'ADRESSE À DÉCLARER CHEZ STRIPE est construite sur l'origine de la page (précédent :
// `VitrinesBoutique.jsx`). À MESURER EN PRÉPROD : derrière un proxy, ce peut ne pas être l'adresse
// publique que Stripe appelle ; le cas échéant, vérifier l'adresse avant de la déclarer.

export default function PaiementStripe({ profil, droits }) {
  const [ligne, setLigne] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [cle, setCle] = useState('')
  const [secret, setSecret] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState('')
  const [info, setInfo] = useState('')
  const [copie, setCopie] = useState('')

  const autorise = peutConfigurerPaiement(droits)
  const eligible = profilEligible(profil?.type)
  const profilId = profil?.id

  // Profil courant : une réponse tardive d'un autre profil n'écrase pas l'écran.
  const profilCourant = useRef(profilId)
  profilCourant.current = profilId
  const minuterieCopie = useRef(null)
  useEffect(() => () => clearTimeout(minuterieCopie.current), [])

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur('')
    try {
      const r = await api.stripeConfigs()
      if (profilCourant.current !== profilId) return
      setLigne(ligneDuProfil(r?.member ?? r?.['hydra:member'] ?? [], profilId))
    } catch (e) {
      setErreur(messageErreur(e))
    } finally {
      setChargement(false)
    }
  }, [profilId])

  useEffect(() => {
    if (autorise && eligible && profilId) charger()
    else setChargement(false)
  }, [autorise, eligible, profilId, charger])

  if (!autorise || !profilId) return null

  if (!eligible) {
    return (
      <div className="field">
        <h4>Paiement par carte (Stripe)</h4>
        <p className="hint">Cette structure encaisse par PayFiP : aucune clé Stripe à saisir.</p>
      </div>
    )
  }

  const existe = Boolean(ligne)
  const mode = libelleMode(ligne?.keyMode)
  const adresse = adresseNotification(window.location.origin, profilId)

  async function enregistrer(e) {
    e.preventDefault()
    if (!saisieEnvoyable({ cle, secret, existe })) return
    setEnCours(true)
    setErreur('')
    setInfo('')
    try {
      const enregistre = existe
        ? await api.majStripeConfig(ligne.id, corpsEnregistrement(cle, secret))
        : await api.creerStripeConfig(corpsEnregistrement(cle, secret, profilId))
      setLigne(enregistre)
      // Les valeurs saisies ne restent jamais à l'écran une fois parties.
      setCle('')
      setSecret('')
      setInfo('Enregistré. La clé et le secret ne sont plus affichés.')
    } catch (err) {
      // Rien n'a été enregistré (conflit, serveur non configuré, objet devenu inaccessible) : la
      // valeur saisie ne reste pas en mémoire. Sur un 422, on la garde pour la corriger.
      if ([404, 409, 503].includes(err?.status)) {
        setCle('')
        setSecret('')
      }
      setErreur(messageErreur(err))
    } finally {
      setEnCours(false)
    }
  }

  async function retirer() {
    if (!existe) return
    if (!(await confirmer(texteConfirmationRetrait(profil?.raisonSociale)))) return
    setEnCours(true)
    setErreur('')
    setInfo('')
    try {
      await api.retirerStripeConfig(ligne.id)
      setLigne(null)
      setCle('')
      setSecret('')
      setInfo('Clé retirée.')
    } catch (err) {
      setErreur(messageErreur(err))
    } finally {
      setEnCours(false)
    }
  }

  async function copier() {
    if (!adresse) return
    try {
      await navigator.clipboard.writeText(adresse)
      setCopie('Copié')
    } catch {
      setCopie('Sélectionnez et copiez à la main')
    }
    clearTimeout(minuterieCopie.current)
    minuterieCopie.current = setTimeout(() => setCopie(''), 2500)
  }

  if (chargement) return <p className="hint" role="status">Chargement du paiement par carte…</p>

  return (
    <div className="field">
      <h4>Paiement par carte (Stripe)</h4>

      <p className="hint">
        Chaque exploitant encaisse sur son propre compte Stripe. Sans clé enregistrée ici, la clé
        globale de la plateforme s’applique, si elle existe.
      </p>

      <p className={existe ? 'banner banner-ok' : 'banner banner-warn'} role="status">
        {existe
          ? `Clé configurée${mode ? ` — ${mode}` : ''}.`
          : 'Aucune clé enregistrée pour cet exploitant.'}
        {existe ? (ligne.hasWebhookSecret ? ' Secret de signature enregistré.' : ' Secret de signature absent.') : ''}
      </p>

      <form onSubmit={enregistrer}>
        <div className="field">
          <label htmlFor="ps-cle">Clé secrète Stripe{existe ? ' (laisser vide pour conserver)' : ' *'}</label>
          <input
            id="ps-cle"
            type="password"
            className="input"
            autoComplete="new-password"
            spellCheck={false}
            placeholder={existe ? 'Enregistrée — saisir pour remplacer' : 'sk_… ou rk_…'}
            value={cle}
            disabled={enCours}
            onChange={(ev) => setCle(ev.target.value)}
          />
          <small className="hint">
            Clé restreinte (rk_…) recommandée, avec l’écriture sur les sessions Checkout. Jamais réaffichée après l’enregistrement.
          </small>
        </div>

        <div className="field">
          <label htmlFor="ps-secret">Secret de signature du webhook{existe ? ' (laisser vide pour conserver)' : ''}</label>
          <input
            id="ps-secret"
            type="password"
            className="input"
            autoComplete="new-password"
            spellCheck={false}
            placeholder={existe && ligne.hasWebhookSecret ? 'Enregistré — saisir pour remplacer' : 'whsec_…'}
            value={secret}
            disabled={enCours}
            onChange={(ev) => setSecret(ev.target.value)}
          />
        </div>

        {erreur ? <p className="banner banner-error" role="alert">{erreur}</p> : null}
        {info ? <p className="banner banner-ok" role="status">{info}</p> : null}

        <button
          type="submit"
          className="btn primary"
          disabled={enCours || !saisieEnvoyable({ cle, secret, existe })}
        >
          {enCours ? 'Enregistrement…' : 'Enregistrer'}
        </button>
        {existe ? (
          <>
            {' '}
            <button type="button" className="btn danger" disabled={enCours} onClick={retirer}>
              Retirer
            </button>
          </>
        ) : null}
      </form>

      <div className="field">
        <label htmlFor="ps-adresse">Adresse de notification à déclarer chez Stripe</label>
        <input id="ps-adresse" type="text" className="input" readOnly value={adresse ?? ''} />
        <button type="button" className="btn ghost sm" onClick={copier} disabled={!adresse}>
          Copier
        </button>
        <span role="status" className="hint">{copie}</span>
        <small className="hint">
          Événements à déclarer : checkout.session.completed et checkout.session.expired.
        </small>
      </div>
    </div>
  )
}
