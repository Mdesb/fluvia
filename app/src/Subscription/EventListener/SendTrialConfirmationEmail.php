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
use App\Subscription\Service\TrialConfirmationLink;
use Doctrine\ORM\EntityManagerInterface;
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
 * **LE JETON N'EST PAS TRANSPORTÉ PAR L'ÉVÉNEMENT.** Même règle que
 * {@see EnvoyerCourrielDeBienvenue}, et pour la même raison : un événement se journalise, se rejoue
 * et se transporte, et un secret qui voyage dans ces conditions finit par vivre dans un fichier de
 * journal. Il est frappé au moment de composer le message, par
 * {@see \App\Subscription\Service\TrialConfirmationLink} — qui porte aussi la forme du lien et
 * la raison pour laquelle il pointe sur la vitrine et non sur le back-office. La base ne garde que
 * le `sha256` ; la valeur en clair n'existe que le temps de l'envoi.
 *
 * ⚠ **RIEN NE PART TANT QUE E-8 N'EST PAS LEVÉ.** `ClientNotifierInterface` est câblé sur
 * `LogClientNotifier` et `MAILER_DSN=null://null` : ce message n'arrive chez personne. Le tunnel est
 * donc complet et **inerte** — c'est la raison, et la seule, pour laquelle l'essai ne peut pas
 * encore être ouvert au public.
 *
 * ⚠ **ET IL NE S'ÉCRIT MÊME PAS DANS LE JOURNAL.** `LogClientNotifier` ne journalise ni le contenu
 * ni les variables — délibérément, elles portent un nom, une adresse, un solde. Le lien n'existe
 * donc **nulle part** après l'envoi : E-8 ne bloque pas seulement l'ouverture au public, il bloque
 * la recette du tunnel. C'est pourquoi
 * {@see \App\Subscription\Command\IssueTrialConfirmationLinkCommand} existe — un contournement
 * assumé, fermé par défaut, à retirer le jour où un expéditeur réel est branché.
 */
#[AsEventListener(event: 'subscription.trial_requested')]
final class SendTrialConfirmationEmail
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientNotifierInterface $notifier,
        /**
         * ⚠ LA FRAPPE DU JETON EST SORTIE D'ICI, ET CE N'EST PAS UN RANGEMENT.
         *
         * L'outil de recette du tunnel ({@see \App\Subscription\Command\IssueTrialConfirmationLinkCommand})
         * a besoin exactement du même geste. Deux frappes séparées auraient pu diverger — longueur,
         * hachage, forme du lien — sans que rien ne le signale, et l'outil aurait alors prouvé son
         * propre chemin plutôt que celui du prospect.
         */
        private readonly TrialConfirmationLink $liens,
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

        $lienConfirmation = $this->liens->issue($subscription);

        $this->notifier->notify(new ClientNotification(
            $prospect->getId(),
            NotificationChannel::Email,
            'abonnement.essai.confirmation',
            [
                'structure' => $prospect->getRaisonSociale() ?? '',
                'lienConfirmation' => $lienConfirmation,
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
