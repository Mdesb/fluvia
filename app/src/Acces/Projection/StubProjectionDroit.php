<?php

declare(strict_types=1);

namespace App\Acces\Projection;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Port\ProjectionDroitInterface;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Vente\Entity\BilletSupport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stub L3 du port de projection (T4) : lit `BilletSupport` (M2) et le `Produit`/`CarteMultiEntrees`
 * (M1) associés pour construire/rafraîchir la projection locale. Une carte multi-entrées projette un
 * droit `carte_quota` avec `creditRestant` = compostages restants ; sinon `billet`/`abonnement` avec
 * une fenêtre ouverte (⚠ la fenêtre métier précise relève de M1, cf. Risque n°8 du plan).
 */
final class StubProjectionDroit implements ProjectionDroitInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function projeter(Uuid $billetSupportRef, Etablissement $etablissement): DroitAcces
    {
        $existant = $this->em->getRepository(DroitAcces::class)->findOneBy(['billetSupportRef' => $billetSupportRef]);
        $droit = $existant instanceof DroitAcces ? $existant : new DroitAcces();

        $droit->setBilletSupportRef($billetSupportRef)->setEtablissement($etablissement);

        $support = $this->em->getRepository(BilletSupport::class)->find($billetSupportRef);
        $produit = null;
        if ($support instanceof BilletSupport && $support->getLigne() !== null) {
            $produit = $this->em->getRepository(Produit::class)->find($support->getLigne()->getProduit());
        }

        if ($produit instanceof Produit && $produit->getCarte() !== null) {
            $carte = $produit->getCarte();
            $droit->setSourceType(TypeDroitAcces::CarteQuota);
            $droit->setCreditRestant($support?->getNbCompostages() ?? $carte->getStockCompostagesInitial());
            $droit->setProduitRef($produit->getId());
        } else {
            $droit->setSourceType(TypeDroitAcces::Billet);
            $droit->setCreditRestant(null);
            if ($produit instanceof Produit) {
                $droit->setProduitRef($produit->getId());
            }
        }

        $droit->setStatutProjection(StatutProjectionDroit::Valide);
        $droit->setSynchroniseLe(new \DateTimeImmutable());

        $this->em->persist($droit);

        return $droit;
    }
}
