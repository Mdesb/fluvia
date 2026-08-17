<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Passage;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\TypeDroitAcces;
use App\Vente\Entity\BilletSupport;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dérive `affichage` (US-TERM-02, spec-acces-terminal.md §4.2) depuis `DroitAcces`/`Support` déjà
 * résolus par le moteur — aucune nouvelle donnée métier n'est créée ici. `nomPorteur` reste
 * systématiquement `null` (⚠ Risque R-3 du plan : `App\Vente\Entity\BilletSupport` ne porte aucun champ
 * porteur nominatif aujourd'hui, point à trancher avec M2/CRM) : dégrade gracieusement sans bloquer
 * CA-2 (le champ est documenté « si connu »).
 */
final class AffichagePorteurResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{nomPorteur: ?string, numeroBillet: ?string, typeSupport: ?string, compostagesRestants: ?int, validiteAbonnement: ?array{valide: bool, debut: ?string, fin: ?string}}|null */
    public function resoudre(Passage $passage): ?array
    {
        // Pas de fuite d'info sur un refus « signature invalide »/« support inconnu » (§4.2 spec).
        if ($passage->getResultat() === ResultatPassage::Refuse
            && \in_array($passage->getCodeMotif(), [CodeMotifRefus::SignatureInvalide, CodeMotifRefus::DroitInvalide], true)
        ) {
            return null;
        }

        $droit = $passage->getDroit();
        if (!$droit instanceof DroitAcces) {
            return null;
        }

        $numeroBillet = null;
        if ($droit->getBilletSupportRef() !== null) {
            $billetSupport = $this->em->getRepository(BilletSupport::class)->find($droit->getBilletSupportRef());
            $numeroBillet = $billetSupport?->getVente()?->getNumero();
        }

        $validiteAbonnement = null;
        if ($droit->getSourceType() === TypeDroitAcces::Abonnement) {
            $maintenant = new \DateTimeImmutable();
            $debut = $droit->getFenetreDebut();
            $fin = $droit->getFenetreFin();
            $valide = ($debut === null || $maintenant >= $debut) && ($fin === null || $maintenant <= $fin);
            $validiteAbonnement = [
                'valide' => $valide,
                'debut' => $debut?->format(DATE_ATOM),
                'fin' => $fin?->format(DATE_ATOM),
            ];
        }

        return [
            'nomPorteur' => null,
            'numeroBillet' => $numeroBillet,
            'typeSupport' => $passage->getSupport()?->getType()?->value,
            'compostagesRestants' => $droit->getSourceType() === TypeDroitAcces::CarteQuota ? $droit->getCreditRestant() : null,
            'validiteAbonnement' => $validiteAbonnement,
        ];
    }
}
