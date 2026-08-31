<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Doctrine\ProductScope;
use App\Offre\Entity\ProductPhoto;
use App\Offre\Entity\Produit;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `DELETE /offre/photos-produit/{id}` — retirer une photo du catalogue.
 *
 * ── LE DOCUMENT N'EST PAS DÉTRUIT ───────────────────────────────────────────────────────────────
 *
 * On rompt le lien ; le fichier reste dans le DMS, avec sa rétention et sa traçabilité. C'est
 * délibéré : le DMS a ses propres règles de conservation, et un module consommateur qui effacerait
 * des documents par la bande les contournerait toutes.
 *
 * La contrepartie est que retirer une photo ne libère pas d'espace disque. C'est le bon échange :
 * une photo retirée par erreur se rebranche, un document supprimé ne revient pas.
 *
 * @cloisonnement-verifie : la photo est résolue, PUIS son produit est vérifié visible par
 * `ProductScope::restreindre()` — la clause du module (socle + établissement actif, D51). Ce n'est
 * pas `codesEffectifs()` parce qu'un produit du socle n'appartient à aucun établissement.
 * L'ordre compte : on ne juge pas sur l'identifiant reçu mais sur l'entité résolue (C19). Refus en
 * 404 — un 403 confirmerait que cette photo existe ailleurs. — claude-A, 28/08
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteProductPhotoProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContexteEtablissement $contexte,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'] ?? null;
        $photo = $id === null ? null : $this->entityManager->getRepository(ProductPhoto::class)->find($id);

        if (!$photo instanceof ProductPhoto || $photo->getProduit() === null) {
            throw new NotFoundHttpException('Photo introuvable.');
        }

        $this->exigerProduitVisible($photo->getProduit());

        $this->entityManager->remove($photo);
        $this->entityManager->flush();

        return null;
    }

    private function exigerProduitVisible(Produit $produit): void
    {
        $qb = $this->entityManager->getRepository(Produit::class)->createQueryBuilder('p');
        ProductScope::restreindre($qb, 'p', $this->contexte->idActif());

        $visible = $qb->andWhere('p.id = :produit')
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$visible instanceof Produit) {
            throw new NotFoundHttpException('Photo introuvable.');
        }
    }
}
