import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euroCentimes, dateFr } from './Liste.jsx'

/**
 * LES ABONNEMENTS D'UN CLIENT — les deux rôles, séparés parce qu'ils répondent à deux questions.
 *
 * Un abonnement porte un ADHÉRENT (`Beneficiaire` — celui qui vient s'entraîner, dont le badge
 * porte le nom) et un PAYEUR (`Client` — celui qui est prélevé). Le cas courant les sépare : un
 * parent règle pour son enfant. Le cas le plus fréquent les confond : l'adhérent se paie lui-même.
 *
 * ⚠ LA FICHE CLIENT NE MONTRAIT NI L'UN NI L'AUTRE. Elle sait ce que le client a acheté, s'il est
 * entré, ce qu'il consent à recevoir — et pas qu'il est prélevé de 39,90 € tous les mois. Un
 * exploitant au comptoir devant un client qui conteste un prélèvement n'avait aucun écran à ouvrir.
 *
 * ⚠ DEUX LISTES ET PAS UNE, PARCE QUE LA QUESTION N'EST PAS LA MÊME. « Que paie-t-il ? » sert au
 * litige bancaire ; « à quoi a-t-il droit ? » sert à la porte. Les fondre obligerait à relire
 * chaque ligne pour savoir laquelle des deux on regarde.
 *
 * ⚠ ET « AUCUN » N'EST PAS « JE N'AI PAS PU LIRE ». Un compte sans `sport.lire` reçoit un refus ;
 * afficher « aucun abonnement » ferait conclure que ce client n'en a pas. On distingue les deux.
 */
export default function AbonnementsClient({ client, droits }) {
  const [payes, setPayes] = useState(undefined)
  const [aSonNom, setASonNom] = useState(undefined)

  const peutLire = aLeDroit(droits, 'sport.lire')

  const charger = useCallback(async () => {
    if (!client?.id || !peutLire) return

    // Ce qu'il PAIE : filtre direct sur le payeur.
    try {
      setPayes(membres(await api.abonnementsDuPayeur(client.id)))
    } catch {
      setPayes(null)
    }

    // Ce qui est À SON NOM : le pont est `Beneficiaire.client`. Deux appels, parce que l'abonnement
    // pointe l'adhérent et pas le client — et c'est justement ce que la distinction des deux rôles
    // veut dire.
    try {
      const benefs = membres(await api.beneficiairesDuClient(client.id))
      const ids = benefs.map((b) => b.id).filter(Boolean)
      setASonNom(ids.length === 0 ? [] : membres(await api.abonnementsDesAdherents(ids)))
    } catch {
      setASonNom(null)
    }
  }, [client?.id, peutLire])

  useEffect(() => { charger() }, [charger])

  if (!peutLire) return null

  const idsPayes = new Set((payes || []).map((a) => a.id))

  return (
    <div>
      <div className="fiche-sec">
        Abonnements {compte(payes) === null ? '' : `(${compte(payes)})`}
      </div>

      <Bloc
        titre="Qu’il paie"
        aide="Il est le payeur : c’est son compte bancaire qui est prélevé."
        lignes={payes}
        vide="Ce client ne paie aucun abonnement."
        rendu={(a) => (
          <>
            <td>{statut(a)}</td>
            <td className="num">{euroCentimes(a.montantCentimes)}</td>
            <td>{a.periodicite || '—'}</td>
            <td className="mono">{a.mandatSepa?.iban4Derniers ? `…${a.mandatSepa.iban4Derniers}` : '—'}</td>
            <td>{dateFr(a.dateFinEngagement)}</td>
          </>
        )}
        entetes={['Statut', 'Montant', 'Périodicité', 'Compte', 'Fin d’engagement']}
      />

      <Bloc
        titre="À son nom"
        aide="Il est l’adhérent : c’est lui qui entre, et son badge porte ce droit."
        lignes={aSonNom}
        vide="Aucun abonnement n’est au nom de ce client."
        rendu={(a) => (
          <>
            <td>{statut(a)}</td>
            <td className="num">{euroCentimes(a.montantCentimes)}</td>
            <td>{a.periodicite || '—'}</td>
            {/* Le cas le plus fréquent : il est les deux à la fois. Le dire évite de croire à deux
                abonnements là où il n'y en a qu'un. */}
            <td>{idsPayes.has(a.id) ? <span className="sub">il le paie lui-même</span> : <span className="sub">payé par un tiers</span>}</td>
            <td>{dateFr(a.dateFinEngagement)}</td>
          </>
        )}
        entetes={['Statut', 'Montant', 'Périodicité', 'Qui paie', 'Fin d’engagement']}
      />
    </div>
  )
}

function compte(lignes) {
  if (lignes === undefined || lignes === null) return null
  return lignes.length
}

function statut(a) {
  const s = a.statut || '—'
  const classe = s === 'actif' ? 'good' : s === 'resilie' ? 'crit' : 'warn'

  return <span className={`badge ${classe}`}>{s}</span>
}

function Bloc({ titre, aide, lignes, vide, entetes, rendu }) {
  return (
    <div style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="sub" style={{ marginBottom: 'var(--esp-serre)' }}>
        <b>{titre}</b> — {aide}
      </div>

      {lignes === undefined && <div className="empty" style={{ padding: 'var(--esp-large)' }}>Lecture…</div>}

      {/* ⚠ L'ÉCHEC DE LECTURE NE SE DIT PAS « AUCUN ». Rendre le message de vide sur un refus ferait
          affirmer une absence qu'on n'a pas mesurée. */}
      {lignes === null && (
        <div className="banner banner-warn">
          Ces abonnements n’ont pas pu être lus. La liste est vide parce que la lecture a échoué, pas
          parce qu’il n’y en a aucun.
        </div>
      )}

      {Array.isArray(lignes) && lignes.length === 0 && (
        <div className="empty" style={{ padding: 'var(--esp-large)' }}>{vide}</div>
      )}

      {Array.isArray(lignes) && lignes.length > 0 && (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>{entetes.map((e) => <th key={e}>{e}</th>)}</tr>
            </thead>
            <tbody>
              {lignes.map((a) => <tr key={a.id}>{rendu(a)}</tr>)}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
