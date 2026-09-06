import { useCallback, useEffect, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import { dateHeureFr } from './Liste.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { centimes } from '../api/produit.js'
import { confirmer } from './Confirmation.jsx'

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
  // ⚠ `null` = PAS LU, `[]` = LU ET VIDE. Sur une lecture refusee, cet ecran affirmait deux fois
  // qu'il n'y avait aucun impaye — dans le compteur du bandeau de carte, et dans l'etat vide.
  // C'est la phrase qui fait arreter de chercher, sur le seul ecran ou une creance oubliee
  // vieillit toute seule.
  const [incidentsLu, setIncidentsLu] = useState(null)
  // ⚠ `null` NE SORT PAS D'ICI. Il dit « pas lu » et rien d'autre ; tout l'aval — y
  // compris ce qui part en prop vers un enfant — lit un tableau. Sans cette ligne il faut
  // trouver chaque usage, et un usage manque ne se signale que par un ecran mort.
  const incidents = incidentsLu || []
  const [totalIncidents, setTotalIncidents] = useState(null)
  const [bord, setBord] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [forcage, setForcage] = useState(null)

  const peutPiloter = aLeDroit(droits, 'recouvrement.piloter')
  const peutForcer = aLeDroit(droits, 'recouvrement.forcer_acces')
  const [exemptions, setExemptions] = useState([])
  const [exemption, setExemption] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    try {
      const reponse = await api.incidentsImpayes()
      setIncidentsLu(membres(reponse))
      // Le serveur pagine chaque collection (l'explication complète est dans
      // `components/Liste.jsx`). Ici la conséquence n'est pas seulement une liste courte : le
      // tableau des représentations retrouve le nom du redevable EN RECOUPANT cette liste. Au-delà
      // d'une page, des représentations perdent leur redevable sans que rien ne le dise.
      const total = reponse?.totalItems ?? reponse?.['hydra:totalItems']
      setTotalIncidents(typeof total === 'number' ? total : null)
    } catch (e) {
      setErreur(e.message)
      // On ne garde pas la liste precedente : elle donnerait les impayes d'hier pour ceux
      // d'aujourd'hui, ce qui est pire qu'une absence annoncee.
      setIncidentsLu(null)
      setTotalIncidents(null)
    } finally {
      setChargement(false)
    }
    // Le tableau de bord est un complément : son absence ne doit pas priver de la liste.
    api.tableauBordRecouvrement().then(setBord).catch(() => setBord(null))
    // Même raison pour les exemptions : sans elles la liste reste juste, on perd seulement la
    // pastille « exempté ». Une erreur ici ne doit pas masquer les impayés.
    api.exemptionsBlocage().then((r) => setExemptions(membres(r))).catch(() => setExemptions([]))
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  // ⚠ On compare le COUPLE, pas la seule référence. Deux verticales peuvent fabriquer la même
  // référence sans se concerter : le recouvrement identifie un redevable par son type ET sa
  // référence, et cet écran doit dire la même chose que le serveur.
  const actives = exemptions.filter((e) => !e.revokedAt)
  const estExempte = (incident) =>
    actives.some(
      (e) => e.debtorType === incident.typeRedevable && e.debtorRef === incident.referenceRedevable,
    )

  // ⚠ On affiche le nom si un impaye du meme redevable est charge, sinon la reference brute.
  // Inventer un libelle « client inconnu » ferait croire a une donnee manquante ; la reference est
  // laide mais vraie, et elle permet de retrouver la ligne.
  const nomRedevable = (type, reference) => {
    const connu = (incidents || []).find((i) => i.typeRedevable === type && i.referenceRedevable === reference)
    return connu?.nomRedevable || connu?.libelleRedevable || reference
  }

  async function retirerExemption(e) {
    if (
      !await confirmer(
        'Retirer cette exemption ?\n\nLe client redeviendra bloquable, et si un impayé reste dû '
          + 'son accès sera coupé immédiatement.',
      )
    ) {
      return
    }
    try {
      await api.retirerExemption(e.id)
      setSucces('Exemption retirée. Si un impayé reste dû, l’accès vient d’être coupé.')
      setErreur(null)
      recharger()
    } catch (err) {
      setErreur(err.message || "Le retrait n'a pas abouti.")
    }
  }

  async function resoudre(incident) {
    if (
      !await confirmer(
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

  const incidentsParId = useMemo(() => new Map(incidents.map((i) => [i.id, i])), [incidents])

  const ouverts = (incidents || []).filter((i) => i.statut !== 'resolu')
  // LE DENOMINATEUR EXACT, DEPUIS QUE LE SERVEUR EXPOSE `nbResolus` (main, bb1ff2e).
  //
  // Hier soir je ne pouvais trancher que le cas << rien nulle part >>, faute de connaitre le total :
  // je l'avais ecrit dans le code plutot que de maquiller l'affichage. Le champ manquant a ete pose
  // en reponse, et le taux se lit maintenant exactement -- avec son assiette, ce qui vaut mieux que
  // le seul pourcentage : << 0 % sur 3 incidents >> et << 0 % sur 500 >> ne se lisent pas pareil.
  //
  // ⚠ ON NE L'APPELLE PAS `totalIncidents` : ce nom designe deja, plus haut, le total de PAGINATION
  // de la liste (combien le serveur en a, pour signaler une liste tronquee). Deux totaux differents
  // sous le meme nom sur le meme ecran, c'est la collision de vocabulaire qui fait lire un chiffre
  // pour un autre -- la meme que << casse >> a la patinoire ou << caisse >> pour l'appairage.
  //
  // ⚠ ET LE CHAMP PEUT NE PAS ETRE SERVI. Mesure contre la preprod le 29/08 : `nbResolus` revient
  // ABSENT sur les quatre etablissements -- le champ est sur `main`, pas encore deploye. Un
  // `|| 0` aveugle ferait donc une assiette FAUSSE (trop basse) des qu'un incident existe, et
  // l'ecran l'annoncerait avec aplomb. On distingue les deux : denominateur connu, ou pas.
  const assietteConnue = bord != null && bord.nbResolus !== undefined && bord.nbResolus !== null
  const assietteDuTaux = assietteConnue
    ? (bord.nbEnRepresentation || 0) + (bord.nbEnRecouvrement || 0) + bord.nbResolus
    : null
  // Sans le champ, on retombe sur le seul cas qu'on sait trancher : rien nulle part.
  const listeLue = incidentsLu !== null
  // ⚠ « Rien a mesurer » est une CONCLUSION : elle exige d'avoir lu. Sans la liste, on ne peut pas
  // la tirer — meme quand le tableau de bord, lui, a repondu.
  const rienAMesurer = listeLue && (assietteConnue
    ? assietteDuTaux === 0
    : (incidents || []).length === 0 && !bord?.nbEnRepresentation && !bord?.nbEnRecouvrement && !bord?.nbAccesBloques)
  const resolus = (incidents || []).filter((i) => i.statut === 'resolu')

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
          {/* « 0 % » SUR ZÉRO INCIDENT SE LIT COMME UNE CONTRE-PERFORMANCE.
              Le serveur rend `0.0` quand le dénominateur est nul — la valeur mathématiquement sûre
              quand il n'y a rien à diviser. Affichée telle quelle, elle annonçait « nos clients ne
              régularisent jamais seuls » à un établissement qui n'a jamais eu d'impayé. Même famille
              que la jauge du musée qui criait la saturation sur une salle vide : une absence de
              mesure présentée comme un résultat.
              Le dénominateur est désormais reconstituable — `nbEnRepresentation + nbEnRecouvrement
              + nbResolus` — donc on ne devine plus : ou bien il y a des incidents et on dit le taux
              AVEC son assiette, ou bien il n'y en a pas et on dit qu'il n'y a rien à mesurer. */}
          <div className="stat-tile">
            <div className="st-val num">
              {rienAMesurer
                ? '—'
                : `${Math.round((bord.tauxResolutionSelfService || 0) * 100)} %`}
            </div>
            <div className="st-lbl">
              {!listeLue
                ? 'Taux non calculable : la liste des impayés n’a pas pu être lue'
                : rienAMesurer
                ? 'Rien à mesurer : aucun impayé'
                : assietteConnue
                  ? `Réglés par le client seul, sur ${assietteDuTaux} incident${assietteDuTaux > 1 ? 's' : ''}`
                  : 'Réglés par le client seul'}
            </div>
          </div>
        </div>
      )}

      <section className="card">
        <div className="card-h">
          <h3>Impayés en cours</h3>
          <span className="sub">
            {!listeLue
              ? 'liste non lue'
              : ouverts.length === 0 ? 'aucun impayé ouvert' : `${ouverts.length} à traiter`}
          </span>
        </div>
        <div className="card-b">
          {chargement ? (
            <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
          ) : ouverts.length === 0 ? (
            <>
            {/* CETTE PHRASE PROMETTAIT UNE CHAÎNE QUI N'EXISTE PAS, ET JE L'AI VÉRIFIÉ EN LA
                PARCOURANT. Elle disait « un prélèvement rejeté par la banque arrive ici ».
                Éprouvé le 29/08 avec l'accord de Maxime, sur un mandat de démonstration : rejet
                déclaré (`POST /api/rejet_sepas`, 201), puis mesuré — ZÉRO incident créé, tableau de
                bord inchangé, mandat toujours `actif`, aucun support bloqué. Le rejet n'a produit
                qu'une ligne dans le journal des rejets.
                La cause est lisible : `DeclarerRejetSepaProcessor` n'émet aucun événement et
                n'appelle pas `MoteurRecouvrementHandler`. Les deux seuls appelants de
                `detecterRejet()` sont la simulation d'échéance du module Sport et le résultat d'une
                représentation. Un rejet SEPA ordinaire ne rejoint donc jamais cet écran.
                On décrit ce qui remplit réellement cette liste. Signalé au serveur : c'est là que le
                chaînage manque, pas ici. */}
            <div className="empty">
              {!listeLue ? (
                <b>
                  La liste des impayés n’a pas pu être lue. Elle est vide parce que la lecture a
                  échoué, pas parce qu’aucun impayé n’est ouvert — ne concluez pas que tout est
                  réglé, et réessayez.
                </b>
              ) : (
                <>
              {/* ⚠ CETTE LISTE OMETTAIT LE REJET SEPA, ET LE NIAIT EN GRAS JUSTE EN DESSOUS.
                  « Un rejet SEPA déclaré depuis l'écran Prélèvements n'ouvre pas d'impayé [...]
                  Vérifié en le faisant » : c'était vrai le 29/08 à 03h50, et faux à 09h44, quand
                  `DeclarerRejetSepaProcessor` a été branché sur `detecterRejet()`. La vérification
                  était bonne ; c'est de l'avoir gravée ici qui l'a rendue fausse.

                  ⚠ « PEUT BLOQUER », PAS « BLOQUE ». Le blocage dépend du moment de refus choisi
                  dans la règle de recouvrement de l'établissement — et, plus loin, de l'existence
                  d'un port d'accès pour ce type de redevable. */}
              Aucun impayé en cours. Un impayé s’ouvre à partir d’un rejet SEPA déclaré depuis
              l’écran Prélèvements, d’une échéance d’abonnement rejetée, ou du résultat négatif
              d’une représentation bancaire. Il programme les représentations prévues par la règle
              de l’établissement, peut bloquer l’accès du redevable selon cette même règle, et se
              referme quand le paiement est régularisé.
                </>
              )}
              {resolus.length > 0 && (
                <div style={{ marginTop: 8 }}>
                  {resolus.length} impayé{resolus.length > 1 ? 's ont' : ' a'} été régularisé
                  {resolus.length > 1 ? 's' : ''}.
                </div>
              )}
            </div>
            </>
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
                          {/* Le geste se pose ICI, devant le client qui revient tous les mois —
                              c'est là qu'on s'aperçoit qu'on force le même depuis six mois. */}
                          {peutForcer && !estExempte(i) && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              title="Ce client ne sera plus jamais bloqué pour impayé. La dette reste due."
                              onClick={() => setExemption(i)}
                            >
                              Ne plus bloquer
                            </button>
                          )}
                          {estExempte(i) && <span className="badge">exempté</span>}
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

      {/* ⚠ LA LISTE DES EXEMPTIONS EST LA MOITIÉ QUI REND LE GESTE RÉVERSIBLE. Sans elle, « ne plus
          jamais bloquer » serait une porte à sens unique : posable d'un clic, retirable par
          personne. Le garde-fou d'écart l'a d'ailleurs dit avant moi — la fonction de retrait
          existait, aucun écran ne l'appelait. */}
      {actives.length > 0 && (
        <section className="card">
          <div className="card-h">
            <h3>Clients jamais bloqués</h3>
            <span className="sub">la dette reste due — seul le blocage d'accès est levé</span>
          </div>
          <div className="card-b">
            <table className="tbl">
              <thead>
                <tr>
                  <th>Redevable</th>
                  <th>Motif</th>
                  <th>Depuis</th>
                  {peutForcer && <th className="num">Action</th>}
                </tr>
              </thead>
              <tbody>
                {actives.map((e) => (
                  <tr key={e.id}>
                    <td>{nomRedevable(e.debtorType, e.debtorRef)}</td>
                    <td>{e.reason}</td>
                    <td>{dateHeureFr(e.grantedAt)}</td>
                    {peutForcer && (
                      <td className="num">
                        <button
                          className="btn ghost sm"
                          type="button"
                          title="Le client redeviendra bloquable ; si un impayé reste dû, son accès sera coupé."
                          onClick={() => retirerExemption(e)}
                        >
                          Retirer
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="hint">
              Une exemption n'a pas de date de fin : elle dure jusqu'à ce que quelqu'un la retire.
              C'est ce qui la distingue d'une réouverture forcée, qui ne vaut que pour un impayé.
            </div>
          </div>
        </section>
      )}


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

      <Representations
        peutPiloter={peutPiloter}
        incidentsParId={incidentsParId}
        listeIncidentsPartielle={totalIncidents !== null && (incidents || []).length < totalIncidents}
        onErreur={setErreur}
      />

      <Politique droits={droits} etabActif={etabActif} onSucces={setSucces} />

      <ExemptionModal
        incident={exemption}
        onClose={() => setExemption(null)}
        onFait={(m) => { setExemption(null); setSucces(m); setErreur(null); recharger() }}
        onErreur={(m) => setErreur(m)}
      />

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
function Representations({ peutPiloter, incidentsParId, listeIncidentsPartielle, onErreur }) {
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
        {/* Le nom du redevable est retrouvé en recoupant la liste des impayés. Si le serveur a
            coupé cette liste, certaines lignes garderont un tiret — et un tiret ici se lirait
            comme « pas de redevable » plutôt que comme « je n'ai pas pu le retrouver ». */}
        {listeIncidentsPartielle && (
          <div className="banner banner-warn">
            La liste des impayés a été tronquée par le serveur : le redevable de certaines
            représentations ne peut pas être retrouvé et reste affiché « — ».
          </div>
        )}
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
                <td>{redevableDe(r, incidentsParId)}</td>
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
  const [politiquesLu, setPolitiquesLu] = useState(null)
  // ⚠ `null` = PAS LU. Il ne sort pas d'ici : tout l'aval lit un tableau.
  const politiques = politiquesLu || []
  const [edition, setEdition] = useState(null)

  const peutParametrer = aLeDroit(droits, 'recouvrement.parametrer')

  const recharger = useCallback(() => {
    let annule = false
    api.politiquesRecouvrement()
      .then((p) => { if (!annule) setPolitiquesLu(membres(p)) })
      // ⚠ `[]` faisait dire a l'ecran un REGLEMENT — « une representation a J+5, refus d'acces
      // apres une representation echouee » — qui n'est peut-etre pas celui de cet etablissement.
      // Une absence deguisee en reponse est pire qu'un tableau vide : elle ne se signale pas.
      .catch(() => { if (!annule) setPolitiquesLu(null) })
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
            {politiquesLu === null ? (
              <b>
                La règle de recouvrement n’a pas pu être lue. N’en concluez pas que les valeurs par
                défaut s’appliquent : cet établissement en a peut-être une à lui, et elle décide
                quand un accès se ferme.
              </b>
            ) : (<>
            Aucune règle propre à cet établissement — les valeurs par défaut s&rsquo;appliquent :
            une représentation à J+5, et refus d&rsquo;accès après une représentation échouée.
            </>)}
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

/**
 * « Ne plus jamais bloquer ce client » — l'exemption durable (D84).
 *
 * ⚠ ELLE N'EFFACE PAS LA DETTE, et c'est ce que cet écran doit dire avant tout le reste. Le libellé
 * du bouton se lit spontanément comme « passer l'éponge » ; l'impayé reste pourtant ouvert, le
 * dossier reste dû et la relance continue. Ce qui est exempté est la conséquence sur la PORTE.
 *
 * ⚠ ELLE N'A PAS DE DATE DE FIN, ET LE DIRE FAIT PARTIE DU GESTE. Maxime a écarté l'expiration
 * obligatoire : « une exemption qui expire un lundi matin bloque un client à la porte sans que
 * personne n'ait rien décidé ce jour-là ». Elle dure donc jusqu'à ce qu'on la retire à la main —
 * l'écrire ici évite qu'on la pose en croyant qu'elle s'éteindra seule.
 */
function ExemptionModal({ incident, onClose, onFait, onErreur }) {
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (incident) setMotif('')
  }, [incident])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.exempterRedevable(incident.typeRedevable, incident.referenceRedevable, motif.trim())
      onFait("Ce client ne sera plus bloqué pour impayé. La dette reste due et le recouvrement continue.")
    } catch (err) {
      onErreur(err.message || "L'exemption n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!incident} onClose={onClose} titre="Ne plus jamais bloquer ce client">
      {incident && (
        <form onSubmit={envoyer}>
          <div className="banner banner-warn">
            <b>La dette reste due.</b> Vous levez seulement le blocage d'accès, pour cet impayé
            <b> et pour ceux à venir</b>. Les impayés continueront d'apparaître dans cette liste et
            le recouvrement suit son cours.
          </div>

          <p>
            Sans date de fin : l'exemption dure jusqu'à ce que quelqu'un la retire. C'est le
            comportement voulu — une exemption qui expire toute seule bloquerait un client un matin
            sans que personne ne l'ait décidé ce jour-là.
          </p>

          <div className="field">
            <label htmlFor="ex-motif">Pourquoi ce client ne doit-il jamais être bloqué ? *</label>
            <textarea
              id="ex-motif"
              className="input"
              rows={3}
              value={motif}
              onChange={(ev) => setMotif(ev.target.value)}
              placeholder="Ex. : collectivité payant à 45 jours — convention 2026."
            />
            <div className="hint">
              Ce motif est la seule trace de la décision : c'est lui qu'on lira dans six mois pour
              comprendre pourquoi ce client ne bloque jamais.
            </div>
          </div>

          <div className="modal-actions">
            <button className="btn ghost" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || motif.trim() === ''}>
              {enCours ? 'En cours…' : 'Ne plus bloquer ce client'}
            </button>
          </div>
        </form>
      )}
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
// LA COLONNE « REDEVABLE » DES REPRÉSENTATIONS ÉTAIT VIDE DEPUIS TOUJOURS, ET PERSONNE NE L'AVAIT VU.
//
// Le code lisait `r.incident?.referenceRedevable`. Or `IncidentImpaye` ne déclare AUCUNE propriété
// dans le groupe `representation:read` — vérifié dans l'entité, pas supposé : le champ `incident`
// d'une représentation revient donc en IRI nue, et l'expression valait `undefined` sur chaque ligne.
// La colonne affichait un tiret partout.
//
// Ce n'est pas cosmétique. Le commentaire au-dessus de ce tableau explique sa raison d'être : « un
// agent qui reçoit l'appel d'un abonné bloqué n'a que deux réponses utiles — ce sera représenté le 5,
// ou il faut régler maintenant ». Sans le nom, il ne peut pas savoir QUELLE ligne est celle de son
// interlocuteur : le tableau ne répond plus à la seule question pour laquelle il existe.
//
// Même défaut que `ligne.mandat` dans l'écran SEPA, trouvé le même jour. Deux relations, deux
// écrans, une seule cause : une expression optionnelle sur une relation non embarquée ne lève pas,
// elle rend `undefined` — et `undefined` s'affiche comme une donnée manquante, pas comme un bug.
function redevableDe(representation, incidentsParId) {
  const ref = representation.incident
  if (!ref) return '—'
  // On accepte les deux formes : si quelqu'un ajoute un jour `representation:read` aux propriétés
  // de l'incident, cet écran s'en servira sans qu'on ait à y revenir.
  const incident = typeof ref === 'object'
    ? ref
    : incidentsParId.get(String(ref).split('/').pop())
  if (!incident) return '—'
  return incident.referenceRedevable || incident.typeRedevable || '—'
}

