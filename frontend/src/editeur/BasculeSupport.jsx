import { useEffect, useMemo, useState } from 'react'
import { api } from '../api/client.js'
import Modal from '../components/Modal.jsx'

// Les bornes du serveur, recopiées — `GrantSupportAccessProcessor::MAX_HOURS` et `DEFAULT_HOURS`.
//
// ⚠ UNE DUPLICATION ASSUMÉE, ET QUI SE CORRIGE TOUTE SEULE. Le serveur revalide et rend un 422 dont
// le message dit la borne en toutes lettres ; si ces deux nombres divergeaient un jour, le pire cas
// est une liste déroulante trop courte ou une option refusée avec une phrase claire — jamais un
// accès plus long que la règle. La règle vit là-bas ; ceci n'est qu'un menu.
const MAX_HEURES = 8
const DEFAUT_HEURES = 2

// « BASCULER EN MODE SUPPORT » — la porte qui manquait à un mécanisme complet.
//
// ── L'ÉTAT DES LIEUX AVANT CET ÉCRAN ───────────────────────────────────────────────────────────
//
// `SupportAccess`, `SupportAccessGuard`, les trois routes `/editor/support-accesses`, la trace
// d'audit, la fenêtre bornée, la révocation : tout existait, tout était testé, et **aucun écran
// n'appelait rien**. On pouvait ouvrir un accès d'assistance avec `curl`, pas depuis Fluvia.
//
// Ce n'est pas une lacune de confort. Une règle sans chemin praticable ne tient pas : le jour où un
// client appelle parce que sa caisse ne s'ouvre pas, la seule façon de le dépanner devient de lui
// poser une affectation permanente à la main — invisible, que personne ne pensera à retirer, et
// exactement ce que RG-ED-07 interdit.
//
// ── CE QUE CET ÉCRAN DEMANDE, ET POURQUOI IL LE DEMANDE ────────────────────────────────────────
//
// Le motif est OBLIGATOIRE et libre. Une liste déroulante produirait « autre » neuf fois sur dix ;
// une phrase écrite se relit six mois plus tard, et se justifie devant le client — qui la voit,
// puisque l'ouverture est journalisée dans SON journal d'audit. C'est ce qui distingue une trace
// d'un champ de formulaire.
//
// La durée est courte par défaut. Deux heures, c'est une intervention ; huit heures est le plafond
// du serveur. Au-delà, on rouvre — et rouvrir laisse une seconde trace, ce qui est précisément
// l'information qu'on veut avoir.
//
// ── ⚠ UN NOUVEL ONGLET, ET C'EST LE CHOIX DE MAXIME ────────────────────────────────────────────
//
// « C'est plus simple si on doit faire la réponse au ticket » : l'administration reste ouverte dans
// le premier onglet pendant qu'on regarde chez le client dans le second.
//
// Le contexte de support vit en `sessionStorage`, donc DANS CET ONGLET seulement (voir
// `supportStore`). Avec `localStorage`, l'établissement actif du premier onglet aurait changé aussi,
// et l'agent y serait revenu — chez le client, sans bandeau, sans rien qui le dise.
export default function BasculeSupport() {
  const [ouvert, setOuvert] = useState(false)

  return (
    <>
      <button
        type="button"
        className="btn ghost sm"
        onClick={() => setOuvert(true)}
        title="Ouvrir un accès d’assistance chez un client et travailler dans son établissement"
      >
        ◈ Basculer en mode support
      </button>
      <FenetreBascule open={ouvert} onClose={() => setOuvert(false)} />
    </>
  )
}

