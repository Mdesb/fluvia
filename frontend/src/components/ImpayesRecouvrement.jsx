import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { dateHeureFr } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'

// Les impayés, et les deux gestes qui les closent.
//
// DERRIÈRE CHAQUE LIGNE, QUELQU'UN NE PEUT PAS ENTRER.
//
// Un impayé bloque l'accès du redevable. Le tableau de bord du serveur compte d'ailleurs
// `nbAccesBloques` — et personne ne l'affichait. Ce n'est donc pas une liste de créances : c'est une
// liste de gens à qui on a fermé la porte, et qui se présenteront au guichet sans comprendre.
//
// C'est pour ça que le compteur d'accès bloqués est en premier, avant le nombre d'incidents. Les
// deux chiffres décrivent la même réalité ; un seul dit ce qu'elle coûte.
//
// LA LISTE ÉTAIT AFFICHÉE ET NE POUVAIT PAS DESCENDRE (D55).
//
// `resoudre` et `forcer-reouverture` existaient depuis le début, et l'onglet « Impayés » de l'écran
// comptable ne montrait qu'un tableau. On regardait des accès bloqués sans pouvoir les rouvrir.
//
// DEUX GESTES QUI N'ONT RIEN À VOIR, ET L'ÉCRAN NE LES PRÉSENTE PAS PAREIL.
//
// **Résoudre** constate que l'argent est rentré : l'accès se rouvre parce que la dette n'existe plus.
// C'est le geste normal, il ne demande rien d'autre qu'un clic.
//
// **Forcer la réouverture** rouvre l'accès alors que la dette est toujours là. C'est une décision
// commerciale ou humaine — un abonné de longue date, une erreur bancaire en cours de correction — et
// elle exige un droit distinct (`recouvrement.forcer_acces`) et un motif écrit. Le motif n'est pas
// une formalité : il explique à celui qui relira pourquoi on a laissé entrer quelqu'un qui devait de
// l'argent.
//
// Les présenter côte à côte comme deux boutons équivalents ferait choisir le plus rapide.

