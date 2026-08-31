<?php

declare(strict_types=1);

namespace App\Project\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Project\Entity\Project;
use App\Project\Entity\ProjectTask;
use App\Project\Enum\TaskStatus;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /projets/tableau` — les projets de l'établissement actif, **avec ce qui se compte**.
 *
 * **Rien n'est stocké de ce que rend ce fournisseur.** L'avancement se compte depuis les tâches, le
 * retard se déduit de l'échéance. Une colonne « avancement » demanderait d'être recalculée à chaque
 * modification de tâche — donc partout, donc oubliée quelque part — et un statut « en retard »
 * demanderait un passage nocturne, laissant un projet à l'heure jusqu'au lendemain matin.
 *
 * > **Un chiffre qu'on peut compter ne se recopie pas.**
 *
 * **Le retard d'une TÂCHE et celui d'un PROJET ne sont pas la même information**, et l'écran a besoin
 * des deux : un projet dont l'échéance est loin peut contenir trois tâches en retard, et c'est
 * précisément ce qu'un responsable veut voir avant que le projet, lui, ne dérape.
 *
 * @cloisonnement-verifie: la requête filtre sur l'établissement ACTIF résolu par
 * `ContexteEtablissement`, et `PermissionVoter` a déjà refusé l'accès si l'utilisateur n'y est pas
 * affecté — les droits sont l'union des permissions des affectations SUR cet établissement.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class ProjectBoardProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : sans établissement actif, aucun projet. Rendre « tout » serait la
            // seule erreur irrattrapable de ce fichier.
            return new JsonResponse(['projets' => [], 'total' => 0]);
        }

        /** @var list<Project> $projets */
        $projets = $this->em->getRepository(Project::class)->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.establishment) = :etab')
            // Type `uuid` explicite : sur un identifiant à type personnalisé, une comparaison sans
            // type ne compte rien **et ne lève pas** (D58). Ici, un tableau vide se lirait comme
            // « aucun projet », ce qui est exactement l'état d'un module neuf.
            ->setParameter('etab', $actif, 'uuid')
            ->orderBy('p.dueDate', 'ASC')
            ->getQuery()
            ->getResult();

        $aujourdhui = new \DateTimeImmutable('today');
        $sortie = [];

        foreach ($projets as $projet) {
            $taches = $projet->getTasks();
            $total = \count($taches);
            $faites = 0;
            $enRetard = 0;

            foreach ($taches as $tache) {
                \assert($tache instanceof ProjectTask);
                if ($tache->getStatus() === TaskStatus::Done) {
                    ++$faites;
                    continue;
                }
                // Une tâche faite n'est jamais en retard, même livrée après l'échéance : le retard
                // décrit ce qui reste à faire, pas ce qui a mal fini.
                if ($tache->getDueDate() !== null && $tache->getDueDate() < $aujourdhui) {
                    ++$enRetard;
                }
            }

            $echeance = $projet->getDueDate();
            $projetEnRetard = $echeance !== null
                && $echeance < $aujourdhui
                && !$projet->getStatus()->closed();

            $sortie[] = [
                'id' => (string) $projet->getId(),
                'nom' => $projet->getName(),
                'statut' => $projet->getStatus()->value,
                'statutLibelle' => $projet->getStatus()->label(),
                'responsable' => $projet->getOwner()?->getNom(),
                'debut' => $projet->getStartDate()?->format('Y-m-d'),
                'echeance' => $echeance?->format('Y-m-d'),
                'nbTaches' => $total,
                'nbFaites' => $faites,
                // Le pourcentage est rendu tel quel, et un projet SANS tâche vaut `null` et non zéro :
                // zéro dirait « rien n'est fait » là où la vérité est « rien n'est prévu ».
                'avancement' => $total > 0 ? (int) round($faites * 100 / $total) : null,
                'tachesEnRetard' => $enRetard,
                'enRetard' => $projetEnRetard,
            ];
        }

        return new JsonResponse(['projets' => $sortie, 'total' => \count($sortie)]);
    }
}
