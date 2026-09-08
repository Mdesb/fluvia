import { useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { api, tokenStore, etablissementStore } from '../api/client.js'

/**
 * LA FACTURE, ENFIN REMISE À CELUI QUI DOIT LA PAYER.
 *
 * Fluvia savait tout faire d'une facture sauf la donner : la créer, la numéroter légalement, la
 * sceller, l'émettre, encaisser, l'annuler par un avoir, la déposer sur Chorus. Elle ne pouvait
 * être ni affichée, ni imprimée, ni téléchargée. `GET /factures/{id}/rendu` existait côté serveur
 * et n'était appelé par AUCUN écran — un exploitant qui facture une école pour une sortie scolaire
 * n'avait aucun moyen de lui remettre sa facture.
 *
 * ── ON N'ADDITIONNE RIEN ICI, ET C'EST LA RÈGLE PRINCIPALE ──────────────────────────────────────
 *
 * Tous les montants affichés viennent du serveur, verbatim. Le rendu porte `montantHT`,
 * `montantTva`, `montantTTC` par ligne, la ventilation par taux, et les trois totaux. Recalculer
 * quoi que ce soit depuis `tauxTva` fabriquerait un TROISIÈME chiffre : `tauxTva` est lu vivant sur
 * la fiche du taux tandis que les montants sont figés en colonne, donc après un changement de taux
 * ils divergent déjà. Signalé par `allaccess-b8`, corrigé côté serveur par `allaccess-73` dans le
 * chantier de scellement. Un écran qui recalcule aurait survécu à ce correctif en gardant tort.
 *
 * ── PAS DE BIBLIOTHÈQUE PDF ────────────────────────────────────────────────────────────────────
 *
 * L'impression du navigateur produit un PDF, ne dépend d'aucun prestataire et fonctionne le jour
 * où on la demande. Les règles d'impression sont dans `styles.css`.
 *
 * ── ET PAS DE BOUTON « ENVOYER » ───────────────────────────────────────────────────────────────
 *
 * Aucun expéditeur de courriel n'est branché dans ce dépôt. Un bouton qui ne part pas est pire
 * qu'un bouton absent : on croit avoir envoyé. L'écran le dit au lieu de l'offrir.
 */
// ── LES MENTIONS DE L'ÉMETTEUR SONT UN TABLEAU LIBRE, ET IL FAUT TOUT RENDRE ───────────────────
//
// `mentionsLegalesEmetteur` est une colonne JSON **sans schéma** sur
// `ParametreFacturationEtablissement`, écrivable par l'exploitant (`parametre_facturation:write`),
// et `FactureRenduProvider` la publie VERBATIM : ce que l'exploitant y met arrive tel quel dans
// `rendu.emetteur`. Aucune liste de champs nulle part côté serveur.
//
// ⚠ CE COMPOSANT N'EN RENDAIT QUE QUATRE CLÉS CONNUES ET JETAIT LES AUTRES EN SILENCE — donc
// précisément celles que son propre avertissement déclarait manquantes. Un exploitant qui
// renseignait sa forme juridique pour faire disparaître l'avertissement ne voyait rien changer :
// l'écran décrivait un défaut qu'il rendait lui-même incorrigible.
//
// Désormais les clés connues gardent leur libellé et leur ordre, les inconnues sont rendues quand
// même, et l'avertissement est CALCULÉ sur ce qui est réellement présent.

/** Clés déjà rendues par leur propre balisage plus haut : nom, adresse, lignes SIRET et TVA. */
const EMETTEUR_RENDU_A_PART = ['denomination', 'adresse', 'siret', 'tvaIntra']

// ⚠ `attendue` dit « son absence se signale », PAS « le lexique la connaît ». Le code APE se rend
//    proprement s'il est là, mais ne pas l'avoir n'est pas un défaut : l'annoncer manquant
//    inventerait une obligation légale que personne n'a mesurée. Les trois autres viennent du
//    relevé fait sur ce document le 31/08.
const EMETTEUR_MENTIONS = [
  { cle: 'formeJuridique', libelle: 'Forme juridique', phrase: 'forme juridique', attendue: true },
  { cle: 'capitalSocial', libelle: 'Capital social', phrase: 'capital social', attendue: true },
  { cle: 'rcs', libelle: 'RCS', phrase: 'numéro RCS', attendue: true },
  { cle: 'ape', libelle: 'APE', phrase: 'code APE', attendue: false },
]

// ── UN SIRET FAIT QUATORZE CHIFFRES, UN SIREN NEUF ────────────────────────────────────────────
//
// Les neuf premiers sont le SIREN, qui désigne l'ENTREPRISE. Les cinq suivants sont le NIC, qui
// désigne l'ÉTABLISSEMENT — c'est-à-dire lequel de ses sites facture. C'est le SIRET complet qui
// est la mention exigée sur une facture française.
//
// ⚠ ET LA SUBSTITUTION EST INVISIBLE À TOUT CE QUI VÉRIFIE. Mesuré le 01/09 sur `/rendu` :
//
//       la veille   "siret": "13002526500012"    14 chiffres
//       le lendemain "siret": "130025265"          9 chiffres
//
// Le champ était toujours présent, toujours non vide, toujours numérique. Aucun test ne tombe,
// aucun garde-fou ne parle, le build passe — et le document affirme « SIRET » sous un numéro qui
// n'en est pas un. Un écran qui pose une mention légale sans en vérifier la forme la CERTIFIE.
function formeIdentifiant(v) {
  const chiffres = String(v ?? '').replace(/\D/g, '')
  if (chiffres.length === 14) return { libelle: 'SIRET', complet: true }
  if (chiffres.length === 9) return { libelle: 'SIREN', complet: false, siren: true }
  return { libelle: 'Identifiant', complet: false }
}

/** Une valeur qu'on peut poser telle quelle sur le document. On n'additionne, ne formate et
 *  n'unifie rien ici : un capital social arrive comme le serveur le donne. */
const imprimable = (v) => v !== null && v !== undefined && v !== '' && typeof v !== 'object'

/** `numeroRcs` → « Numero rcs ». Approximatif pour une clé qu'on ne connaît pas, et c'est le but :
 *  un libellé maladroit se corrige en le lisant, une mention disparue ne se voit jamais. */
const humaniser = (cle) => {
  const mots = String(cle).replace(/([A-Z])/g, ' $1').trim()
  return mots.charAt(0).toUpperCase() + mots.slice(1).toLowerCase()
}

function lireEmetteur(emetteur) {
  const e = emetteur || {}
  const connues = EMETTEUR_MENTIONS.map((m) => m.cle)

  const lignes = EMETTEUR_MENTIONS
    .filter((m) => imprimable(e[m.cle]))
    .map((m) => ({ cle: m.cle, libelle: m.libelle, valeur: String(e[m.cle]) }))

  const autres = Object.keys(e).filter(
    (c) => !EMETTEUR_RENDU_A_PART.includes(c) && !connues.includes(c),
  )

  autres.filter((c) => imprimable(e[c])).forEach((c) => {
    lignes.push({ cle: c, libelle: humaniser(c), valeur: String(e[c]) })
  })

  const identifiant = formeIdentifiant(e.siret)

  return {
    lignes,
    identifiant,
    absentes: [
      ...EMETTEUR_MENTIONS.filter((m) => m.attendue && !imprimable(e[m.cle])).map((m) => m.phrase),
      // ⚠ Le SIRET n'est pas « absent » au sens du tableau libre : la clé existe et porte une
      //    valeur. Il est absent au sens qui compte — le document n'en porte pas un.
      ...(identifiant.complet
        ? []
        : [identifiant.siren
          ? 'numéro SIRET (le document porte un SIREN, qui désigne l’entreprise et non l’établissement)'
          : 'numéro SIRET']),
    ],
    // ⚠ Une valeur non primitive ne se pose pas sur le document, mais on ne la fait pas
    //    disparaître pour autant : on la NOMME à l'écran. Muet est le seul état interdit.
    nonRendues: autres.filter((c) => !imprimable(e[c]) && e[c] !== null && e[c] !== undefined && e[c] !== ''),
  }
}

export default function FactureRendu({ facture, onClose }) {
  const [rendu, setRendu] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [tele, setTele] = useState(null) // null | 'en_cours' | message d'erreur

  // Télécharge le Factur-X (PDF/A-3 opposable). Binaire + jeton porteur -> fetch manuel puis blob,
  // comme le téléchargement DMS. Un 422 = facture incomplète : le serveur NOMME le terme manquant.
  async function telechargerFacturX() {
    setTele('en_cours')
    try {
      const reponse = await fetch(api.urlFacturX(facture.id), {
        headers: {
          Authorization: `Bearer ${tokenStore.get()}`,
          'X-Etablissement': etablissementStore.get() || '',
          Accept: 'application/json',
        },
      })
      if (!reponse.ok) {
        let msg = `Téléchargement refusé (${reponse.status}).`
        try {
          const j = await reponse.json()
          msg = j?.detail || j?.['hydra:description'] || j?.message || msg
        } catch {
          /* corps non-JSON : on garde le message par statut */
        }
        throw new Error(msg)
      }
      const blob = await reponse.blob()
      const url = URL.createObjectURL(blob)
      const lien = document.createElement('a')
      lien.href = url
      lien.download = `${facture.numero || facture.id}.pdf`
      lien.click()
      URL.revokeObjectURL(url)
      setTele(null)
    } catch (e) {
      setTele(e.message || 'Le téléchargement a échoué.')
    }
  }

  useEffect(() => {
    if (!facture?.id) return undefined
    let annule = false
    setChargement(true)
    setErreur(null)
    setRendu(null)
    api
      .renduFacture(facture.id)
      .then((r) => { if (!annule) setRendu(r) })
      .catch((e) => { if (!annule) setErreur(e.message || 'Le document n’a pas pu être lu.') })
      .finally(() => { if (!annule) setChargement(false) })
    return () => { annule = true }
  }, [facture?.id])

  const emetteur = lireEmetteur(rendu?.emetteur)

  // ⚠ LE TAUX DE PÉNALITÉS N'EST PAS UNE MENTION DE L'ÉMETTEUR. Il est composé par le serveur
  //    dans `conditionsReglement`, et aucun écran ne le rédige. On le CHERCHE donc dans cette
  //    chaîne au lieu de le déclarer absent d'office : écrit en dur, l'avertissement deviendrait
  //    un mensonge le jour où le serveur le posera, et rien ne relierait les deux.
  const sansPenalites = Boolean(rendu) && !/p[ée]nalit/i.test(String(rendu?.conditionsReglement || ''))

  const dest = rendu?.destinataire
  // ⚠ `denomination()` côté serveur ne rend `raisonSociale` que si le type est « personne morale ».
  // Un destinataire typé « particulier » mais porteur d'une raison sociale sort donc SANS NOM —
  // mesuré sur FA-2026-00001, dont le client est « École municipale ». Une facture sans
  // destinataire nommé ne doit pas être remise : on le marque DANS le document, parce qu'un bloc
  // vide se remet par mégarde.
  const sansDestinataire = Boolean(rendu) && !String(dest?.denomination || '').trim()

  // ⚠ L'ADRESSE DU DESTINATAIRE EST AUSSI UNE MENTION OBLIGATOIRE, ET JE NE LA MARQUAIS PAS.
  //
  // La premiere version de cet ecran signalait un nom manquant et laissait passer une adresse
  // absente en silence -- donc un document tout aussi irrecevable, mais sans rien qui le dise.
  // Signale par `allaccess-b8`, verifie ici : sur Piscine A, les DEUX destinataires ont
  // `adresse: []`. Aucune des factures existantes ne porte l'adresse de son destinataire.
  const adresseDest = dest?.adresse
  const sansAdresse = Boolean(rendu)
    && (Array.isArray(adresseDest) ? adresseDest.filter(Boolean).length === 0 : !adresseDest)

  // ⚠ SOLDEE MAIS SANS MENTION ACQUITTEE. `mentionAcquittee` n'est pose qu'a l'emission d'une
  // facture justificative ou sur un avoir : une facture ordinaire payee par lettrage garde `false`,
  // et son document ne porte donc rien. Maxime a tranche que toute facture soldee doit porter la
  // mention, sans que la facture soit modifiee -- la deduire au rendu est du ressort du serveur.
  // En attendant, l'ecran le SIGNALE plutot que de laisser croire que le document est complet.
  const soldeeSansMention = Boolean(rendu)
    && facture?.statut === 'payee'
    && !rendu.mentionAcquittee

  return (
    <Modal open={Boolean(facture)} onClose={onClose} titre={`Facture ${facture?.numero || ''}`} taille="lg">
      {chargement ? (
        <div className="center"><div className="spinner" /></div>
      ) : erreur ? (
        <div className="banner banner-error">
          Le document n’a pas pu être lu&nbsp;: <b>ne remettez rien au client sur cette base</b>.
          <div className="sub">{erreur}</div>
        </div>
      ) : !rendu ? null : (
        <>
          {/* CE QUE LE DOCUMENT NE PORTE PAS — À L'ÉCRAN SEULEMENT.
              L'exploitant doit le savoir avant de remettre la facture ; le client, lui, n'a rien à
              faire d'une note technique sur sa facture. D'où `fact-noprint`. */}
          {(emetteur.absentes.length > 0 || sansPenalites) && (
            <div className="banner banner-warn fact-noprint">
              <b>Mentions absentes de ce document&nbsp;:</b>{' '}
              {[...emetteur.absentes, ...(sansPenalites ? ['taux de pénalités de retard'] : [])]
                .join(', ')}.
              {emetteur.absentes.length > 0 && (
                <> Les mentions de l’émetteur se renseignent dans les paramètres de facturation
                  de l’établissement, et le document les portera dès qu’elles y seront.</>
              )}
              {sansPenalites && (
                <> Le taux de pénalités, lui, ne se renseigne nulle part : il est composé par le
                  serveur avec les conditions de règlement.</>
              )}
            </div>
          )}

          {emetteur.nonRendues.length > 0 && (
            <div className="banner banner-warn fact-noprint">
              <b>Renseigné mais pas imprimable&nbsp;:</b> {emetteur.nonRendues.join(', ')}. Ces
              clés existent dans les paramètres de facturation, mais leur valeur n’est pas un
              texte simple, donc elle n’est pas posée sur le document. Elles sont nommées ici
              plutôt que passées sous silence.
            </div>
          )}

          {(sansDestinataire || sansAdresse) && (
            <div className="banner banner-error fact-noprint">
              <b>Ce document est incomplet et ne doit pas être remis en l’état&nbsp;:</b>{' '}
              {[
                sansDestinataire ? 'le destinataire n’est pas nommé' : null,
                sansAdresse ? 'son adresse est absente' : null,
              ].filter(Boolean).join(', ')}. Une facture doit désigner qui doit payer et où&nbsp;:
              corrigez la fiche du destinataire, puis rouvrez ce document.
            </div>
          )}

          {soldeeSansMention && (
            <div className="banner banner-warn fact-noprint">
              Cette facture est <b>soldée</b>, mais le document ne porte pas la mention
              «&nbsp;acquittée&nbsp;». Le serveur ne la pose qu’à l’émission d’une facture
              justificative ou sur un avoir&nbsp;; un règlement encaissé ensuite ne la déclenche
              pas. Si vous remettez ce document comme preuve de paiement, elle y manquera.
            </div>
          )}

          <div className="fact-doc doc-imprimer">
            <div className="fact-tete">
              <div className="fact-bloc">
                <div className="fact-bloc-titre">Émetteur</div>
                <div className="fact-nom">{rendu.emetteur?.denomination || '—'}</div>
                <AdresseBloc adresse={rendu.emetteur?.adresse} />
                {/* ⚠ LE LIBELLÉ SUIT LA VALEUR, IL NE LA DÉCRÈTE PAS. Écrire « SIRET » devant neuf
                    chiffres, c'est le document lui-même qui ment — et il est opposable. */}
                {rendu.emetteur?.siret && (
                  <div className="fact-ligne-info">
                    {emetteur.identifiant.libelle} {rendu.emetteur.siret}
                  </div>
                )}
                {rendu.emetteur?.tvaIntra && <div className="fact-ligne-info">TVA {rendu.emetteur.tvaIntra}</div>}
                {/* Tout le reste du tableau libre, connu ou non. Une clé que ce fichier n’a
                    jamais vue s’imprime avec un libellé approximatif plutôt que de disparaître. */}
                {emetteur.lignes.map((m) => (
                  <div key={m.cle} className="fact-ligne-info">{m.libelle} {m.valeur}</div>
                ))}
              </div>

              <div className="fact-bloc">
                <div className="fact-bloc-titre">Destinataire</div>
                <div className="fact-nom">
                  {sansDestinataire
                    ? <span className="fact-manque">[destinataire non renseigné]</span>
                    : dest.denomination}
                </div>
                {sansAdresse
                  ? <div className="fact-manque">[adresse non renseignée]</div>
                  : <AdresseBloc adresse={adresseDest} />}
                {dest?.siret && <div className="fact-ligne-info">SIRET {dest.siret}</div>}
                {dest?.tvaIntracommunautaire && (
                  <div className="fact-ligne-info">TVA {dest.tvaIntracommunautaire}</div>
                )}
              </div>
            </div>

            <h2 className="fact-titre">
              {rendu.nature === 'avoir' ? 'Avoir' : 'Facture'} n° {rendu.numero || '—'}
            </h2>
            <div className="fact-dates">
              Émise le {dateFr(rendu.dateEmission)}
              {rendu.dateEcheance ? ` · échéance le ${dateFr(rendu.dateEcheance)}` : ''}
            </div>

            <table className="fact-tbl">
              <thead>
                <tr>
                  <th>Désignation</th>
                  <th className="num">Qté</th>
                  <th className="num">P.U. HT</th>
                  <th className="num">TVA</th>
                  <th className="num">Total HT</th>
                  <th className="num">Total TTC</th>
                </tr>
              </thead>
              <tbody>
                {(rendu.lignes || []).map((l, i) => (
                  <tr key={`${l.designation}-${i}`}>
                    <td>{l.designation || '—'}</td>
                    <td className="num">{l.quantite}</td>
                    <td className="num">{euro(l.prixUnitaireHT)}</td>
                    <td className="num">{pourcent(l.tauxTva)}</td>
                    <td className="num">{euro(l.montantHT)}</td>
                    <td className="num">{euro(l.montantTTC)}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            {(rendu.ventilationTva || []).length > 0 && (
              <table className="fact-tbl">
                <thead>
                  <tr>
                    <th>Ventilation TVA</th>
                    <th className="num">Base HT</th>
                    <th className="num">Montant TVA</th>
                  </tr>
                </thead>
                <tbody>
                  {rendu.ventilationTva.map((v) => (
                    <tr key={String(v.taux)}>
                      <td>{pourcent(v.taux)}</td>
                      <td className="num">{euro(v.baseHT)}</td>
                      <td className="num">{euro(v.montantTva)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}

            <div className="fact-totaux">
              <div><span>Total HT</span><span>{euro(rendu.totalHT)}</span></div>
              <div><span>Total TVA</span><span>{euro(rendu.totalTVA)}</span></div>
              <div className="fact-ttc"><span>Total TTC</span><span>{euro(rendu.totalTTC)}</span></div>
            </div>

            {rendu.conditionsReglement && (
              <div className="fact-cond">{rendu.conditionsReglement}</div>
            )}

            {rendu.mentionAcquittee && (
              <div className="fact-acquittee">
                Facture acquittée
                {rendu.acquitteeLe ? ` le ${dateFr(rendu.acquitteeLe)}` : ''}
                {rendu.acquitteeMoyen ? ` — ${rendu.acquitteeMoyen}` : ''}
                {rendu.acquitteeReference ? ` (réf. ${rendu.acquitteeReference})` : ''}
              </div>
            )}
          </div>

          <div className="r fact-noprint">
            <span className="hint">
              L’envoi par courriel n’est pas branché dans cette version&nbsp;: imprimez le document
              ou enregistrez-le en PDF depuis la fenêtre d’impression, puis remettez-le vous-même.
            </span>
            <button className="btn ghost" type="button" onClick={onClose}>Fermer</button>
            <button className="btn" type="button" onClick={() => window.print()}>
              Imprimer
            </button>
            <button className="btn primary" type="button" onClick={telechargerFacturX} disabled={tele === 'en_cours'}>
              {tele === 'en_cours' ? 'Préparation\u2026' : 'Télécharger (Factur-X)'}
            </button>
          </div>
          {tele && tele !== 'en_cours' && (
            <div className="banner banner-error fact-noprint">{tele}</div>
          )}
        </>
      )}
    </Modal>
  )
}

// ── L'ADRESSE EST UNE COLONNE LIBRE, ELLE AUSSI ───────────────────────────────────────────────
//
// Le commentaire qui vivait ici disait « on rend ce qui est là, dans l'ordre où il est là ». C'était
// vrai de la branche TABLEAU et faux de la branche OBJET, qui piochait quatre clés fixes — `rue`,
// `cp`, `ville`, `pays`.
//
// ⚠ OR LA COLONNE EN DÉCLARE CINQ. Le docbloc de `DestinataireFacturation::$adresse` écrit
// `{rue, complement, cp, ville, pays}` : **`complement` était jeté en silence**. C'est la ligne qui
// porte « Bâtiment B », « 3e étage », « BP 42 » ou « Service facturier » — sur une facture, celle
// qui décide si le pli arrive au bon bureau. Et pour une collectivité, le service facturier est
// souvent la seule mention qui compte.
//
// Ça ne mord pas AUJOURD'HUI : mesuré le 01/09, aucune adresse n'est renseignée sur la préprod, et
// la facture affiche « [adresse non renseignée] ». Le défaut est entier le jour où quelqu'un en
// saisit une.
//
// Même famille que le bloc émetteur plus haut, dans le même fichier : un écran qui rend une PARTIE
// CHOISIE d'une source sans schéma, et dont le commentaire affirme l'inverse.
//
// L'ordre suit la norme postale française : le complément d'adresse (bâtiment, étage, service) se
// place AVANT la voie, pas après.
const ADRESSE_CONNUES = ['complement', 'rue', 'cp', 'ville', 'pays']

function AdresseBloc({ adresse }) {
  if (!adresse) return null

  if (Array.isArray(adresse)) {
    return adresse.filter(Boolean).map((l, i) => (
      <div className="fact-ligne-info" key={`${l}-${i}`}>{l}</div>
    ))
  }

  const lignes = []
  if (imprimable(adresse.complement)) lignes.push(String(adresse.complement))
  if (imprimable(adresse.rue)) lignes.push(String(adresse.rue))
  const ville = [adresse.cp, adresse.ville].filter(imprimable).map(String).join(' ')
  if (ville) lignes.push(ville)
  if (imprimable(adresse.pays)) lignes.push(String(adresse.pays))

  // Toute clé que ce fichier ne connaît pas s'imprime quand même, à la fin : une ligne d'adresse
  // maladroitement placée se corrige en la lisant, une ligne disparue ne se voit jamais.
  const autres = Object.keys(adresse).filter((c) => !ADRESSE_CONNUES.includes(c))
  autres.filter((c) => imprimable(adresse[c])).forEach((c) => lignes.push(String(adresse[c])))

  // ⚠ Une valeur non affichable ne se pose pas sur le document — mais elle est NOMMÉE à l'écran,
  //    hors impression. Muet est le seul état interdit.
  const nonRendues = autres.filter(
    (c) => !imprimable(adresse[c]) && adresse[c] !== null && adresse[c] !== undefined && adresse[c] !== '',
  )

  return (
    <>
      {lignes.map((l, i) => (
        <div className="fact-ligne-info" key={`${l}-${i}`}>{l}</div>
      ))}
      {nonRendues.length > 0 && (
        <div className="fact-ligne-info fact-noprint">
          ⚠ Non imprimé&nbsp;: {nonRendues.join(', ')} — valeur qui n’est pas un texte simple.
        </div>
      )}
    </>
  )
}

// Les montants arrivent en CHAÎNES décimales : on les met en forme sans jamais les additionner.
// Le seul rôle de cette fonction est typographique.
function euro(v) {
  if (v == null || v === '') return '—'
  const n = typeof v === 'number' ? v : parseFloat(v)
  if (Number.isNaN(n)) return String(v)
  return n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

function pourcent(v) {
  if (v == null || v === '') return '—'
  const n = typeof v === 'number' ? v : parseFloat(v)
  return Number.isNaN(n) ? String(v) : `${n.toLocaleString('fr-FR')} %`
}

function dateFr(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR')
}
