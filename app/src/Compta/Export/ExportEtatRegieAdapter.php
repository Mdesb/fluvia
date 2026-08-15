<?php

declare(strict_types=1);

namespace App\Compta\Export;

use App\Compta\Entity\ExportComptable;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Enum\FormatExport;
use App\Compta\Port\ExportComptableInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * État de régie (US-L4-07) — implémentation réelle : encaissements par mode, versements, restes, à
 * partir de `RegieRecettes`/`BordereauVersement` du profil concerné.
 */
#[AutoconfigureTag('compta.export_adapter')]
final class ExportEtatRegieAdapter implements ExportComptableInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function format(): FormatExport
    {
        return FormatExport::EtatRegie;
    }

    public function generer(ExportComptable $export, iterable $ecrituresValidees): string
    {
        $profil = $export->getProfilExploitant();
        /** @var list<RegieRecettes> $regies */
        $regies = $this->em->getRepository(RegieRecettes::class)->findBy(['profilExploitant' => $profil?->getId()]);

        $lignes = ['Régie;Solde encaisse (€);Plafond (€);Versements'];
        foreach ($regies as $regie) {
            $versements = $this->em->getRepository(\App\Compta\Entity\BordereauVersement::class)->findBy(['regie' => $regie->getId()]);
            $totalVerse = array_sum(array_map(static fn (\App\Compta\Entity\BordereauVersement $b): int => $b->getMontantCentimes(), $versements));
            $lignes[] = sprintf(
                '%s;%s;%s;%s',
                $regie->getLibelle(),
                number_format($regie->getSoldeEncaisseCentimes() / 100, 2, ',', ''),
                number_format($regie->getPlafondEncaisseCentimes() / 100, 2, ',', ''),
                number_format($totalVerse / 100, 2, ',', ''),
            );
        }

        $totalEncaisse = 0;
        foreach ($ecrituresValidees as $ecriture) {
            $totalEncaisse += $ecriture->totalDebitCentimes();
        }
        $lignes[] = '';
        $lignes[] = sprintf('Total encaissements période;%s', number_format($totalEncaisse / 100, 2, ',', ''));

        return implode("\n", $lignes);
    }
}
