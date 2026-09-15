import { useCallback, useEffect, useMemo, useState } from 'react'
import Liste, { dateHeureFr } from '../components/Liste.jsx'
import Modal from '../components/Modal.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { mot } from '../api/vocabulaire.js'
import { euros } from '../api/produit.js'
import { confirmer } from '../components/Confirmation.jsx'
import { useVocabulaireVerticales } from '../api/vocabulaire-verticales.js'
import { useEtatUrl } from '../api/url.js'

// Patinoire — l'écran de guichet, et non plus la vitrine en lecture seule.
//
// L'ÉCRAN EST ORGANISÉ AUTOUR DU PARC, PAS DE LA LISTE DES LOCATIONS.
//
// La question qu'on pose cent fois par jour à un guichet de patinoire est « vous avez du 38 ? ». Une
// liste des locations en cours n'y répond pas : elle dit ce qui est sorti, pas ce qui reste. Le parc
// par pointure y répond d'un coup d'œil, et c'est de lui qu'on part pour louer.
//
// UNE POINTURE ÉPUISÉE N'EST PAS UNE ERREUR, C'EST UN EMBRANCHEMENT.
//
// Le serveur refuse la sortie d'une pointure épuisée par un 409 dont le message dit, en toutes
// lettres, quoi faire ensuite : proposer une pointure voisine, ou inscrire en liste d'attente. C'est
// le métier même de la patinoire, et il ne doit pas s'apprendre en lisant un message d'erreur. On
// propose donc les deux issues **avant** le refus, en cliquant sur une pointure épuisée.
//
// Le 409 reste traité malgré tout : deux agents peuvent louer la dernière paire en même temps, et
// c'est précisément le cas où l'écran ne peut rien savoir d'avance.
//
// LE SÉLECTEUR DE BÉNÉFICIAIRE A FAILLI ÊTRE UN CONTOURNEMENT.
//
// À la première version, `/api/beneficiaires` ne publiait du client rattaché que son identifiant :
// seul `id` de `Client` portait le groupe `beneficiaire:read`. Un sélecteur bâti dessus n'aurait
// affiché que des UUID. Je recoupais donc avec `/api/clients` pour retrouver les noms — ce qui
// marchait, et ne couvrait que les cent premiers clients : au-delà, l'agent lisait
// « Bénéficiaire 3f2a91c4 » et ne pouvait pas louer.
//
// `claude-A` a ajouté les deux groupes manquants. Le recoupement est retiré : une limite invisible
// qui survit à sa propre correction est pire que pas de limite du tout, parce que plus personne ne
// la cherche.

// `retour` : la location dont on enregistre le retour ; `bareme` : `nouvelle` ou l'identifiant d'une
// ligne de barème ; `pointure` : `nouvelle` quand on en déclare une.
const DEFAUTS_URL = { retour: '', bareme: '', pointure: '' }

// ⚠ UNE CONSTANTE DE MODULE : l'effet du formulaire de barème recharge ses champs à chaque nouvel
// objet `grille`. Un `{}` écrit en ligne effacerait la saisie à chaque rendu.
const GRILLE_NOUVELLE = Object.freeze({})

