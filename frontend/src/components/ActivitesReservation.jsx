import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { libelleProduit } from '../api/produit.js'
import Modal from './Modal.jsx'

/**
 * LES ACTIVITÉS DE RÉSERVATION — un module entier dont l'exploitant ne pouvait rien régler.
 *
 * `GET/POST/PATCH /api/reservation_activites` existent depuis l'origine. La lecture était même déjà
 * branchée dans le client (`api.reservationActivites`) et n'était appelée par aucun écran : les
 * activités n'apparaissaient nulle part, et rien ne permettait d'en créer ni d'en corriger une.
 *
 * ⚠ CE QUI REND CET ÉCRAN URGENT N'EST PAS LE CONFORT, C'EST UNE LIGNE COMPTABLE FANTÔME.
 *
 * `VenteReservationHandler` pose le produit de la ligne de vente à partir de
 * `Activite::$produitTarifReference`. Quand il est absent, il tire un identifiant au hasard : la
 * ligne désigne un produit qui n'existe pas, donc sans catégorie comptable, donc absente de la
 * ventilation — et le libellé reste vide.
 *
 * Mesure du 04/09 : les trois activités existantes n'ont AUCUN produit. Le champ est écrivable par
 * l'API depuis toujours ; aucun écran ne le proposait. Cet écran est le prérequis qui permettra de
 * rendre le produit obligatoire — jusque-là, l'exiger reviendrait à refuser toute vente de
 * réservation sans aucun moyen de débloquer.
 */

const VIDE = {
  libelle: '',
  typeActivite: '',
  dureeMinutes: '60',
  battementMinutes: '0',
  niveauRequis: '',
  competenceExigee: '',
  tarifReferenceMontant: '0.00',
  produit: '',
  actif: true,
}

function idDeProduit(ref) {
  if (!ref) return ''
  return typeof ref === 'string' ? String(ref).split('/').pop() : String(ref.id || '')
}

