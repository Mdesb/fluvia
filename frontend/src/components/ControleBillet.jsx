import { useRef, useState } from 'react'
import { api } from '../api/client.js'
import { euros } from '../api/produit.js'

/**
 * CONTRÔLER UN BILLET À LA MAIN — l'outil des sites sans tourniquet.
 *
 * Demandé par Maxime : aujourd'hui un agent n'a aucun moyen de vérifier un billet sans matériel.
 * Sur un site sans portique, un billet vendu est donc invendable en pratique — on l'encaisse et
 * personne ne peut le contrôler à l'entrée.
 *
 * ── POURQUOI ICI, ET PAS DANS « BADGES & TERMINAUX » ────────────────────────────────────────────
 *
 * L'onglet du contrôle d'accès est conditionné à la capacité `controle_acces`. Il serait donc caché
 * **exactement sur les sites qui n'ont pas de matériel** — ceux pour qui cet outil existe. Le mettre
 * là aurait produit une fonction invisible pour son unique public.
 *
 * L'agent est à la caisse ; l'outil est à la caisse.
 *
 * ── ⚠ LE SCAN CONSOMME LE BILLET, ET C'EST UNE DÉCISION DE MAXIME ──────────────────────────────
 *
 * Deux branches étaient possibles et aucune n'était neutre : consommer, et un agent qui vérifie
 * deux fois a décompté deux entrées ; ne pas consommer, et le même billet entre dix fois sans que
 * personne ne s'en aperçoive.
 *
 * Il a tranché « consomme, et le dit » — le seul des deux choix qui laisse un humain rattraper. Un
 * second contrôle rend « déjà contrôlé à telle heure » : l'agent voit ce qui s'est passé et décide
 * de laisser entrer ou non. L'autre branche ne laisse personne décider.
 *
 * D'où la conséquence sur cet écran : **l'heure du contrôle précédent doit être visible**, sinon on
 * lui retire le seul élément sur lequel il peut fonder son jugement.
 */
