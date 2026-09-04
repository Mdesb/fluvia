import { useCallback, useEffect, useState } from 'react'
import IdentiteVendeur from '../components/IdentiteVendeur.jsx'
import ParametresFacturation from '../components/ParametresFacturation.jsx'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { useEtatUrl } from '../api/url.js'
import { api, membres, ApiError } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import DevisModal from '../components/DevisModal.jsx'
import FactureRendu from '../components/FactureRendu.jsx'
import { mot } from '../api/vocabulaire.js'
import { euros } from '../api/produit.js'
import { idDe } from '../api/iri'

const NATURE_BADGE = { quote: 'info', sales_order: 'warn', delivery_note: 'mut' }
const STATUT_BADGE = {
  draft: 'mut',
  issued: 'info',
  accepted: 'good',
  rejected: 'crit',
  expired: 'crit',
  converted: 'good',
  cancelled: 'mut',
}

const LIBELLE_GESTE = {
  issue: 'Émettre',
  accept: 'Accepter',
  reject: 'Refuser',
  derive: 'Transformer',
  invoice: 'Facturer',
}

// `invoice` engage la facturation directe : il exige `facturation.emettre_directe` là où les quatre
// autres se contentent de `facturation.gerer`. Ne pas déduire le bouton du droit qui affiche la ligne.
const DROIT_GESTE = {
  issue: 'facturation.gerer',
  accept: 'facturation.gerer',
  reject: 'facturation.gerer',
  derive: 'facturation.gerer',
  invoice: 'facturation.emettre_directe',
}

// FACTURATION — L'ÉCRAN NE MONTRAIT PAS DE FACTURES.
//
// Maxime, devant cet écran : « Cette partie va pas du tout mais je sais pas quoi faire. Ça devrait
// être simple. »
//
// Il montrait des PIÈCES COMMERCIALES et enseignait une chaîne — devis → bon de commande → bon de
// livraison → facture — avec pour seule action « + Nouveau devis ». On cliquait donc sur
// « Facturation » et on se voyait proposer un devis.
//
// C'est une chaîne d'ERP imposée à des gens qui n'en ont pas besoin : une piscine facture une école
// pour une sortie de groupe, un club de padel facture une entreprise pour un tournoi. Ni devis, ni
// bon de livraison. Et surtout : **une facture émise sortait de l'écran et n'était plus visible
// nulle part.** Dix opérations exposées sur `Facture`, zéro route dans `client.js`. Il n'existait
// aucune liste des factures, donc aucun moyen de savoir qui doit combien.
//
// L'ÉCRAN EST RENVERSÉ : la facture au centre, le devis en option.
//
//   Onglet « Factures »  ce qu'un exploitant regarde tous les jours — émises, réglées, en retard
//   Onglet « Devis »     pour ceux qui vendent avant de facturer ; les autres ne le voient jamais
//
// QUATRE DROITS DISTINCTS, DONC QUATRE BOUTONS. Émettre (`facturation.emettre_directe`), encaisser
// un règlement (`facturation.lettrer`), faire un avoir (`facturation.avoir`), déposer sur Chorus
// (`facturation.deposer_chorus`). Qui encaisse n'a pas à pouvoir annuler la facture par un avoir.
// Même raisonnement que « Modifier la comptabilité » sur la fiche produit.
//
// LES IMPAYÉS RESTENT CHEZ RECOUVREMENT — arbitré par Maxime : « les deux, avec un lien ». Le retard
// se LIT ici, parce que c'est ici qu'on regarde qui doit combien ; il s'AGIT dans Recouvrement, qui
// porte les relances et le blocage d'accès. L'information reste, l'action déménage.
const STATUT_FACTURE = {
  brouillon: { libelle: 'Brouillon', ton: 'mut' },
  emise: { libelle: 'Émise', ton: 'info' },
  en_attente_paiement: { libelle: 'En attente de paiement', ton: 'warn' },
  partiellement_reglee: { libelle: 'Partiellement réglée', ton: 'warn' },
  payee: { libelle: 'Payée', ton: 'good' },
  acquittee: { libelle: 'Acquittée', ton: 'good' },
  echue: { libelle: 'Échue', ton: 'crit' },
}