export default function ActivitesReservation({ droits = [] }) {
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [activites, setActivites] = useState(null)
  const [produits, setProduits] = useState(null)
  const [edition, setEdition] = useState(null)
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  const peutGerer = aLeDroit(droits, 'reservation.gerer_ressource')

  function charger() {
    setActivites(null)
    api.reservationActivites()
      .then((r) => setActivites(membres(r)))
      .catch(() => setActivites(undefined))
  }

  useEffect(() => {
    charger()
    // Le catalogue sert à choisir le produit. Son absence n'empêche pas de lire les activités :
    // on garde `undefined` pour le dire, plutôt qu'une liste vide qui ferait croire à un catalogue
    // sans produits.
    api.produits({ itemsPerPage: 200 })
      .then((r) => setProduits(membres(r)))
      .catch(() => setProduits(undefined))
  }, [])

  const parId = useMemo(() => {
    const m = {}
    for (const p of produits || []) m[String(p.id)] = p
    return m
  }, [produits])

  const sansProduit = Array.isArray(activites)
    ? activites.filter((a) => !a.produitTarifReference).length
    : 0

  return (
    <section className="card">
      <div className="card-h">
        <h3>Activités</h3>
        <span className="sub">
          {activites === null ? 'lecture…' : activites === undefined ? 'illisible' : `${activites.length}`}
        </span>
        {peutGerer && (
          <button
            className="btn primary sm"
            type="button"
            style={{ marginLeft: 'auto' }}
            onClick={() => setEdition({ ...VIDE })}
          >
            Nouvelle activité
          </button>
        )}
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {activites === undefined && (
          <div className="banner banner-warn">
            Les activités n’ont pas pu être lues. Cet écran ne sait donc pas si elles sont
            correctement réglées — ce n’est pas la même chose que « tout va bien ».
          </div>
        )}

        {/* ⚠ CE BANDEAU EST LE CŒUR DE L'ÉCRAN. Une activité sans produit vend quand même — et
            produit une ligne comptable qui ne désigne rien. Le défaut ne se voit ni à la caisse ni
            au ticket : il se découvre à la ventilation, quand on cherche pourquoi un montant
            n'appartient à aucune catégorie. */}
        {sansProduit > 0 && (
          <div className="banner banner-warn">
            <b>
              {sansProduit} activité{sansProduit > 1 ? 's' : ''} sans produit de référence.
            </b>{' '}
            Une vente issue de ces activités crée une ligne qui ne désigne aucun produit du
            catalogue&nbsp;: elle sort de la ventilation comptable, et son libellé reste vide.
            Renseignez le produit sous lequel l’activité se vend.
          </div>
        )}

        {activites === null && <div className="empty">Lecture des activités…</div>}

        {Array.isArray(activites) && activites.length === 0 && (
          <div className="empty">
            Aucune activité. Une activité décrit ce qui se réserve — un cours, un créneau de bassin,
            un rendez-vous — sa durée par défaut et le tarif sous lequel elle se vend.
          </div>
        )}

        {Array.isArray(activites) && activites.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Activité</th>
                  <th>Type</th>
                  <th className="num">Durée</th>
                  <th className="num">Tarif</th>
                  <th>Produit de référence</th>
                  <th>État</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {activites.map((a) => {
                  const pid = idDeProduit(a.produitTarifReference)
                  const p = parId[pid]
                  return (
                    <tr key={a.id}>
                      <td>
                        <span className="nm">{a.libelle || '—'}</span>
                        {a.niveauRequis && <div className="sub">niveau : {a.niveauRequis}</div>}
                      </td>
                      <td>{a.typeActivite || <span className="sub">—</span>}</td>
                      <td className="num">
                        {a.dureeMinutes} min
                        {a.battementMinutes > 0 && <div className="sub">+{a.battementMinutes} de battement</div>}
                      </td>
                      <td className="num">{a.tarifReferenceMontant}</td>
                      <td>
                        {/* Trois cas distincts, et le troisième n'est pas le deuxième : « aucun
                            produit » est un défaut à corriger ; « catalogue non lu » veut dire
                            qu'on n'a pas su regarder. */}
                        {!pid
                          ? <span className="badge warn">aucun</span>
                          : produits === undefined
                            ? <span className="sub">catalogue non lu</span>
                            : p
                              ? libelleProduit(p)
                              : <span className="badge crit">produit introuvable</span>}
                      </td>
                      <td>
                        {a.actif
                          ? <span className="badge good">active</span>
                          : <span className="badge mut">inactive</span>}
                      </td>
                      {peutGerer && (
                        <td>
                          <button
                            className="btn ghost sm"
                            type="button"
                            onClick={() => setEdition({
                              id: a.id,
                              libelle: a.libelle || '',
                              typeActivite: a.typeActivite || '',
                              dureeMinutes: String(a.dureeMinutes ?? 60),
                              battementMinutes: String(a.battementMinutes ?? 0),
                              niveauRequis: a.niveauRequis || '',
                              competenceExigee: a.competenceExigee || '',
                              tarifReferenceMontant: a.tarifReferenceMontant || '0.00',
                              produit: pid,
                              actif: a.actif !== false,
                            })}
                          >
                            Modifier
                          </button>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <EditionActivite
        valeurs={edition}
        produits={produits}
        onFermer={() => setEdition(null)}
        onFait={(message) => { setEdition(null); setSucces(message); setErreur(null); charger() }}
        onErreur={setErreur}
      />
    </section>
  )
}

/**
 * ⚠ LE PRODUIT N'EST PAS EXIGÉ PAR CE FORMULAIRE, ET C'EST DÉLIBÉRÉ.
 *
 * Le serveur l'accepte encore vide, et les trois activités existantes le sont. Bloquer ici
 * empêcherait de corriger les autres champs d'une activité tant qu'on n'a pas tranché son produit —
 * on transformerait un défaut en impasse. Le manque est donc SIGNALÉ, partout et sans détour, mais
 * il ne verrouille pas l'enregistrement.
 *
 * Le jour où le serveur exigera le produit (§8.8 c), ce formulaire sera l'endroit qui aura permis
 * de le renseigner — et c'est cet ordre-là qui compte.
 */
function EditionActivite({ valeurs, produits, onFermer, onFait, onErreur }) {
  const [v, setV] = useState(VIDE)
  const [busy, setBusy] = useState(false)

  useEffect(() => { if (valeurs) setV(valeurs) }, [valeurs])

  if (!valeurs) return null

  function champ(nom, valeur) {
    setV((precedent) => ({ ...precedent, [nom]: valeur }))
  }

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = {
        libelle: v.libelle.trim(),
        typeActivite: v.typeActivite.trim(),
        dureeMinutes: Number(v.dureeMinutes) || 0,
        battementMinutes: Number(v.battementMinutes) || 0,
        niveauRequis: v.niveauRequis.trim() || null,
        competenceExigee: v.competenceExigee.trim() || null,
        tarifReferenceMontant: String(v.tarifReferenceMontant || '0.00'),
        actif: !!v.actif,
        // La relation s'écrit en IRI. `null` détache explicitement : un champ absent laisserait la
        // valeur précédente, et on n'aurait aucun moyen de retirer un produit posé par erreur.
        produitTarifReference: v.produit ? `/api/produits/${v.produit}` : null,
      }
      if (v.id) await api.majActiviteReservation(v.id, corps)
      else await api.creerActiviteReservation(corps)
      await onFait(v.id ? 'Activité enregistrée.' : 'Activité créée.')
    } catch (e) {
      onErreur(e.message || 'L’activité n’a pas pu être enregistrée.')
    } finally {
      setBusy(false)
    }
  }

  const pret = v.libelle.trim() !== '' && Number(v.dureeMinutes) > 0

  return (
    <Modal
      open
      onClose={onFermer}
      titre={v.id ? 'Modifier l’activité' : 'Nouvelle activité'}
      taille="sm"
    >
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Nom de l’activité *</span>
          <input className="input" value={v.libelle} onChange={(e) => champ('libelle', e.target.value)} maxLength={120} />
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Type</span>
          <input
            className="input"
            value={v.typeActivite}
            onChange={(e) => champ('typeActivite', e.target.value)}
            placeholder="cours collectif, rendez-vous, location…"
            maxLength={60}
          />
        </label>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-normal)' }}>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Durée (minutes) *</span>
            <input className="input" type="number" min="1" value={v.dureeMinutes} onChange={(e) => champ('dureeMinutes', e.target.value)} />
          </label>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Battement (minutes)</span>
            <input className="input" type="number" min="0" value={v.battementMinutes} onChange={(e) => champ('battementMinutes', e.target.value)} />
            <span className="sub">Temps laissé entre deux créneaux.</span>
          </label>
        </div>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Produit de référence</span>
          {produits === undefined ? (
            <span className="sub">
              Le catalogue n’a pas pu être lu : impossible de choisir un produit pour l’instant.
            </span>
          ) : (
            <select className="select" value={v.produit} onChange={(e) => champ('produit', e.target.value)}>
              <option value="">— aucun —</option>
              {(produits || []).map((p) => (
                <option key={p.id} value={p.id}>{libelleProduit(p)}{p.code ? ` (${p.code})` : ''}</option>
              ))}
            </select>
          )}
          {!v.produit && (
            <span className="sub">
              ⚠ Sans produit, une vente issue de cette activité crée une ligne qui ne désigne rien au
              catalogue : elle sort de la ventilation comptable.
            </span>
          )}
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Tarif de référence</span>
          <input
            className="input"
            inputMode="decimal"
            value={v.tarifReferenceMontant}
            onChange={(e) => champ('tarifReferenceMontant', e.target.value)}
          />
        </label>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-normal)' }}>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Niveau requis</span>
            <input className="input" value={v.niveauRequis} onChange={(e) => champ('niveauRequis', e.target.value)} maxLength={60} />
          </label>
          <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
            <span className="sub">Compétence exigée</span>
            <input className="input" value={v.competenceExigee} onChange={(e) => champ('competenceExigee', e.target.value)} maxLength={60} />
          </label>
        </div>

        <label style={{ display: 'flex', gap: 'var(--esp-normal)', alignItems: 'baseline' }}>
          <input type="checkbox" checked={!!v.actif} onChange={(e) => champ('actif', e.target.checked)} />
          <span>Activité proposée à la réservation</span>
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="button" disabled={busy || !pret} onClick={enregistrer}>
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
