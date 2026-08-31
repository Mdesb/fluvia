<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Crm\Entity\Client;
use App\Organisation\Service\EditorTenantResolver;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\UnknownCustomerException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le point où un abonnement payé devient un abonnement actif, et où le fait est annoncé (ED-3,
 * RG-ED-04).
 *
 * **Ce service n'appelle pas le provisioning, et c'est délibéré.** Il annonce
 * `subscription.activated` ; le provisioning y est abonné. Le tunnel ignore donc jusqu'à l'existence
 * du provisioning (D2), ce qui permet de rejouer l'événement, de le différer, ou d'y accrocher un
 * effet supplémentaire — courriel de bienvenue, ligne de facturation — sans revenir ici.
 *
 * **Le tenant de l'événement est l'établissement de l'éditeur, pas celui du client.** Au moment où
 * l'abonnement s'active, l'établissement du client n'existe pas encore : c'est précisément ce que le
 * provisioning va créer. L'événement appartient donc au périmètre où vit le commerce — celui de
 * l'éditeur (RG-ED-01, D12).
 *
 * **La désignation vient de {@see EditorTenantResolver}, plus d'une déduction** (Q-1, D36). On la
 * tirait de `Client::getEtablissementCreation()`, ce qui était juste mais indirect — et surtout
 * intenable pour le tunnel, où le prospect compose son panier avant d'avoir une fiche. La fiche
 * reste vérifiée, mais pour ce qu'elle est : l'intégrité du rattachement (RG-ED-02), pas la source
 * du périmètre.
 */
final class SubscriptionActivator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $bus,
        private readonly EditorTenantResolver $editorTenant,
    ) {
    }

    /**
     * Active l'abonnement et annonce le fait.
     *
     * L'abonnement est écrit **avant** la publication : le bus est synchrone (D7), et un abonné qui
     * lèverait une exception ne doit pas laisser derrière lui un abonnement annoncé actif mais resté
     * en brouillon en base.
     *
     * @throws UnknownCustomerException si la fiche CRM du prospect est introuvable ou hors périmètre
     */
    public function activate(Subscription $subscription, \DateTimeImmutable $at): void
    {
        $this->assertCustomerExists($subscription);
        $editor = $this->editorTenant->resolve();

        $subscription->transitionTo(SubscriptionStatus::Active, $at);
        $this->em->flush();

        $this->bus->publish(new DomainEvent(
            'subscription.activated',
            new EventTenant($editor->getId()),
            new EventSubject('Subscription', $subscription->getId()->toRfc4122()),
            [
                'planCode' => $subscription->getPlan()?->getCode() ?? '',
                'capabilities' => array_values($subscription->activeCapabilities($at)),
                'effectiveFrom' => $at->format(\DateTimeInterface::ATOM),
            ],
            null,
            // L'événement est horodaté à l'instant **métier**, pas à l'instant d'exécution. L'abonné
            // s'en sert pour calculer les capacités actives : laisser « maintenant » ferait
            // provisionner un abonnement qui prend effet plus tard sans les options qu'il a achetées,
            // et personne ne le verrait avant que le client ne cherche son module.
            $at,
        ));
    }

    /**
     * Un abonnement s'active toujours pour quelqu'un (RG-ED-02).
     *
     * On ne s'en sert plus pour trouver le périmètre — c'est le rôle de la désignation — mais activer
     * un abonnement dont la référence client ne désigne rien laisserait un provisionnement condamné
     * d'avance : il échouerait plus loin, après que le prélèvement a été accepté. Autant refuser ici.
     */
    private function assertCustomerExists(Subscription $subscription): void
    {
        if (null !== $this->em->getRepository(Client::class)->find($subscription->getCustomerReference())) {
            return;
        }

        throw new UnknownCustomerException(sprintf(
            'Abonnement « %s » : aucune fiche client « %s ». Activer un abonnement sans client '
            .'condamne son provisionnement, après encaissement.',
            $subscription->getId()->toRfc4122(),
            $subscription->getCustomerReference(),
        ));
    }
}
