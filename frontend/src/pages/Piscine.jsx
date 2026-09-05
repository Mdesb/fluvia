import { useCallback, useEffect, useState } from 'react'
import Liste, { texte } from '../components/Liste.jsx'
import Modal from '../components/Modal.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { idDe } from '../api/iri.js'

function heure(v) {
  if (!v) return '—'
  return new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function dateFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}

// Un `Utilisateur` arrive imbriqué ou en IRI selon le groupe de sérialisation du chemin emprunté.
// `idDe()` accepte les deux — et surtout ne rend jamais la chaîne vide, contrairement à un
// `.split('/').pop()` qui la rend sur une référence terminée par un slash.
function libelleEncadrant(q) {
  const e = q?.encadrant
  if (e && typeof e === 'object' && (e.email || e.nom)) return e.email || e.nom
  const id = idDe(e)
  return id ? `encadrant ${id.slice(0, 8)}…` : 'encadrant'
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

      <BassinModal
        open={creation}
        onClose={() => setCreation(false)}
        onCree={() => { setCreation(false); setRechargement((n) => n + 1) }}
      />

      {/* Plus de barre d'onglets : les casiers ont leur propre ecran (R10), il ne restait qu'un
          seul contenu. Un onglet unique se lit comme un choix, alors qu'il n'y en a plus. */}
      <div className="resa-grid">
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

      <div style={{ marginTop: 'var(--esp-bloc)' }}>
        <QualificationsEncadrants etabActif={etabActif} droits={droits} />
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
// Il est donc en haut de l'écran, avant tout le reste : on ne range pas une limite de sécurité
// derrière un clic. La phrase disait « avant les onglets » ; il n'y en a plus depuis que les
// casiers ont leur propre écran (R10), mais la raison, elle, n'a pas bougé.
//
// Le rafraîchissement est manuel et daté. Un compteur de sécurité qui change tout seul pendant
// qu'on le lit ne se cite pas à voix haute — et c'est exactement ce qu'un maître-nageur fait avec.
function SurveillancePoss({ etabActif }) {
  // ⚠ `null` = PAS LU · `[]` = LU, AUCUN PLAN DECLARE. Le bloc les confondait, et les deux
  // menaient au meme `return null` : la section DISPARAISSAIT.
  //
  // C'est la contradiction la plus couteuse trouvee sur cet ecran, parce que le commentaire
  // ci-dessus enonce exactement ce que le code defait : << on ne range pas une limite de securite
  // derriere un clic >>. On ne la fait pas disparaitre non plus. Un bandeau absent ne laisse
  // AUCUNE trace -- pas meme un vide qu'on remarquerait -- et un maitre-nageur qui survole le haut
  // de l'ecran conclut qu'il n'y a rien a surveiller.
  const [etats, setEtats] = useState(null)
  const [erreur, setErreur] = useState(null)
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
      setErreur(null)
      setLuA(new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }))
    } catch (e) {
      // `catch { setEtats([]) }` avalait l'erreur ET effacait la distinction : plus de message,
      // plus de liste, plus de bloc. Trois informations perdues en deux mots.
      setEtats(null)
      setErreur(e?.message || 'Le plan de surveillance n’a pas pu être lu.')
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  // Pendant le tout premier chargement on ne peint rien : un cadre qui clignote sur un compteur
  // de securite est pire qu'un cadre qui arrive une seconde plus tard.
  if (chargement && etats === null && !erreur) return null

  // ⚠ LA LECTURE A ECHOUE : LE BLOC RESTE, ET IL LE DIT.
  if (etats === null) {
    return (
      <section className="card" style={{ marginBottom: 'var(--esp-bloc)' }}>
        <div className="card-h">
          <h3>Surveillance</h3>
          <span className="sub">seuil du plan de surveillance</span>
          <div className="r">
            <button className="btn ghost sm" type="button" onClick={recharger}>Actualiser</button>
          </div>
        </div>
        <div className="card-b">
          <div className="banner banner-error">
            <b>Le seuil de surveillance n’a pas pu être lu.</b> Cet écran ne peut pas dire combien
            de baigneurs sont présents ni si le plan est dépassé. <b>N’en concluez pas que tout va
            bien</b> : comptez sur place, ou rechargez.
            {erreur ? <div className="sub" style={{ marginTop: 'var(--esp-serre)' }}>{erreur}</div> : null}
          </div>
        </div>
      </section>
    )
  }

  // Lu, et aucun plan declare. Ce n'est pas rien a dire : une piscine sans POSS declare n'a pas de
  // seuil oppose au guichet, et personne ne l'apprenait puisque le bloc s'effacait.
  if (etats.length === 0) {
    return (
      <section className="card" style={{ marginBottom: 'var(--esp-bloc)' }}>
        <div className="card-h">
          <h3>Surveillance</h3>
          <span className="sub">seuil du plan de surveillance</span>
          {/* Le bouton est present dans les deux autres etats : l'omettre ici obligerait a changer
              d'ecran pour revoir le bloc apres avoir declare un plan. */}
          <div className="r">
            <button className="btn ghost sm" type="button" onClick={recharger}>Actualiser</button>
          </div>
        </div>
        <div className="card-b">
          <div className="empty">
            Aucun plan de surveillance déclaré pour cet établissement. Tant qu’il n’y en a pas,
            aucun seuil de fréquentation n’est opposé à la vente ni affiché ici.
          </div>
        </div>
      </section>
    )
  }

  return (
    <section className="card" style={{ marginBottom: 'var(--esp-bloc)' }}>
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
  const [qualifications, setQualifications] = useState([])
  const [affectations, setAffectations] = useState([])
  const [tronque, setTronque] = useState(false)
  const [aAffecter, setAAffecter] = useState(null)

  // ⚠ CES DEUX LECTURES NE SONT PAS DECORATIVES : sans elles, la colonne « Affecté » ne pourrait
  // qu'afficher un identifiant, et le bouton d'affectation ne saurait pas quoi proposer. Un refus
  // de lecture est donc dit, jamais avale en liste vide — sinon l'ecran annoncerait « aucun
  // encadrant affecté » sur des créneaux qui en ont un.
  useEffect(() => {
    let vivant = true
    Promise.all([api.qualificationsEncadrant(), api.affectationsEncadrant()])
      .then(([q, a]) => {
        if (!vivant) return
        const lq = membres(q)
        const la = membres(a)
        setQualifications(lq)
        setAffectations(la)
        const tq = q?.totalItems ?? q?.['hydra:totalItems']
        const ta = a?.totalItems ?? a?.['hydra:totalItems']
        setTronque((typeof tq === 'number' && tq > lq.length) || (typeof ta === 'number' && ta > la.length))
      })
      .catch((e) => {
        if (!vivant) return
        setQualifications([])
        setAffectations([])
        setErreur(e.message || 'Les qualifications d’encadrants n’ont pas pu être lues.')
      })
    return () => { vivant = false }
  }, [etabActif, version])

  const parQualification = {}
  for (const q of qualifications) parQualification[String(q.id)] = q

  const affectationsParCreneau = {}
  for (const a of affectations) {
    const cle = idDe(a.creneauBassin)
    if (!cle) continue
    ;(affectationsParCreneau[cle] = affectationsParCreneau[cle] || []).push(a)
  }

  // La qualification d'une affectation arrive imbriquée (groupe `affect:read`) ou en IRI selon le
  // chemin. Imbriquée, elle porte déjà tout ; en IRI, on la retrouve dans la table.
  const qualifDe = (a) => (a && typeof a.qualification === 'object' && a.qualification !== null
    ? a.qualification
    : parQualification[idDe(a?.qualification)])

  const nomEncadrant = (a) => libelleEncadrant(qualifDe(a))

  // La MEME regle que `QualificationEncadrant::estValideA()` : `dateValidite >= debut du creneau`,
  // et le type doit couvrir celui qu'exige le creneau. Recopier la regle du serveur est un risque
  // assume ici — l'alternative serait de n'afficher aucun verdict, donc de laisser l'exploitant
  // decouvrir le refus au clic.
  const estCouvrante = (a, creneau) => {
    const q = qualifDe(a)
    if (!q || q.type !== creneau.encadrantRequis) return false
    if (!q.dateValidite || !creneau.debut) return false
    return new Date(q.dateValidite) >= new Date(creneau.debut)
  }

  const colonnes = [
    { cle: 'bassin', entete: 'Bassin', rendu: (r) => texte(r.bassin?.libelle, String(r.bassin || '').split('/').pop() || '—') },
    { cle: 'debut', entete: 'Début', rendu: (r) => heure(r.debut) },
    { cle: 'fin', entete: 'Fin', rendu: (r) => heure(r.fin) },
    // ⚠ `'aucune'` EST UNE CHAINE, DONC TRUTHY. Cette colonne rendait « requis » pour TOUS les
    // creneaux, à commencer par ceux qui n'exigent rien — c'est la valeur par defaut de l'enum.
    // On montre le type, qui est ce sur quoi la regle serveur s'aligne.
    {
      cle: 'encadrantRequis',
      entete: 'Encadrant requis',
      rendu: (r) => (!r.encadrantRequis || r.encadrantRequis === 'aucune'
        ? <span className="sub">aucun</span>
        : <span className="badge info">{r.encadrantRequis}</span>),
    },
    {
      cle: 'affecte',
      entete: 'Affecté',
      rendu: (r) => {
        if (!r.encadrantRequis || r.encadrantRequis === 'aucune') return <span className="sub">—</span>
        const posees = affectationsParCreneau[String(r.id)] || []
        const couvrantes = posees.filter((a) => estCouvrante(a, r))
        if (couvrantes.length > 0) {
          return <span className="badge good">{couvrantes.map((a) => nomEncadrant(a)).join(', ')}</span>
        }
        return (
          <span className="badge crit" title="Le serveur refusera la validation">
            {posees.length > 0 ? 'diplôme expiré' : 'aucun'}
          </span>
        )
      },
    },
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
        const exige = r.encadrantRequis && r.encadrantRequis !== 'aucune'
        return (
          <div style={{ textAlign: 'right' }}>
            {exige && (
              <button
                className="btn ghost sm"
                type="button"
                disabled={busy}
                style={{ padding: '1px 8px', fontSize: 11.5, marginRight: 'var(--esp-serre)' }}
                onClick={() => setAAffecter(r)}
              >
                Affecter
              </button>
            )}
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
      {tronque && (
        <div className="banner banner-warn">
          Le serveur détient plus de qualifications ou d’affectations que cette page n’en a lu : la
          colonne « Affecté » peut annoncer une absence qui n’en est pas une.
        </div>
      )}
      <Liste
        titre="Créneaux bassins"
        sous="planning surveillance"
        deps={[etabActif, version]}
        charger={api.creneauxBassin}
        vide="Aucun créneau planifié."
        colonnes={colonnes}
      />
      {aAffecter && (
        <AffecterEncadrantModal
          creneau={aAffecter}
          qualifications={qualifications}
          dejaPosees={affectationsParCreneau[String(aAffecter.id)] || []}
          onFermer={() => setAAffecter(null)}
          onFait={() => { setAAffecter(null); setVersion((v) => v + 1) }}
          onErreur={setErreur}
        />
      )}
    </div>
  )
}

/**
 * AFFECTER UN ENCADRANT A UN CRENEAU — le geste qui rend « Valider » possible.
 *
 * ⚠ ON NE PROPOSE QUE CE QUE LE SERVEUR ACCEPTERA : type couvrant le besoin du créneau, et diplôme
 * valide À LA DATE DU CRÉNEAU (`dateValidite >= debut`, la règle exacte de `estValideA`). Proposer
 * un diplôme expiré ferait cliquer, puis refuser la validation trois écrans plus loin, sans que
 * rien ne relie les deux.
 */
function AffecterEncadrantModal({ creneau, qualifications, dejaPosees, onFermer, onFait, onErreur }) {
  const [choix, setChoix] = useState('')
  const [envoi, setEnvoi] = useState(false)

  const posees = new Set(dejaPosees.map((a) => idDe(a.qualification)).filter(Boolean))

  const eligibles = qualifications.filter((q) => {
    if (q.type !== creneau.encadrantRequis) return false
    if (!q.dateValidite || !creneau.debut) return false
    if (posees.has(String(q.id))) return false
    return new Date(q.dateValidite) >= new Date(creneau.debut)
  })

  async function valider() {
    setEnvoi(true)
    onErreur(null)
    try {
      await api.affecterEncadrant({
        creneauBassin: `/api/creneau_bassins/${creneau.id}`,
        qualification: `/api/qualification_encadrants/${choix}`,
      })
      onFait()
    } catch (e) {
      onErreur(e.message || 'L’affectation a échoué.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre="Affecter un encadrant" taille="md">
      <p className="hint">
        Ce créneau exige un encadrement <strong>{creneau.encadrantRequis}</strong>. Le serveur
        refusera de le valider sans un encadrant dont le diplôme couvre ce type et reste valide au{' '}
        {heure(creneau.debut)}.
      </p>

      {eligibles.length === 0 ? (
        <p className="empty">
          Aucun diplôme <strong>{creneau.encadrantRequis}</strong> valide à cette date n’est
          enregistré, ou tous sont déjà affectés à ce créneau. Enregistrez-en un dans « Qualifications
          d’encadrants », plus bas.
        </p>
      ) : (
        <div className="field">
          <label htmlFor="pi-qual">Encadrant</label>
          <select id="pi-qual" className="select" value={choix} onChange={(e) => setChoix(e.target.value)}>
            <option value="">Choisir…</option>
            {eligibles.map((q) => (
              <option key={q.id} value={q.id}>
                {libelleEncadrant(q)} — {q.type}, valide jusqu’au {dateFr(q.dateValidite)}
              </option>
            ))}
          </select>
        </div>
      )}

      <div className="bar">
        <button type="button" className="btn" onClick={onFermer}>Annuler</button>
        <button type="button" className="btn primary" onClick={valider} disabled={envoi || !choix}>
          {envoi ? 'Affectation…' : 'Affecter'}
        </button>
      </div>
    </Modal>
  )
}

/**
 * LES DIPLÔMES DES ENCADRANTS — ce que « Valider » exige, et qui n'existait nulle part.
 *
 * `ValiderCreneauBassinHandler` refuse un créneau exigeant un encadrement sans affectation dont la
 * qualification couvre le type ET reste valide à la date du créneau (RG-PISC-02). Aucun écran ne
 * permettait d'enregistrer un diplôme : le refus n'avait donc aucune issue.
 *
 * ⚠ ET LA ROUTE DE CRÉATION RENDAIT 500 jusqu'au 05/09 — établissement `NOT NULL` que rien ne
 * posait. Corrigée côté serveur dans le même lot ; sans cela, ce formulaire aurait été un bouton
 * mort de plus.
 *
 * ⚠ ON N'EFFACE PAS UN DIPLÔME EXPIRÉ. Le handler le dit : « qualification expirée = non prise en
 * compte, calcul seulement — pas de suppression ». L'API ne l'expose d'ailleurs pas : ni `Delete`,
 * seulement `Post` et `Patch`. On corrige une date d'échéance, on n'efface pas un historique.
 */
function QualificationsEncadrants({ etabActif, droits = [] }) {
  const peutGerer = aLeDroit(droits, 'piscine.gerer')
  const [version, setVersion] = useState(0)
  const [creation, setCreation] = useState(false)
  const [erreur, setErreur] = useState(null)

  const maintenant = new Date()

  return (
    <div>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      <Liste
        titre="Qualifications d’encadrants"
        sous="diplômes et échéances"
        deps={[etabActif, version]}
        charger={api.qualificationsEncadrant}
        vide="Aucun diplôme enregistré. Sans diplôme valide, un créneau exigeant un encadrement ne peut pas être validé."
        actions={peutGerer ? (
          <button className="btn sm" type="button" onClick={() => setCreation(true)}>
            ＋ Enregistrer un diplôme
          </button>
        ) : null}
        colonnes={[
          { cle: 'encadrant', entete: 'Encadrant', rendu: (r) => libelleEncadrant(r) },
          { cle: 'type', entete: 'Diplôme', rendu: (r) => <span className="badge info">{r.type || '—'}</span> },
          {
            cle: 'dateValidite',
            entete: 'Valide jusqu’au',
            rendu: (r) => {
              const expire = r.dateValidite && new Date(r.dateValidite) < maintenant
              return (
                <>
                  {dateFr(r.dateValidite)}
                  {/* Expiré ne veut pas dire supprimé : la ligne reste, elle cesse simplement de
                      couvrir un créneau. Le dire évite qu'on la cherche ailleurs. */}
                  {expire && <div className="sub">expiré — ne couvre plus aucun créneau</div>}
                </>
              )
            },
          },
        ]}
      />
      {creation && (
        <QualificationModal
          onFermer={() => setCreation(false)}
          onCree={() => { setCreation(false); setVersion((v) => v + 1) }}
          onErreur={setErreur}
        />
      )}
    </div>
  )
}

function QualificationModal({ onFermer, onCree, onErreur }) {
  const [utilisateurs, setUtilisateurs] = useState(null)
  const [encadrant, setEncadrant] = useState('')
  const [type, setType] = useState('MNS')
  const [validite, setValidite] = useState('')
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    let vivant = true
    api.utilisateurs()
      .then((r) => { if (vivant) setUtilisateurs(membres(r)) })
      .catch((e) => {
        if (!vivant) return
        // On distingue « je n'ai pas pu lire » de « il n'y a personne » : sans ça, un refus de
        // lecture se lirait comme un établissement sans personnel.
        setUtilisateurs([])
        onErreur(e.message || 'La liste des utilisateurs n’a pas pu être lue.')
      })
    return () => { vivant = false }
  }, [onErreur])

  async function valider() {
    setEnvoi(true)
    onErreur(null)
    try {
      await api.creerQualificationEncadrant({
        encadrant: `/api/utilisateurs/${encadrant}`,
        type,
        dateValidite: validite,
      })
      onCree()
    } catch (e) {
      onErreur(e.message || 'Le diplôme n’a pas pu être enregistré.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open onClose={onFermer} titre="Enregistrer un diplôme" taille="md">
      <div className="field">
        <label htmlFor="pi-enc">Encadrant</label>
        {utilisateurs === null ? (
          <div className="spinner" />
        ) : (
          <select id="pi-enc" className="select" value={encadrant} onChange={(e) => setEncadrant(e.target.value)}>
            <option value="">Choisir…</option>
            {utilisateurs.map((u) => (
              <option key={u.id} value={u.id}>{u.email || u.nom || u.id}</option>
            ))}
          </select>
        )}
      </div>

      <div className="field">
        <label htmlFor="pi-type">Diplôme</label>
        <select id="pi-type" className="select" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="MNS">MNS — maître-nageur sauveteur</option>
          <option value="BNSSA">BNSSA — surveillant de baignade</option>
          <option value="autre">Autre</option>
        </select>
        <div className="hint">
          {/* « aucune » existe dans l'enum du serveur mais désigne l'ABSENCE d'exigence sur un
              créneau : un diplôme « aucune » ne couvrirait rien. On ne le propose donc pas. */}
          Le diplôme doit couvrir exactement le type exigé par le créneau : un BNSSA ne valide pas un
          créneau qui demande un MNS.
        </div>
      </div>

      <div className="field">
        <label htmlFor="pi-val">Valide jusqu’au</label>
        <input
          id="pi-val"
          className="input"
          type="date"
          value={validite}
          onChange={(e) => setValidite(e.target.value)}
        />
        <div className="hint">
          Un diplôme couvre un créneau tant que cette date n’est pas dépassée par la date du créneau.
        </div>
      </div>

      <div className="bar">
        <button type="button" className="btn" onClick={onFermer}>Annuler</button>
        <button
          type="button"
          className="btn primary"
          onClick={valider}
          disabled={envoi || !encadrant || !validite}
        >
          {envoi ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </Modal>
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

            <div className="row row-champs" style={{ display: 'flex', gap: 'var(--esp-large)' }}>
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
