<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * Sur quel fondement on écrit à quelqu'un.
 *
 * **Pourquoi cette distinction existe, et pourquoi elle a failli manquer.** Le décorateur de
 * consentement livré ce matin (CMP-1) refusait **toute** notification sans consentement — y compris un
 * courriel de bienvenue à un client qui vient de souscrire. C'est faux en droit et absurde en pratique :
 * un message nécessaire à l'exécution du contrat n'a pas besoin d'un consentement **marketing**, et le
 * refuser priverait le client de ce qu'il a acheté. Défaut trouvé en répondant à une question de
 * `claude-D` sur le courriel de bienvenue, pas par un test.
 *
 * **Le défaut est `Consentement`, à dessein.** Un développeur qui ne se pose pas la question tombe donc
 * du côté prudent : son message sera refusé faute de consentement, ce qui se voit et se corrige.
 * L'inverse — un défaut contractuel — enverrait des messages marketing à des gens qui les ont refusés,
 * ce qui ne se voit pas et ne se corrige plus.
 *
 * **`Contractuelle` se déclare, elle ne se déduit pas.** Facture, confirmation de commande, accès
 * ouvert, mandat signé, mot de passe : ce que le client a demandé en contractant. **Ce n'est pas** une
 * relance commerciale déguisée en information de service, et c'est exactement l'abus que la loi vise.
 */
enum NotificationBasis: string
{
    /** Nécessaire à l'exécution du contrat : le consentement marketing n'est pas requis. */
    case Contractuelle = 'contractuelle';

    /** Prospection : le consentement est requis, par canal, et il est refusé s'il manque. */
    case Consentement = 'consentement';
}
