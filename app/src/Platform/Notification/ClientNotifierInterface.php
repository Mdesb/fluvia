<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * Le point de passage **unique** pour atteindre un client.
 *
 * **Pourquoi il vit dans `Platform` et pas dans un module.** `SmartFlow\Port\
 * ClientNotificationInterface` existe déjà, mais ses méthodes prennent des entités Smart Flow
 * (`RescheduleProposal`, `SlotWaitlistEntry`) : le déplacer tel quel ferait dépendre le noyau d'un
 * module, ce que D3/D8 interdisent. Trois modules ont désormais besoin de notifier — Smart Flow,
 * Revenue Recovery et Campagnes (D42) — donc la capacité n'appartient à aucun des trois.
 *
 * **Ce que ce port rend possible, et qui est sa vraie raison d'être** : le consentement RGPD peut être
 * imposé **ici**, par un décorateur, plutôt que rappelé à chaque appelant. Le contrôle appartient à
 * `Crm`, qui possède le consentement ; `Platform` ne le connaît pas et n'a pas à le connaître. Un
 * chemin d'envoi qui contournerait le consentement ne doit pas exister — et il n'existera pas, parce
 * qu'il n'y a qu'un chemin.
 *
 * L'implémentation par défaut journalise (`LogClientNotifier`) : **aucun envoi réel n'existe dans ce
 * dépôt**, et cela dépend d'un prestataire (D19, D42 `CMP-6`).
 */
interface ClientNotifierInterface
{
    public function notify(ClientNotification $notification): NotificationOutcome;
}
