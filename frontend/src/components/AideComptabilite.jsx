import { useEffect, useState } from 'react'

// CE QUE CE MODULE FAIT, ÉCRIT UNE FOIS ET EN ENTIER.
//
// ── POURQUOI UN BLOC D'AIDE ICI, ALORS QUE CHAQUE CARTE EXPLIQUE DÉJÀ SON BOUTON ───────────────
//
// Les textes de ce module sont nombreux et bons, mais ils sont tous LOCAUX : « le geste est
// rejouable », « la clôture est définitive », « l'écriture du versement est générée toute seule ».
// Chacun explique un bouton. Aucun ne dit dans quel ORDRE les boutons s'enchaînent, ni ce qu'on
// obtient au bout.
//
// Maxime, à la revue : « il manque des explications sur le fonctionnement, je n'y comprends pas
// grand-chose ». Le diagnostic est exact et il ne porte pas sur un écran en particulier : cinq
// onglets, dix-huit gestes, et nulle part la phrase qui les relie.
//
// ── DÉPLIÉ PAR DÉFAUT, ET REFERMABLE POUR DE BON ───────────────────────────────────────────────
//
// Un bloc d'aide replié par défaut n'est jamais lu — c'est justement celui qui ne sait pas qu'il a
// besoin d'aide qui ne l'ouvre pas. Il s'ouvre donc, et le comptable qui connaît son métier le
// referme une fois : la préférence tient dans `localStorage`, comme le bandeau des scans.
//
// ⚠ `localStorage` peut lever (navigation privée, stockage bloqué). On perd alors la préférence,
// jamais la fonction : les deux accès sont gardés, et l'état par défaut reste « ouvert ».
const CLE_AIDE = 'billetterie.compta-aide'

export default function AideComptabilite() {
  const [ouvert, setOuvert] = useState(() => {
    try {
      return localStorage.getItem(CLE_AIDE) !== 'non'
    } catch {
      return true
    }
  })

  useEffect(() => {
    try {
      localStorage.setItem(CLE_AIDE, ouvert ? 'oui' : 'non')
    } catch {
      // Préférence perdue, fonction intacte.
    }
  }, [ouvert])

  return (
    <section className="card cpt-aide">
      <div className="card-h">
        <h3>Comment ce module fonctionne</h3>
        <span className="sub">de la vente au fichier des impôts, et la régie</span>
        <div className="r">
          <button
            type="button"
            className="btn ghost sm"
            aria-expanded={ouvert}
            onClick={() => setOuvert((o) => !o)}
          >
            {ouvert ? 'Masquer' : 'Afficher'}
          </button>
        </div>
      </div>

      {ouvert && (
        <div className="card-b cpt-aide-b">
          <div className="cpt-aide-col">
            <div className="fiche-sec">La chaîne comptable</div>
            <p className="cpt-aide-chapo">
              Vos ventes ne deviennent pas des écritures toutes seules. Cinq gestes les y mènent,
              dans cet ordre, et chacun a son onglet.
            </p>
            <ol className="cpt-aide-etapes">
              <li>
                <b>Générer</b> — reprend les ventes validées qui n’ont pas encore d’écriture.
                Rejouable sans risque : rien n’est compté deux fois. Il vous dit aussi{' '}
                <b>lesquelles n’ont pas pu passer</b>, et pourquoi — c’est le seul endroit du
                logiciel qui vous le dira.
              </li>
              <li>
                <b>Valider</b> — une écriture générée est <i>provisoire</i> : elle ne compte pas
                encore. La valider la scelle et l’enchaîne à la précédente. À partir de là elle
                n’est plus modifiable&nbsp;: c’est ce que la loi exige (NF525).
              </li>
              <li>
                <b>Lettrer</b> — rapprocher ce qui a été facturé du règlement qui le solde. C’est ce
                qui permet de savoir ce qui reste dû, et de ne pas relancer quelqu’un qui a payé.
              </li>
              <li>
                <b>Clôturer</b> — arrêter le mois. <b>Définitif</b>&nbsp;: aucune période ne se
                rouvre. Après la clôture, une correction ne se fait plus sur place — elle passe par
                une extourne datée du jour où on la fait.
              </li>
              <li>
                <b>Exporter</b> — le FEC est le fichier que l’administration réclame en cas de
                contrôle fiscal. Les autres formats servent à alimenter votre logiciel comptable.
              </li>
            </ol>
            <p className="cpt-aide-note">
              La <b>saisie manuelle</b> est en dehors de cette chaîne : elle sert aux écritures
              qu’aucune vente ne produit — une régularisation, une subvention, un apport.
            </p>
          </div>

          <div className="cpt-aide-col">
            <div className="fiche-sec">La régie de recettes</div>
            <p className="cpt-aide-chapo">
              Elle ne concerne que les exploitants <b>en régie directe</b> — une collectivité qui
              encaisse elle-même. Un délégataire ou un groupe privé peut ignorer cet onglet.
            </p>
            <p className="cpt-aide-def">
              Une régie de recettes est l’autorisation, donnée par arrêté à un agent — le{' '}
              <b>régisseur</b> —, d’encaisser de l’argent au nom de la collectivité. Elle fixe les
              moyens de paiement qu’il peut accepter et un <b>plafond d’encaisse</b> : le montant
              qu’il n’a pas le droit de détenir au-delà.
            </p>
            <ol className="cpt-aide-etapes">
              <li>
                L’<b>encaisse</b> est ce que le régisseur détient à un instant donné. Elle monte à
                mesure qu’il encaisse, et ne redescend qu’au versement.
              </li>
              <li>
                Au-dessus du plafond, <b>la clôture du mois est refusée</b> tant que rien n’a été
                versé. Ce n’est pas un avertissement : la période reste ouverte.
              </li>
              <li>
                Le <b>versement</b> remet les fonds au comptable public. Il émet un bordereau et{' '}
                <b>écrit son écriture comptable tout seul</b> — ne la ressaisissez pas dans l’onglet
                de saisie manuelle, elle serait comptée deux fois.
              </li>
              <li>
                Les <b>impayés</b> sont l’autre moitié du compte. Une régie se lit par ce qu’elle a
                encaissé <i>et</i> par ce qui lui manque : un solde regardé seul a toujours l’air bon.
              </li>
            </ol>
            <p className="cpt-aide-note">
              Le libellé d’une régie, son plafond, ses moyens de paiement autorisés et son acte de
              nomination se règlent une fois, dans{' '}
              <b>Paramètres › Caisse &amp; moyens de paiement</b>. Ici, on verse.
            </p>
          </div>
        </div>
      )}
    </section>
  )
}
