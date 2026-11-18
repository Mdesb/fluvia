<?php

declare(strict_types=1);

namespace App\Platform\Doctrine;

use App\Platform\Entity\Nf525Alert;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Unicité de l'alerte NF525 ouverte, élevée au niveau base (plan D-B) : ajoute au schéma généré la
 * colonne générée virtuelle `open_chain_key` (`chain_key` si `status = 'open'`, sinon `NULL`) et
 * l'index `UNIQUE` qui la porte — au plus une alerte ouverte par chaîne, garanti par MariaDB (qui
 * exclut les `NULL` d'un index unique).
 *
 * ⚠ NULL SOUS UNIQUE AUTORISE LES DOUBLONS : la colonne ne doit être NULL que pour une alerte résolue.
 * `status` et `chain_key` sont donc NOT NULL (Nf525AlertSchemaTest le prouve).
 *
 * Cette colonne n'a aucun sens applicatif PHP : elle n'est pas un champ mappé de l'entité, mais un
 * artefact de schéma ajouté ici pour que le schéma de test — construit par `SchemaTool` depuis les
 * métadonnées ORM, jamais rejoué depuis les migrations — porte la même contrainte que la migration
 * en production (même piège que `TreasuryCashAlertSchemaListener`).
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class Nf525AlertSchemaListener
{
    private const GENERATED_COLUMN = 'open_chain_key';
    private const UNIQUE_INDEX = 'uniq_nf525_alert_open_chain';

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        if (Nf525Alert::class !== $eventArgs->getClassMetadata()->getName()) {
            return;
        }

        $table = $eventArgs->getClassTable();
        if ($table->hasColumn(self::GENERATED_COLUMN)) {
            return;
        }

        $table->addColumn(self::GENERATED_COLUMN, Types::STRING, [
            'length' => 100,
            'notnull' => false,
            'columnDefinition' => "VARCHAR(100) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN chain_key END) VIRTUAL",
        ]);
        $table->addUniqueIndex([self::GENERATED_COLUMN], self::UNIQUE_INDEX);
    }
}
