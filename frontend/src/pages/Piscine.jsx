import { useCallback, useEffect, useState } from 'react'
import Liste, { texte } from '../components/Liste.jsx'
import Tabs from '../components/Tabs.jsx'
import Modal from '../components/Modal.jsx'
import CasiersPiscine from '../components/CasiersPiscine.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

// Verticale Piscine : bassins, créneaux et jauges grand public (FMI).
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// CET ÉCRAN SAVAIT TOUT FAIRE D'UN BASSIN, SAUF EN DÉCLARER UN.
//
// Il attribue un casier, le libère, relance un retard, force une ouverture, lit les jauges — et
// `POST /api/bassins`, protégé par `piscine.configurer`, n'était appelé de nulle part. Un
// établissement qui démarre voyait « Aucun bassin déclaré » sans aucun moyen d'en sortir.
//
// ⚠ UN BASSIN N'EST PAS UN ESPACE, ET CE N'EST PAS UN DOUBLON — c'est une composition.
//
// `Paramètres › Espaces` liste les LIEUX de l'établissement (« Bassin principal », « Grand
// bassin », « Piste de glace »…), chacun avec un type. `Bassin` est l'objet d'EXPLOITATION de la
// piscine — lignes d'eau, capacité, occupation courante — et il porte une relation obligatoire
// vers un espace : « Un espace du socle est requis (RG-SOCLE-01) ».
//
// Le voir comme deux listes concurrentes conduirait à en créer une troisième. Le formulaire
// CHOISIT donc un espace existant au lieu d'en inventer un, et ne propose que ceux de type
// « bassin » : les autres ne sont pas des lieux de baignade.
export default function Piscine({ etabActif, droits }) {
  const [onglet, setOnglet] = useState('bassins')
  const [creation, setCreation] = useState(false)
  // Incrementé après une création : `Liste` recharge sur ses `deps`, sans que l'écran ait à
  // connaître son état interne.
  const [rechargement, setRechargement] = useState(0)
  const peutConfigurer = aLeDroit(droits, 'piscine.configurer')

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

      <BassinModal
        open={creation}
        onClose={() => setCreation(false)}
        onCree={() => { setCreation(false); setRechargement((n) => n + 1) }}
      />

      <div className="resa-grid" style={{ display: onglet === 'bassins' ? undefined : 'none' }}>
        <Liste
          titre="Bassins"
          sous="capacité &amp; occupation"
          deps={[etabActif, rechargement]}
          charger={api.bassins}
          vide="Aucun bassin déclaré. Un bassin porte les lignes d’eau, la capacité et l’occupation : sans lui, ni jauge ni créneau."
          actions={peutConfigurer ? (
            <button className="btn sm" type="button" onClick={() => setCreation(true)}>
              ＋ Déclarer un bassin
            </button>
          ) : null}
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
        <CreneauxBassins etabActif={etabActif} droits={droits} />
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

/**
 * LES CRÉNEAUX DE BASSIN — et le geste qui les fait exister.
 *
 * **Un créneau naît en brouillon.** Valider est ce qui le fait entrer dans le planning de
 * surveillance ; l'opération existait côté serveur et aucun écran ne l'appelait. Le tableau
 * affichait donc une colonne « statut » où tout restait « brouillon », sans que rien n'explique
 * pourquoi ni comment en sortir.
 *
 * > **Un planning dont aucune ligne n'est validée ressemble à un planning, et n'en est pas un.**
 *
 * **Le ton du statut n'est pas décoratif.** Un brouillon en gris se lit comme une nuance ; en orange,
 * il se lit comme un travail à finir. C'est exactement ce qu'il est — et sur un plan de surveillance,
 * la différence entre les deux lectures est réglementaire.
 */
function CreneauxBassins({ etabActif, droits = [] }) {
  const peutConfigurer = aLeDroit(droits, 'piscine.configurer')
  const [version, setVersion] = useState(0)
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)

  const colonnes = [
    { cle: 'bassin', entete: 'Bassin', rendu: (r) => texte(r.bassin?.libelle, String(r.bassin || '').split('/').pop() || '—') },
    { cle: 'debut', entete: 'Début', rendu: (r) => heure(r.debut) },
    { cle: 'fin', entete: 'Fin', rendu: (r) => heure(r.fin) },
    { cle: 'encadrantRequis', entete: 'Encadrant', rendu: (r) => (r.encadrantRequis ? 'requis' : '—') },
    {
      cle: 'statut',
      entete: 'Statut',
      rendu: (r) => {
        const s = String(r.statut || '').toLowerCase()
        const ton = s === 'valide' ? 'good' : s === 'annule' ? 'crit' : 'warn'
        return <span className={`badge ${ton}`}>{r.statut || '—'}</span>
      },
    },
  ]

  if (peutConfigurer) {
    colonnes.push({
      cle: 'valider',
      entete: '',
      rendu: (r) => {
        if (String(r.statut || '').toLowerCase() !== 'brouillon') return null
        return (
          <div style={{ textAlign: 'right' }}>
            <button
              className="btn ghost sm"
              type="button"
              disabled={busy}
              style={{ padding: '1px 8px', fontSize: 11.5 }}
              onClick={async () => {
                setBusy(true)
                setErreur(null)
                try {
                  await api.piscineValiderCreneauBassin(r.id)
                  setVersion((v) => v + 1)
                } catch (e) {
                  setErreur(e.message || 'Le créneau n’a pas pu être validé.')
                } finally {
                  setBusy(false)
                }
              }}
            >
              Valider
            </button>
          </div>
        )
      },
    })
  }

  return (
    <div>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <Liste
        titre="Créneaux bassins"
        sous="planning surveillance"
        deps={[etabActif, version]}
        charger={api.creneauxBassin}
        vide="Aucun créneau planifié."
        colonnes={colonnes}
      />
    </div>
  )
}

