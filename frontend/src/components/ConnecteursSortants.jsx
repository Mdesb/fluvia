import { useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'

/**
 * LES DESTINATIONS SORTANTES — Slack, Teams, Discord, et l'automate maison.
 *
 * ⚠ L'URL NE SE RELIT PAS, ET L'ÉCRAN LE DIT. Elle vaut un mot de passe : qui la détient peut
 * écrire dans le canal au nom de l'établissement. Un formulaire qui la réafficherait pour édition
 * la remettrait sur le réseau à chaque ouverture, au premier utilisateur venu qui a le droit de
 * lire. On la recolle, on ne la modifie pas.
 *
 * ⚠ AUCUN ÉVÉNEMENT COCHÉ = RIEN N'EST ENVOYÉ, jamais « tout ». La règle est contre-intuitive dans
 * le même sens que l'éligibilité des promotions, et l'écran l'écrit là où l'on coche — pas dans un
 * message qui arriverait après.
 */
export default function ConnecteursSortants({ droits, etabActif, params = {}, majParams }) {
  const ouvert = params.destination || ''
  const fermer = () => majParams({ destination: '' }, { pousser: true })
  // `null` = on lit ; `undefined` = on n'a PAS PU lire ; un tableau = on a lu.
  const [destinations, setDestinations] = useState(null)
  const [evenements, setEvenements] = useState(null)
  const [succes, setSucces] = useState(null)
  const [erreur, setErreur] = useState(null)

  const peutGerer = aLeDroit(droits, 'connecteurs.gerer_destination')

  function charger() {
    setDestinations(null)
    api.connecteurs().then((r) => setDestinations(membres(r))).catch(() => setDestinations(undefined))
  }

  useEffect(charger, [etabActif])

  useEffect(() => {
    api.evenementsConnecteurs()
      .then((r) => setEvenements(membres(r)))
      .catch(() => setEvenements(undefined))
  }, [etabActif])

  const muettes = useMemo(
    () => (Array.isArray(destinations) ? destinations.filter((d) => (d.evenements || []).length === 0) : []),
    [destinations],
  )
  const enEchec = useMemo(
    () => (Array.isArray(destinations) ? destinations.filter((d) => d.dernierEchec) : []),
    [destinations],
  )

  async function supprimer(d) {
    setErreur(null)
    try {
      await api.supprimerConnecteur(d.id)
      setSucces(`Destination « ${d.libelle} » supprimée.`)
      charger()
    } catch (e) {
      setErreur(e.message || 'La destination n’a pas pu être supprimée.')
    }
  }

  // ── LA DESTINATION, EN ÉCRAN ────────────────────────────────────────────────────────────
  //
  // ⚠ `nouvelle` NE VEUT PAS DIRE « objet vide ». Le bouton pré-remplissait `kind: 'slack'` et
  // une liste d'événements vide : c'est ce défaut-là qu'il faut reconstruire, sinon le
  // formulaire s'ouvrirait sans service choisi là où il en proposait un.
  //
  // ⚠ TROIS ÉTATS, et le fichier les nomme en tête : `null` = on lit, `undefined` = on n'a pas
  // pu lire, un tableau = on a lu. On les consulte avant de conclure « elle n'existe pas ».
  if (ouvert) {
    const creation = ouvert === 'nouvelle'
    const retour = (
      <button className="btn ghost sm" type="button" onClick={fermer}
        style={{ marginBottom: 'var(--esp-large)' }}>
        ← Retour aux connecteurs
      </button>
    )
    if (!creation && destinations === null) {
      return (
        <section className="card"><div className="card-b">
          {retour}
          <div className="empty">Lecture des destinations…</div>
        </div></section>
      )
    }
    if (!creation && destinations === undefined) {
      return (
        <section className="card"><div className="card-b">
          {retour}
          <div className="banner banner-warn">
            Les destinations n’ont pas pu être lues, donc celle-ci non plus. Ce n’est pas la même
            chose que « elle n’existe pas ».
          </div>
        </div></section>
      )
    }
    const d = creation ? null : (destinations || []).find((x) => String(x.id) === String(ouvert))
    if (!creation && !d) {
      return (
        <section className="card"><div className="card-b">
          {retour}
          <div className="banner banner-warn">
            Cette destination n’est plus dans la liste — elle a sans doute été supprimée depuis
            que ce lien a été copié. Revenez à la liste plutôt que d’en recréer une.
          </div>
        </div></section>
      )
    }
    // ⚠ `url` REPART TOUJOURS VIDE, en création comme en modification : le serveur ne la relit
    // pas, et l'afficher donnerait à croire qu'on la conserve. C'était déjà le cas avant.
    const valeurs = creation
      ? { kind: 'slack', libelle: '', url: '', evenements: [], actif: true }
      : { id: d.id, kind: d.kind, libelle: d.libelle, url: '', evenements: [...(d.evenements || [])], actif: d.actif }
    return (
      <section className="card"><div className="card-b">
        {retour}
        {erreur && <div className="banner banner-error">{erreur}</div>}
        <EditionDestination
          key={ouvert}
          valeurs={valeurs}
          evenements={evenements}
          onFermer={fermer}
          onFait={(m) => { fermer(); setSucces(m); setErreur(null); charger() }}
          onErreur={setErreur}
        />
      </div></section>
    )
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>Destinations sortantes</h3>
        <span className="sub">
          {destinations === null ? 'lecture…' : destinations === undefined ? 'illisible' : `${destinations.length}`}
        </span>
        {peutGerer && (
          <button
            type="button"
            className="btn primary sm"
            style={{ marginLeft: 'auto' }}
            onClick={() => majParams({ destination: 'nouvelle' }, { pousser: true })}
          >
            Ajouter une destination
          </button>
        )}
      </div>

      <div className="card-b">
        <div className="sub" style={{ marginBottom: 'var(--esp-normal)' }}>
          Fluvia envoie un message dans votre canal quand un événement se produit. L’adresse du
          webhook se colle une fois&nbsp;: elle vaut un mot de passe, elle n’est jamais réaffichée.
        </div>

        {succes && <div className="banner banner-ok">{succes}</div>}
        {erreur && <div className="banner banner-error">{erreur}</div>}

        {destinations === undefined && (
          <div className="banner banner-warn">
            Les destinations n’ont pas pu être lues. Cet écran ne sait donc pas ce qui est branché —
            ce n’est pas la même chose que « rien ».
          </div>
        )}

        {/* ⚠ UNE DESTINATION EN ÉCHEC EST UN CANAL MUET, et un canal muet ressemble exactement à un
            canal calme. On ne s'en aperçoit que le jour où l'on comptait sur l'alerte. */}
        {enEchec.length > 0 && (
          <div className="banner banner-error">
            <b>{enEchec.length} destination{enEchec.length > 1 ? 's' : ''} en échec.</b> Rien n’y
            arrive plus. Une adresse révoquée dans Slack ou Teams produit exactement ce symptôme.
          </div>
        )}

        {muettes.length > 0 && (
          <div className="banner banner-warn">
            <b>{muettes.length} destination{muettes.length > 1 ? 's' : ''} sans aucun événement
            coché.</b> Elles n’enverront rien&nbsp;: aucun événement ne veut dire aucun envoi, pas
            « tous ».
          </div>
        )}

        {destinations === null && <div className="empty">Lecture des destinations…</div>}

        {Array.isArray(destinations) && destinations.length === 0 && (
          <div className="empty">
            Aucune destination. Créez-en une avec l’URL de webhook fournie par Slack, Teams ou
            Discord.
          </div>
        )}

        {Array.isArray(destinations) && destinations.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Destination</th>
                  <th>Service</th>
                  <th>Événements</th>
                  <th>État</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {destinations.map((d) => (
                  <tr key={d.id}>
                    <td>
                      {d.libelle}
                      <div className="sub">{d.hote}</div>
                    </td>
                    <td>{LIBELLE_SERVICE[d.kind] || d.kind}</td>
                    <td>
                      {(d.evenements || []).length === 0
                        ? <span className="badge warn">aucun — rien ne part</span>
                        : `${d.evenements.length}`}
                    </td>
                    <td>
                      {!d.actif && <span className="badge mut">désactivée</span>}
                      {d.actif && d.dernierEchec && <span className="badge crit">en échec</span>}
                      {d.actif && !d.dernierEchec && d.dernierEnvoiLe && <span className="badge good">active</span>}
                      {d.actif && !d.dernierEchec && !d.dernierEnvoiLe && (
                        <span className="badge mut">rien envoyé pour l’instant</span>
                      )}
                      {d.dernierEchec && <div className="sub">{d.dernierEchec}</div>}
                    </td>
                    {peutGerer && (
                      <td>
                        <button
                          type="button"
                          className="btn ghost sm"
                          onClick={() => majParams({ destination: String(d.id) }, { pousser: true })}
                        >
                          Modifier
                        </button>
                        <button type="button" className="btn ghost sm" onClick={() => supprimer(d)}>
                          Supprimer
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* ⚠ CE QUE LE MODULE NE FAIT PAS, ÉCRIT LÀ OÙ ON LE CONFIGURE. Un exploitant qui compte
            dessus pour un flux complet doit le savoir avant, pas après un canal indisponible. */}
        <div className="sub" style={{ marginTop: 'var(--esp-bloc)' }}>
          Les messages partent une fois, sans réessai&nbsp;: un canal indisponible perd l’alerte, et
          la colonne État le dit. C’est fait pour prévenir une équipe, pas pour transporter un flux
          qui doit être complet.
        </div>
      </div>
    </section>
  )
}

const LIBELLE_SERVICE = {
  slack: 'Slack',
  teams: 'Microsoft Teams',
  discord: 'Discord',
  generique: 'Webhook générique',
}

function EditionDestination({ valeurs, evenements, onFermer, onFait, onErreur }) {
  const [v, setV] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => { setV(valeurs) }, [valeurs])

  const parDomaine = useMemo(() => {
    if (!Array.isArray(evenements)) return null
    const g = {}
    for (const e of evenements) {
      const d = e.domaine || 'autre'
      if (!g[d]) g[d] = []
      g[d].push(e)
    }
    return g
  }, [evenements])

  if (!valeurs || !v) return null

  const creation = !v.id
  const pret = v.libelle.trim() !== '' && (!creation || v.url.trim() !== '')

  function basculer(nom) {
    setV((p) => ({
      ...p,
      evenements: p.evenements.includes(nom)
        ? p.evenements.filter((x) => x !== nom)
        : [...p.evenements, nom],
    }))
  }

  async function enregistrer() {
    setBusy(true)
    onErreur(null)
    try {
      const corps = {
        kind: v.kind,
        libelle: v.libelle.trim(),
        evenements: v.evenements,
        actif: v.actif,
      }
      // ⚠ L'URL N'EST ENVOYÉE QUE SI ELLE A ÉTÉ SAISIE. À la modification, un champ laissé vide
      // veut dire « garde l'adresse actuelle » — l'envoyer vide l'effacerait.
      if (v.url.trim() !== '') corps.url = v.url.trim()

      if (v.id) await api.majConnecteur(v.id, corps)
      else await api.creerConnecteur(corps)
      await onFait(v.id ? 'Destination enregistrée.' : 'Destination créée.')
    } catch (e) {
      onErreur(e.message || 'La destination n’a pas pu être enregistrée.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h2>{creation ? 'Nouvelle destination' : 'Modifier la destination'}</h2>
      <div style={{ display: 'grid', gap: 'var(--esp-large)' }}>
        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Service *</span>
          <select className="select" value={v.kind} onChange={(e) => setV((p) => ({ ...p, kind: e.target.value }))}>
            <option value="slack">Slack</option>
            <option value="teams">Microsoft Teams</option>
            <option value="discord">Discord</option>
            <option value="generique">Webhook générique</option>
          </select>
          <span className="sub">
            Le service ne change pas ce qui est envoyé, mais la forme du message&nbsp;: se tromper
            produit un message vide dans le canal, pas une erreur.
          </span>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Nom *</span>
          <input
            className="input"
            value={v.libelle}
            placeholder="#accueil-piscine"
            onChange={(e) => setV((p) => ({ ...p, libelle: e.target.value }))}
          />
          <span className="sub">Ce nom se relit ; une URL, non.</span>
        </label>

        <label style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Adresse du webhook {creation ? '*' : ''}</span>
          <input
            className="input"
            type="password"
            value={v.url}
            placeholder={creation ? 'https://hooks.slack.com/services/…' : 'inchangée'}
            onChange={(e) => setV((p) => ({ ...p, url: e.target.value }))}
          />
          {/* ⚠ ON DIT POURQUOI ELLE NE SE RELIT PAS. Sans cette phrase, un exploitant croit à un
              défaut d'affichage et cherche une case « voir ». */}
          <span className="sub">
            {creation
              ? 'Elle vaut un mot de passe : qui la détient peut écrire dans le canal en votre nom. Elle est chiffrée et ne sera plus jamais réaffichée.'
              : 'Laissez vide pour garder l’adresse actuelle. Elle ne peut pas être relue — pour en changer, recollez-la.'}
          </span>
        </label>

        <div style={{ display: 'grid', gap: 'var(--esp-serre)' }}>
          <span className="sub">Événements à relayer</span>
          {/* ⚠ LA PHRASE QUI COMPTE, LÀ OÙ L'ON COCHE. */}
          <span className="sub">
            Rien de coché = rien n’est envoyé. Une case vide ne veut pas dire « tous ».
          </span>

          {evenements === undefined && (
            <span className="sub">La liste des événements n’a pas pu être lue.</span>
          )}
          {evenements === null && <span className="sub">Lecture…</span>}
          {Array.isArray(evenements) && evenements.length === 0 && (
            <span className="sub">Aucun événement disponible : aucun module n’en déclare.</span>
          )}

          {parDomaine && Object.keys(parDomaine).sort().map((dom) => (
            <div key={dom} style={{ marginTop: 'var(--esp-serre)' }}>
              <div className="sub"><b>{dom}</b></div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--esp-serre)' }}>
                {parDomaine[dom].map((e) => (
                  <button
                    key={e.nom}
                    type="button"
                    className={v.evenements.includes(e.nom) ? 'btn primary sm' : 'btn ghost sm'}
                    onClick={() => basculer(e.nom)}
                    title={`Émis par le module ${e.module}`}
                  >
                    {e.nom.split('.')[1] || e.nom}
                  </button>
                ))}
              </div>
            </div>
          ))}
        </div>

        <label style={{ display: 'flex', gap: 'var(--esp-normal)', alignItems: 'center' }}>
          <input type="checkbox" checked={v.actif} onChange={(e) => setV((p) => ({ ...p, actif: e.target.checked }))} />
          <span className="sub">Destination active</span>
        </label>

        <div style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button className="btn ghost" type="button" onClick={onFermer}>Annuler</button>
          <button className="btn primary" type="button" disabled={busy || !pret} onClick={enregistrer}>
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </>
  )
}
