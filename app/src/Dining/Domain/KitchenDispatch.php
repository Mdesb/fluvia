<?php

declare(strict_types=1);

namespace App\Dining\Domain;

use App\Dining\Entity\DiningOrderLine;
use App\Dining\Enum\LineStatus;
/**
 * L'envoi en cuisine : quelles lignes partent, ensemble, et dans quel ordre (ACT-4, D16).
 *
 * **Un service part d'un bloc.** Envoyer les plats un par un, au fil de la saisie, obligerait la
 * cuisine à deviner quand une table est complète — et une table dont les plats sortent à cinq minutes
 * d'intervalle est une table mécontente. Le bon de cuisine est donc un **service entier**, daté.
 */
final class KitchenDispatch
{
    /**
     * Prépare l'envoi d'un service : les lignes au brouillon de ce service, et elles seules.
     *
     * **Refuse tant qu'un service antérieur attend encore au brouillon.** C'est la faute réelle du
     * coup de feu : on saisit les entrées, on saisit les plats, on envoie les plats — et les entrées
     * restent à l'écran. La table reçoit son plat principal en premier, et personne ne comprend
     * pourquoi avant le dessert.
     *
     * On ne contrôle **que** les brouillons antérieurs : un service déjà envoyé n'empêche rien, c'est
     * même le déroulement normal du repas.
     *
     * @param list<DiningOrderLine> $lignes toutes les lignes de la table
     *
     * @return list<DiningOrderLine> les lignes qui partiront, dans l'ordre de saisie
     *
     * @throws \LogicException si un service antérieur n'a pas été envoyé
     */
    public function prepare(CourseRef $service, array $lignes): array
    {
        foreach ($lignes as $ligne) {
            if (LineStatus::Draft === $ligne->getStatus() && $ligne->getCourse()->precedes($service)) {
                throw new \LogicException(sprintf(
                    'Le service « %s » attend encore au brouillon : l\'envoyer après « %s » ferait sortir les plats avant les entrées.',
                    $ligne->getCourse()->code,
                    $service->code,
                ));
            }
        }

        $aEnvoyer = [];
        foreach ($lignes as $ligne) {
            if (LineStatus::Draft === $ligne->getStatus() && $ligne->getCourse()->equals($service)) {
                $aEnvoyer[] = $ligne;
            }
        }

        return $aEnvoyer;
    }

    /**
     * Envoie le service et rend le bon de cuisine.
     *
     * **Un envoi vide est refusé** plutôt que silencieux : un serveur qui appuie sur « envoyer » et
     * ne voit rien se passer appuiera une seconde fois, puis ira voir en cuisine. Mieux vaut lui dire
     * qu'il n'y avait rien à envoyer.
     *
     * @param list<DiningOrderLine> $lignes
     *
     * @return list<DiningOrderLine> les lignes effectivement parties
     */
    public function fire(CourseRef $service, array $lignes, \DateTimeImmutable $at): array
    {
        $aEnvoyer = $this->prepare($service, $lignes);

        if ([] === $aEnvoyer) {
            throw new \LogicException(sprintf(
                'Aucune ligne au brouillon pour le service « %s » : il n\'y a rien à envoyer.',
                $service->code,
            ));
        }

        foreach ($aEnvoyer as $ligne) {
            $ligne->fire($at);
        }

        return $aEnvoyer;
    }
}