export default function Patinoire({ etabActif, droits, envoiCourriel = false }) {
  // ⚠ `null` = PAS LU · `[]` = LU ET VIDE.
  //
  // Sur un refus, ces trois listes restaient a `[]` et l'ecran annoncait << Aucune paire n'est
  // sortie >>, << Personne n'attend >> et << Aucune pointure n'est enregistree >>. La premiere est
  // la plus couteuse : on la lit pour savoir si tout le materiel est rentre avant de fermer.
  const [parc, setParc] = useState(null)
  const [locations, setLocations] = useState(null)
  const [attente, setAttente] = useState(null)
  const [retenues, setRetenues] = useState([])
  // ⚠ `null` = PAS LU. << Aucun bareme. Sans lui, chaque retenue est un montant decide au
  // guichet >> annonce une consequence : on facture une retenue a la main, sur la foi d'un
  // bareme qu'on n'a pas pu lire.
  const [grilles, setGrilles] = useState(null)
  const [beneficiaires, setBeneficiaires] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [params, majParams] = useEtatUrl('patinoire', DEFAUTS_URL)

  const peutLouer = aLeDroit(droits, 'patinoire.gerer_location')
  const peutAffuter = aLeDroit(droits, 'patinoire.gerer_affutage')
  const peutAttente = aLeDroit(droits, 'patinoire.gerer_liste_attente')
  const peutForcer = aLeDroit(droits, 'patinoire.forcer_retenue')
  const peutConfigurer = aLeDroit(droits, 'patinoire.configurer')

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [p, l, a, r, g] = await Promise.all([
        api.patinoireParc(),
        api.patinoireLocations(),
        api.patinoireListeAttente(),
        api.patinoireRetenues(),
        api.patinoireGrillesRetenue().catch(() => null),
      ])
      setParc(
        membres(p)
          .filter((x) => x.actif !== false)
          .sort((x, y) => (x.pointure || 0) - (y.pointure || 0)),
      )
      setLocations(membres(l))
      setAttente(membres(a))
      setRetenues(membres(r))
      setGrilles(g ? membres(g) : null)
    } catch (e) {
      setErreur(e.message)
      setParc(null)
      setLocations(null)
      setAttente(null)
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  // ⚠ LA LOCATION RENDUE SE LIT PAR SON IDENTIFIANT : la liste est bornée à 100. Seul un 404 dit
  // « elle n'existe pas » ; tout le reste est une lecture qui a échoué.
  const [locationRendue, setLocationRendue] = useState(null)
  const [chargementLocation, setChargementLocation] = useState(false)
  const [lectureLocationEchouee, setLectureLocationEchouee] = useState(false)
  useEffect(() => {
    const id = params.retour
    if (!id) { setLocationRendue(null); setLectureLocationEchouee(false); return undefined }
    let vivant = true
    setChargementLocation(true)
    setLectureLocationEchouee(false)
    setLocationRendue(null)
    api.patinoireLocation(id)
      .then((l) => { if (vivant) setLocationRendue(l) })
      .catch((e) => { if (vivant) setLectureLocationEchouee(e?.status !== 404) })
      .finally(() => { if (vivant) setChargementLocation(false) })
    return () => { vivant = false }
  }, [params.retour, etabActif])

  // La liste ne sert qu'à ceux qui peuvent louer : la charger pour les autres serait un appel de plus
  // à chaque ouverture de l'écran, pour un menu qu'ils ne verront jamais.
  useEffect(() => {
    if (!peutLouer) return
    api
      .beneficiaires()
      .then((b) => setBeneficiaires(membres(b)))
      .catch(() => setBeneficiaires([]))
  }, [peutLouer, etabActif])

  // Le repli sur l'identifiant court reste : une fiche client sans nom ni prénom est possible, et un
  // menu déroulant qui contient une ligne vide ne se choisit pas.
  const nommer = useCallback((b) => {
    const c = b?.client
    const nom = [c?.prenom, c?.nom].filter(Boolean).join(' ').trim()
    return nom || `Bénéficiaire ${String(b?.id || '').slice(0, 8)}`
  }, [])

  // ⚠ LE MÊME COMPTAGE QUE `ParcSection` — `en_attente` ou `proposee` —, parce qu'une personne
  // « proposée » attend toujours : on lui a réservé la paire, on ne l'a pas prévenue. Il vivait dans
  // LocationsSection, qui ne s'en servait que pour la modale de retour ; l'écran de retour est posé
  // ici, le compte monte avec lui.
  const enAttenteParParc = useMemo(() => {
    const c = {}
    for (const l of attente || []) {
      if (l.statut !== 'en_attente' && l.statut !== 'proposee') continue
      const id = l.parcPatins?.id || String(l.parcPatins || '').split('/').pop()
      c[id] = (c[id] || 0) + 1
    }
    return c
  }, [attente])

  // La ligne de barème ouverte : la constante en création, la ligne de la liste en modification.
  const grilleEditee = useMemo(() => {
    if (!params.bareme) return null
    if (params.bareme === 'nouvelle') return GRILLE_NOUVELLE
    return (grilles || []).find((g) => String(g.id) === String(params.bareme)) || null
  }, [params.bareme, grilles])

  const apres = useCallback(
    async (message) => {
      setSucces(message)
      setErreur(null)
      await recharger()
    },
    [recharger],
  )

  // ── RETOUR, BARÈME, POINTURE : LES ÉCRANS PRENNENT LA PAGE ─────────────────────────────────────
  //
  // ⚠ L'ADRESSE CONTOURNE LES CONDITIONS DES BOUTONS ET CHAQUE ÉCRAN LES REPREND. Et chaque formulaire
  // décide sur une liste de la page : illisible, on refuse plutôt que de laisser passer un doublon de
  // pointure — qui ne se supprime pas — ou de modifier un barème qu'on n'a pas lu.
  if (params.retour || params.bareme || params.pointure) {
    const fermerEcran = () => majParams({ retour: '', bareme: '', pointure: '' }, { pousser: true })
    let contenu
    if (params.retour) {
      if (!peutLouer) {
        contenu = <div className="banner banner-warn">Enregistrer un retour de patins demande le droit de gérer les locations, que ce compte n’a pas.</div>
      } else if (chargementLocation) {
        contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
      } else if (!locationRendue) {
        contenu = (
          <div className="banner banner-warn">
            {lectureLocationEchouee
              ? 'Cette location n’a pas pu être lue. Ce n’est pas la même chose que « elle n’existe pas » : réessayez avant d’en conclure quoi que ce soit.'
              : 'Cette location n’existe pas, ou n’est pas visible depuis cet établissement.'}
          </div>
        )
      } else if (locationRendue.statut !== 'en_cours') {
        contenu = (
          <div className="banner banner-warn">
            Cette paire n’est plus sortie (« {mot(locationRendue.statut)} ») : il n’y a pas de retour à enregistrer.
          </div>
        )
      } else {
        contenu = (
          <>
            {erreur && <div className="banner banner-error">{erreur}</div>}
            <RetourModal
              key={params.retour}
              location={locationRendue}
              enAttenteParParc={enAttenteParParc}
              envoiCourriel={envoiCourriel}
              onClose={fermerEcran}
              onFait={(m) => { fermerEcran(); apres(m) }}
              onErreur={setErreur}
            />
          </>
        )
      }
    } else if (params.bareme) {
      if (!peutConfigurer) {
        contenu = <div className="banner banner-warn">Modifier le barème de retenue demande le droit de configurer la patinoire, que ce compte n’a pas.</div>
      } else if (chargement) {
        contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
      } else if (grilles === null) {
        contenu = (
          <div className="banner banner-error">
            Le barème n’a pas pu être lu : on ne modifie pas une ligne qu’on n’a pas lue, et on n’en
            ajoute pas une sans voir celles qui existent.
          </div>
        )
      } else if (!grilleEditee) {
        contenu = <div className="banner banner-warn">Cette ligne de barème n’existe pas, ou n’est pas visible depuis cet établissement.</div>
      } else {
        contenu = (
          <>
            {erreur && <div className="banner banner-error">{erreur}</div>}
            <BaremeModal
              key={params.bareme}
              grille={grilleEditee}
              parc={parc}
              etabActif={etabActif}
              onClose={fermerEcran}
              onFait={(m) => { fermerEcran(); apres(m) }}
              onErreur={setErreur}
            />
          </>
        )
      }
    } else if (!peutConfigurer) {
      contenu = <div className="banner banner-warn">Déclarer une {mot('pointure').toLowerCase()} demande le droit de configurer la patinoire, que ce compte n’a pas.</div>
    } else if (chargement) {
      contenu = <div className="center" style={{ minHeight: 'var(--esp-section)' }}><div className="spinner" /></div>
    } else if (parc === null) {
      contenu = (
        <div className="banner banner-error">
          Le parc n’a pas pu être lu : cet écran ne pourrait pas vérifier que la {mot('pointure').toLowerCase()} n’est
          pas déjà déclarée — et une {mot('pointure').toLowerCase()} déclarée ne se supprime pas.
        </div>
      )
    } else {
      contenu = <ParcPatinsModal open parc={parc} onClose={fermerEcran} onFait={() => { fermerEcran(); apres() }} onErreur={setErreur} />
    }
    return (
      <div className="view large">
        <button className="btn ghost sm" type="button" onClick={fermerEcran}
          style={{ marginBottom: 'var(--esp-large)' }}>
          ← Retour à la patinoire
        </button>
        {contenu}
      </div>
    )
  }

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Patinoire</h1>
          <p>Location de patins, affûtage, liste d'attente</p>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
      ) : (
        <>
          <ParcSection
            parc={parc}
            attente={attente}
            beneficiaires={beneficiaires}
            nommer={nommer}
            peutLouer={peutLouer}
            peutAttente={peutAttente}
            peutConfigurer={peutConfigurer}
            onFait={apres}
            onErreur={setErreur}
            majParams={majParams}
          />

          <div className="resa-grid" style={{ marginTop: 16 }}>
            <LocationsSection
              locations={locations}
              attente={attente}
              envoiCourriel={envoiCourriel}
              nommer={nommer}
              peutLouer={peutLouer}
              onFait={apres}
              onErreur={setErreur}
              majParams={majParams}
            />
            <ListeAttenteSection
              attente={attente}
              nommer={nommer}
              peutAttente={peutAttente}
              onFait={apres}
              onErreur={setErreur}
            />
          </div>

          <RetenuesSection
            retenues={retenues}
            peutValider={peutLouer || peutForcer}
            peutForcer={peutForcer}
            onFait={apres}
            onErreur={setErreur}
          />

          <BaremeSection
            grilles={grilles}
            parc={parc}
            peutConfigurer={peutConfigurer}
            etabActif={etabActif}
            onFait={apres}
            onErreur={setErreur}
            majParams={majParams}
          />

          <AffutagesSection
            parc={parc}
            peutAffuter={peutAffuter}
            etabActif={etabActif}
            onFait={apres}
            onErreur={setErreur}
          />
        </>
      )}

      <div style={{ marginTop: 16 }}>
        <ConflitsGlace etabActif={etabActif} />
      </div>
    </div>
  )
}

