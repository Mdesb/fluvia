<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\AccountKind;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * UN COMPTE CLIENT N'OUVRE QUE L'ESPACE CLIENT — audit du 06/09, constat 4.
 *
 * Le jeton d'un client final est un JWT comme un autre : même pare-feu, même fournisseur. Ce qui le
 * distingue est la nature du compte (`AccountKind::Customer`), posée à la création par la boutique et
 * qu'aucun rôle ne peut recopier. Ce listener la lit, après le pare-feu, et refuse tout chemin qui n'est
 * pas celui de l'espace client.
 *
 * ── UNE LISTE BLANCHE, PAS UNE LISTE NOIRE ─────────────────────────────────────────────────────
 *
 * Énumérer ce qu'un client ne doit PAS atteindre, c'est promettre de penser à la prochaine route. La
 * liste ci-dessous est ce que le frontal public appelle avec un jeton client — mesurée dans
 * `frontend/src/public/api/boutiqueClient.js`, et `CustomerAllowlistCoversPublicShopTest` refuse
 * de passer si ce fichier appelle un chemin qui n'y est pas. Le jour où l'espace client s'étend, le
 * test tombe, et la liste s'allonge d'une ligne assumée.
 *
 * **403, et un message qui dit pourquoi.** Il n'y a rien à cacher ici — les chemins du back-office ne
 * sont pas un secret — et un client qui se trompe de porte (la page de connexion des exploitants) doit
 * lire qu'il s'est trompé de porte, pas qu'un écran n'existe pas.
 */
final class CustomerAccountPathListener implements EventSubscriberInterface
{
    /** Après le pare-feu (8) et le contrôle de l'en-tête d'établissement (7). */
    public const PRIORITY = 6;

    /**
     * Les chemins de l'espace client. `^/auth` pour se connecter et vérifier un second facteur,
     * `^/mot-de-passe/` pour le retrouver, `^/api/boutique/` pour tout le reste ; les documents légaux
     * publics parce que la boutique les affiche au client connecté.
     */
    public const ALLOWED_PATHS = '#^/(api/boutique/|api/legal/publics/|auth(/|$)|mot-de-passe/)#';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', self::PRIORITY]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur || $user->getKind() !== AccountKind::Customer) {
            return;
        }

        if (preg_match(self::ALLOWED_PATHS, $event->getRequest()->getPathInfo()) === 1) {
            return;
        }

        throw new AccessDeniedHttpException(
            'Ce compte est un compte client : il n’ouvre que l’espace client de la boutique, pas le back-office.',
        );
    }
}
