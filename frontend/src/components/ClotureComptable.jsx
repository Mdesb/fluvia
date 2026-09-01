import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { dateHeureFr } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

// La clôture comptable : générer, valider, vérifier la chaîne, clôturer, exporter.
//
// POURQUOI CE MODULE AVANT LES PLUS GROS ÉCARTS.
//
// `Musee` expose soixante-sept opérations pour deux appelées, `Compta` soixante-dix-sept pour sept.
// Mais un musée inaccessible se voit le matin même. **Un module comptable inaccessible se découvre à
// la clôture, ou au contrôle** — c'est le seul du produit où ne pas pouvoir agir a des conséquences
// légales. Avant cet écran, un exploitant ne pouvait ni valider une écriture, ni clôturer une
// période, ni sortir son FEC.
//
// CE QUE CET ÉCRAN MONTRE ET QUE PERSONNE NE VOYAIT : LES VENTES QUI NE PASSENT PAS.
//
// `generer` parcourt les ventes validées non comptabilisées. Celles dont le mapping comptable est
// incomplet sont **sautées en silence** (`continue`) et rapportées dans `anomalies` — un tableau que
// le serveur renvoie et que personne n'affichait. Autrement dit : des ventes réelles restent hors
// comptabilité, et rien ne le dit.
//
// C'est ce que la mesure de couverture ne sait pas voir — une opération peut être branchée pendant
// que le signal qu'elle porte reste muet. On affiche donc ces anomalies **avant** le nombre
// d'écritures générées : savoir que quarante écritures sont passées importe moins que savoir que
// trois ventes ne passeront jamais tant qu'on n'aura pas corrigé un compte.
//
// LA GÉNÉRATION EST IDEMPOTENTE, ET C'EST VÉRIFIÉ, PAS SUPPOSÉ.
//
// `ventesValideesNonComptabilisees` ne rend que ce qui n'a pas encore d'écriture : relancer ne
// double rien. L'écran peut donc proposer le geste sans avertissement anxiogène — mais il le dit,
// parce qu'un bouton dont on ignore s'il est rejouable ne se presse qu'une fois, dans le doute, et
// c'est ainsi qu'on laisse une journée non comptabilisée.
//
// LA CLÔTURE, ELLE, N'EST PAS RÉVERSIBLE. `StatutPeriode` ne connaît que `ouverte` et `cloturee`, et
// aucune opération ne rouvre. La confirmation le dit en toutes lettres : c'est un geste mensuel,
// et au moment de cliquer on ne se souvient jamais s'il est rattrapable.

