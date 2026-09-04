<?php

declare(strict_types=1);

namespace App\Subscription\EventListener;

use App\Crm\Entity\Client;
use App\Platform\Event\DomainEvent;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\Subscription\Entity\Subscription;
use App\Subscription\Service\SubscriptionFunnel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * Le courriel qui vérifie l'adresse d'un prospect avant de lui ouvrir une plateforme (ED-5).
 *
 * **Ce message n'est pas une politesse : c'est la seule garde du tunnel d'essai.** Le formulaire est
 * public et n'exige aucun paiement. Sans preuve que l'adresse existe et appartient au demandeur, une
 * boucle de requêtes créerait autant d'établissements réels — groupe, région, compte administrateur —
 * qu'elle veut. Le clic sur le lien de ce message est ce qui remplace le mandat SEPA du tunnel payant.
 *
 * ---
 *
 * **LE JETON EST FRAPPÉ ICI, PAS TRANSPORTÉ PAR L'ÉVÉNEMENT.** Même règle que
 * {@see EnvoyerCourrielDeBienvenue}, et pour la même raison : un événement se journalise, se rejoue
 * et se transporte, et un secret qui voyage dans ces conditions finit par vivre dans un fichier de
 * journal. La base ne garde que le `sha256` ; la valeur en clair n'existe que le temps de composer
 * le message.
 *
 * **LE LIEN POINTE SUR LA VITRINE, PAS SUR L'APPLICATION.** `FRONT_BASE_URL` désigne le back-office,
 * où ce prospect n'a encore aucun compte : le lien y afficherait une page de connexion, sur un
 * parcours où le visiteur n'a jamais choisi de mot de passe. D'où `VITRINE_BASE_URL`, distinct — et
 * une variable d'environnement plutôt qu'une valeur en dur parce que le site changera de domaine.
 *
 * ⚠ **RIEN NE PART TANT QUE E-8 N'EST PAS LEVÉ.** `ClientNotifierInterface` est câblé sur
 * `LogClientNotifier` et `MAILER_DSN=null://null` : ce message s'écrit dans le journal et n'arrive
 * chez personne. Le tunnel est donc complet et **inerte** — c'est la raison, et la seule, pour
 * laquelle l'essai ne peut pas encore être ouvert au public.
 */
#[AsEventListener(event: 'subscription.trial_requested')]
final class SendTrialConfirmationEmail
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientNotifierInterface $notifier,
        #[Autowire(env: 'VITRINE_BASE_URL')] private readonly string $vitrineBaseUrl,
    ) {
    }

    public function __invoke(DomainEvent $event): void
    {
        $subscription = $this->em->getRepository(Subscription::class)->find(
            Uuid::fromString($event->subject->id)
        );

        if (!$subscription instanceof Subscription) {
            return;
        }

        $prospect = $this->em->getRepository(Client::class)->find($subscription->getCustomerReference());
        if (!$prospect instanceof Client) {
            // Sans fiche, on n'a pas d'adresse à qui écrire. Le tunnel a déjà refusé ce cas en amont
            // ({@see \App\Subscription\Service\SubscriptionActivator}) ; ici, on se tait.
            return;
        }

        $jetonClair = bin2hex(random_bytes(32));
        $subscription->setEmailConfirmationTokenHash(hash('sha256', $jetonClair));
        $this->em->flush();

        $this->notifier->notify(new ClientNotification(
            $prospect->getId(),
            NotificationChannel::Email,
            'abonnement.essai.confirmation',
            [
                'structure' => $prospect->getRaisonSociale() ?? '',
                'lienConfirmation' => sprintf(
                    '%s/confirmation.html?jeton=%s',
                    rtrim($this->vitrineBaseUrl, '/'),
                    $jetonClair,
                ),
                'validiteHeures' => SubscriptionFunnel::CONFIRMATION_HOURS,
                'joursEssai' => SubscriptionFunnel::TRIAL_DAYS,
            ],
            $event->occurredAt,
            'subscription.trial',
            // Message dû au titre de la demande du prospect lui-même : il vient de la formuler, et
            // sans cette réponse il n'obtient pas ce qu'il a demandé. Ce n'est pas de la prospection.
            NotificationBasis::Contractuelle,
        ));
    }
}