const DEFAUTS = { tab: 'factures', statut: '' }

function jours(depuis) {
  const d = new Date(depuis)
  if (Number.isNaN(d.getTime())) return null
  return Math.floor((Date.now() - d.getTime()) / 86400000)
}

function dateCourte(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('fr-FR')
}

// Le nom du destinataire, quelle que soit sa forme. `DestinataireFacturation` porte soit une raison
// sociale, soit un nom et un prénom — jamais les deux à la fois.
function nomDestinataire(d) {
  if (!d) return null
  return d.raisonSociale || [d.prenom, d.nom].filter(Boolean).join(' ').trim() || null
}

export default function Facturation({ etabActif, droits, onNaviguer }) {
  const [params, majParams] = useEtatUrl('facturation', DEFAUTS)

  const [factures, setFactures] = useState([])
  const [pieces, setPieces] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [enCours, setEnCours] = useState(null)

  const [nouveauDevis, setNouveauDevis] = useState(false)
  const [nouvelleFacture, setNouvelleFacture] = useState(false)
  const [reglementPour, setReglementPour] = useState(null)
  // La facture dont on regarde le document. Un brouillon n'a pas de numero et ne se remet pas :
  // le bouton n'apparait donc que sur une facture emise.
  const [documentPour, setDocumentPour] = useState(null)
  // Le rapport d'intégrité : `null` tant qu'on n'a pas demandé, jamais un état par défaut.
  const [chaine, setChaine] = useState(null)
  const [chaineEnCours, setChaineEnCours] = useState(false)
  const [brouillonEdite, setBrouillonEdite] = useState(null)

  const peutGerer = aLeDroit(droits, 'facturation.gerer')
  const peutEmettre = aLeDroit(droits, 'facturation.emettre_directe')
  const peutLettrer = aLeDroit(droits, 'facturation.lettrer')
  const peutAvoir = aLeDroit(droits, 'facturation.avoir')
  const peutChorus = aLeDroit(droits, 'facturation.deposer_chorus')

  const recharger = useCallback(async () => {
    setChargement(true)
    // Deux lectures, deux droits : `facturation.lire` porte les deux, mais une collection en échec
    // ne doit pas vider l'autre onglet.
    const [f, p] = await Promise.allSettled([
      api.factures({ statut: params.statut || '', 'order[creeLe]': 'desc' }),
      api.piecesCommerciales(),
    ])
    // ⚠ `null` = PAS LU · `[]` = LU ET VIDE. Le rejet etait DEJA connu ici -- il alimente
    // `setErreur` juste en dessous -- et l'information etait perdue en le convertissant en `[]`.
    // Consequence : trois indicateurs d'argent a zero (<< Reste du 0,00 € >>, << Factures impayees
    // 0 >>, << Dont en retard 0 >>) et << Aucune facture >>, au-dessus du bandeau qui dit que la
    // lecture a echoue. Un exploitant qui lit << 0 impayee >> ferme l'ecran.
    setFactures(f.status === 'fulfilled' ? membres(f.value) : null)
    setPieces(p.status === 'fulfilled' ? membres(p.value) : null)
    setErreur(f.status === 'rejected' ? (f.reason?.message || 'Chargement des factures impossible.') : null)
    setChargement(false)
  }, [params.statut])

  useEffect(() => { recharger() }, [etabActif, recharger])

  async function agir(fn, message) {
    setErreur(null)
    setSucces(null)
    try {
      await fn()
      setSucces(message)
      await recharger()
    } catch (e) {
      // Le message du serveur tel quel : c'est lui qui sait pourquoi il refuse.
      setErreur(e instanceof ApiError ? e.message : "L'action n'a pas abouti.")
    }
  }

  async function faire(piece, geste) {
    const id = idDe(piece)
    setEnCours(`${id}:${geste}`)
    try {
      await api.gestePiece(id, geste)
      setErreur(null)
      await recharger()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Action impossible.')
    } finally {
      setEnCours(null)
    }
  }

  // UN BROUILLON N'EST DU PAR PERSONNE, ET LE COMPTER SERAIT UNE FAUSSE ALERTE.
  //
  // Constate en creant la premiere facture : le brouillon partait dans << reste du >>, dans
  // << impayees >> et dans << en retard depuis 44 jours >>. Une facture non emise n'a pas ete
  // envoyee : nul ne la doit, et son echeance ne court pas. C'est le meme defaut que la jauge du
  // musee qui criait la saturation sur une salle vide -- une alarme qui se declenche sans cause
  // apprend a ignorer l'alarme.
  const emises = (factures || []).filter((f) => f.statut !== 'brouillon')
  const impayees = emises.filter((f) => Number(f.soldeDu || 0) > 0)
  const enRetard = impayees.filter((f) => f.dateEcheance && jours(f.dateEcheance) > 0)
  const duTotal = impayees.reduce((s, f) => s + Number(f.soldeDu || 0), 0)

  return (
    <div className="view large">
      <div className="view-head">
        <div className="ttl">
          <h1>Facturation</h1>
          <p>Ce qu’on vous doit, et ce qui reste à émettre</p>
        </div>
        <div className="actions">
          {params.tab === 'factures' && peutEmettre && (
            <button className="btn primary" type="button" onClick={() => setNouvelleFacture(true)}>
              Facturer un client
            </button>
          )}
          {params.tab === 'devis' && peutGerer && (
            <button className="btn primary" type="button" onClick={() => setNouveauDevis(true)}>
              + Nouveau devis
            </button>
          )}
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <Tabs
        onglets={[
          ['factures', `Factures${factures?.length ? ` (${factures.length})` : ''}`],
          ['devis', `Devis & pièces${pieces?.length ? ` (${pieces.length})` : ''}`],
          // Le paramétrage vit ici et non dans Paramètres : ce qu'il règle — délai, pénalités,
          // mentions — ne se comprend qu'en regardant une facture. On le met à côté de ce qu'il
          // gouverne, pas dans un hub d'administration où personne ne le cherche.
          ['reglages', 'Paramétrage'],
        ]}
        actif={params.tab}
        onChange={(v) => majParams({ tab: v })}
      />

      {params.tab === 'reglages' ? (
        <>
          {/* ⚠ L'IDENTITE AVANT LE PARAMETRAGE, ET CE N'EST PAS UN ORDRE ESTHETIQUE.
              Sans raison sociale ni SIREN, aucune facture ne part au format electronique — les
              conditions de reglement et les comptes par defaut sont des reglages de confort a cote.
              Ce qui bloque doit se voir en premier. */}
          <IdentiteVendeur peutModifier={peutGerer} />
          <ParametresFacturation peutModifier={peutGerer} />
        </>
      ) : params.tab === 'factures' ? (
        <>
          {/* Trois chiffres bornés, qui appellent une décision : ils restent sur la page.
              La liste, elle, peut grandir sans limite — d'où le tableau filtrable en dessous. */}
          <div className="grid g4" style={{ marginBottom: 16 }}>
            <div className="kpi">
              <div className="lbl">Reste dû</div>
              {/* ⚠ `euros(0)` rend << 0,00 € >>, ce qui se lit << tout est encaisse >>. Sur une
                  lecture refusee, c'est la phrase la plus couteuse de cet ecran. */}
              <div className="val">{factures === null ? '—' : euros(duTotal)}</div>
            </div>
            <div className="kpi">
              <div className="lbl">Factures impayées</div>
              <div className="val">{factures === null ? '—' : impayees.length}</div>
            </div>
            <div className="kpi">
              <div className="lbl">Dont en retard</div>
              <div className="val" style={{ color: enRetard.length ? 'var(--crit)' : undefined }}>
                {factures === null ? '—' : enRetard.length}
              </div>
            </div>
          </div>

          {/* LE RETARD SE LIT ICI, IL S'AGIT AILLEURS. Arbitrage de Maxime : les deux écrans
              restent, on ajoute le passage de l'un à l'autre. */}
          {enRetard.length > 0 && (
            <div className="banner banner-warn">
              {enRetard.length} facture(s) en retard, {euros(enRetard.reduce((s, f) => s + Number(f.soldeDu || 0), 0))}{' '}
              non réglés. Les relances et le blocage d’accès se pilotent depuis Recouvrement.
              {onNaviguer && (
                <button className="btn sm" style={{ marginLeft: 10 }} type="button" onClick={() => onNaviguer('recouvrement')}>
                  Ouvrir Recouvrement
                </button>
              )}
            </div>
          )}

          <section className="card">
            <div className="card-h">
              <h3>Factures</h3>
              <div className="actions" style={{ marginLeft: 'auto' }}>
                <select
                  className="input sm"
                  aria-label="Filtrer par statut"
                  value={params.statut}
                  onChange={(e) => majParams({ statut: e.target.value })}
                >
                  <option value="">Tous les statuts</option>
                  {Object.entries(STATUT_FACTURE).map(([v, s]) => (
                    <option key={v} value={v}>{s.libelle}</option>
                  ))}
                </select>
              </div>
            </div>
            <div className="card-b" style={{ overflowX: 'auto' }}>
              {chargement ? (
                <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
              ) : factures === null ? (
                <div className="banner banner-error">
                  La liste des factures n’a pas pu être lue. <b>N’en concluez rien sur ce qui reste
                  dû</b>&nbsp;: ni le tableau ci-dessous ni les compteurs ci-dessus n’ont été obtenus.
                </div>
              ) : factures.length === 0 ? (
                // §9.1 des conventions : on dit ce qu'EST la chose, et comment elle vient à exister.
                <div className="empty">
                  {params.statut
                    ? 'Aucune facture dans ce statut.'
                    : 'Aucune facture. Une facture, c’est ce que vous envoyez à quelqu’un qui doit vous payer — '
                      + 'une école pour une sortie de groupe, une entreprise pour un tournoi. '
                      + '« Facturer un client » en rédige une en brouillon ; elle ne prend son numéro qu’à l’émission.'}
                </div>
              ) : (
                <table className="tbl">
                  <thead>
                    <tr>
                      <th>Facture</th>
                      <th>Destinataire</th>
                      <th>Échéance</th>
                      <th className="num">Total TTC</th>
                      <th className="num">Reste dû</th>
                      <th>Statut</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {(factures || []).map((f) => {
                      const st = STATUT_FACTURE[f.statut] || { libelle: f.statut, ton: 'mut' }
                      const solde = Number(f.soldeDu || 0)
                      const brouillon = f.statut === 'brouillon'
                      const retard = !brouillon && f.dateEcheance && solde > 0 ? jours(f.dateEcheance) : null
                      return (
                        <tr key={f.id}>
                          <td>
                            <span className="nm mono">{f.numero || 'sans numéro'}</span>
                            <div className="sub">
                              {brouillon ? 'pas encore émise' : `émise le ${dateCourte(f.dateEmission)}`}
                            </div>
                          </td>
                          <td>{nomDestinataire(f.destinataire) || <span className="sub">sans destinataire</span>}</td>
                          <td>
                            {dateCourte(f.dateEcheance)}
                            {retard > 0 && (
                              <div className="sub" style={{ color: 'var(--crit)' }}>
                                en retard depuis {retard} jour{retard > 1 ? 's' : ''}
                              </div>
                            )}
                          </td>
                          <td className="num">{euros(f.totalTTC)}</td>
                          <td className="num">
                            {brouillon
                              ? <span className="sub">non émise</span>
                              : solde > 0 ? <b>{euros(solde)}</b> : <span className="sub">soldée</span>}
                          </td>
                          <td><span className={`badge ${st.ton}`}>{st.libelle}</span></td>
                          <td className="row actions" style={{ justifyContent: 'flex-end', gap: 6 }}>
                            {peutEmettre && brouillon && (
                              <>
                                {/* UN BROUILLON ERRONE ETAIT DEFINITIF. `Facture` n'expose aucune
                                    suppression -- et c'est voulu, la serie des numeros ne se troue
                                    pas. Sans correction possible, une facture mal saisie serait
                                    restee dans la liste pour toujours. */}
                                <button className="btn sm" type="button" onClick={() => setBrouillonEdite(f)}>
                                  Corriger
                                </button>
                                <button
                                  className="btn sm"
                                  type="button"
                                  title="Consomme un numéro et rend la facture inaltérable."
                                  onClick={() => agir(() => api.emettreFacture(f.id), `Facture ${f.numero || ''} émise.`)}
                                >
                                  Émettre
                                </button>
                              </>
                            )}
                            {/* VOIR LA FACTURE — le geste qui manquait pour qu'elle serve.
                                Sur une facture EMISE seulement : un brouillon n'a pas de numero,
                                et remettre un document sans numero serait pire que ne rien
                                remettre. */}
                            {!brouillon && (
                              <button
                                className="btn sm"
                                type="button"
                                title="Affiche le document légal, imprimable ou enregistrable en PDF."
                                onClick={() => setDocumentPour(f)}
                              >
                                Voir
                              </button>
                            )}
                            {peutLettrer && !brouillon && solde > 0 && (
                              <button className="btn sm" type="button" onClick={() => setReglementPour(f)}>
                                Encaisser
                              </button>
                            )}
                            {peutAvoir && !brouillon && (
                              <button
                                className="btn sm"
                                type="button"
                                title="Émet un avoir total. Une facture ne se supprime pas : elle s'annule par un avoir."
                                onClick={() => {
                                  if (!window.confirm(
                                    `Émettre un avoir total sur la facture ${f.numero || ''} ?\n\n`
                                    + "Une facture émise est inaltérable : l'avoir est la seule façon de l'annuler, "
                                    + 'et il laisse les deux pièces dans la série.',
                                  )) return
                                  agir(() => api.genererAvoirFacture(f.id), 'Avoir émis.')
                                }}
                              >
                                Avoir
                              </button>
                            )}
                            {peutChorus && !brouillon && f.destinataire?.estOrganismePublic && (
                              <button
                                className="btn sm"
                                type="button"
                                title="Dépose la facture sur Chorus Pro. Obligatoire pour un donneur d'ordre public."
                                onClick={() => agir(() => api.deposerFactureChorus(f.id, {}), 'Déposée sur Chorus Pro.')}
                              >
                                Chorus
                              </button>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              )}
            </div>
          </section>
        </>
      ) : (
        <section className="card">
          <div className="card-h">
            <h3>Devis &amp; pièces commerciales</h3>
            <span className="sub">{pieces === null ? '—' : `${pieces.length} en cours`}</span>
          </div>
          <div className="card-b" style={{ overflowX: 'auto' }}>
            {chargement ? (
              <div className="empty">Chargement…</div>
            ) : pieces === null ? (
              <div className="banner banner-error">
                Les devis et pièces commerciales n’ont pas pu être lus&nbsp;: ce tableau est vide
                parce que la lecture a échoué.
              </div>
            ) : pieces.length === 0 ? (
              <div className="empty">
                <p>Aucune pièce commerciale.</p>
                {/* L'explication de la chaîne a été DÉPLACÉE ici, pas supprimée : elle est juste, et
                    elle justifie une chaîne à qui en veut une. Sur l'écran d'accueil, elle
                    l'imposait à qui n'en avait pas demandé. */}
                <p className="hint">
                  Un devis propose un prix à un client avant qu&apos;il s&apos;engage. Une fois accepté,
                  il se transforme en bon de commande puis en facture — sans que personne ne ressaisisse
                  les lignes, ce qui est exactement là où naissent les écarts. Si vous facturez sans
                  devis, l&apos;onglet « Factures » suffit.
                </p>
              </div>
            ) : (
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Pièce</th>
                    <th>Destinataire</th>
                    <th className="num">Total TTC</th>
                    <th>Statut</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {(pieces || []).map((p) => (
                    <LignePiece
                      key={idDe(p)}
                      piece={p}
                      droits={droits}
                      enCours={enCours}
                      onGeste={faire}
                    />
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>
      )}

      <DevisModal
        open={nouveauDevis}
        onClose={() => setNouveauDevis(false)}
        onCree={() => { setNouveauDevis(false); recharger() }}
      />

      <DevisModal
        open={nouvelleFacture}
        cible="facture"
        onClose={() => setNouvelleFacture(false)}
        onCree={() => {
          setNouvelleFacture(false)
          setSucces('Brouillon de facture créé. Il ne prendra son numéro qu’à l’émission.')
          recharger()
        }}
      />

      <DevisModal
        open={!!brouillonEdite}
        cible="facture"
        existante={brouillonEdite}
        onClose={() => setBrouillonEdite(null)}
        onCree={() => { setBrouillonEdite(null); setSucces('Brouillon corrigé.'); recharger() }}
      />

      <ReglementModal
        facture={reglementPour}
        onClose={() => setReglementPour(null)}
        onFait={() => { setReglementPour(null); recharger() }}
      />

      {/* Montee seulement quand une facture est choisie : le composant lit le document a
          l'ouverture, et le monter en permanence declencherait une lecture par rendu. */}
      {documentPour && (
        <FactureRendu facture={documentPour} onClose={() => setDocumentPour(null)} />
      )}

      {/* L'INTÉGRITÉ DE LA CHAÎNE — ce qu'on montre à un expert-comptable ou à un contrôle.
          La chaîne des ÉCRITURES était déjà vérifiable depuis Comptabilité ; celle des FACTURES
          répondait sans que rien ne l'appelle. Deux chaînes scellées, une seule qu'on pouvait
          prouver. */}
      <section className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
        <div className="card-h">
          <h3>Intégrité de la chaîne des factures</h3>
          <span className="sub">contrôle NF525</span>
          <div className="r">
            <button
              className="btn"
              type="button"
              disabled={chaineEnCours}
              onClick={async () => {
                setChaineEnCours(true)
                setChaine(null)
                try {
                  setChaine(await api.verifierChaineFactures())
                } catch (e) {
                  // ⚠ UN CONTRÔLE QUI ÉCHOUE N'EST PAS UN CONTRÔLE QUI PASSE. On ne rend surtout
                  // pas un rapport vide, qui se lirait « rien à signaler ».
                  setChaine({ __echec: e.message || 'Le contrôle n’a pas pu être exécuté.' })
                } finally {
                  setChaineEnCours(false)
                }
              }}
            >
              {chaineEnCours ? 'Vérification…' : 'Vérifier la chaîne'}
            </button>
          </div>
        </div>
        <div className="card-b">
          <div className="hint" style={{ marginTop: 0 }}>
            Chaque facture émise est scellée et chaînée à la précédente. Ce contrôle recalcule la
            chaîne&nbsp;: il détecte un trou de séquence, un chaînage rompu ou une donnée altérée
            après coup. À lancer avant une clôture, et lors d’un contrôle.
          </div>

          {chaine?.__echec ? (
            <div className="banner banner-error">
              <b>Le contrôle n’a pas pu être exécuté.</b> Ce n’est pas un résultat&nbsp;:
              n’en concluez rien sur l’état de la chaîne.
              <div className="sub">{chaine.__echec}</div>
            </div>
          ) : chaine?.intacte ? (
            <div className="banner banner-ok">
              Chaîne intacte&nbsp;: {chaine.nbDocuments} document
              {chaine.nbDocuments > 1 ? 's' : ''} vérifié{chaine.nbDocuments > 1 ? 's' : ''},
              aucune anomalie.
            </div>
          ) : chaine ? (
            <>
              <div className="banner banner-error">
                <b>{(chaine.anomalies || []).length} anomalie
                {(chaine.anomalies || []).length > 1 ? 's' : ''} sur la chaîne des factures.</b>{' '}
                Une chaîne rompue signifie qu’une facture scellée a été modifiée, supprimée ou
                insérée après coup. Ce n’est pas un incident d’affichage&nbsp;: conservez ce
                rapport et faites-le remonter.
              </div>
              {(chaine.anomalies || []).length > 0 && (
                <table className="tbl">
                  <thead>
                    <tr><th>Document</th><th>Problème</th></tr>
                  </thead>
                  <tbody>
                    {/* ⚠ ON AFFICHE LE LIBELLÉ DU SERVEUR, JAMAIS UNE REFORMULATION. Le serveur
                        type ses anomalies — trou de séquence, chaînage rompu, empreinte
                        incohérente, signature invalide. Deux formulations d'une même anomalie
                        divergeraient le jour où l'une des deux évolue. */}
                    {(chaine.anomalies || []).map((a, i) => (
                      <tr key={i}>
                        <td>{a.numero || a.sequence || a.document || '—'}</td>
                        <td>{a.probleme || a.message || JSON.stringify(a)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </>
          ) : null}
        </div>
      </section>
    </div>
  )
}

// ENCAISSER UN RÈGLEMENT — le geste qui vient APRÈS la facture, et qui n'existait nulle part.
//
// `POST /factures/{id}/reglements` attend { montant, moyen, reference? }. Le montant est proposé au
// solde restant : c'est le cas courant, et le pré-remplir évite de retaper une somme qu'on vient de
// lire à l'écran. Un acompte se saisit en corrigeant.
function ReglementModal({ facture, onClose, onFait }) {
  const [montant, setMontant] = useState('')
  const [moyen, setMoyen] = useState('')
  const [reference, setReference] = useState('')
  const [moyens, setMoyens] = useState([])
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => {
    if (!facture) return undefined
    setMontant(String(facture.soldeDu || ''))
    setReference('')
    setErreur(null)
    let annule = false
    api.moyensPaiement()
      .then((r) => {
        if (annule) return
        const actifs = membres(r).filter((m) => m.actif !== false)
        setMoyens(actifs)
        setMoyen((prec) => prec || actifs[0]?.code || '')
      })
      .catch(() => { if (!annule) setMoyens([]) })
    return () => { annule = true }
  }, [facture])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setEnvoi(true)
    try {
      await api.enregistrerReglement(facture.id, {
        montant: String(montant),
        moyen,
        ...(reference.trim() ? { reference: reference.trim() } : {}),
      })
      onFait()
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : "Le règlement n'a pas pu être enregistré.")
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={!!facture} onClose={onClose} titre="Encaisser un règlement">
      {facture && (
        <form onSubmit={soumettre}>
          {erreur && <div className="banner banner-error">{erreur}</div>}

          <div className="banner banner-info">
            Facture <b className="mono">{facture.numero || 'sans numéro'}</b> ·{' '}
            {nomDestinataire(facture.destinataire) || 'sans destinataire'} · reste dû{' '}
            <b>{euros(facture.soldeDu)}</b> sur {euros(facture.totalTTC)}.
          </div>

          <div className="field">
            <label htmlFor="rg-montant">Montant encaissé *</label>
            <input
              id="rg-montant"
              className="input"
              type="number"
              step="0.01"
              min="0"
              required
              value={montant}
              onChange={(e) => setMontant(e.target.value)}
            />
            <p className="hint">
              Proposé au solde restant. Corrigez pour un acompte : la facture passera alors en
              « partiellement réglée » plutôt qu’en « payée ».
            </p>
          </div>

          <div className="field">
            <label htmlFor="rg-moyen">Moyen *</label>
            <select id="rg-moyen" className="input" required value={moyen} onChange={(e) => setMoyen(e.target.value)}>
              {moyens.length === 0 && <option value="">Aucun moyen déclaré</option>}
              {moyens.map((m) => (
                <option key={m.id} value={m.code}>{m.libelle || m.code}</option>
              ))}
            </select>
            <p className="hint">
              Les moyens viennent du référentiel de l’établissement, réglable dans Paramètres ›
              Caisse &amp; moyens de paiement.
            </p>
          </div>

          <div className="field">
            <label htmlFor="rg-ref">Référence</label>
            <input
              id="rg-ref"
              className="input"
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              placeholder="N° de chèque, référence de virement…"
            />
            <p className="hint">
              Ce qui permettra de retrouver ce règlement sur le relevé bancaire le jour où le client
              conteste.
            </p>
          </div>

          <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
            <button type="button" className="btn" onClick={onClose}>Annuler</button>
            <button type="submit" className="btn primary" disabled={envoi || !moyen}>
              {envoi ? 'Enregistrement…' : 'Enregistrer le règlement'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}

function LignePiece({ piece, droits, enCours, onGeste }) {
  const id = idDe(piece)
  const gestes = piece.gestesPossibles || []

  return (
    <tr>
      <td>
        <span className={`badge ${NATURE_BADGE[piece.nature] || 'mut'}`}>{mot(piece.nature)}</span>{' '}
        <span className="mono">{piece.numero || '—'}</span>
      </td>
      <td className="nm">{piece.destinataire?.raisonSociale || piece.destinataire?.nom || '—'}</td>
      <td className="num">{euros(piece.totalTTC)}</td>
      <td>
        <span className={`badge ${STATUT_BADGE[piece.statut] || 'mut'}`}>{mot(piece.statut)}</span>
      </td>
      <td>
        {gestes.length === 0 ? (
          <span className="hint">—</span>
        ) : (
          gestes.map((g) => (
            <BoutonGeste
              key={g}
              geste={g}
              droits={droits}
              occupe={enCours === `${id}:${g}`}
              onClick={() => onGeste(piece, g)}
            />
          ))
        )}
      </td>
    </tr>
  )
}

// Un geste que l'état n'autorise pas est ABSENT — il ne figure pas dans `gestesPossibles`. Un geste
// autorisé mais dont l'utilisateur n'a pas le droit reste VISIBLE et désactivé (D54) : le cacher
// ferait croire qu'il est impossible, et l'utilisateur chercherait un contournement.
//
// L'ordre des deux phrases n'est pas indifférent : d'abord POURQUOI le geste est renforcé — un fait
// sur la pièce —, ensuite si vous y avez droit — un fait sur vous. Seul le premier explique la
// situation ; commencer par le refus laisse croire à une erreur de compte.
function BoutonGeste({ geste, droits, occupe, onClick }) {
  const droitRequis = DROIT_GESTE[geste]
  const autorise = aLeDroit(droits, droitRequis)
  const renforce = 'invoice' === geste

  const titre = autorise
    ? undefined
    : renforce
      ? `Facturer émet une facture définitive, numérotée et inaltérable : ce geste exige le droit « ${droitRequis} », plus fort que celui qui permet de gérer la pièce. Vous ne l’avez pas.`
      : `Ce geste exige le droit « ${droitRequis} ». Vous ne l’avez pas.`

  return (
    <button
      type="button"
      className={`btn sm ${renforce ? 'primary' : 'ghost'}`}
      disabled={!autorise || occupe}
      title={titre}
      onClick={onClick}
    >
      {occupe ? '…' : LIBELLE_GESTE[geste] || geste}
    </button>
  )
}
