<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Offre\Entity\Produit;
use App\Offre\Enum\AxeCategorie;
use App\Organisation\Entity\Etablissement;
use App\Vente\Port\SaleFiscalContextInterface;
use App\Vente\Port\VatRateResolution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le taux d'une ligne de vente, lu là où les écritures le lisent (D123, Q-B1).
 *
 * Même chemin que la génération des écritures : la catégorie de l'axe comptable du produit
 * (`ProjectionVenteDoctrineAdapter`), le profil exploitant qui couvre le site (même règle que
 * `ResolveurComptesFacturation::profilPour()`), sa correspondance pour cette catégorie, exigée valide
 * (`MappingComptable::estValide()`, comme `MappingComptableGuard`). Un écart entre ce que la ligne
 * grave et ce que les comptes enregistrent est exactement ce que ce lot ferme.
 *
 * `Produit::$tauxTva` n'est pas lu (voir le port).
 */
final class SaleFiscalContextAdapter implements SaleFiscalContextInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function vatRateFor(Uuid $productId, ?Etablissement $site): VatRateResolution
    {
        $product = $this->em->getRepository(Produit::class)->find($productId);
        if (!$product instanceof Produit) {
            return VatRateResolution::missing('la ligne ne désigne aucun produit du catalogue, donc aucune catégorie comptable');
        }

        $category = $product->getCategorieParAxe(AxeCategorie::Comptable);
        if ($category === null) {
            return VatRateResolution::missing(sprintf('le produit « %s » n\'a pas de catégorie comptable', $product->getCode()));
        }

        $profile = $site === null ? null : $this->profileCovering($site);
        if ($profile === null) {
            return VatRateResolution::missing(sprintf(
                'aucun profil exploitant ne couvre le site « %s », donc aucune correspondance comptable ne s\'y applique',
                $site?->getNom() ?? '(aucun)',
            ));
        }

        $mapping = $this->em->getRepository(MappingComptable::class)->findOneBy([
            'profilExploitant' => $profile->getId(),
            'categorie' => $category->getId(),
        ]);
        $rate = $mapping instanceof MappingComptable && $mapping->estValide() ? $mapping->getTauxTva() : null;
        if ($rate === null) {
            return VatRateResolution::missing(sprintf(
                'la catégorie comptable « %s » n\'a pas de correspondance valide (compte de produit et taux de TVA actifs) pour ce site',
                $category->getLibelle(),
            ));
        }

        return VatRateResolution::resolved($rate->getTaux(), $rate->getVatCategory()?->value, $rate->getLibelle(), $rate->getId());
    }

    private function profileCovering(Etablissement $site): ?ProfilExploitant
    {
        foreach ($this->em->getRepository(ProfilExploitant::class)->findAll() as $profile) {
            if ($profile->couvre($site)) {
                return $profile;
            }
        }

        return null;
    }
}