// --------------------------------------------------------------------------------------------
// Le parc : une tuile par pointure, et la sortie part d'ici.
// --------------------------------------------------------------------------------------------
function ParcSection({ parc, attente, beneficiaires, nommer, peutLouer, peutAttente, peutConfigurer, onFait, onErreur, majParams }) {
  const [sortie, setSortie] = useState(null)
  const [indispo, setIndispo] = useState(null)

  const enAttenteParParc = useMemo(() => {
    const c = {}
    for (const l of attente || []) {
      if (l.statut !== 'en_attente' && l.statut !== 'proposee') continue
      const id = l.parcPatins?.id || String(l.parcPatins || '').split('/').pop()
      c[id] = (c[id] || 0) + 1
    }
    return c
  }, [attente])

  // Les pointures voisines réellement disponibles, les plus proches d'abord. C'est ce que le serveur
  // calcule pour son message de refus ; le calculer ici permet de le proposer avant le refus.
  function voisines(ligne) {
    return (parc || [])
      .filter((p) => p.id !== ligne.id && (p.quantiteDisponible || 0) > 0)
      .map((p) => ({ ...p, ecart: Math.abs((p.pointure || 0) - (ligne.pointure || 0)) }))
      .sort((a, b) => a.ecart - b.ecart)
      .slice(0, 3)
  }

  function cliquer(ligne) {
    if (!peutLouer) return
    if ((ligne.quantiteDisponible || 0) > 0) setSortie({ ligne })
    else setIndispo({ ligne, voisines: voisines(ligne) })
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>Parc de patins</h3>
        <span className="sub">ce qui est louable, {mot('pointure').toLowerCase()} par {mot('pointure').toLowerCase()}</span>
        {peutConfigurer && (
          <div className="actions" style={{ marginLeft: 'auto' }}>
            <button className="btn sm" type="button" onClick={() => majParams({ pointure: 'nouvelle' }, { pousser: true })}>
              ＋ Déclarer une {mot('pointure').toLowerCase()}
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {parc === null ? (
          <div className="banner banner-error">
            Le parc de patins n’a pas pu être lu. Ce cadre est vide parce que la lecture a échoué,
            <b> pas</b> parce qu’aucune pointure n’est enregistrée.
          </div>
        ) : parc.length === 0 ? (
          <div className="empty">
            {/* LA PHRASE ENVOYAIT « DANS LE PARAMÉTRAGE », OÙ IL N'Y A RIEN DE TEL.
                Les six onglets de Paramètres ne portent ni pointure, ni terrain, ni salle. Une
                absence laisse chercher ; une fausse piste fait chercher au mauvais endroit, puis
                conclure qu'on n'a pas compris son propre logiciel. C'est pire que le silence. */}
            Aucune pointure n'est enregistrée pour cet établissement. Tant que le parc est vide,
            aucune paire ne peut être louée.{peutConfigurer ? ' Déclarez-en une avec le bouton ci-dessus.' : ''}
          </div>
        ) : (
          <>
            {peutLouer && (
              <div className="hint" style={{ marginTop: 0, marginBottom: 10 }}>
                Cliquez une pointure pour sortir une paire. Une pointure épuisée propose les tailles
                voisines et l'inscription en liste d'attente.
              </div>
            )}
            <div className="pat-parc">
              {parc.map((p) => {
                const dispo = p.quantiteDisponible || 0
                const enAttente = enAttenteParParc[p.id] || 0
                return (
                  <button
                    key={p.id}
                    type="button"
                    className={`pat-tuile${dispo > 0 ? '' : ' vide'}`}
                    disabled={!peutLouer}
                    onClick={() => cliquer(p)}
                    title={
                      dispo > 0
                        ? `${dispo} paire(s) disponible(s) sur ${p.quantiteTotale || 0}`
                        : 'Épuisée : voir les pointures voisines ou inscrire en liste d’attente'
                    }
                  >
                    <span className="pat-pt">{p.pointure}</span>
                    <span className="pat-dispo">{dispo}</span>
                    <span className="pat-detail">
                      {p.quantiteSortie || 0} sortie{(p.quantiteSortie || 0) > 1 ? 's' : ''}
                      {(p.quantiteEnAffutage || 0) > 0 && ` · ${p.quantiteEnAffutage} affût.`}
                      {(p.quantiteHS || 0) > 0 && ` · ${p.quantiteHS} HS`}
                    </span>
                    {enAttente > 0 && <span className="badge warn pat-att">{enAttente} en attente</span>}
                  </button>
                )
              })}
            </div>
          </>
        )}
      </div>

      <SortieModal
        etat={sortie}
        beneficiaires={beneficiaires}
        nommer={nommer}
        onClose={() => setSortie(null)}
        onFait={(m) => { setSortie(null); onFait(m) }}
        onErreur={onErreur}
        onIndisponible={(ligne) => { setSortie(null); setIndispo({ ligne, voisines: voisines(ligne) }) }}
      />

      <IndisponibleModal
        etat={indispo}
        beneficiaires={beneficiaires}
        nommer={nommer}
        peutAttente={peutAttente}
        onClose={() => setIndispo(null)}
        onChoisirVoisine={(p) => { setIndispo(null); setSortie({ ligne: p }) }}
        onFait={(m) => { setIndispo(null); onFait(m) }}
        onErreur={onErreur}
      />
    </section>
  )
}

// Sortir une paire. La caution est pré-remplie au montant que le serveur applique par défaut : un
// champ vide ferait croire qu'aucune caution n'est prise, alors qu'il en prend une.
function SortieModal({ etat, beneficiaires, nommer, onClose, onFait, onErreur, onIndisponible }) {
  // Vocabulaire patinoire (#100, lot 3) : t('deposit', 'patinoire', …) rend « Caution patins ».
  const { t } = useVocabulaireVerticales()
  const [beneficiaire, setBeneficiaire] = useState('')
  const [caution, setCaution] = useState('15.00')
  const [moyen, setMoyen] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (etat) { setBeneficiaire(''); setCaution('15.00'); setMoyen('') }
  }, [etat])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.patinoireSortirPatins({
        parcPatins: etat.ligne.id,
        beneficiaire,
        caution,
        ...(moyen ? { moyenEncaissement: moyen } : {}),
      })
      onFait(`Pointure ${etat.ligne.pointure} sortie. Caution de ${euros(caution)} encaissée.`)
    } catch (err) {
      // 409 : la dernière paire est partie entre l'affichage et le clic. L'écran ne pouvait pas le
      // savoir — on bascule sur les mêmes issues que pour une pointure déjà épuisée.
      if (err.status === 409) onIndisponible(etat.ligne)
      else onErreur(err.message || "La sortie n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!etat} onClose={onClose} titre={etat ? `Sortir une paire — pointure ${etat.ligne.pointure}` : ''}>
      {etat && (
        <form onSubmit={envoyer}>
          <div className="field">
            <label htmlFor="pat-benef">Bénéficiaire *</label>
            <select id="pat-benef" className="input" required value={beneficiaire} onChange={(e) => setBeneficiaire(e.target.value)}>
              <option value="">Choisir…</option>
              {beneficiaires.map((b) => (
                <option key={b.id} value={b.id}>{nommer(b)}</option>
              ))}
            </select>
            <div className="hint">
              {beneficiaires.length === 0
                ? "Aucun bénéficiaire n'est enregistré : créez d'abord la fiche client, la location y sera rattachée."
                : 'La paire est rattachée à cette personne : c’est elle qu’on rappellera si les patins ne reviennent pas.'}
            </div>
          </div>

          <div className="field">
            <label htmlFor="pat-caution">{t('deposit', 'patinoire', 'Caution')} encaissée</label>
            <input id="pat-caution" className="input" type="number" step="0.01" min="0" value={caution} onChange={(e) => setCaution(e.target.value)} />
            <div className="hint">15,00 € par défaut. Consignée à la sortie, rendue au retour sauf retenue.</div>
          </div>

          <div className="field">
            <label htmlFor="pat-moyen">Moyen d'encaissement</label>
            <input id="pat-moyen" className="input" value={moyen} placeholder="espèces, carte, chèque…" onChange={(e) => setMoyen(e.target.value)} />
            <div className="hint">Facultatif : sert à retrouver comment rendre la caution.</div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || !beneficiaire}>
              {enCours ? 'Sortie…' : 'Sortir la paire'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// Pointure épuisée : les deux issues, dans l'ordre où on les tente au guichet.
function IndisponibleModal({ etat, beneficiaires, nommer, peutAttente, onClose, onChoisirVoisine, onFait, onErreur }) {
  const [beneficiaire, setBeneficiaire] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (etat) setBeneficiaire('')
  }, [etat])

  async function inscrire() {
    setEnCours(true)
    try {
      await api.patinoireInscrireListeAttente({ parcPatins: etat.ligne.id, beneficiaire })
      onFait(`Inscrit en liste d'attente pour la pointure ${etat.ligne.pointure}.`)
    } catch (err) {
      onErreur(err.message || "L'inscription n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!etat} onClose={onClose} titre={etat ? `Pointure ${etat.ligne.pointure} épuisée` : ''}>
      {etat && (
        <>
          <p style={{ marginTop: 0 }}>
            Toutes les paires de cette pointure sont sorties, en affûtage ou hors service. Deux issues,
            dans l'ordre où on les tente au guichet.
          </p>

          <div className="fiche-sec">1. Proposer une pointure voisine</div>
          {etat.voisines.length === 0 ? (
            <div className="empty" style={{ marginBottom: 12 }}>
              Aucune autre pointure n'est disponible en ce moment.
            </div>
          ) : (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
              {etat.voisines.map((p) => (
                <button key={p.id} className="btn" type="button" onClick={() => onChoisirVoisine(p)}>
                  Pointure {p.pointure} · {p.quantiteDisponible} dispo
                </button>
              ))}
            </div>
          )}

          <div className="fiche-sec">2. Inscrire en liste d'attente</div>
          {!peutAttente ? (
            <div className="hint" style={{ marginTop: 0 }}>Votre profil ne gère pas la liste d'attente.</div>
          ) : (
            <>
              <div className="field">
                <label htmlFor="pat-att-benef">Bénéficiaire</label>
                <select id="pat-att-benef" className="input" value={beneficiaire} onChange={(e) => setBeneficiaire(e.target.value)}>
                  <option value="">Choisir…</option>
                  {beneficiaires.map((b) => (
                    <option key={b.id} value={b.id}>{nommer(b)}</option>
                  ))}
                </select>
                <div className="hint">La personne est rappelée dans l'ordre d'inscription dès qu'une paire revient.</div>
              </div>
              <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                <button className="btn" type="button" onClick={onClose}>Fermer</button>
                <button className="btn primary" type="button" disabled={!beneficiaire || enCours} onClick={inscrire}>
                  {enCours ? 'Inscription…' : 'Inscrire en liste d’attente'}
                </button>
              </div>
            </>
          )}
        </>
      )}
    </Modal>
  )
}

// --------------------------------------------------------------------------------------------
// Les locations en cours, et le retour.
// --------------------------------------------------------------------------------------------
function LocationsSection({ locations, envoiCourriel = false, nommer, peutLouer, majParams }) {
  const enCours = (locations || []).filter((l) => l.statut === 'en_cours')

  return (
    <section className="card">
      <div className="card-h">
        <h3>Paires sorties</h3>
        <span className="sub">{locations === null ? '—' : `${enCours.length} en circulation`}</span>
      </div>
      <div className="card-b">
        {locations === null ? (
          // ⚠ TON D'ALERTE. C'est le cadre qu'on lit avant de fermer, pour savoir si tout le
          // materiel est rentre. << Aucune paire n'est sortie >> sur une lecture refusee fait
          // fermer sur des paires dehors.
          <div className="banner banner-error">
            Les locations n’ont pas pu être lues. <b>Ne concluez pas que tout est rentré</b>&nbsp;:
            cette liste n’a pas été obtenue.
          </div>
        ) : enCours.length === 0 ? (
          <div className="empty">
            Aucune paire n'est sortie. Les locations apparaissent ici dès qu'une pointure quitte le
            parc, et en disparaissent au retour.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Pointure</th>
                <th>Bénéficiaire</th>
                <th>Sortie</th>
                {peutLouer && <th />}
              </tr>
            </thead>
            <tbody>
              {enCours.map((l) => (
                <tr key={l.id}>
                  <td><span className="nm">{l.parcPatins?.pointure ?? '—'}</span></td>
                  <td>{l.beneficiaire ? nommer(l.beneficiaire) : '—'}</td>
                  <td>{dateHeureFr(l.dateSortie)}</td>
                  {peutLouer && (
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => majParams({ retour: String(l.id) }, { pousser: true })}>Retour</button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

    </section>
  )
}

// Le retour : trois états, et chacun a une conséquence différente sur la caution. On les écrit, parce
// qu'un agent qui coche « cassés » sans savoir qu'il déclenche une retenue le découvrira par la
// réclamation du client.
const ETATS_RETOUR = [
  {
    valeur: 'bon',
    titre: 'Bon état',
    effet: 'La paire retourne au parc et la caution est rendue en entier.',
  },
  {
    valeur: 'casse',
    titre: 'Cassés',
    effet: 'La paire quitte le parc et passe hors service. Une retenue sur caution est créée, à valider ensuite.',
  },
  {
    valeur: 'non_rendu',
    titre: 'Non rendus',
    effet: 'La paire est retirée du parc. Une retenue sur caution est créée, à valider ensuite.',
  },
]

function RetourModal({ location, enAttenteParParc = {}, envoiCourriel = false, onClose, onFait, onErreur }) {
  const [etat, setEtat] = useState('bon')
  const [partielle, setPartielle] = useState(false)
  const [motif, setMotif] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (location) { setEtat('bon'); setPartielle(false); setMotif('') }
  }, [location])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.patinoireRetourPatins(location.id, {
        etatRetour: etat,
        ...(etat === 'non_rendu' && partielle ? { restitutionPartielle: true } : {}),
        ...(motif.trim() ? { motif: motif.trim() } : {}),
      })
      // ⚠ RENDRE UNE PAIRE PROMEUT QUELQU'UN DE LA LISTE D'ATTENTE — ET PERSONNE NE LE PRÉVIENT.
      //
      // Le serveur appelle `PromotionListeAttenteHandler::promouvoir()` au retour, qui compose un
      // courriel. Ce courriel ne part pas : cette instance n'a pas d'expéditeur configuré. Rien à
      // l'écran ne le disait, donc l'opérateur rendait la paire en croyant la personne prévenue —
      // et elle attend un message qui ne viendra jamais.
      //
      // ⚠ CE N'EST PAS UNE PROMESSE NON TENUE, C'EST UNE ABSENCE DE PROMESSE. L'écran ne mentait
      // pas : il ne disait simplement rien. C'est plus difficile à trouver qu'un mensonge, parce
      // qu'il n'y a aucune phrase à contredire — et c'est plus coûteux, parce que le geste qui
      // manque (décrocher son téléphone) n'est demandé à personne.
      //
      // La phrase suit `/me` : le jour où un expéditeur est branché, elle disparaît d'elle-même.
      const idParc = location?.parcPatins?.id || String(location?.parcPatins || '').split('/').pop()
      const enAttente = enAttenteParParc[idParc] || 0

      const base = etat === 'bon'
        ? 'Paire rendue, caution à restituer.'
        : 'Retour enregistré. Une retenue sur caution attend votre validation.'

      onFait(
        enAttente > 0 && !envoiCourriel
          ? `${base} ⚠ ${enAttente} personne(s) attendent cette pointure et ne seront PAS prévenues : `
            + `cette instance n’envoie aucun courriel. Contactez la première de la liste.`
          : base,
      )
    } catch (err) {
      onErreur(err.message || "Le retour n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  if (!location) return null

  return (
    <>
      <h2>{`Retour — pointure ${location.parcPatins?.pointure ?? ''}`}</h2>
      {location && (
        <form onSubmit={envoyer}>
          <div className="fiche-sec" style={{ marginTop: 0 }}>Dans quel état la paire revient-elle ?</div>
          {ETATS_RETOUR.map((e) => (
            <label
              key={e.valeur}
              style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 0', fontWeight: 400 }}
            >
              <input
                type="radio"
                name="etat-retour"
                checked={etat === e.valeur}
                onChange={() => setEtat(e.valeur)}
                style={{ marginTop: 3 }}
              />
              <span>
                <b>{e.titre}</b>
                <div className="sub">{e.effet}</div>
              </span>
            </label>
          ))}

          {etat === 'non_rendu' && (
            <div className="field" style={{ marginTop: 10 }}>
              <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 400 }}>
                <input type="checkbox" checked={partielle} onChange={(ev) => setPartielle(ev.target.checked)} />
                Une partie seulement manque (un patin sur deux, une lame…)
              </label>
              <div className="hint">
                Change le motif de la retenue : le barème n'applique pas le même montant à une paire
                entière qu'à un élément manquant.
              </div>
            </div>
          )}

          {etat !== 'bon' && (
            <div className="field">
              <label htmlFor="pat-motif">Précision</label>
              <input
                id="pat-motif"
                className="input"
                value={motif}
                placeholder="Lame tordue côté gauche"
                onChange={(ev) => setMotif(ev.target.value)}
              />
              <div className="hint">
                Facultatif, mais c'est ce que lira la personne qui validera la retenue — et le client
                si elle est contestée.
              </div>
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours}>
              {enCours ? 'Enregistrement…' : 'Enregistrer le retour'}
            </button>
          </div>
        </form>
      )}
    </>
  )
}

// --------------------------------------------------------------------------------------------
// La liste d'attente.
// --------------------------------------------------------------------------------------------
function ListeAttenteSection({ attente, nommer, peutAttente, onFait, onErreur }) {
  const ouvertes = (attente || [])
    .filter((l) => l.statut === 'en_attente' || l.statut === 'proposee')
    .sort((a, b) => (a.rang || 0) - (b.rang || 0))

  async function annuler(l) {
    if (
      !await confirmer(
        "Retirer cette personne de la liste d'attente ?\n\nElle perd son rang : si elle revient, elle "
          + 'repassera derrière celles inscrites entre-temps.',
      )
    )
      return
    try {
      await api.patinoireAnnulerListeAttente(l.id)
      onFait("Retiré de la liste d'attente.")
    } catch (e) {
      onErreur(e.message || "L'annulation n'a pas abouti.")
    }
  }

  return (
    <section className="card">
      <div className="card-h">
        <h3>Liste d'attente</h3>
        <span className="sub">
          {attente === null ? '—' : `${ouvertes.length} personne${ouvertes.length > 1 ? 's' : ''}`}
        </span>
      </div>
      <div className="card-b">
        {attente === null ? (
          <div className="banner banner-error">
            La liste d’attente n’a pas pu être lue. <b>Ne concluez pas que personne n’attend</b>.
          </div>
        ) : ouvertes.length === 0 ? (
          <div className="empty">
            Personne n'attend. On inscrit ici les clients dont la pointure est épuisée, pour les
            rappeler dans l'ordre dès qu'une paire revient.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th className="num">Rang</th>
                <th>Pointure</th>
                <th>Personne</th>
                <th>État</th>
                {peutAttente && <th />}
              </tr>
            </thead>
            <tbody>
              {ouvertes.map((l) => (
                <tr key={l.id}>
                  <td className="num">{l.rang}</td>
                  <td>{l.parcPatins?.pointure ?? '—'}</td>
                  <td>{l.beneficiaire ? nommer(l.beneficiaire) : '—'}</td>
                  <td>
                    <span className={`badge ${l.statut === 'proposee' ? 'warn' : 'mut'}`}>{mot(l.statut)}</span>
                    {l.pointureVoisineProposee != null && (
                      <span className="sub"> — pointure {l.pointureVoisineProposee} proposée</span>
                    )}
                  </td>
                  {peutAttente && (
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => annuler(l)}>Retirer</button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  )
}

// --------------------------------------------------------------------------------------------
// Les retenues sur caution.
// --------------------------------------------------------------------------------------------
function RetenuesSection({ retenues, peutValider, peutForcer, onFait, onErreur }) {
  const [validation, setValidation] = useState(null)
  const aValider = retenues.filter((r) => !r.validee)

  if (retenues.length === 0) return null

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Retenues sur caution</h3>
        <span className="sub">{aValider.length} en attente de validation</span>
      </div>
      <div className="card-b">
        <table className="tbl">
          <thead>
            <tr>
              <th>Motif</th>
              <th className="num">Montant</th>
              <th>Créée le</th>
              <th>État</th>
              {peutValider && <th />}
            </tr>
          </thead>
          <tbody>
            {retenues.map((r) => (
              <tr key={r.id}>
                <td>{r.motif ? mot(r.motif) : '—'}</td>
                <td className="num">{euros(r.montantRetenu)}</td>
                <td>{dateHeureFr(r.horodatage)}</td>
                <td>
                  {r.validee ? (
                    <span className="badge good">validée{r.forcee ? ' (forcée)' : ''}</span>
                  ) : (
                    <span className="badge warn">à valider</span>
                  )}
                </td>
                {peutValider && (
                  <td className="num">
                    {!r.validee && (
                      <button className="btn ghost sm" type="button" onClick={() => setValidation(r)}>Valider</button>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <ValidationRetenueModal
        retenue={validation}
        peutForcer={peutForcer}
        onClose={() => setValidation(null)}
        onFait={(m) => { setValidation(null); onFait(m) }}
        onErreur={onErreur}
      />
    </section>
  )
}

// Valider une retenue. Le montant du barème est proposé ; en changer sort du barème, et le serveur
// exige alors un droit distinct (`patinoire.forcer_retenue`). L'écran le dit avant l'envoi plutôt que
// de laisser le refus l'apprendre.
function ValidationRetenueModal({ retenue, peutForcer, onClose, onFait, onErreur }) {
  const [montant, setMontant] = useState('')
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (retenue) setMontant(retenue.montantRetenu || '0.00')
  }, [retenue])

  const bareme = retenue?.montantRetenu || '0.00'
  const horsBareme = retenue != null && Number(montant) !== Number(bareme)

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.patinoireValiderRetenue(retenue.id, { montantRetenu: montant })
      onFait(`Retenue de ${euros(montant)} validée.`)
    } catch (err) {
      onErreur(err.message || "La validation n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={!!retenue} onClose={onClose} titre="Valider la retenue sur caution">
      {retenue && (
        <form onSubmit={envoyer}>
          <p style={{ marginTop: 0 }}>
            Motif : <b>{retenue.motif ? mot(retenue.motif) : '—'}</b>. Le barème de l'établissement
            fixe cette retenue à <b>{euros(bareme)}</b>.
          </p>

          <div className="field">
            <label htmlFor="pat-montant">Montant retenu</label>
            <input
              id="pat-montant"
              className="input"
              type="number"
              step="0.01"
              min="0"
              value={montant}
              onChange={(ev) => setMontant(ev.target.value)}
            />
            <div className="hint">
              Le reste de la caution est rendu au client. Laisser le montant du barème est le cas normal.
            </div>
          </div>

          {horsBareme && (
            <div className="banner banner-error">
              {peutForcer
                ? 'Ce montant sort du barème. La retenue sera enregistrée comme forcée, à votre nom, et '
                  + 'restera identifiable comme telle.'
                : 'Ce montant sort du barème et votre profil ne permet pas de le forcer : le serveur '
                  + 'refusera. Remettez le montant du barème, ou faites valider par un responsable.'}
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours || (horsBareme && !peutForcer)}>
              {enCours ? 'Validation…' : horsBareme ? 'Forcer la retenue' : 'Valider la retenue'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

// --------------------------------------------------------------------------------------------
// L'atelier d'affûtage.
// --------------------------------------------------------------------------------------------
// LE BARÈME DE RETENUE, LÀ OÙ ON RETIENT — et pas seulement dans l'écran central des cautions.
//
// La patinoire expose quatre opérations sur son propre barème (`patinoire_grille_retenues`) :
// lecture, création, modification. **Aucune n'était atteignable.** Le régisseur voyait donc les
// retenues à valider, juste au-dessus, sans jamais voir NI pouvoir régler la règle qui en fixe le
// montant.
//
// Le barème générique du socle existe bien dans l'écran Cautions, et il couvre la même donnée. Mais
// pour l'atteindre depuis la patinoire il faut quitter son écran, aller au registre central, et
// taper `patinoire.patins` À LA MAIN dans un champ de texte libre — une faute de frappe y crée
// silencieusement un barème que rien n'applique jamais. Ici la cible est implicite, et le motif se
// choisit dans une liste fermée de quatre valeurs.
//
// > **La règle se règle là où on l'applique.** Un paramétrage qui n'est atteignable que depuis un
// > autre écran est un paramétrage qu'on ne corrige pas : on constate la retenue, on la trouve
// > fausse, et on la valide quand même.

// LES QUATRE MOTIFS DE RETENUE, ET POURQUOI ILS NE PASSENT PAS PAR `mot()`.
//
// `mot()` est une carte GLOBALE : un code y a une seule traduction. Or `casse` et `non_rendu`
// existent déjà dans ce module comme ÉTATS D'UNE PAIRE DE PATINS — ils y sont traduits « Cassés »
// et « Non rendus », au pluriel, parce qu'ils qualifient des patins.
//
// Comme MOTIF de retenue, le même code désigne la cause, pas l'objet : on retient pour « casse »,
// pas pour « cassés ». Vu à l'écran en créant la première ligne de barème — la table affichait
// « Cassés » là où la liste de saisie proposait « Casse ».
//
// Deux sens pour un code dans le même module : la carte globale ne peut pas porter les deux. La
// liste locale est la bonne réponse, et ce commentaire existe pour qu'on ne la « simplifie » pas en
// la renvoyant vers `mot()`.
const MOTIFS_RETENUE = {
  casse: 'Casse',
  non_rendu: 'Non rendu',
  perte: 'Perte',
  restitution_partielle: 'Restitution partielle',
}

function BaremeSection({ grilles, parc, peutConfigurer, majParams }) {
  const actives = (grilles || []).filter((g) => g.actif !== false)
  const inactives = (grilles || []).filter((g) => g.actif === false)

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Barème de retenue</h3>
        <span className="sub">ce qu&rsquo;on garde sur la caution, et pour quoi</span>
        {peutConfigurer && (
          <div className="r">
            <button className="btn primary sm" type="button" onClick={() => majParams({ bareme: 'nouvelle' }, { pousser: true })}>
              ＋ Ajouter une ligne
            </button>
          </div>
        )}
      </div>
      <div className="card-b" style={{ overflowX: 'auto' }}>
        {grilles === null ? (
          <div className="banner banner-error">
            Le barème de retenue n’a pas pu être lu. <b>N’en concluez pas qu’il n’y en a
            pas</b>&nbsp;: décider un montant au guichet sur cette base serait une erreur.
          </div>
        ) : grilles.length === 0 ? (
          <div className="empty">
            Aucun barème. Sans lui, chaque retenue est un montant décidé au guichet — donc un montant
            qui se discute, et qui n&rsquo;est pas le même d&rsquo;un agent à l&rsquo;autre.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Motif</th>
                <th>Parc concerné</th>
                <th>Mode</th>
                <th className="num">Montant</th>
                <th>État</th>
                {peutConfigurer && <th />}
              </tr>
            </thead>
            <tbody>
              {[...actives, ...inactives].map((g) => (
                <tr key={g.id} style={g.actif === false ? { opacity: 0.55 } : undefined}>
                  <td><span className="nm">{MOTIFS_RETENUE[g.motif] || mot(g.motif)}</span></td>
                  <td>
                    {/* `parcPatins` désigne une pointure précise du parc, ou rien : le barème vaut
                        alors pour tout le parc. « Tout le parc » est une information, pas un blanc. */}
                    {g.parcPatins
                      ? (nomParc(g.parcPatins, parc) || <span className="sub">une pointure précise</span>)
                      : <span className="sub">tout le parc</span>}
                  </td>
                  <td>
                    {mot(g.mode)}
                    {g.mode === 'valeur_remplacement' && (
                      <div className="sub">le prix de rachat de la paire</div>
                    )}
                  </td>
                  <td className="num">{euros(g.montantOuTaux)}</td>
                  <td>
                    <span className={`badge ${g.actif === false ? 'mut' : 'good'}`}>
                      {g.actif === false ? 'Suspendu' : 'Appliqué'}
                    </span>
                  </td>
                  {peutConfigurer && (
                    <td className="num">
                      <button className="btn ghost sm" type="button" onClick={() => majParams({ bareme: String(g.id) }, { pousser: true })}>
                        Modifier
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {(grilles?.length || 0) > 0 && (
          <div className="hint">
            Une ligne ne se supprime pas, elle se suspend : les retenues déjà faites la citent comme
            justification, et l&rsquo;effacer les rendrait inexplicables.
          </div>
        )}
      </div>

    </section>
  )
}

function BaremeModal({ grille, parc, etabActif, onClose, onFait, onErreur }) {
  const edition = grille && grille.id
  const [motif, setMotif] = useState('casse')
  const [mode, setMode] = useState('forfait')
  const [montant, setMontant] = useState('')
  const [parcPatins, setParcPatins] = useState('')
  const [actif, setActif] = useState(true)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!grille) return
    setMotif(grille.motif || 'casse')
    setMode(grille.mode || 'forfait')
    setMontant(grille.montantOuTaux != null ? String(grille.montantOuTaux) : '')
    setParcPatins(grille.parcPatins || '')
    setActif(grille.actif !== false)
  }, [grille])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      // Le serveur attend une décimale en chaîne (`montantOuTaux`), pas des centimes : c'est lui qui
      // convertit. On envoie donc ce que l'agent a tapé, virgule normalisée.
      const corps = {
        motif,
        mode,
        montantOuTaux: String(montant).replace(',', '.').trim() || '0.00',
        parcPatins: parcPatins || null,
        actif,
      }
      if (edition) {
        await api.majPatinoireGrilleRetenue(grille.id, corps)
      } else {
        await api.creerPatinoireGrilleRetenue({
          ...corps,
          etablissement: `/api/etablissements/${etabActif}`,
        })
      }
      onFait(edition ? 'Barème modifié.' : 'Ligne de barème ajoutée.')
    } catch (err) {
      onErreur(err.message || "Le barème n'a pas pu être enregistré.")
    } finally {
      setEnCours(false)
    }
  }

  if (!grille) return null

  return (
    <>
      <h2>{edition ? 'Modifier une ligne de barème' : 'Ajouter une ligne de barème'}</h2>
      <form onSubmit={envoyer}>
        <div className="field">
          <label htmlFor="pb-motif">Motif de la retenue *</label>
          <select id="pb-motif" className="input" value={motif} onChange={(e) => setMotif(e.target.value)}>
            <option value="casse">Casse</option>
            <option value="non_rendu">Non rendu</option>
            <option value="perte">Perte</option>
            <option value="restitution_partielle">Restitution partielle</option>
          </select>
          {/* LA LISTE FERMÉE EST LE POINT DE CET ÉCRAN.
              Sur le barème générique du socle, le motif est un champ libre : deux agents écrivent
              « casse » et « cassé », et ce sont deux règles. Ici la patinoire impose ses quatre
              motifs, et c'est ce que le client lira sur son reçu. */}
          <div className="hint">
            C&rsquo;est la phrase que le client lira sur son reçu, et qu&rsquo;il contestera ou non.
          </div>
        </div>

        <div className="field">
          <label htmlFor="pb-parc">Pointure concernée</label>
          <select id="pb-parc" className="input" value={parcPatins} onChange={(e) => setParcPatins(e.target.value)}>
            <option value="">Tout le parc</option>
            {(parc || []).map((p) => (
              <option key={p.id} value={p['@id'] || `/api/patinoire_parc_patins/${p.id}`}>
                Pointure {p.pointure}
              </option>
            ))}
          </select>
          <div className="hint">
            Laisser « tout le parc » sauf si une pointure vaut vraiment un autre prix — une paire de
            grande taille, par exemple.
          </div>
        </div>

        <div className="field">
          <label htmlFor="pb-mode">Mode de calcul</label>
          <select id="pb-mode" className="input" value={mode} onChange={(e) => setMode(e.target.value)}>
            <option value="forfait">Forfait — un montant fixe</option>
            <option value="valeur_remplacement">Valeur de remplacement — le prix de rachat</option>
          </select>
        </div>

        <div className="field">
          <label htmlFor="pb-montant">Montant (€) *</label>
          <input
            id="pb-montant"
            className="input"
            required
            inputMode="decimal"
            placeholder="15,00"
            value={montant}
            onChange={(e) => setMontant(e.target.value)}
          />
        </div>

        <div className="field">
          <label>
            <input type="checkbox" checked={actif} onChange={(e) => setActif(e.target.checked)} />{' '}
            Ligne appliquée
          </label>
          <div className="hint">
            Décochez pour suspendre sans effacer : les retenues déjà faites continueront de la citer.
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours || !montant.trim()}>
            {enCours ? 'Enregistrement…' : edition ? 'Enregistrer' : 'Ajouter'}
          </button>
        </div>
      </form>
    </>
  )
}

// Le parc arrive en IRI dans la grille : on le retrouve dans la liste déjà chargée par l'écran.
function nomParc(reference, parc) {
  const id = String(reference).split('/').pop()
  const p = (parc || []).find((x) => x.id === id)
  return p ? `Pointure ${p.pointure}` : null
}

function AffutagesSection({ parc, peutAffuter, etabActif, onFait, onErreur }) {
  // ⚠ `null` = PAS LU. Une lame a l'atelier qui n'apparait pas se lit << la paire est au
  // parc >>, et on la loue.
  const [affutages, setAffutages] = useState(null)
  const [nouveau, setNouveau] = useState(false)
  const [rafraichir, setRafraichir] = useState(0)

  useEffect(() => {
    api
      .patinoireAffutages()
      .then((c) => setAffutages(membres(c)))
      .catch(() => setAffutages(null))
  }, [etabActif, rafraichir])

  async function terminer(a) {
    try {
      await api.patinoireTerminerAffutage(a.id)
      setRafraichir((n) => n + 1)
      onFait('Affûtage terminé, la paire revient au parc.')
    } catch (e) {
      onErreur(e.message || "La clôture n'a pas abouti.")
    }
  }

  const ouverts = (affutages || []).filter((a) => a.statut !== 'termine')

  return (
    <section className="card" style={{ marginTop: 16 }}>
      <div className="card-h">
        <h3>Atelier d'affûtage</h3>
        <span className="sub">{affutages === null ? '—' : `${ouverts.length} en cours`}</span>
        {peutAffuter && (
          <div className="r">
            <button className="btn primary sm" type="button" onClick={() => setNouveau(true)}>
              ＋ Nouvel affûtage
            </button>
          </div>
        )}
      </div>
      <div className="card-b">
        {affutages === null ? (
          <div className="banner banner-error">
            Les affûtages n’ont pas pu être lus. <b>Ne concluez pas que toutes les lames sont au
            parc</b>&nbsp;: certaines sont peut-être à l’atelier.
          </div>
        ) : affutages.length === 0 ? (
          <div className="empty">
            Aucun affûtage. On enregistre ici les lames confiées à l'atelier — celles du parc, qui
            sortent alors du stock louable, et celles apportées par un client.
          </div>
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Type</th>
                <th>Pointure</th>
                <th>Entrée atelier</th>
                <th>État</th>
                {peutAffuter && <th />}
              </tr>
            </thead>
            <tbody>
              {(affutages || []).map((a) => (
                <tr key={a.id}>
                  <td>{mot(a.type)}</td>
                  <td>{a.parcPatins?.pointure ?? <span className="sub">patins du client</span>}</td>
                  <td>{dateHeureFr(a.dateEntreeAtelier)}</td>
                  <td>
                    <span className={`badge ${a.statut === 'termine' ? 'good' : 'info'}`}>{mot(a.statut)}</span>
                  </td>
                  {peutAffuter && (
                    <td className="num">
                      {a.statut !== 'termine' && (
                        <button className="btn ghost sm" type="button" onClick={() => terminer(a)}>Terminer</button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <AffutageModal
        open={nouveau}
        parc={parc}
        onClose={() => setNouveau(false)}
        onFait={(m) => { setNouveau(false); setRafraichir((n) => n + 1); onFait(m) }}
        onErreur={onErreur}
      />
    </section>
  )
}

// Deux affûtages qui n'ont rien à voir : l'entretien du parc immobilise une paire à nous, la
// prestation client porte sur des patins qui ne nous appartiennent pas. Le formulaire change donc de
// forme selon le type, au lieu d'afficher un champ « parc » qu'il faudrait deviner.
function AffutageModal({ open, parc, onClose, onFait, onErreur }) {
  const [type, setType] = useState('maintenance_parc')
  const [parcPatins, setParcPatins] = useState('')
  const [technicien, setTechnicien] = useState('')
  const [techniciens, setTechniciens] = useState([])
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!open) return
    setType('maintenance_parc')
    setParcPatins('')
    setTechnicien('')
    api
      .utilisateurs()
      .then((c) => setTechniciens(membres(c)))
      .catch(() => setTechniciens([]))
  }, [open])

  async function envoyer(e) {
    e.preventDefault()
    setEnCours(true)
    try {
      await api.patinoireDemarrerAffutage({
        type,
        technicien,
        ...(type === 'maintenance_parc' ? { parcPatins } : {}),
      })
      onFait(
        type === 'maintenance_parc'
          ? 'Affûtage lancé. La paire est retirée du stock louable le temps du passage à l’atelier.'
          : 'Affûtage client enregistré.',
      )
    } catch (err) {
      onErreur(err.message || "L'affûtage n'a pas pu être lancé.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Nouvel affûtage">
      <form onSubmit={envoyer}>
        <div className="field">
          <label htmlFor="af-type">Type</label>
          <select id="af-type" className="input" value={type} onChange={(e) => setType(e.target.value)}>
            <option value="maintenance_parc">Entretien du parc — une de nos paires</option>
            <option value="prestation_client">Affûtage client — patins apportés par le client</option>
          </select>
          <div className="hint">
            {type === 'maintenance_parc'
              ? 'La paire choisie sort du stock louable jusqu’à la fin de l’affûtage.'
              : 'Les patins du client ne touchent pas au stock : rien n’est retiré du parc.'}
          </div>
        </div>

        {type === 'maintenance_parc' && (
          <div className="field">
            <label htmlFor="af-parc">Pointure concernée *</label>
            <select id="af-parc" className="input" required value={parcPatins} onChange={(e) => setParcPatins(e.target.value)}>
              <option value="">Choisir…</option>
              {(parc || []).map((p) => (
                <option key={p.id} value={p.id}>
                  Pointure {p.pointure} — {p.quantiteDisponible || 0} dispo
                </option>
              ))}
            </select>
          </div>
        )}

        <div className="field">
          <label htmlFor="af-tech">Technicien *</label>
          <select id="af-tech" className="input" required value={technicien} onChange={(e) => setTechnicien(e.target.value)}>
            <option value="">Choisir…</option>
            {techniciens.map((u) => (
              <option key={u.id} value={u.id}>{u.nomComplet || u.email || String(u.id).slice(0, 8)}</option>
            ))}
          </select>
          <div className="hint">Qui a la paire en main : c'est à lui qu'on la réclamera.</div>
        </div>

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn" type="button" onClick={onClose}>Annuler</button>
          <button
            className="btn primary"
            type="submit"
            disabled={enCours || !technicien || (type === 'maintenance_parc' && !parcPatins)}
          >
            {enCours ? 'Enregistrement…' : 'Lancer l’affûtage'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// Conflits de glace : l'endpoint renvoie un objet { conflits: [...] }, pas une collection Hydra.
function ConflitsGlace({ etabActif }) {
  return (
    <Liste
      titre="Conflits de créneaux glace"
      sous="chevauchements détectés"
      deps={[etabActif]}
      charger={api.patinoireConflits}
      transforme={(_, res) => res?.conflits || []}
      vide="Aucun chevauchement de créneau."
      colonnes={[
        { cle: 'debut', entete: 'Début', rendu: (r) => dateHeureFr(r.debut) },
        { cle: 'fin', entete: 'Fin', rendu: (r) => dateHeureFr(r.fin) },
        { cle: 'motif', entete: 'Motif', rendu: (r) => r.motif || '—' },
      ]}
    />
  )
}

// DÉCLARER UNE POINTURE — deuxième des écrans qui savaient exploiter sans savoir créer.
//
// ⚠ TOUTE LA VALIDATION EST ICI, PARCE QU'IL N'Y EN A AUCUNE EN FACE.
//
// `ParcPatins` ne déclare ni `NotBlank`, ni `NotNull`, ni `Positive` : un POST au corps vide rend
// **201** et crée une pointure 28 à zéro paire. Mesuré en le faisant, et payé — l'entité n'a pas
// d'opération `Delete`, le parc vide ainsi créé est définitif (405 sur DELETE).
//
// Un formulaire n'est pas un garde-fou : quelqu'un qui appelle l'API directement passera toujours.
// Mais tant que le serveur ne borne rien, c'est le seul endroit qui empêche d'enregistrer une
// pointure 0 ou une quantité négative. Signalé pour le moteur ; en attendant, on borne ici et on
// le dit plutôt que de laisser croire que le serveur vérifie.
function ParcPatinsModal({ open, parc, onClose, onFait, onErreur }) {
  const [pointure, setPointure] = useState('')
  const [quantite, setQuantite] = useState('')
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!open) return
    setPointure('')
    setQuantite('')
    setErreur(null)
  }, [open])

  const n = Number(pointure)
  const q = Number(quantite)
  // Une pointure de patin descend rarement sous 25 et dépasse rarement 48 ; on ne l'interdit pas,
  // on prévient. Le doublon, lui, se refuse : deux lignes pour la même pointure rendraient le
  // décompte des paires ininterprétable.
  const dejaLa = (parc || []).some((x) => Number(x.pointure) === n)
  const pret = Number.isInteger(n) && n > 0 && Number.isInteger(q) && q > 0 && !dejaLa

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.creerParcPatins({ pointure: n, quantiteTotale: q })
      onFait()
    } catch (err) {
      setErreur(err.message || 'La pointure n’a pas pu être déclarée.')
    } finally {
      setEnvoi(false)
    }
  }

  if (!open) return null

  return (
    // ⚠ LE MOT VIENT DE L'ÉTABLISSEMENT (R12). Par défaut « Pointure » — `humaniser` le rend tel
    // quel — mais un loueur de combinaisons écrit « Taille » et un loueur de skis « Longueur ».
    <>
      <h2>{`Déclarer une ${mot('pointure').toLowerCase()}`}</h2>
      <form onSubmit={soumettre}>
        {erreur && <div className="banner banner-error" style={{ marginBottom: 'var(--esp-large)' }}>{erreur}</div>}

        {/* ⚠ `row-champs` ALIGNE PAR LE HAUT. Sans elle, `.row` aligne par le bas et les deux
            champs se décalent de 52 px — le champ de gauche n'a d'aide que par intermittence, et
            la mise en page saute de 90 px quand elle apparaît sous les doigts (R11). */}
        <div className="row row-champs">
          <div className="field" style={{ flex: 1 }}>
            <label htmlFor="pp-pointure">{mot('pointure')} *</label>
            <input
              id="pp-pointure"
              className="input"
              type="number"
              min="1"
              value={pointure}
              onChange={(e) => setPointure(e.target.value)}
            />
            {dejaLa && (
              <span className="hint">
                Cette pointure est déjà déclarée. Modifiez la ligne existante plutôt que d’en créer
                une seconde : deux lignes pour la même taille rendraient le décompte des paires
                impossible à lire.
              </span>
            )}
            {!dejaLa && n > 0 && (n < 25 || n > 48) && (
              <span className="hint">
                {n} est inhabituel pour un patin — vérifiez avant d’enregistrer, la ligne ne pourra
                pas être supprimée.
              </span>
            )}
          </div>
          <div className="field" style={{ flex: 1 }}>
            <label htmlFor="pp-quantite">Paires possédées *</label>
            <input
              id="pp-quantite"
              className="input"
              type="number"
              min="1"
              value={quantite}
              onChange={(e) => setQuantite(e.target.value)}
            />
            <span className="hint">
              Le total détenu, pas le disponible : les paires sorties et en affûtage se déduisent
              toutes seules.
            </span>
          </div>
        </div>

        <p className="hint">
          ⚠ Une pointure déclarée ne peut pas être supprimée — le serveur n’offre pas cette
          opération. Une erreur se corrige en ramenant la quantité à zéro.
        </p>

        <div className="r" style={{ display: 'flex', gap: 'var(--esp-normal)', justifyContent: 'flex-end' }}>
          <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
          <button type="submit" className="btn" disabled={envoi || !pret}>
            {envoi ? 'Déclaration…' : 'Déclarer'}
          </button>
        </div>
      </form>
    </>
  )
}