export default function ControleBillet({ ecranEntier = false }) {
  const [code, setCode] = useState('')
  const [verdict, setVerdict] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(false)
  const champ = useRef(null)

  async function controler(e) {
    e.preventDefault()
    const saisi = code.trim()
    if (saisi === '') return

    setEnCours(true)
    setErreur(null)
    try {
      setVerdict(await api.controlerBillet(saisi))
    } catch (err) {
      setVerdict(null)
      setErreur(err.message || "Le contrôle n'a pas abouti.")
    } finally {
      setEnCours(false)
      // ⚠ LE CHAMP SE VIDE ET REPREND LE FOCUS, MÊME EN CAS DE REFUS.
      //
      // Une douchette envoie le code puis un retour chariot : si le champ garde le code précédent,
      // le scan suivant s'ajoute au bout et produit un code inexistant. L'agent voit alors un refus
      // qui ne parle pas du billet qu'il tient.
      setCode('')
      champ.current?.focus()
    }
  }

  const refuse = verdict !== null && verdict.resultat !== 'valide'

  // ⚠ DEUX REFUS QUI SE RESSEMBLENT ET QUI APPELLENT DES GESTES OPPOSES.
  //
  //     credit_epuise    une carte à zéro        → elle SE RECHARGE à la caisse
  //     deja_consomme    un billet déjà contrôlé → il ne se recharge pas, il appelle une QUESTION
  //
  // Le même bandeau rouge pour les deux ferait recharger un billet qui n'a pas de crédit : l'agent
  // encaisse, et le porteur entre une seconde fois avec le même titre.
  //
  // Si l'une de ces valeurs était renommée côté serveur, la branche cesserait de correspondre et
  // l'écran retomberait sur le bandeau générique avec le libellé du serveur. Moins utile, jamais
  // faux — on ne construit rien qui casse sur un code inconnu.
  const dejaControle = verdict?.codeMotif === 'deja_consomme'
  const creditEpuise = verdict?.codeMotif === 'credit_epuise'

  const carte = (
    <section className="card">
      <div className="card-h">
        <h3>Contrôler un billet</h3>
        <span className="sub">scannez ou saisissez le code — sans matériel</span>
      </div>
      <div className="card-b">
        <form onSubmit={controler}>
          <div className="field">
            <label htmlFor="cb-code">Code du billet</label>
            <input
              id="cb-code"
              ref={champ}
              className="input"
              value={code}
              onChange={(ev) => setCode(ev.target.value)}
              placeholder="Scannez le QR, ou tapez le code"
              autoComplete="off"
            />
          </div>
          <button className="btn primary" type="submit" disabled={enCours || code.trim() === ''}>
            {enCours ? 'Contrôle…' : 'Contrôler'}
          </button>
        </form>

        {erreur && <div className="banner banner-error">{erreur}</div>}

        {verdict && (
          <div
            className={
              dejaControle ? 'banner banner-warn' : refuse ? 'banner banner-error' : 'banner banner-ok'
            }
            role="status"
          >
            <b className="cb-verdict">{dejaControle ? 'Déjà contrôlé' : refuse ? 'Refusé' : 'Valide'}</b>
            {verdict.libelleMotif ? ` — ${verdict.libelleMotif}` : ''}

            {/* ⚠ L'HEURE DU CONTRÔLE PRÉCÉDENT EST LA DONNÉE QUI PERMET DE DÉCIDER. Sans elle,
                « déjà contrôlé » ne dit pas si c'était il y a trente secondes — l'agent qui a
                scanné deux fois — ou ce matin, c'est-à-dire quelqu'un d'autre avec le même billet.
                Les deux appellent des gestes opposés. */}
            {verdict.dejaControleLe && (
              <div>
                Déjà contrôlé le{' '}
                {new Date(verdict.dejaControleLe).toLocaleString('fr-FR', {
                  dateStyle: 'short',
                  timeStyle: 'short',
                })}
                .
              </div>
            )}

            {verdict.billet && (
              <div>
                <b>{verdict.billet.produit}</b>
                {verdict.billet.tarif ? ` · ${verdict.billet.tarif}` : ''}
                {/* ⚠ LE NOM N'ARRIVE QUE POUR UN TITRE NOMINATIF, ET C'EST LE SERVEUR QUI TRANCHE.
                    Cette branche existait deja ici — mais `porteur` valait `null` EN DUR cote
                    serveur : elle n'a jamais pu s'executer. Le nom est une donnee personnelle sur
                    un ecran tourne vers une file d'attente ; Maxime a arbitre « seulement pour les
                    titres nominatifs », et la presence d'un beneficiaire sur la vente est ce
                    marqueur. L'ecran n'a aucune regle a appliquer : il affiche ce qu'il recoit. */}
                {verdict.billet.porteur ? ` — ${verdict.billet.porteur}` : ''}
              </div>
            )}

            {/* ⚠ LA VALIDITE EST CE QUI TRANCHE UN LITIGE A LA PORTE. « Votre billet etait pour
                hier » ne se dit pas de memoire. On n'affiche que ce qu'on a : une borne absente
                veut dire « pas de limite de ce cote », pas « je ne sais pas ». */}
            {verdict.billet && (verdict.billet.validite?.debut || verdict.billet.validite?.fin) && (
              <div>
                Valable{' '}
                {verdict.billet.validite.debut
                  ? `du ${new Date(verdict.billet.validite.debut).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}`
                  : 'sans date de debut'}
                {' '}
                {verdict.billet.validite.fin
                  ? `au ${new Date(verdict.billet.validite.fin).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}`
                  : 'sans date de fin'}
                .
              </div>
            )}

            {/* `credit: null` veut dire « ce billet n'a pas de notion de crédit » — une entrée
                unique — et rien d'autre : mesuré côté serveur, la colonne est nullable et aucun
                chemin ne fait échouer le calcul. On n'affiche donc rien plutôt qu'un « — ». */}
            {verdict.credit && <div>Crédit restant : {verdict.credit.restant}</div>}

            {/* En dernier : le prix est ecrit sur le billet que l'agent tient. Il sert a lever un
                doute (« ce n'est pas le bon tarif »), pas a decider d'ouvrir. */}
            {verdict.billet?.prix && <div className="sub">Payé&nbsp;: {euros(verdict.billet.prix)}</div>}

            {/* Le geste, nommé — parce que c'est là que les deux refus divergent. */}
            {dejaControle && (
              <div>
                Ce titre a déjà servi. Il ne se recharge pas&nbsp;: demandez au porteur, puis
                décidez de le laisser entrer ou non.
              </div>
            )}
            {creditEpuise && (
              <div>
                Ce support n’a plus de crédit. Il se <b>recharge à la caisse</b> — ce n’est pas un
                titre déjà utilisé.
              </div>
            )}
          </div>
        )}

        <div className="hint">
          Le contrôle <b>consomme</b> le billet. Un second contrôle du même billet ne sera pas
          bloqué&nbsp;: il indiquera l’heure du premier, à vous de décider.
        </div>
      </div>
    </section>
  )

  // ⚠ LE TITRE N'APPARAIT QUE LA OU CE COMPOSANT EST L'ECRAN. Il est aussi inclus dans la page de
  // caisse, qui porte deja son propre `h1` — en poser un ici sans condition en mettrait deux dans
  // la meme page, ce qui casse la navigation par titres au lieu de la reparer.
  if (!ecranEntier) return carte

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Composter</h1>
          <p>Contrôle des billets à l’entrée, sans matériel</p>
        </div>
      </div>
      {carte}
    </div>
  )
}
