import { useCallback, useEffect, useMemo, useState } from 'react'
import ClientPicker, { nomClient } from './ClientPicker.jsx'
import SignaturePad from './SignaturePad.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import { euros, libelleProduit, prixIndicatif, typeTarifId } from '../api/produit.js'
import { idDe } from '../api/iri.js'

// Composant PARTAGÉ de souscription d'abonnement (onglet Abonnements ET modale de caisse). Un
// abonnement se vend de la même manière partout (demande de Maxime) : même formulaire, mêmes
// signatures scellées (mandat + contrat), même prorata, même encaissement au comptoir — donc UNE
// seule source. L'écran Abonnements le rend en pleine page ; la caisse le rend en modale, produit
// imposé (la ligne de panier) et payeur pré-rempli (le client rattaché à la vente).
//
// ── POURQUOI UN ASSISTANT EN TROIS ÉTAPES (demande de Maxime) ──────────────────────────────────
// Tout sur un écran, l'agent de caisse recevait « IBAN requis » À LA FIN, après avoir tout saisi.
// On découpe donc le geste : 1) la Formule, 2) le Client & son mandat, 3) la Validation. Chaque
// étape valide ce qui la concerne — l'erreur IBAN bloque l'étape « client & mandat », pas la
// dernière — et l'étape 3 récapitule avant d'engager. Aucune logique de souscription ne change :
// mêmes états, même corps envoyé, même encaissement ; seul l'agencement à l'écran est repensé.

const ETAPES = ['Formule', 'Client & mandat', 'Validation']

const LABEL_PERIODICITE = {
  mensuel: 'Mensuel',
  annuel: 'Annuel',
  personnalise: 'Personnalisé',
}

function labelPeriodicite(v) {
  if (!v) return '—'
  return LABEL_PERIODICITE[v] || v.charAt(0).toUpperCase() + v.slice(1)
}

// La frise des trois étapes : pastille numérotée (✓ une fois franchie), libellé sous l'active en
// gras. Non cliquable — on avance par « Suivant » pour que chaque étape valide la précédente.
function FriseEtapes({ etape }) {
  return (
    <div
      className="row"
      style={{ gap: 'var(--esp-normal)', marginBottom: 'var(--esp-normal)', flexWrap: 'wrap' }}
    >
      {ETAPES.map((libelle, i) => {
        const n = i + 1
        const actif = n === etape
        const franchie = n < etape
        return (
          <div key={libelle} className="row" style={{ gap: 'var(--esp-serre)', alignItems: 'center' }}>
            <span
              aria-hidden="true"
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: 26,
                height: 26,
                borderRadius: 999,
                fontSize: 13,
                fontWeight: 600,
                background: actif || franchie ? 'var(--accent)' : 'var(--line)',
                color: actif || franchie ? 'var(--sur-accent)' : 'var(--ink-soft)',
              }}
            >
              {franchie ? '✓' : n}
            </span>
            <span
              style={{
                color: actif ? 'var(--ink)' : 'var(--ink-soft)',
                fontWeight: actif ? 600 : 400,
              }}
            >
              {libelle}
            </span>
          </div>
        )
      })}
    </div>
  )
}

// Une ligne de récapitulatif : libellé discret à gauche, valeur à droite.
function LigneRecap({ label, children }) {
  return (
    <div className="row" style={{ gap: 'var(--esp-normal)', justifyContent: 'space-between', flexWrap: 'wrap' }}>
      <span className="sub">{label}</span>
      <span style={{ textAlign: 'right' }}>{children}</span>
    </div>
  )
}

// Détails du produit choisi : prix indicatif, cadence, jour de prélèvement. Rendus depuis l'objet
// produit déjà chargé (la collection porte `formule` et `grilles`), sans appel supplémentaire.
function DetailsProduit({ produit }) {
  const f = produit.formule || {}
  const prix = prixIndicatif(produit)
  return (
    <div
      style={{
        border: '1px solid var(--line)',
        borderRadius: 10,
        padding: 'var(--esp-normal)',
        display: 'grid',
        gap: 'var(--esp-serre)',
      }}
    >
      <strong>{libelleProduit(produit)}</strong>
      <div className="row" style={{ gap: 'var(--esp-large)', flexWrap: 'wrap' }}>
        <span>
          <span className="sub">Prix&nbsp;: </span>
          {prix != null ? euros(prix) : '—'}
        </span>
        <span>
          <span className="sub">Périodicité&nbsp;: </span>
          {labelPeriodicite(f.periodicite)}
        </span>
        {f.jourPrelevement != null && (
          <span>
            <span className="sub">Prélèvement le&nbsp;: </span>
            {f.jourPrelevement}
          </span>
        )}
        <span>
          <span className="sub">SEPA&nbsp;: </span>
          {f.sepaActif ? 'oui' : 'non'}
        </span>
      </div>
    </div>
  )
}

