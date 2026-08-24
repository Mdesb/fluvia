<?php

declare(strict_types=1);

namespace App\Subscription\EventListener;

use App\Platform\Event\DomainEvent;
use App\Subscription\Entity\Subscription;
use App\Subscription\Service\ProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Provisionne l'établissement du client dès que son abonnement s'active (ED-3, RG-ED-04).
 *
 * **C'est ici que se réalise le découplage de D2.** Le tunnel de souscription annonce un fait ; ce
 * n'est pas lui qui décide qu'un établissement doit naître. On peut donc rejouer l'événement,
 * provisionner en différé, ou ajouter demain un courriel de bienvenue, sans toucher au tunnel.
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
 * **Pourquoi il n'émet pas `establishment.provisioned`.** Ce serait le fait naturel à annoncer, mais
 * personne ne l'écouterait : son consommateur prévu est le courriel de bienvenue, qui relève de
 * `Communication`. Un nom au catalogue sans preneur est une dette que le cliquet compte et refuse de
 * laisser croître — à raison. L'émission viendra avec son abonné, dans le même commit.
 */
#[AsEventListener(event: 'subscription.activated')]
final class ProvisionOnSubscriptionActivated
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProvisioningService $provisioning,
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
        $this->provisioning->provision($subscription, $event->occurredAt);
    }
}
