<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ACT-1 point 3 / D33 — résout les **créneaux consommés** par une réservation.
 *
 * Une réservation garde **un seul créneau visé** : celui que le client a choisi, celui qui s'affiche,
 * celui dont parle RG-M5-01. Ce qu'elle *consomme* est plus large : le visé, plus les créneaux des
 * ressources **ancêtres** qui le couvrent dans le temps. Une table réservée à 20 h consomme la table
 * et le service du soir de la salle ; un moniteur consomme le moniteur et le créneau de l'école.
 *
 * **Le résultat est destiné à être stocké, pas recalculé à chaque contrôle** (D33). Remonter l'arbre
 * à chaque vérification serait plus léger et faux : ce qu'on relâche doit être exactement ce qu'on a
 * pris, et un contrôle dérivé d'un parcours se course avec lui-même dès deux réservations
 * simultanées sur la même salle.
 *
 * **La chaîne est parcourue en entier**, pas sur un seul niveau. `Ressource::ressourcePorteuseJauge()`
 * s'arrête à `ressourceMere ?? $this` — correct pour la jauge globale qu'elle sert, insuffisant ici :
 * `ressourceMere` est une auto-référence, donc une ligne d'eau peut avoir un bassin qui a lui-même un
 * espace. Un garde-fou anti-cycle borne la remontée, parce que rien dans le modèle n'interdit
 * aujourd'hui à une ressource d'être sa propre aïeule.
 */
final class ConsumedSlotResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<Creneau> le créneau visé en premier, puis les créneaux ancêtres qui le couvrent
     */
    public function resolve(Creneau $vise): array
    {
        $consommes = [$vise];
        $vus = [];

        $ressource = $vise->getRessource();
        while ($ressource instanceof Ressource) {
            $mere = $ressource->getRessourceMere();
            if (!$mere instanceof Ressource) {
                break;
            }

            $cle = (string) $mere->getId();
            if (isset($vus[$cle])) {
                break; // cycle dans l'arbre des ressources : on s'arrête plutôt que de boucler.
            }
            $vus[$cle] = true;

            foreach ($this->creneauxCouvrants($mere, $vise) as $couvrant) {
                $consommes[] = $couvrant;
            }

            $ressource = $mere;
        }

        return $consommes;
    }

    /**
     * Couvrir, et non chevaucher : le créneau ancêtre doit commencer au plus tard et finir au plus
     * tôt aux bornes du visé. Un service qui ne couvre qu'une partie du créneau ne dit pas combien
     * d'unités il faudrait lui imputer — le chevauchement partiel est une question ouverte, pas un
     * cas à deviner ici.
     *
     * @return list<Creneau>
     */
    private function creneauxCouvrants(Ressource $ancetre, Creneau $vise): array
    {
        /** @var list<Creneau> $resultat */
        $resultat = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.ressource = :ressource')
            ->andWhere('c.debut <= :debut')
            ->andWhere('c.fin >= :fin')
            ->andWhere('c.statut = :planifie')
            ->setParameter('ressource', $ancetre->getId(), 'uuid')
            ->setParameter('debut', $vise->getDebut(), 'datetime_immutable')
            ->setParameter('fin', $vise->getFin(), 'datetime_immutable')
            ->setParameter('planifie', StatutCreneau::Planifie->value)
            ->getQuery()
            ->getResult();

        return $resultat;
    }
}
