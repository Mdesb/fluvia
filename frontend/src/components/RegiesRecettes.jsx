import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euroCentimes } from './Liste.jsx'

// DÉCLARER UNE RÉGIE DE RECETTES — et ce n'était pas un écran manquant de plus, c'était le premier.
//
// ── CE QUI A ÉTÉ MESURÉ, ET NON DÉDUIT ─────────────────────────────────────────────────────────
//
// `POST /api/regie_recettes` et `PATCH` existent depuis l'origine du module, tous deux sous
// `compta.gerer`. Aucun écran ne les appelait. Côté serveur, `RegieRecettes` n'est instanciée qu'à
// un seul endroit : `ComptaFixtures` — les jeux d'essai.
//
// Autrement dit, sur un établissement réellement ouvert, il n'existait aucun moyen de créer une
// régie. En base de préprod le 08/09 : six profils d'exploitant, UNE seule régie — « Régie
// piscine A », une fixture. Les cinq autres établissements lisaient « Aucune régie. » dans
// Comptabilité › Régie, définitivement, et rien n'expliquait pourquoi.
//
// ── POURQUOI DANS LES PARAMÈTRES, ET PAS DANS LE MODULE COMPTABLE ──────────────────────────────
//
// C'est le critère de R21, le même qui a déplacé les correspondances comptables : le libellé d'une
// régie, son plafond et son acte de nomination se règlent une fois — à l'arrêté de création, puis
// le jour où le plafond change. Verser, en revanche, est un geste quotidien. Le réglage vient donc
// ici, à côté des points de vente et des moyens de paiement ; le geste reste dans Comptabilité.
//
// ── ⚠ DEUX CHAMPS SONT CONSIGNÉS, PAS APPLIQUÉS — ET L'ÉCRAN LE DIT ────────────────────────────
//
// `modesAutorises` porte les moyens de paiement que l'acte de régie autorise. Mesuré : hors de
// l'entité elle-même, RIEN ne le lit — ni la caisse, ni un validateur. Le docblock de
// `MoyenPaiement` le dit d'ailleurs en toutes lettres, « correspondance manuelle, non synchronisée
// automatiquement dans ce lot ».
//
// Un champ qu'on présente comme un réglage alors qu'il ne règle rien est pire que son absence :
// l'exploitant coche « espèces et chèques seulement » et croit que la caisse refusera une carte.
// Le champ est donc proposé — un régisseur a besoin de consigner ce que son arrêté autorise — mais
// sous son vrai nom : une **mention de l'acte**, avec ce qu'elle ne fait pas.
//
// `periodiciteVersement` est dans le même cas et n'a pas la même valeur de dossier : rien ne le
// lit, et il ne figure sur aucun document. Il n'est pas proposé du tout. Le laisser à son défaut
// serveur (« quotidien ») ne coûte rien ; l'afficher aurait coûté une promesse.
//
// ── LE PLAFOND, LUI, AGIT — ET C'EST LE SEUL ───────────────────────────────────────────────────
//
// `RegieRecettes::depassePlafond()` est lu par `ClotureGuard::pointsBloquants()` : au-dessus du
// plafond, la clôture de la période est REFUSÉE. C'est le seul champ de cet écran dont la valeur
// change ce que le logiciel accepte de faire, et l'écran le dit à côté du champ plutôt que dans une
// aide qu'on n'ouvre pas.
export default function RegiesRecettes({ etabActif, droits = [], rafraichir = 0, onEcrit }) {
  const peutLire = aLeDroit(droits, 'compta.lire')
  const peutGerer = aLeDroit(droits, 'compta.gerer')

  // ⚠ `null` = PAS LU · `[]` = LU ET VIDE. « Aucune régie » est une affirmation : elle dit à
  // l'exploitant qu'il doit en créer une. Une lecture ratée qui rendrait la même phrase l'enverrait
  // créer un doublon.
  const [regies, setRegies] = useState(null)
  const [profils, setProfils] = useState([])
  const [moyens, setMoyens] = useState([])
  const [guichets, setGuichets] = useState([])
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [edition, setEdition] = useState(null) // { id?, libelle, plafond, acteNomination, modes[], profil }
  const [busy, setBusy] = useState(false)

  const charger = useCallback(async () => {
    if (!peutLire) return
    setErreur(null)
    const [r, p, m, g] = await Promise.allSettled([
      api.regieRecettes(),
      api.profilsExploitant(),
      api.moyensPaiement(),
      api.pointDeVentes(),
    ])
    setRegies(r.status === 'fulfilled' ? membres(r.value) : null)
    setProfils(p.status === 'fulfilled' ? membres(p.value) : [])
    setMoyens(m.status === 'fulfilled' ? membres(m.value) : [])
    // ⚠ `null` ET NON `[]` QUAND LA LECTURE RATE. « Aucun guichet ne l'alimente » est une
    // accusation de mauvais paramétrage : la porter sur une lecture qui a échoué enverrait
    // l'exploitant corriger une configuration parfaitement saine.
    setGuichets(g.status === 'fulfilled' ? membres(g.value) : null)
    if (r.status === 'rejected') {
      setErreur(
        r.reason?.status === 403
          ? 'Ce compte n’a pas le droit de lire la comptabilité de cet établissement.'
          : r.reason?.message || 'Les régies de recettes n’ont pas pu être lues.',
      )
    }
  }, [peutLire])

  useEffect(() => { charger() }, [etabActif, charger, rafraichir])

  function ouvrirCreation() {
    setSucces(null)
    setErreur(null)
    setEdition({
      id: null,
      libelle: '',
      plafond: '',
      acteNomination: '',
      modes: [],
      // Un seul profil : on le choisit pour l'exploitant. C'est le cas courant, et la liste
      // déroulante à une entrée est un obstacle qui ne décide de rien.
      profil: profils.length === 1 ? profils[0]['@id'] || `/api/profil_exploitants/${profils[0].id}` : '',
    })
  }

  function ouvrirEdition(r) {
    setSucces(null)
    setErreur(null)
    setEdition({
      id: r.id,
      libelle: r.libelle || '',
      plafond: ((r.plafondEncaisseCentimes || 0) / 100).toFixed(2),
      acteNomination: r.acteNomination || '',
      modes: Array.isArray(r.modesAutorises) ? r.modesAutorises : [],
      profil: '',
    })
  }

  async function enregistrer() {
    setBusy(true)
    setErreur(null)
    try {
      const corps = {
        libelle: edition.libelle.trim(),
        plafondEncaisseCentimes: centimes(edition.plafond),
        acteNomination: edition.acteNomination.trim() || null,
        modesAutorises: edition.modes,
      }
      if (edition.id) {
        await api.majRegieRecettes(edition.id, corps)
        setSucces('Régie mise à jour.')
      } else {
        // ⚠ LE PROFIL EST OBLIGATOIRE CÔTÉ SERVEUR (`nullable: false`, `NotNull`). L'omettre rend
        // un 422 sur un champ que l'écran n'aurait jamais montré — le même piège que le profil
        // comptable des taux de TVA, qui a coûté une mise en vente bloquée.
        await api.creerRegieRecettes({ ...corps, profilExploitant: edition.profil })
        setSucces('Régie créée. Elle apparaît maintenant dans Comptabilité › Régie & versements.')
      }
      setEdition(null)
      charger()
      onEcrit?.()
    } catch (e) {
      setErreur(e.message || 'L’enregistrement n’a pas abouti.')
    } finally {
      setBusy(false)
    }
  }

  if (!peutLire) {
    return (
      <section className="card">
        <div className="card-h"><h3>Régies de recettes</h3></div>
        <div className="card-b">
          <div className="empty">Ce compte n’a pas le droit de lire la comptabilité.</div>
        </div>
      </section>
    )
  }

  const plafondCentimes = edition ? centimes(edition.plafond) : 0
  const complet = edition
    && edition.libelle.trim().length > 0
    && plafondCentimes > 0
    && (edition.id || edition.profil)

  return (
    <section className="card">
      <div className="card-h">
        <h3>Régies de recettes</h3>
        <span className="sub">qui encaisse au nom de la collectivité, et jusqu’à combien</span>
        {peutGerer && !edition && (
          <div className="r">
            <button
              type="button"
              className="btn primary sm"
              disabled={profils.length === 0}
              title={profils.length === 0 ? 'Aucun profil d’exploitant : la comptabilité n’est pas encore configurée pour cet établissement.' : undefined}
              onClick={ouvrirCreation}
            >
              ＋ Déclarer une régie
            </button>
          </div>
        )}
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {/* CE QU'EST UNE RÉGIE, AVANT LA LISTE. Le mot est du vocabulaire de comptabilité publique :
            un exploitant privé ne le connaît pas, et un régisseur municipal ne devine pas que
            « plafond d'encaisse » est ce qui fera refuser sa clôture. */}
        <p className="cpt-intro">
          Une régie de recettes est l’autorisation, donnée par arrêté à un agent, d’encaisser de
          l’argent au nom de la collectivité. <b>Elle ne concerne que les exploitants en régie
          directe</b> — un délégataire ou un groupe privé n’en déclare pas.
        </p>

        {regies === null ? (
          <div className="banner banner-error">
            Les régies n’ont pas pu être lues. <b>N’en concluez pas qu’il n’y en a aucune</b> : cette
            liste n’a pas été obtenue, et en créer une seconde ferait un doublon.
          </div>
        ) : regies.length === 0 && !edition ? (
          <div className="empty">
            Aucune régie déclarée. Tant qu’il n’y en a pas, l’onglet Comptabilité › Régie reste vide
            et aucun versement ne peut être enregistré.
          </div>
        ) : (
          regies.map((r) => (
            <div key={r.id} className="sup-acces">
              <div className="sup-acces-t">
                <span className="nm">{r.libelle || '—'}</span>
                {peutGerer && !edition && (
                  <button type="button" className="btn ghost sm" onClick={() => ouvrirEdition(r)}>
                    Modifier
                  </button>
                )}
              </div>
              <div className="hint">
                Plafond d’encaisse <span className="mono">{euroCentimes(r.plafondEncaisseCentimes || 0)}</span>
                {r.acteNomination ? <> · acte <span className="mono">{r.acteNomination}</span></> : null}
              </div>
              {(r.modesAutorises || []).length > 0 && (
                <div className="hint">
                  Moyens portés par l’acte : {(r.modesAutorises || []).join(', ')}
                </div>
              )}
              {/* ⚠ LE SEUL ENDROIT OÙ UN RATTACHEMENT OUBLIÉ SE VOIT.
                  Une régie sans guichet n'est alimentée par rien : son encaisse reste à zéro, son
                  plafond n'est jamais atteint, et l'écran de versement n'aura jamais rien à verser
                  — exactement l'état d'avant ce lot, mais avec une régie déclarée qui donne
                  l'impression que tout est en place.
                  On ne le détecte pas à la clôture de caisse : ce serait une requête à chaque Z de
                  chaque guichet du produit, pour une ligne d'audit que personne ne lit. On le dit
                  ici, là où quelqu'un regarde déjà la régie. */}
              <RattachementGuichets regie={r} guichets={guichets} />
            </div>
          ))
        )}

        {edition && (
          <div className="cpt-form">
            <div className="fiche-sec">{edition.id ? 'Modifier la régie' : 'Déclarer une régie'}</div>

            <label className="field">
              <span className="field-lbl">Libellé</span>
              <input
                className="input"
                value={edition.libelle}
                onChange={(e) => setEdition({ ...edition, libelle: e.target.value })}
                placeholder="Régie de la piscine municipale"
              />
              <span className="hint">
                C’est ce nom qui apparaîtra dans le refus de clôture, si la régie dépasse son plafond.
              </span>
            </label>

            <label className="field">
              <span className="field-lbl">Plafond d’encaisse</span>
              <input
                className="input num"
                type="number"
                step="0.01"
                min="0"
                value={edition.plafond}
                onChange={(e) => setEdition({ ...edition, plafond: e.target.value })}
              />
              {/* LE SEUL CHAMP DE CET ÉCRAN QUI CHANGE CE QUE LE LOGICIEL ACCEPTE. */}
              <span className="hint">
                Le montant que le régisseur n’a pas le droit de détenir au-delà — il figure sur
                l’arrêté. <b>Au-dessus, la clôture comptable de la période est refusée</b> tant
                qu’un versement n’a pas fait redescendre l’encaisse.
                {plafondCentimes <= 0 && ' Il doit être strictement positif.'}
              </span>
            </label>

            <label className="field">
              <span className="field-lbl">Acte de nomination</span>
              <input
                className="input"
                value={edition.acteNomination}
                onChange={(e) => setEdition({ ...edition, acteNomination: e.target.value })}
                placeholder="Arrêté n° 2026-14 du 3 mars 2026"
              />
              <span className="hint">
                La référence de l’arrêté qui institue la régie et nomme le régisseur. Facultatif ici,
                obligatoire dans votre dossier.
              </span>
            </label>

            {moyens.length > 0 && (
              <div className="field">
                <span className="field-lbl">Moyens de paiement portés par l’acte</span>
                <div className="cpt-modes">
                  {moyens.map((m) => {
                    const code = m.code || ''
                    const coche = edition.modes.includes(code)
                    return (
                      <label key={m.id || code} className="cpt-mode">
                        <input
                          type="checkbox"
                          checked={coche}
                          onChange={(e) => setEdition({
                            ...edition,
                            modes: e.target.checked
                              ? [...edition.modes, code]
                              : edition.modes.filter((c) => c !== code),
                          })}
                        />
                        <span>{m.libelle || code}</span>
                      </label>
                    )
                  })}
                </div>
                {/* ⚠ ON NE PROMET PAS UN CONTRÔLE QUI N'EXISTE PAS. Rien, hors de cette fiche, ne
                    lit ce champ : la caisse n'oppose pas cette liste au caissier. */}
                <span className="hint">
                  Consigné pour votre dossier : c’est ce que l’arrêté autorise. <b>La caisse ne le
                  fait pas encore respecter</b> — cocher « espèces » seulement n’empêchera pas un
                  encaissement par carte.
                </span>
              </div>
            )}

            {!edition.id && profils.length > 1 && (
              <label className="field">
                <span className="field-lbl">Profil comptable</span>
                <select
                  className="select"
                  value={edition.profil}
                  onChange={(e) => setEdition({ ...edition, profil: e.target.value })}
                >
                  <option value="">— choisir —</option>
                  {profils.map((p) => (
                    <option key={p.id} value={p['@id'] || `/api/profil_exploitants/${p.id}`}>
                      {[p.referentielComptable, p.siren].filter(Boolean).join(' · ') || p.id}
                    </option>
                  ))}
                </select>
                <span className="hint">La régie appartient à un profil comptable, qui porte le plan de comptes.</span>
              </label>
            )}

            <div className="ticket-actions">
              <button type="button" className="btn primary" disabled={busy || !complet} onClick={enregistrer}>
                {busy ? 'Enregistrement…' : edition.id ? 'Enregistrer' : 'Déclarer la régie'}
              </button>
              <button type="button" className="btn ghost" onClick={() => setEdition(null)}>Annuler</button>
            </div>
          </div>
        )}
      </div>
    </section>
  )
}

// Les guichets qui alimentent une régie — ou l'absence, dite en clair.
function RattachementGuichets({ regie, guichets }) {
  if (guichets === null) {
    return (
      <div className="hint">
        Les points de vente n’ont pas pu être lus : <b>on ne peut pas dire</b> lesquels alimentent
        cette régie.
      </div>
    )
  }

  const iri = regie['@id'] || `/api/regie_recettes/${regie.id}`
  const rattaches = guichets.filter((g) => g.regie === iri)

  if (rattaches.length === 0) {
    return (
      <div className="hint">
        <b>Aucun guichet n’encaisse pour cette régie</b>, donc son encaisse restera à zéro et il n’y
        aura jamais rien à verser. Rattachez-la à un point de vente, juste au-dessus.
      </div>
    )
  }

  return (
    <div className="hint">
      Alimentée par {rattaches.map((g) => g.libelle || '—').join(', ')}.
    </div>
  )
}

function centimes(valeur) {
  const n = Number.parseFloat(String(valeur).replace(',', '.'))
  return Number.isFinite(n) ? Math.round(n * 100) : 0
}
