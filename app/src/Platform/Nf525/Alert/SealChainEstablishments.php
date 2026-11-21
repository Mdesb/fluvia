<?php

declare(strict_types=1);

namespace App\Platform\Nf525\Alert;

use App\Caisse\Entity\PointDeVente;
use App\Compta\Entity\ProfilExploitant;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealChainType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Component\Uid\Uuid;

/**
 * Établissements VISÉS par une chaîne scellée : ce sont eux qui portent le cloisonnement de l'alerte et
 * reçoivent la cloche (plan, D-C).
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - Lecture sur la connexion PRINCIPALE de l'appelant (vue cohérente avec sa transaction) ; jamais sur la
 *   seconde connexion.
 * - Noms de tables et de colonnes lus dans les métadonnées ORM, pas recopiés : un renommage de colonne ne
 *   doit pas casser l'alerte en silence. Les identifiants sont des paramètres, jamais concaténés ; seuls les
 *   noms issus des métadonnées entrent dans le SQL.
 * - Profil : principal ET rattachés, même définition que `ProfilExploitant::couvre`.
 * - Point de vente sans établissement, ou chaîne inconnue : liste vide (aucune liaison, aucune cloche).
 */
final class SealChainEstablishments
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Uuid> dédoublonnée */
    public function forChain(SealChainRef $chain): array
    {
        $ids = match ($chain->type) {
            SealChainType::Sales => $chain->pointOfSaleId === null ? [] : $this->ofPointOfSale($chain->pointOfSaleId),
            SealChainType::Accounting, SealChainType::Invoicing => $chain->operatorProfileId === null ? [] : $this->ofProfile($chain->operatorProfileId),
        };

        $unique = [];
        foreach ($ids as $raw) {
            if (is_string($raw) && $raw !== '') {
                $uuid = Uuid::fromBinary($raw);
                $unique[$uuid->toRfc4122()] = $uuid;
            }
        }

        return array_values($unique);
    }

    /** @return list<mixed> */
    private function ofPointOfSale(Uuid $id): array
    {
        $meta = $this->em->getClassMetadata(PointDeVente::class);
        $mapping = $meta->getAssociationMapping('etablissement');
        \assert($mapping instanceof ToOneOwningSideMapping);

        return $this->em->getConnection()->fetchFirstColumn(
            sprintf('SELECT %s FROM %s WHERE %s = :id', $mapping->joinColumns[0]->name, $meta->getTableName(), $meta->getSingleIdentifierColumnName()),
            ['id' => $id->toBinary()],
        );
    }

    /** @return list<mixed> */
    private function ofProfile(Uuid $id): array
    {
        $meta = $this->em->getClassMetadata(ProfilExploitant::class);
        $principal = $meta->getAssociationMapping('etablissementPrincipal');
        $attached = $meta->getAssociationMapping('etablissementsRattaches');
        \assert($principal instanceof ToOneOwningSideMapping && $attached instanceof ManyToManyOwningSideMapping);
        $table = $attached->joinTable;
        $db = $this->em->getConnection();

        return [
            ...$db->fetchFirstColumn(
                sprintf('SELECT %s FROM %s WHERE %s = :id', $principal->joinColumns[0]->name, $meta->getTableName(), $meta->getSingleIdentifierColumnName()),
                ['id' => $id->toBinary()],
            ),
            ...$db->fetchFirstColumn(
                sprintf('SELECT %s FROM %s WHERE %s = :id', $table->inverseJoinColumns[0]->name, $table->name, $table->joinColumns[0]->name),
                ['id' => $id->toBinary()],
            ),
        ];
    }
}
