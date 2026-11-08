import { useEffect, useRef, useState } from 'react'
import { boutique, paiementEnCoursStore } from '../api/boutiqueClient.js'
import { Chargement, Erreur } from '../components/Etats.jsx'
import { EtapeConfirmation } from './Tunnel.jsx'

// Au plus une minute d'attente active (12 × 5 s) : au-delà, la banque répondra au serveur sans nous.
const ESSAIS_MAX = 12
const INTERVALLE_MS = 5000

/**
 * LE RETOUR DE L'ACHETEUR APRÈS LE PAIEMENT CHEZ LE PRESTATAIRE (#339).
 *
 * On arrive ici par `/b/<vitrine>/retour-paiement`, complété de `?idop=…` (PayFiP) ou
 * `?session_id=…` (Stripe), ou de rien du tout (annulation). La page ne CROIT rien de ce que
 * l'adresse transporte : elle demande au serveur, qui demande au prestataire. Le paramètre ne sert
 * qu'à désigner la transaction ; à défaut, on reprend celle notée au départ.
 *
 * ⚠ « EN ATTENTE » N'EST PAS UN ÉCHEC. Si le prestataire n'a pas encore l'issue, on ne propose PAS de
 * repayer — l'acheteur a peut-être payé, et paierait deux fois. On réinterroge quelques fois, puis on
 * dit que la confirmation arrivera par courriel : le serveur la recevra de toute façon par la
 * notification du prestataire.
 */
export default function PaymentReturn({ reference, annule, connecte, onCommandeConfirmee, onNaviguer }) {
  const [etat, setEtat] = useState('verification') // verification | attente | confirme | echec | erreur
  const [message, setMessage] = useState('')
  const [resultat, setResultat] = useState(null)
  const [infoBillets, setInfoBillets] = useState(null)
  const essais = useRef(0)

  useEffect(() => {
    const enCours = paiementEnCoursStore.get()
    const ref = reference || enCours?.reference
    if (annule) {
      // Annulé sur la page du prestataire : rien n'a été payé, et Stripe laisse la session « ouverte »
      // jusqu'à son expiration — l'interroger ferait attendre l'acheteur pour rien.
      setEtat('echec')
      return undefined
    }
    if (!enCours?.panierId || !ref) {
      setEtat('erreur')
      setMessage("Nous ne retrouvons pas de paiement en cours sur cet appareil. Si vous avez payé, votre confirmation vous parviendra par courriel.")
      return undefined
    }

    let annuleEffet = false
    let minuterie = null

    const verifier = () => {
      essais.current += 1
      boutique
        .retourPaiement(enCours.panierId, { referenceTransaction: ref }, enCours.panierToken)
        .then((r) => {
          if (annuleEffet) return
          if (r?.statut === 'confirme' || r?.statut === 'conflit_inventaire') {
            paiementEnCoursStore.clear()
            setInfoBillets({ panierId: enCours.panierId, panierToken: enCours.panierToken })
            setResultat({ retour: r })
            onCommandeConfirmee?.()
            setEtat('confirme')
          } else if (r?.statut === 'en_attente') {
            if (essais.current < ESSAIS_MAX) {
              setEtat('attente')
              minuterie = setTimeout(verifier, INTERVALLE_MS)
            } else {
              setEtat('attente-longue')
            }
          } else {
            paiementEnCoursStore.clear()
            setEtat('echec')
          }
        })
        .catch((e) => {
          if (annuleEffet) return
          setEtat('erreur')
          setMessage(e?.message || "La vérification du paiement a échoué.")
        })
    }
    verifier()

    return () => {
      annuleEffet = true
      if (minuterie) clearTimeout(minuterie)
    }
  }, [reference])

  if (etat === 'confirme') {
    return <EtapeConfirmation resultat={resultat} infoBillets={infoBillets} connecte={connecte} onNaviguer={onNaviguer} />
  }

  return (
    <section className="bq-narrow" aria-live="polite">
      <div className="bq-view-head">
        <h1>Votre paiement</h1>
      </div>

      {(etat === 'verification' || etat === 'attente') && (
        <Chargement texte={etat === 'attente' ? 'La banque n’a pas encore donné sa réponse. Nous vérifions…' : 'Vérification du paiement…'} />
      )}

      {etat === 'attente-longue' && (
        <p>
          La réponse de la banque n'est pas encore arrivée. Ne payez pas une seconde fois : dès que le paiement est
          confirmé, vous recevrez vos billets par courriel.
        </p>
      )}

      {etat === 'echec' && (
        <>
          <p>{annule ? 'Vous avez annulé le paiement.' : 'Le paiement n’a pas abouti.'} Aucun montant n'a été débité. Votre panier est conservé.</p>
          <div className="bq-etape-actions">
            <button type="button" className="btn primary" onClick={() => onNaviguer({ vue: 'tunnel' })}>
              Réessayer le paiement
            </button>
          </div>
        </>
      )}

      {etat === 'erreur' && (
        <>
          <Erreur message={message} />
          <div className="bq-etape-actions">
            <button type="button" className="btn" onClick={() => onNaviguer({ vue: 'vitrine' })}>
              Retour à la boutique
            </button>
          </div>
        </>
      )}
    </section>
  )
}
