import { useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { api } from '../api/client.js'

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
export default function FactureRendu({ facture, onClose }) {
  const [rendu, setRendu] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [chargement, setChargement] = useState(true)

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

  const dest = rendu?.destinataire
  // ⚠ `denomination()` côté serveur ne rend `raisonSociale` que si le type est « personne morale ».
  // Un destinataire typé « particulier » mais porteur d'une raison sociale sort donc SANS NOM —
  // mesuré sur FA-2026-00001, dont le client est « École municipale ». Une facture sans
  // destinataire nommé ne doit pas être remise : on le marque DANS le document, parce qu'un bloc
  // vide se remet par mégarde.
  const sansDestinataire = Boolean(rendu) && !String(dest?.denomination || '').trim()

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
          <div className="banner banner-warn fact-noprint">
            <b>Mentions absentes de ce document&nbsp;:</b> forme juridique, capital social, numéro
            RCS et taux de pénalités de retard. Ils ne figurent pas dans le rendu produit par le
            serveur. L’indemnité forfaitaire de recouvrement, elle, est bien présente dans les
            conditions de règlement.
          </div>

          {sansDestinataire && (
            <div className="banner banner-error fact-noprint">
              <b>Ce document n’a pas de destinataire nommé.</b> Une facture doit désigner qui doit
              payer&nbsp;: corrigez la fiche du destinataire avant de la remettre.
            </div>
          )}

          <div className="fact-doc">
            <div className="fact-tete">
              <div className="fact-bloc">
                <div className="fact-bloc-titre">Émetteur</div>
                <div className="fact-nom">{rendu.emetteur?.denomination || '—'}</div>
                <AdresseBloc adresse={rendu.emetteur?.adresse} />
                {rendu.emetteur?.siret && <div className="fact-ligne-info">SIRET {rendu.emetteur.siret}</div>}
                {rendu.emetteur?.tvaIntra && <div className="fact-ligne-info">TVA {rendu.emetteur.tvaIntra}</div>}
              </div>

              <div className="fact-bloc">
                <div className="fact-bloc-titre">Destinataire</div>
                <div className="fact-nom">
                  {sansDestinataire
                    ? <span className="fact-manque">[destinataire non renseigné]</span>
                    : dest.denomination}
                </div>
                <AdresseBloc adresse={dest?.adresse} />
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
            <button className="btn primary" type="button" onClick={() => window.print()}>
              Imprimer
            </button>
          </div>
        </>
      )}
    </Modal>
  )
}

// L'adresse arrive en tableau de lignes libres, ou en objet structuré, ou vide. On ne devine pas
// un format : on rend ce qui est là, dans l'ordre où il est là.
function AdresseBloc({ adresse }) {
  if (!adresse) return null
  const lignes = Array.isArray(adresse)
    ? adresse
    : [adresse.rue, [adresse.cp, adresse.ville].filter(Boolean).join(' '), adresse.pays]
  return lignes.filter(Boolean).map((l, i) => (
    <div className="fact-ligne-info" key={`${l}-${i}`}>{l}</div>
  ))
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
