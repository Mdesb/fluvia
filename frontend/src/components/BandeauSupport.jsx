import { useEffect, useState } from 'react'
import { supportStore } from '../api/client.js'

// LE BANDEAU DU MODE SUPPORT — « vous n'êtes pas chez vous ».
//
// ── CE QU'IL EMPÊCHE ────────────────────────────────────────────────────────────────────────────
//
// Un agent de l'éditeur, en mode support, a les droits d'un administrateur chez un client : il peut
// encaisser, supprimer, modifier des écritures comptables. L'écran, lui, est EXACTEMENT le même que
// celui de son propre établissement — même couleurs, même menu, mêmes boutons. Rien ne distingue
// « je regarde chez le Camping des Écluses » de « je travaille chez moi », et l'erreur qu'on
// cherche à rendre impossible est celle-là : encaisser une vente chez quelqu'un d'autre.
//
// D'où un bandeau PERMANENT, en haut, qui nomme le client. Pas une notification qui passe : quelque
// chose qu'on ne peut pas ne pas voir, et qui est encore là dix minutes plus tard.
//
// ── ⚠ LA FIN AUTOMATIQUE EST VÉRIFIÉE AUPRÈS DU SERVEUR, PAS COMPTÉE ICI ───────────────────────
//
// Maxime a demandé une fin automatique. Le réflexe serait un compte à rebours sur `expiresAt` : il
// afficherait « expiré » quand l'horloge du NAVIGATEUR le dit, et « il reste 12 minutes » quand le
// serveur a déjà refermé l'accès — une révocation anticipée, une horloge décalée, un onglet
// endormi par le système suffisent à les désaccorder. L'écran dirait alors le contraire de ce que
// l'API répond, ce qui est pire que de ne rien dire.
//
// La preuve employée ici est la liste des établissements que le serveur RENVOIE. Depuis que le
// périmètre de cloisonnement tient compte des accès d'assistance, un établissement n'y figure que
// tant que l'accès est utilisable — ouvert, non révoqué, non expiré. **Sa présence EST l'accès**,
// et sa disparition est la fin, à la seconde où le serveur le décide.
//
// C'est aussi pourquoi ce composant reçoit `etablissements` plutôt que d'interroger lui-même : la
// liste est déjà rafraîchie par l'application, et deux sources pour une même question finissent
// toujours par se contredire.
//
// ── POURQUOI SORTIR NE RÉVOQUE PAS ─────────────────────────────────────────────────────────────
//
// `POST /editor/support-accesses/{id}/revoke` exige que l'établissement ACTIF soit celui de
// l'éditeur — et dans cet onglet, l'établissement actif est justement celui du client. L'appel
// rendrait 404. Ce n'est pas un oubli du serveur mais sa règle : les gestes d'éditeur se font
// depuis l'éditeur.
//
// Sortir ferme donc l'onglet et oublie le contexte ; l'accès s'éteint de lui-même à son terme, et
// se referme avant terme depuis l'écran « Accès d'assistance » de l'administration. Le dire ici
// évite qu'un lecteur croie à une révocation qui n'a pas lieu.
export default function BandeauSupport({ etablissements = [], onFin }) {
  const [contexte, setContexte] = useState(() => supportStore.get())

  // Le nom vient du SERVEUR, jamais de l'URL. Un onglet ouvert à la main sur
  // `/?support=<identifiant>` ne doit pas afficher un bandeau qui a l'air légitime : si
  // l'établissement n'est pas dans la liste, il n'y a pas d'accès, et on le dit.
  const etablissement = etablissements.find((e) => e.id === contexte?.etablissementId)

  // ⚠ LA LISTE VIDE N'EST PAS UNE ABSENCE D'ACCÈS. Au premier rendu, elle n'est pas encore
  // revenue ; conclure « l'accès est fini » ferait clignoter l'écran de fin à chaque chargement,
  // et personne ne croirait plus ce message-là. On ne conclut que sur une liste effectivement
  // reçue.
  const listeRecue = etablissements.length > 0
  const termine = Boolean(contexte) && listeRecue && !etablissement

  useEffect(() => {
    if (termine) supportStore.clear()
  }, [termine])

  if (!contexte) return null

  if (termine) {
    return (
      <div className="sup-fin" role="alertdialog" aria-modal="true">
        <div className="card sup-fin-boite">
          <div className="card-h">L’accès d’assistance est terminé</div>
          <div className="card-b">
            <p>
              L’accès sur <strong>{contexte.nom || 'cet établissement'}</strong> n’est plus valable :
              il a atteint son terme, ou il a été refermé depuis l’administration.
            </p>
            <p className="hint">
              Cet onglet ne peut plus rien lire ni écrire chez ce client. Pour reprendre, ouvrez un
              nouvel accès depuis l’administration — la seconde ouverture laisse une seconde trace,
              et c’est l’information qu’on veut avoir.
            </p>
            <div className="modal-actions">
              <button type="button" className="btn" onClick={() => quitter(onFin)}>
                Fermer cet onglet
              </button>
            </div>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="sup-bandeau" role="status">
      <span className="sup-ic" aria-hidden="true">◈</span>
      <span className="sup-txt">
        <strong>Mode support</strong> — vous travaillez chez{' '}
        <strong>{etablissement?.nom || contexte.nom || '…'}</strong>. Tout ce que vous faites ici est
        enregistré dans le journal d’audit de ce client.
      </span>
      <button
        type="button"
        className="btn ghost sm"
        onClick={() => {
          supportStore.clear()
          setContexte(null)
          quitter(onFin)
        }}
        title="Ferme cet onglet et oublie le contexte de support. L’accès lui-même s’éteint à son terme ; pour le refermer tout de suite, utilisez l’administration."
      >
        Sortir du mode support
      </button>
    </div>
  )
}

// `window.close()` ne fonctionne que sur un onglet ouvert par un script — c'est le cas ici, la
// bascule l'a ouvert. Mais un navigateur peut refuser, et un onglet restauré après un
// redémarrage n'a plus d'ouvreur : sans repli, le bouton ne ferait alors RIEN, ce qui se lit comme
// une panne. On retombe donc sur l'administration de l'éditeur, qui est l'endroit d'où l'on vient.
function quitter(onFin) {
  onFin?.()
  window.close()
  if (!window.closed) window.location.assign('/editeur')
}
