<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Regime\Dto\VenteProjectionDto;
use App\Compta\Regime\MappingResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Garde le mapping comptable (RG-M6-01, CA-2) : une catégorie vendue sans mapping actif (compte +
 * taux TVA actifs) **bloque** la génération d'écriture pour la vente concernée — la vente M2 reste
 * possible (non modifiée), l'écriture reste « en attente » (retry au prochain passage, ⚠ HYPOTHÈSE
 * §9 du plan).
 */
final class MappingComptableGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<string> anomalies (vide = mapping complet) */
    public function anomalies(ProfilExploitant $profil, VenteProjectionDto $vente): array
    {
        $anomalies = [];
        foreach ($vente->lignes as $ligne) {
            if ($ligne->categorieComptable === null) {
                $anomalies[] = sprintf('Produit %s : aucune catégorie comptable (RG-M1-05).', $ligne->produit);
                continue;
            }
            $mapping = $this->em->getRepository(MappingComptable::class)->findOneBy([
                'profilExploitant' => $profil->getId(),
                'categorie' => $ligne->categorieComptable,
            ]);
            if ($mapping === null || !$mapping->estValide()) {
                $anomalies[] = sprintf('Catégorie %s : mapping comptable incomplet ou inactif (CA-2).', $ligne->categorieComptable);
            }
        }

        return $anomalies;
    }

    public function resolveur(ProfilExploitant $profil): MappingResolver
    {
        /** @var list<MappingComptable> $mappings */
        $mappings = $this->em->getRepository(MappingComptable::class)->findBy(['profilExploitant' => $profil->getId()]);

        $parCategorie = [];
        foreach ($mappings as $mapping) {
            if ($mapping->getCategorie() !== null) {
                $parCategorie[(string) $mapping->getCategorie()] = $mapping;
            }
        }

        return new MappingResolver($parCategorie);
    }
}
