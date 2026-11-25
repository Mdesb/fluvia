import { useState } from 'react'
import { api } from '../api/client.js'
import { idDe } from '../api/iri.js'
import { confirmer } from './Confirmation.jsx'
import {
  corpsNumeroClient,
  libelleEtat,
  messageErreurNumeroClient,
  messageNumeroClient,
  numeroClientValide,
  peutConfigurerPaiement,
  profilEligiblePayFip,
  texteConfirmationEffacement,
} from './paiementPayFip.js'

// SECTION « PAIEMENT PAR PAYFIP » DE L'ÉCRAN DU PROFIL EXPLOITANT (lot D2-B de #365, G-6).
//
// Le numéro client PayFiP (NUMCLI) n'est PAS un secret : le serveur le rend avec le profil, et le champ est
// pré-rempli (contrairement à la clé Stripe). Le serveur le jugera de toute façon : 6 chiffres, régie directe,
// droit `payment.configure`, établissement principal. L'écran ne fait que prévenir.
//
// ⚠ ELLE N'EST MONTÉE QUE POUR LE PROFIL DE L'ÉTABLISSEMENT ACTIF, avec le droit et pour une régie directe :
// l'écriture n'est possible que depuis l'établissement principal du profil (404 sinon), qui est celui que cet
// écran affiche. Le droit est reçu en propriété (`droits`), jamais deviné.
//
// ⚠ MODE (TEST, ACTIVATION, RÉEL) ET ADRESSE DE NOTIFICATION SONT COMMUNS À TOUTES LES RÉGIES : ils ne se
// règlent pas ici, mais dans l'environnement du serveur. Tant qu'une régie est en test, aucune autre ne peut
// passer en réel.

export default function PaiementPayFip({ profil, droits }) {
  const profilId = idDe(profil)
  const autorise = peutConfigurerPaiement(droits)
  const eligible = profilEligiblePayFip(profil?.type)

  // Pré-rempli : la valeur n'est pas secrète. `enregistre` est ce que le serveur a confirmé, `numero` la saisie.
  const [enregistre, setEnregistre] = useState(profil?.payfipClientNumber ?? '')
  const [numero, setNumero] = useState(profil?.payfipClientNumber ?? '')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState('')
  const [info, setInfo] = useState('')

  if (!autorise || !profilId || !eligible) return null

  const faute = messageNumeroClient(numero)
  const actif = String(enregistre ?? '').trim() !== ''

  async function enregistrer(e) {
    e.preventDefault()
    if (!numeroClientValide(numero)) return
    setEnCours(true)
    setErreur('')
    setInfo('')
    try {
      const maj = await api.majNumeroClientPayFip(profilId, corpsNumeroClient(numero))
      const valeur = maj?.payfipClientNumber ?? ''
      setEnregistre(valeur)
      setNumero(valeur)
      setInfo('Numéro client enregistré. Les paiements déjà ouverts gardent le numéro avec lequel ils ont été initiés.')
    } catch (err) {
      setErreur(messageErreurNumeroClient(err))
    } finally {
      setEnCours(false)
    }
  }

  async function effacer() {
    if (!actif) return
    if (!(await confirmer(texteConfirmationEffacement()))) return
    setEnCours(true)
    setErreur('')
    setInfo('')
    try {
      await api.majNumeroClientPayFip(profilId, corpsNumeroClient(''))
      setEnregistre('')
      setNumero('')
      setInfo('Numéro client effacé.')
    } catch (err) {
      setErreur(messageErreurNumeroClient(err))
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div className="field">
      <h4>Paiement par PayFiP</h4>

      <p className="hint">
        Ce numéro est communiqué par votre correspondant moyens de paiement de la DDFiP lors de l’adhésion à PayFiP.
        Le mode (test, activation, réel) et l’adresse de notification sont communs à toutes les régies : ils se
        règlent sur le serveur, pas ici.
      </p>

      <p className={actif ? 'banner banner-ok' : 'banner banner-warn'} role="status">
        {libelleEtat(enregistre)}
      </p>

      <form onSubmit={enregistrer}>
        <div className="field">
          <label htmlFor="ppf-numero">Numéro client PayFiP (6 chiffres)</label>
          <input
            id="ppf-numero"
            type="text"
            className="input"
            inputMode="numeric"
            maxLength={6}
            autoComplete="off"
            spellCheck={false}
            value={numero}
            disabled={enCours}
            aria-invalid={faute ? 'true' : 'false'}
            onChange={(ev) => setNumero(ev.target.value)}
          />
          {faute ? <small className="hint" role="alert">{faute}</small> : null}
        </div>

        {erreur ? <p className="banner banner-error" role="alert">{erreur}</p> : null}
        {info ? <p className="banner banner-ok" role="status">{info}</p> : null}

        <button
          type="submit"
          className="btn primary"
          disabled={enCours || !numeroClientValide(numero)}
        >
          {enCours ? 'Enregistrement…' : 'Enregistrer'}
        </button>
        {actif ? (
          <>
            {' '}
            <button type="button" className="btn danger" disabled={enCours} onClick={effacer}>
              Effacer
            </button>
          </>
        ) : null}
      </form>
    </div>
  )
}
