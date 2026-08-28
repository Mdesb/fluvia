import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

// LES PASSAGES D'UN CLIENT, SUR SA FICHE — « il dit qu'il est venu mardi, c'est vrai ? »
//
// La fiche 360 sait ce que le client a ACHETÉ. Elle ne savait pas s'il est ENTRÉ. Or les deux
// questions du comptoir sont exactement celles-là : « est-ce qu'il a utilisé sa carte ? » et « il
// affirme que la borne l'a refusé hier ».
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// LE CHEMIN, ET POURQUOI IL PASSE PAR LA VENTE
//
// `Passage` ne porte PAS de client, et `DroitAcces` non plus : rien, côté accès, ne sait à qui
// appartient un badge. Ce qui relie les deux mondes est le NUMÉRO DE SUPPORT — imprimé sur le billet
// à la vente (`BilletSupport.identifiantSupport`), lu au tourniquet (`Passage.support.identifiant`).
//
//   client → ses ventes (`GET /api/ventes?client=<uuid>`, filtre `SaleCustomerFilter`)
//          → les numéros de support de ces ventes (embarqués dans `vente:read`)
//          → les passages de ces numéros (`support.identifiant[]=…`, filtre `exact`)
//
// UNE SEULE REQUÊTE POUR LA DERNIÈRE ÉTAPE, et ce n'est pas un détail : `SearchFilter` en stratégie
// `exact` accepte plusieurs valeurs (`prop[]=a&prop[]=b`, traduit en `IN`), et `qs()` sait déjà
// sérialiser un tableau. Une requête par billet aurait fait dix appels à l'ouverture d'une fiche.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// TROIS LIMITES, DITES À L'ÉCRAN PLUTÔT QUE DÉCOUVERTES
//
// 1. Les ventes sont plafonnées à 30 par le serveur (aucune configuration de pagination, et
//    `itemsPerPage` est ignoré). Sur un client ancien, on ne remonte donc que ses 30 dernières
//    ventes — et un passage rattaché à un billet plus vieux n'apparaîtra pas. Le bloc le dit.
// 2. Seuls les billets VENDUS avec un numéro de support sont traçables. Un badge appairé à la main,
//    hors vente, n'a aucun lien avec un client : ses passages existent, mais rien ne les rattache
//    ici. Ce n'est pas une perte de données, c'est l'absence d'un lien qui n'a jamais été créé.
// 3. Les passages eux-mêmes sont plafonnés à 30. On affiche le compte réel quand il dépasse.
//
// Aucune de ces trois limites ne se corrige depuis l'écran ; les taire ferait lire « ce client n'est
// jamais entré » là où la bonne phrase est « je ne vois pas plus loin ».

const RESULTAT = { valide: 'Validé', refuse: 'Refusé', compte: 'Compté' }
const RESULTAT_CLS = { valide: 'good', refuse: 'crit', compte: 'mut' }

const POURQUOI = {
  hors_marge: 'Hors créneau',
  anti_passback: 'Repassage trop rapproché',
  credit_epuise: 'Carte épuisée',
  support_bloque: 'Badge bloqué (perte ou vol)',
  seuil_fmi: 'Jauge de l’espace atteinte',
  droit_invalide: 'Billet inconnu ou droit annulé',
  sens_interdit: 'Sens non autorisé sur ce lecteur',
  non_nominatif: 'Comptage sans billet',
  ouverture_manuelle: 'Ouverture forcée par un agent',
  federation_inactive: 'Reconnaissance mutuelle inactive',
  signature_invalide: 'Code de billet invalide',
  hors_portee: 'Terminal sans autorité sur ce lecteur',
  credit_epuise_hors_ligne_litige: 'Accepté hors ligne, carte épuisée',
  hors_horaires_ouverture: 'Site fermé à cette heure',
}

const MAX_NUMEROS = 25

function dateHeure(v) {
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR')
}

