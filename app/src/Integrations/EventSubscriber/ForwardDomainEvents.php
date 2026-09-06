<?php

declare(strict_types=1);

namespace App\Integrations\EventSubscriber;

use App\Integrations\Entity\OutboundEndpoint;
use App\Integrations\Service\OutboundDispatcher;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\SymfonyEventBus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RELAIE LES ÉVÉNEMENTS DE DOMAINE VERS LES DESTINATIONS CONFIGURÉES.
 *
 * ── ⚠ POURQUOI DÉCORER LE BUS PLUTÔT QUE S'Y ABONNER ───────────────────────────────────────────
 *
 * `getSubscribedEvents()` est STATIQUE : elle ne peut pas dépendre de ce que chaque établissement a
 * coché sur ses destinations. `NotifyOnDomainEvent` s'en sort avec une liste figée de six
 * événements admis ; ici il en faut cinquante-huit, et surtout la liste doit suivre les
 * établissements, pas le code.
 *
 * Décorer `EventBus` règle ça en une ligne : tout ce qui est publié passe par là, quel que soit son
 * nom, sans liste à tenir à jour au fil des modules qui en ajoutent.
 *
 * ── ⚠ ET SURTOUT : ON N'APPELLE RIEN PENDANT `publish()` ───────────────────────────────────────
 *
 * Un appel HTTP dans le chemin de publication ferait attendre CE QUI A ÉMIS l'événement. Une vente
 * encaissée attendrait Slack ; un Slack lent ajouterait trois secondes à une caisse, devant un
 * client. C'est inacceptable, et c'est exactement le genre de couplage qu'on ne voit qu'en
 * production un samedi.
 *
 * Les événements sont donc COLLECTÉS pendant la requête, et envoyés à `kernel.terminate` — après
 * que la réponse est partie chez l'utilisateur. Le coût devient invisible.
 *
 * ⚠ `console.terminate` EST TRAITÉ AUSSI, ET CE N'EST PAS UN DÉTAIL : les vingt-quatre commandes
 * planifiées émettent des événements, et `kernel.terminate` ne se déclenche JAMAIS en ligne de
 * commande. Sans ce second point de sortie, tout ce qui vient de l'ordonnanceur — une échéance
 * dépassée, un impayé, un rappel — serait collecté puis jeté en silence.
 *
 * ── ⚠ CE QUI RESTE VRAI : IL N'Y A AUCUN RÉESSAI ───────────────────────────────────────────────
 *
 * Un processus qui meurt entre la collecte et la sortie perd ses messages, et un canal indisponible
 * les perd aussi. C'est acceptable pour de l'alerte ; ça ne le serait pas pour un flux qui doit
 * être complet. Le jour où quelqu'un branche là-dessus autre chose que de l'alerte, il lui faut une
 * file, pas ce service.
 */
/**
 * ⚠ ON DÉCORE LE SERVICE CONCRET, PAS L'INTERFACE — et ce n'est pas un détail de style.
 *
 * `EventBus` n'est pas un service déclaré : Symfony en fabriquait l'alias tout seul parce qu'UNE
 * SEULE classe l'implémentait. Cette classe-ci en est une seconde, et l'alias automatique
 * disparaît alors — silencieusement, en emportant les six autres consommateurs.
 *
 * L'alias est donc déclaré à la main dans `services.yaml`, dans le même commit. Comme la décoration
 * réattribue l'identifiant du service concret au décorateur, cet alias résout vers lui : rien ne
 * change pour ceux qui injectent l'interface.
 */
#[AsDecorator(decorates: SymfonyEventBus::class)]
final class ForwardDomainEvents implements EventBus, EventSubscriberInterface
{
    /** @var list<DomainEvent> */
    private array $enAttente = [];

    public function __construct(
        #[AutowireDecorated]
        private readonly EventBus $interne,
        private readonly EntityManagerInterface $em,
        private readonly OutboundDispatcher $expediteur,
    ) {
    }

    public function publish(DomainEvent $event): void
    {
        // ⚠ LE BUS D'ABORD, TOUJOURS. Si la collecte levait, elle empêcherait la publication réelle
        // — un connecteur casserait le cœur de la plateforme.
        $this->interne->publish($event);

        try {
            $this->enAttente[] = $event;
        } catch (\Throwable) {
            // Rien : un relais qui n'arrive pas à noter ce qu'il doit relayer ne doit rien casser.
        }
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'vider',
            ConsoleEvents::TERMINATE => 'vider',
        ];
    }

    public function vider(): void
    {
        if ($this->enAttente === []) {
            return;
        }

        $evenements = $this->enAttente;
        // ⚠ ON VIDE AVANT D'ENVOYER. Un envoi qui relancerait la publication d'un événement (ce que
        // rien ne fait aujourd'hui, mais rien ne l'interdit) ferait boucler la liste sur elle-même.
        $this->enAttente = [];

        try {
            $depot = $this->em->getRepository(OutboundEndpoint::class);

            foreach ($evenements as $evenement) {
                /** @var list<OutboundEndpoint> $destinations */
                $destinations = $depot->findBy([
                    // ⚠ LE PÉRIMÈTRE VIENT DE L'ÉVÉNEMENT, JAMAIS DE L'EN-TÊTE DE LA REQUÊTE.
                    // `X-Etablissement` est un sélecteur d'écran ; il peut désigner un établissement
                    // qui n'est pas celui du fait qu'on relaie — et une commande planifiée n'en a
                    // aucun. L'événement, lui, porte son locataire.
                    'establishment' => $evenement->tenant->establishmentId,
                    'actif' => true,
                ]);

                foreach ($destinations as $destination) {
                    if (!$destination->ecoute((string) $evenement->name)) {
                        continue;
                    }
                    $this->expediteur->envoyer($destination, $evenement);
                }
            }
        } catch (\Throwable) {
            // Best-effort de bout en bout : après la réponse, plus rien ne doit pouvoir échouer
            // bruyamment. Le détail de chaque échec est déjà consigné sur la destination concernée.
        }
    }
}