function FenetreBascule({ open, onClose }) {
  const [clients, setClients] = useState(null)
  const [erreurChargement, setErreurChargement] = useState(null)
  const [filtre, setFiltre] = useState('')
  const [choisi, setChoisi] = useState(null)
  const [motif, setMotif] = useState('')
  const [heures, setHeures] = useState(DEFAUT_HEURES)
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)

  useEffect(() => {
    if (!open) return
    setChoisi(null)
    setMotif('')
    setHeures(DEFAUT_HEURES)
    setErreur(null)
    setErreurChargement(null)
    setClients(null)
    api
      .editorCustomers()
      .then((r) => setClients(r['hydra:member'] ?? r.member ?? []))
      .catch((e) => setErreurChargement(e.message || 'La liste des clients n’a pas pu être chargée.'))
  }, [open])

  // UNE LIGNE PAR ÉTABLISSEMENT, PAS PAR CLIENT. On bascule chez un établissement, pas chez une
  // raison sociale : un client peut en avoir plusieurs, et « lequel ? » est la première question.
  //
  // ⚠ Les abonnements sans `establishmentId` sont ÉCARTÉS et comptés. Un abonnement non provisionné
  // n'a pas encore d'établissement : l'afficher donnerait une ligne qu'on ne peut pas choisir, et
  // le lecteur croirait à une panne de l'écran. Le nombre est dit plus bas, parce qu'une ligne
  // silencieusement absente se lit comme « ce client n'existe pas ».
  const { lignes, ecartes } = useMemo(() => {
    const vues = new Map()
    let sansEtablissement = 0

    for (const client of clients ?? []) {
      for (const abo of client.subscriptions ?? []) {
        if (!abo.establishmentId) {
          sansEtablissement += 1
          continue
        }
        if (vues.has(abo.establishmentId)) continue
        vues.set(abo.establishmentId, {
          etablissementId: abo.establishmentId,
          etablissementNom: abo.establishmentName || 'Établissement sans nom',
          clientNom: client.name,
          accesOuvert: (client.supportAccesses ?? []).some((a) => a.usable),
        })
      }
    }

    return { lignes: [...vues.values()], ecartes: sansEtablissement }
  }, [clients])

  const recherche = filtre.trim().toLowerCase()
  const filtrees = recherche
    ? lignes.filter(
        (l) =>
          l.etablissementNom.toLowerCase().includes(recherche) ||
          l.clientNom.toLowerCase().includes(recherche),
      )
    : lignes

  async function basculer() {
    if (!choisi || !motif.trim()) return
    setEnvoi(true)
    setErreur(null)
    try {
      await api.ouvrirAccesAssistance({
        establishmentId: choisi.etablissementId,
        reason: motif.trim(),
        hours: heures,
      })

      // ⚠ ON N'ENVOIE QUE L'IDENTIFIANT DANS L'URL, ET RIEN QUI S'AFFICHE. Le nom du client, dans
      // l'onglet de support, vient de la liste que le SERVEUR renvoie — pas de ce lien. Un onglet
      // ouvert à la main sur `/?support=<identifiant quelconque>` ne doit pas produire un bandeau
      // qui a l'air légitime : sans accès réel, l'établissement n'est pas dans la liste, et
      // l'écran le dit au lieu de le maquiller.
      window.open(`/?support=${encodeURIComponent(choisi.etablissementId)}`, '_blank', 'noopener')
      onClose()
    } catch (e) {
      setErreur(e.message || 'L’accès n’a pas pu être ouvert.')
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} titre="Basculer en mode support" taille="lg">
      {erreurChargement && <div className="banner banner-error">{erreurChargement}</div>}
      {clients === null && !erreurChargement && <div className="empty">Chargement des clients…</div>}

      {clients !== null && (
        <>
          <div className="field">
            <label className="field-lbl" htmlFor="sup-filtre">Chercher un client ou un établissement</label>
            <input
              id="sup-filtre"
              className="input"
              value={filtre}
              onChange={(e) => setFiltre(e.target.value)}
              placeholder="Camping des Écluses…"
            />
          </div>

          {filtrees.length === 0 && (
            <div className="empty">
              {lignes.length === 0
                ? 'Aucun client n’a d’établissement livré : il n’y a nulle part où basculer.'
                : 'Aucun client ne correspond à cette recherche.'}
            </div>
          )}

          <div className="sup-liste">
            {filtrees.map((l) => (
              <button
                key={l.etablissementId}
                type="button"
                className={`sup-ligne${choisi?.etablissementId === l.etablissementId ? ' sup-ligne-on' : ''}`}
                onClick={() => setChoisi(l)}
              >
                <span className="sup-ligne-nom">{l.etablissementNom}</span>
                <span className="hint">{l.clientNom}</span>
                {/* Un accès déjà ouvert n'empêche pas d'en ouvrir un second, et ce n'est pas une
                    négligence : deux agents peuvent intervenir sur deux tickets. Mais le dire évite
                    d'en rouvrir un par simple oubli, et donc de multiplier les traces sans raison. */}
                {l.accesOuvert && <span className="badge">Accès déjà ouvert</span>}
              </button>
            ))}
          </div>

          {ecartes > 0 && (
            <p className="hint">
              {ecartes === 1
                ? '1 abonnement n’apparaît pas : sa plateforme n’est pas encore livrée, il n’a donc pas d’établissement.'
                : `${ecartes} abonnements n’apparaissent pas : leur plateforme n’est pas encore livrée, ils n’ont donc pas d’établissement.`}
            </p>
          )}

          <div className="field">
            <label className="field-lbl" htmlFor="sup-motif">Pourquoi ouvrez-vous cet accès ?</label>
            <input
              id="sup-motif"
              className="input"
              value={motif}
              onChange={(e) => setMotif(e.target.value)}
              placeholder="Ticket 5102 — la caisse ne s’ouvre pas"
            />
            <p className="hint">
              Ce motif est enregistré dans le journal d’audit du client, qui peut le lire. Écrivez-le
              comme vous l’expliqueriez au téléphone.
            </p>
          </div>

          <div className="field">
            <label className="field-lbl" htmlFor="sup-duree">Durée</label>
            <select
              id="sup-duree"
              className="select"
              value={heures}
              onChange={(e) => setHeures(Number(e.target.value))}
            >
              {Array.from({ length: MAX_HEURES }, (_, i) => i + 1).map((h) => (
                <option key={h} value={h}>{h === 1 ? '1 heure' : `${h} heures`}</option>
              ))}
            </select>
            <p className="hint">
              L’accès se referme tout seul au terme. Au-delà de {MAX_HEURES} heures, rouvrez-en un —
              la seconde ouverture laisse une seconde trace.
            </p>
          </div>

          {erreur && <div className="banner banner-error">{erreur}</div>}

          <div className="modal-actions">
            <button type="button" className="btn ghost" onClick={onClose}>Annuler</button>
            <button
              type="button"
              className="btn"
              disabled={!choisi || !motif.trim() || envoi}
              onClick={basculer}
              title={
                !choisi
                  ? 'Choisissez d’abord l’établissement'
                  : !motif.trim()
                    ? 'Le motif est obligatoire : le client peut le lire'
                    : 'Ouvre l’accès et bascule dans un nouvel onglet'
              }
            >
              {envoi ? 'Ouverture…' : 'Ouvrir l’accès et basculer'}
            </button>
          </div>
        </>
      )}
    </Modal>
  )
}