export default function PassagesClient({ clientId, droits = [] }) {
  const [etat, setEtat] = useState({ chargement: true, passages: [], total: null, numeros: 0, ventesTronquees: false })
  const [erreur, setErreur] = useState(null)

  const peutLire = aLeDroit(droits, 'acces.lire')

  const charger = useCallback(async () => {
    if (!clientId || !peutLire) return
    setErreur(null)
    setEtat((s) => ({ ...s, chargement: true }))
    try {
      const reponseVentes = await api.ventes({ client: clientId, 'order[date]': 'desc' })
      const ventes = membres(reponseVentes)
      const totalVentes = reponseVentes?.totalItems ?? reponseVentes?.['hydra:totalItems']
      const ventesTronquees = typeof totalVentes === 'number' && ventes.length < totalVentes

      // Un même numéro peut apparaître deux fois (réédition d'un billet) : on dédoublonne avant
      // d'interroger, sinon le filtre `IN` porte deux fois la même valeur pour rien.
      const numeros = [
        ...new Set(
          ventes
            .flatMap((v) => v.supports || [])
            .map((s) => s.identifiantSupport)
            .filter(Boolean),
        ),
      ].slice(0, MAX_NUMEROS)

      if (numeros.length === 0) {
        setEtat({ chargement: false, passages: [], total: 0, numeros: 0, ventesTronquees })
        return
      }

      const reponse = await api.journalPassages({
        'support.identifiant': numeros,
        'order[horodatage]': 'desc',
      })
      const passages = membres(reponse)
      const total = reponse?.totalItems ?? reponse?.['hydra:totalItems'] ?? passages.length
      setEtat({ chargement: false, passages, total, numeros: numeros.length, ventesTronquees })
    } catch (e) {
      // Un compte CRM sans droit sur les accès reçoit un 403 : ce n'est pas une panne, c'est une
      // frontière. On le dit dans ces mots-là.
      setErreur(e.status === 403 ? 'Ce compte n’a pas le droit de lire les passages.' : e.message || 'Passages indisponibles.')
      setEtat((s) => ({ ...s, chargement: false }))
    }
  }, [clientId, peutLire])

  useEffect(() => {
    charger()
  }, [charger])

  if (!peutLire) return null

  const { chargement, passages, total, numeros, ventesTronquees } = etat

  return (
    <div>
      <div className="fiche-sec">Passages aux accès{passages.length > 0 ? ` (${passages.length})` : ''}</div>

      {erreur ? (
        <div className="banner banner-error">{erreur}</div>
      ) : chargement ? (
        <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
      ) : numeros === 0 ? (
        <div className="empty" style={{ padding: 12 }}>
          Aucun billet nominatif vendu à ce client{ventesTronquees ? ' parmi ses 30 dernières ventes' : ''}.
          Les passages ne se rattachent à un client que par le numéro du billet qui lui a été vendu.
        </div>
      ) : passages.length === 0 ? (
        <div className="empty" style={{ padding: 12 }}>
          {numeros} billet(s) suivis, aucun passage enregistré. Ce client n’est pas entré avec ces
          billets — ou pas encore.
        </div>
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Espace</th>
                  <th>Lecteur</th>
                  <th>Résultat</th>
                  <th>Billet</th>
                </tr>
              </thead>
              <tbody>
                {passages.map((p) => (
                  <tr key={p.id}>
                    <td>{dateHeure(p.horodatage)}</td>
                    <td>{p.espace?.libelle || '—'}</td>
                    <td>{p.equipement?.libelle || <span className="mut">—</span>}</td>
                    <td>
                      <span className={`badge ${RESULTAT_CLS[p.resultat] || 'mut'}`}>
                        {RESULTAT[p.resultat] || p.resultat}
                      </span>
                      {p.resultat === 'refuse' && (
                        <div className="mut">{POURQUOI[p.codeMotif] || p.motif || 'motif non transmis'}</div>
                      )}
                    </td>
                    <td className="mono">{p.support?.identifiant || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {(typeof total === 'number' && passages.length < total) || ventesTronquees ? (
            <div className="hint">
              {typeof total === 'number' && passages.length < total
                ? `${passages.length} passages affichés sur ${total} — le serveur en rend 30 au maximum. `
                : ''}
              {ventesTronquees
                ? 'Et seules les 30 dernières ventes de ce client ont été parcourues : un billet plus ancien n’est pas suivi ici. '
                : ''}
              Le journal complet est dans Topologie &amp; passages.
            </div>
          ) : null}
        </>
      )}
    </div>
  )
}
