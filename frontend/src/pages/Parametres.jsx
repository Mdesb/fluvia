import { useEffect, useState, useCallback } from 'react'
import Liste, { texte, dateHeureFr } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import { api, membres } from '../api/client.js'

const SOUS = [
  ['entites', 'Établissement & entités'],
  ['referentiels', 'Référentiels (M1)'],
  ['caisse', 'Caisse & paiement (M2)'],
  ['droits', 'Comptes & droits (M8)'],
  ['capacites', 'Capacités activables'],
]

// Paramètres : hub de consultation des référentiels du socle. Édition volontairement limitée
// (lecture d'abord) ; les droits M8 sont en lecture seule côté front.
export default function Parametres({ etabActif, etablissements }) {
  const [sousOnglet, setSousOnglet] = useState('entites')

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Paramètres</h1>
          <p>Référentiels, entités, caisse, droits &amp; capacités</p>
        </div>
      </div>

      <Tabs onglets={SOUS} actif={sousOnglet} onChange={setSousOnglet} />

      {sousOnglet === 'entites' && (
        <div className="resa-grid">
          <Liste
            titre="Établissements"
            sous={`${etablissements?.length || 0} accessible(s)`}
            deps={[etabActif]}
            charger={api.etablissements}
            vide="Aucun établissement."
            colonnes={[
              { cle: 'nom', entete: 'Établissement', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
              { cle: 'region', entete: 'Région', rendu: (r) => texte(r.region?.nom, '—') },
              { cle: 'actif', entete: 'État', rendu: (r) => <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'actif' : 'inactif'}</span> },
            ]}
          />
          <Liste
            titre="Espaces"
            sous="zones physiques"
            deps={[etabActif]}
            charger={api.espaces}
            vide="Aucun espace."
            colonnes={[
              { cle: 'nom', entete: 'Espace', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
              { cle: 'type', entete: 'Type', rendu: (r) => r.type || '—' },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'referentiels' && (
        <div className="resa-grid">
          <Liste
            titre="Taux de TVA"
            deps={[etabActif]}
            charger={api.tauxTvas}
            vide="Aucun taux."
            colonnes={[
              { cle: 'libelle', entete: 'Libellé', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
              { cle: 'taux', entete: 'Taux', num: true, rendu: (r) => (r.taux != null ? `${r.taux} %` : '—') },
              { cle: 'actif', entete: 'État', rendu: (r) => <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'actif' : 'inactif'}</span> },
            ]}
          />
          <Liste
            titre="Catégories"
            deps={[etabActif]}
            charger={api.categories}
            vide="Aucune catégorie."
            colonnes={[
              { cle: 'libelle', entete: 'Catégorie', rendu: (r) => <span className="nm">{texte(r.libelle, '—')}</span> },
              { cle: 'chemin', entete: 'Chemin', rendu: (r) => <span className="mono">{r.chemin || '—'}</span> },
            ]}
          />
          <Liste
            titre="Types de tarif"
            deps={[etabActif]}
            charger={api.typeTarifs}
            vide="Aucun type de tarif."
            colonnes={[
              { cle: 'nom', entete: 'Type', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
              { cle: 'visibiliteCanal', entete: 'Canal', rendu: (r) => (Array.isArray(r.visibiliteCanal) ? r.visibiliteCanal.join(', ') : r.visibiliteCanal || '—') },
              { cle: 'actif', entete: 'État', rendu: (r) => <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'actif' : 'inactif'}</span> },
            ]}
          />
          <Liste
            titre="Saisons"
            deps={[etabActif]}
            charger={api.saisons}
            vide="Aucune saison."
            colonnes={[
              { cle: 'nom', entete: 'Saison', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
              { cle: 'priorite', entete: 'Priorité', num: true, rendu: (r) => r.priorite ?? '—' },
              { cle: 'actif', entete: 'État', rendu: (r) => <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'actif' : 'inactif'}</span> },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'caisse' && (
        <div className="resa-grid">
          <Liste
            titre="Points de vente"
            deps={[etabActif]}
            charger={api.pointDeVentes}
            vide="Aucun point de vente."
            colonnes={[
              { cle: 'libelle', entete: 'Point de vente', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
              { cle: 'tpe', entete: 'TPE', rendu: (r) => (r.tpe ? 'oui' : '—') },
              { cle: 'moyensAutorises', entete: 'Moyens', rendu: (r) => (Array.isArray(r.moyensAutorises) && r.moyensAutorises.length ? r.moyensAutorises.join(', ') : 'tous') },
            ]}
          />
          <Liste
            titre="Caisses"
            deps={[etabActif]}
            charger={api.caisses}
            vide="Aucune caisse."
            colonnes={[
              { cle: 'libelle', entete: 'Caisse', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
              { cle: 'pointDeVente', entete: 'Point de vente', rendu: (r) => texte(r.pointDeVente?.libelle, '—') },
              { cle: 'etat', entete: 'État', rendu: (r) => <span className="badge mut">{r.etat || '—'}</span> },
            ]}
          />
          <Liste
            titre="Moyens de paiement"
            deps={[etabActif]}
            charger={api.moyensPaiement}
            vide="Aucun moyen de paiement."
            colonnes={[
              { cle: 'libelle', entete: 'Moyen', rendu: (r) => <span className="nm">{r.libelle || '—'}</span> },
              { cle: 'code', entete: 'Code', rendu: (r) => <span className="mono">{r.code || '—'}</span> },
              { cle: 'exigeReference', entete: 'TPE / réf.', rendu: (r) => (r.exigeReference ? 'oui' : '—') },
              { cle: 'actif', entete: 'État', rendu: (r) => <span className={`badge ${r.actif !== false ? 'good' : 'mut'}`}>{r.actif !== false ? 'actif' : 'inactif'}</span> },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'droits' && (
        <div className="resa-grid">
          <Liste
            titre="Comptes utilisateurs"
            sous="lecture seule (M8)"
            deps={[etabActif]}
            charger={api.utilisateurs}
            vide="Aucun compte (droits securite.gerer requis)."
            colonnes={[
              { cle: 'nom', entete: 'Utilisateur', rendu: (r) => <span className="nm">{r.nom || r.email || '—'}</span> },
              { cle: 'email', entete: 'E-mail', rendu: (r) => r.email || '—' },
              { cle: 'mfaActif', entete: 'MFA', rendu: (r) => (r.mfaActif ? <span className="badge good">actif</span> : <span className="badge mut">—</span>) },
              { cle: 'statut', entete: 'Statut', rendu: (r) => <span className="badge mut">{r.statut || '—'}</span> },
              { cle: 'dernierAcces', entete: 'Dernier accès', rendu: (r) => dateHeureFr(r.dernierAcces) },
            ]}
          />
          <Liste
            titre="Rôles"
            sous="lecture seule (M8)"
            deps={[etabActif]}
            charger={api.roles}
            vide="Aucun rôle (droits securite.gerer requis)."
            colonnes={[
              { cle: 'nom', entete: 'Rôle', rendu: (r) => <span className="nm">{r.nom || '—'}</span> },
              { cle: 'estModele', entete: 'Modèle', rendu: (r) => (r.estModele ? 'oui' : '—') },
              { cle: 'permissions', entete: 'Permissions', num: true, rendu: (r) => (Array.isArray(r.permissions) ? r.permissions.length : '—') },
            ]}
          />
        </div>
      )}

      {sousOnglet === 'capacites' && <Capacites etabActif={etabActif} />}
    </div>
  )
}

// Capacités activables : catalogue socle croisé avec l'état par établissement (lecture).
function Capacites({ etabActif }) {
  const [items, setItems] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [cat, flags] = await Promise.all([
        api.catalogueCapacites(),
        etabActif ? api.fonctionnalitesEtablissement(etabActif).catch(() => null) : Promise.resolve(null),
      ])
      const actives = {}
      for (const f of membres(flags)) actives[f.capaciteCode] = f.active
      setItems(membres(cat).map((c) => ({ ...c, active: actives[c.code] ?? false })))
    } catch (e) {
      setErreur(e.message || 'Chargement du catalogue impossible.')
      setItems([])
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    charger()
  }, [charger])

  return (
    <section className="card">
      <div className="card-h">
        <h3>Capacités activables</h3>
        <span className="sub">feature flags par établissement</span>
        <button className="btn ghost sm" style={{ marginLeft: 'auto' }} onClick={charger} disabled={chargement}>↻</button>
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : erreur ? (
          <div className="banner banner-error">{erreur}</div>
        ) : (
          <table className="tbl">
            <thead>
              <tr><th>Capacité</th><th>Catégorie</th><th>Description</th><th>État (établissement)</th></tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.code}>
                  <td><span className="nm">{c.libelle || c.code}</span> <span className="mono" style={{ color: 'var(--ink-faint)' }}>{c.code}</span></td>
                  <td>{c.categorie || '—'}</td>
                  <td style={{ color: 'var(--ink-soft)' }}>{c.description || '—'}</td>
                  <td><span className={`badge ${c.active ? 'good' : 'mut'}`}>{c.active ? 'activée' : 'inactive'}</span></td>
                </tr>
              ))}
              {items.length === 0 && <tr><td colSpan={4} className="empty">Aucune capacité au catalogue.</td></tr>}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}
