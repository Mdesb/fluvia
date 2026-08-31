<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Creneau;
use App\Reservation\Service\ChevauchementCreneauGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Modifie une seule occurrence (horaire ou ressource) d'un Créneau récurrent, sans affecter la série
 * ni les occurrences déjà réservées (RG-M5-07, CA-6). La modification est marquée
 * `occurrenceModifiee=true` si le créneau appartient à une récurrence.
 *
 * @implements ProcessorInterface<Creneau, Creneau>
 */
final class ModifierOccurrenceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChevauchementCreneauGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Creneau
    {
        \assert($data instanceof Creneau);

        $ressource = $data->getRessource();
        if ($ressource !== null && $this->guard->enConflit($ressource, $data->getDebut(), $data->getFin(), $data->getId())) {
            throw new ConflictHttpException('Conflit de ressource : la nouvelle fenêtre chevauche un autre créneau (RG-M5-03).');
        }

        // ⚠ ON A LA PREUVE QUE LE CONFLIT A DISPARU, DONC ON LEVE LE DRAPEAU.
        //
        // Le contrôle ci-dessus vient d'établir que la nouvelle fenêtre est libre sur cette
        // ressource. Si la séance attendait un arbitrage, la raison même du drapeau n'existe plus.
        //
        // Sans cette ligne, l'exploitant qui déplace la séance hors du conflit obtient une séance
        // libre et pourtant toujours bloquée, et il lui faudrait ensuite cliquer « confirmer telle
        // quelle » — un libellé qui dit « j'assume le chevauchement » alors qu'il n'y en a plus.
        // C'était sans conséquence tant que rien ne posait ce drapeau ; ça ne l'est plus depuis que
        // la création le pose.
        $data->setEnAttenteArbitrage(false);

        if ($data->getRecurrence() !== null) {
            $data->setOccurrenceModifiee(true);
        }

        $this->em->flush();

        return $data;
    }
}