// Souscription au guichet. Le prix et la cadence NE SONT PAS envoyés — le serveur les résout depuis la
// formule du produit (« pas de prix libre »). Payeur et adhérent sont des CLIENTS (recherche/création
// comme en caisse) ; l'adhérent est facultatif (par défaut le payeur).
// ── CE QUE LA SOUSCRIPTION SAIT FAIRE DE PLUS (reprise PR #74) ────────────────────────────────────
//
// 1. ELLE FAIT SIGNER. Le mandat SEPA et le contrat sont signés à l'écran (pad manuscrit) et le
//    serveur les SCELLE (`App\Signature` : empreinte du document, opérateur, horodatage, IP, chaîne
//    HMAC). ⚠ La signature reste FACULTATIVE, et ce n'est pas un oubli : sans image, le backend
//    enregistre quand même un consentement horodaté — jamais moins qu'avant, où RIEN n'était signé.
//    Bloquer la souscription sur un pad vide empêcherait de vendre là où on vendait hier.
//
// 2. ELLE SAIT POSER UN PRORATA. Première échéance à montant réduit (demi-mois d'entrée, mois offert) :
//    `montantPremiereEcheanceCentimes`. Le serveur le BORNE au tarif résolu — un prorata retranche,
//    il ne fixe pas un prix — et refuse au-delà. On affiche SON refus, on ne redouble pas sa règle.
//
// 3. ELLE SAIT ENCAISSER AU COMPTOIR. Caisse ouverte : l'opérateur encaisse la première échéance tout
//    de suite au lieu de la faire prélever. Voir la note d'ordonnancement dans `encaisserComptant`.
export default function SouscriptionAbonnement({
  produits,
  // Modale de caisse : le produit est IMPOSÉ (la ligne de panier) et le payeur pré-rempli (le
  // client rattaché à la vente). `enModale` retire l'entête pleine page — la modale a la sienne.
  produitImpose = null,
  payeurInitial = null,
  enModale = false,
  onAnnuler,
  onCree,
  session,
  droits,
}) {
  const [etape, setEtape] = useState(1)
  const [produitId, setProduitId] = useState(produitImpose ? idDe(produitImpose) : '')
  const [payeur, setPayeur] = useState(payeurInitial || null)
  const [adherent, setAdherent] = useState(null)
  const [picker, setPicker] = useState(null) // null | 'payeur' | 'adherent'
  const [iban, setIban] = useState('')
  const [titulaire, setTitulaire] = useState('')
  const [dureeMois, setDureeMois] = useState(12)
  const [signatureMandat, setSignatureMandat] = useState(null)
  const [signatureContrat, setSignatureContrat] = useState(null)
  const [prorata, setProrata] = useState('') // euros, saisie libre ; vide = pas de prorata
  const [comptant, setComptant] = useState(false)
  const [moyenSel, setMoyenSel] = useState('')
  const [moyens, setMoyens] = useState(null) // null = on lit ; undefined = refus ; [] = lu vide
  const [pdvs, setPdvs] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)
  // ⚠ `avis` N'EST PAS UNE ERREUR, ET LES CONFONDRE COÛTERAIT DE L'ARGENT. Il porte le cas où
  // l'abonnement EST créé (et parfois l'argent DÉJÀ encaissé) mais où une étape en aval a échoué :
  // afficher « souscription refusée » ferait recommencer une souscription qui a abouti.
  const [avis, setAvis] = useState(null)

  const peutForcerPrix = aLeDroit(droits, 'vente.forcer_prix')

  // ⚠ CE QUE CET ÉCRAN SAIT SOUSCRIRE : un produit d'abonnement PRÉLEVÉ EN SEPA. Le filtre n'est pas
  // cosmétique — la souscription ouvre un mandat (IBAN + titulaire obligatoires). Montrer un produit
  // sans SEPA laisserait choisir ce qu'on ne peut pas finir de saisir ici.
  const produitsAbo = useMemo(
    () => {
      // Modale de caisse : le produit est IMPOSÉ, pas choisi dans une liste.
      if (produitImpose) return [produitImpose]
      return Array.isArray(produits) ? produits.filter((p) => p?.formule?.sepaActif) : []
    },
    [produits, produitImpose],
  )
  const produit = useMemo(
    () => produitsAbo.find((p) => idDe(p) === produitId) || null,
    [produitsAbo, produitId],
  )

  // Moyens de paiement : lus une seule fois, et seulement s'il y a une caisse ouverte à alimenter.
  useEffect(() => {
    if (!session?.id) return undefined
    let vivant = true
    Promise.all([api.moyensPaiement(), api.pointDeVentes()])
      .then(([m, p]) => {
        if (!vivant) return
        // Les moyens INACTIFS ne se proposent nulle part (mêmes règles que la caisse) : c'est ce qui
        // écarte le « prélèvement » système du choix d'encaissement au comptoir.
        setMoyens(membres(m).filter((x) => x.actif !== false))
        setPdvs(membres(p))
      })
      .catch(() => {
        if (!vivant) return
        setMoyens(undefined)
        setPdvs(undefined)
      })
    return () => {
      vivant = false
    }
  }, [session?.id])

  // ⚠ ON NE PROPOSE PAS ICI LES MOYENS QUI EXIGENT UN DIALOGUE TPE (`exigeReference`). Cet écran n'a
  // pas le dialogue terminal de la caisse (simulation, refus, timeout, rejeu) ; l'y bricoler ferait
  // deux chemins d'argent qui divergent. CB et chèque référencé restent le geste de la CAISSE, et on
  // le DIT au lieu de le laisser découvrir sur un refus.
  const moyensDispo = useMemo(() => {
    if (!Array.isArray(moyens)) return moyens
    const pdvId = session?.pointDeVente?.id || session?.pointDeVente
    const pdv = Array.isArray(pdvs) ? pdvs.find((x) => String(x.id) === String(pdvId)) : null
    const autorises = pdv?.moyensAutorises || []
    return moyens
      .filter((m) => autorises.length === 0 || autorises.includes(m.code))
      .filter((m) => !m.exigeReference)
      .filter((m) => m.code !== 'pmv')
  }, [moyens, pdvs, session])

  useEffect(() => {
    if (Array.isArray(moyensDispo) && !moyenSel) setMoyenSel(moyensDispo[0]?.code || '')
  }, [moyensDispo, moyenSel])

  // Le tarif affiché par le catalogue, en euros. C'est la référence à laquelle le prorata se compare
  // (le serveur, lui, résout le SIEN à la date de souscription : les deux peuvent différer, et c'est
  // le sien qui fait foi — d'où le fait qu'on n'oppose jamais ce nombre à l'opérateur).
  const tarifEuros = produit ? prixIndicatif(produit) : null
  const prorataEuros = prorata.trim() === '' ? null : Number(prorata.replace(',', '.'))
  const prorataInvalide = prorataEuros !== null && (Number.isNaN(prorataEuros) || prorataEuros < 0)
  // Ce que l'opérateur encaisserait au comptoir : le prorata s'il est posé, sinon le tarif entier.
  const montantComptant = prorataEuros !== null && !prorataInvalide ? prorataEuros : tarifEuros

  // ⚠ ENCAISSER UN MONTANT QUI N'EST PAS LE TARIF, C'EST FORCER UN PRIX EN CAISSE. La ligne de vente
  // se tarife depuis la grille du produit ; un prorata est par construction inférieur. Le seul
  // chemin honnête est `prixForce`, et il est tenu par le droit `vente.forcer_prix`. Sans ce droit,
  // on n'encaisse au comptoir QUE le tarif plein — et on dit pourquoi, au lieu de griser en silence.
  const comptantExigeForcage =
    montantComptant != null
    && tarifEuros != null
    && Number(montantComptant).toFixed(2) !== Number(tarifEuros).toFixed(2)
  const comptantPossible =
    Boolean(session?.id)
    && Array.isArray(moyensDispo)
    && moyensDispo.length > 0
    && montantComptant != null
    && Number(montantComptant) > 0
    && (!comptantExigeForcage || peutForcerPrix)

  useEffect(() => {
    if (!comptantPossible && comptant) setComptant(false)
  }, [comptantPossible, comptant])

  // ── ENCAISSEMENT AU COMPTOIR ───────────────────────────────────────────────────────────────────
  //
  // ⚠ L'ORDRE DES APPELS EST LA SEULE CHOSE QUI PROTÈGE L'ADHÉRENT D'UN DOUBLE PRÉLÈVEMENT, ET IL SE
  // LIT À L'ENVERS DE L'INTUITION.
  //
  // Encaisser la première échéance au comptoir veut dire qu'elle ne doit PAS être prélevée en plus.
  // Il faut donc l'annuler — mais l'annuler AVANT d'avoir l'argent laisserait, au moindre refus de
  // règlement, un abonnement dont la première échéance est annulée et jamais encaissée : une somme
  // que le club ne réclamerait plus jamais, sans que personne ne s'en aperçoive.
  //
  // On annule donc EN DERNIER, une fois l'argent réellement encaissé et la vente validée. Si cette
  // dernière étape échoue, l'échéance reste « à venir » : l'adhérent risque d'être prélevé deux
  // fois, ce qui est visible, réclamable et réparable — au contraire du silence.
  //
  // ⚠ ET ON NE JETTE PAS. Chaque échec est raconté à l'opérateur avec l'endroit où ça s'est arrêté,
  // parce que la réparation n'est pas la même selon l'étape.
  const encaisserComptant = useCallback(
    async (abonnement) => {
      const abonnementId = idDe(abonnement)
      const tarif = typeTarifId(produit)
      if (!tarif) {
        return "L'abonnement est souscrit, mais ce produit n'a aucun tarif au guichet : rien n'a été "
          + "encaissé. Encaissez depuis la caisse, ou laissez la première échéance se prélever."
      }
      let vente
      try {
        vente = await api.creerVente({ session: session.id })
      } catch (e) {
        return "L'abonnement est souscrit, mais la vente n'a pas pu être ouverte en caisse ("
          + (e?.message || 'refus du serveur')
          + ") : rien n'a été encaissé, et la première échéance sera prélevée normalement."
      }
      const numero = vente?.numero ? `n° ${vente.numero}` : `id ${vente?.id}`
      try {
        if (payeur) {
          // Le rattachement peut échouer sans empêcher d'encaisser : la vente reste anonyme, ce qui
          // est moins bien mais pas faux. On ne casse pas un encaissement pour un champ de confort.
          try {
            await api.rattacherClientVente(vente.id, { client: idDe(payeur) })
          } catch {
            /* vente anonyme : sans conséquence sur l'argent, donc non remonté */
          }
        }
        const ligne = { produit: idDe(produit), typeTarif: tarif, quantite: 1 }
        if (comptantExigeForcage) {
          ligne.prixForce = true
          ligne.prixUnitaire = Number(montantComptant).toFixed(2)
        }
        await api.ajouterLigne(vente.id, ligne)
        const reglement = await api.payer(vente.id, {
          moyen: moyenSel,
          montant: Number(montantComptant).toFixed(2),
        })
        if (!reglement?.reglementEnregistre) {
          return `L'abonnement est souscrit. Le règlement a été refusé : la vente ${numero} reste `
            + `ouverte en caisse, et la première échéance sera prélevée normalement.`
        }
        await api.valider(vente.id)
      } catch (e) {
        return `L'abonnement est souscrit. L'encaissement s'est arrêté sur la vente ${numero} (`
          + (e?.message || 'refus du serveur')
          + `) : reprenez-la depuis la caisse. La première échéance reste programmée.`
      }
      // L'argent est encaissé. À partir d'ici, tout échec laisse une échéance de trop — jamais un
      // encaissement de moins.
      let echeance = null
      try {
        const liste = membres(
          await api.echeancesSepaSport({ abonnement: abonnementId, statut: 'a_venir', order: 'asc' }),
        )
        echeance = Array.isArray(liste) && liste.length > 0 ? liste[0] : null
      } catch {
        /* dit par le message ci-dessous */
      }
      if (!echeance) {
        return `Encaissé au comptoir (vente ${numero}). ⚠ La première échéance n'a pas pu être `
          + `retrouvée : vérifiez l'échéancier et annulez-la, sinon l'adhérent sera prélevé deux fois.`
      }
      try {
        await api.annulerEcheanceSepa(
          idDe(echeance),
          `Première échéance encaissée au comptoir (vente ${numero}).`,
        )
      } catch (e) {
        return `Encaissé au comptoir (vente ${numero}). ⚠ La première échéance n'a pas pu être `
          + `annulée (${e?.message || 'refus du serveur'}) : annulez-la dans l'échéancier, sinon `
          + `l'adhérent sera prélevé deux fois.`
      }
      return null
    },
    [produit, session, payeur, moyenSel, montantComptant, comptantExigeForcage],
  )

  const soumettre = useCallback(async () => {
    setErreur(null)
    setAvis(null)
    if (!produit || !payeur || !iban.trim() || !titulaire.trim()) {
      setErreur('Produit, payeur, IBAN et titulaire du mandat sont requis.')
      return
    }
    if (prorataInvalide) {
      setErreur('Le prorata doit être un montant positif, ou vide.')
      return
    }
    const formule = produit.formule ? idDe(produit.formule) : null
    if (!formule) {
      setErreur("Ce produit n'est pas un abonnement (aucune formule attachée).")
      return
    }
    setEnvoi(true)
    try {
      const corps = {
        payeur: idDe(payeur),
        formule,
        iban: iban.trim(),
        titulaireMandat: titulaire.trim(),
        dureeEngagementMois: Number(dureeMois) || 12,
      }
      // Adhérent facultatif : présent seulement s'il diffère du payeur. Absent = le serveur prend le
      // payeur comme adhérent (mais un adhérent DÉSIGNÉ mais introuvable est refusé, pas ignoré).
      if (adherent) corps.adherent = idDe(adherent)
      // Les images de signature : envoyées quand elles existent, jamais inventées. Le serveur scelle
      // ce qu'il reçoit ; une clé absente vaut « consentement sans image », pas « non signé ».
      if (signatureMandat) corps.signatureMandat = signatureMandat
      if (signatureContrat) corps.signatureContrat = signatureContrat
      // ⚠ `0` EST UNE VALEUR, PAS UN VIDE : c'est le mois offert. Tester `!== null` le laisse passer
      // là où un test de véracité l'aurait effacé en silence.
      if (prorataEuros !== null) corps.montantPremiereEcheanceCentimes = Math.round(prorataEuros * 100)

      const abonnement = await api.souscrireAbonnement(corps)

      if (comptant && comptantPossible) {
        const souci = await encaisserComptant(abonnement)
        if (souci) {
          // La souscription a abouti : on NE la rejoue pas. On remonte l'avertissement et on laisse
          // l'opérateur revenir à la liste quand il l'a lu.
          setAvis(souci)
          setEnvoi(false)
          return
        }
      }
      await onCree()
    } catch (e) {
      setErreur(e?.message || 'La souscription a été refusée.')
      setEnvoi(false)
    }
  }, [
    produit,
    payeur,
    adherent,
    iban,
    titulaire,
    dureeMois,
    onCree,
    signatureMandat,
    signatureContrat,
    prorataEuros,
    prorataInvalide,
    comptant,
    comptantPossible,
    encaisserComptant,
  ])

  // Passage à l'étape suivante : on ne valide QUE ce que l'étape courante porte, pour que l'agent
  // voie le manque là où il le saisit (et pas un mur d'erreurs à la fin).
  const allerSuivant = () => {
    if (etape === 1) {
      if (!produit) {
        setErreur("Choisissez un produit d'abonnement pour continuer.")
        return
      }
      if (prorataInvalide) {
        setErreur('Le prorata doit être un montant positif, ou vide.')
        return
      }
    }
    if (etape === 2) {
      if (!payeur) {
        setErreur('Choisissez un payeur pour continuer.')
        return
      }
      if (!iban.trim() || !titulaire.trim()) {
        setErreur('IBAN et titulaire du mandat sont requis.')
        return
      }
    }
    setErreur(null)
    setEtape((n) => Math.min(3, n + 1))
  }

  const allerPrecedent = () => {
    setErreur(null)
    setEtape((n) => Math.max(1, n - 1))
  }

  return (
    <div className={enModale ? '' : 'view large'}>
      {!enModale && (
        <div className="view-head">
          <div className="ttl">
            <button
              type="button"
              className="btn ghost sm"
              onClick={onAnnuler}
              style={{ marginBottom: 'var(--esp-serre)' }}
            >
              ← Retour aux abonnements
            </button>
            <h2>Nouvel abonnement</h2>
            <p className="sub">Souscription au guichet, en trois étapes : formule, client &amp; mandat, validation.</p>
          </div>
        </div>
      )}

      <div className="card">
        <div className="card-b" style={{ display: 'grid', gap: 'var(--esp-normal)' }}>
          <FriseEtapes etape={etape} />

          {/* ── ÉTAPE 1 — FORMULE ──────────────────────────────────────────────────────────────── */}
          {etape === 1 && (
            <>
              {!produitImpose && (
                <label className="field" style={{ margin: 0 }}>
                  <span className="sub">Produit d'abonnement</span>
                  <select className="select" value={produitId} onChange={(e) => setProduitId(e.target.value)}>
                    <option value="">Sélectionner…</option>
                    {produitsAbo.map((p) => (
                      <option key={idDe(p)} value={idDe(p)}>
                        {libelleProduit(p)}
                      </option>
                    ))}
                  </select>
                  {produits === undefined && <small className="crit">Produits non lisibles.</small>}
                  {Array.isArray(produits) && produitsAbo.length === 0 && (
                    <small className="sub">Aucun produit d'abonnement prélevé en SEPA dans le catalogue.</small>
                  )}
                  <small className="sub">Le prix et la cadence viennent de la formule du produit.</small>
                </label>
              )}

              {produit && <DetailsProduit produit={produit} />}

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-serre)' }}>
                <label className="field" style={{ margin: 0 }}>
                  <span className="sub">Durée d'engagement (mois)</span>
                  <input
                    className="input"
                    type="number"
                    min="0"
                    value={dureeMois}
                    onChange={(e) => setDureeMois(e.target.value)}
                  />
                </label>
                <label className="field" style={{ margin: 0 }}>
                  <span className="sub">Première échéance — prorata (€, facultatif)</span>
                  <input
                    className="input"
                    type="number"
                    min="0"
                    step="0.01"
                    value={prorata}
                    onChange={(e) => setProrata(e.target.value)}
                    placeholder={tarifEuros != null ? `Par défaut ${euros(tarifEuros)}` : 'Par défaut, le tarif'}
                  />
                  {prorataInvalide ? (
                    <small className="crit">Montant positif, ou vide.</small>
                  ) : (
                    <small className="sub">
                      Demi-mois d'entrée, mois offert (0 €). Un prorata retranche : il ne peut pas dépasser
                      le tarif, et c'est le serveur qui tranche.
                    </small>
                  )}
                </label>
              </div>
            </>
          )}

          {/* ── ÉTAPE 2 — CLIENT & MANDAT ──────────────────────────────────────────────────────── */}
          {etape === 2 && (
            <>
              <div className="field" style={{ margin: 0 }}>
                <span className="sub">Payeur</span>
                <div className="row" style={{ gap: 'var(--esp-serre)', alignItems: 'center', flexWrap: 'wrap' }}>
                  <button type="button" className="btn" onClick={() => setPicker('payeur')}>
                    {payeur ? 'Modifier le payeur' : 'Choisir un payeur'}
                  </button>
                  <span>{payeur ? nomClient(payeur) : <span className="sub">Aucun payeur choisi</span>}</span>
                </div>
              </div>

              <div className="field" style={{ margin: 0 }}>
                <span className="sub">Adhérent (facultatif)</span>
                <div className="row" style={{ gap: 'var(--esp-serre)', alignItems: 'center', flexWrap: 'wrap' }}>
                  <button type="button" className="btn" onClick={() => setPicker('adherent')}>
                    {adherent ? "Modifier l'adhérent" : 'Choisir un adhérent'}
                  </button>
                  <span>
                    {adherent ? (
                      nomClient(adherent)
                    ) : (
                      <span className="sub">Par défaut, le payeur est l'adhérent</span>
                    )}
                  </span>
                  {adherent && (
                    <button type="button" className="btn ghost sm" onClick={() => setAdherent(null)}>
                      Retirer
                    </button>
                  )}
                </div>
                <small className="sub">Recherche ou création d'un client, comme en caisse.</small>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-serre)' }}>
                <label className="field" style={{ margin: 0 }}>
                  <span className="sub">IBAN (mandat SEPA)</span>
                  <input className="input" value={iban} onChange={(e) => setIban(e.target.value)} placeholder="FR76 …" />
                </label>
                <label className="field" style={{ margin: 0 }}>
                  <span className="sub">Titulaire du mandat</span>
                  <input className="input" value={titulaire} onChange={(e) => setTitulaire(e.target.value)} />
                </label>
              </div>
              <small className="sub" style={{ display: 'block' }}>
                Le « mandat SEPA » est l'autorisation de prélèvement signée par le titulaire du compte : on
                prélèvera ensuite automatiquement le montant, à la cadence du produit.
              </small>
            </>
          )}

          {/* ── ÉTAPE 3 — VALIDATION (signature, encaissement, récapitulatif) ──────────────────── */}
          {etape === 3 && (
            <>
              {/* Récapitulatif : ce qu'on s'apprête à engager, relu d'un coup d'œil avant de valider. */}
              <div
                style={{
                  border: '1px solid var(--line)',
                  borderRadius: 10,
                  padding: 'var(--esp-normal)',
                  display: 'grid',
                  gap: 'var(--esp-serre)',
                }}
              >
                <b>Récapitulatif</b>
                <LigneRecap label="Produit">{produit ? libelleProduit(produit) : '—'}</LigneRecap>
                <LigneRecap label="Prix">{tarifEuros != null ? euros(tarifEuros) : '—'}</LigneRecap>
                <LigneRecap label="Payeur">{payeur ? nomClient(payeur) : '—'}</LigneRecap>
                <LigneRecap label="Adhérent">{adherent ? nomClient(adherent) : 'Le payeur'}</LigneRecap>
                <LigneRecap label="Engagement">{`${Number(dureeMois) || 12} mois`}</LigneRecap>
                <LigneRecap label="Titulaire du mandat">{titulaire || '—'}</LigneRecap>
                <LigneRecap label="IBAN"><span className="mono">{iban || '—'}</span></LigneRecap>
                <LigneRecap label="Première échéance">
                  {prorataEuros !== null && !prorataInvalide ? `${euros(prorataEuros)} (prorata)` : 'Tarif plein'}
                </LigneRecap>
                <LigneRecap label="Encaissement">
                  {comptant && comptantPossible
                    ? `Au comptoir${montantComptant != null ? ` (${euros(montantComptant)})` : ''}`
                    : 'Prélèvement de la première échéance'}
                </LigneRecap>
              </div>

              {/* ── SIGNATURE ────────────────────────────────────────────────────────────────────── */}
              <div
                style={{
                  border: '1px solid var(--line)',
                  borderRadius: 10,
                  padding: 'var(--esp-normal)',
                  display: 'grid',
                  gap: 'var(--esp-normal)',
                }}
              >
                <div>
                  <b>Signature</b>
                  <p className="sub" style={{ margin: 'var(--esp-serre) 0 0' }}>
                    Le mandat et le contrat sont composés, signés et <b>scellés</b> par le serveur
                    (empreinte du document, opérateur, horodatage, adresse IP). Le texte réglementaire
                    exact du mandat SEPA et les clauses du contrat sont en cours de validation : les
                    documents signés aujourd'hui portent une mention factuelle et un marqueur
                    « à finaliser ».
                  </p>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--esp-normal)' }}>
                  <SignaturePad label="Signature du mandat SEPA" onChange={setSignatureMandat} />
                  <SignaturePad label="Signature du contrat d'abonnement" onChange={setSignatureContrat} />
                </div>
                {(!signatureMandat || !signatureContrat) && (
                  <small className="sub">
                    Sans signature manuscrite, la souscription reste possible : le serveur enregistre un
                    consentement horodaté. Faire signer reste préférable — c'est ce qui lie la signature
                    au geste de l'adhérent.
                  </small>
                )}
              </div>

              {/* ── ENCAISSEMENT AU COMPTOIR ─────────────────────────────────────────────────────── */}
              <div
                style={{
                  border: '1px solid var(--line)',
                  borderRadius: 10,
                  padding: 'var(--esp-normal)',
                  display: 'grid',
                  gap: 'var(--esp-serre)',
                }}
              >
                <b>Encaissement de la première échéance</b>
                {!session?.id ? (
                  <small className="sub">
                    Aucune caisse ouverte : la première échéance sera prélevée avec les suivantes. Ouvrez
                    une caisse pour pouvoir l'encaisser au comptoir.
                  </small>
                ) : moyens === undefined ? (
                  <small className="crit">
                    Les moyens de paiement n'ont pas pu être lus : l'encaissement au comptoir est
                    indisponible. La première échéance sera prélevée.
                  </small>
                ) : moyens === null ? (
                  <small className="sub">Lecture des moyens de paiement…</small>
                ) : (
                  <>
                    <label className="row" style={{ gap: 'var(--esp-serre)', alignItems: 'center' }}>
                      <input
                        type="checkbox"
                        checked={comptant}
                        disabled={!comptantPossible}
                        onChange={(e) => setComptant(e.target.checked)}
                      />
                      <span>
                        Encaisser maintenant
                        {montantComptant != null ? ` ${euros(montantComptant)}` : ''} au comptoir, et ne pas
                        prélever la première échéance
                      </span>
                    </label>
                    {comptant && (
                      <label className="field" style={{ margin: 0, maxWidth: 260 }}>
                        <span className="sub">Moyen de paiement</span>
                        <select className="select" value={moyenSel} onChange={(e) => setMoyenSel(e.target.value)}>
                          {moyensDispo.map((m) => (
                            <option key={m.code} value={m.code}>
                              {m.libelle || m.code}
                            </option>
                          ))}
                        </select>
                      </label>
                    )}
                    {Array.isArray(moyensDispo) && moyensDispo.length === 0 && (
                      <small className="sub">
                        Aucun moyen encaissable ici. La carte et le chèque référencé passent par le
                        terminal, donc par l'écran Caisse.
                      </small>
                    )}
                    {comptantExigeForcage && !peutForcerPrix && (
                      <small className="sub">
                        Le montant à encaisser diffère du tarif du produit (prorata) : l'encaisser exige le
                        droit « forcer un prix » en caisse. Sans lui, la première échéance sera prélevée.
                      </small>
                    )}
                    {comptant && comptantExigeForcage && peutForcerPrix && (
                      <small className="sub">
                        Le prix sera <b>forcé</b> sur la ligne de vente pour coller au prorata : la caisse
                        en garde la trace.
                      </small>
                    )}
                    <small className="sub">
                      La vente est ouverte dans la caisse en cours, réglée, validée — puis la première
                      échéance est annulée avec ce motif. Dans cet ordre : rien n'est annulé avant que
                      l'argent soit encaissé.
                    </small>
                  </>
                )}
              </div>
            </>
          )}

          {erreur && <div className="banner banner-error">{erreur}</div>}
          {avis && (
            <div className="banner banner-warn">
              {avis}
              <div style={{ marginTop: 'var(--esp-serre)' }}>
                <button type="button" className="btn sm" onClick={onCree}>
                  Retour à la liste
                </button>
              </div>
            </div>
          )}

          {/* ── NAVIGATION ─────────────────────────────────────────────────────────────────────── */}
          <div className="row" style={{ justifyContent: 'space-between', gap: 'var(--esp-serre)', flexWrap: 'wrap' }}>
            <button type="button" className="btn" onClick={onAnnuler} disabled={envoi}>
              Annuler
            </button>
            <div className="row" style={{ gap: 'var(--esp-serre)' }}>
              {etape > 1 && (
                <button type="button" className="btn" onClick={allerPrecedent} disabled={envoi}>
                  ← Précédent
                </button>
              )}
              {etape < 3 ? (
                <button type="button" className="btn primary" onClick={allerSuivant}>
                  Suivant →
                </button>
              ) : (
                <button type="button" className="btn primary" onClick={soumettre} disabled={envoi}>
                  {envoi ? 'Souscription…' : "Souscrire l'abonnement"}
                </button>
              )}
            </div>
          </div>
        </div>
      </div>

      {picker && (
        <ClientPicker
          open
          titre={picker === 'payeur' ? 'Payeur' : 'Adhérent'}
          avecCreation
          onClose={() => setPicker(null)}
          onSelect={(c) => {
            if (picker === 'payeur') setPayeur(c)
            else setAdherent(c)
            setPicker(null)
          }}
        />
      )}
    </div>
  )
}
