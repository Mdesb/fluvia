<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Securite\Entity\Utilisateur;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RÉÉMISSION GLISSANTE DU JETON — l'activité prolonge la session, l'inactivité la ferme.
 *
 * ── LE PROBLÈME ─────────────────────────────────────────────────────────────────────────────────
 *
 * `token_ttl: 3600`. Un caissier est déconnecté au bout d'une heure, **en pleine vente, sans un
 * mot**. Rien ne distingue « votre session a expiré » d'une panne : l'écran cesse simplement de
 * répondre. Constaté deux fois pendant une revue d'écrans, chaque fois sans explication.
 *
 * ── POURQUOI UN « PING RÉGULIER » NE MARCHERAIT PAS ─────────────────────────────────────────────
 *
 * C'est la solution qui vient à l'esprit, et elle est inopérante : **l'expiration d'un JWT est
 * fixée à son émission**. Appeler une route toutes les cinq minutes rend 200 pendant une heure,
 * puis 401 exactement au même instant qu'avant. On aurait ajouté du trafic et gardé la panne.
 *
 * Ce qui réalise vraiment l'intention, c'est de RÉÉMETTRE : à chaque requête authentifiée dont le
 * jeton a passé la moitié de sa vie, le serveur en renvoie un frais dans un en-tête. Le client
 * remplace le sien. L'activité prolonge alors la session pour de bon.
 *
 * ── POURQUOI PAS SIMPLEMENT ALLONGER `token_ttl` ────────────────────────────────────────────────
 *
 * Un jeton volé resterait valide aussi longtemps que le réglage. Aujourd'hui la MFA n'est active
 * sur **aucun** compte : le jeton est le seul secret en circulation. Une heure d'activité prolongée
 * n'est pas une journée d'exposition.
 *
 * ── LA BORNE ABSOLUE, ET C'EST ELLE QUI REND LE MÉCANISME SÛR ───────────────────────────────────
 *
 * Sans plafond, un jeton dérobé se renouvelle **indéfiniment** : il suffit de l'utiliser. Le claim
 * `sessionDebut` porte l'heure de la PREMIÈRE connexion et voyage de réémission en réémission sans
 * jamais être remis à zéro. Passé douze heures, on cesse de réémettre — la session s'éteint alors
 * comme avant, et il faut se reconnecter pour de vrai.
 *
 *   > Une session qui se prolonge sans fin n'est plus une session, c'est un mot de passe.
 *
 * ── CE QUI N'EST PAS TOUCHÉ ─────────────────────────────────────────────────────────────────────
 *
 * `tokenVersion` continue d'invalider immédiatement : suspendre un compte ou réinitialiser un mot
 * de passe coupe toutes ses sessions, réémission ou pas. La réémission recopie la version courante,
 * elle ne la contourne pas — un jeton réémis pour un compte suspendu serait refusé au coup suivant
 * par `VerificateurJwtActifListener`.
 */
final class SlidingSessionListener implements EventSubscriberInterface
{
    /** En-tête que le client doit lire et stocker à la place de son jeton courant. */
    public const ENTETE = 'X-Jeton-Renouvele';

    /** Claim portant l'heure de la première connexion — jamais remis à zéro. */
    public const CLAIM_DEBUT = 'sessionDebut';

    /** On réémet quand il reste moins de la moitié de la vie du jeton. */
    private const SEUIL = 0.5;

    /** Au-delà, plus de réémission : il faut se reconnecter. */
    private const DUREE_MAXIMALE = 12 * 3600;

    public function __construct(
        private readonly Security $security,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly int $dureeDeVie,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        // ⚠ LA CHARGE SE LIT DANS L'EN-TÊTE, PAS DANS L'OBJET D'AUTHENTIFICATION.
        //
        // Deux chemins essayés avant celui-ci, tous deux faux : le jeton post-authentification ne
        // porte pas les claims (l'écouteur ne réémettait alors jamais — inerte, et l'inertie
        // ressemble à de la prudence), et `decode()` appelle `getCredentials()`, absent du jeton
        // de la requête de connexion elle-même.
        //
        // `parse()` sur le jeton brut ne suppose rien du type de l'objet d'authentification. Une
        // requête sans jeton porteur n'a rien à réémettre.
        $entete = (string) $event->getRequest()->headers->get('Authorization', '');
        if (!str_starts_with($entete, 'Bearer ')) {
            return;
        }

        try {
            $charge = $this->jwtManager->parse(substr($entete, 7));
        } catch (\Throwable) {
            // Un jeton illisible n'est pas notre affaire : l'authentification l'a déjà refusé, ou
            // le refusera. On ne transforme pas un problème d'authentification en erreur 500.
            return;
        }

        $emisLe = (int) ($charge['iat'] ?? 0);
        $expireLe = (int) ($charge['exp'] ?? 0);
        if ($emisLe === 0 || $expireLe === 0) {
            return;
        }

        $maintenant = time();

        // Encore plus de la moitié de sa vie : rien à faire. Réémettre à chaque requête ferait
        // tourner les jetons pour rien et rendrait tout journal d'authentification illisible.
        if (($expireLe - $maintenant) > (int) ($this->dureeDeVie * self::SEUIL)) {
            return;
        }

        // ⚠ LA BORNE ABSOLUE. `sessionDebut` vient du jeton courant et n'est jamais recalculé :
        // c'est ce qui empêche un jeton dérobé de se renouveler sans fin. Un jeton d'avant cette
        // fonctionnalité n'a pas le claim — on prend alors son heure d'émission, ce qui lui laisse
        // au plus une fenêtre, jamais une éternité.
        $debut = (int) ($charge[self::CLAIM_DEBUT] ?? $emisLe);
        if (($maintenant - $debut) >= self::DUREE_MAXIMALE) {
            return;
        }

        $frais = $this->jwtManager->createFromPayload($utilisateur, [self::CLAIM_DEBUT => $debut]);

        $event->getResponse()->headers->set(self::ENTETE, $frais);
    }
}
