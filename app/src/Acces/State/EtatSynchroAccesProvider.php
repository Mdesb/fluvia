<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\ApiResource\SynchronisationAcces;
use App\Acces\Entity\Controleur;
use App\Acces\Enum\EtatControleur;
use App\Securite\Service\ContexteEtablissement;
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
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SynchronisationAcces
    {
        $vue = new SynchronisationAcces();

        /** @var list<Controleur> $controleurs */
        // `findAll()` rendait les controleurs de TOUS les sites, avec leurs libelles et leurs etats
        // de synchronisation. Meme cause que l'export des passages : un fournisseur sur mesure ne
        // passe par aucune extension. `SupervisionProvider`, ecrit dans ce meme dossier, lit bien
        // l'etablissement actif — la regle etait connue, c'est ici qu'elle manquait.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            return [];
        }

        $controleurs = $this->em->getRepository(Controleur::class)
            ->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.etablissement) = :synchro_etablissement')
            ->setParameter('synchro_etablissement', $actif, 'uuid')
            ->getQuery()
            ->getResult();
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
