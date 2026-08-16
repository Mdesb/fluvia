<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\DisponibiliteAffichageHandler;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/vitrines/{id}/catalogue (US-L8-01, RG-M3-01/08, CA-1) : catalogue public — ne montre
 * que les produits **publiés** et **visibles au canal `en_ligne`** (RG-M1-07/09), prix/disponibilité
 * en temps réel (aucun décalage avec M1/le stock).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class CatalogueVitrineProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisponibiliteAffichageHandler $disponibilite,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = PanierProprietaireGuard::estUuid($uriVariables['id'] ?? null);
        $vitrine = $id !== null ? $this->em->getRepository(Vitrine::class)->find($id) : null;
        if (!$vitrine instanceof Vitrine) {
            throw new NotFoundHttpException('Vitrine introuvable.');
        }
        $etablissement = $vitrine->getEtablissement();

        $produits = $this->em->getRepository(Produit::class)->createQueryBuilder('p')
            ->innerJoin('p.etablissements', 'e')
            ->andWhere('e = :etablissement')
            ->andWhere('p.statut = :publie')
            ->setParameter('etablissement', $etablissement?->getId(), 'uuid')
            ->setParameter('publie', StatutProduit::Publie->value)
            ->getQuery()
            ->getResult();

        $catalogue = [];
        foreach ($produits as $produit) {
            \assert($produit instanceof Produit);
            if (!$produit->aCanal(Canal::EnLigne)) {
                continue;
            }
            $catalogue[] = [
                'produit' => (string) $produit->getId(),
                'code' => $produit->getCode(),
                'libelle' => $produit->getLibelle(),
                'timedEntry' => $this->disponibilite->estTimedEntry($produit),
                'disponibilite' => $this->disponibilite->disponibilitePourProduit($produit),
            ];
        }

        return new JsonResponse([
            'vitrine' => (string) $vitrine->getId(),
            'logo' => $vitrine->getLogo(),
            'couleurs' => $vitrine->getCouleurs(),
            'langues' => $vitrine->getLangues(),
            'produits' => $catalogue,
        ]);
    }
}
