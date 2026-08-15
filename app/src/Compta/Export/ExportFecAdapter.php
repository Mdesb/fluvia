<?php

declare(strict_types=1);

namespace App\Compta\Export;

use App\Compta\Entity\ExportComptable;
use App\Compta\Enum\FormatExport;
use App\Compta\Port\ExportComptableInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Export FEC légal (18 champs, US-L4-07, CA-11) — implémentation réelle. Séparateur tabulation,
 * une ligne par `LigneEcriture`, montants reconvertis centimes → decimal texte uniquement en sortie
 * (le domaine reste en centimes, cf. §1 du plan).
 */
#[AutoconfigureTag('compta.export_adapter')]
final class ExportFecAdapter implements ExportComptableInterface
{
    /** @var list<string> */
    public const CHAMPS = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib',
        'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit',
        'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
    ];

    public function format(): FormatExport
    {
        return FormatExport::Fec;
    }

    public function generer(ExportComptable $export, iterable $ecrituresValidees): string
    {
        $lignesFichier = [implode("\t", self::CHAMPS)];

        foreach ($ecrituresValidees as $ecriture) {
            foreach ($ecriture->getLignes() as $ligne) {
                $lignesFichier[] = implode("\t", [
                    $ecriture->getJournal()?->getCode() ?? '',
                    $ecriture->getJournal()?->getLibelle() ?? '',
                    (string) $ecriture->getNumeroSequence(),
                    $ecriture->getDateEcriture()->format('Ymd'),
                    $ligne->getCompte()?->getNumero() ?? '',
                    $ligne->getCompte()?->getLibelle() ?? '',
                    '',
                    '',
                    (string) $ecriture->getId(),
                    $ecriture->getDateEcriture()->format('Ymd'),
                    $ecriture->getLibelle() ?? '',
                    $this->decimal($ligne->getDebitCentimes()),
                    $this->decimal($ligne->getCreditCentimes()),
                    '',
                    '',
                    $ecriture->getDateEcriture()->format('Ymd'),
                    '',
                    '',
                ]);
            }
        }

        return implode("\n", $lignesFichier);
    }

    private function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', '');
    }
}
