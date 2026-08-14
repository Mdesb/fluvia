<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\ApiResource\SynchronisationAcces;
use App\Acces\Entity\Controleur;
use App\Acces\Enum\EtatControleur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * État réseau des contrôleurs (GET /acces/synchro/etat, US-L3-07, CA-8) : bascule online/offline
 * signalée sans dégrader l'affichage des autres contrôleurs (cahier A-03).
 *
 * @implements ProviderInterface<SynchronisationAcces>
 */
final class EtatSynchroAccesProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SynchronisationAcces
    {
        $vue = new SynchronisationAcces();

        /** @var list<Controleur> $controleurs */
        $controleurs = $this->em->getRepository(Controleur::class)->findAll();
        $horsLigne = 0;
        foreach ($controleurs as $controleur) {
            $vue->controleurs[] = [
                'id' => (string) $controleur->getId(),
                'libelle' => $controleur->getLibelle(),
                'etat' => $controleur->getEtat()->value,
            ];
            if ($controleur->getEtat() !== EtatControleur::EnLigne) {
                ++$horsLigne;
            }
        }

        $vue->etat = match (true) {
            $controleurs === [] || $horsLigne === 0 => 'en_ligne',
            $horsLigne === \count($controleurs) => 'hors_ligne',
            default => 'mixte',
        };

        return $vue;
    }
}
