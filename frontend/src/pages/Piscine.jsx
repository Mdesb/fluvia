import { useCallback, useEffect, useState } from 'react'
import Liste, { texte } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import CasiersPiscine from '../components/CasiersPiscine.jsx'
import { api, membres } from '../api/client.js'

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

// Verticale Piscine (consultation) : bassins, créneaux et jauges grand public (FMI).
export default function Piscine({ etabActif, droits }) {
  const [onglet, setOnglet] = useState('bassins')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Piscine</h1>
          <p>Bassins, créneaux et jauges de fréquentation</p>
        </div>
      </div>

      <SurveillancePoss etabActif={etabActif} />

      <Tabs
        onglets={[
          ['bassins', 'Bassins & créneaux'],
          ['casiers', 'Casiers'],
        ]}
        actif={onglet}
        onChange={setOnglet}
      />

      {onglet === 'casiers' && <CasiersPiscine etabActif={etabActif} droits={droits} />}

      <div className="resa-grid" style={{ display: onglet === 'bassins' ? undefined : 'none' }}>
        <Liste
          titre="Bassins"
          sous="capacité &amp; occupation"
          deps={[etabActif]}
          charger={api.bassins}
          vide="Aucun bassin déclaré."
          colonnes={[
            { cle: 'libelle', entete: 'Bassin', rendu: (r) => <span className="nm">{texte(r.libelle, r.code || 'Bassin')}</span> },
            { cle: 'nbLignes', entete: 'Lignes', num: true, rendu: (r) => r.nbLignes ?? '—' },
            { cle: 'capacite', entete: 'Capacité', num: true, rendu: (r) => r.capacite ?? '—' },
            { cle: 'occupationCourante', entete: 'Occupation', num: true, rendu: (r) => r.occupationCourante ?? 0 },
          ]}
        />

        <Liste
          titre="Jauges grand public"
          sous="places restantes (FMI)"
          deps={[etabActif]}
          charger={api.jaugesGrandPublic}
          vide="Aucune jauge calculée."
          colonnes={[
            { cle: 'creneauBassin', entete: 'Créneau bassin', rendu: (r) => <span className="mono">{String(r.creneauBassin || '').split('/').pop() || '—'}</span> },
            { cle: 'capaciteRestante', entete: 'Places restantes', num: true, rendu: (r) => (
              <span className={`badge ${(r.capaciteRestante ?? 0) <= 0 ? 'crit' : 'good'}`}>{r.capaciteRestante ?? '—'}</span>
            ) },
            { cle: 'modeProrata', entete: 'Mode', rendu: (r) => r.modeProrata || '—' },
          ]}
        />
      </div>

      <div style={{ marginTop: 16 }}>
        <Liste
          titre="Créneaux bassins"
          sous="planning surveillance"
          deps={[etabActif]}
          charger={api.creneauxBassin}
          vide="Aucun créneau planifié."
          colonnes={[
            { cle: 'bassin', entete: 'Bassin', rendu: (r) => texte(r.bassin?.libelle, String(r.bassin || '').split('/').pop() || '—') },
            { cle: 'debut', entete: 'Début', rendu: (r) => heure(r.debut) },
            { cle: 'fin', entete: 'Fin', rendu: (r) => heure(r.fin) },
            { cle: 'encadrantRequis', entete: 'Encadrant', rendu: (r) => (r.encadrantRequis ? 'requis' : '—') },
            { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
          ]}
        />
      </div>
    </div>
  )
}


// Le POSS — plan d'organisation de la surveillance et des secours.
//
// CE SEUIL N'EST PAS UN CONFORT D'EXPLOITATION.
//
// Le POSS fixe le nombre maximal de baigneurs que la surveillance en place peut couvrir. Le
// dépasser n'est pas « chargé » : c'est hors du plan déclaré. Le serveur calcule `presents`,
// `seuilPoss`, la pré-alerte et les places réservées restantes — et personne ne l'affichait.
//
// Il est donc en haut de l'écran, avant les onglets, et visible quel que soit l'onglet ouvert : on
// ne range pas une limite de sécurité derrière un clic.
//
// Le rafraîchissement est manuel et daté. Un compteur de sécurité qui change tout seul pendant
// qu'on le lit ne se cite pas à voix haute — et c'est exactement ce qu'un maître-nageur fait avec.
function SurveillancePoss({ etabActif }) {
  const [etats, setEtats] = useState([])
  const [chargement, setChargement] = useState(true)
  const [luA, setLuA] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const plans = membres(await api.piscinePoss())
      const resultats = await Promise.all(
        plans.map((p) =>
          api
            .piscineEtatPoss(p.id)
            .then((e) => ({ plan: p, etat: e }))
            .catch(() => ({ plan: p, etat: null })),
        ),
      )
      setEtats(resultats)
      setLuA(new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }))
    } catch {
      setEtats([])
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  if (chargement || etats.length === 0) return null

  return (
    <section className="card" style={{ marginBottom: 16 }}>
      <div className="card-h">
        <h3>Surveillance</h3>
        <span className="sub">
          seuil du plan de surveillance{luA ? ` · relevé à ${luA}` : ''}
        </span>
        <div className="r">
          <button className="btn ghost sm" type="button" onClick={recharger}>Actualiser</button>
        </div>
      </div>
      <div className="card-b">
        <table className="tbl">
          <thead>
            <tr>
              <th>Plan</th>
              <th className="num">Présents</th>
              <th className="num">Seuil POSS</th>
              <th className="num">Places réservées restantes</th>
              <th>État</th>
            </tr>
          </thead>
          <tbody>
            {etats.map(({ plan, etat }) => (
              <tr key={plan.id}>
                <td><span className="nm">{texte(plan.libelle, plan.nom || 'Plan de surveillance')}</span></td>
                <td className="num">{etat ? <b>{etat.presents}</b> : '—'}</td>
                <td className="num">{etat ? etat.seuilPoss : '—'}</td>
                <td className="num">{etat ? etat.placesReserveesRestantes : '—'}</td>
                <td>
                  {!etat ? (
                    <span className="sub">état indisponible</span>
                  ) : etat.presents >= etat.seuilPoss && etat.seuilPoss > 0 ? (
                    <span className="badge crit">seuil atteint</span>
                  ) : etat.preAlerteAtteinte ? (
                    <span className="badge warn">pré-alerte</span>
                  ) : (
                    <span className="badge good">dans le plan</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="hint">
          Le seuil est celui du plan de surveillance déclaré : au-delà, la fréquentation n'est plus
          couverte par le dispositif en place. Le relevé ne se met pas à jour tout seul — un compteur
          de sécurité qui bouge pendant qu'on le lit ne se cite pas à voix haute.
        </div>
      </div>
    </section>
  )
}
