<?php

declare(strict_types=1);

namespace App\Platform\Module;

use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Securite\Security\EstablishmentHeaderListener;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * LA GARDE SERVEUR UNIQUE DES MODULES MASQUÉS (recentrage, lot 1, CA-1 et CA-6).
 *
 * Toute route — opération API Platform ou contrôleur — dont une classe appartient à un module
 * masqué (ressource, contrôleur, fournisseur, processeur, ou classe parente d'une sous-ressource)
 * rend **404** si ce module est éteint pour l'établissement concerné : la même réponse qu'une
 * ressource inexistante, pour ne pas révéler que le module existe.
 *
 * ── L'ÉTABLISSEMENT CONCERNÉ ──────────────────────────────────────────────────────────────────
 *
 *  1. l'établissement actif (`X-Etablissement`), déjà confronté aux affectations par
 *     `EstablishmentHeaderListener` (priorité 7) ;
 *  2. sans en-tête, pour un `Utilisateur` : le module doit être allumé dans au moins un de ses
 *     établissements — sinon un client qui retire l'en-tête passerait la garde ;
 *  3. sans établissement du tout (plateforme ou éditeur sans en-tête, terminal, partenaire,
 *     anonyme) : pas de garde. Aucune route d'un module masqué n'est publique aujourd'hui, et
 *     `ModuleGuardCoverageTest` tombe le jour où il en naît une.
 *
 * Aucune permission n'est lue : un rôle `*.*` ne rallume pas un module éteint.
 *
 * La règle elle-même vit dans `ModuleGuard::isOnFor()`, que `/me` lit aussi pour le menu.
 *
 * ⚠ Elle garde des ROUTES. Les écouteurs d'événements de domaine sont filtrés par `SymfonyEventBus`,
 * les autres écouteurs, messages et commandes par `ModuleGuard::allows()` ; les appels PHP entre
 * modules ne passent par aucune garde.
 */
final class ModuleGuardListener implements EventSubscriberInterface
{
    /** Après le contrôle de l'en-tête d'établissement (7), avant tout fournisseur ou processeur. */
    public const PRIORITY = EstablishmentHeaderListener::PRIORITY - 2;

    public function __construct(
        private readonly ModuleGuard $guard,
        private readonly ContexteEtablissement $context,
        private readonly Security $security,
        private readonly ResourceMetadataCollectionFactoryInterface $metadata,
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

        $module = $this->guard->maskedModuleOf(...$this->classesOf($event->getRequest()));
        if ($module === null) {
            return;
        }

        if (!$this->guard->isOnFor($module, $this->context->etablissementActif(), $this->security->getUser())) {
            // Le message est celui d'API Platform pour une ressource introuvable : rien ne distingue
            // un module éteint d'un identifiant qui n'existe pas.
            throw new NotFoundHttpException('Not Found');
        }
    }

    /** @return list<?string> */
    private function classesOf(Request $request): array
    {
        $resource = $request->attributes->get('_api_resource_class');
        $controller = $request->attributes->get('_controller');
        $classes = [
            \is_string($resource) ? $resource : null,
            \is_string($controller) ? explode('::', $controller)[0] : (\is_array($controller) && \is_object($controller[0] ?? null) ? $controller[0]::class : null),
        ];

        $name = $request->attributes->get('_api_operation_name');
        if (\is_string($resource) && \is_string($name)) {
            $operation = $this->metadata->create($resource)->getOperation($name);
            foreach ([$operation->getProvider(), $operation->getProcessor(), $operation->getController()] as $class) {
                $classes[] = \is_string($class) ? explode('::', $class)[0] : null;
            }
            foreach ($operation->getUriVariables() ?? [] as $link) {
                $classes[] = $link instanceof Link ? $link->getFromClass() : null;
            }
        }

        return $classes;
    }
}
