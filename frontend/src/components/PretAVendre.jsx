import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'

// « Avant de pouvoir vendre » — les trois conditions techniques, cochées automatiquement.
//
// POURQUOI PAS UN ASSISTANT EN PLUSIEURS ÉTAPES. Un assistant prend la main, impose un ordre et
// enferme : celui qui sait déjà où aller le subit, et celui qui l'abandonne au milieu ne sait plus où
// il en est. Ici, rien n'est bloqué et rien n'est imposé — c'est une liste qui se coche toute seule
// pendant qu'on paramètre, dans l'ordre qu'on veut.
//
// POURQUOI IL DISPARAÎT. Une fois les trois conditions remplies, il se réduit à une ligne. Un bandeau
// permanent qui répète « tout va bien » devient un meuble qu'on ne lit plus — et le jour où il
// annonce un vrai problème, personne ne le voit.
//
// CE QU'UNE LIGNE DIT. La conséquence, jamais le réglage. « Un point de vente » ne veut rien dire à
// quelqu'un qui découvre ; « sans point de vente, aucune caisse ne peut être ouverte et rien ne peut
// être encaissé » lui dit pourquoi s'en occuper maintenant. Les lignes déjà faites rappellent ce qui
// existe, pour qu'on n'ait pas à descendre dans la page pour le vérifier.
//
// CE QU'IL NE FAIT JAMAIS : affirmer sans savoir. Une condition qu'on n'a pas le droit de vérifier
// n'est ni cochée ni signalée — elle est retirée de la liste. Annoncer « il vous manque un point de
// vente » à quelqu'un qui n'a simplement pas le droit de les lire serait un mensonge, et il chercherait
// longtemps.

export default function PretAVendre({ etabActif, droits = [], onAller }) {
  const [etat, setEtat] = useState(null)
  const [deplie, setDeplie] = useState(false)

  const peutLireOffre = droits.includes('offre.lire')
  const peutLireCompta = droits.includes('compta.lire')
  const peutLireCaisse = droits.includes('caisse.lire')

  const verifier = useCallback(async () => {
    const [tarifs, taux, points] = await Promise.all([
      peutLireOffre ? api.typeTarifs().catch(() => null) : null,
      peutLireCompta ? api.tauxTvas().catch(() => null) : null,
      peutLireCaisse ? api.pointDeVentes().catch(() => null) : null,
    ])

    const conditions = []

    if (tarifs) {
      const utilisables = membres(tarifs).filter(
        (t) => t.actif !== false && (Array.isArray(t.visibiliteCanal) ? t.visibiliteCanal : []).includes('guichet'),
      )
      conditions.push({
        cle: 'tarif',
        titre: 'Un type de tarif utilisable au guichet',
        fait: utilisables.length > 0,
        rappel: utilisables.map((t) => t.nom).filter(Boolean).slice(0, 3).join(', '),
        pourquoi:
          "Sans type de tarif proposé au guichet, vos produits ne peuvent recevoir aucun prix : ils ne "
          + 'sont ni vendables ni publiables.',
        action: 'Créer un type de tarif',
        vers: 'referentiels',
      })
    }

    if (taux) {
      const actifs = membres(taux).filter((t) => t.actif !== false)
      conditions.push({
        cle: 'tva',
        titre: 'Un taux de TVA',
        fait: actifs.length > 0,
        rappel: actifs.map((t) => (t.taux != null ? `${t.libelle} ${t.taux} %` : t.libelle)).filter(Boolean).slice(0, 3).join(', '),
        pourquoi:
          'Sans taux de TVA, vos produits ne peuvent pas être rattachés à un taux et la comptabilité '
          + 'ne peut pas être tenue.',
        action: 'Créer un taux',
        vers: 'referentiels',
      })
    }

    if (points) {
      const liste = membres(points)
      conditions.push({
        cle: 'pdv',
        titre: 'Un point de vente',
        fait: liste.length > 0,
        rappel: liste.map((p) => p.nom || p.libelle).filter(Boolean).slice(0, 3).join(', '),
        pourquoi: "Sans point de vente, aucune caisse ne peut être ouverte et rien ne peut être encaissé.",
        action: 'Créer un point de vente',
        vers: 'caisse',
      })
    }

    setEtat(conditions.length ? conditions : null)
  }, [peutLireOffre, peutLireCompta, peutLireCaisse])

  useEffect(() => {
    verifier()
  }, [verifier, etabActif])

  if (!etat) return null

  const faits = etat.filter((c) => c.fait).length
  const total = etat.length
  const complet = faits === total

  if (complet && !deplie) {
    return (
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-b" style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '12px 16px' }}>
          <span className="badge good">✓</span>
          <span style={{ flex: 1 }}>Tout est prêt pour vendre</span>
          <button className="btn ghost sm" type="button" onClick={() => setDeplie(true)}>Voir le détail</button>
        </div>
      </div>
    )
  }

  return (
    <div className="card" style={{ marginBottom: 16 }}>
      <div className="card-h">
        <h3>Avant de pouvoir vendre</h3>
        <span className="sub">{faits} sur {total}</span>
        {complet && (
          <div className="r">
            <button className="btn ghost sm" type="button" onClick={() => setDeplie(false)}>Replier</button>
          </div>
        )}
      </div>
      <div className="card-b">
        <p className="hint" style={{ marginTop: 0 }}>
          {complet
            ? 'Les trois réglages nécessaires sont en place.'
            : 'Ces réglages sont nécessaires pour qu’un produit puisse être mis en vente. Vous pouvez les faire dans l’ordre que vous voulez.'}
        </p>

        <div
          style={{ height: 4, background: 'var(--panel-2)', borderRadius: 999, overflow: 'hidden', margin: '10px 0 4px' }}
          role="progressbar"
          aria-valuenow={faits}
          aria-valuemin={0}
          aria-valuemax={total}
        >
          <div style={{ width: `${(faits / total) * 100}%`, height: '100%', background: 'var(--good)' }} />
        </div>

        {etat.map((c) => (
          <div
            key={c.cle}
            style={{
              display: 'flex',
              alignItems: 'flex-start',
              gap: 10,
              padding: '10px 0',
              borderTop: '1px solid var(--line)',
            }}
          >
            <span className={`badge ${c.fait ? 'good' : 'warn'}`} aria-hidden="true">{c.fait ? '✓' : '!'}</span>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div>{c.titre}</div>
              {/* Ce qui existe déjà, plutôt qu'un simple « fait » : on évite d'avoir à descendre
                  dans la page pour vérifier de quoi il s'agit. */}
              <div className="hint" style={{ marginTop: 2 }}>
                {c.fait ? c.rappel || '—' : c.pourquoi}
              </div>
            </div>
            {!c.fait && (
              <button className="btn primary sm" type="button" onClick={() => onAller?.(c.vers)}>
                {c.action}
              </button>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