export default function ImpayesRecouvrement({ etabActif, droits }) {
  const [incidents, setIncidents] = useState([])
  const [bord, setBord] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [forcage, setForcage] = useState(null)

  const peutPiloter = aLeDroit(droits, 'recouvrement.piloter')
  const peutForcer = aLeDroit(droits, 'recouvrement.forcer_acces')

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      setIncidents(membres(await api.incidentsImpayes()))
    } catch (e) {
      setErreur(e.message)
    } finally {
      setChargement(false)
    }
    // Le tableau de bord est un complément : son absence ne doit pas priver de la liste.
    api.tableauBordRecouvrement().then(setBord).catch(() => setBord(null))
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function resoudre(incident) {
    if (
      !window.confirm(
        "Marquer cet impayé comme réglé ?\n\nL'accès du redevable est rouvert immédiatement. "
          + "Ne le faites que si l'encaissement est confirmé : si le paiement échoue à son tour, "
          + "l'accès aura été rendu pour rien.",
      )
    )
      return
    setErreur(null)
    try {
      await api.resoudreImpaye(incident.id)
      await recharger()
      setSucces("Impayé réglé, l'accès est rouvert.")
    } catch (e) {
      setErreur(e.message || "La résolution n'a pas abouti.")
    }
  }

  const ouverts = incidents.filter((i) => i.statut !== 'resolu')
  const resolus = incidents.filter((i) => i.statut === 'resolu')

  return (
    <>
      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {bord && (bord.nbAccesBloques > 0 || ouverts.length > 0) && (
        <div className="banner banner-warn">
          <b>{bord.nbAccesBloques} accès bloqué{bord.nbAccesBloques > 1 ? 's' : ''}.</b> Derrière
          chaque ligne ci-dessous, quelqu'un ne peut plus entrer et se présentera au guichet sans
          savoir pourquoi. {bord.nbEnRepresentation} en attente de représentation bancaire,{' '}
          {bord.nbEnRecouvrement} en recouvrement.
        </div>
      )}

      {/* LE QUATRIÈME CHIFFRE DU TABLEAU DE BORD, QUE PERSONNE N'AFFICHAIT (28/08).
          `tauxResolutionSelfService` était calculé par le serveur à chaque appel et jeté par le
          front — repéré par `scripts/mesurer-signaux-muets.mjs`, pas à l'œil.
          Ce n'est pas un indicateur de confort : c'est la part des impayés que le client a réglés
          SEUL depuis l'application. Chaque point gagné est un appel au standard et un passage au
          guichet en moins, sur des gens qui arrivent fâchés d'être bloqués. Un exploitant qui ne le
          voit pas ne saura jamais que le règlement en ligne mérite d'être mieux mis en avant. */}
      {bord && (
        <div className="fiche-stats" style={{ marginBottom: 16 }}>
          <div className="stat-tile">
            <div className="st-val num">{bord.nbAccesBloques}</div>
            <div className="st-lbl">Accès bloqués</div>
          </div>
          <div className="stat-tile">
            <div className="st-val num">{bord.nbEnRepresentation}</div>
            <div className="st-lbl">En représentation</div>
          </div>
          <div className="stat-tile">
            <div className="st-val num">{bord.nbEnRecouvrement}</div>
            <div className="st-lbl">En recouvrement</div>
          </div>
          <div className="stat-tile">
            <div className="st-val num">
              {Math.round((bord.tauxResolutionSelfService || 0) * 100)} %
            </div>
            <div className="st-lbl">Réglés par le client seul</div>
          </div>
        </div>
      )}

      <section className="card">
        <div className="card-h">
          <h3>Impayés en cours</h3>
          <span className="sub">
            {ouverts.length === 0 ? 'aucun impayé ouvert' : `${ouverts.length} à traiter`}
          </span>
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : ouverts.length === 0 ? (
            <div className="empty">
              Aucun impayé en cours. Un prélèvement rejeté par la banque arrive ici, bloque l'accès du
              redevable, et en repart quand le paiement est régularisé.
              {resolus.length > 0 && (
                <div style={{ marginTop: 8 }}>
                  {resolus.length} impayé{resolus.length > 1 ? 's ont' : ' a'} été régularisé
                  {resolus.length > 1 ? 's' : ''}.
                </div>
              )}
            </div>
          ) : (
            <table className="tbl">
              <thead>
                <tr>
                  <th>Redevable</th>
                  <th className="num">Montant</th>
                  <th>Motif bancaire</th>
                  <th>Rejeté le</th>
                  <th>État</th>
                  {peutPiloter && <th />}
                </tr>
              </thead>
              <tbody>
                {ouverts.map((i) => (
                  <tr key={i.id}>
                    <td>
                      <span className="nm">{i.typeRedevable || '—'}</span>
                      <div className="sub mono">{String(i.referenceRedevable || '').slice(0, 12)}</div>
                    </td>
                    <td className="num">{centimes(i.montantCentimes)}</td>
                    <td>
                      {i.libelleMotifBancaire || i.motifBancaire || '—'}
                      {i.motifBancaire && i.libelleMotifBancaire && (
                        <div className="sub mono">{i.motifBancaire}</div>
                      )}
                    </td>
                    <td>{dateHeureFr(i.dateRejet)}</td>
                    <td><span className="badge warn">{mot(i.statut)}</span></td>
                    {peutPiloter && (
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          <button className="btn primary sm" type="button" onClick={() => resoudre(i)}>
                            Réglé
                          </button>
                          {peutForcer && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              title="Rouvrir l'accès sans que la dette soit payée."
                              onClick={() => setForcage(i)}
                            >
                              Rouvrir quand même
                            </button>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {!chargement && ouverts.length > 0 && (
            <div className="hint">
              « Réglé » constate que l'argent est rentré : la dette disparaît et l'accès suit.
              {peutForcer
                ? ' « Rouvrir quand même » laisse la dette en place et rend seulement l’accès — c’est une décision, pas un constat, et elle demande un motif écrit.'
                : ' Rouvrir un accès sans que la dette soit payée demande un droit distinct que votre profil n’a pas.'}
            </div>
          )}
        </div>
      </section>

      {resolus.length > 0 && (
        <section className="card" style={{ marginTop: 16 }}>
          <div className="card-h">
            <h3>Impayés régularisés</h3>
            <span className="sub">pour mémoire — plus rien à faire dessus</span>
          </div>
          <div className="card-b">
            <table className="tbl">
              <thead>
                <tr>
                  <th>Redevable</th>
                  <th className="num">Montant</th>
                  <th>Régularisé le</th>
                  <th>Par quel canal</th>
                </tr>
              </thead>
              <tbody>
                {resolus.map((i) => (
                  <tr key={i.id}>
                    <td>{i.typeRedevable || '—'}</td>
                    <td className="num">{centimes(i.montantCentimes)}</td>
                    <td>{i.dateResolution ? dateHeureFr(i.dateResolution) : '—'}</td>
                    <td>
                      {i.canalResolution ? mot(i.canalResolution) : '—'}
                      {i.motifReouvertureForcee && (
                        <div className="sub">Réouverture forcée — « {i.motifReouvertureForcee} »</div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <Representations peutPiloter={peutPiloter} onErreur={setErreur} />

      <Politique droits={droits} etabActif={etabActif} onSucces={setSucces} />

      <ForcageModal
        incident={forcage}
        onClose={() => setForcage(null)}
        onFait={(m) => { setForcage(null); setSucces(m); setErreur(null); recharger() }}
        onErreur={setErreur}
      />
    </>
  )
}

// LE CALENDRIER BANCAIRE, PARCE QUE << QUAND >> EST LA PREMIERE QUESTION DU REDEVABLE.
//
// L'écran savait dire qu'un accès était bloqué. Il ne savait pas dire quand la banque réessaierait —
// alors que `RepresentationSepa` porte la date programmée depuis le début, et que l'opération pour en
// enregistrer le résultat existait sans appelant.
//
// Un agent qui reçoit l'appel d'un abonné bloqué n'a que deux réponses utiles : « ce sera représenté
// le 5 » ou « il faut régler maintenant ». Sans cette liste, il n'avait ni l'une ni l'autre.
function Representations({ peutPiloter, onErreur }) {
  const [lignes, setLignes] = useState([])
  const [chargement, setChargement] = useState(true)
  const [enCours, setEnCours] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      setLignes(membres(await api.representationsRecouvrement()))
    } catch {
      // Une représentation absente n'empêche pas de traiter les impayés : l'écran principal reste
      // utilisable, et on ne remonte pas une erreur qui ferait croire que la liste du dessus est fausse.
      setLignes([])
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => { recharger() }, [recharger])

  async function enregistrer(id, resultat) {
    setEnCours(true)
    try {
      await api.enregistrerResultatRepresentation(id, resultat)
      await recharger()
    } catch (e) {
      onErreur(e.message || "Le résultat n'a pas pu être enregistré.")
    } finally {
      setEnCours(false)
    }
  }

  if (chargement || lignes.length === 0) return null

  const attendues = lignes.filter((r) => r.resultat === 'en_attente')

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Représentations bancaires</h3>
        <span className="sub">
          {attendues.length === 0
            ? 'aucune en attente'
            : `${attendues.length} programmée${attendues.length > 1 ? 's' : ''}`}
        </span>
      </div>
      <div className="card-b">
        <table className="tbl">
          <thead>
            <tr>
              <th>Redevable</th>
              <th>Programmée le</th>
              <th>Exécutée le</th>
              <th>Résultat</th>
              {peutPiloter && <th />}
            </tr>
          </thead>
          <tbody>
            {lignes.map((r) => (
              <tr key={r.id}>
                <td>{r.incident?.referenceRedevable || r.incident?.typeRedevable || '—'}</td>
                <td>{r.dateProgrammee ? dateHeureFr(r.dateProgrammee) : '—'}</td>
                <td>{r.dateExecution ? dateHeureFr(r.dateExecution) : <span className="sub">—</span>}</td>
                <td>
                  <span className={`badge ${r.resultat === 'reussie' ? 'good' : r.resultat === 'echouee' ? 'crit' : 'mut'}`}>
                    {mot(r.resultat)}
                  </span>
                </td>
                {peutPiloter && (
                  <td>
                    {/* Le résultat ne se saisit que sur une représentation encore en attente : le
                        rejouer sur une ligne déjà tranchée réécrirait un fait bancaire constaté. */}
                    {r.resultat === 'en_attente' && (
                      <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                        <button className="btn primary sm" type="button" disabled={enCours} onClick={() => enregistrer(r.id, 'reussie')}>
                          Réussie
                        </button>
                        <button className="btn sm" type="button" disabled={enCours} onClick={() => enregistrer(r.id, 'echouee')}>
                          Échouée
                        </button>
                      </div>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

// LA REGLE QUI A COUPE L'ACCES, ECRITE LA OU ON CONSTATE SES EFFETS.
//
// `PolitiqueRecouvrement` decide combien de fois la banque represente, a quel rythme, et A QUEL
// MOMENT L'ACCES EST REFUSE. C'est donc elle qui explique chaque ligne bloquee du tableau ci-dessus.
//
// Elle etait modifiable par l'API et invisible de l'ecran : on voyait la consequence sans jamais la
// cause. Un exploitant qui trouve le blocage trop brutal n'avait aucun moyen de savoir que le
// reglage existait -- il concluait que le logiciel etait comme ca.
//
// ET ELLE ÉTAIT LISIBLE SANS ÊTRE MODIFIABLE (D-28/08).
//
// `POST` et `PATCH /api/politique_recouvrements` existaient depuis le début avec leur processor
// dédié, et aucun écran ne les appelait. L'exploitant voyait donc la règle qui coupe l'accès de ses
// abonnés, la trouvait trop brutale, et n'avait aucun moyen d'en changer : il en concluait que le
// logiciel était comme ça. Montrer un réglage sans donner le bouton est pire que ne rien montrer.
function Politique({ droits, etabActif, onSucces }) {
  const [politiques, setPolitiques] = useState([])
  const [edition, setEdition] = useState(null)

  const peutParametrer = aLeDroit(droits, 'recouvrement.parametrer')

  const recharger = useCallback(() => {
    let annule = false
    api.politiquesRecouvrement()
      .then((p) => { if (!annule) setPolitiques(membres(p)) })
      .catch(() => { if (!annule) setPolitiques([]) })
    return () => { annule = true }
  }, [])

  useEffect(() => recharger(), [recharger])

  const MOMENTS = {
    apres_1er_echec: 'dès le premier échec',
    apres_representation_echouee: 'après une représentation échouée',
    apres_n_representations_echouees: 'après N représentations échouées',
  }

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Règle appliquée</h3>
        <span className="sub">ce qui décide du blocage</span>
        {peutParametrer && (
          <div className="r" style={{ marginLeft: 'auto' }}>
            <button
              className="btn sm"
              type="button"
              onClick={() => setEdition(politiques[0] || {})}
            >
              {politiques.length > 0 ? 'Modifier la règle' : 'Définir une règle'}
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {politiques.length === 0 ? (
          // Une politique absente n'est pas une absence de regle : ce sont les valeurs par defaut de
          // l'entite qui s'appliquent. Les taire laisserait croire que rien ne coupe l'acces.
          <div className="empty">
            Aucune règle propre à cet établissement — les valeurs par défaut s&rsquo;appliquent :
            une représentation à J+5, et refus d&rsquo;accès après une représentation échouée.
          </div>
        ) : (
          politiques.map((p) => (
            <div className="deflist" key={p.id}>
              <div><span>Représentations maximum</span><span className="num">{p.nbRepresentationsMax}</span></div>
              <div>
                <span>Calendrier</span>
                <span className="num">
                  {(p.calendrierRepresentationJours || []).map((j) => `J+${j}`).join(' · ') || '—'}
                </span>
              </div>
              <div>
                <span>Refus d&rsquo;accès</span>
                <span className="num">{MOMENTS[p.momentRefusAcces] || p.momentRefusAcces}</span>
              </div>
              {p.nReprAvantBlocage != null && (
                <div><span>Représentations avant blocage</span><span className="num">{p.nReprAvantBlocage}</span></div>
              )}
              <div>
                <span>Suspension du contrat</span>
                <span className="num">
                  {p.delaiAvantSuspensionContratJours != null
                    ? `après ${p.delaiAvantSuspensionContratJours} j`
                    : 'jamais'}
                </span>
              </div>
            </div>
          ))
        )}
      </div>

      <PolitiqueModal
        politique={edition}
        etabActif={etabActif}
        onClose={() => setEdition(null)}
        onFait={(m) => { setEdition(null); onSucces(m); recharger() }}
      />
    </section>
  )
}

// LA MODALE QUI RÈGLE LA DURETÉ DU RECOUVREMENT.
//
// Les cinq champs ne sont pas cinq préférences : ce sont trois décisions commerciales et deux
// paramètres bancaires, et l'écran les présente dans cet ordre parce que c'est celui de la question
// que l'exploitant se pose — « à partir de quand je ferme la porte ? » avant « combien de fois la
// banque réessaie ? ».
function PolitiqueModal({ politique, etabActif, onClose, onFait }) {
  const edition = politique && politique.id
  const [nbRepresentationsMax, setNbMax] = useState(1)
  const [calendrier, setCalendrier] = useState('5')
  const [momentRefusAcces, setMoment] = useState('apres_representation_echouee')
  const [nReprAvantBlocage, setNRepr] = useState('')
  const [delaiSuspension, setDelai] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!politique) return
    setNbMax(politique.nbRepresentationsMax ?? 1)
    setCalendrier((politique.calendrierRepresentationJours || [5]).join(', '))
    setMoment(politique.momentRefusAcces || 'apres_representation_echouee')
    setNRepr(politique.nReprAvantBlocage != null ? String(politique.nReprAvantBlocage) : '')
    setDelai(
      politique.delaiAvantSuspensionContratJours != null
        ? String(politique.delaiAvantSuspensionContratJours)
        : '',
    )
    setErreur(null)
  }, [politique])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    setErreur(null)
    try {
      // LE CALENDRIER EST SAISI EN TEXTE ET ENVOYÉ EN TABLEAU D'ENTIERS.
      //
      // « 5, 12 » est ce qu'un humain écrit ; `[5, 12]` est ce que la colonne JSON attend. Envoyer
      // les chaînes telles quelles passerait la validation d'API Platform sans broncher et écrirait
      // `["5", "12"]` en base — que le moteur de recouvrement comparerait à des entiers, donc
      // jamais. Une règle silencieusement inapplicable, et aucune erreur nulle part.
      const jours = calendrier
        .split(/[,;\s]+/)
        .map((j) => parseInt(j, 10))
        .filter((j) => Number.isInteger(j) && j >= 0)
      if (jours.length === 0) throw new Error('Indiquez au moins un délai de représentation, en jours.')

      const corps = {
        nbRepresentationsMax: Number(nbRepresentationsMax),
        calendrierRepresentationJours: jours,
        momentRefusAcces,
        nReprAvantBlocage:
          momentRefusAcces === 'apres_n_representations_echouees' && nReprAvantBlocage !== ''
            ? Number(nReprAvantBlocage)
            : null,
        delaiAvantSuspensionContratJours: delaiSuspension !== '' ? Number(delaiSuspension) : null,
      }

      if (edition) {
        await api.majPolitiqueRecouvrement(politique.id, corps)
      } else {
        await api.creerPolitiqueRecouvrement({
          ...corps,
          etablissement: `/api/etablissements/${etabActif}`,
        })
      }
      onFait('Règle de recouvrement enregistrée. Elle vaut pour les incidents à venir.')
    } catch (err) {
      setErreur(err.message || "La règle n'a pas pu être enregistrée.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal
      open={!!politique}
      onClose={onClose}
      titre={edition ? 'Modifier la règle de recouvrement' : 'Définir la règle de recouvrement'}
    >
      <form onSubmit={envoyer}>
        {erreur && <div className="banner banner-error">{erreur}</div>}

        <div className="banner banner-warn">
          <b>Cette règle ferme des portes.</b> Elle décide à quel moment un abonné qui doit de
          l&rsquo;argent cesse de pouvoir entrer. La durcir se voit tout de suite au guichet ; la
          desserrer ne rouvre pas les accès déjà bloqués.
        </div>

        <div className="field">
          <label htmlFor="pr-moment">Refuser l&rsquo;accès…</label>
          <select
            id="pr-moment"
            className="input"
            value={momentRefusAcces}
            onChange={(e) => setMoment(e.target.value)}
          >
            <option value="apres_1er_echec">dès le premier échec de prélèvement</option>
            <option value="apres_representation_echouee">après une représentation échouée</option>
            <option value="apres_n_representations_echouees">après N représentations échouées</option>
          </select>
          <div className="hint">
            « Dès le premier échec » bloque un abonné dont la banque a simplement refusé un
            prélèvement — parfois pour une erreur de leur côté. Les deux autres laissent à la banque
            le temps de réessayer avant de fermer la porte.
          </div>
        </div>

        {momentRefusAcces === 'apres_n_representations_echouees' && (
          <div className="field">
            <label htmlFor="pr-nrepr">Nombre de représentations échouées avant blocage</label>
            <input
              id="pr-nrepr"
              className="input"
              type="number"
              min="1"
              value={nReprAvantBlocage}
              onChange={(e) => setNRepr(e.target.value)}
            />
          </div>
        )}

        <div className="field">
          <label htmlFor="pr-nbmax">Représentations bancaires maximum</label>
          <input
            id="pr-nbmax"
            className="input"
            type="number"
            min="0"
            value={nbRepresentationsMax}
            onChange={(e) => setNbMax(e.target.value)}
          />
          <div className="hint">
            Combien de fois on redemande à la banque de prélever après un rejet. Chaque
            représentation peut être facturée par la banque, au créancier comme au débiteur.
          </div>
        </div>

        <div className="field">
          <label htmlFor="pr-calendrier">Calendrier des représentations (jours)</label>
          <input
            id="pr-calendrier"
            className="input mono"
            placeholder="5, 12"
            value={calendrier}
            onChange={(e) => setCalendrier(e.target.value)}
          />
          <div className="hint">
            En jours après le rejet, séparés par des virgules. « 5, 12 » représente une première fois
            à J+5, une seconde à J+12.
          </div>
        </div>

        <div className="field">
          <label htmlFor="pr-suspension">Suspendre le contrat après (jours)</label>
          <input
            id="pr-suspension"
            className="input"
            type="number"
            min="0"
            placeholder="jamais"
            value={delaiSuspension}
            onChange={(e) => setDelai(e.target.value)}
          />
          <div className="hint">
            Laisser vide pour ne jamais suspendre. Suspendre un contrat va plus loin que bloquer un
            accès : l&rsquo;abonnement lui-même s&rsquo;arrête.
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours}>
            {enCours ? 'Enregistrement…' : 'Enregistrer la règle'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function ForcageModal({ incident, onClose, onFait, onErreur }) {
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (incident) setMotif('')
  }, [incident])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.forcerReouvertureImpaye(incident.id, motif.trim())
      onFait("Accès rouvert. La dette reste due et l'impayé reste ouvert.")
    } catch (err) {
      onErreur(err.message || "La réouverture n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!incident} onClose={onClose} titre="Rouvrir un accès sans paiement">
      {incident && (
        <form onSubmit={envoyer}>
          <div className="banner banner-warn">
            <b>La dette reste due.</b> Vous rendez seulement l'accès. L'impayé restera dans la liste
            des impayés en cours, et le recouvrement continue.
          </div>

          <p style={{ marginTop: 0 }}>
            Impayé de <b>{centimes(incident.montantCentimes)}</b>, rejeté le{' '}
            {dateHeureFr(incident.dateRejet)}
            {incident.libelleMotifBancaire ? ` — ${incident.libelleMotifBancaire}` : ''}.
          </p>

          <div className="field">
            <label htmlFor="fr-motif">Pourquoi rouvrez-vous cet accès ? *</label>
            <textarea
              id="fr-motif"
              className="input"
              rows={3}
              required
              value={motif}
              placeholder="Erreur bancaire confirmée par le client, nouveau prélèvement présenté le 3."
              onChange={(e) => setMotif(e.target.value)}
            />
            <div className="hint">
              Obligatoire, et enregistré à votre nom. C'est ce que lira la personne qui se demandera,
              dans six mois, pourquoi quelqu'un qui devait de l'argent pouvait entrer.
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !motif.trim()}>
              {enCours ? 'Réouverture…' : "Rouvrir l'accès"}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// Les montants d'impayé sont en centimes entiers, pas en décimal : les passer à `euros` les
// diviserait par cent de travers.
function centimes(v) {
  const n = Number(v)
  if (!Number.isFinite(n)) return '—'
  return `${(n / 100).toFixed(2).replace('.', ',')} €`
}
