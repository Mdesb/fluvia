<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Securite\Controller\MeController;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Securite\Service\EstablishmentReachability;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * L'EN-TÊTE `X-Etablissement` N'EST PLUS UN LIBRE-SERVICE.
 *
 * ── CE QUI SE PASSAIT ─────────────────────────────────────────────────────────────────────────
 *
 * `ContexteEtablissement::idActif()` lit l'en-tête et vérifie la syntaxe de l'UUID — c'est un
 * sélecteur, pas une preuve (D6), et le dépôt le disait. Ce qui refusait, c'était `PermissionVoter` :
 * sans affectation sur le site nommé, aucun code effectif, donc aucune opération à `PERM`. Le modèle
 * tenait tant que chaque opération exigeait un droit fin.
 *
 * Trente-six opérations n'en exigent aucun — `IS_AUTHENTICATED_FULLY` seul — et elles lisent l'en-tête
 * comme les autres. Audit du 06/09, constat 3, vérifié en exécution : un compte affecté nulle part a
 * créé un événement d'agenda chez Patinoire B (HTTP 201, `establishment_id` de B en base) en écrivant
 * l'identifiant de B dans l'en-tête. L'agenda du site voisin se lisait de la même manière.
 *
 * ── CE QUE FAIT CE LISTENER, ET CE QU'IL NE FAIT PAS ──────────────────────────────────────────
 *
 * Après le pare-feu (priorité 8) et avant que quiconque ne lise l'en-tête (le `ReadListener` d'API
 * Platform est à 4) : si un `Utilisateur` est authentifié et nomme un établissement qu'il ne peut pas
 * ATTEINDRE — ni affectation, ni délégation active, ni accès d'assistance (`EstablishmentReachability`)
 * — la requête est refusée **404**, comme partout ailleurs dans ce dépôt : un 403 confirmerait que
 * l'établissement existe.
 *
 * Il ne remplace pas `PermissionVoter` : atteindre un site n'y donne aucun droit. Il ferme la porte
 * que le voter ne gardait pas, celle des opérations sans droit fin — et, par construction, celle de
 * tout fournisseur ou processeur écrit à la main qui fait confiance à `etablissementActif()`.
 *
 * ── LES CAS QU'IL LAISSE PASSER, ET POURQUOI ──────────────────────────────────────────────────
 *
 *  - pas d'en-tête : rien à confronter — la fermeture par défaut appartient aux extensions ;
 *  - en-tête syntaxiquement invalide : `idActif()` rend `null`, tout se comporte comme sans en-tête ;
 *  - pas d'`Utilisateur` : les pare-feux `terminal` et `public_api` authentifient d'autres identités,
 *    et la boutique publique n'envoie pas d'en-tête ;
 *  - la route `/me` : c'est par elle que l'écran se remet d'un établissement mémorisé qui ne lui
 *    appartient plus ; `MeController` neutralise lui-même l'en-tête au lieu de le servir.
 */
final class EstablishmentHeaderListener implements EventSubscriberInterface
{
    /** Après le pare-feu (8), avant le `ReadListener` d'API Platform (4). */
    public const PRIORITY = 7;

    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly EstablishmentReachability $reachability,
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

        $request = $event->getRequest();
        if ($request->attributes->get('_route') === MeController::ROUTE) {
            return;
        }

        $raw = $request->headers->get(ContexteEtablissement::HEADER);
        if ($raw === null || $raw === '') {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return;
        }

        $id = $this->contexte->idActif();
        if ($id === null) {
            return;
        }

        if ($this->reachability->canReach($user, $id, new \DateTimeImmutable())) {
            return;
        }

        // Le message ne nomme pas l'établissement : le nommer reviendrait à confirmer son existence.
        throw new NotFoundHttpException('Ressource introuvable.');
    }
}