export default function ClotureComptable({ etabActif, droits }) {
  // ⚠ `null` = PAS LU · `[]` = LU ET VIDE. Sur cet ecran, << tout est valide >> est une
  // affirmation comptable : elle dit qu'aucune ecriture n'attend, donc qu'on peut cloturer.
  const [profils, setProfils] = useState(null)
  const [periodes, setPeriodes] = useState(null)
  const [ecritures, setEcritures] = useState(null)
  const [journaux, setJournaux] = useState(null)
  const [exports, setExports] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [rapportGeneration, setRapportGeneration] = useState(null)
  const [rapportChaine, setRapportChaine] = useState(null)
  const [aCloturer, setACloturer] = useState(null)
  const [enCours, setEnCours] = useState(false)

  const peutValider = aLeDroit(droits, 'compta.valider')
  const peutCloturer = aLeDroit(droits, 'compta.cloturer')
  const peutExporter = aLeDroit(droits, 'compta.exporter')

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const [p, pe, e, j, ex] = await Promise.all([
        api.profilsExploitant(),
        api.periodesComptables(),
        api.ecrituresComptables(),
        api.journaux(),
        api.exportsComptables(),
      ])
      setProfils(membres(p))
      setPeriodes(membres(pe))
      setEcritures(membres(e))
      setJournaux(membres(j))
      setExports(membres(ex))
    } catch (err) {
      setErreur(err.message)
      setProfils(null)
      setEcritures(null)
      setPeriodes(null)
      setJournaux(null)
      setExports(null)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function generer() {
    setEnCours(true)
    setErreur(null)
    try {
      const rapport = await api.genererEcritures(profils[0]?.id)
      setRapportGeneration(rapport)
      await recharger()
    } catch (err) {
      setErreur(err.message || "La génération n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  async function surEcriture(ecriture, action, message) {
    setErreur(null)
    try {
      await action(ecriture.id)
      await recharger()
      setSucces(message)
    } catch (err) {
      setErreur(err.message || "L'opération n'a pas abouti.")
    }
  }

  async function cloturer(periode) {
    setErreur(null)
    try {
      await api.cloturerPeriode(periode.id)
      await recharger()
      setSucces('Période clôturée. Les montants arrêtés sont figés sur la ligne.')
    } catch (err) {
      // Le 409 nomme les points bloquants — écritures déséquilibrées, régie au-dessus du plafond
      // sans versement. Affiché tel quel : c'est le serveur qui sait lesquels, et le reformuler
      // ferait diverger le diagnostic de la réalité.
      setErreur(err.message || "La clôture n'a pas abouti.")
    }
  }

  async function verifierChaine(journal) {
    setErreur(null)
    setRapportChaine(null)
    try {
      setRapportChaine({ journal, ...(await api.verifierChaineEcritures(journal.id)) })
    } catch (err) {
      setErreur(err.message || "La vérification n'a pas abouti.")
    }
  }

  if (chargement) {
    return (
      <section className="card">
        <div className="card-b center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      </section>
    )
  }

  const aValider = (ecritures || []).filter((e) => e.statut === 'provisoire' || e.statut === 'controlee')
  const ouvertes = (periodes || []).filter((p) => p.statut !== 'cloturee')

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <section className="card">
        <div className="card-h">
          <h3>Comptabiliser les ventes</h3>
          <span className="sub">reprendre les ventes validées qui n'ont pas encore d'écriture</span>
          {peutValider && (
            <div className="r">
              <button
                className="btn primary sm"
                type="button"
                disabled={enCours || !profils?.length}
                title={!profils?.length ? "Aucun profil d'exploitant utilisable : soit aucun n'est configuré, soit la lecture a échoué." : undefined}
                onClick={generer}
              >
                {enCours ? 'Génération…' : 'Générer les écritures'}
              </button>
            </div>
          )}
        </div>
        <div className="card-b">
          {profils === null ? (
            <div className="banner banner-error">
              Les profils d’exploitant n’ont pas pu être lus. <b>N’en concluez pas qu’aucun n’est
              configuré</b> : la génération d’écritures est désactivée par prudence, pas par constat.
            </div>
          ) : profils.length === 0 ? (
            <div className="empty">
              Aucun profil d'exploitant n'est configuré. C'est lui qui porte le régime comptable et le
              plan de comptes : sans lui, aucune écriture ne peut être générée.
            </div>
          ) : !rapportGeneration ? (
            <div className="hint" style={{ marginTop: 0 }}>
              Le geste est rejouable sans risque : seules les ventes qui n'ont pas encore d'écriture
              sont reprises. Lancez-le avant chaque clôture.
            </div>
          ) : (
            <RapportGeneration rapport={rapportGeneration} />
          )}
        </div>
      </section>

      <section className="card" style={{ marginTop: 16 }}>
        <div className="card-h">
          <h3>Écritures à valider</h3>
          <span className="sub">
            {ecritures === null
              ? 'état inconnu — la lecture n’a pas abouti'
              : aValider.length === 0 ? 'tout est validé' : `${aValider.length} en attente`}
          </span>
        </div>
        <div className="card-b">
          {ecritures === null ? (
            <div className="banner banner-error">
              Les écritures n’ont pas pu être lues. <b>Ne concluez pas que tout est validé</b> avant
              de clôturer&nbsp;: cette liste n’a pas été obtenue.
            </div>
          ) : aValider.length === 0 ? (
            <div className="empty">
              Aucune écriture en attente. Une écriture générée reste provisoire jusqu'à sa validation :
              c'est la validation qui la scelle et l'inscrit dans la chaîne.
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Libellé</th>
                  <th>Journal</th>
                  <th>État</th>
                  {peutValider && <th />}
                </tr>
              </thead>
              <tbody>
                {aValider.map((e) => (
                  <tr key={e.id}>
                    <td>{jour(e.dateEcriture)}</td>
                    <td><span className="nm">{e.libelle || '—'}</span></td>
                    <td>{e.journal?.libelle || e.journal?.code || '—'}</td>
                    <td><span className="badge warn">{mot(e.statut)}</span></td>
                    {peutValider && (
                      <td className="num">
                        <button
                          className="btn primary sm"
                          type="button"
                          onClick={() => surEcriture(e, api.validerEcriture, 'Écriture validée et scellée.')}
                        >
                          Valider
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {peutValider && (ecritures || []).some((e) => e.statut === 'validee' || e.statut === 'exportee') && (
            <ExtourneSection
              ecritures={(ecritures || []).filter((e) => e.statut === 'validee' || e.statut === 'exportee')}
              onExtourner={(e) =>
                surEcriture(e, api.extournerEcriture, "Écriture extournée : la contre-écriture est datée d'aujourd'hui.")}
            />
          )}
        </div>
      </section>

      <section className="card" style={{ marginTop: 16 }}>
        <div className="card-h">
          <h3>Périodes</h3>
          <span className="sub">
            {periodes === null
              ? 'état inconnu — la lecture n’a pas abouti'
              : ouvertes.length === 0 ? 'aucune période ouverte' : `${ouvertes.length} ouverte${ouvertes.length > 1 ? 's' : ''}`}
          </span>
        </div>
        <div className="card-b">
          {periodes === null ? (
            <div className="banner banner-error">
              Les périodes comptables n’ont pas pu être lues. <b>N’en concluez pas qu’aucune n’est
              ouverte</b>&nbsp;: cette liste n’a pas été obtenue.
            </div>
          ) : periodes.length === 0 ? (
            <div className="empty">
              Aucune période comptable. Les périodes découpent l'exercice ; on les clôture une à une,
              et une période clôturée ne se rouvre pas.
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Du</th>
                  <th>Au</th>
                  <th>État</th>
                  {peutCloturer && <th />}
                </tr>
              </thead>
              <tbody>
                {(periodes || []).map((p) => (
                  <tr key={p.id}>
                    <td>{jour(p.dateDebut)}</td>
                    <td>{jour(p.dateFin)}</td>
                    <td>
                      <span className={`badge ${p.statut === 'cloturee' ? 'good' : 'info'}`}>{mot(p.statut)}</span>
                    </td>
                    {peutCloturer && (
                      <td className="num">
                        {p.statut !== 'cloturee' ? (
                          <button className="btn ghost sm" type="button" onClick={() => setACloturer(p)}>
                            Clôturer
                          </button>
                        ) : (
                          p.etatCloture && (
                            <span className="sub" title={JSON.stringify(p.etatCloture)}>arrêté figé</span>
                          )
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>

      <ClotureModal
        periode={aCloturer}
        onClose={() => setACloturer(null)}
        onConfirmer={(p) => { setACloturer(null); cloturer(p) }}
      />

      <ChaineSection
        journaux={journaux}
        rapport={rapportChaine}
        onVerifier={verifierChaine}
        onFermer={() => setRapportChaine(null)}
      />

      <ExportsSection
        exports={exports}
        profils={profils}
        peutExporter={peutExporter}
        onChange={recharger}
        onErreur={setErreur}
        onFait={(m) => { setSucces(m); recharger() }}
      />
    </>
  )
}

// La clôture d'une période — un arrêté, pas un changement de statut.
//
// CE QUE CET ÉCRAN NE PEUT PAS MONTRER, ET QUI DEVRAIT L'ÊTRE.
//
// La clôture fige un état chiffré : produits, TVA, encaissements, nombre d'écritures, horodatage.
// C'est ce que l'exploitant arrête, et il ne pourra plus le corriger. **Mais `etatCloture` n'est
// rempli qu'APRÈS la clôture** : il n'existe aucune opération de prévisualisation, donc aucun moyen
// d'afficher les montants avant le clic irréversible.
//
// On signe donc à l'aveugle. Ce n'est pas un choix d'écran, c'est un manque côté serveur — signalé —
// et cette fenêtre le dit plutôt que de laisser croire qu'un aperçu a été jugé inutile. Ce qu'elle
// peut faire, elle le fait : dire que c'est définitif, et rappeler le contrôle à passer avant.
function ClotureModal({ periode, onClose, onConfirmer }) {
  return (
    <Modal open={!!periode} onClose={onClose} titre="Clôturer une période comptable">
      {periode && (
        <>
          <p style={{ marginTop: 0 }}>
            Période du <b>{jour(periode.dateDebut)}</b> au <b>{jour(periode.dateFin)}</b>.
          </p>

          <div className="banner banner-error">
            <b>La clôture est définitive.</b> Aucune opération ne rouvre une période. Les écritures de
            cette période ne pourront plus être modifiées : toute correction passera par une extourne
            datée du jour où on la fera.
          </div>

          <div className="fiche-sec" style={{ marginTop: 0 }}>Avant de clôturer</div>
          <ul style={{ margin: '0 0 12px', paddingLeft: 18, fontSize: 13.5 }}>
            <li>
              Lancez « générer les écritures » : il vous dira si des ventes sont restées hors
              comptabilité. Une fois la période close, elles ne pourront plus y entrer.
            </li>
            <li>
              Vérifiez l'intégrité de la chaîne sur vos journaux : une anomalie découverte après
              l'arrêté est bien plus coûteuse à traiter.
            </li>
          </ul>

          <div className="hint" style={{ marginTop: 0 }}>
            La clôture arrête un état chiffré — produits, TVA, encaissements. <b>Le logiciel ne sait
            pas encore vous le montrer avant l'enregistrement</b> : il n'est calculé qu'au moment de
            la clôture. Si le serveur refuse, il vous dira précisément ce qui bloque.
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="button" onClick={() => onConfirmer(periode)}>
              Clôturer définitivement
            </button>
          </div>
        </>
      )}
    </Modal>
  )
}

// Les anomalies AVANT le compte : savoir que quarante écritures sont passées importe moins que
// savoir que trois ventes ne passeront jamais tant qu'un compte n'aura pas été corrigé.
function RapportGeneration({ rapport }) {
  const anomalies = rapport.anomalies || []
  return (
    <>
      {anomalies.length > 0 && (
        <div className="banner banner-error">
          <b>{anomalies.length} vente{anomalies.length > 1 ? 's n’ont' : " n'a"} pas pu être
          comptabilisée{anomalies.length > 1 ? 's' : ''}.</b> Elles restent hors comptabilité tant que
          la cause n'est pas corrigée — et rien d'autre dans le logiciel ne vous le dira.
        </div>
      )}

      <div className="hint" style={{ marginTop: 0 }}>
        {rapport.ecrituresGenerees || 0} écriture{(rapport.ecrituresGenerees || 0) > 1 ? 's' : ''} générée
        {(rapport.ecrituresGenerees || 0) > 1 ? 's' : ''}
        {rapport.extournesGenerees ? `, ${rapport.extournesGenerees} extourne(s)` : ''}.
      </div>

      {anomalies.length > 0 && (
        <table className="tbl">
          <thead>
            <tr><th>Vente</th><th>Ce qui bloque</th></tr>
          </thead>
          <tbody>
            {anomalies.map((a, i) => (
              <tr key={a.vente || i}>
                <td><span className="mono">{a.vente || '—'}</span></td>
                <td>
                  {(a.anomalies || []).map((x, j) => (
                    <div key={j}>{typeof x === 'string' ? x : JSON.stringify(x)}</div>
                  ))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  )
}

// L'extourne est repliée : c'est un geste rare et lourd — il ne s'affiche pas au même niveau que la
// validation, qu'on fait tous les jours.
function ExtourneSection({ ecritures, onExtourner }) {
  const [ouvert, setOuvert] = useState(false)
  const [choisie, setChoisie] = useState(null)

  return (
    <>
      <div style={{ marginTop: 14 }}>
        <button className="btn ghost sm" type="button" onClick={() => setOuvert((o) => !o)}>
          {ouvert ? 'Masquer' : 'Corriger une écriture déjà validée'}
        </button>
      </div>

      {ouvert && (
        <>
          <div className="banner banner-warn" style={{ marginTop: 10 }}>
            Une écriture validée ne se modifie pas : on l'annule par une <b>extourne</b>, qui est une
            contre-écriture datée d'aujourd'hui. Les deux restent visibles — c'est ce qui rend la
            comptabilité relisible.
          </div>
          <table className="tbl">
            <thead>
              <tr><th>Date</th><th>Libellé</th><th>État</th><th /></tr>
            </thead>
            <tbody>
              {(ecritures || []).map((e) => (
                <tr key={e.id}>
                  <td>{jour(e.dateEcriture)}</td>
                  <td>{e.libelle || '—'}</td>
                  <td><span className="badge good">{mot(e.statut)}</span></td>
                  <td className="num">
                    <button className="btn ghost sm" type="button" onClick={() => setChoisie(e)}>
                      Extourner
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      <Modal open={!!choisie} onClose={() => setChoisie(null)} titre="Extourner une écriture">
        {choisie && (
          <>
            <p style={{ marginTop: 0 }}>
              « {choisie.libelle || 'écriture'} » du {jour(choisie.dateEcriture)}.
            </p>
            <div className="banner banner-warn">
              L'écriture d'origine <b>reste</b>. Une contre-écriture de sens inverse est créée, datée
              d'aujourd'hui. Le solde revient à sa valeur d'avant, et l'historique montre les deux
              mouvements plutôt qu'un trou.
            </div>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
              <button className="btn" type="button" onClick={() => setChoisie(null)}>Annuler</button>
              <button
                className="btn primary"
                type="button"
                onClick={() => { const e = choisie; setChoisie(null); onExtourner(e) }}
              >
                Extourner
              </button>
            </div>
          </>
        )}
      </Modal>
    </>
  )
}

// La chaîne NF525 : un contrôle qu'on LANCE, pas un voyant permanent.
//
// Un badge vert affiché en continu finit par ne plus être lu, et c'est précisément celui qu'on
// voudrait voir rougir. On propose donc le contrôle, journal par journal, avec sa date de passage.
function ChaineSection({ journaux, rapport, onVerifier, onFermer }) {
  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Intégrité des écritures</h3>
        <span className="sub">contrôle de la chaîne NF525, journal par journal</span>
      </div>
      <div className="card-b">
        {journaux === null ? (
          <div className="banner banner-error">
            Les journaux comptables n’ont pas pu être lus.
          </div>
        ) : journaux.length === 0 ? (
          <div className="empty">Aucun journal comptable.</div>
        ) : (
          <>
            <div className="hint" style={{ marginTop: 0 }}>
              Chaque écriture validée est scellée et chaînée à la précédente. Ce contrôle recalcule la
              chaîne : il détecte un trou de séquence, un chaînage rompu ou une donnée altérée après
              coup. À lancer avant une clôture, et lors d'un contrôle.
            </div>
            {/* ⚠ LE VERBE UNE FOIS, PAS DIX. Chaque bouton portait « Vérifier « Journal des … » »
                — dix libellés de 43 caractères dont les trente premiers étaient identiques, et le
                mot qui distingue arrivait à la fin. Trouver un journal demandait de lire jusqu'au
                bout, dix fois.
                L'`aria-label` garde la phrase entière : au lecteur d'écran, cette amorce visuelle
                n'est pas le contexte du contrôle, et un bouton nommé « Journal des ventes » ne
                dirait plus ce qu'il fait. */}
            <div className="cc-verifier-amorce">Vérifier la chaîne de&nbsp;:</div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {(journaux || []).map((j) => {
                const nomJournal = j.libelle || j.code || 'journal'
                return (
                  <button
                    key={j.id}
                    className="btn"
                    type="button"
                    aria-label={`Vérifier la chaîne de « ${nomJournal} »`}
                    onClick={() => onVerifier(j)}
                  >
                    {nomJournal}
                  </button>
                )
              })}
            </div>
          </>
        )}

        {rapport && (
          <div style={{ marginTop: 14 }}>
            {rapport.intacte ? (
              <div className="banner banner-ok">
                Chaîne intacte sur « {rapport.journal?.libelle || rapport.journal?.code} » :{' '}
                {rapport.nbOperations} écriture{rapport.nbOperations > 1 ? 's' : ''} vérifiée
                {rapport.nbOperations > 1 ? 's' : ''}, aucune anomalie.
              </div>
            ) : (
              <>
                <div className="banner banner-error">
                  <b>{(rapport.anomalies || []).length} anomalie
                  {(rapport.anomalies || []).length > 1 ? 's' : ''} sur la chaîne de «{' '}
                  {rapport.journal?.libelle || rapport.journal?.code} ».</b> Une chaîne rompue signifie
                  qu'une écriture scellée a été modifiée, supprimée ou insérée après coup. Ce n'est pas
                  un incident d'affichage : conservez ce rapport et faites-le remonter.
                </div>
                <table className="tbl">
                  <thead>
                    <tr><th className="num">Écriture n°</th><th>Problème</th></tr>
                  </thead>
                  <tbody>
                    {(rapport.anomalies || []).map((a, i) => {
                      // Un trou de séquence désigne une écriture qui N'EXISTE PAS : il n'y a rien à
                      // retrouver, c'est précisément le problème. Le distinguer évite de promettre une
                      // ligne à consulter dans le seul cas où elle est introuvable.
                      const trou = String(a.probleme || '').startsWith('trou de séquence')
                      return (
                        <tr key={i}>
                          <td className="num">{a.sequence ?? '—'}</td>
                          <td>
                            {a.probleme || '—'}
                            <div className="sub">
                              {trou
                                ? 'Cette écriture est absente du journal : elle a été supprimée, ou n’a jamais été enregistrée. Il n’y a pas de ligne à consulter.'
                                : 'L’écriture existe et porte ce numéro dans ce journal : elle est consultable dans la liste des écritures.'}
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </>
            )}
            <div style={{ marginTop: 8 }}>
              <button className="btn ghost sm" type="button" onClick={onFermer}>Fermer le rapport</button>
            </div>
          </div>
        )}
      </div>
    </section>
  )
}

function ExportsSection({ exports, profils, peutExporter, onChange, onErreur, onFait }) {
  const [nouveau, setNouveau] = useState(false)

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Exports comptables</h3>
        <span className="sub">FEC et formats d'échange</span>
        {peutExporter && (profils?.length || 0) > 0 && (
          <div className="r">
            <button className="btn primary sm" type="button" onClick={() => setNouveau(true)}>
              ＋ Nouvel export
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {exports === null ? (
          <div className="banner banner-error">
            La liste des exports n’a pas pu être lue&nbsp;: <b>ne concluez pas qu’aucun FEC n’a été
            produit</b> pour cet exercice.
          </div>
        ) : exports.length === 0 ? (
          <div className="empty">
            Aucun export. Le FEC est le fichier que réclame l'administration en cas de contrôle : il se
            génère sur une période et se télécharge ici.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Format</th>
                <th>Période</th>
                <th>Généré le</th>
                {peutExporter && <th />}
              </tr>
            </thead>
            <tbody>
              {(exports || []).map((e) => (
                <tr key={e.id}>
                  <td><span className="badge mut">{String(e.format || '—').toUpperCase()}</span></td>
                  <td>{jour(e.periodeDebut)} → {jour(e.periodeFin)}</td>
                  <td>{e.dateGeneration ? dateHeureFr(e.dateGeneration) : '—'}</td>
                  {peutExporter && (
                    <td className="num">
                      <button
                        className="btn ghost sm"
                        type="button"
                        onClick={async () => {
                          try {
                            await api.telechargerExport(e.id)
                            onFait('Export récupéré.')
                          } catch (err) {
                            onErreur(err.message || 'Le téléchargement a échoué.')
                          }
                        }}
                      >
                        Récupérer
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <NouvelExportModal
        open={nouveau}
        profils={profils}
        onClose={() => setNouveau(false)}
        onFait={(m) => { setNouveau(false); onChange(); onFait(m) }}
        onErreur={onErreur}
      />
    </section>
  )
}

function NouvelExportModal({ open, profils, onClose, onFait, onErreur }) {
  const [debut, setDebut] = useState('')
  const [fin, setFin] = useState('')
  const [format, setFormat] = useState('fec')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (open) { setDebut(''); setFin(''); setFormat('fec') }
  }, [open])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.creerExportComptable({
        profilExploitant: `/api/profil_exploitants/${profils[0]?.id}`,
        format,
        periodeDebut: debut,
        periodeFin: fin,
      })
      onFait('Export généré.')
    } catch (err) {
      onErreur(err.message || "L'export n'a pas pu être généré.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Nouvel export comptable">
      <form onSubmit={envoyer}>
        <div className="field">
          <label htmlFor="ex-format">Format</label>
          <select id="ex-format" className="input" value={format} onChange={(e) => setFormat(e.target.value)}>
            <option value="fec">FEC — fichier des écritures comptables</option>
          </select>
          <div className="hint">
            Le FEC est le format normalisé que réclame l'administration fiscale en cas de contrôle.
          </div>
        </div>

        <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="ex-debut">Du *</label>
            <input id="ex-debut" className="input" type="date" required value={debut} onChange={(e) => setDebut(e.target.value)} />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="ex-fin">Au *</label>
            <input id="ex-fin" className="input" type="date" required value={fin} onChange={(e) => setFin(e.target.value)} />
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours || !debut || !fin}>
            {enCours ? 'Génération…' : "Générer l'export"}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function jour(v) {
  if (!v) return '—'
  const s = String(v).slice(0, 10)
  const [a, m, j] = s.split('-')
  return j ? `${j}/${m}/${a}` : s
}
