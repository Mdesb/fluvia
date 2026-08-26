<?php

declare(strict_types=1);

namespace App\Reservation\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Ce qui se passe sur une ressource pendant une plage de dates — **en une seule réponse**.
 *
 * **Pourquoi les fermetures voyagent avec les créneaux.** La question posée par un calendrier n'est
 * pas « quels créneaux existent » puis « quelles fermetures existent » : c'est *« que se passe-t-il
 * sur cette ressource ce mois-ci »*, et une fermeture y répond autant qu'un créneau.
 *
 * `claude-H` a donné la raison décisive, qui n'est pas le confort : **deux appels, ce sont deux états
 * de chargement**, donc une fenêtre courte mais réelle où l'écran a reçu les créneaux et pas encore
 * les fermetures. Pendant cette fenêtre il affiche **un créneau réservable sur une journée fermée** —
 * un écran temporairement faux qui a l'air juste, et personne ne rafraîchit pour vérifier ce qui
 * s'affiche déjà correctement.
 *
 * **Aucune donnée personnelle ici.** Ni participants, ni organisateurs : un calendrier de charge n'en
 * a pas besoin, et un balayage mensuel diffuserait les noms de tous les réservants du mois à quiconque
 * ouvre la vue. Le jour où un écran devra dire « réservé par », ce sera une requête **ciblée**, au
 * clic — et c'est une décision pour Maxime, pas un détail d'implémentation.
 */
final class ResourceOccupancy
{
    /**
     * @param list<array<string, mixed>> $creneaux
     * @param list<array<string, mixed>> $fermetures
     */
    public function __construct(
        #[Groups(['occupation:read'])]
        public readonly string $ressource,
        #[Groups(['occupation:read'])]
        public readonly string $libelle,
        #[Groups(['occupation:read'])]
        public readonly string $du,
        #[Groups(['occupation:read'])]
        public readonly string $au,
        /**
         * Les créneaux de la plage, avec leur occupation **calculée par le serveur**.
         *
         * L'écran ne peut pas la calculer : l'occupation ne compte pas les réservations qui *visent*
         * le créneau mais celles qui le **consomment** — une table réservée à 20 h consomme le service
         * du soir de la salle (D33) — et `Reservation::$consumedSlots` n'est délibérément pas
         * sérialisé. Un client qui filtrerait par créneau afficherait **zéro sur un service complet**.
         */
        #[Groups(['occupation:read'])]
        public readonly array $creneaux,
        /** Les indisponibilités qui recoupent la plage : maintenance, fermeture annuelle, travaux. */
        #[Groups(['occupation:read'])]
        public readonly array $fermetures,
    ) {
    }
}
