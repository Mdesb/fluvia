<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\ExportComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\StatutExport;
use App\Compta\Export\ExportComptableResolver;
use App\Compta\Regime\RegimeComptableResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Génération d'un export comptable (RG-EXPORT-07, CA-10/CA-11) : contrôle pré-export, garde du
 * format disponible pour le profil (masquage, CA-1/CA-10), génération du contenu, statut.
 */
final class GenererExportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegimeComptableResolver $regimeResolver,
        private readonly ExportComptableResolver $exportResolver,
        private readonly ControleExportGuard $controle,
    ) {
    }

    public function generer(ExportComptable $export): ExportComptable
    {
        $profil = $export->getProfilExploitant();
        \assert($profil instanceof ProfilExploitant);

        $regime = $this->regimeResolver->pour($profil);
        if (!\in_array($export->getFormat(), $regime->formatsExportDisponibles(), true)) {
            throw new AccessDeniedHttpException(sprintf(
                'Format « %s » non disponible pour le profil « %s » (RG-EXPORT-07).',
                $export->getFormat()->value,
                $profil->getType()->value,
            ));
        }

        $anomalies = $this->controle->anomalies($profil, $export->getPeriodeDebut(), $export->getPeriodeFin());
        if ($anomalies !== []) {
            $export->setStatut(StatutExport::BloqueAnomalies);
            $export->setAnomalies($anomalies);
            $this->em->persist($export);
            $this->em->flush();

            return $export;
        }

        $ecritures = $this->controle->ecrituresValidees($profil, $export->getPeriodeDebut(), $export->getPeriodeFin());
        $adaptateur = $this->exportResolver->pour($export->getFormat());
        $contenu = $adaptateur->generer($export, $ecritures);

        $export->setContenu($contenu);
        $export->setStatut(StatutExport::Genere);
        $export->setAnomalies(null);

        $this->em->persist($export);
        $this->em->flush();

        return $export;
    }
}
