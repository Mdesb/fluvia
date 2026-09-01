<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Doctrine;

use App\Finance\Treasury\Entity\TreasuryCashAlert;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Anti-répétition de `TreasuryCashAlert` élevée au niveau base (§0.3 du plan) : ajoute au schéma
 * généré la colonne générée virtuelle `open_establishment_id` (`NULL` hors `status = open`) et l'index
 * `UNIQUE` qui la porte — au plus une alerte `open` par établissement, garanti par MariaDB (qui exclut
 * les `NULL` d'un index unique), pas seulement par un `findOneBy()` applicatif dans la commande.
 *
 * Cette colonne n'a **aucun** sens applicatif PHP (elle n'est jamais lue ni écrite directement) : elle
 * n'est donc pas un champ mappé de l'entité, mais un artefact de schéma ajouté ici pour que le schéma
 * de test — construit par `Doctrine\ORM\Tools\SchemaTool` depuis les métadonnées ORM
 * (`App\Tests\SchemaDuHarnais`), **jamais** rejoué depuis les migrations — porte la **même** contrainte
 * que `Version20260901090100` en production. Sans ce listener, `TreasuryCashAlertEntityTest` passerait
 * en test alors que la garde n'existerait que sur le papier de la migration.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class TreasuryCashAlertSchemaListener
{
    private const GENERATED_COLUMN = 'open_establishment_id';
    private const UNIQUE_INDEX = 'uniq_treasury_cash_alert_open_establishment';

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        if (TreasuryCashAlert::class !== $eventArgs->getClassMetadata()->getName()) {
            return;
        }

        $table = $eventArgs->getClassTable();
        if ($table->hasColumn(self::GENERATED_COLUMN)) {
            return;
        }

        $table->addColumn(self::GENERATED_COLUMN, Types::BINARY, [
            'length' => 16,
            'notnull' => false,
            'columnDefinition' => "BINARY(16) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN establishment_id END) VIRTUAL",
        ]);
        $table->addUniqueIndex([self::GENERATED_COLUMN], self::UNIQUE_INDEX);
    }
}
