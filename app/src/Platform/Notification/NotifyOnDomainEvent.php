<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Organisation\Entity\Etablissement;
use App\Platform\Entity\Notification;
use App\Platform\Event\DomainEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * CE QUI REMPLIT LA CLOCHE — ET SANS QUOI ELLE SERAIT UN DÉCOR.
 *
 * Une notification n'invente rien : c'est un événement de domaine dont on a décidé qu'il appelait le
 * geste d'une personne. Le producteur existait déjà ; il manquait la décision.
 *
 * ⚠ C'EST LA MOITIÉ QUI MANQUE À LA PLUPART DES MÉCANISMES DE CE DÉPÔT. Vingt-trois commandes
 * planifiées existent et aucune ne se déclenche ; des abonnés sont écrits et rien ne les appelle.
 * Une table de notifications que rien ne remplit aurait rejoint cette liste — avec une pastille
 * rouge pour la rendre crédible.
 *
 * ── BEST-EFFORT : UNE NOTIFICATION NE FAIT JAMAIS ÉCHOUER CE QUI L'A DÉCLENCHÉE ─────────────────
 *
 * ⚠ Si prévenir quelqu'un d'un paiement échoué faisait échouer l'enregistrement du paiement échoué,
 * le remède serait pire que le mal. L'exception est journalisée et avalée — même patron que les
 * autres abonnés du bus (D7).
 *
 * ── ON NE `flush()` PAS ICI ─────────────────────────────────────────────────────────────────────
 *
 * L'événement est publié pendant la transaction de l'émetteur. Forcer l'écriture depuis un abonné
 * validerait au passage tout ce que l'émetteur avait en cours et qu'il n'a peut-être pas fini. On
 * persiste, et l'émetteur écrit — ou n'écrit pas, et la notification disparaît avec le fait qu'elle
 * annonçait. C'est le comportement juste : une alerte pour une vente annulée n'a pas lieu d'être.
 */
final class NotifyOnDomainEvent implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRecipientResolver $recipients,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        $abonnements = [];
        foreach (NotificationRule::admittedEvents() as $nom) {
            $abonnements[$nom] = 'onDomainEvent';
        }

        return $abonnements;
    }

    /**
     * Le titre, suivi de ce qui NOMME le cas quand la charge utile le porte.
     *
     * ⚠ L'ABSENCE D'ANCRE N'EST PAS UNE ERREUR. Un evenement peut ne pas porter la cle — un incident
     * sans echeance d'origine, par exemple. On rend alors le titre seul plutot qu'un titre suivi
     * d'un tiret et de rien : une ligne qui montre son gabarit est pire qu'une ligne courte.
     *
     * @param array<string, scalar|array|null> $payload
     */
    private function titreAncre(NotificationRule $regle, array $payload): string
    {
        if ($regle->anchorKey === null) {
            return $regle->title;
        }

        $ancre = $payload[$regle->anchorKey] ?? null;
        if (!\is_scalar($ancre) || (string) $ancre === '') {
            return $regle->title;
        }

        return $regle->title.' — '.(string) $ancre;
    }

    public function onDomainEvent(DomainEvent $event): void
    {
        try {
            $regle = NotificationRule::forEvent($event->name->value);
            if ($regle === null) {
                return;
            }

            $etablissement = $this->em->find(Etablissement::class, $event->tenant->establishmentId);
            if ($etablissement === null) {
                // Un événement dont l'établissement n'existe plus : on n'invente pas de destinataire.
                return;
            }

            $destinataires = $this->recipients->resolve(
                $event->tenant->establishmentId,
                $regle->module,
                $regle->action,
            );

            foreach ($destinataires as $destinataire) {
                $notification = (new Notification())
                    ->setDestinataire($destinataire)
                    ->setEtablissement($etablissement)
                    ->setHorodatage($event->occurredAt)
                    ->setGravite($regle->severity)
                    ->setTitre($this->titreAncre($regle, $event->payload))
                    ->setTexte($regle->text)
                    ->setEcran($regle->screen)
                    ->setParams([$regle->paramName => $event->subject->id])
                    ->setSource($regle->eventName);

                $this->em->persist($notification);
            }
        } catch (\Throwable $erreur) {
            // ⚠ Jamais de propagation vers l'émetteur : prévenir d'un incident ne doit pas empêcher
            // de l'enregistrer.
            $this->logger?->error('Notification non posée : {message}', [
                'message' => $erreur->getMessage(),
                'evenement' => $event->name->value,
            ]);
        }
    }
}
