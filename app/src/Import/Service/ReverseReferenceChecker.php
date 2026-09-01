<?php

declare(strict_types=1);

namespace App\Import\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Uid\Uuid;

/**
 * Utilitaire **générique et partagé** (plan-import-i1.md §0.8) : « cette entité a-t-elle servi
 * ailleurs ? », par scan des métadonnées Doctrine — pas une liste figée par type, qui serait exacte
 * aujourd'hui et fausse dès qu'un nouveau module ajoute une relation vers la cible sans que quiconque
 * pense à mettre à jour cette liste (même classe de risque que D41).
 *
 * Implémentation : parcourt `EntityManager::getMetadataFactory()->getAllMetadata()`, retient les
 * associations `ManyToOne`/`OneToOne` **côté propriétaire** (seul côté qui porte une colonne FK
 * interrogeable directement) vers `$entityClass`, exécute une requête `EXISTS` par association trouvée.
 *
 * Coût non négligeable (scan de métadonnées + N requêtes), jugé acceptable : appelé uniquement par
 * `RevertImportBatchProcessor`, une action rare, déclenchée à la main, jamais un chemin chaud.
 *
 * **Limite assumée (§7 point 3 du plan)** : ne voit que les associations Doctrine déclarées ; une
 * référence portée par une colonne libre (json, id stocké en clair) lui échapperait silencieusement.
 * Aucun cas connu pour `Client` aujourd'hui — à revérifier avant chaque type I2+, en particulier
 * `card_credits`.
 */
final class ReverseReferenceChecker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param class-string $entityClass
     */
    public function isReferenced(string $entityClass, Uuid $id): bool
    {
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->getAssociationMappings() as $fieldName => $mapping) {
                $type = $mapping['type'] ?? null;
                if ($type !== ClassMetadata::MANY_TO_ONE && $type !== ClassMetadata::ONE_TO_ONE) {
                    continue;
                }
                if (($mapping['isOwningSide'] ?? true) !== true) {
                    // Le côté inverse n'a pas de colonne FK à interroger ; l'association déclarée côté
                    // propriétaire (toujours présente pour une relation bidirectionnelle exploitable
                    // par une requête EXISTS) suffit à couvrir le graphe.
                    continue;
                }
                if (($mapping['targetEntity'] ?? null) !== $entityClass) {
                    continue;
                }

                $count = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(e.id)')
                    ->from($metadata->getName(), 'e')
                    ->andWhere(sprintf('IDENTITY(e.%s) = :app_import_rrc_target', $fieldName))
                    ->setParameter('app_import_rrc_target', $id, 'uuid')
                    ->getQuery()
                    ->getSingleScalarResult();

                if ($count > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
