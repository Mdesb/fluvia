<?php

declare(strict_types=1);

namespace App\Compta\Export;

use App\Compta\Entity\ExportComptable;

/**
 * Squelette CSV minimal commun aux formats éditeurs (CIEL/EBP/Sage/Cegid, §5 du plan) : « format
 * éditeur à finaliser », suffisant pour tester le masquage par profil et le pipeline de contrôle
 * sans figer un format propriétaire non documenté dans les sources.
 */
trait CsvEditeurSkeletonTrait
{
    public function generer(ExportComptable $export, iterable $ecrituresValidees): string
    {
        $lignes = ['Journal;Date;Compte;Libelle;Debit;Credit'];
        foreach ($ecrituresValidees as $ecriture) {
            foreach ($ecriture->getLignes() as $ligne) {
                $lignes[] = sprintf(
                    '%s;%s;%s;%s;%s;%s',
                    $ecriture->getJournal()?->getCode() ?? '',
                    $ecriture->getDateEcriture()->format('d/m/Y'),
                    $ligne->getCompte()?->getNumero() ?? '',
                    $ecriture->getLibelle() ?? '',
                    number_format($ligne->getDebitCentimes() / 100, 2, ',', ''),
                    number_format($ligne->getCreditCentimes() / 100, 2, ',', ''),
                );
            }
        }

        return implode("\n", $lignes);
    }
}
