import { useCallback, useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import { api } from '../api/client.js'
import { euros } from '../api/produit.js'

/**
 * Choisir les options d'un produit au moment de le vendre.
 *
 * **Pourquoi cet écran existe.** Les options étaient configurables depuis le catalogue et
 * **invendables** : aucun écran ne permettait d'en choisir une en encaissant. Maxime l'a signalé il y a
 * trois jours — « je ne comprends rien aux options produit » — et la réponse n'était pas que l'écran
 * était incomplet, c'est qu'il n'existait pas.
 *
 * **Aucun prix n'est calculé ici.** Chaque changement de sélection redemande le devis au serveur, qui
 * appelle **le même service que la composition d'une ligne de vente**. Ce n'est pas de la prudence : un
 * plafond sur le cumul, une remise « pack », une option qui en rend une autre gratuite — et une addition
 * faite ici deviendrait fausse **en continuant de rendre un nombre plausible**. C'est le défaut qu'on a
 * corrigé trois fois cette semaine, à trois endroits.
 *
 * **Les options indisponibles sont affichées, désactivées, avec leur motif.** C'est une exception
 * assumée à la règle « une action sans objet est absente, jamais grisée » : *la question n'est pas si
 * l'action est possible, c'est si l'utilisateur a une raison de la chercher.* Un client qui réclame
 * nommément une option que le caissier ne trouve pas l'envoie fouiller le paramétrage. **Une absence
 * sans explication est une énigme ; une présence expliquée est une réponse.**
 *
 * Et l'ordre des phrases suit D54 : d'abord le fait sur la donnée — *pourquoi cette option-ci est
 * indisponible* — jamais un grisé muet.
 */
export default function ChoixOptions({
  ouvert,
  produit,
  typeTarifId,
  tarifLibelle,
  devis,
  selectionInitiale,
  ajustement = false,
  onFermer,
  onValider,
}) {
  const [retenues, setRetenues] = useState([])
  const [courant, setCourant] = useState(devis)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)

  // Le devis d'ouverture fait foi, et LA SÉLECTION AUSSI.
  //
  // Repartir de zéro était juste tant que la fenêtre ne s'ouvrait qu'avant l'ajout au panier. Depuis
  // qu'on peut rouvrir une ligne déjà vendue pour l'ajuster, remettre les cases à blanc effacerait en
  // silence ce que le client a déjà choisi — et le total, lui, resterait juste, donc rien ne le dirait.
  useEffect(() => {
    setCourant(devis)
    setRetenues(selectionInitiale ?? [])
    setErreur(null)
  }, [devis, selectionInitiale])

  const redemander = useCallback(
    async (selection) => {
      if (!produit || !devis) return
      setChargement(true)
      setErreur(null)
      try {
        setCourant(
          await api.tarifProduit(produit.id, {
            // ⚠ L'IDENTIFIANT du tarif, pas celui du devis. `PriceQuote.typeTarif` porte le **nom**
            // (« Plein tarif ») : le renvoyer faisait refuser la requête, les options se cochaient,
            // et le total restait celui d'avant. Trouvé en cliquant, pas en relisant.
            typeTarif: typeTarifId,
            canal: devis.canal,
            options: selection,
          }),
        )
      } catch (e) {
        // ⚠ ON N'AFFICHE PAS UN TOTAL PÉRIMÉ, ET CE N'EST PAS UNE PRÉCAUTION DE STYLE.
        //
        // Ma première version disait ça en commentaire **et gardait le total d'avant** à l'écran. En
        // cliquant, on voyait donc deux options cochées, un bandeau d'erreur, et un montant inchangé
        // qui avait toutes les apparences d'un montant à jour. C'est exactement le défaut que cet
        // écran existe pour supprimer — un prix annoncé qui n'est pas celui qui sera facturé —
        // reproduit par le remède, dans son propre gestionnaire d'erreur.
        //
        // Le montant devient donc inconnu, et l'ajout au panier est refusé : on ne vend pas un prix
        // qu'on n'a pas.
        setCourant((avant) => (avant ? { ...avant, totalUnitaire: null, prixUnitaire: null } : avant))
        setErreur(e.message || "Le prix n'a pas pu être recalculé.")
      } finally {
        setChargement(false)
      }
    },
    [produit, devis, typeTarifId],
  )

  function basculer(groupe, valeur) {
    const uniques = groupe.modeSelection === 'unique'
    const idsDuGroupe = groupe.valeurs.map((v) => v.valeurOption)

    setRetenues((avant) => {
      const deja = avant.includes(valeur.valeurOption)
      // Un groupe à choix unique remplace ; un groupe à choix multiple ajoute.
      const apres = deja
        ? avant.filter((id) => id !== valeur.valeurOption)
        : [...(uniques ? avant.filter((id) => !idsDuGroupe.includes(id)) : avant), valeur.valeurOption]
      redemander(apres)
      return apres
    })
  }

  const groupes = courant?.options ?? []
  const obligatoiresManquants = groupes.filter(
    (g) => g.obligatoire && !g.valeurs.some((v) => retenues.includes(v.valeurOption)),
  )

  return (
    <Modal
      open={ouvert}
      onClose={onFermer}
      titre={produit ? `${produit.libelleRecherche || 'Produit'} — options` : ''}
    >
      {courant && (
        <div style={{ display: 'grid', gap: 16 }}>
          {tarifLibelle && (
            <div className="hint" style={{ margin: 0 }}>
              Tarif : <b>{tarifLibelle}</b> · prix de base {euros(courant.prixUnitaire)}
            </div>
          )}

          {groupes.map((groupe) => (
            <div key={groupe.groupeOption} style={{ display: 'grid', gap: 6 }}>
              <div style={{ fontWeight: 620, fontSize: 14 }}>
                {groupe.libelle}
                {groupe.obligatoire && <span style={{ color: 'var(--danger)' }}> · obligatoire</span>}
                {groupe.modeSelection === 'multiple' && (
                  <span className="hint" style={{ marginLeft: 8 }}>plusieurs choix possibles</span>
                )}
              </div>

              {groupe.valeurs.map((valeur) => {
                const choisie = retenues.includes(valeur.valeurOption)
                return (
                  <button
                    key={valeur.valeurOption}
                    type="button"
                    className={`prodtile${choisie ? ' actif' : ''}`}
                    disabled={!valeur.disponible || chargement}
                    onClick={() => basculer(groupe, valeur)}
                    title={valeur.disponible ? undefined : valeur.motif}
                  >
                    <span className="pn">
                      {choisie ? '✓ ' : ''}
                      {valeur.libelle}
                    </span>
                    {/* Le garde répond à « pourquoi ce montant », que « +1,00 € » seul ne dit pas. */}
                    {valeur.impactType === 'pourcentage' && (
                      <span className="pc">+{valeur.impactValeur} %</span>
                    )}
                    {/* Le motif AVANT l'indisponibilité : le fait sur la donnée, puis sur l'utilisateur. */}
                    {!valeur.disponible && <span className="pc">{valeur.motif}</span>}
                    <span className="pp">
                      {Number.parseFloat(valeur.montantParUnite) === 0 ? 'inclus' : `+${euros(valeur.montantParUnite)}`}
                    </span>
                  </button>
                )
              })}
            </div>
          ))}

          {groupes.length === 0 && (
            <div className="hint" style={{ margin: 0 }}>Ce produit n'a aucune option proposable ici.</div>
          )}

          {erreur && <div className="banner-warn">{erreur}</div>}

          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: 12,
              borderTop: '1px solid var(--line)',
              paddingTop: 12,
            }}
          >
            <div>
              <div style={{ fontSize: 20, fontWeight: 640 }}>
                {chargement ? '…' : (courant.totalUnitaire ?? courant.prixUnitaire) === null
                  ? '— €'
                  : euros(courant.totalUnitaire ?? courant.prixUnitaire)}
              </div>
              <div className="hint" style={{ margin: 0 }}>par unité, options comprises</div>
            </div>

            <div style={{ display: 'flex', gap: 8 }}>
              <button type="button" className="btn ghost" onClick={onFermer}>Annuler</button>
              <button
                type="button"
                className="btn"
                disabled={
                  chargement
                  || obligatoiresManquants.length > 0
                  // Un prix inconnu ne s'ajoute pas au panier : le caissier l'annoncerait.
                  || (courant.totalUnitaire ?? courant.prixUnitaire) === null
                }
                onClick={() => onValider(retenues, courant)}
              >
                {/* Le bouton dit ce qu'il fait : rouvrir une ligne deja vendue pour la corriger n'est
                    pas ajouter une seconde ligne, et le panier derriere montrerait le contraire. */}
                {ajustement ? 'Mettre a jour la ligne' : 'Ajouter au panier'}
              </button>
            </div>
          </div>

          {/* Ce qui manque, dit avant le refus : un bouton inactif sans raison fait chercher un droit. */}
          {obligatoiresManquants.length > 0 && (
            <div className="hint" style={{ margin: 0 }}>
              À choisir avant de continuer : {obligatoiresManquants.map((g) => g.libelle).join(', ')}.
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
