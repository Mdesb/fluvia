<?php

declare(strict_types=1);

namespace App\Subscription\EventListener;

use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Subscription\Entity\Subscription;
use App\Subscription\Service\ProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Provisionne l'établissement du client dès que son abonnement s'active (ED-3, RG-ED-04).
 *
 * **C'est ici que se réalise le découplage de D2.** Le tunnel de souscription annonce un fait ; ce
 * n'est pas lui qui décide qu'un établissement doit naître. On peut donc rejouer l'événement,
 * provisionner en différé, ou ajouter un effet, sans toucher au tunnel.
 *
 * **L'abonnement se fait par nom, pas par classe** : le bus dispatche toujours {@see DomainEvent} en
 * passant `subscription.activated` comme nom d'événement. S'abonner à la classe attraperait *tous*
 * les faits du domaine.
 *
 * **Cet abonné ne lève jamais d'exception sur un échec métier.** Le bus est synchrone et une
 * exception d'abonné remonte à l'émetteur (RG-PLAT-05) : un rôle modèle manquant ferait alors échouer
 * l'activation d'un abonnement déjà payé. Le provisioning trace ses échecs dans
 * `ProvisioningRequest` — c'est là qu'on les lit, pas dans une pile d'appels.
 *
 * **Il annonce `establishment.provisioned` — et seulement quand la plateforme existe vraiment.**
 * L'événement a attendu deux jours d'avoir un preneur : le catalogue refuse un nom que personne
 * n'écoute, et il a raison. Son consommateur est le courriel de bienvenue.
 *
 * **La charge utile ne porte aucun secret.** Le jeton d'activation de l'administrateur n'y figure
 * pas : un événement se journalise, se rejoue, se transporte, et un identifiant de connexion qui
 * voyage dans ces conditions finit par vivre dans un fichier de journal. Celui qui délivre le jeton
 * le frappe lui-même, au moment de l'envoi.
 */
#[AsEventListener(event: 'subscription.activated')]
final class ProvisionOnSubscriptionActivated
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProvisioningService $provisioning,
        private readonly EventBus $bus,
    ) {
    }

    public function __invoke(DomainEvent $event): void
    {
        $subscription = $this->em->getRepository(Subscription::class)->find($event->subject->id);
        if (!$subscription instanceof Subscription) {
            return;
        }

        // `occurredAt` plutôt que « maintenant » : les capacités actives se calculent à la date du
        // fait. Un événement rejoué le lendemain doit provisionner ce qui avait été acheté la veille.
        $outcome = $this->provisioning->provision($subscription, $event->occurredAt);

        // Rejeu : l'établissement existe déjà et son arrivée a déjà été annoncée. Le réannoncer ferait
        // agir deux fois tous les abonnés en aval — dont le courriel de bienvenue.
        if (!$outcome->isFirstRun()) {
            return;
        }

        $establishment = $outcome->request->getEstablishment();
        if (null === $establishment) {
            // Échec tracé dans la demande, avec sa cause en clair. Rien à annoncer : annoncer une
            // plateforme qui n'existe pas ferait envoyer un courriel de bienvenue à un client qui
            // n'a rien reçu.
            return;
        }

        $this->bus->publish(new DomainEvent(
            'establishment.provisioned',
            new EventTenant($establishment->getId()),
            new EventSubject('Establishment', $establishment->getId()->toRfc4122()),
            [
                'establishmentId' => $establishment->getId()->toRfc4122(),
                'adminUserId' => $outcome->administratorId?->toRfc4122(),
                // La clé qui a rendu le provisionnement idempotent, pour que l'aval puisse dédupliquer
                // sur la même base que nous plutôt que d'inventer la sienne.
                'idempotencyKey' => $subscription->getId()->toRfc4122(),
            ],
            null,
            $event->occurredAt,
        ));
    }
}
