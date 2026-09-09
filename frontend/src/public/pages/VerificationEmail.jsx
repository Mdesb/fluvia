import { useEffect, useState } from 'react'
import { boutique } from '../api/boutiqueClient.js'

/**
 * L'arrivée depuis le courriel de confirmation — la page qui transforme un lien en preuve.
 *
 * ⚠ ELLE S'EXÉCUTE TOUTE SEULE, ET C'EST VOULU. Le client vient de cliquer un lien : lui demander
 * de cliquer un second bouton « Confirmer » ne prouverait rien de plus et perdrait ceux qui
 * referment l'onglet. Le geste a déjà été fait dans la boîte mail.
 *
 * ⚠ ON N'ANNONCE JAMAIS CE QU'ON A TROUVÉ AVANT D'AVOIR VÉRIFIÉ. Le nombre de commandes rattachées
 * ne s'affiche qu'APRÈS la réponse du serveur : l'écrire plus tôt dirait à qui s'est inscrit avec
 * l'adresse d'un autre ce que cette adresse a acheté.
 *
 * ⚠ ET UN ÉCHEC NE DIT PAS LEQUEL. Jeton inconnu, déjà utilisé, expiré : le serveur rend le même
 * message dans les trois cas, et cette page le recopie tel quel sans le « préciser ». Distinguer
 * ici annulerait la protection posée là-bas.
 */
export default function VerificationEmail({ jeton, onNaviguer }) {
  const [etat, setEtat] = useState('en-cours')
  const [rattachees, setRattachees] = useState(0)
  const [message, setMessage] = useState('')

  useEffect(() => {
    let annule = false
    if (!jeton) {
      setEtat('echec')
      setMessage('Lien de confirmation invalide ou expiré.')
      return undefined
    }
    boutique
      .verifierEmail(jeton)
      .then((r) => {
        if (annule) return
        setRattachees(Number(r?.commandesRattachees) || 0)
        setEtat('ok')
      })
      .catch((e) => {
        if (annule) return
        setMessage(e?.message || 'Lien de confirmation invalide ou expiré.')
        setEtat('echec')
      })
    return () => { annule = true }
  }, [jeton])

  return (
    <section className="bq-narrow">
      <div className="bq-view-head"><h1>Confirmation de votre adresse</h1></div>

      {etat === 'en-cours' && <p>Vérification en cours…</p>}

      {etat === 'ok' && (
        <>
          <p>Votre adresse est confirmée. Votre compte est actif.</p>
          {rattachees > 0 ? (
            <p>
              {rattachees === 1
                ? 'Une commande passée sans compte a été rattachée à votre compte.'
                : `${rattachees} commandes passées sans compte ont été rattachées à votre compte.`}
            </p>
          ) : (
            /* ⚠ « Aucune » se dit, et ne se tait pas : le silence laisserait croire à un échec
                partiel chez quelqu'un qui attendait de retrouver un achat. */
            <p>Aucune commande passée sans compte n’était associée à cette adresse.</p>
          )}
          <button type="button" className="btn" onClick={() => onNaviguer('compte')}>
            Voir mon compte
          </button>
        </>
      )}

      {etat === 'echec' && (
        <>
          <p>{message}</p>
          <p>
            Un lien de confirmation est valable 48&nbsp;heures et ne sert qu’une fois. Si le vôtre
            a expiré, connectez-vous&nbsp;: votre compte existe, seule l’adresse reste à confirmer.
          </p>
          <button type="button" className="btn" onClick={() => onNaviguer('compte')}>
            Aller à mon compte
          </button>
        </>
      )}
    </section>
  )
}