// DÉCLARER UN BASSIN — le premier des sept écrans qui savaient exploiter sans savoir créer.
//
// Le formulaire est court parce que le serveur l'est : `libelle` et `espace` sont exigés,
// `nbLignes` et `capacite` ont des valeurs par défaut positives. Sondé avec un corps vide plutôt
// que déduit de l'entité — le 422 énonce exactement deux violations :
//     libelle  This value should not be blank.
//     espace   Un espace du socle est requis (RG-SOCLE-01).
//
// ⚠ ON NE CRÉE PAS L'ESPACE ICI, ON LE CHOISIT. Un bassin sans espace n'existe pas, mais un espace
// est un objet du socle : il porte les droits d'accès, les tourniquets, les autres verticales. Le
// créer depuis la piscine en ferait un objet de piscine, et la patinoire recréerait le sien à
// côté. C'est exactement le doublon qu'on voulait éviter en ouvrant ce chantier.
function BassinModal({ open, onClose, onCree }) {
  const [espaces, setEspaces] = useState([])
  const [bassins, setBassins] = useState([])
  const [libelle, setLibelle] = useState('')
  const [espace, setEspace] = useState('')
  const [lignes, setLignes] = useState('1')
  const [capacite, setCapacite] = useState('1')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return
    setLibelle('')
    setLignes('1')
    setCapacite('1')
    setErreur(null)
    Promise.allSettled([api.espaces(), api.bassins()]).then(([e, b]) => {
      const liste = e.status === 'fulfilled' ? membres(e.value) : []
      setEspaces(liste)
      setBassins(b.status === 'fulfilled' ? membres(b.value) : [])
      const premier = liste.find((x) => x.type === 'bassin')
      setEspace(premier ? premier.id : '')
    })
  }, [open])

  // Seuls les lieux de baignade. Les autres types — guichet, gradins, glace — sont des espaces du
  // même établissement, et n'ont rien à faire dans ce choix.
  const lieux = espaces.filter((e) => e.type === 'bassin')
  const dejaPris = new Set(
    bassins.map((b) => (typeof b.espace === 'string' ? b.espace.split('/').pop() : b.espace?.id)),
  )

  async function soumettre(evenement) {
    evenement.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.creerBassin({
        libelle: libelle.trim(),
        espace: `/api/espaces/${espace}`,
        nbLignes: Number(lignes),
        capacite: Number(capacite),
      })
      onCree()
    } catch (e) {
      setErreur(e.message || 'Le bassin n’a pas pu être déclaré.')
    } finally {
      setEnvoi(false)
    }
  }

  const pret = libelle.trim() !== '' && espace !== '' && Number(lignes) > 0 && Number(capacite) > 0

  return (
    <Modal open={open} onClose={onClose} titre="Déclarer un bassin">
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        {lieux.length === 0 ? (
          <div className="banner banner-warn">
            Aucun espace de type « bassin » n’est déclaré sur cet établissement. Un bassin s’appuie
            sur un espace du socle — celui qui porte les accès et les tourniquets. Créez-le d’abord
            dans <b>Paramètres › Espaces</b>, puis revenez ici.
          </div>
        ) : (
          <>
            <div className="field">
              <label htmlFor="ba-lib">Nom du bassin *</label>
              <input
                id="ba-lib"
                className="input"
                value={libelle}
                maxLength={120}
                placeholder="Grand bassin, bassin d’apprentissage…"
                onChange={(e) => setLibelle(e.target.value)}
              />
              <p className="hint">
                Ce que le maître-nageur lit sur son planning et ce qui figure sur la jauge affichée
                au public.
              </p>
            </div>

            <div className="field">
              <label htmlFor="ba-espace">Espace *</label>
              <select id="ba-espace" className="input" value={espace} onChange={(e) => setEspace(e.target.value)}>
                {lieux.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.nom}{dejaPris.has(l.id) ? ' — porte déjà un bassin' : ''}
                  </option>
                ))}
              </select>
              <p className="hint">
                Le lieu, tel qu’il est déclaré dans <b>Paramètres › Espaces</b>. C’est lui qui porte
                les droits d’accès et les tourniquets ; le bassin y ajoute les lignes d’eau et la
                capacité.
              </p>
            </div>

            <div className="row" style={{ display: 'flex', gap: 'var(--esp-large)' }}>
              <div className="field" style={{ flex: 1 }}>
                <label htmlFor="ba-lignes">Lignes d’eau *</label>
                <input id="ba-lignes" className="input" type="number" min="1" value={lignes}
                  onChange={(e) => setLignes(e.target.value)} />
              </div>
              <div className="field" style={{ flex: 1 }}>
                <label htmlFor="ba-cap">Capacité *</label>
                <input id="ba-cap" className="input" type="number" min="1" value={capacite}
                  onChange={(e) => setCapacite(e.target.value)} />
                <p className="hint">Le nombre de baigneurs simultanés : c’est lui qui borne la jauge.</p>
              </div>
            </div>
          </>
        )}

        <div className="row" style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi || !pret || lieux.length === 0}>
            {envoi ? 'Déclaration…' : 'Déclarer le bassin'}
          </button>
        </div>
      </form>
    </Modal>
  )
}
