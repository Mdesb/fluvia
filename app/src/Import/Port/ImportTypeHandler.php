<?php

declare(strict_types=1);

namespace App\Import\Port;

use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportType;
use App\Organisation\Entity\Etablissement;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ce qu'un type de reprise doit savoir faire.
 *
 * **Six types sont prévus, un seul est écrit.** Le contrat existe dès le premier pour que les cinq
 * suivants se branchent sans refonte — la spécification les ordonne par dépendance, ils arriveront
 * un par un. Sans ce point d'accroche, le second type demanderait de rouvrir l'orchestration, et le
 * troisième de la rouvrir encore.
 *
 * **Les trois méthodes séparent délibérément ce que la décision « tout refuser » sépare** : dire ce
 * que le fichier doit contenir, juger une ligne **sans rien écrire**, puis créer. Un type qui
 * mélangerait jugement et écriture ramènerait le défaut que les deux temps existent pour supprimer.
 */
#[AutoconfigureTag('import.type_handler')]
interface ImportTypeHandler
{
    public function supports(): ImportType;

    /**
     * Colonnes que le fichier doit porter, en plus de `externalRef`.
     *
     * @return list<string>
     */
    public function requiredColumns(): array;

    /**
     * Juge une ligne. **N'écrit rien.**
     *
     * @param array<string, string> $row
     *
     * @return list<string> les fautes de cette ligne ; liste vide si la ligne est bonne
     */
    public function validateRow(array $row): array;

    /**
     * Crée l'objet métier de cette ligne, et le rattache au lot.
     *
     * Appelée uniquement après qu'un lot entier a été jugé bon, et à l'intérieur de la transaction
     * d'application. Elle peut donc supposer la ligne valide — c'est ce que le premier temps garantit.
     *
     * @param array<string, string> $row
     */
    public function create(array $row, Etablissement $establishment, ImportBatch $batch): object;

    /**
     * Les objets que ce lot a créés, pour l'annulation.
     *
     * @return list<object>
     */
    public function createdBy(ImportBatch $batch): array;

    /**
     * Cet objet a-t-il servi depuis sa reprise ?
     *
     * ⚠ C'est la question qui rend l'annulation sûre. Un client repris sur lequel une vente a été
     * faite ne se supprime pas : on corrigerait un fichier en détruisant une écriture. La règle de
     * la spécification est nette — on ne défait pas ce qui a déjà servi, on corrige par un second
     * import.
     */
    public function hasBeenUsed(object $created): bool;
}
