<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\DisponibiliteAffichageHandler;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Reservation\Entity\Creneau;
use App\Reservation\Enum\StatutCreneau;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/produits/{produitId}/creneaux (US-L8-02, RG-M3-02, CA-2) : liste des créneaux
 * disponibles pour un produit timed-entry — délègue au module `reservation` (`Creneau`), exclut les
 * créneaux à public réservé (RG-M5-08). ⚠ HYPOTHÈSE (Risque n°2 du plan) : la ressource associée au
 * produit est résolue via `Produit.champsPerso['ressourceId']` (aucun lien générique Produit↔Ressource
 * n'existe côté M1 à ce jour).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class CreneauxProduitProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisponibiliteAffichageHandler $disponibilite,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $produitId = PanierProprietaireGuard::estUuid($uriVariables['id'] ?? null);
        $produit = $produitId !== null ? $this->em->getRepository(Produit::class)->find($produitId) : null;
        if (!$produit instanceof Produit) {
            throw new NotFoundHttpException('Produit introuvable.');
        }
        // Revue de sécurité — faille majeure : un produit non publié ou non visible au canal en ligne
        // ne doit jamais exposer ses créneaux publiquement (même garde qu'à l'ajout au panier).
        if ($produit->getStatut() !== StatutProduit::Publie || !$produit->aCanal(Canal::EnLigne)) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        $ressourceId = PanierProprietaireGuard::estUuid($produit->getChampsPerso()['ressourceId'] ?? null);
        if ($ressourceId === null) {
            return new JsonResponse(['produit' => (string) $produit->getId(), 'creneaux' => []]);
        }

        $creneaux = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.ressource = :ressource')
            ->andWhere('c.statut = :planifie')
            ->andWhere('c.debut >= :maintenant')
            ->setParameter('ressource', $ressourceId, 'uuid')
            ->setParameter('planifie', StatutCreneau::Planifie->value)
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->orderBy('c.debut', 'ASC')
            ->getQuery()
            ->getResult();

        $liste = [];
        foreach ($creneaux as $creneau) {
            \assert($creneau instanceof Creneau);
            // RG-M5-08 : un créneau à public réservé n'apparaît pas dans le sélecteur en ligne.
            if ($creneau->getPublicReserve() !== null && $creneau->getPublicReserve() !== '') {
                continue;
            }
            $liste[] = [
                'creneau' => (string) $creneau->getId(),
                'debut' => $creneau->getDebut()->format(DATE_ATOM),
                'fin' => $creneau->getFin()->format(DATE_ATOM),
                'reste' => $this->disponibilite->disponibilitePourCreneau($creneau),
            ];
        }

        return new JsonResponse(['produit' => (string) $produit->getId(), 'creneaux' => $liste]);
    }
}
