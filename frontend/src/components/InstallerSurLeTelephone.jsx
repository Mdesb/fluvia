import { useEffect, useState } from 'react'

/*
 * ⚠ LE NOM DE CE FICHIER AFFIRME UN APPAREIL, ET LE COMPOSANT NE LE FAIT PLUS.
 *
 * `InstallerSurLeTelephone` date du jour où la bannière ne visait que les téléphones. Elle
 * s'affiche aussi sur un poste de bureau — Chrome y émet `beforeinstallprompt` — et son texte ne
 * nomme donc plus d'appareil. Le fichier, lui, garde son nom : le renommer traverserait le
 * garde-fou de nommage (un fichier renommé compte comme neuf) et deux imports, pendant que
 * d'autres sessions travaillent dans ce répertoire. Le coût dépasse le gain — mais un nom qui ment
 * en silence est pire qu'un nom qui ment en le disant.
 */
/**
 * « INSTALLER FLUVIA SUR CE TÉLÉPHONE » — la bannière qui apparaît quand c'est possible, et jamais
 * autrement.
 *
 * ── POURQUOI CETTE BANNIÈRE EXISTE ──────────────────────────────────────────────────────────────
 *
 * Sur un poste de guichet, l'application vit dans un onglet parmi douze. Sur le téléphone d'un
 * agent de terrain — celui qui ouvre une porte, qui contrôle un billet, qui note une intervention —
 * un onglet à retrouver est un obstacle à chaque usage. Installée, l'application a une icône, un
 * plein écran, et s'ouvre en un geste.
 *
 * ── ON NE DEMANDE PAS, ON ATTEND QU'ON NOUS PROPOSE ─────────────────────────────────────────────
 *
 * `beforeinstallprompt` n'est émis par le navigateur QUE si l'installation est réellement possible :
 * manifeste valide, service worker actif, HTTPS, et application pas déjà installée. Se fier à cet
 * événement plutôt qu'à une détection maison, c'est la différence entre une bannière qui apparaît
 * quand elle sert et une bannière qui propose d'installer ce qui l'est déjà.
 *
 * Sur iOS, l'événement n'existe pas : Safari installe par le menu de partage, sans API. La bannière
 * ne s'affiche donc jamais là — plutôt que de donner une consigne que trois utilisateurs sur quatre
 * ne suivront pas.
 *
 * ── UN REFUS SE SOUVIENT ────────────────────────────────────────────────────────────────────────
 *
 * Fermer la bannière l'éteint pour de bon sur cet appareil. Une invitation qui revient à chaque
 * connexion n'est plus une invitation, c'est une réclame — et on apprend à la fermer sans la lire,
 * ce qui est exactement ce qu'on ne veut pas d'un bandeau qui sert aussi à avertir.
 */

const REFUS = 'fluvia.installation.refusee'

export default function InstallerSurLeTelephone() {
  const [invite, setInvite] = useState(null)

  useEffect(() => {
    if (localStorage.getItem(REFUS) === '1') return undefined

    function surProposition(e) {
      // Sans `preventDefault`, certains navigateurs affichent leur propre barre en plus de la
      // nôtre : deux invitations pour une seule action.
      e.preventDefault()
      setInvite(e)
    }

    window.addEventListener('beforeinstallprompt', surProposition)
    // L'installation faite ailleurs (menu du navigateur) doit faire disparaître la bannière : la
    // laisser proposer d'installer ce qui est installé ferait douter de tout le reste.
    const surInstallation = () => setInvite(null)
    window.addEventListener('appinstalled', surInstallation)

    return () => {
      window.removeEventListener('beforeinstallprompt', surProposition)
      window.removeEventListener('appinstalled', surInstallation)
    }
  }, [])

  if (!invite) return null

  return (
    <div className="pwa-bandeau">
      <span className="pwa-ic" aria-hidden="true">◈</span>
      <span className="pwa-txt">
        {/*
          ⚠ NE NOMMEZ PAS L'APPAREIL : ON NE L'A PAS REGARDÉ. Cette phrase disait « sur ce
          téléphone », et Chrome émet `beforeinstallprompt` sur un poste de bureau aussi — la
          bannière s'affichait devant un régisseur, en lui parlant d'un appareil qu'il n'avait pas.
          Il lisait une phrase qui ne le concernait pas et l'ignorait, alors que l'installation sur
          un poste fixe est justement ce qui sort Fluvia de l'onglet parmi douze.

          Distinguer sur la largeur ou le pointeur serait une heuristique, et une heuristique qui se
          trompe REPRODUIT le défaut. On dit donc le bénéfice plutôt que le lieu : une icône au lieu
          d'un onglet à retrouver. Chacun y lit sa propre situation.
        */}
        Installez Fluvia pour l’ouvrir d’une icône, sans le chercher parmi vos onglets.
      </span>
      <button
        className="btn primary sm"
        type="button"
        onClick={async () => {
          // `prompt()` ne peut être appelé qu'une fois par événement : on efface l'invite quoi
          // qu'il arrive, sinon un second clic échoue en silence.
          const proposition = invite
          setInvite(null)
          try {
            await proposition.prompt()
          } catch {
            /* Refus, ou invite déjà consommée : il n'y a rien à dire de plus à l'utilisateur. */
          }
        }}
      >
        Installer
      </button>
      <button
        className="btn sm"
        type="button"
        aria-label="Ne plus proposer"
        onClick={() => {
          localStorage.setItem(REFUS, '1')
          setInvite(null)
        }}
      >
        Plus tard
      </button>
    </div>
  )
}
