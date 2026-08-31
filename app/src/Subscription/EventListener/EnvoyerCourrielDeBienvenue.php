<?php

declare(strict_types=1);

namespace App\Subscription\EventListener;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Subscription\Entity\ProvisioningRequest;
use App\Subscription\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * Le courriel de bienvenue : le client qui vient de payer reçoit ses accès (ED-9).
 *
 * **Sans lui, le provisionnement est muet.** Un client paie, son établissement est créé, son compte
 * administrateur existe — et personne ne le lui dit. Il attend un courriel qui n'arrive pas, puis il
 * appelle. C'est le dernier maillon manquant entre « il a payé » et « il utilise ».
 *
 * ---
 *
 * **BASE LÉGALE : CONTRACTUELLE, ET DÉCLARÉE EXPLICITEMENT.**
 *
 * Le défaut de {@see NotificationBasis} est `Consentement`, volontairement : qui ne se pose pas la
 * question voit son message refusé, ce qui se voit et se corrige. Ici la question se pose et la
 * réponse est claire — livrer ses identifiants à quelqu'un qui vient d'acheter une plateforme est
 * **dû au titre du contrat**, pas une sollicitation.
 *
 * **Ce fondement ne couvre que ce message-là.** Le jour où quelqu'un voudra ajouter ici « découvrez
 * nos autres modules », ce ne sera plus un message dû : ce sera de la prospection envoyée à des gens
 * qui n'ont rien demandé, sous couvert d'un courriel qu'ils attendaient. C'est exactement l'abus que
 * la loi vise, et le seul garde-fou à cet endroit est de le lire avant d'écrire la ligne.
 *
 * ---
 *
 * **LE JETON EST FRAPPÉ ICI, PAS TRANSPORTÉ PAR L'ÉVÉNEMENT.**
 *
 * `establishment.provisioned` ne porte aucun secret : un événement se journalise, se rejoue, se
 * transporte, et un identifiant de connexion qui voyage dans ces conditions finit par vivre dans un
 * fichier de journal. Celui qui délivre le jeton le génère donc lui-même, au moment de l'envoi —
 * même geste que `ReinvitationProcessor`. La base n'en garde que le `sha256` ; la valeur en clair
 * n'existe que le temps de composer le message.
 */
#[AsEventListener(event: 'establishment.provisioned')]
final class EnvoyerCourrielDeBienvenue
{
    /** Durée de validité de l'invitation, alignée sur celle du socle. */
    private const HEURES_VALIDITE = 72;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientNotifierInterface $notifier,
    ) {
    }

    public function __invoke(DomainEvent $event): void
    {
        $administrateur = $this->administrateur($event);
        $etablissement = $this->em->getRepository(Etablissement::class)->find($event->tenant->establishmentId);

        if (!$administrateur instanceof Utilisateur || !$etablissement instanceof Etablissement) {
            // Provisionnement incomplet : rien à annoncer. Un courriel de bienvenue qui arrive sans
            // que le compte existe est pire que pas de courriel.
            return;
        }

        $client = $this->clientDe($etablissement);
        if (!$client instanceof Client) {
            return;
        }

        $jetonClair = $this->frapperInvitation($administrateur);

        $this->notifier->notify(new ClientNotification(
            $client->getId(),
            NotificationChannel::Email,
            'abonnement.bienvenue',
            [
                'etablissement' => $etablissement->getNom(),
                'administrateur' => $administrateur->getNom(),
                'email' => $administrateur->getEmail(),
                'jetonActivation' => $jetonClair,
                'validiteHeures' => self::HEURES_VALIDITE,
            ],
            $event->occurredAt,
            'subscription.provisioning',
            // Message dû au titre du contrat. Voir le commentaire de classe avant d'y toucher.
            NotificationBasis::Contractuelle,
        ));
    }

    /**
     * Régénère l'invitation de l'administrateur et rend le jeton en clair.
     *
     * Régénérer plutôt que réutiliser : le jeton posé au provisionnement n'a jamais été délivré à
     * personne, et le remplacer garantit que celui qui part dans ce courriel est le seul en
     * circulation.
     */
    private function frapperInvitation(Utilisateur $administrateur): string
    {
        $jetonClair = bin2hex(random_bytes(32));

        $administrateur->setJetonInvitation(hash('sha256', $jetonClair))
            ->setJetonInvitationExpire(new \DateTimeImmutable('+'.self::HEURES_VALIDITE.' hours'))
            ->setStatut(StatutUtilisateur::Invite);

        $this->em->flush();

        return $jetonClair;
    }

    private function administrateur(DomainEvent $event): ?Utilisateur
    {
        $id = $event->payload['adminUserId'] ?? null;

        if (!\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(Utilisateur::class)->find(Uuid::fromString($id));
    }

    /**
     * La fiche CRM du client, retrouvée par l'abonnement qui a produit cet établissement.
     *
     * @cloisonnement-verifie: l etablissement vient du TENANT de l evenement, pas d une entree
     * client — c est le bus qui l a pose au moment du provisionnement. La demande de provisionnement
     * est ensuite resolue PAR cet etablissement, jamais par un identifiant fourni de l exterieur.
     */
    private function clientDe(Etablissement $etablissement): ?Client
    {
        $demande = $this->em->getRepository(ProvisioningRequest::class)->findOneBy(['establishment' => $etablissement]);
        $abonnement = $demande?->getSubscription();

        if (!$abonnement instanceof Subscription) {
            return null;
        }

        return $this->em->getRepository(Client::class)->find($abonnement->getCustomerReference());
    }
}
