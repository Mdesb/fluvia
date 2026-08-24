<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
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
 * l'éditeur (RG-ED-01, D12) — et on le déduit de la fiche CRM du prospect, seule donnée qui le porte
 * aujourd'hui.
 */
final class SubscriptionActivator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $bus,
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
        $editor = $this->editorEstablishment($subscription);

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
        ));
    }

    /**
     * L'établissement de l'éditeur, déduit de la fiche CRM du prospect.
     *
     * Rien dans le dépôt ne désigne aujourd'hui « l'établissement éditeur » : il n'existe ni
     * paramètre, ni marqueur. `etablissementCreation` de la fiche client est la seule donnée qui le
     * porte — c'est bien l'éditeur, puisque c'est son CRM qui a créé le prospect. La déduction est
     * juste mais indirecte, et elle ne tiendra plus le jour où un prospect anonyme composera son
     * panier avant d'avoir une fiche.
     */
    private function editorEstablishment(Subscription $subscription): Etablissement
    {
        $client = $this->em->getRepository(Client::class)->find($subscription->getCustomerReference());
        if (!$client instanceof Client) {
            throw new UnknownCustomerException(sprintf(
                'Abonnement « %s » : aucune fiche client « %s ». Un abonnement sans client ne peut pas '
                .'être rattaché à un périmètre, et un événement sans tenant est refusé par le contrat.',
                $subscription->getId()->toRfc4122(),
                $subscription->getCustomerReference(),
            ));
        }

        $editor = $client->getEtablissementCreation();
        if (!$editor instanceof Etablissement) {
            throw new UnknownCustomerException(sprintf(
                'La fiche client « %s » n\'est rattachée à aucun établissement : impossible de savoir '
                .'dans quel périmètre l\'abonnement est vendu.',
                $subscription->getCustomerReference(),
            ));
        }

        return $editor;
    }
}
