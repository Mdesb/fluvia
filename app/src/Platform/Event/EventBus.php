<?php

declare(strict_types=1);

namespace App\Platform\Event;

/**
 * Le point de passage unique des faits du domaine.
 *
 * Les modules publient ici et s'abonnent par **nom d'événement** ; ils ne s'appellent jamais
 * directement (D2). L'interface est volontairement réduite à une méthode : tout ce qu'un module a le
 * droit de faire, c'est annoncer qu'un fait s'est produit. Il ne choisit ni les destinataires, ni
 * l'ordre, ni le moment.
 *
 * Implémentation v0 : {@see SymfonyEventBus}, **synchrone in-process** (D7).
 */
interface EventBus
{
    /**
     * Publie un fait accompli.
     *
     * Synchrone : au retour, tous les abonnés ont été exécutés. Si l'un d'eux lève une exception, elle
     * remonte à l'émetteur et interrompt son traitement (RG-PLAT-05) — la cohérence prime sur la
     * disponibilité. Un abonné *best-effort* (e-mail, notification) doit donc attraper ses propres
     * erreurs : il ne casse jamais l'action métier.
     *
     * @throws Exception\EventBusOverflowException si la profondeur de publication est dépassée
     */
    public function publish(DomainEvent $event): void;
}
